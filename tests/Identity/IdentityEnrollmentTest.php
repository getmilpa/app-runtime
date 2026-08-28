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

use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\IdentityEnrollment;
use Milpa\AppRuntime\Identity\IdentityNotRooted;
use Milpa\AppRuntime\Identity\RootedSigners;
use PHPUnit\Framework\TestCase;

/**
 * Enrolling an identity consumes a root of trust — it never mints the one that authorizes it.
 *
 * These tests pin H-ENROLL-1 (decisions/0117): the first gate of enrollment is the root, and it is
 * fail-closed against the circular bootstrap where a fingerprint would vouch for itself.
 */
final class IdentityEnrollmentTest extends TestCase
{
    private const A = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';
    private const B = 'FFFF6666EEEE7777DDDD8888CCCC9999BBBB0000';

    /** The killer forger: a fingerprint the root never declared cannot be enrolled into existence. */
    public function testAnUnrootedFingerprintIsRefused(): void
    {
        $enrollment = new IdentityEnrollment(new RootedSigners([self::A]));

        $this->expectException(IdentityNotRooted::class);
        $enrollment->enroll(self::B, ['*'], 'key:' . self::A);
    }

    public function testTheRootReportsWhetherItDeclaredAnyone(): void
    {
        self::assertTrue((new RootedSigners([]))->isEmpty(), 'no declaration is an empty root');
        self::assertFalse((new RootedSigners([self::A]))->isEmpty());
    }

    /** A rooted fingerprint is recognized, carrying the scopes policy assigned and who authorized it. */
    public function testARootedFingerprintIsEnrolled(): void
    {
        $enrollment = new IdentityEnrollment(new RootedSigners([self::A]));

        $enrolled = $enrollment->enroll(self::A, ['agent:read'], 'key:' . self::A);

        self::assertInstanceOf(IdentityEnrolled::class, $enrolled);
        self::assertSame(self::A, $enrolled->fingerprint);
        self::assertSame(['agent:read'], $enrolled->scopes);
        self::assertSame('key:' . self::A, $enrolled->authorizedBy);
    }

    /** The root ignores case and spaces — a fingerprint pasted with spaces still matches its declaration. */
    public function testTheRootMatchesRegardlessOfCaseAndSpaces(): void
    {
        $root = new RootedSigners(['aaaa1111 bbbb2222 cccc3333 dddd4444 eeee5555']);

        self::assertTrue($root->admits(self::A));
        self::assertFalse($root->admits(self::B));
    }

    /**
     * Second invariant: an enrollment is bound to its fingerprint. Changing the root AFTER a
     * recognition does not rewrite it — if A was enrolled and the root is later changed to B, A does
     * not become B, and it does not silently keep A's rights under a new name.
     */
    public function testChangingTheRootDoesNotRewriteAnExistingEnrollment(): void
    {
        $enrolled = (new IdentityEnrollment(new RootedSigners([self::A])))
            ->enroll(self::A, ['agent:read'], 'key:' . self::A);

        // The root the operator declares later has no reach into a fact already made: the enrollment
        // still names A, not B, because it never consults the root once produced.
        self::assertSame(self::A, $enrolled->fingerprint);
        self::assertNotSame(self::B, $enrolled->fingerprint);

        // And a fresh enrollment under the new root cannot launder A through B either — B is its own
        // fingerprint, rooted or not, and A is simply no longer rooted here.
        $underNewRoot = new IdentityEnrollment(new RootedSigners([self::B]));
        $this->expectException(IdentityNotRooted::class);
        $underNewRoot->enroll(self::A, ['agent:read'], 'key:' . self::B);
    }
}
