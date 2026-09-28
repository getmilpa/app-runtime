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

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\InMemoryIntentChallengeStore;
use Milpa\AppRuntime\Agent\PasskeyIntentAdmission;
use Milpa\AppRuntime\Agent\PasskeyIntentProof;
use Milpa\AppRuntime\Agent\SeatFrontier;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Auth\WebAuthn\ChallengeStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyCredentialStore;
use Milpa\Auth\WebAuthn\RegisteredCredential;
use Milpa\Command\Consent\OperationId;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * identity:grant (greenhouse decisions/0493): the human who answers for a seat decides the scope one of
 * its recorded refusals names — and nobody outside the seat's enrollment line can.
 */
final class IdentityGrantOperationTest extends TestCase
{
    /** The human's own key: it enrolled the seat and the passkey (the rehearsal's shape, evidence/1024). */
    private const HUMAN = 'C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';
    /** Another human's key, which enrolled nothing of this seat. */
    private const STRANGER = 'D00D0000111122223333444455556666777788889';
    /** The resident's seat. */
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';
    private const PASSKEY = 'QM1LEWEfsoWiMm';
    private const STRANGER_PASSKEY = 'ZZ9otherCredential';
    private const RP_ID = 'milpa.local';
    private const SESSION = 'camino-blog';
    private const SEAT_SCOPES = ['agent:run', 'agent:read', 'plugins:read', 'plugins:write', 'plugins.config:write'];

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testTheCatalogueSaysGrantingIsSignedPrivilegedAndNeverTheSeatsOwn(): void
    {
        $op = $this->operation(new DIContainer());

        self::assertTrue($op->requiresConfirmation);
        self::assertTrue($op->mutating);
        self::assertSame(['identity:enroll'], $op->scopes);
        self::assertNotContains('mcp', $op->surfaces, 'a seat does not decide its own frontier');
        self::assertContains('http', $op->surfaces);
    }

    public function testTheHumanWhoEnrolledTheSeatGrantsExactlyTheRefusedScope(): void
    {
        [$c, $root, $seq] = $this->house();
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $seq]);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('plugins.Blog:write', $r['granted']);
        self::assertSame('key:' . self::HUMAN, $r['authorized_by']);
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        self::assertSame([...self::SEAT_SCOPES, 'plugins.Blog:write'], $ledger->scopesFor(self::SEAT));
        $raw = json_decode((string) file_get_contents($root . '/storage/identity/enrollments.json'), true);
        self::assertSame(self::SEAT_SCOPES, $raw[self::SEAT]['history'][0]['scopes'], 'the state it replaced is kept');

        // The refusal is judged again, not remembered: granted, it is no longer open, and a second grant has nothing to add.
        self::assertSame([], $this->frontier($c, $root)->openRefusals(self::SESSION));
        $again = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);
        self::assertFalse($again['ok']);
        self::assertStringContainsString('not an open refusal', (string) $again['error']);
    }

    public function testTheGrantTellsTheSeatsSessionWhatChanged(): void
    {
        [$c, , $seq] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $seq]);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertTrue($r['session_told']);
        $turns = array_values(array_filter(
            $sessions->stream(self::SESSION),
            static fn ($e): bool => $e->type === 'session.turn' && ($e->payload['role'] ?? null) === 'user',
        ));
        self::assertCount(1, $turns, 'one fact, recorded once');
        $told = (string) $turns[0]->payload['content'];
        self::assertStringContainsString('key:' . self::HUMAN . ' granted this seat the scope «plugins.Blog:write»', $told);
        self::assertStringContainsString('#' . $seq . ' (make plugin=Blog)', $told);
    }

    public function testARefusedGrantTellsTheSessionNothing(): void
    {
        [$c, , $seq] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $this->signed($c, self::STRANGER, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertFalse($this->call($c, ['session' => self::SESSION, 'seq' => $seq])['ok']);
        self::assertSame([], array_values(array_filter($sessions->stream(self::SESSION), static fn ($e): bool => $e->type === 'session.turn')));
    }

    public function testAKeyOutsideTheSeatsLineGrantsNothing(): void
    {
        [$c, $root, $seq] = $this->house();
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');
        $this->signed($c, self::STRANGER, ['session' => self::SESSION, 'seq' => $seq]);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('do not answer for', (string) $r['error']);
        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'), 'the ledger is untouched');
    }

    public function testASignatureOverAnotherRefusalGrantsNothing(): void
    {
        [$c, , $seq] = $this->house();
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $seq + 1]);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('does not cover', (string) $r['error']);
    }

    public function testWithoutAProvenDeciderNothingIsGranted(): void
    {
        [$c, , $seq] = $this->house();

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq], new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']));

        self::assertFalse($r['ok'], 'a passkey session without a touch bound to this grant decides nothing');
        self::assertStringContainsString('did not approve', (string) $r['error']);
    }

    public function testThePasskeyTheHumansKeyEnrolledGrantsWithATouchBoundToThisCall(): void
    {
        [$c, $root, $seq] = $this->house();
        [$proof, $touch] = $this->passkey($c, self::PASSKEY);
        $call = ['session' => self::SESSION, 'seq' => $seq];

        $r = $this->call($c, $call + ['assertion' => $touch($call)], new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('passkey:' . self::PASSKEY, $r['authorized_by'], 'the house knows who decided');
        // After the passkey decided, the chain starts at it and still reaches the human's key: both answer.
        self::assertTrue($this->frontier($c, $root)->answersFor('passkey:' . self::PASSKEY, self::SESSION));
        self::assertTrue($this->frontier($c, $root)->answersFor('key:' . self::HUMAN, self::SESSION));
        self::assertFalse($this->frontier($c, $root)->answersFor('passkey:' . self::STRANGER_PASSKEY, self::SESSION));
        unset($proof);
    }

    public function testAPasskeyAnotherKeyEnrolledNeitherSeesNorGrants(): void
    {
        [$c, $root, $seq] = $this->house();
        [, $touch] = $this->passkey($c, self::STRANGER_PASSKEY);
        $call = ['session' => self::SESSION, 'seq' => $seq];
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');

        self::assertSame([], $this->frontier($c, $root)->sessionsFor('passkey:' . self::STRANGER_PASSKEY), 'it does not see the seat');
        $r = $this->call($c, $call + ['assertion' => $touch($call)], new ToolContext('passkey:' . self::STRANGER_PASSKEY, 'web', ['identity:enroll']));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('do not answer for', (string) $r['error']);
        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'));
    }

    public function testATouchFromAnotherCredentialDoesNotSpeakForTheSignedInPasskey(): void
    {
        [$c, , $seq] = $this->house();
        [, $touch] = $this->passkey($c, self::STRANGER_PASSKEY);
        $call = ['session' => self::SESSION, 'seq' => $seq];

        $r = $this->call($c, $call + ['assertion' => $touch($call)], new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('did not approve', (string) $r['error']);
    }

    public function testTheHumanSeesTheSeatSessionWithItsOpenRefusal(): void
    {
        [$c, $root, $seq] = $this->house();

        $seen = $this->frontier($c, $root)->sessionsFor('passkey:' . self::PASSKEY);

        self::assertCount(1, $seen);
        self::assertSame(self::SESSION, $seen[0]['session']);
        self::assertSame('key:' . self::SEAT, $seen[0]['seat']);
        self::assertSame([['seq' => $seq, 'tool' => 'make', 'plugin' => 'Blog', 'permission' => 'plugins.Blog:write']], $seen[0]['refusals'], 'one row for the scope, not one per retry');
    }

    public function testACallThatFailedForAnotherReasonIsNotAFrontier(): void
    {
        [$c, $root] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $seq = $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'no-such name'], 'Scoped authoring requires one canonical plugin name.', false, true);

        self::assertNull($this->frontier($c, $root)->refusal(self::SESSION, $seq));
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $seq]);
        self::assertFalse($this->call($c, ['session' => self::SESSION, 'seq' => $seq])['ok']);
    }

    public function testASessionNoVerifiedKeyOpenedHasNoSeat(): void
    {
        [$c, $root] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $sessions->start('anon', 'goal', by: new Principal('cli:someone@host', false));
        $sessions->recordToolCall('anon', 'make', ['what' => 'plugin', 'plugin' => 'Blog'], 'refused', false, true);

        self::assertNull($this->frontier($c, $root)->seatOf('anon'));
        self::assertSame([], $this->frontier($c, $root)->openRefusals('anon'));
    }

    // --- helpers ---

    /**
     * A house where the human's key enrolled the seat (without plugins.Blog:write) and a passkey, a stranger's
     * key enrolled another passkey, and the seat's session recorded one refused `make` — twice.
     *
     * @return array{0: DIContainer, 1: string, 2: int}
     */
    private function house(): array
    {
        $root = sys_get_temp_dir() . '/milpa-grant-op-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/storage/identity', 0o777, true);
        $this->dirs[] = $root;
        file_put_contents($root . '/config/identity.php', "<?php return ['rooted' => ['" . self::SEAT . "', '" . self::PASSKEY . "', '" . self::STRANGER_PASSKEY . "']];");
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $ledger->record(new IdentityEnrolled(self::SEAT, self::SEAT_SCOPES, 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::PASSKEY, ['milpa.admin', 'agent:read', 'identity:enroll'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::STRANGER_PASSKEY, ['milpa.admin', 'agent:read', 'identity:enroll'], 'key:' . self::STRANGER));

        $c = new DIContainer();
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setAccessible(true);
            $p->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        $events = new InMemoryEventStore();
        $c->registerService(EventStoreInterface::class, $events);
        $sessions = new SessionStore($events);
        $c->registerService(SessionStore::class, $sessions);

        $sessions->start(self::SESSION, 'Build the blog', by: new Principal('key:' . self::SEAT, true));
        $refused = "Missing required permission 'plugins.Blog:write' for plugin 'Blog'.";
        $seq = $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'BlogPlugin'], $refused, false, true);
        $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], $refused, false, true);

        return [$c, $root, $seq];
    }

    private function frontier(DIContainer $c, string $root): SeatFrontier
    {
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);

        return SeatFrontier::forRoot($root, $sessions);
    }

    /** @param array<string, mixed> $arguments */
    private function signed(DIContainer $c, string $fingerprint, array $arguments): void
    {
        $authorization = new OperationAuthorization(
            operation: 'identity:grant',
            arguments: $arguments,
            host: 'lab-host',
            issuedAt: '2026-09-27T00:00:00+00:00',
            nonce: 'n-1',
        );
        $c->registerService(GrantedAuthorization::class, new GrantedAuthorization(
            authorization: $authorization,
            signer: new VerifiedSigner($fingerprint, 'Lab <lab@example.invalid>'),
            payload: $authorization->canonical(),
            signature: 'exact-signature-bytes',
        ));
    }

    /**
     * Register the intent proof for one credential and return a function that touches it for a call.
     *
     * @return array{0: PasskeyIntentProof, 1: \Closure(array<string, mixed>): array<string, string>}
     */
    private function passkey(DIContainer $c, string $credentialId): array
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $admission = new PasskeyIntentAdmission($this->authenticator($credentialId, (string) $details['key']), new InMemoryIntentChallengeStore());
        $proof = new PasskeyIntentProof($admission, self::RP_ID);
        $c->registerService(PasskeyIntentProof::class, $proof);
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $touch = static function (array $call) use ($admission, $key, $credentialId, $b64): array {
            $challenge = $admission->challengeFor(new OperationId('identity:grant'), $call, \is_string($call['session'] ?? null) ? $call['session'] : null);
            $clientData = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $b64($challenge), 'origin' => 'https://' . self::RP_ID]);
            $authData = hash('sha256', self::RP_ID, true) . "\x01" . pack('N', 1);
            $signature = '';
            openssl_sign($authData . hash('sha256', $clientData, true), $signature, $key, \OPENSSL_ALGO_SHA256);

            return ['credentialId' => $credentialId, 'clientDataJSON' => $b64($clientData), 'authenticatorData' => $b64($authData), 'signature' => $b64($signature)];
        };

        return [$proof, $touch];
    }

    private function authenticator(string $credentialId, string $publicKeyPem): PasskeyAuthenticator
    {
        $credentials = new class () implements PasskeyCredentialStore {
            /** @var array<string, RegisteredCredential> */
            public array $store = [];

            public function register(RegisteredCredential $credential): void
            {
                $this->store[$credential->credentialId] = $credential;
            }

            public function find(string $credentialId): ?RegisteredCredential
            {
                return $this->store[$credentialId] ?? null;
            }

            public function updateSignCount(string $credentialId, int $signCount): void
            {
                $c = $this->store[$credentialId] ?? null;
                if ($c !== null) {
                    $this->store[$credentialId] = new RegisteredCredential($c->credentialId, $c->publicKeyPem, $signCount);
                }
            }
        };
        $credentials->register(new RegisteredCredential($credentialId, $publicKeyPem, 0));
        $challenges = new class () implements ChallengeStore {
            /** @var array<string, true> */
            private array $live = [];

            public function issue(): string
            {
                $c = random_bytes(32);
                $this->live[$c] = true;

                return $c;
            }

            public function consume(string $challenge): bool
            {
                if (!isset($this->live[$challenge])) {
                    return false;
                }
                unset($this->live[$challenge]);

                return true;
            }
        };

        return new PasskeyAuthenticator($challenges, $credentials);
    }

    private function operation(DIContainer $c): \Milpa\Command\Operation
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === 'identity:grant') {
                return $op;
            }
        }
        self::fail('identity:grant is not offered');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $c, array $input, ?ToolContext $authority = null): array
    {
        $handler = $this->operation($c)->handler;
        self::assertIsCallable($handler);
        /** @var array<string, mixed> */
        return $handler($input, null, $authority);
    }
}
