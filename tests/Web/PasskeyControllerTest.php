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

use Milpa\AppRuntime\Web\Controllers\PasskeyController;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\WebAuthn\FileChallengeStore;
use Milpa\Auth\WebAuthn\FilePasskeyCredentialStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyLogin;
use Milpa\Auth\WebAuthn\RegisteredCredential;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The passkey HTTP door: a challenge out, an assertion in, a session cookie back — or a refusal. The
 * ceremony's safety is milpa/auth's; this pins that the controller marshals the bytes and the cookie
 * (greenhouse decisions/0126).
 */
final class PasskeyControllerTest extends TestCase
{
    private const RP_ID = 'milpa.local';
    private const CRED = 'cred-1';
    private const COOKIE = 'milpa_session';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    public function testOptionsIssuesAChallenge(): void
    {
        [$controller] = $this->controller(recognized: true);

        $res = $controller->options(new ServerRequest('POST', '/webauthn/authenticate/options'));
        $body = json_decode((string) $res->getBody(), true);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(self::RP_ID, $body['rpId']);
        self::assertNotEmpty($body['challenge']);
    }

    public function testARecognizedAssertionMintsASessionCookie(): void
    {
        [$controller, $auth, $key, $sessions] = $this->controller(recognized: true);
        $challenge = $auth->challenge();
        $req = $this->assertionRequest($key, $challenge);

        $res = $controller->authenticate($req);
        $body = json_decode((string) $res->getBody(), true);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($body['ok']);
        self::assertSame('passkey:' . self::CRED, $body['actor']);
        self::assertStringContainsString(self::COOKIE . '=', $res->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('HttpOnly', $res->getHeaderLine('Set-Cookie'));
        // The session was actually written.
        $id = explode(';', explode('=', $res->getHeaderLine('Set-Cookie'), 2)[1])[0];
        self::assertNotNull($sessions->read($id));
    }

    public function testAnUnrecognizedAssertionIsRefusedWithNoCookie(): void
    {
        [$controller, $auth, $key] = $this->controller(recognized: false);
        $challenge = $auth->challenge();

        $res = $controller->authenticate($this->assertionRequest($key, $challenge));

        self::assertSame(401, $res->getStatusCode());
        self::assertSame('', $res->getHeaderLine('Set-Cookie'));
    }

    public function testAMalformedBodyIsRejected(): void
    {
        [$controller] = $this->controller(recognized: true);

        $res = $controller->authenticate(new ServerRequest('POST', '/webauthn/authenticate', [], 'not-json'));

        self::assertSame(400, $res->getStatusCode());
    }

    public function testMissingFieldsAreRejected(): void
    {
        [$controller] = $this->controller(recognized: true);
        $req = new ServerRequest('POST', '/webauthn/authenticate', [], (string) json_encode(['credentialId' => 'x']));

        $res = $controller->authenticate($req);

        self::assertSame(400, $res->getStatusCode());
    }

    // --- helpers ---

    /** @return array{0: PasskeyController, 1: PasskeyAuthenticator, 2: \OpenSSLAsymmetricKey, 3: InMemorySessionStore} */
    private function controller(bool $recognized): array
    {
        $dir = sys_get_temp_dir() . '/milpa-pkc-' . bin2hex(random_bytes(4));
        $this->files[] = $dir . '-ch.json';
        $this->files[] = $dir . '-cr.json';

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = (string) openssl_pkey_get_details($key)['key'];
        $credentials = new FilePasskeyCredentialStore($dir . '-cr.json');
        $credentials->register(new RegisteredCredential(self::CRED, $pem, 0));

        $auth = new PasskeyAuthenticator(new FileChallengeStore($dir . '-ch.json'), $credentials);
        $sessions = new InMemorySessionStore();
        $scopesFor = $recognized
            ? static fn (string $c): array => ['agent:read']
            : static fn (string $c): ?array => null;
        $login = new PasskeyLogin($auth, $sessions, $scopesFor);

        return [new PasskeyController($auth, $login, self::RP_ID, self::COOKIE), $auth, $key, $sessions];
    }

    private function assertionRequest(\OpenSSLAsymmetricKey $key, string $challenge): ServerRequest
    {
        $client = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='),
            'origin' => 'https://' . self::RP_ID,
        ]);
        $data = hash('sha256', self::RP_ID, true) . "\x01" . pack('N', 7);
        $sig = '';
        openssl_sign($data . hash('sha256', $client, true), $sig, $key, OPENSSL_ALGO_SHA256);

        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $payload = (string) json_encode([
            'credentialId' => self::CRED,
            'clientDataJSON' => $b64($client),
            'authenticatorData' => $b64($data),
            'signature' => $b64($sig),
        ]);

        return new ServerRequest('POST', '/webauthn/authenticate', [], $payload);
    }
}
