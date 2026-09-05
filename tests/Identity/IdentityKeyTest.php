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

namespace Milpa\AppRuntime\Tests\Identity;

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\IdentityKey;
use Milpa\AppRuntime\Identity\RootedSigners;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One rule for what «the same identity key» means, shared by the root and the ledger (greenhouse decisions/0206).
 */
final class IdentityKeyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function keys(): iterable
    {
        yield 'a gpg fingerprint, lowercase with spaces' => ['ab12 cd34 ef56 ab12 cd34 ef56 ab12 cd34 ef56 ab12', 'AB12CD34EF56AB12CD34EF56AB12CD34EF56AB12'];
        yield 'a gpg fingerprint, already canonical' => ['AB12CD34EF56AB12CD34EF56AB12CD34EF56AB12', 'AB12CD34EF56AB12CD34EF56AB12CD34EF56AB12'];
        yield 'a base64url credential id keeps its case' => ['AbC-x_1Q', 'AbC-x_1Q'];
        yield 'a short hex-looking id is not a fingerprint' => ['abc123', 'abc123'];
        yield 'a base64url id that happens to be all hex chars but short' => ['deadbeef', 'deadbeef'];
    }

    #[DataProvider('keys')]
    public function testHexFingerprintsAreCanonicalisedAndEveryOtherIdIsKeptVerbatim(string $given, string $expected): void
    {
        self::assertSame($expected, IdentityKey::normalize($given));
    }

    public function testRootAndLedgerAgreeOnACaseSensitiveCredentialId(): void
    {
        $root = new RootedSigners(['AbC-x_1Q']);
        self::assertTrue($root->admits('AbC-x_1Q'));
        self::assertFalse($root->admits('abc-x_1q'), 'the root is as exact as the ledger');

        $ledger = new FileEnrollmentStore(sys_get_temp_dir() . '/milpa-key-' . bin2hex(random_bytes(6)) . '.json');
        $ledger->record(new IdentityEnrolled('AbC-x_1Q', ['milpa.admin'], 'key:TEST'));
        self::assertSame(['milpa.admin'], $ledger->scopesFor('AbC-x_1Q'));
        self::assertNull($ledger->scopesFor('abc-x_1q'), 'a case-variant id is a different id');
    }

    public function testAnUppercaseHexFingerprintStoredEarlierStillResolves(): void
    {
        $ledger = new FileEnrollmentStore(sys_get_temp_dir() . '/milpa-key-' . bin2hex(random_bytes(6)) . '.json');
        $ledger->record(new IdentityEnrolled('AB12CD34EF56AB12CD34EF56AB12CD34EF56AB12', ['identity:enroll'], 'key:TEST'));

        self::assertSame(['identity:enroll'], $ledger->scopesFor('ab12 cd34 ef56 ab12 cd34 ef56 ab12 cd34 ef56 ab12'));
    }
}
