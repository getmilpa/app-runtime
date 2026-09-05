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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Agent\InMemoryIntentChallengeStore;
use Milpa\AppRuntime\Agent\PasskeyIntentAdmission;
use Milpa\AppRuntime\Web\Controllers\PasskeyIntentController;
use Milpa\AppRuntime\Web\RegisteredCredentialIds;
use Milpa\Auth\WebAuthn\FileChallengeStore;
use Milpa\Auth\WebAuthn\FilePasskeyCredentialStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\RegisteredCredential;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The browser ceremony's HTTP door (greenhouse decisions/0187, evidence/0464): a challenge bound to a
 * concrete call goes out, a passkey assertion comes in, a proof-backed authorisation for THAT call
 * comes back — or a refusal. The ceremony's safety is milpa/auth's and the admission's; this pins that
 * the controller marshals the bytes. Assertions are signed by a real ES256 key (a synthetic
 * authenticator, no device).
 */
final class PasskeyIntentControllerTest extends TestCase
{
    private const RP_ID = 'milpa.local';

    private const CRED = 'cred-desktop-1';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    public function testTheCeremonyAuthorizesTheExactCall(): void
    {
        [$controller, $key] = $this->controller();

        $challenge = $this->optionsChallenge($controller, 'capabilities.enable', ['name' => 'a2a'], 'ses-A');
        $res = $controller->intentAdmit($this->admitRequest($key, $challenge));
        $body = json_decode((string) $res->getBody(), true);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($body['authorized']);
        self::assertSame('capabilities.enable', $body['operation']);
        self::assertSame('passkey:' . self::CRED, $body['principal']);
    }

    public function testAnAssertionOverAnUnboundChallengeIsRefused(): void
    {
        [$controller, $key, $authenticator] = $this->controller();

        // A crypto-valid challenge that no intent ceremony bound to a call.
        $challenge = $authenticator->challenge();
        $res = $controller->intentAdmit($this->admitRequest($key, $challenge));

        self::assertSame(401, $res->getStatusCode());
        self::assertFalse(json_decode((string) $res->getBody(), true)['ok']);
    }

    public function testATamperedSignatureIsRefused(): void
    {
        [$controller, $key] = $this->controller();

        $challenge = $this->optionsChallenge($controller, 'capabilities.enable', ['name' => 'a2a'], 'ses-A');
        $req = $this->admitRequest($key, $challenge, tamper: true);

        self::assertSame(401, $controller->intentAdmit($req)->getStatusCode());
    }

    public function testOptionsRequiresAnOperation(): void
    {
        [$controller] = $this->controller();

        $res = $controller->intentOptions(new ServerRequest('POST', '/webauthn/intent/options', [], (string) json_encode(['arguments' => []])));

        self::assertSame(400, $res->getStatusCode());
    }

    public function testTheCeremonyPageIsServed(): void
    {
        [$controller] = $this->controller();

        $res = $controller->page(new ServerRequest('GET', '/webauthn/intent'));

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('navigator.credentials.get', (string) $res->getBody());
        self::assertStringContainsString('/webauthn/intent/admit', (string) $res->getBody());
        // A non-discoverable key answers only a request that names it (greenhouse decisions/0206).
        self::assertStringContainsString('allowCredentials: allow', (string) $res->getBody());
        self::assertStringContainsString("userVerification: 'required'", (string) $res->getBody());
        // An extension that replaced the WebAuthn API is named before the ceremony waits on it (greenhouse evidence/0519).
        self::assertStringContainsString('has replaced navigator.credentials.get', (string) $res->getBody());
    }

    public function testOptionsNameEveryRegisteredCredential(): void
    {
        [$controller] = $this->controller();

        $res = $controller->intentOptions(new ServerRequest(
            'POST',
            '/webauthn/intent/options',
            [],
            (string) json_encode(['operation' => 'capabilities.enable', 'arguments' => [], 'session' => null]),
        ));
        $body = json_decode((string) $res->getBody(), true);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([['type' => 'public-key', 'id' => self::CRED]], $body['allowCredentials']);
    }

    // --- helpers ---

    /**
     * @param array<string, mixed> $arguments
     */
    private function optionsChallenge(PasskeyIntentController $controller, string $operation, array $arguments, string $session): string
    {
        $res = $controller->intentOptions(new ServerRequest(
            'POST',
            '/webauthn/intent/options',
            [],
            (string) json_encode(['operation' => $operation, 'arguments' => $arguments, 'session' => $session]),
        ));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);

        return (string) base64_decode(strtr($body['challenge'], '-_', '+/'), true);
    }

    private function admitRequest(\OpenSSLAsymmetricKey $key, string $challenge, bool $tamper = false): ServerRequest
    {
        $client = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='),
            'origin' => 'https://' . self::RP_ID,
        ]);
        $authData = hash('sha256', self::RP_ID, true) . "\x01" . pack('N', 7);
        $sig = '';
        openssl_sign($authData . hash('sha256', $client, true), $sig, $key, \OPENSSL_ALGO_SHA256);
        if ($tamper) {
            $sig .= 'x';
        }

        $b64u = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return new ServerRequest('POST', '/webauthn/intent/admit', [], (string) json_encode([
            'credentialId' => self::CRED,
            'clientDataJSON' => $b64u($client),
            'authenticatorData' => $b64u($authData),
            'signature' => $b64u($sig),
        ]));
    }

    /** @return array{0: PasskeyIntentController, 1: \OpenSSLAsymmetricKey, 2: PasskeyAuthenticator} */
    private function controller(): array
    {
        $dir = sys_get_temp_dir() . '/milpa-pic-' . bin2hex(random_bytes(4));
        $this->files[] = $dir . '-ch.json';
        $this->files[] = $dir . '-cr.json';

        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $pem = (string) openssl_pkey_get_details($key)['key'];

        $credentials = new FilePasskeyCredentialStore($dir . '-cr.json');
        $credentials->register(new RegisteredCredential(self::CRED, $pem, 0));
        $authenticator = new PasskeyAuthenticator(new FileChallengeStore($dir . '-ch.json'), $credentials);
        $admission = new PasskeyIntentAdmission($authenticator, new InMemoryIntentChallengeStore());

        return [new PasskeyIntentController($admission, new RegisteredCredentialIds($dir . '-cr.json'), self::RP_ID), $key, $authenticator];
    }
}
