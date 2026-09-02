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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\InMemoryIntentChallengeStore;
use Milpa\AppRuntime\Agent\PasskeyIntentAdmission;
use Milpa\Auth\WebAuthn\ChallengeStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyCredentialStore;
use Milpa\Auth\WebAuthn\RegisteredCredential;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\Consent;
use PHPUnit\Framework\TestCase;

/**
 * The D-01 residue (greenhouse decisions/0187, evidence/0459): a recording channel's verified-human
 * YES, made a first-class proof by a passkey, mints an IntentGrant for the EXACT call — the second
 * producer of the satisfiedBy seam. Every assertion here is signed by a real ES256 key (a synthetic
 * authenticator, no device): the crypto is genuine, only the browser ceremony is out of frame.
 */
final class PasskeyIntentAdmissionTest extends TestCase
{
    private const RP_ID = 'milpa.local';

    private const CRED = 'cred-desktop-1';

    /** THE FIX: a genuine passkey approval mints a proof-backed grant that admits the EXACT call. */
    public function testAGenuinePasskeyApprovalMintsAGrantForTheExactCall(): void
    {
        [$priv, $pub] = $this->keypair();
        $admission = $this->admission($pub);

        $challenge = $admission->challengeFor(new OperationId('capabilities.enable'), ['name' => 'a2a'], 'ses-A');
        [$client, $data, $sig] = $this->assertion($priv, $challenge, counter: 3);

        $grant = $admission->admit(self::RP_ID, self::CRED, $client, $data, $sig);

        self::assertNotNull($grant);
        self::assertSame('intent-grant', $grant->provenance);
        self::assertSame('passkey:' . self::CRED, $grant->principal);
        self::assertTrue($grant->admits('capabilities.enable', ['name' => 'a2a'], 'ses-A'), 'the verified human YES clears the exact call');
        self::assertFalse($grant->admits('capabilities.enable', ['name' => 'exfil'], 'ses-A'), 'and only the exact call');
        self::assertFalse($grant->admits('capabilities.enable', ['name' => 'a2a'], 'ses-B'), 'and only in its session');
    }

    /** THE SEAM (evidence/0459): the passkey grant clears the SAME demand a gpg signature would. */
    public function testThePasskeyGrantClearsThePrivilegedDemand(): void
    {
        [$priv, $pub] = $this->keypair();
        $admission = $this->admission($pub);
        $op = new Operation(
            'capabilities.enable',
            'enables an executable capability under a privileged authority',
            static fn (): array => ['ok' => true],
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Privileged,
                subject: Subject::Executable,
                rollbackContract: 'synthetic probe',
            ),
        );

        self::assertTrue(Consent::demanded($op, ['name' => 'a2a']), 'a privileged executable op demands authorization');

        $challenge = $admission->challengeFor(new OperationId('capabilities.enable'), ['name' => 'a2a'], 'ses-A');
        [$client, $data, $sig] = $this->assertion($priv, $challenge, counter: 3);
        $grant = $admission->admit(self::RP_ID, self::CRED, $client, $data, $sig);

        self::assertNotNull($grant);
        self::assertTrue(
            Consent::satisfiedBy($op, ['name' => 'a2a'], [$grant], 'ses-A'),
            'the verified-human passkey is a proof the demand accepts — one authority, many projections',
        );
        self::assertFalse(
            Consent::satisfiedBy($op, ['name' => 'a2a', 'force' => true], [$grant], 'ses-A'),
            'but only for the exact call the human approved',
        );
    }

    /** An unregistered credential proves nothing — no grant. */
    public function testAnUnregisteredCredentialMintsNothing(): void
    {
        [$priv] = $this->keypair();
        $admission = $this->admission($this->keypair()[1]); // the store holds a DIFFERENT key

        $challenge = $admission->challengeFor(new OperationId('capabilities.enable'), ['name' => 'a2a'], 'ses-A');
        [$client, $data, $sig] = $this->assertion($priv, $challenge);

        self::assertNull($admission->admit(self::RP_ID, self::CRED, $client, $data, $sig), 'a signature from an unregistered key is not authority');
    }

    /** A tampered signature proves nothing — no grant. */
    public function testATamperedSignatureMintsNothing(): void
    {
        [$priv, $pub] = $this->keypair();
        $admission = $this->admission($pub);

        $challenge = $admission->challengeFor(new OperationId('capabilities.enable'), ['name' => 'a2a'], 'ses-A');
        [$client, $data, $sig] = $this->assertion($priv, $challenge);

        self::assertNull($admission->admit(self::RP_ID, self::CRED, $client, $data, $sig . 'x'), 'a broken signature is not authority');
    }

    /** A replayed assertion mints nothing — the challenge is spent once. */
    public function testAReplayedAssertionMintsNothing(): void
    {
        [$priv, $pub] = $this->keypair();
        $admission = $this->admission($pub);

        $challenge = $admission->challengeFor(new OperationId('capabilities.enable'), ['name' => 'a2a'], 'ses-A');
        [$client, $data, $sig] = $this->assertion($priv, $challenge, counter: 3);

        self::assertNotNull($admission->admit(self::RP_ID, self::CRED, $client, $data, $sig), 'first use works');
        self::assertNull($admission->admit(self::RP_ID, self::CRED, $client, $data, $sig), 'the challenge is single-use');
    }

    /** A valid assertion over an UNBOUND challenge mints nothing — no call to authorise. */
    public function testAnUnboundChallengeMintsNothing(): void
    {
        [$priv, $pub] = $this->keypair();
        $authenticator = $this->authenticator($pub);
        $admission = new PasskeyIntentAdmission($authenticator, new InMemoryIntentChallengeStore());

        // Issue a crypto-valid challenge WITHOUT binding it to any call.
        $challenge = $authenticator->challenge();
        [$client, $data, $sig] = $this->assertion($priv, $challenge);

        self::assertNull($admission->admit(self::RP_ID, self::CRED, $client, $data, $sig), 'a proven assertion with no bound call authorises nothing');
    }

    // --- helpers ---

    private function admission(string $publicKeyPem): PasskeyIntentAdmission
    {
        return new PasskeyIntentAdmission($this->authenticator($publicKeyPem), new InMemoryIntentChallengeStore());
    }

    private function authenticator(string $publicKeyPem): PasskeyAuthenticator
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
        $credentials->register(new RegisteredCredential(self::CRED, $publicKeyPem, 0));

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

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private function keypair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return [$key, (string) $details['key']];
    }

    /**
     * @return array{0: string, 1: string, 2: string} clientDataJSON, authenticatorData, signature
     */
    private function assertion(\OpenSSLAsymmetricKey $priv, string $challenge, int $counter = 1): array
    {
        $clientData = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='),
            'origin' => 'https://' . self::RP_ID,
        ]);

        $authData = hash('sha256', self::RP_ID, true) . "\x01" . pack('N', $counter);
        $signedData = $authData . hash('sha256', $clientData, true);
        $sig = '';
        openssl_sign($signedData, $sig, $priv, \OPENSSL_ALGO_SHA256);

        return [$clientData, $authData, $sig];
    }
}
