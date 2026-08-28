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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * identity:enroll wired as a governed operation (decisions/0117, evidence/0374): the signed caller,
 * the out-of-band root gate, and the persisted recognition, exercised through the real handler.
 */
final class IdentityEnrollOperationTest extends TestCase
{
    private const ROOTED = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';
    private const UNROOTED = 'FFFF6666EEEE7777DDDD8888CCCC9999BBBB0000';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            foreach (glob($d . '/storage/identity/*') ?: [] as $f) {
                unlink($f);
            }
            @unlink($d . '/config/identity.php');
            @rmdir($d . '/storage/identity');
            @rmdir($d . '/storage');
            @rmdir($d . '/config');
            @rmdir($d);
        }
    }

    public function testTheCatalogueSaysEnrollingIsSignedAndMutating(): void
    {
        $op = $this->operation(new DIContainer());

        self::assertTrue($op->requiresConfirmation);
        self::assertTrue($op->mutating);
        self::assertContains('identity:enroll', $op->scopes);
    }

    public function testWithoutAGrantedAuthorizationNothingIsEnrolled(): void
    {
        $r = $this->call($this->container(self::ROOTED), ['fingerprint' => self::ROOTED, 'scopes' => ['agent:read']]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('--sign', (string) $r['error']);
    }

    public function testASignatureCoveringAnotherFingerprintEnrollsNothing(): void
    {
        $c = $this->container(self::ROOTED);
        $this->grant($c, self::UNROOTED); // signed to enroll UNROOTED, but we ask to enroll ROOTED

        $r = $this->call($c, ['fingerprint' => self::ROOTED, 'scopes' => ['agent:read']]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('does not cover', (string) $r['error']);
    }

    public function testAFingerprintTheRootNeverDeclaredIsRefused(): void
    {
        $c = $this->container(self::ROOTED); // root declares ROOTED only
        $this->grant($c, self::UNROOTED);

        $r = $this->call($c, ['fingerprint' => self::UNROOTED, 'scopes' => ['*']]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('not in the out-of-band root', (string) $r['error']);
    }

    public function testARootedKeyIsRecognizedAndPersisted(): void
    {
        [$c, $root] = $this->containerWithRoot(self::ROOTED);
        $this->grant($c, self::ROOTED);

        $r = $this->call($c, ['fingerprint' => self::ROOTED, 'scopes' => ['agent:read', 'agent:answer']]);

        self::assertTrue($r['ok']);
        self::assertSame(self::ROOTED, $r['fingerprint']);
        self::assertSame(['agent:read', 'agent:answer'], $r['scopes']);
        self::assertSame('key:' . self::ROOTED, $r['authorized_by']);

        // The recognition is on disk, readable by admission.
        $store = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        self::assertSame(['agent:read', 'agent:answer'], $store->scopesFor(self::ROOTED));
    }

    public function testEmptyFingerprintOrScopesEnrollNothing(): void
    {
        $c = $this->container(self::ROOTED);
        $this->grant($c, self::ROOTED);

        self::assertFalse($this->call($c, ['fingerprint' => '', 'scopes' => ['x']])['ok']);
        self::assertFalse($this->call($c, ['fingerprint' => self::ROOTED, 'scopes' => []])['ok']);
    }

    public function testRevokingARecognizedKeyStopsAdmittingItButKeepsTheFact(): void
    {
        [$c, $root] = $this->containerWithRoot(self::ROOTED);
        $store = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');

        // The key is already recognized (the enrollment path is covered elsewhere).
        $store->record(new IdentityEnrolled(self::ROOTED, ['agent:read'], 'key:' . self::ROOTED));
        self::assertSame(['agent:read'], $store->scopesFor(self::ROOTED));

        // Revoke it through the operation.
        $this->grant($c, self::ROOTED, 'identity:revoke');
        $r = $this->call($c, ['fingerprint' => self::ROOTED], 'identity:revoke');

        self::assertTrue($r['ok']);
        self::assertSame(self::ROOTED, $r['fingerprint']);
        self::assertSame('key:' . self::ROOTED, $r['revoked_by']);
        self::assertNull($store->scopesFor(self::ROOTED), 'a revoked key is no longer admitted');
    }

    public function testRevokingWhatWasNeverRecognizedIsRefused(): void
    {
        $c = $this->container(self::ROOTED);
        $this->grant($c, self::UNROOTED, 'identity:revoke');

        $r = $this->call($c, ['fingerprint' => self::UNROOTED], 'identity:revoke');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('did not recognize', (string) $r['error']);
    }

    public function testRevokingNeedsTheSignatureThatNamesTheRevoker(): void
    {
        $c = $this->container(self::ROOTED);

        $r = $this->call($c, ['fingerprint' => self::ROOTED], 'identity:revoke');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('--sign', (string) $r['error']);
    }

    public function testTheRevokeCatalogueEntryIsSignedAndScoped(): void
    {
        $op = $this->operation(new DIContainer(), 'identity:revoke');

        self::assertTrue($op->requiresConfirmation);
        self::assertTrue($op->mutating);
        self::assertContains('identity:revoke', $op->scopes);
    }

    // --- helpers ---

    private function container(string $rooted): DIContainer
    {
        return $this->containerWithRoot($rooted)[0];
    }

    /** @return array{0: DIContainer, 1: string} */
    private function containerWithRoot(string $rooted): array
    {
        $root = sys_get_temp_dir() . '/milpa-enroll-op-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/storage/identity', 0o777, true);
        file_put_contents($root . '/config/identity.php', "<?php return ['rooted' => ['" . $rooted . "']];");
        $this->dirs[] = $root;

        $c = new DIContainer();
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setAccessible(true);
            $p->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);

        return [$c, $root];
    }

    private function grant(DIContainer $c, string $fingerprint, string $operation = 'identity:enroll'): void
    {
        $authorization = new OperationAuthorization(
            operation: $operation,
            arguments: ['fingerprint' => $fingerprint],
            host: 'lab-host',
            issuedAt: '2026-08-18T00:00:00+00:00',
            nonce: 'n-1',
        );
        $c->registerService(GrantedAuthorization::class, new GrantedAuthorization(
            authorization: $authorization,
            signer: new VerifiedSigner($fingerprint, 'Rod <rodrigo@teamx.agency>'),
            payload: $authorization->canonical(),
            signature: 'exact-signature-bytes',
        ));
    }

    private function operation(DIContainer $c, string $name = 'identity:enroll'): \Milpa\Command\Operation
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === $name) {
                return $op;
            }
        }
        self::fail($name . ' is not offered');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $c, array $input, string $name = 'identity:enroll'): array
    {
        $handler = $this->operation($c, $name)->handler;
        self::assertIsCallable($handler);

        /** @var array<string, mixed> */
        return $handler($input);
    }
}
