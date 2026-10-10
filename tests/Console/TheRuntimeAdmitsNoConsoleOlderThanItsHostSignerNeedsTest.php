<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Console\RemoteOperationSigner;
use Milpa\Console\ExplainsSigningFailure;
use PHPUnit\Framework\TestCase;

/**
 * The runtime admits no `milpa/console` older than the one its host signer is written against (greenhouse
 * evidence/1191 §6).
 *
 * {@see RemoteOperationSigner} implements `ExplainsSigningFailure` and answers with `SigningFailure`; both were born
 * in milpa/console 0.24.0. app-runtime 0.220.0 still admitted console 0.23: composer resolved that pair, and there
 * loading the signer —which {@see \Milpa\AppRuntime\Console\Application} names on every run that brings no signer of
 * its own— was a fatal, «Interface "Milpa\Console\ExplainsSigningFailure" not found».
 *
 * A test of this package runs beside the console composer installed, which is the newest: it cannot see an old one.
 * So this pins the floor where its reason is written. What MEASURES it is resolving the package beside the lowest
 * console it admits (greenhouse scripts/lab/dev-image-1191/console-floor/at-its-floor.sh).
 *
 * @guards the floor declared for milpa/console is at or above the release that carries what the signer implements
 *
 * @refuses a floor lowered below it while the signer still implements that interface
 */
final class TheRuntimeAdmitsNoConsoleOlderThanItsHostSignerNeedsTest extends TestCase
{
    /** The milpa/console release in which `ExplainsSigningFailure` and `SigningFailure` were born. */
    private const string BORN_IN = '0.24';

    public function testTheHostSignerIsWrittenAgainstTheInterfaceThatRaisesTheFloor(): void
    {
        self::assertContains(ExplainsSigningFailure::class, class_implements(RemoteOperationSigner::class) ?: []);
    }

    public function testTheDeclaredFloorCarriesThatInterface(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $declared = (string) ($manifest['require']['milpa/console'] ?? '');

        self::assertSame(
            1,
            preg_match('/^>=(\d+\.\d+(?:\.\d+)?) <1\.0$/', $declared, $floor),
            "milpa/console is declared «{$declared}»: a floor and the family's ceiling were expected",
        );
        self::assertTrue(
            version_compare($floor[1], self::BORN_IN, '>='),
            "milpa/console is admitted from {$floor[1]}, and RemoteOperationSigner implements an interface born in " . self::BORN_IN,
        );
    }
}
