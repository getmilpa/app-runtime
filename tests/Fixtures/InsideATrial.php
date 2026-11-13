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

namespace Milpa\AppRuntime\Tests\Fixtures;

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Support\ChildProcess;

/**
 * Runs a PHP script INSIDE A REAL TRIAL: the confinement {@see TrialRunner::confinement()} builds for `$tree`, with its
 * home, its mark and its network as a trial has them. A process is in a trial by what its room shows, never by a
 * variable it is handed (greenhouse decisions/0631), so a test that needs "inside a trial" runs there. Null where this
 * machine cannot confine — the caller skips.
 */
final class InsideATrial
{
    /** @return array{exit: int, stdout: string, stderr: string}|null */
    public static function run(string $tree, string $script): ?array
    {
        $confinement = (new TrialRunner())->confinement($tree, $tree);
        if ($confinement === null) {
            return null;
        }

        return ChildProcess::run([...$confinement, '--', \PHP_BINARY, '-d', 'display_errors=stderr', $script]);
    }
}
