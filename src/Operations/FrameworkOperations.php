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

use Milpa\AppRuntime\Framework\FrameworkApply;
use Milpa\AppRuntime\Framework\FrameworkDivergence;
use Milpa\AppRuntime\Framework\FrameworkReconciliation;
use Milpa\AppRuntime\Framework\FrameworkRelease;
use Milpa\AppRuntime\Framework\FrameworkStamp;
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
 * These are OPERATIONS and not panel routes because applying one is a governed act: it writes source
 * files that will run inside this house. An operation is the trunk — the same declaration reaches the
 * terminal, MCP, the TUI and HTTP, judged by the same ceiling everywhere (greenhouse decisions/0135) —
 * and a panel cannot declare one. That is why this family moved out of `milpa/admin` (decisions/0295).
 */
final readonly class FrameworkOperations implements CommandProvider
{
    /**
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
                handler: fn (array $input): array => $this->provenance(),
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
                handler: fn (array $input): array => $this->diff($input),
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
                handler: fn (array $input): array => $this->apply($input),
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

    /**
     * @return array<string, mixed>
     */
    private function provenance(): array
    {
        $root = Capabilities::raizDeLaApp();
        $summary = FrameworkDivergence::summary($root);
        if ($summary === null) {
            return [
                'version' => FrameworkStamp::version($root),
                'born' => null,
                'untouched' => 0,
                'customized' => 0,
                'deleted' => 0,
                'files' => [],
                // «Nothing changed» and «I cannot say» are different answers, and zeros would print the
                // first while meaning the second (greenhouse decisions/0293).
                'cannot_say' => 'this house carries no birth record, so nothing can say what it has changed — houses created with milpa/framework 0.48 or later record it',
            ];
        }

        return [
            'version' => FrameworkStamp::version($root),
            'born' => $summary['born'],
            'untouched' => $summary['untouched'],
            'customized' => $summary['customized'],
            'deleted' => $summary['deleted'],
            'files' => array_values(array_filter(
                FrameworkDivergence::rows($root),
                static fn (array $row): bool => $row['status'] !== FrameworkDivergence::UNTOUCHED,
            )),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function diff(array $input): array
    {
        $root = Capabilities::raizDeLaApp();
        [$version, $ships, $refusal] = $this->against($input, $root);
        if ($refusal !== null) {
            return ['against' => $version, 'actionable' => 0, 'files' => [], 'cannot_say' => $refusal];
        }

        $rows = FrameworkReconciliation::rows($root, $ships);
        $summary = FrameworkReconciliation::summary($root, $ships);
        if ($rows === null || $summary === null) {
            return [
                'against' => $version,
                'actionable' => 0,
                'files' => [],
                'cannot_say' => 'this house carries no birth record, so a newer release cannot be compared against anything',
            ];
        }

        return [
            'against' => $version,
            'actionable' => $summary['actionable'],
            'files' => array_values(array_filter(
                $rows,
                static fn (array $row): bool => !\in_array($row['status'], [FrameworkReconciliation::SETTLED, FrameworkReconciliation::KEPT], true),
            )),
        ];
    }

    /**
     * Writes only what the reconciliation calls `offered` or `added`, and says what it left.
     *
     * ── WHY ONLY THOSE TWO ──────────────────────────────────────────────────────────────────────────
     *
     * `offered` is «the skeleton moved this file and the house did not» — the one case where taking the
     * new bytes loses nothing, because the bytes being replaced are the ones the skeleton handed over.
     * `added` is a file this house does not have. Everything else is left, by name:
     *
     *   · `conflicted` — both moved. Overwriting is losing the house's work; a person decides, with a diff.
     *   · `kept`       — the house moved it and the skeleton did not. There is nothing to take.
     *   · `unrecorded` — the house has it and its birth bytes were never recorded, so nothing can say
     *                    whether it diverged. That is the `conflicted` risk without the evidence.
     *   · `settled`    — already identical.
     *
     * ── AND WHY IT REFUSES WITHOUT GIT ──────────────────────────────────────────────────────────────
     *
     * 🚨 `Reversibility::ManualRecovery` above is a PROMISE that a person can get back. Git is how, and
     * the panel's own copy says so in as many words — «you will see that file change in git». So this
     * refuses when git cannot be that: not a repository, or the targets already carry uncommitted
     * changes. Writing over an uncommitted edit is the one way this operation could destroy work that
     * has no copy anywhere, and declaring recoverability while removing the means is worse than
     * declaring the act irreversible.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function apply(array $input): array
    {
        $root = Capabilities::raizDeLaApp();
        [$version, $ships, $refusal] = $this->against($input, $root);
        if ($refusal !== null) {
            return ['applied' => [], 'left' => [], 'refused' => $refusal];
        }

        $rows = FrameworkReconciliation::rows($root, $ships);
        if ($rows === null) {
            return ['applied' => [], 'left' => [], 'refused' => 'this house carries no birth record, so nothing can be judged safe to take'];
        }

        $takeable = array_values(array_filter(
            $rows,
            static fn (array $row): bool => \in_array($row['status'], [FrameworkReconciliation::OFFERED, FrameworkReconciliation::ADDED], true),
        ));
        $left = array_values(array_map(
            static fn (array $row): array => ['path' => $row['path'], 'why' => $row['status']],
            array_filter(
                $rows,
                static fn (array $row): bool => \in_array($row['status'], [FrameworkReconciliation::CONFLICTED, FrameworkReconciliation::UNRECORDED], true),
            ),
        ));

        if ($takeable === []) {
            return ['applied' => [], 'left' => $left, 'refused' => 'nothing is safe to take: every file is either already the newest, one this house changed, or one whose original was never recorded'];
        }

        $blocked = $this->gitCannotBeTheWayBack($root, array_column($takeable, 'path'));
        if ($blocked !== null) {
            return ['applied' => [], 'left' => $left, 'refused' => $blocked];
        }

        // Fetched a second time, on purpose: the hashes came from a cache that could be days old, and
        // the BYTES are what gets written. A cache is a fine answer to «what would change»; it is not
        // one to «what shall I write into this house».
        $tree = FrameworkRelease::fetch($version, $root);
        if ($tree === null) {
            return ['applied' => [], 'left' => $left, 'refused' => 'the release could not be fetched to take its bytes from'];
        }

        $applied = FrameworkApply::take($tree, $root, array_column($takeable, 'path'));
        FrameworkRelease::discard($tree);

        return ['applied' => $applied, 'left' => $left];
    }

    /**
     * The release to judge against and its hashes, or the sentence saying why neither is available.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: string|null, 1: array<string, string>, 2: string|null}
     */
    private function against(array $input, string $root): array
    {
        $asked = \is_string($input['version'] ?? null) && $input['version'] !== '' ? $input['version'] : null;
        $version = $asked ?? FrameworkRelease::latest();
        if ($version === null) {
            return [null, [], 'the package registry could not be reached, so there is nothing to compare against'];
        }
        $ships = FrameworkRelease::ships($version, $root);
        if ($ships === null) {
            return [$version, [], 'release ' . $version . ' could not be fetched'];
        }

        return [$version, $ships, null];
    }

    /**
     * Why git cannot be the way back for these paths, or null when it can.
     *
     * @param list<string> $paths
     */
    private function gitCannotBeTheWayBack(string $root, array $paths): ?string
    {
        exec('git -C ' . escapeshellarg($root) . ' rev-parse --is-inside-work-tree 2>/dev/null', $out, $status);
        if ($status !== 0) {
            return 'this house is not a git repository, so there would be no way back from an overwrite — commit it to git first, or take the files by hand';
        }

        // 🚨 TRACKED FIRST, AND CLEAN SECOND. `git status --porcelain` answers EMPTY for a file git has
        // never seen — an untracked or ignored one — which reads exactly like «clean». Measured in the
        // real lab layout, where the app sits inside this house's own repository under a gitignored
        // `var/lab/`: `rev-parse` said yes, `status` said clean, and git held no copy of a single file.
        // The guard would have declared a way back that did not exist (greenhouse decisions/0295).
        $untracked = [];
        $dirty = [];
        foreach ($paths as $path) {
            $lines = [];
            exec('git -C ' . escapeshellarg($root) . ' ls-files --error-unmatch -- ' . escapeshellarg($path) . ' 2>/dev/null', $lines, $tracked);
            if ($tracked !== 0) {
                // A file the release ADDS is not in this house yet, so of course git has never seen it:
                // writing it destroys nothing and `git status` will show it as new.
                if (is_file($root . '/' . $path)) {
                    $untracked[] = $path;
                }

                continue;
            }
            $lines = [];
            exec('git -C ' . escapeshellarg($root) . ' status --porcelain -- ' . escapeshellarg($path) . ' 2>/dev/null', $lines, $code);
            if ($code === 0 && $lines !== []) {
                $dirty[] = $path;
            }
        }
        if ($untracked !== []) {
            return 'git has never seen these files, so committing them is the only way back and it has not happened: ' . implode(', ', $untracked);
        }
        if ($dirty !== []) {
            return 'these files carry uncommitted changes, and writing over them would destroy work with no copy anywhere: ' . implode(', ', $dirty);
        }

        return null;
    }
}
