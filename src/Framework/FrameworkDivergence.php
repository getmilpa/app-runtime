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
 * WHAT THIS HOUSE HAS CHANGED SINCE IT WAS BORN — the first two of the update's three points.
 *
 * A house is a COPY of `milpa/framework`, so «update the framework» cannot be a composer update: the
 * skeleton is not a dependency, and its files became the app's files. Reconciling them needs three
 * points — what the house was BORN with, what it has NOW, and what the new skeleton SHIPS — because
 * only the first two together can tell «the app customized this file» from «the app left it alone»,
 * and those demand opposite answers when the third arrives (greenhouse decisions/0291).
 *
 * This class answers the first two, which needs no network and is useful on its own: a person can see
 * what their house has diverged into before anybody offers them anything. The third point is the next
 * slice, and it is a VERB — asking a remote registry what the latest release contains is egress, and
 * this house measured what probing on paint costs (greenhouse decisions/0281: PINTAR NO SONDEA).
 *
 * 🚨 IT DOES NOT LIVE IN THE SKELETON, and that is structural rather than tidy. Code in
 * `milpa/framework` is COPIED into the app and then frozen — a reconciler shipped there would go as
 * stale as the files it reconciles, and would be reconciling a newer skeleton with an older
 * reconciler. It lives in a package `composer update` can move.
 */
final class FrameworkDivergence
{
    /** The file is exactly the bytes the skeleton handed over. */
    public const string UNTOUCHED = 'untouched';

    /** The house edited it. When the skeleton also changes it, this is the one that needs a diff. */
    public const string CUSTOMIZED = 'customized';

    /** The house deleted it. Not a defect: an app may drop the starter plugin, and often should. */
    public const string DELETED = 'deleted';

    /**
     * Every tracked file, with what became of it, sorted by path.
     *
     * Files the HOUSE ADDED are deliberately not listed. The birth record only knows what the skeleton
     * handed over, so «added» here would mean «every file of the app you have written since» — which
     * is the app, not a divergence from anything, and would drown the three rows that matter.
     *
     * @return list<array{path: string, status: string}>
     */
    public static function rows(string $root): array
    {
        $born = FrameworkStamp::born($root);
        if ($born === null) {
            return [];
        }

        $rows = [];
        foreach ($born['files'] as $path => $hash) {
            $file = $root . '/' . $path;
            if (!is_file($file)) {
                $rows[] = ['path' => $path, 'status' => self::DELETED];

                continue;
            }
            $rows[] = [
                'path' => $path,
                'status' => hash_file('sha256', $file) === $hash ? self::UNTOUCHED : self::CUSTOMIZED,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        return $rows;
    }

    /**
     * The counts a screen leads with, and the version the comparison is against.
     *
     * `null` when the house carries no birth record — a house created before the stamp existed cannot
     * say, and counting zero customized files would read as «nothing changed» rather than «unknown».
     * Those two are not the same answer and the difference is the whole point of this class.
     *
     * @return array{born: string, at: string, untouched: int, customized: int, deleted: int}|null
     */
    public static function summary(string $root): ?array
    {
        $born = FrameworkStamp::born($root);
        if ($born === null) {
            return null;
        }

        $counts = [self::UNTOUCHED => 0, self::CUSTOMIZED => 0, self::DELETED => 0];
        foreach (self::rows($root) as $row) {
            ++$counts[$row['status']];
        }

        return [
            'born' => $born['version'],
            'at' => $born['at'],
            'untouched' => $counts[self::UNTOUCHED],
            'customized' => $counts[self::CUSTOMIZED],
            'deleted' => $counts[self::DELETED],
        ];
    }
}
