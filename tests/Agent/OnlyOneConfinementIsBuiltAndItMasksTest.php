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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Agent\TrialRunner;
use PHPUnit\Framework\TestCase;

/**
 * THE MASK OF A CONFINEMENT IS BUILT IN ONE PLACE, AND EVERY CONFINEMENT GOES THROUGH IT.
 *
 * A confined process runs under `--ro-bind / /` — the whole machine, read-only — so each builder of a confinement
 * must mask the files the house keeps a secret in, or `--ro-bind / /` hands them over (greenhouse evidence/1161,
 * the C table's confined-read gap closed for `route:observe`). Two builders exist; this names them in one place and
 * fails if a third appears without the mask, and checks the mask lands AFTER the writable bind.
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class OnlyOneConfinementIsBuiltAndItMasksTest extends TestCase
{
    /** The whole-filesystem root bind that marks a process as confined. */
    private const ROOT_BIND = "'--ro-bind', '/', '/'";

    /** Who builds a confinement — said once. A file here either masks, or is only the namespace probe. */
    private const BUILDERS = [
        'src/Agent/HouseRouteObserver.php',
        'src/Agent/TrialRunner.php',
    ];

    public function testEveryFileThatBuildsAConfinementIsOnTheListAndGoesThroughTheMask(): void
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $building = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($directory as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), self::ROOT_BIND)) {
                $building[] = str_replace(\dirname(__DIR__, 2) . '/', '', $file->getPathname());
            }
        }
        sort($building);

        self::assertSame(
            self::BUILDERS,
            $building,
            'a confinement (--ro-bind / /) is built in a file that is not on the list: add it, and make it mask the secret files',
        );
        foreach (self::BUILDERS as $relative) {
            self::assertStringContainsString(
                'maskArgs',
                (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $relative),
                "{$relative} builds a confinement but does not go through the mask (TrialRunner::maskArgs)",
            );
        }
    }

    public function testTheMaskIsAppliedAfterTheWritableBind(): void
    {
        $root = sys_get_temp_dir() . '/milpa-confine-order-' . bin2hex(random_bytes(4));
        mkdir($root . '/.milpa', 0o777, true);
        file_put_contents($root . '/.milpa/secrets.json', '{"agent":{"apiKey":"x"}}');
        $writable = $root . '/var/boot-candidates/c';
        mkdir($writable, 0o777, true);

        try {
            $observer = new HouseRouteObserver();
            $method = new \ReflectionMethod(HouseRouteObserver::class, 'confineWith');
            /** @var list<string> $argv */
            $argv = $method->invoke($observer, $writable, $root);

            $bind = self::indexOfPair($argv, '--bind', $writable);
            $mask = self::indexOfPair($argv, '--ro-bind', '/dev/null');
            self::assertNotNull($bind, 'the writable is bound');
            self::assertNotNull($mask, 'the envelope is masked');
            self::assertGreaterThan($bind, $mask, 'the mask must come AFTER the writable bind, so the bind cannot re-expose a secret');
            self::assertContains($root . '/.milpa/secrets.json', $argv, 'the real envelope is one of the masked paths');
        } finally {
            @unlink($root . '/.milpa/secrets.json');
            @rmdir($root . '/.milpa');
            @rmdir($writable);
            @rmdir($root . '/var/boot-candidates');
            @rmdir($root . '/var');
            @rmdir($root);
        }
    }

    public function testTheMaskAlsoHidesTheKeyringTheHouseSignsWith(): void
    {
        // The one mask hides the keyring the house signs with, not only the house's secret files (greenhouse
        // evidence/1178): under `--ro-bind / /` a confined boot or trial could read the private key the Desktop
        // keeps mounted and sign a governed act as the person. A keyring is a directory, so it is masked with a
        // tmpfs, not `--ro-bind /dev/null`.
        $base = sys_get_temp_dir() . '/milpa-guard-keyring-' . bin2hex(random_bytes(4));
        $home = $base . '/home';
        $house = $base . '/house';
        mkdir($home . '/.gnupg', 0o700, true);
        mkdir($house, 0o700, true);
        $was = getenv('HOME');
        putenv("HOME={$home}");

        try {
            $args = (new TrialRunner())->maskArgs($house);
            $i = array_search($home . '/.gnupg', $args, true);
            self::assertNotFalse($i, 'the keyring the house signs with ($HOME/.gnupg) is in the mask');
            self::assertSame('--tmpfs', $args[$i - 1] ?? null, 'a keyring is masked with an empty tmpfs');
        } finally {
            $was === false ? putenv('HOME') : putenv("HOME={$was}");
            @rmdir($home . '/.gnupg');
            @rmdir($home);
            @rmdir($house);
            @rmdir($base);
        }
    }

    /** @param list<string> $argv */
    private static function indexOfPair(array $argv, string $flag, string $value): ?int
    {
        foreach ($argv as $i => $arg) {
            if ($arg === $flag && ($argv[$i + 1] ?? null) === $value) {
                return $i;
            }
        }

        return null;
    }
}
