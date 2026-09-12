<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Identity;

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\SignerAuthority;
use Milpa\AppRuntime\Policy\PolicyProvider;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/** Revocation must not fall through to a static policy or the local wildcard. */
final class SignerAuthorityTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-signer-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testLiveRecognitionWinsAndRevocationNeverFallsBackToPolicy(): void
    {
        $store = new FileEnrollmentStore($this->path);
        $policy = $this->createMock(PolicyProvider::class);
        $policy->method('scopesForSigner')->willReturn(['*']);
        $resolver = new SignerAuthority($store, $policy);
        $signer = new VerifiedSigner('enrolled-key');
        self::assertSame(['*'], $resolver->forSigner($signer)->scopes);
        $store->record(new IdentityEnrolled('enrolled-key', ['probe:read'], 'key:supervisor'));
        self::assertSame(['probe:read'], $resolver->forSigner($signer)->scopes);
        self::assertSame('key:enrolled-key', $resolver->forSigner($signer)->principal);
        self::assertTrue($store->revoke('enrolled-key', 'key:supervisor'));
        self::assertSame([], $resolver->forSigner($signer)->scopes);
        self::assertTrue($store->contains('enrolled-key'));
    }

    public function testOnlyANeverRecognizedKeyRetainsTheLocalFallback(): void
    {
        $store = new FileEnrollmentStore($this->path);
        $resolver = new SignerAuthority($store);
        self::assertNull($resolver->forSigner(new VerifiedSigner('unknown')));
        $store->record(new IdentityEnrolled('empty', [], 'key:supervisor'));
        self::assertSame([], $resolver->forSigner(new VerifiedSigner('empty'))->scopes);
        file_put_contents($this->path, '{"malformed":false}');
        self::assertSame([], $resolver->forSigner(new VerifiedSigner('malformed'))->scopes);
    }

    public function testAnUnreadableLedgerIsNotAnUnknownKey(): void
    {
        file_put_contents($this->path, '{not-json');
        $resolver = new SignerAuthority(new FileEnrollmentStore($this->path));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be read');
        $resolver->forSigner(new VerifiedSigner('unknown'));
    }
}
