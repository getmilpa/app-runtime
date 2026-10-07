<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\InMemoryIntentChallengeStore;
use Milpa\AppRuntime\Agent\PasskeyIntentAdmission;
use Milpa\AppRuntime\Agent\PasskeyIntentProof;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Tests\Agent\BuiltHouse;
use Milpa\Auth\WebAuthn\ChallengeStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyCredentialStore;
use Milpa\Auth\WebAuthn\RegisteredCredential;
use Milpa\Auth\WebAuthn\RelyingParty;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * A person takes back ONE admission (greenhouse decisions/0590, rule 12).
 *
 * With decisions/0597 a person can admit what no seat asked for, and until now the only ways to undo one admission
 * were to revoke the seat or to recognize it again by a typed list — which drops them all. This removes exactly one
 * scope of one capability from one seat: it only takes authority away, so it asks no reading, but it is a proven
 * act — the same line and the same proof as admitting — and the ledger keeps who withdrew, when, and what.
 */
final class WithdrawingAnAdmissionTest extends TestCase
{
    use BuiltHouse;

    private const STRANGER = 'D00D0000111122223333444455556666777788889';
    private const PASSKEY = 'QM1LEWEfsoWiMm';
    private const RP_ID = 'milpa.local';
    private const WRITE = ['seat' => self::SEAT, 'capability' => 'Prestamos', 'scope' => 'herramientas:write'];

    public function testItIsAPrivilegedSignedActAndNeverASeatsOwn(): void
    {
        $op = $this->operation(new DIContainer());

        self::assertTrue($op->mutating);
        self::assertTrue($op->requiresConfirmation);
        self::assertSame(['identity:enroll'], $op->scopes);
        self::assertSame(['cli', 'http'], $op->surfaces);
        self::assertSame(Authority::Privileged, $op->effectCeiling()->authority);
        self::assertSame(['seat', 'capability', 'scope'], $op->inputSchema['required'] ?? null);
    }

    public function testWhatWasAdmittedIsTakenBackAndOnlyThat(): void
    {
        [$c, $root] = $this->house();
        $this->admit($c, $root, self::SEAT, 'herramientas:write');
        $this->admit($c, $root, self::SEAT, 'herramientas:read');
        $this->admit($c, $root, self::OTHER_SEAT, 'herramientas:write');
        self::assertTrue($this->asks($c, $root, self::SEAT, 'herramientas.prestar')->allowed);
        $this->signed($c, self::HUMAN, self::WRITE);

        $r = $this->call($c, self::WRITE);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(self::SEAT, $r['fingerprint']);
        self::assertSame('Prestamos', $r['capability']);
        self::assertSame('herramientas:write', $r['withdrawn']);
        self::assertSame(['herramientas.agregar', 'herramientas.devolver', 'herramientas.prestar'], $r['verbs']);
        self::assertSame('key:' . self::HUMAN, $r['authorized_by']);

        // From the next call on, and with a sentence that says what happened.
        $next = $this->asks($c, $root, self::SEAT, 'herramientas.prestar');
        self::assertFalse($next->allowed);
        self::assertStringContainsString('a person withdrew its admission for this seat', (string) $next->reason);
        self::assertStringContainsString("'herramientas:write' of «Prestamos»", (string) $next->reason, 'spelled the way the leg\'s door looks for');
        // Only that: the seat's other scope, the other seat, and the seat's own words are what they were.
        self::assertTrue($this->asks($c, $root, self::SEAT, 'herramientas.listar')->allowed);
        self::assertTrue($this->asks($c, $root, self::OTHER_SEAT, 'herramientas.prestar')->allowed);
        $ledger = $this->ledger($root);
        self::assertSame(self::SEAT_SCOPES, $ledger->scopesFor(self::SEAT));
        self::assertSame(['herramientas:read'], array_keys($ledger->admissionsFor(self::SEAT)['Prestamos']));
    }

    public function testTheLedgerKeepsWhoWithdrewWhenAndWhat(): void
    {
        [$c, $root] = $this->house();
        $this->admit($c, $root, self::SEAT, 'herramientas:write');
        $this->signed($c, self::HUMAN, self::WRITE);
        self::assertTrue($this->call($c, self::WRITE)['ok']);

        $trail = $this->ledger($root)->withdrawalsFor(self::SEAT);

        self::assertCount(1, $trail);
        self::assertSame('Prestamos', $trail[0]['capability']);
        self::assertSame('herramientas:write', $trail[0]['scope']);
        self::assertSame(['herramientas.agregar', 'herramientas.devolver', 'herramientas.prestar'], $trail[0]['verbs']);
        self::assertSame('key:' . self::HUMAN, $trail[0]['withdrawn_by']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $trail[0]['at']);
        self::assertSame('key:' . self::HUMAN, $trail[0]['admitted_by'], 'and who had admitted it');
        // The state it replaced — the seat with that admission — is in the history, as after every write.
        $raw = json_decode((string) file_get_contents($root . '/storage/identity/enrollments.json'), true);
        self::assertArrayHasKey('herramientas:write', end($raw[self::SEAT]['history'])['admissions']['Prestamos']);

        // The list a person reads says it, and the scope waits again — marked as withdrawn, not as never admitted.
        $seat = $this->seat($c, self::SEAT);
        self::assertSame([], $seat['admitted']);
        self::assertSame($trail, $seat['withdrawn']);
        $waiting = array_column($seat['unadmitted'], null, 'scope')['herramientas:write'];
        self::assertSame(['by' => 'key:' . self::HUMAN, 'at' => $trail[0]['at']], $waiting['withdrawn']);
        self::assertNull(array_column($seat['unadmitted'], null, 'scope')['herramientas:read']['withdrawn']);
    }

    public function testWhereNothingOfThatIsAdmittedNothingIsWithdrawn(): void
    {
        [$c, $root] = $this->house();
        $this->admit($c, $root, self::SEAT, 'herramientas:read');
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');

        foreach ([self::WRITE, ['capability' => 'Otra'] + self::WRITE, ['scope' => 'graph:run'] + self::WRITE] as $call) {
            $this->signed($c, self::HUMAN, $call);
            $r = $this->call($c, $call);
            self::assertFalse($r['ok']);
            self::assertStringContainsString('is not admitted to that seat', (string) $r['error']);
        }

        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'));
    }

    public function testOnlyTheLineThatEnrolledTheSeatWithdrawsAndTheProofCoversThisOne(): void
    {
        [$c, $root] = $this->house();
        $this->admit($c, $root, self::SEAT, 'herramientas:write');
        $this->admit($c, $root, self::SEAT, 'herramientas:read');

        $unsigned = $this->call($c, self::WRITE);
        self::assertFalse($unsigned['ok']);
        self::assertStringContainsString('requires the signature that names WHO decides', (string) $unsigned['error']);

        $this->signed($c, self::STRANGER, self::WRITE);
        self::assertStringContainsString('you do not answer for that seat', (string) $this->call($c, self::WRITE)['error']);

        $this->signed($c, self::SEAT, self::WRITE);
        self::assertFalse($this->call($c, self::WRITE)['ok'], 'a seat does not decide what it holds');

        // A signature for one scope does not withdraw another, nor from another seat.
        $this->signed($c, self::HUMAN, ['scope' => 'herramientas:read'] + self::WRITE);
        self::assertStringContainsString('does not cover', (string) $this->call($c, self::WRITE)['error']);
        self::assertStringContainsString('does not cover', (string) $this->call($c, ['seat' => self::OTHER_SEAT, 'scope' => 'herramientas:read'] + self::WRITE)['error']);

        $nobody = ['seat' => 'ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234'] + self::WRITE;
        $this->signed($c, self::HUMAN, $nobody);
        self::assertStringContainsString('is no seat of this house', (string) $this->call($c, $nobody)['error']);

        self::assertCount(2, $this->ledger($root)->admissionsFor(self::SEAT)['Prestamos'], 'nothing was withdrawn');
        self::assertSame([], $this->ledger($root)->withdrawalsFor(self::SEAT));
    }

    /** An admission the contract left behind still sits in the ledger: it can be taken out too. */
    public function testAnAdmissionThatNoLongerCoversAnythingCanBeWithdrawn(): void
    {
        [$c, $root, $plugin] = $this->house();
        $this->admit($c, $root, self::SEAT, 'herramientas:write');
        $this->declare($plugin, [$this->verb('herramientas.listar', ['herramientas:read'])]);
        $c->replaceService(Kernel::class, $this->kernel($root, [$plugin]));
        $this->signed($c, self::HUMAN, self::WRITE);

        $r = $this->call($c, self::WRITE);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
    }

    /** A verb admitted by itself is named the way a person reads it, or the way the ledger keeps it. */
    public function testAVerbAdmittedByItselfIsWithdrawnByEitherSpelling(): void
    {
        foreach (['(no scope) herramientas.contar', '=herramientas.contar'] as $spelled) {
            $root = $this->root();
            $plugin = $this->capability($root, 'Prestamos', [$this->verb('herramientas.contar', [])]);
            $c = new DIContainer();
            $c->registerService(Kernel::class, $this->kernel($root, [$plugin]));
            $this->admit($c, $root, self::SEAT, '=herramientas.contar');
            $call = ['scope' => $spelled] + self::WRITE;
            $this->signed($c, self::HUMAN, $call);

            $r = $this->call($c, $call);

            self::assertTrue($r['ok'], $spelled . ': ' . ($r['error'] ?? ''));
            self::assertSame('(no scope) herramientas.contar', $r['withdrawn']);
            self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
        }
    }

    public function testWhatWasWithdrawnCanBeAdmittedAgainAndTheTrailStays(): void
    {
        [$c, $root] = $this->house();
        $this->admit($c, $root, self::SEAT, 'herramientas:write');
        $this->signed($c, self::HUMAN, self::WRITE);
        self::assertTrue($this->call($c, self::WRITE)['ok']);

        $this->admit($c, $root, self::SEAT, 'herramientas:write');

        self::assertTrue($this->asks($c, $root, self::SEAT, 'herramientas.prestar')->allowed);
        self::assertCount(1, $this->ledger($root)->withdrawalsFor(self::SEAT), 'what happened is still said');
        // But the scope no longer READS as withdrawn: a person admitted it again, and that is what stands.
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $admissions = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel));
        self::assertNull($admissions->withdrawalOf(self::SEAT, 'Prestamos', 'herramientas:write'));
        self::assertNull($admissions->card(self::SEAT, 'Prestamos', 'herramientas:write')['withdrawn'] ?? null);
        self::assertNull($admissions->withdrawalOf(self::OTHER_SEAT, 'Prestamos', 'herramientas:write'), 'and it was never another seat\'s');
        // And what one more scope for the seat keeps, a typed list drops — with the state it belonged to.
        $ledger = $this->ledger($root);
        $ledger->recordAndReport(new IdentityEnrolled(self::SEAT, [...self::SEAT_SCOPES, 'plugins.Blog:write'], 'key:' . self::HUMAN), keepAdmissions: true);
        self::assertCount(1, $ledger->withdrawalsFor(self::SEAT));
        $ledger->recordAndReport(new IdentityEnrolled(self::SEAT, self::SEAT_SCOPES, 'key:' . self::HUMAN));
        self::assertSame([], $ledger->withdrawalsFor(self::SEAT));
    }

    /** The seat asks again, and the card a person gets says this is something they took back — by whom, and when. */
    public function testAfterAWithdrawalTheSeatsRefusedCallIsOfferedAgainAndSaysWhoTookItBack(): void
    {
        [$c, $root] = $this->house();
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $sessions = new \Milpa\Agent\SessionStore(new \Milpa\EventStore\InMemoryEventStore());
        $sessions->start('taller', 'Presta el taladro.', by: new \Milpa\Agent\Principal('key:' . self::SEAT, true));
        $this->admit($c, $root, self::SEAT, 'herramientas:write');
        $this->signed($c, self::HUMAN, self::WRITE);
        $taken = $this->call($c, self::WRITE);
        self::assertTrue($taken['ok']);
        $seq = $sessions->recordToolCall('taller', 'herramientas_prestar', ['id' => 1], 'refused', false, true);

        $frontier = \Milpa\AppRuntime\Agent\SeatFrontier::forRoot($root, $sessions, BuiltCapabilities::of($kernel));
        $row = $frontier->refusal('taller', $seq);

        self::assertNotNull($row);
        self::assertSame('capability', $row['kind']);
        self::assertSame('withdrawn', $row['why']);
        self::assertSame(['by' => 'key:' . self::HUMAN, 'at' => $taken['at']], $row['withdrawn']);
        self::assertSame(['withdrawn', 'withdrawn', 'withdrawn'], array_column($row['opens'], 'standing'));
        self::assertSame('herramientas:write', $frontier->wouldOffer('taller', 'herramientas_prestar', ['id' => 1])['permission'] ?? null, 'the leg ends and waits, as for any admission');
    }

    /** What is not the shape a withdrawal is written in is not one. */
    public function testAMalformedTrailSaysNothing(): void
    {
        [, $root] = $this->house();
        $file = $root . '/storage/identity/enrollments.json';
        $raw = json_decode((string) file_get_contents($file), true);
        $raw[self::SEAT]['withdrawals'] = ['yes', ['capability' => 'Prestamos'], ['capability' => 'Prestamos', 'scope' => 'herramientas:write', 'withdrawn_by' => 'key:X', 'verbs' => ['a', 7]]];
        file_put_contents($file, (string) json_encode($raw));

        self::assertSame(
            [['capability' => 'Prestamos', 'scope' => 'herramientas:write', 'verbs' => ['a'], 'withdrawn_by' => 'key:X', 'at' => '', 'admitted_by' => '']],
            $this->ledger($root)->withdrawalsFor(self::SEAT),
        );
        // A revoked seat that still carries an admission in its entry: it says nothing, and nothing is taken from it.
        self::assertTrue($this->ledger($root)->admit(self::SEAT, 'Prestamos', 'herramientas:write', ['herramientas.prestar' => 'sha256:a'], 'key:' . self::HUMAN));
        $this->ledger($root)->revoke(self::SEAT, 'key:' . self::HUMAN);
        $revoked = (string) file_get_contents($file);
        self::assertSame([], $this->ledger($root)->withdrawalsFor(self::SEAT), 'a revoked seat says nothing');
        self::assertNull($this->ledger($root)->withdraw(self::SEAT, 'Prestamos', 'herramientas:write', 'key:' . self::HUMAN), 'and nothing is taken from it');
        self::assertSame($revoked, (string) file_get_contents($file), 'the ledger was not written');
    }

    /** In the panel: the passkey session, plus a live touch bound to this seat, this capability and this scope. */
    public function testAPasskeyTouchBoundToThisWithdrawalWithdrawsAndOneBoundToAnotherDoesNot(): void
    {
        [$c, $root] = $this->house();
        $this->ledger($root)->record(new IdentityEnrolled(self::PASSKEY, ['milpa.admin', 'identity:enroll'], 'key:' . self::HUMAN));
        $this->admit($c, $root, self::SEAT, 'herramientas:write');
        $this->admit($c, $root, self::SEAT, 'herramientas:read');
        $touch = $this->passkey($c, self::PASSKEY);
        $web = new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']);
        $read = ['scope' => 'herramientas:read'] + self::WRITE;

        $swapped = $this->call($c, self::WRITE + ['assertion' => $touch($read)], $web);
        self::assertFalse($swapped['ok']);
        self::assertStringContainsString('did not approve', (string) $swapped['error']);
        self::assertCount(2, $this->ledger($root)->admissionsFor(self::SEAT)['Prestamos']);

        $r = $this->call($c, self::WRITE + ['assertion' => $touch(self::WRITE)], $web);
        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('passkey:' . self::PASSKEY, $r['authorized_by']);
        self::assertSame('passkey:' . self::PASSKEY, $this->ledger($root)->withdrawalsFor(self::SEAT)[0]['withdrawn_by']);
    }

    /**
     * A house with the course's capability and two seats as seated.
     *
     * @return array{0: DIContainer, 1: string, 2: object}
     */
    private function house(): array
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $c = new DIContainer();
        $c->registerService(Kernel::class, $this->kernel($root, [$plugin]));

        return [$c, $root, $plugin];
    }

    private function admit(DIContainer $c, string $root, string $seat, string $scope): void
    {
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', $scope);
        self::assertNotNull($group, "Prestamos declares nothing under {$scope}");
        self::assertTrue($this->ledger($root)->admit($seat, 'Prestamos', $scope, $group['verbs'], 'key:' . self::HUMAN));
    }

    private function asks(DIContainer $c, string $root, string $seat, string $verb): AuthorizationResult
    {
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $policy = new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel));

        return $policy->authorize(
            new ToolContext('key:' . $seat, 'cli', self::SEAT_SCOPES),
            new ToolDefinition(McpProjector::toolName($verb), $verb, [], static fn (): array => []),
            [],
        );
    }

    /** @return array<string, mixed> what `identity:seats` says of one seat */
    private function seat(DIContainer $c, string $fingerprint): array
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === 'identity:seats') {
                return array_column(($op->handler)([], null, ToolContext::cli())['seats'], null, 'fingerprint')[$fingerprint];
            }
        }
        self::fail('identity:seats is not offered');
    }

    private function operation(DIContainer $c): Operation
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === 'identity:withdraw') {
                return $op;
            }
        }
        self::fail('identity:withdraw is not offered');
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

    /** @param array<string, mixed> $arguments */
    private function signed(DIContainer $c, string $fingerprint, array $arguments): void
    {
        $authorization = new OperationAuthorization(operation: 'identity:withdraw', arguments: $arguments, host: 'lab-host', issuedAt: '2026-10-07T00:00:00+00:00', nonce: 'n-1');
        $c->{$c->has(GrantedAuthorization::class) ? 'replaceService' : 'registerService'}(GrantedAuthorization::class, new GrantedAuthorization(
            authorization: $authorization,
            signer: new VerifiedSigner($fingerprint, 'Lab <lab@example.invalid>'),
            payload: $authorization->canonical(),
            signature: 'exact-signature-bytes',
        ));
    }

    /**
     * Register the intent proof for one credential and return a function that touches it for a call.
     *
     * @return \Closure(array<string, mixed>): array<string, string>
     */
    private function passkey(DIContainer $c, string $credentialId): \Closure
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
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
            }
        };
        $credentials->register(new RegisteredCredential($credentialId, (string) $details['key'], 0));
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
        $admission = new PasskeyIntentAdmission(new PasskeyAuthenticator($challenges, $credentials), new InMemoryIntentChallengeStore());
        $c->registerService(PasskeyIntentProof::class, new PasskeyIntentProof($admission, new RelyingParty(self::RP_ID, 'Milpa', ['https://' . self::RP_ID])));
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return static function (array $call) use ($admission, $key, $credentialId, $b64): array {
            $challenge = $admission->challengeFor(new OperationId('identity:withdraw'), $call, CapabilityAdmissions::WITHDRAW_INTENT_SESSION);
            $clientData = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $b64($challenge), 'origin' => 'https://' . self::RP_ID]);
            $authData = hash('sha256', self::RP_ID, true) . "\x05" . pack('N', 1);
            $signature = '';
            openssl_sign($authData . hash('sha256', $clientData, true), $signature, $key, \OPENSSL_ALGO_SHA256);

            return ['credentialId' => $credentialId, 'clientDataJSON' => $b64($clientData), 'authenticatorData' => $b64($authData), 'signature' => $b64($signature)];
        };
    }
}
