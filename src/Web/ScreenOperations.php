<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * The primitive that lets the AGENT author a live screen MATERIALLY (greenhouse decisions/0158):
 * `screen:declare` persists a screen DECLARATION (a data-table — name + columns + rows) to the
 * {@see ScreenStore}. {@see LivePlugin} reads that store to register the screen as a live component
 * and {@see DeclaredScreensPageProvider} serves its data, so a declared screen answers at
 * `GET /live/page?component=<name>` with NO code deploy.
 *
 * The operation MUTATES and declares its effect profile, so it flows through the governed path the
 * runtime already owns: the gate pauses (a mutation needs the authority's signature), the agent runs
 * it in a disposable trial (the write is quarantined until the human commits), and the served screen
 * is bound to the component-type scope. The agent declares a datum; the framework projects; the human
 * governs. Contributed by LivePlugin (a booted `CommandProvider`), so enabling the live door enables
 * the author-material loop — no per-app wiring.
 */
final class ScreenOperations implements CommandProvider
{
    public function __construct(private readonly ScreenStore $store)
    {
    }

    /** @return list<Operation> */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'screen:declare',
                description: 'Declare a live screen (a data-table) by name, columns and rows. It is served at /live/page?component=<name> with no code deploy.',
                handler: fn (array $input): array => $this->store->declare($input),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['name', 'columns', 'rows'],
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'a-z, 0-9, dash; starts with a letter'],
                        'columns' => ['type' => 'array', 'description' => 'list of { key, label }'],
                        'rows' => ['type' => 'array', 'description' => 'list of row objects keyed by column key'],
                    ],
                ],
                mutating: true,
                scopes: ['milpa:component:data-table:*'],
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::Guaranteed,
                    subject: Subject::Data,
                    rollbackContract: 'forget the screen with screen:forget',
                ),
            ),
            new Operation(
                name: 'screen:list',
                description: 'List the live screens declared at runtime — name, where each is served, and its shape. Read-only.',
                handler: fn (array $input): array => ['screens' => $this->store->catalogue()],
                effects: EffectProfile::readOnly(),
            ),
            new Operation(
                name: 'screen:forget',
                description: 'Forget a runtime-declared screen by name — the rollback of screen:declare. It stops being served at /live/page?component=<name>.',
                handler: fn (array $input): array => $this->store->forget((string) ($input['name'] ?? '')),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'the declared screen to forget'],
                    ],
                ],
                mutating: true,
                scopes: ['milpa:component:data-table:*'],
                // The screen to forget must be NAMED by the request (ADR-0044): «remove the old screen» does
                // not execute against a guessed target — it asks, with the operation and the name in the ask.
                namedTarget: 'name',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // COMPENSATABLE, not Guaranteed: re-declaring the screen restores it, but the delete is not
                    // free — the prior rows/columns are gone unless the caller re-supplies them.
                    Reversibility::Compensatable,
                    subject: Subject::Data,
                    rollbackContract: 'declare the screen again with screen:declare',
                ),
            ),
        ];
    }
}
