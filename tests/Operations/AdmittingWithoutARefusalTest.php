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
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * A person admits a scope of a built capability for a seat without waiting for that seat to be refused (greenhouse
 * decisions/0597).
 *
 * The first slice admits only from a refusal a session recorded. A seat that only calls from the terminal leaves
 * none — measured in evidence/1131: in a house that already existed it ran by the word, stopped, and had no way to
 * be admitted. What is admitted here is the same thing, by the same judge and the same write. What differs is how it
 * is named: with no call in front, the only thing typed is the digest of what was read — never a capability, never
 * a scope.
 */
final class AdmittingWithoutARefusalTest extends TestCase
{
    use BuiltHouse;

    private const STRANGER = 'D00D0000111122223333444455556666777788889';
    private const PASSKEY = 'QM1LEWEfsoWiMm';
    private const RP_ID = 'milpa.local';

    public function testItIsAPrivilegedSignedActAndNeverASeatsOwn(): void
    {
        $op = $this->operation(new DIContainer());

        self::assertTrue($op->mutating);
        self::assertTrue($op->requiresConfirmation);
        self::assertSame(['identity:enroll'], $op->scopes);
        self::assertSame(['cli', 'http'], $op->surfaces);
        self::assertSame(Authority::Privileged, $op->effectCeiling()->authority);
        self::assertSame(['seat', 'admits'], $op->inputSchema['required'] ?? null, 'nothing else is typed: no capability, no scope');
    }

    /** The house of evidence/1131: a seat that held the domain's words by hand and only ever calls from the terminal. */
    public function testASeatThatNeverLeftARefusalIsAdmittedByTheDigestOfWhatWasRead(): void
    {
        [$c, $root] = $this->house();
        $this->ledger($root)->record(new IdentityEnrolled(self::SEAT, [...self::SEAT_SCOPES, 'herramientas:write'], 'key:' . self::HUMAN));
        $waits = $this->waiting($c, self::SEAT, 'herramientas:write');
        self::assertTrue($waits['ran_before']);
        self::assertSame(['herramientas.agregar', 'herramientas.devolver', 'herramientas.prestar'], array_column($waits['opens'], 'verb'));
        $call = ['seat' => self::SEAT, 'admits' => $waits['contract']];
        $this->signed($c, self::HUMAN, $call);

        $r = $this->call($c, $call);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(self::SEAT, $r['fingerprint']);
        self::assertSame('Prestamos', $r['capability']);
        self::assertSame('herramientas:write', $r['granted']);
        self::assertSame(['herramientas.agregar', 'herramientas.devolver', 'herramientas.prestar'], $r['admitted']);
        self::assertSame($waits['contract'], $r['contract']);
        self::assertSame('key:' . self::HUMAN, $r['authorized_by']);
        // The same fact an admission from a refusal leaves, and no word.
        $ledger = $this->ledger($root);
        self::assertSame([...self::SEAT_SCOPES, 'herramientas:write'], $ledger->scopesFor(self::SEAT), 'the scopes are what they were');
        self::assertSame($r['scopes'], $ledger->scopesFor(self::SEAT));
        self::assertSame(array_column($waits['opens'], 'digest', 'verb'), $ledger->admissionsFor(self::SEAT)['Prestamos']['herramientas:write']['verbs']);
        self::assertTrue($this->runs($c, $root, self::SEAT, 'herramientas.prestar'));
        self::assertFalse($this->runs($c, $root, self::SEAT, 'herramientas.listar'), 'one act, one scope');
        self::assertFalse($this->runs($c, $root, self::OTHER_SEAT, 'herramientas.prestar'), 'one act, one seat');
        self::assertNotContains('herramientas:write', array_column($this->seat($c, self::SEAT)['unadmitted'], 'scope'));
    }

    public function testADigestThatIsNoScopeOfAnythingTodayAdmitsNothing(): void
    {
        [$c, $root, $plugin] = $this->house();
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');

        // A digest nobody was shown.
        $made = ['seat' => self::SEAT, 'admits' => 'sha256:' . str_repeat('0', 64)];
        $this->signed($c, self::HUMAN, $made);
        $r = $this->call($c, $made);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('is the digest of no scope of a capability this house has now', (string) $r['error']);

        // The digest of what was read — and the contract moved since.
        $read = ['seat' => self::SEAT, 'admits' => $this->waiting($c, self::SEAT, 'herramientas:write')['contract']];
        $this->signed($c, self::HUMAN, $read);
        $this->declare($plugin, [...$this->prestamos(), $this->verb('herramientas.baja', ['herramientas:write'], mutating: true)]);
        $c->replaceService(Kernel::class, $this->kernel($root, [$plugin]));
        $moved = $this->call($c, $read);
        self::assertFalse($moved['ok']);
        self::assertStringContainsString('read it again', (string) $moved['error']);

        // Nor is a word a digest: nothing typed names a scope.
        $word = ['seat' => self::SEAT, 'admits' => 'herramientas:write'];
        $this->signed($c, self::HUMAN, $word);
        self::assertFalse($this->call($c, $word)['ok']);

        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'));
    }

    public function testOnlyTheLineThatEnrolledTheSeatAdmitsAndTheSeatHasToBeOne(): void
    {
        [$c, $root] = $this->house();
        $admits = $this->waiting($c, self::SEAT, 'herramientas:read')['contract'];

        $call = ['seat' => self::SEAT, 'admits' => $admits];
        $this->signed($c, self::STRANGER, $call);
        $stranger = $this->call($c, $call);
        self::assertFalse($stranger['ok']);
        self::assertStringContainsString('you do not answer for that seat', (string) $stranger['error']);

        // The seat signing for itself: its own enroller is in its line, and that is not answering for it.
        $this->signed($c, self::SEAT, $call);
        self::assertFalse($this->call($c, $call)['ok'], 'a seat does not admit itself');

        $nobody = ['seat' => 'ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234', 'admits' => $admits];
        $this->signed($c, self::HUMAN, $nobody);
        self::assertStringContainsString('is no seat of this house', (string) $this->call($c, $nobody)['error']);

        $this->ledger($root)->revoke(self::OTHER_SEAT, 'key:' . self::HUMAN);
        $revoked = ['seat' => self::OTHER_SEAT, 'admits' => $admits];
        $this->signed($c, self::HUMAN, $revoked);
        self::assertFalse($this->call($c, $revoked)['ok']);

        // A passkey is a person, not a seat: nothing is admitted to it.
        $this->ledger($root)->record(new IdentityEnrolled(self::PASSKEY, ['milpa.admin'], 'key:' . self::HUMAN));
        $person = ['seat' => self::PASSKEY, 'admits' => $admits];
        $this->signed($c, self::HUMAN, $person);
        self::assertFalse($this->call($c, $person)['ok']);

        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
    }

    public function testTheProofHasToCoverThisSeatAndThisDigest(): void
    {
        [$c, $root] = $this->house();
        $read = $this->waiting($c, self::SEAT, 'herramientas:read')['contract'];
        $write = $this->waiting($c, self::SEAT, 'herramientas:write')['contract'];

        // No proof at all.
        $unsigned = $this->call($c, ['seat' => self::SEAT, 'admits' => $write]);
        self::assertFalse($unsigned['ok']);
        self::assertStringContainsString('requires the signature that names WHO decides', (string) $unsigned['error']);

        // A signature for reading does not admit writing; one for a seat does not admit another.
        $this->signed($c, self::HUMAN, ['seat' => self::SEAT, 'admits' => $read]);
        self::assertStringContainsString('does not cover', (string) $this->call($c, ['seat' => self::SEAT, 'admits' => $write])['error']);
        self::assertStringContainsString('does not cover', (string) $this->call($c, ['seat' => self::OTHER_SEAT, 'admits' => $read])['error']);

        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
        self::assertSame([], $this->ledger($root)->admissionsFor(self::OTHER_SEAT));
    }

    public function testWhatIsNotAdmissibleIsNotAdmittedByThisDoorEither(): void
    {
        [$c, $root, $plugin] = $this->house();
        $this->declare($plugin, [...$this->prestamos(), $this->verb('herramientas.purgar', ['herramientas:write'], mutating: true, classified: false)]);
        $c->replaceService(Kernel::class, $this->kernel($root, [$plugin]));
        $waits = $this->waiting($c, self::SEAT, 'herramientas:write');
        self::assertSame('«herramientas.purgar» does not declare its effects', $waits['not_admissible']);
        $call = ['seat' => self::SEAT, 'admits' => $waits['contract']];
        $this->signed($c, self::HUMAN, $call);

        $r = $this->call($c, $call);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('does not declare its effects', (string) $r['error']);
        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
    }

    /** In the panel: the passkey session, plus a live touch bound to this seat and this digest. */
    public function testAPasskeyTouchBoundToThisSeatAndThisDigestAdmitsAndOneBoundToAnotherDoesNot(): void
    {
        [$c, $root] = $this->house();
        $this->ledger($root)->record(new IdentityEnrolled(self::PASSKEY, ['milpa.admin', 'identity:enroll'], 'key:' . self::HUMAN));
        $touch = $this->passkey($c, self::PASSKEY);
        $web = new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']);
        $read = ['seat' => self::SEAT, 'admits' => $this->waiting($c, self::SEAT, 'herramientas:read')['contract']];
        $write = ['seat' => self::SEAT, 'admits' => $this->waiting($c, self::SEAT, 'herramientas:write')['contract']];

        $swapped = $this->call($c, $write + ['assertion' => $touch($read)], $web);
        self::assertFalse($swapped['ok']);
        self::assertStringContainsString('did not approve', (string) $swapped['error']);
        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));

        $r = $this->call($c, $write + ['assertion' => $touch($write)], $web);
        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('passkey:' . self::PASSKEY, $r['authorized_by']);
        self::assertSame('herramientas:write', $r['granted']);
    }

    /** Two doors to one fact: what `identity:grant` admits from a refusal and what this admits are written the same. */
    public function testWhatItLeavesIsWhatAnAdmissionFromARefusalLeaves(): void
    {
        [$c, $root] = $this->house();
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', 'herramientas:write');
        self::assertNotNull($group);
        $call = ['seat' => self::SEAT, 'admits' => $group['contract']];
        $this->signed($c, self::HUMAN, $call);
        self::assertTrue($this->call($c, $call)['ok']);

        $left = $this->ledger($root)->admissionsFor(self::SEAT)['Prestamos']['herramientas:write'];

        self::assertSame($group['verbs'], $left['verbs']);
        self::assertSame('key:' . self::HUMAN, $left['admitted_by']);
        $raw = json_decode((string) file_get_contents($root . '/storage/identity/enrollments.json'), true);
        self::assertArrayNotHasKey('admissions', $raw[self::SEAT]['history'][0], 'the state it replaced is kept');
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

    /** @return array<string, mixed> what `identity:seats` says of one seat */
    private function seat(DIContainer $c, string $fingerprint): array
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === 'identity:seats') {
                $answer = ($op->handler)([], null, ToolContext::cli());

                return array_column($answer['seats'], null, 'fingerprint')[$fingerprint];
            }
        }
        self::fail('identity:seats is not offered');
    }

    /** @return array<string, mixed> the row of a scope no admission covers, as a person reads it */
    private function waiting(DIContainer $c, string $fingerprint, string $scope): array
    {
        $row = array_column($this->seat($c, $fingerprint)['unadmitted'], null, 'scope')[$scope] ?? null;
        self::assertIsArray($row, "{$scope} is not waiting for an admission");
        self::assertStringStartsWith('sha256:', (string) $row['contract']);

        return $row;
    }

    private function runs(DIContainer $c, string $root, string $seat, string $verb): bool
    {
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $policy = new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel));

        return $policy->authorize(
            new ToolContext('key:' . $seat, 'cli', self::SEAT_SCOPES),
            new ToolDefinition(McpProjector::toolName($verb), $verb, [], static fn (): array => []),
            [],
        )->allowed;
    }

    private function operation(DIContainer $c): Operation
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === 'identity:admit') {
                return $op;
            }
        }
        self::fail('identity:admit is not offered');
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
        $authorization = new OperationAuthorization(operation: 'identity:admit', arguments: $arguments, host: 'lab-host', issuedAt: '2026-10-07T00:00:00+00:00', nonce: 'n-1');
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
            $challenge = $admission->challengeFor(new OperationId('identity:admit'), $call, CapabilityAdmissions::INTENT_SESSION);
            $clientData = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $b64($challenge), 'origin' => 'https://' . self::RP_ID]);
            $authData = hash('sha256', self::RP_ID, true) . "\x05" . pack('N', 1);
            $signature = '';
            openssl_sign($authData . hash('sha256', $clientData, true), $signature, $key, \OPENSSL_ALGO_SHA256);

            return ['credentialId' => $credentialId, 'clientDataJSON' => $b64($clientData), 'authenticatorData' => $b64($authData), 'signature' => $b64($signature)];
        };
    }
}
