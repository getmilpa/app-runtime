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
        // A first enrollment replaced nothing (greenhouse decisions/0207).
        self::assertSame(0, $r['history_entries']);
        self::assertArrayNotHasKey('previously_revoked_by', $r);

        // The recognition is on disk, readable by admission.
        $store = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        self::assertSame(['agent:read', 'agent:answer'], $store->scopesFor(self::ROOTED));
    }

    /** F3: the real handler over a revoked id says who revoked it and how many states the ledger keeps. */
    public function testReEnrollingARevokedKeySaysWhoRevokedItAndKeepsTheHistory(): void
    {
        [$c, $root] = $this->containerWithRoot(self::ROOTED);
        $store = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $store->record(new IdentityEnrolled(self::ROOTED, ['agent:read'], 'key:' . self::ROOTED));
        self::assertTrue($store->revoke(self::ROOTED, 'key:' . self::UNROOTED));
        self::assertNull($store->scopesFor(self::ROOTED));

        $this->grant($c, self::ROOTED);
        $r = $this->call($c, ['fingerprint' => self::ROOTED, 'scopes' => ['milpa.admin']]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('key:' . self::UNROOTED, $r['previously_revoked_by'], 'the act names the revoker it re-recognizes over');
        self::assertSame(1, $r['history_entries']);
        self::assertSame(['milpa.admin'], $store->scopesFor(self::ROOTED), 'admitted again, with the new scopes');

        // The revocation is a fact the ledger keeps, not state the re-recognition erased.
        $raw = json_decode((string) file_get_contents($root . '/storage/identity/enrollments.json'), true);
        self::assertSame('key:' . self::UNROOTED, $raw[self::ROOTED]['history'][0]['revoked_by']);
        self::assertSame(['agent:read'], $raw[self::ROOTED]['history'][0]['scopes']);
        self::assertArrayNotHasKey('revoked_by', $raw[self::ROOTED]);
    }

    /** The act reports only on a write that happened: a ledger it cannot write is refused as ok:false, not narrated. */
    public function testAnEnrollmentTheLedgerCannotWriteIsRefusedNotReported(): void
    {
        [$c, $root] = $this->containerWithRoot(self::ROOTED);
        $ledger = $root . '/storage/identity/enrollments.json';
        mkdir($ledger); // a directory where the file should be: it cannot be opened for writing
        $this->grant($c, self::ROOTED);

        try {
            $r = $this->call($c, ['fingerprint' => self::ROOTED, 'scopes' => ['milpa.admin']]);
        } finally {
            rmdir($ledger);
        }

        self::assertFalse($r['ok']);
        self::assertStringContainsString('nothing was recognized', (string) $r['error']);
        self::assertStringContainsString('could not be opened', (string) $r['error']);
        self::assertArrayNotHasKey('history_entries', $r, 'no report stands for a write that did not happen');
    }

    public function testTheEnrollCatalogueDeclaresWhatTheActSays(): void
    {
        $schema = $this->operation(new DIContainer())->outputSchema;

        self::assertIsArray($schema);
        self::assertSame('integer', $schema['properties']['history_entries']['type']);
        self::assertSame('string', $schema['properties']['previously_revoked_by']['type']);
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

    public function testBootstrapSelfEnrollsTheFirstSignerOnAGreenfieldThatOptedIn(): void
    {
        [$c, $root] = $this->containerGreenfield();
        $this->grant($c, self::ROOTED, 'identity:bootstrap');

        $r = $this->call($c, ['scopes' => ['*']], 'identity:bootstrap');

        self::assertTrue($r['ok']);
        self::assertSame(self::ROOTED, $r['fingerprint'], 'the signer becomes the root');
        self::assertSame(['*'], $r['scopes']);

        $store = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        self::assertSame(['*'], $store->scopesFor(self::ROOTED));
    }

    public function testBootstrapSealsAfterTheFirstRecognition(): void
    {
        [$c, $root] = $this->containerGreenfield();
        // A recognition already stands.
        (new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))
            ->record(new IdentityEnrolled('SOMEONE0000000000000000000000000000ELSE0', ['agent:read'], 'bootstrap'));
        $this->grant($c, self::ROOTED, 'identity:bootstrap');

        $r = $this->call($c, ['scopes' => ['*']], 'identity:bootstrap');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no longer a greenfield', (string) $r['error']);
    }

    /** A ledger the store cannot read is not a greenfield: bootstrap refuses rather than minting a root over it. */
    public function testBootstrapRefusesOverALedgerItCannotRead(): void
    {
        [$c, $root] = $this->containerGreenfield();
        $ledger = $root . '/storage/identity/enrollments.json';
        $garbage = '{"SOMEONE": {"scopes": ["*"]}} trailing garbage';
        file_put_contents($ledger, $garbage);
        $this->grant($c, self::ROOTED, 'identity:bootstrap');

        $r = $this->call($c, ['scopes' => ['*']], 'identity:bootstrap');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no longer a greenfield', (string) $r['error']);
        self::assertSame($garbage, (string) file_get_contents($ledger), 'nothing was written over it');
    }

    public function testBootstrapRefusesWhenTheAppDidNotOptIn(): void
    {
        // A root declared out of band (containerWithRoot) is NOT a bootstrap opt-in.
        $c = $this->container(self::ROOTED);
        $this->grant($c, self::ROOTED, 'identity:bootstrap');

        $r = $this->call($c, ['scopes' => ['*']], 'identity:bootstrap');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('did not opt into', (string) $r['error']);
    }

    public function testBootstrapRefusesWhenARootWasDeclaredOutOfBand(): void
    {
        // Opted in AND a root declared — bootstrap steps aside for identity:enroll.
        [$c, $root] = $this->containerGreenfield(rooted: self::UNROOTED);
        $this->grant($c, self::ROOTED, 'identity:bootstrap');

        $r = $this->call($c, ['scopes' => ['*']], 'identity:bootstrap');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('already declared a root', (string) $r['error']);
    }

    public function testBootstrapNeedsTheSignatureThatBecomesTheRoot(): void
    {
        [$c] = $this->containerGreenfield();

        $r = $this->call($c, ['scopes' => ['*']], 'identity:bootstrap');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('--sign', (string) $r['error']);
    }

    // --- helpers ---

    private function container(string $rooted): DIContainer
    {
        return $this->containerWithRoot($rooted)[0];
    }

    /** @return array{0: DIContainer, 1: string} */
    private function containerGreenfield(?string $rooted = null): array
    {
        $root = sys_get_temp_dir() . '/milpa-boot-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/storage/identity', 0o777, true);
        $decl = "['bootstrap' => true" . ($rooted === null ? '' : ", 'rooted' => ['" . $rooted . "']") . ']';
        file_put_contents($root . '/config/identity.php', '<?php return ' . $decl . ';');
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
