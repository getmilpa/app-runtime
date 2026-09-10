<?php

/**
 * This file is part of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Operations;

use Milpa\AppRuntime\Framework\FrameworkUpdate;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * WHAT THIS HOUSE WAS BORN FROM, WHAT IT CHANGED, AND WHAT A NEWER SKELETON WOULD DO — as operations.
 *
 * `milpa/framework` is a SKELETON: `create-project` copies its files and the package is gone, so
 * «update the framework» is not a composer update. It needs three points — born, now, ships — and
 * `.milpa/framework.json` is what makes the first knowable at all (greenhouse decisions/0291, 0293,
 * 0294).
 *
 * This class DECLARES and delegates: everything that decides takes a `$root` and lives in
 * {@see FrameworkUpdate}, because `CommandProvider` fixes this constructor to no arguments and
 * `Capabilities::raizDeLaApp()` asks Composer — so a decider written here could never be pointed at a
 * fixture house, and the coverage floor said so.
 *
 * These are OPERATIONS and not panel routes because applying one is a governed act: it writes source
 * files that will run inside this house. An operation is the trunk — the same declaration reaches the
 * terminal, MCP, the TUI and HTTP, judged by the same ceiling everywhere (greenhouse decisions/0135) —
 * and a panel cannot declare one. That is why this family moved out of `milpa/admin` (decisions/0295).
 */
final readonly class FrameworkOperations implements CommandProvider
{
    /**
     * The three framework operations this group contributes to the registry.
     *
     * Returned rather than self-registered, like every other group here: whoever assembles the registry
     * decides which groups enter and with what authority, and a group that registered itself would take
     * that decision away.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'framework:provenance',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    // NotApplicable and not Guaranteed: there is nothing to undo, and `Guaranteed`
                    // demands a compensation that cites its arguments — a promise a read cannot keep
                    // and does not need (greenhouse «contrato de reversa»).
                    Reversibility::NotApplicable,
                    Authority::Read,
                    // NO SUBJECT, because `EffectProfile` refuses one here and is right to: «an
                    // operation that changes nothing cannot declare a subject — Mutation::None and
                    // «data» disagree about whether anything happens». The contract taught me its own
                    // rule when I gave it `Subject::Data` out of habit.
                    subject: Subject::None,
                ),
                description: 'Which milpa/framework this house was born from, and which of the files it received have changed since',
                handler: static fn (array $input): array => FrameworkUpdate::provenance(Capabilities::raizDeLaApp()),
                inputSchema: ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'version' => ['type' => ['string', 'null'], 'description' => 'The framework version this tree says it is, or null when it carries no record'],
                        'born' => ['type' => ['string', 'null'], 'description' => 'The version this house was created from'],
                        'untouched' => ['type' => 'integer'],
                        'customized' => ['type' => 'integer'],
                        'deleted' => ['type' => 'integer'],
                        'files' => ['type' => 'array', 'description' => 'Every file that is not untouched, with what became of it'],
                        'cannot_say' => ['type' => 'string', 'description' => 'Present only when there is no birth record: why nothing can be compared'],
                    ],
                ],
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'framework:diff',
                effects: new EffectProfile(
                    Mutation::None,
                    // IT ASKS A REGISTRY AND FETCHES A RELEASE. Nothing of this house leaves, but a
                    // third party is asked and its bytes arrive — measured at 567 ms to ask and 1.0 s
                    // to fetch, which is exactly why it is a verb somebody runs and never something a
                    // render does (greenhouse decisions/0281, 0294).
                    Externality::ThirdParty,
                    Reversibility::NotApplicable,
                    Authority::Read,
                    // NO SUBJECT, because `EffectProfile` refuses one here and is right to: «an
                    // operation that changes nothing cannot declare a subject — Mutation::None and
                    // «data» disagree about whether anything happens». The contract taught me its own
                    // rule when I gave it `Subject::Data` out of habit.
                    subject: Subject::None,
                ),
                description: 'What a newer milpa/framework would do to this house, file by file — asks the registry, writes nothing',
                handler: static fn (array $input): array => FrameworkUpdate::diff(Capabilities::raizDeLaApp(), \is_string($input['version'] ?? null) ? $input['version'] : null),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'version' => ['type' => 'string', 'description' => 'The release to compare against; omitted means the newest published'],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'against' => ['type' => ['string', 'null']],
                        'actionable' => ['type' => 'integer', 'description' => 'How many files would need a decision: offered + conflicted + added + unrecorded'],
                        'files' => ['type' => 'array'],
                        'cannot_say' => ['type' => 'string'],
                    ],
                ],
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'framework:apply',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    // The bytes come from the registry — the same package this house was created from,
                    // but a third party all the same, and what arrives is code that will run here.
                    Externality::ThirdParty,
                    // Git is the way back, which is why this refuses to run when git cannot be the way
                    // back. That makes it recoverable BY A PERSON, not by an inverse this operation has.
                    Reversibility::ManualRecovery,
                    // It rewrites the files this app boots from.
                    Authority::Privileged,
                    subject: Subject::Executable,
                ),
                description: 'Take the files a newer milpa/framework changed that this house did not — never the ones it customized',
                handler: static fn (array $input): array => FrameworkUpdate::apply(Capabilities::raizDeLaApp(), \is_string($input['version'] ?? null) ? $input['version'] : null),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'version' => ['type' => 'string', 'description' => 'The release to take files from; omitted means the newest published'],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'applied' => ['type' => 'array', 'description' => 'The paths written'],
                        'left' => ['type' => 'array', 'description' => 'The paths deliberately not written, each with why'],
                        'refused' => ['type' => 'string', 'description' => 'Present when nothing was written at all, and why'],
                    ],
                ],
                // `mutating` AND the profile must agree. The flag is what decides POST versus GET on
                // the HTTP surface, and the profile is what the gate judges — declared Persistent with
                // the flag left false, this act would have been served as a GET. A test pins both.
                mutating: true,
                scopes: ['framework:apply'],
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
        ];
    }

}
