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
use PHPUnit\Framework\Attributes\DataProvider;
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
        // THE LINE THAT HAS TO SURVIVE THIS SCREEN (greenhouse decisions/0260). Rod, cutting the lede
        // down: «eso último es la única doctrina que necesita sobrevivir en esta pantalla». Somebody
        // arriving here is about to touch a key; what changes their expectations is that touching it
        // identifies them and grants nothing.
        self::assertStringContainsString('Registering identifies you. It grants no permissions.', $body);
        // AND THE HALF THAT FINISHES IT. Rod: «step 2 debe terminar la oración que step 1 comenzó» —
        // the two together teach identity ≠ authority without one line of architecture.
        self::assertStringContainsString('Step 1 · Who are you?', $body);
        self::assertStringContainsString('Step 2 · What may you do?', $body);
        self::assertStringContainsString('Choose what this identity may do.', $body);
        // The house's own facts are true and secondary: behind a disclosure, not in the main hierarchy.
        self::assertStringContainsString('<summary>Technical details</summary>', $body);
        self::assertStringNotContainsString('Milpa is a PHP framework where an effect', $body, 'la pantalla no da clase de framework');
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
        // The headline is the act; where it leads is the lede's job (greenhouse decisions/0243).
        // It also stopped naming the panel: this page gates whatever the app put behind it, and the
        // panel is only the most common one.
        self::assertStringContainsString('<h1>Sign in</h1>', $body);
        // El copy dejó de prometer «the whole panel»: estas pantallas tienen que servir en una casa
        // sin panel instalado, que es el falsificador F3 de decisions/0243.
        // THE LINE THAT HAS TO SURVIVE THIS SCREEN (greenhouse decisions/0260), not any sentence of the
        // lede: the gate mints a session from a checked SIGNATURE, and that is what a reader must take
        // away. The lede used to open by explaining what kind of framework this is, to somebody who came
        // here to act.
        self::assertStringContainsString('The house checks the signature before it mints a session.', $body);
        self::assertStringNotContainsString('Milpa is a PHP framework where an effect', $body, 'la pantalla no da clase de framework');
        // Sobre lo que el humano LEE, no sobre el archivo entero: la primera versión de esta
        // aserción prohibía la palabra hasta en el comentario que explica por qué no se usa, y
        // grepear prosa no puede fallar por la razón correcta.
        $visible = (string) preg_replace('#<script\b.*?</script>#s', '', $body);
        self::assertStringNotContainsString('panel', $visible, 'the gate guards whatever the app put behind it');
        self::assertStringContainsString('Continue with a passkey', $body);
        self::assertStringContainsString('scope: <code>milpa.admin</code>', $body);
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

        self::assertStringContainsString('Required scope: <code>ops.panel</code>', (string) $controller->signinPage(new ServerRequest('GET', '/webauthn/signin'))->getBody());
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

    /**
     * 🚨 THE SCRIPT THESE PAGES SHIP MUST ACTUALLY PARSE (greenhouse decisions/0261).
     *
     * Every other test here asserts the HTML CONTAINS a string, and not one of them ever asked whether
     * the JavaScript was valid — so a broken script shipped and the ceremony did not work in a browser
     * from the day it was written. The page is an INTERPOLATING heredoc, so PHP ate the `\n` in a JS
     * string literal and wrote a real newline into it: `Uncaught SyntaxError: Invalid or unexpected
     * token`, no listener on the button, and a click that did nothing at all. Rod found it by clicking.
     *
     * This checks the shape that broke — a quoted literal cannot span lines — over the script both
     * pages ship, and it does it without needing a JS engine in CI.
     */
    #[DataProvider('ceremonyPages')]
    public function testTheScriptEachCeremonyPageShipsIsNotBrokenAcrossLines(string $page): void
    {
        [$controller] = $this->controller(recognized: true);
        $body = (string) ($page === 'enroll'
            ? $controller->enrollPage(new ServerRequest('GET', '/webauthn/enroll'))
            : $controller->signinPage(new ServerRequest('GET', '/webauthn/signin')))->getBody();

        self::assertSame(1, preg_match('#<script>(.*?)</script>#s', $body, $m), "$page ships a script");

        // A tiny lexer, not a regex: walk the script tracking whether we are inside a quoted literal,
        // and assert we never reach a newline while still in one. That IS the defect, stated exactly —
        // guessing at it with patterns produced two false positives (an apostrophe in a comment, and a
        // `'//'` inside a string), which is the instrument arguing with the finding.
        $script = $m[1];
        $quote = null;
        $line = 1;
        for ($i = 0, $len = \strlen($script); $i < $len; $i++) {
            $c = $script[$i];
            if ($c === '\\' && $quote !== null) {
                $i++;
                continue;
            }
            if ($c === "\n") {
                self::assertNull($quote, \sprintf('%s: a %s-quoted string is still open at the end of line %d', $page, $quote ?? '', $line));
                $line++;
                continue;
            }
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                // A quote inside a `// …` comment opens nothing; skip the rest of that line.
                $quote = $c;
            } elseif ($c === '/' && ($script[$i + 1] ?? '') === '/') {
                $nl = strpos($script, "\n", $i);
                $i = $nl === false ? $len : $nl - 1;
            }
        }
        self::assertNull($quote, "$page: the script ends inside an unterminated string");

        // AND WHEN A JS ENGINE IS AT HAND, ASK IT — the lexer above catches a string broken across
        // lines, which is the shape that shipped, and it did NOT catch the second one: a COMMENT broken
        // the same way, by the very note that explained the first. A parser has no such blind spots, so
        // it runs whenever `node` is on the box and this stays a lexer-only check where it is not.
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            return;
        }
        $file = tempnam(sys_get_temp_dir(), 'milpa-gate-') . '.js';
        $this->files[] = $file;
        file_put_contents($file, $script);
        @exec(escapeshellarg($node) . ' --check ' . escapeshellarg($file) . ' 2>&1', $said, $status);

        self::assertSame(0, $status, \sprintf("%s ships a script that does not parse:\n%s", $page, implode("\n", $said)));
    }

    /** @return iterable<string, array{0: string}> */
    public static function ceremonyPages(): iterable
    {
        yield 'enroll' => ['enroll'];
        yield 'sign in' => ['signin'];
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
        // 🚨 A COORDINATE IS 32 BYTES, ALWAYS — and `openssl_pkey_get_details()` does not pad it.
        //
        // COSE EC2 over P-256 fixes both coordinates at 32 bytes, left-padded with zeros, so a real
        // authenticator never sends fewer. OpenSSL returns the raw big-endian integer, which is one byte
        // short whenever the top byte happens to be zero: measured over 4 000 generated keys, **0.78 %**
        // — 2/256, exactly as the arithmetic predicts.
        //
        // Unpadded, this helper built a key no authenticator could produce, the controller correctly
        // refused it with a 401, and the test failed about once in every sixty runs. The 401 was RIGHT;
        // the fixture was wrong. Found because CI failed once and the same test passed five times
        // locally — the temptation there is to re-run until green, which is how a suite starts lying.
        $coord = static fn (string $raw): string => str_pad($raw, 32, "\x00", \STR_PAD_LEFT);
        $cose = self::cborCoseMap([1 => 2, 3 => -7, -1 => 1, -2 => $coord($d['ec']['x']), -3 => $coord($d['ec']['y'])]);
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
