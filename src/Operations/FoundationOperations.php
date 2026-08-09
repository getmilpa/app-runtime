<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Operations;

use Milpa\AppRuntime\Support\Foundation;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Operation;

/**
 * What this app IS, and the rite that declares it — greenhouse decisions/0004.
 *
 * ── TWO OPERATIONS, AND THE SECOND ONE IS A RITE ────────────────────────────────────────────────
 *
 * `foundation` reads the constitution; `foundation:found` writes it, exactly once. Before this
 * group existed, founding was doctrine without a system to teach it: no operation could touch
 * `.milpa/foundation.json`, and an agent asked to found an app could not — three runs out of
 * three (greenhouse evidence/0004). The rail exists so the rite can be OPERATED, and therefore
 * measured.
 *
 * ── WHY FOUNDING WEIGHS MORE THAN INSTALLING ────────────────────────────────────────────────────
 *
 * `capabilities:enable` changes what this app can DO; `foundation:found` changes what it IS.
 * Nothing in the catalogue weighs more, so it carries every gate the heaviest operation carries:
 * it is mutating (the agent proposes, a human consents), the domain is a named target the human
 * must have said (the Q-P20-J lesson: an authority gate you can walk around is a suggestion with
 * better press), and it is not offered over http — a surface reachable from anywhere must not be
 * able to redefine the app's identity.
 */
final readonly class FoundationOperations implements CommandProvider
{
    // No constructor, on purpose — same contract as CapabilityOperations: `Support\Operations`
    // builds zero-parameter providers without handing them the container.

    /**
     * The two foundation operations this group contributes to the registry.
     *
     * Returned rather than self-registered, like every other group here: whoever assembles the
     * registry decides which groups enter and with what authority, and a group that registered
     * itself would take that decision away from them.
     *
     * Neither one sits behind an installed capability, unlike every other optional group. The app
     * that has NOT been founded is precisely the one that needs the system to teach it the rite,
     * so an app whose constitution is still empty must be able to find the verb that fills it.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'foundation',
                effects: new EffectProfile(
                    Mutation::None,
                    // Reads `.milpa/foundation.json` from disk. Never the network.
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'What this app is — or, if not founded yet, how it becomes something',
                handler: static fn (array $input): array => Foundation::answer(),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
                // EVERY SURFACE, like `capabilities`: identity that only some callers can read
                // is not identity. An unfounded app TEACHES the rite instead of answering emptiness.
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'foundation:found',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    // Writes the constitution and its acta on local disk. Nothing leaves the box.
                    Externality::None,
                    // Deleting the file exists but is not a tested inverse: un-founding an app is
                    // a recorded decision, not a rollback.
                    Reversibility::ManualRecovery,
                    // It changes WHAT THIS APP IS. Nothing else in the catalogue does that.
                    Authority::Privileged,
                    escalatesOn: ['domain'],
                ),
                description: 'Found this app: write its constitution and the founding acta — once',
                handler: static fn (array $input): array => Foundation::found($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'domain' => [
                            'type' => 'string',
                            'description' => 'What this app is for, as the HUMAN named it',
                        ],
                        'objective' => [
                            'type' => 'string',
                            'description' => 'One sentence: what founding this domain is meant to achieve',
                        ],
                        'boundaries' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'What this app must never do',
                        ],
                        'authorities' => [
                            'type' => 'object',
                            'description' => 'Who decides what — anything not declared defaults to the human',
                        ],
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'Show the exact writes instead of performing them',
                        ],
                    ],
                    'required' => ['domain', 'objective'],
                ],
                // MUTATING, AND THE DOMAIN IS HUMAN-NAMED. Founding with a domain the agent chose
                // would be the agent deciding the app's identity — the exact move the named-target
                // contract (ADR-0044) exists to stop.
                mutating: true,
                namedTarget: 'domain',
                surfaces: ['cli', 'tui', 'mcp'],
            ),
        ];
    }
}
