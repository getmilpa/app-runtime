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

namespace Milpa\AppRuntime\Framework;

/**
 * WHAT `framework:*` ACTUALLY DECIDES — separate from the operations, and TAKING a root.
 *
 * 🚨 IT LIVES HERE BECAUSE OF A TESTABILITY FACT, not tidiness. `CommandProvider` fixes an operation
 * group's constructor to no arguments, so a handler has to resolve the app root itself — and
 * `Capabilities::raizDeLaApp()` asks Composer, which answers for the app that is really running. There
 * is no seam: with the deciding inside the handlers, no test could point them at a fixture house, and
 * the coverage floor said so at 92.51% against a floor of 95 (greenhouse decisions/0295).
 *
 * So the operations declare and delegate, and everything that decides takes a `$root`. The only parts
 * that remain untestable offline are the two in {@see FrameworkRelease} that reach the registry, and
 * those are measured on cattle with the numbers written where they are ignored.
 */
final class FrameworkUpdate
{
    /**
     * @return array<string, mixed>
     */
    public static function provenance(string $root): array
    {
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
     * @return array<string, mixed>
     */
    public static function diff(string $root, ?string $version): array
    {
        [$version, $ships, $refusal] = self::against($root, $version);
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
     * @return array<string, mixed>
     */
    public static function apply(string $root, ?string $version): array
    {
        [$version, $ships, $refusal] = self::against($root, $version);
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

        $blocked = self::gitCannotBeTheWayBack($root, array_column($takeable, 'path'));
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
     * @return array{0: string|null, 1: array<string, string>, 2: string|null}
     */
    private static function against(string $root, ?string $asked): array
    {
        $version = ($asked !== null && $asked !== '') ? $asked : FrameworkRelease::latest();
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
    private static function gitCannotBeTheWayBack(string $root, array $paths): ?string
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
