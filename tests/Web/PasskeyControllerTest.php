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

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Web\Controllers\PasskeyController;
use Milpa\AppRuntime\Web\RegisteredCredentialIds;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\WebAuthn\FilePasskeyCredentialStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyLogin;
use Milpa\Auth\WebAuthn\ChallengeStore;
use Milpa\Auth\WebAuthn\FileChallengeStore;
use Milpa\Auth\WebAuthn\RegisteredCredential;
use Milpa\Auth\WebAuthn\WebAuthnRegistrationVerifier;
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

    public function testTheEnrollmentPageIsServed(): void
    {
        [$controller] = $this->controller(recognized: true);

        $res = $controller->enrollPage(new ServerRequest('GET', '/webauthn/enroll'));

        $body = (string) $res->getBody();
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('navigator.credentials.create', $body);
        self::assertStringContainsString('/webauthn/register', $body);
        // The ceremony prefers a roaming security key (a YubiKey) with a real user-verification gesture
        // (greenhouse evidence/0486): cross-platform attachment, user verification required.
        self::assertStringContainsString("authenticatorAttachment: 'cross-platform'", $body);
        self::assertStringContainsString("userVerification: 'required'", $body);
        // An extension that replaced the WebAuthn API is named before the ceremony waits on it (greenhouse evidence/0519).
        self::assertStringContainsString('has replaced navigator.credentials.create', $body);
    }

    public function testOptionsIssuesAChallengeAndNamesEveryRegisteredCredential(): void
    {
        [$controller] = $this->controller(recognized: true);

        $res = $controller->options(new ServerRequest('POST', '/webauthn/authenticate/options'));
        $body = json_decode((string) $res->getBody(), true);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(self::RP_ID, $body['rpId']);
        self::assertNotEmpty($body['challenge']);
        // A non-discoverable key (residentKey: discouraged at enrollment) only answers a request that
        // names it (greenhouse decisions/0206): the options list what the house registered.
        self::assertSame([['type' => 'public-key', 'id' => self::CRED]], $body['allowCredentials']);
    }

    public function testOptionsListNothingWhenNothingIsRegistered(): void
    {
        [$controller] = $this->controller(recognized: true, registerCred: false);

        $body = json_decode((string) $controller->options(new ServerRequest('POST', '/webauthn/authenticate/options'))->getBody(), true);

        self::assertSame([], $body['allowCredentials']);
    }

    /** `POST /webauthn/register` is open: a registered key nobody enrolled must not bloat the sign-in list. */
    public function testOptionsListNothingForARegisteredButUnenrolledCredential(): void
    {
        [$controller] = $this->controller(recognized: false);

        $body = json_decode((string) $controller->options(new ServerRequest('POST', '/webauthn/authenticate/options'))->getBody(), true);

        self::assertSame([], $body['allowCredentials'], 'registered ∩ enrolled is empty');
    }

    public function testTheSignInPageRendersTheScopeAndRunsTheCeremonyTowardsNext(): void
    {
        [$controller] = $this->controller(recognized: true);
        $req = (new ServerRequest('GET', '/webauthn/signin'))->withQueryParams(['next' => '/milpa/admin?tab=routes']);

        $res = $controller->signinPage($req);
        $body = (string) $res->getBody();

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Sign in to open the panel', $body);
        self::assertStringContainsString('Continue with a passkey', $body);
        self::assertStringContainsString('Scope requested: <code>milpa.admin</code>', $body);
        self::assertStringContainsString('const NEXT = "/milpa/admin?tab=routes";', $body);
        self::assertStringContainsString('/webauthn/authenticate/options', $body);
        self::assertStringContainsString('allowCredentials: allow', $body);
        self::assertStringContainsString("userVerification: 'required'", $body);
        self::assertStringContainsString('navigator.credentials.get', $body);
        self::assertStringContainsString("fetch('/webauthn/authenticate'", $body);
        self::assertStringContainsString('location.replace(NEXT)', $body);
        self::assertStringContainsString('Not enrolled', $body, 'an empty allowCredentials is said, not swallowed');
        self::assertStringContainsString('Passkey rejected', $body);
        // A password-manager extension that replaced navigator.credentials.get swallowed a real ceremony
        // (greenhouse evidence/0519): the page says so before it waits on the call.
        self::assertStringContainsString('has replaced navigator.credentials.get', $body);
    }

    public function testTheSignInPageShowsTheScopeItWasConfiguredWith(): void
    {
        [$controller] = $this->controller(recognized: true, gateScope: 'ops.panel');

        self::assertStringContainsString('Scope requested: <code>ops.panel</code>', (string) $controller->signinPage(new ServerRequest('GET', '/webauthn/signin'))->getBody());
    }

    /** @return iterable<string, array{0: string}> */
    public static function foreignNexts(): iterable
    {
        yield 'protocol-relative' => ['//evil.example'];
        yield 'an absolute URL' => ['https://x'];
        yield 'a backslash' => ['\\x'];
        yield 'a backslash after the slash' => ['/\\x'];
        yield 'relative' => ['milpa/admin'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignNexts')]
    public function testTheSignInPageNeverEmbedsANextThatIsNotALocalPath(string $next): void
    {
        [$controller] = $this->controller(recognized: true);

        $body = (string) $controller->signinPage((new ServerRequest('GET', '/webauthn/signin'))->withQueryParams(['next' => $next]))->getBody();

        self::assertStringContainsString('const NEXT = "/";', $body, 'the foreign target fell back to the root');
        self::assertStringNotContainsString($next, $body);
    }

    public function testTheSignInPageReadsNextFromTheUriWhenTheServerParsedNoQuery(): void
    {
        [$controller] = $this->controller(recognized: true);

        // A bare PSR-7 request carries the query only in its URI.
        $body = (string) $controller->signinPage(new ServerRequest('GET', '/webauthn/signin?next=%2Fmilpa%2Fadmin'))->getBody();
        self::assertStringContainsString('const NEXT = "/milpa/admin";', $body);

        // No next at all: the root.
        $body = (string) $controller->signinPage(new ServerRequest('GET', '/webauthn/signin'))->getBody();
        self::assertStringContainsString('const NEXT = "/";', $body);
    }

    public function testTheSignInPageEscapesNextAgainstScriptBreakout(): void
    {
        [$controller] = $this->controller(recognized: true);
        $next = '/x</script><script>alert(1)</script>';

        $body = (string) $controller->signinPage((new ServerRequest('GET', '/webauthn/signin'))->withQueryParams(['next' => $next]))->getBody();

        // The value survives (it IS a local path) but every angle bracket is a JSON escape, so the
        // literal can never close the script element it lives in.
        self::assertStringNotContainsString('</script><script>alert', $body);
        self::assertStringContainsString('const NEXT = "/x\\u003C/script\\u003E\\u003Cscript\\u003Ealert(1)\\u003C/script\\u003E";', $body);
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

    public function testRegisterOptionsIssuesAChallenge(): void
    {
        [$controller] = $this->controller(recognized: true);
        $res = $controller->registerOptions(new ServerRequest('POST', '/webauthn/register/options'));
        $body = json_decode((string) $res->getBody(), true);

        self::assertSame(200, $res->getStatusCode());
        self::assertNotEmpty($body['challenge']);
    }

    public function testARegistrationStoresACredentialThatCanThenLogIn(): void
    {
        [$controller, , , , $challenges, $credentials] = $this->controller(recognized: true, registerCred: false);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $credId = random_bytes(16);
        $challenge = $challenges->issue();

        // Register through the HTTP door.
        $reg = $controller->register($this->registerRequest($key, $challenge, $credId));
        $regBody = json_decode((string) $reg->getBody(), true);

        self::assertSame(201, $reg->getStatusCode());
        self::assertTrue($regBody['ok']);
        $storedId = $regBody['credentialId'];
        self::assertNotNull($credentials->find($storedId), 'the credential is remembered');

        // The registered credential is RECOGNIZED here (scopesFor returns scopes), so it can now log in.
        $auth = new PasskeyAuthenticator($challenges, $credentials);
        $login = new PasskeyLogin($auth, new InMemorySessionStore(), static fn (string $c): array => ['agent:read']);
        $registered = new RegisteredCredentialIds($this->files[array_key_last($this->files)]); // the credentials ledger
        $enrollments = new FileEnrollmentStore(sys_get_temp_dir() . '/milpa-passkey-en-' . bin2hex(random_bytes(6)) . '.json');
        $enrollments->record(new IdentityEnrolled($storedId, ['agent:read'], 'key:TEST')); // recognized = enrolled
        $loginController = new PasskeyController($auth, $login, $challenges, new WebAuthnRegistrationVerifier(), $credentials, $registered, $enrollments, self::RP_ID, self::COOKIE);
        // The freshly registered id is what the options now offer to the browser.
        $opt = json_decode((string) $loginController->options(new ServerRequest('POST', '/webauthn/authenticate/options'))->getBody(), true);
        self::assertSame([['type' => 'public-key', 'id' => $storedId]], $opt['allowCredentials']);
        $authChallenge = $auth->challenge();
        $res = $loginController->authenticate($this->assertionRequestFor($key, $authChallenge, $storedId));

        self::assertSame(200, $res->getStatusCode(), 'the just-registered credential logs in');
    }

    public function testAReplayedRegistrationIsRejected(): void
    {
        [$controller, , , , $challenges] = $this->controller(recognized: true, registerCred: false);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $challenge = $challenges->issue();
        $req = $this->registerRequest($key, $challenge, random_bytes(8));

        self::assertSame(201, $controller->register($req)->getStatusCode());
        // The challenge is spent — the same registration again is refused.
        self::assertSame(401, $controller->register($req)->getStatusCode());
    }

    public function testAMalformedRegistrationBodyIsRejected(): void
    {
        [$controller] = $this->controller(recognized: true);
        self::assertSame(400, $controller->register(new ServerRequest('POST', '/webauthn/register', [], 'not-json'))->getStatusCode());
    }

    // --- helpers ---

    /** @return array{0: PasskeyController, 1: PasskeyAuthenticator, 2: \OpenSSLAsymmetricKey, 3: InMemorySessionStore, 4: ChallengeStore, 5: FilePasskeyCredentialStore} */
    private function controller(bool $recognized, bool $registerCred = true, string $gateScope = 'milpa.admin'): array
    {
        $dir = sys_get_temp_dir() . '/milpa-pkc-' . bin2hex(random_bytes(4));
        $this->files[] = $dir . '-ch.json';
        $this->files[] = $dir . '-cr.json';

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = (string) openssl_pkey_get_details($key)['key'];
        $credentials = new FilePasskeyCredentialStore($dir . '-cr.json');
        if ($registerCred) {
            $credentials->register(new RegisteredCredential(self::CRED, $pem, 0));
        }

        $challenges = new FileChallengeStore($dir . '-ch.json');
        $auth = new PasskeyAuthenticator($challenges, $credentials);
        $sessions = new InMemorySessionStore();
        $scopesFor = $recognized
            ? static fn (string $c): array => ['agent:read']
            : static fn (string $c): ?array => null;
        $login = new PasskeyLogin($auth, $sessions, $scopesFor);
        $registered = new RegisteredCredentialIds($dir . '-cr.json');
        // The sign-in offers registered AND enrolled ids only (greenhouse decisions/0206): a recognized
        // credential is one the ledger enrolled — the same fact `$scopesFor` stands for above.
        $enrollments = new FileEnrollmentStore($dir . '-en.json');
        if ($recognized && $registerCred) {
            $enrollments->record(new IdentityEnrolled(self::CRED, ['agent:read'], 'key:TEST'));
        }
        $controller = new PasskeyController($auth, $login, $challenges, new WebAuthnRegistrationVerifier(), $credentials, $registered, $enrollments, self::RP_ID, self::COOKIE, $gateScope);

        return [$controller, $auth, $key, $sessions, $challenges, $credentials];
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

    private function assertionRequestFor(\OpenSSLAsymmetricKey $key, string $challenge, string $credentialId): ServerRequest
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

        return new ServerRequest('POST', '/webauthn/authenticate', [], (string) json_encode([
            'credentialId' => $credentialId,
            'clientDataJSON' => $b64($client),
            'authenticatorData' => $b64($data),
            'signature' => $b64($sig),
        ]));
    }

    private function registerRequest(\OpenSSLAsymmetricKey $key, string $challenge, string $credId): ServerRequest
    {
        $client = (string) json_encode([
            'type' => 'webauthn.create',
            'challenge' => rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='),
            'origin' => 'https://' . self::RP_ID,
        ]);
        $d = openssl_pkey_get_details($key);
        $cose = self::cborCoseMap([1 => 2, 3 => -7, -1 => 1, -2 => $d['ec']['x'], -3 => $d['ec']['y']]);
        $authData = hash('sha256', self::RP_ID, true) . "\x41" . pack('N', 0)
            . str_repeat("\x00", 16) . pack('n', \strlen($credId)) . $credId . $cose;
        $att = self::cborHead(5, 3)
            . self::cborText('fmt') . self::cborText('none')
            . self::cborText('attStmt') . self::cborHead(5, 0)
            . self::cborText('authData') . self::cborBytes($authData);
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return new ServerRequest('POST', '/webauthn/register', [], (string) json_encode([
            'clientDataJSON' => $b64($client),
            'attestationObject' => $b64($att),
        ]));
    }

    /** @param array<int, int|string> $map */
    private static function cborCoseMap(array $map): string
    {
        $out = self::cborHead(5, \count($map));
        foreach ($map as $k => $v) {
            $out .= self::cborInt($k);
            $out .= \is_int($v) ? self::cborInt($v) : self::cborBytes($v);
        }

        return $out;
    }

    private static function cborInt(int $n): string
    {
        return $n >= 0 ? self::cborHead(0, $n) : self::cborHead(1, -1 - $n);
    }

    private static function cborBytes(string $sVal): string
    {
        return self::cborHead(2, \strlen($sVal)) . $sVal;
    }

    private static function cborText(string $sVal): string
    {
        return self::cborHead(3, \strlen($sVal)) . $sVal;
    }

    private static function cborHead(int $major, int $value): string
    {
        $mt = $major << 5;
        if ($value < 24) {
            return \chr($mt | $value);
        }
        if ($value < 256) {
            return \chr($mt | 24) . \chr($value);
        }

        return \chr($mt | 25) . pack('n', $value);
    }
}
