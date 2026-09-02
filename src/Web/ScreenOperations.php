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
use Milpa\Command\Effect\Authority;
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
    /**
     * @param list<string> $types the component types a screen may be declared as (data-table, state-machine,
     *                            …); an unknown type is refused so a screen never registers against a class
     *                            that does not exist. Empty means «any» (validation deferred to registration).
     */
    public function __construct(
        private readonly ScreenStore $store,
        private readonly array $types = [],
        private readonly ?LayoutStateStore $layout = null,
    ) {
    }

    /**
     * The operations the live screen store contributes: screen:declare, screen:list and screen:forget.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'screen:declare',
                description: 'Declare a live screen by name and component type (default data-table) with its props. It is served at /live/page?component=<name> with no code deploy. A data-table may pass columns/rows at the top level; any type passes its props under "props".',
                handler: fn (array $input): array => $this->declare($input),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'a-z, 0-9, dash; starts with a letter'],
                        'type' => $this->types === []
                            ? ['type' => 'string', 'description' => 'the SDK component type; default data-table']
                            : ['type' => 'string', 'enum' => $this->types, 'description' => 'the SDK component type; default data-table'],
                        'props' => ['type' => 'object', 'description' => 'the component-type props (e.g. state-machine: { machine: { initial, transitions } })'],
                        'columns' => ['type' => 'array', 'description' => 'data-table convenience: list of { key, label }'],
                        'rows' => ['type' => 'array', 'description' => 'data-table convenience: list of row objects keyed by column key'],
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
            new Operation(
                name: 'screen:set-state',
                description: 'Set one shared value of a layout (its layout state) for a session — what a child WRITES so another child READS it. Server-authoritative and isolated per session; the layout recomputes from it.',
                handler: fn (array $input): array => $this->setState($input),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['session', 'screen', 'key', 'value'],
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'the session whose layout state to set (its owner)'],
                        'screen' => ['type' => 'string', 'description' => 'the layout screen'],
                        'key' => ['type' => 'string', 'description' => 'the shared key (e.g. a filter name)'],
                        'value' => ['type' => 'string', 'description' => 'the shared value'],
                    ],
                ],
                mutating: true,
                // The contract decides the cost, not the syntax (greenhouse decisions/0169): setting a layout's
                // shared value is EPHEMERAL, touches only the caller's own session (WriteAsUser), reaches nothing
                // outside it, and is fully reversible. So the composition can conclude it is cheap — no human
                // ceremony — while a heavier write that happened to use the same shape would NOT, because its
                // profile would say so.
                effects: new EffectProfile(
                    Mutation::Ephemeral,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::WriteAsUser,
                    subject: Subject::Data,
                    rollbackContract: 'set the key back with screen:set-state',
                ),
            ),
        ];
    }

    /**
     * Set one shared value of a layout's state for a session (greenhouse decisions/0169). Needs a wired
     * {@see LayoutStateStore}; without one it is a no-op that says so, never a silent success.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function setState(array $input): array
    {
        if (! $this->layout instanceof LayoutStateStore) {
            return ['ok' => false, 'error' => 'no layout state store is wired'];
        }

        return $this->layout->set(
            (string) ($input['session'] ?? ''),
            (string) ($input['screen'] ?? ''),
            (string) ($input['key'] ?? ''),
            (string) ($input['value'] ?? ''),
        );
    }

    /**
     * Refuse an unknown component type before it reaches the store, so a screen never registers against a
     * class that does not exist (the safety net at registration would just 404 it silently). An empty type
     * list means «any» — validation is deferred to registration.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function declare(array $input): array
    {
        $type = trim((string) ($input['type'] ?? ScreenStore::DEFAULT_TYPE));
        if ($type !== '' && $this->types !== [] && ! \in_array($type, $this->types, true)) {
            return ['ok' => false, 'error' => 'unknown component type', 'type' => $type, 'known' => $this->types];
        }

        $result = $this->store->declare($input);

        // THE OPERATION DECLARES WHAT IT DEMONSTRATED (greenhouse decisions/0187). A served screen is
        // real, verifiable evidence — a reader opens it at `servedAt` — but it is none of the three
        // producer-shaped facts a work claim used to recognise. So the successful result carries a
        // served-evidence receipt: a predicate («served») and its subject (the screen), the shape the
        // judge reads by what it DEMONSTRATES rather than by who produced it. It rides the
        // `session.tool_called` fact this call already leaves; nothing here indexes it a second time.
        if (($result['ok'] ?? false) === true
            && \is_string($result['screen'] ?? null)
            && \is_string($result['servedAt'] ?? null)
        ) {
            $result['evidence'] = [
                'predicate' => 'served',
                'subject' => $result['screen'],
                'servedAt' => $result['servedAt'],
            ];
        }

        return $result;
    }
}
