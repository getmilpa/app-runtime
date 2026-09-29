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

use Milpa\Plugin\Contracts\BootWitnessInterface;

/**
 * TAKES BYTES FROM A FETCHED RELEASE INTO A HOUSE — the only thing in this family that writes.
 *
 * Separate from `framework:apply` for two reasons, and the second is the real one. It keeps the writing
 * away from the deciding: the operation resolves a version, judges the three points and refuses when
 * git cannot be the way back, and this copies files. And it makes the writing testable WITHOUT the
 * network — the operation's own path shells out to composer to fetch a release, which no unit test
 * should need in order to prove that a conflicted file is left alone (greenhouse decisions/0295).
 *
 * It takes the rows already judged. It does not re-judge, and it must not: two places deciding what is
 * safe to overwrite is one place too many, and the one that would be wrong is whichever was written
 * second.
 */
final class FrameworkApply
{
    /**
     * Copies each named path from the release tree into the house, and returns the ones it wrote.
     *
     * A path the release does not actually carry is skipped rather than reported: the hashes came from a
     * cache and the bytes from a fresh fetch, so the two can disagree — a release re-tagged between the
     * two would otherwise have this write nothing while claiming it wrote.
     *
     * @param list<string> $paths the paths the reconciliation judged safe to take
     *
     * @return list<string>
     */
    public static function take(string $tree, string $root, array $paths): array
    {
        $written = [];
        foreach ($paths as $path) {
            $from = $tree . '/' . $path;
            $to = $root . '/' . $path;
            if (!is_file($from)) {
                continue;
            }
            @mkdir(\dirname($to), 0o775, true);
            if (copy($from, $to)) {
                $written[] = $path;
            }
        }

        return $written;
    }

    /**
     * {@see take()}, but only if the house boots with the release's bytes — asked of a copy before one lands.
     *
     * What `framework:apply` takes is `bin/coa`, `public/index.php`, `config/*.php`: the files the house boots
     * from (greenhouse decisions/0515). With a witness, the bytes are booted in a copy of the house first; a
     * release the house cannot boot with writes nothing (`unwritten`), and one that fails once landed is put
     * back (`rolled_back`). Without one it is {@see take()}, as it always was.
     *
     * @param list<string> $paths the paths the reconciliation judged safe to take
     *
     * @return array{applied: list<string>, refused: ?string, said: array<string, mixed>}
     */
    public static function takeIfItBoots(string $tree, string $root, array $paths, ?BootWitnessInterface $witness): array
    {
        if ($witness === null) {
            return ['applied' => self::take($tree, $root, $paths), 'refused' => null, 'said' => []];
        }
        $writes = [];
        foreach ($paths as $path) {
            if (is_file($tree . '/' . $path)) {
                $writes[$path] = (string) file_get_contents($tree . '/' . $path);
            }
        }
        $applied = [];
        $boot = $witness->writeIfItBoots($writes, static function () use ($tree, $root, $paths, &$applied): void {
            $applied = self::take($tree, $root, $paths);
        });

        return ['applied' => $boot['refused'] === null ? $applied : [], 'refused' => $boot['refused'], 'said' => $boot['said']];
    }
}
