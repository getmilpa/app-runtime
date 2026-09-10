<?php

/**
 * This file is part of milpa/app-runtime — the Milpa PHP framework's application runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Operations;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Command\CommandProvider;
use Milpa\Runtime\Stack\ComposeProjection;
use Milpa\Runtime\Stack\StackReader;
use Milpa\Runtime\Stack\TcpProbe;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * WHAT BACKING SERVICES THIS APP'S PLUGINS DECLARED THEY NEED, AND WHETHER THEY ANSWER.
 *
 * 🚨 THIS OPERATION EXISTS BECAUSE A FRESH APP COULD NOT SEE ITS OWN DECLARATION. The contract has
 * shipped since greenhouse decisions/0201 and `milpa/agent-workspace` declares its Mercure hub through
 * it as complete data — image, ports, env with secret config keys, CORS directives. The only reader was
 * inside `milpa/admin`, which is OPT-IN.
 *
 * Measured on fresh cattle: a Mercure hub was running on 127.0.0.1:3000, the exact port that declaration
 * names, and the app answered `GET /desktop/hub` with `{}`, printed «The live hub is not connected», and
 * swallowed the one sentence that says where to go, because that sentence names a panel section shipped
 * elsewhere. The declaration was written and unread (greenhouse decisions/0282).
 *
 * READING IS NOT RUNNING, AND THAT IS WHY THIS IS ONE OPERATION AND NOT TWO. decisions/0201 says the
 * runtime starts nothing and decisions/0252 says starting containers because somebody opened a page is
 * authority a panel does not have. Both stand: they are decisions about the VERB. Rod's call when this
 * was put to him was «complete the saying, not the running» — so this operation says, completely, and
 * starts nothing.
 *
 * 🚨 IT HAS NO `probe` FLAG, AND THAT IS A DECISION. `agent:model` has one because asking its provider
 * costs 5.0 s against a dead endpoint (greenhouse decisions/0281). Here the measurement is different:
 * {@see TcpProbe} is a loopback connect with a 250 ms timeout, paid only for a port that REFUSES — a
 * live service answers in about a millisecond. And «is my stack up» is the question a person runs this
 * to ask, so a flag to skip the answer would be the fourth accessor nobody asked for in this family
 * (greenhouse decisions/0213). It enters when a caller needs declaration-only, not before, which is
 * what {@see \Milpa\Runtime\Stack\ServiceDeclaration} says about its own future fields.
 *
 * AND IT DOES NOT SAY `docker compose up -d <name>`. That sentence exists once, in the panel's catalog,
 * and a command is not copy — a second literal in another package is how a command drifts
 * (greenhouse decisions/0277). What this returns is the compose fragment the declaration was shaped
 * for; whoever needs the sentence shared graduates it to the runtime, next to the declaration.
 */
final class StackOperations implements CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * The one operation this provider contributes.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'stack',
                description: 'The backing services this app\'s plugins declared they need, with whether each answers on its port, its resolved env (never a secret\'s value) and its compose fragment',
                handler: fn (array $input): array => $this->report(),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'kernel' => ['type' => 'boolean', 'description' => 'Whether a booted kernel was there to read plugins from; false means the answer is «I cannot see», not «nothing is declared»'],
                        'services' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'One entry per declared service, sorted by name, each with its state: up, down, unknown (no published port to probe) or conflict (two plugins declared the name)'],
                    ],
                    'required' => ['ok'],
                ],
                // IT READS AND IT REACHES ITS OWN LOOPBACK, so `Externality` is `SamePrincipal` and not
                // `None`: a TCP connect does leave the process boundary, and `None` is documented as
                // «nothing leaves». What it reaches is a service THIS app declared, on this host —
                // systems the same principal already controls, which is exactly that level. Calling it
                // `None` would be the honest-looking lie, and calling it `ThirdParty` would be the
                // over-declaration that makes a ceiling useless.
                //
                // Every other axis is a read, so `Consent::demanded()` is false and the framework's
                // boot guard publishes it without a scope (greenhouse decisions/0279).
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::SamePrincipal,
                    reversibility: Reversibility::NotApplicable,
                    authority: Authority::Read,
                    subject: Subject::None,
                ),
                surfaces: ['cli', 'tui', 'mcp'],
            ),
        ];
    }

    /**
     * The snapshot, read through the family's one reader.
     *
     * @return array{ok: bool, kernel: bool, services: list<array<string, mixed>>}
     */
    private function report(): array
    {
        $reader = new StackReader($this->container, new TcpProbe(), new ComposeProjection());
        $snapshot = $reader->snapshot();

        return [
            'ok' => true,
            'kernel' => $snapshot['kernel'],
            'services' => $snapshot['services'],
        ];
    }
}
