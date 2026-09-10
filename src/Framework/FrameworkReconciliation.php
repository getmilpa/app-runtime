<?php

/**
 * This file is part of milpa/app-runtime.
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
 * THE THREE POINTS, JUDGED: what a newer skeleton would do to this house, file by file.
 *
 * Born ({@see FrameworkStamp::born()}), now (the tree), ships ({@see FrameworkRelease::ships()}). Each
 * tracked path falls into exactly one of the five answers below, and the reason there are five rather
 * than «changed / unchanged» is that the same new bytes mean opposite things depending on whether the
 * HOUSE touched the file (greenhouse decisions/0294).
 *
 * ── THE TABLE THIS CLASS IS ─────────────────────────────────────────────────────────────────────────
 *
 *   born == now   born == ships   →  SETTLED     nothing to do; the file is the skeleton's and unchanged
 *   born == now   born != ships   →  OFFERED     the skeleton moved, the house did not: safe to apply
 *   born != now   born == ships   →  KEPT        the house moved, the skeleton did not: leave it alone
 *   born != now   born != ships   →  CONFLICTED  both moved: a person decides, with a diff
 *   absent in born, in ships, absent on disk  →  ADDED       the skeleton grew a file this house never got
 *   absent in born, in ships, ON DISK         →  UNRECORDED  the house HAS it; its birth bytes were never
 *                                                            recorded, so nothing can say if it diverged
 *
 * 🚨 `UNRECORDED` EXISTS BECAUSE THE FIRST BUILD LIED ABOUT IT. Measured on a real house born from
 * 0.48.0 whose record holds 15 paths while the release now tracks 20 — the skeleton's tracked set was
 * widened afterwards (greenhouse decisions/0294) — `composer.json` came back `ADDED`, «the skeleton
 * grew a file this house never received». The house has that file. What it does not have is a RECORD of
 * it, and those are the two answers this whole arc keeps insisting are different. Offering bytes over a
 * file whose original was never recorded is the `CONFLICTED` risk without the evidence, so it counts as
 * something a person must look at, and it never reads as new.
 *
 * A file the house DELETED and the skeleton changed is `CONFLICTED` too — «bring it back?» is the same
 * question with the same answer shape, and inventing a sixth state for it would split one decision.
 *
 * ── WHAT IT REFUSES TO DO ───────────────────────────────────────────────────────────────────────────
 *
 * It never APPLIES anything and holds no bytes of the new release: it answers what each file's answer
 * IS. Writing into a house is a governed act, it belongs behind the panel's door, and it is the slice
 * after this one. A reader that could also write would make «show me» and «do it» one click apart.
 *
 * And with no birth record it answers null rather than a table of `ADDED`. A house that cannot say
 * what it was born with cannot be told what changed — every file would look new, and «all twenty files
 * are new» is the most confidently wrong thing this screen could print.
 */
final class FrameworkReconciliation
{
    public const string SETTLED = 'settled';

    public const string OFFERED = 'offered';

    public const string KEPT = 'kept';

    public const string CONFLICTED = 'conflicted';

    public const string ADDED = 'added';

    /** In the new release and not in the birth record, but on disk: present, unexplained. */
    public const string UNRECORDED = 'unrecorded';

    /**
     * Every tracked path with its answer, sorted, or null when this house has no birth record.
     *
     * @param array<string, string> $ships the new release's hashes, from {@see FrameworkRelease::ships()}
     *
     * @return list<array{path: string, status: string}>|null
     */
    public static function rows(string $root, array $ships): ?array
    {
        $born = FrameworkStamp::born($root);
        if ($born === null) {
            return null;
        }

        $rows = [];
        foreach ($born['files'] as $path => $birth) {
            $file = $root . '/' . $path;
            $now = is_file($file) ? (hash_file('sha256', $file) ?: '') : null;
            $new = $ships[$path] ?? null;

            $houseMoved = $now !== $birth;
            $skeletonMoved = $new !== null && $new !== $birth;

            $rows[] = ['path' => $path, 'status' => match (true) {
                $houseMoved && $skeletonMoved => self::CONFLICTED,
                $skeletonMoved => self::OFFERED,
                $houseMoved => self::KEPT,
                default => self::SETTLED,
            }];
        }

        foreach ($ships as $path => $new) {
            if (isset($born['files'][$path])) {
                continue;
            }
            $rows[] = [
                'path' => $path,
                'status' => is_file($root . '/' . $path) ? self::UNRECORDED : self::ADDED,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        return $rows;
    }

    /**
     * The counts, and how many files a person would actually have to look at.
     *
     * `actionable` is the number this screen leads with: offered + conflicted + added + unrecorded.
     * `settled` and `kept` need nobody's attention, and a headline counting them would make every
     * update look big.
     *
     * @param array<string, string> $ships
     *
     * @return array{settled: int, offered: int, kept: int, conflicted: int, added: int, unrecorded: int, actionable: int}|null
     */
    public static function summary(string $root, array $ships): ?array
    {
        $rows = self::rows($root, $ships);
        if ($rows === null) {
            return null;
        }

        $counts = [self::SETTLED => 0, self::OFFERED => 0, self::KEPT => 0, self::CONFLICTED => 0, self::ADDED => 0, self::UNRECORDED => 0];
        foreach ($rows as $row) {
            ++$counts[$row['status']];
        }

        return [
            'settled' => $counts[self::SETTLED],
            'offered' => $counts[self::OFFERED],
            'kept' => $counts[self::KEPT],
            'conflicted' => $counts[self::CONFLICTED],
            'added' => $counts[self::ADDED],
            'unrecorded' => $counts[self::UNRECORDED],
            'actionable' => $counts[self::OFFERED] + $counts[self::CONFLICTED] + $counts[self::ADDED] + $counts[self::UNRECORDED],
        ];
    }
}
