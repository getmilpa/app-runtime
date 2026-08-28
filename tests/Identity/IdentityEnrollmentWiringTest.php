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

use Milpa\AppRuntime\Agent\SessionIdentity;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityConfig;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Policy\PolicyProvider;
use Milpa\Command\Effect\AuthorityPolicy;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * Enrollment TAKES EFFECT: a recognition written by identity:enroll is what admission reads to turn a
 * signed key into a principal — even when the app's static registry recognizes no one (decisions/0117,
 * evidence/0373 closed the loop from the pure gate to the wired store).
 */
final class IdentityEnrollmentWiringTest extends TestCase
{
    public const FP = 'ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-enroll-' . uniqid('', true);
        mkdir($this->dir . '/storage/identity', 0o775, true);
        mkdir($this->dir . '/config', 0o775, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/storage/identity/*') ?: []);
        array_map('unlink', glob($this->dir . '/config/*') ?: []);
        @rmdir($this->dir . '/storage/identity');
        @rmdir($this->dir . '/storage');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function testRevocationDeniesTheKeyButKeepsTheEnrollmentFact(): void
    {
        $this->store()->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:' . self::FP));
        self::assertSame(['agent:read'], $this->store()->scopesFor(self::FP));

        $revoked = $this->store()->revoke(self::FP, 'key:REVOKER');
        self::assertTrue($revoked);

        // Admission stops honoring the key...
        self::assertNull($this->store()->scopesFor(self::FP), 'a revoked key resolves to nothing');
        // ...but the enrollment fact survives on disk for the audit trail.
        $raw = json_decode((string) file_get_contents($this->dir . '/storage/identity/enrollments.json'), true);
        self::assertSame(['agent:read'], $raw[self::FP]['scopes'], 'the recognition is not erased');
        self::assertSame('key:REVOKER', $raw[self::FP]['revoked_by']);
    }

    public function testRevokingWhatWasNeverRecognizedOrAlreadyRevokedChangesNothing(): void
    {
        // Never enrolled.
        self::assertFalse($this->store()->revoke(self::FP, 'key:REVOKER'));

        // Enrolled then revoked once; a second revoke is a no-op.
        $this->store()->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:' . self::FP));
        self::assertTrue($this->store()->revoke(self::FP, 'key:REVOKER'));
        self::assertFalse($this->store()->revoke(self::FP, 'key:REVOKER'), 'a standing revocation is not re-applied');
    }

    public function testRe_enrollingAfterRevocationRecognizesTheKeyAgain(): void
    {
        $this->store()->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:' . self::FP));
        $this->store()->revoke(self::FP, 'key:REVOKER');
        self::assertNull($this->store()->scopesFor(self::FP));

        // Enrolling again lays a fresh recognition with no revocation over it.
        $this->store()->record(new IdentityEnrolled(self::FP, ['agent:answer'], 'key:' . self::FP));
        self::assertSame(['agent:answer'], $this->store()->scopesFor(self::FP));
    }

    public function testAdmissionRefusesARevokedKey(): void
    {
        $this->store()->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:' . self::FP));
        $this->store()->revoke(self::FP, 'key:REVOKER');

        // gpg still proves possession, but the house revoked its recognition — possession alone.
        self::assertNull($this->identity(scopesForSigner: null)->admit($this->assertion(), 'run-1'));
    }

    private function store(): FileEnrollmentStore
    {
        return new FileEnrollmentStore($this->dir . '/storage/identity/enrollments.json');
    }

    public function testTheStoreRoundTripsARecognitionAndNormalizes(): void
    {
        $this->store()->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:' . self::FP));

        // Read back with spaces and lower case — the ledger normalizes, so the same key resolves.
        self::assertSame(['agent:read'], $this->store()->scopesFor('abcd1234 abcd1234 abcd1234 abcd1234 abcd1234'));
        self::assertNull($this->store()->scopesFor('0000000000000000000000000000000000000000'), 'a key never enrolled resolves to nothing');
    }

    public function testConfigLoadsTheRootFromEitherShapeAndIsEmptyWhenAbsent(): void
    {
        self::assertFalse(IdentityConfig::load($this->dir)->admits(self::FP), 'no config/identity.php means an empty root');

        file_put_contents($this->dir . '/config/identity.php', "<?php return ['rooted' => ['" . self::FP . "']];");
        self::assertTrue(IdentityConfig::load($this->dir)->admits(self::FP));

        file_put_contents($this->dir . '/config/identity.php', "<?php return ['" . self::FP . "'];");
        self::assertTrue(IdentityConfig::load($this->dir)->admits(self::FP), 'a bare list is accepted too');
    }

    public function testAdmissionRecognizesAnEnrolledKeyEvenWhenTheStaticRegistryDoesNot(): void
    {
        $this->store()->record(new IdentityEnrolled(self::FP, ['agent:read', 'agent:answer'], 'key:' . self::FP));

        // The static policy recognizes NO ONE (scopesForSigner returns null); the enrollment store is
        // the only source of recognition. Admission must still produce a verified principal.
        $facts = $this->identity(scopesForSigner: null)->admit($this->assertion(), 'run-1');

        self::assertNotNull($facts, 'the enrolled key is a principal even with an empty static registry');
        self::assertTrue($facts->verified);
        self::assertSame('key:' . self::FP, $facts->principal);
        self::assertSame(['agent:read', 'agent:answer'], $facts->scopes);
    }

    public function testAdmissionNeedsNoPolicyProviderWhenTheKeyIsEnrolled(): void
    {
        $this->store()->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:' . self::FP));

        // No PolicyProvider AT ALL (an app that declared a root but no config/policy.php): recognition
        // comes wholly from the enrollment store, and admission must still produce a principal
        // (decisions/0117, evidence/0375 — the wiring refinement).
        $facts = $this->identityWithoutPolicy()->admit($this->assertion(), 'run-1');

        self::assertNotNull($facts, 'an enrolled key is a principal even with no policy provider');
        self::assertTrue($facts->verified);
        self::assertSame(['agent:read'], $facts->scopes);
    }

    public function testAKeyNeitherEnrolledNorStaticallyRecognizedIsPossessionAlone(): void
    {
        // Nothing recorded; static registry null. gpg proved possession, but the house recognizes no
        // identity for it — admission yields nothing.
        self::assertNull($this->identity(scopesForSigner: null)->admit($this->assertion(), 'run-1'));
    }

    private function identityWithoutPolicy(): SessionIdentity
    {
        $verifier = new class () implements SignatureVerifier {
            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return new VerifiedSigner(IdentityEnrollmentWiringTest::FP, 'Rod <rodrigo@teamx.agency>');
            }
        };

        return new SessionIdentity($verifier, null, $this->store());
    }

    private function identity(?array $scopesForSigner): SessionIdentity
    {
        $verifier = new class () implements SignatureVerifier {
            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return new VerifiedSigner(IdentityEnrollmentWiringTest::FP, 'Rod <rodrigo@teamx.agency>');
            }
        };

        $policy = new class ($scopesForSigner) implements PolicyProvider {
            public function __construct(private readonly ?array $scopes)
            {
            }

            public function authorityPolicy(): ?AuthorityPolicy
            {
                return null;
            }

            public function scopesForSigner(string $fingerprint): ?array
            {
                return $this->scopes;
            }
        };

        return new SessionIdentity($verifier, $policy, $this->store());
    }

    /** @return array{payload: string, signature: string, fingerprint: string, uid: string} */
    private function assertion(): array
    {
        $payload = (new OperationAuthorization(
            operation: 'session:own',
            arguments: ['session' => 'run-1'],
            host: 'lab-host',
            issuedAt: '2026-08-18T00:00:00+00:00',
            nonce: 'n-1',
        ))->canonical();

        return [
            'payload' => $payload,
            'signature' => 'firma-en-bytes',
            'fingerprint' => self::FP,
            'uid' => 'Rod <rodrigo@teamx.agency>',
        ];
    }
}
