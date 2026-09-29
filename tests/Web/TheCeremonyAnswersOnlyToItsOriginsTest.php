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

use Milpa\AppRuntime\Identity\EnrollmentStore;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Web\Controllers\PasskeyController;
use Milpa\AppRuntime\Web\Controllers\PasskeyIntentController;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\AppRuntime\Web\RegisteredCredentialIds;
use Milpa\Auth\WebAuthn\FilePasskeyCredentialStore;
use Milpa\Auth\WebAuthn\PasskeyCredentialStore;
use Milpa\Auth\WebAuthn\RelyingParty;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Every passkey ceremony the plugin mounts is held to the house's declared origins and to a verified
 * user (milpa/auth 0.11) — through the REAL controllers the plugin registers, not a verifier called by hand.
 *
 * Each refusal has its control beside it: the same key, the same challenge flow, the same enrollment,
 * differing only in the origin the clientDataJSON reports or in the UV flag. A refusal that the control
 * does not turn into a success would prove nothing.
 *
 * Plus the two things the plugin now owes its host: it refuses to boot on a relying party without
 * `passkey.origins`, and it hands the relying party and its ledgers out through the container.
 */
final class TheCeremonyAnswersOnlyToItsOriginsTest extends TestCase
{
    private const RP_ID = 'milpa.local';
    private const ORIGIN = 'https://milpa.local';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmdir($root);
        }
    }

    /** @return iterable<string, array{0: string|null, 1: bool}> */
    public static function refusedCeremonies(): iterable
    {
        yield 'a page the house does not serve' => ['https://evil.example', true];
        yield 'an unlisted subdomain of the rpId' => ['https://evil.milpa.local', true];
        yield 'the listed host over plain http' => ['http://milpa.local', true];
        yield 'the listed host on another port' => ['https://milpa.local:8443', true];
        yield 'the listed origin, but no user verification' => [null, false];
    }

    #[DataProvider('refusedCeremonies')]
    public function testARegistrationFromAnotherOriginOrWithoutUvRegistersNothing(?string $origin, bool $verified): void
    {
        [$c] = $this->bootedHouse();
        $door = $this->door($c);
        $key = SyntheticPasskey::key();

        $refused = $door->register($this->post('/webauthn/register', SyntheticPasskey::attestation($key, self::RP_ID, $this->registerChallenge($door), 'cred-refused', $origin, $verified)));

        self::assertSame(401, $refused->getStatusCode());
        self::assertSame('passkey_rejected', $this->json($refused)['error']);
        self::assertSame([], $this->registered($c)->all(), 'a refused registration leaves no credential behind');

        // Control: the same key, the declared origin, UV set — admitted.
        $admitted = $door->register($this->post('/webauthn/register', SyntheticPasskey::attestation($key, self::RP_ID, $this->registerChallenge($door), 'cred-admitted')));
        self::assertSame(201, $admitted->getStatusCode());
        self::assertSame([SyntheticPasskey::b64u('cred-admitted')], $this->registered($c)->all());
    }

    #[DataProvider('refusedCeremonies')]
    public function testASignInFromAnotherOriginOrWithoutUvMintsNoSession(?string $origin, bool $verified): void
    {
        [$c] = $this->bootedHouse();
        $door = $this->door($c);
        [$key, $credentialId] = $this->enrolledKey($c, $door);

        $refused = $door->authenticate($this->post('/webauthn/authenticate', SyntheticPasskey::assertion($key, self::RP_ID, $this->loginChallenge($door), $credentialId, 5, $origin, $verified)));

        self::assertSame(401, $refused->getStatusCode());
        self::assertSame('', $refused->getHeaderLine('Set-Cookie'), 'no cookie for a refused ceremony');

        // Control: the same enrolled key, a climbing counter, the declared origin and UV — a session.
        $admitted = $door->authenticate($this->post('/webauthn/authenticate', SyntheticPasskey::assertion($key, self::RP_ID, $this->loginChallenge($door), $credentialId, 9)));
        self::assertSame(200, $admitted->getStatusCode());
        self::assertSame('passkey:' . $credentialId, $this->json($admitted)['actor']);
        self::assertStringStartsWith(PasskeyPlugin::DEFAULT_COOKIE . '=', $admitted->getHeaderLine('Set-Cookie'));
    }

    #[DataProvider('refusedCeremonies')]
    public function testAnIntentFromAnotherOriginOrWithoutUvAuthorisesNothing(?string $origin, bool $verified): void
    {
        [$c] = $this->bootedHouse();
        [$key, $credentialId] = $this->enrolledKey($c, $this->door($c));
        $intent = $c->get(PasskeyIntentController::class);
        self::assertInstanceOf(PasskeyIntentController::class, $intent);

        $refused = $intent->intentAdmit($this->post('/webauthn/intent/admit', SyntheticPasskey::assertion($key, self::RP_ID, $this->intentChallenge($intent), $credentialId, 5, $origin, $verified)));
        self::assertSame(401, $refused->getStatusCode());
        self::assertSame('passkey_rejected', $this->json($refused)['error']);

        // Control: the same key over a fresh bound challenge, the declared origin and UV — authorised.
        $admitted = $intent->intentAdmit($this->post('/webauthn/intent/admit', SyntheticPasskey::assertion($key, self::RP_ID, $this->intentChallenge($intent), $credentialId, 9)));
        self::assertSame(200, $admitted->getStatusCode());
        self::assertTrue($this->json($admitted)['authorized']);
    }

    /** The options every ceremony starts from say what the verifier will demand, so a browser is never asked for less. */
    public function testEveryCeremonyIsToldThatUserVerificationIsRequired(): void
    {
        [$c] = $this->bootedHouse();
        $door = $this->door($c);
        $intent = $c->get(PasskeyIntentController::class);
        self::assertInstanceOf(PasskeyIntentController::class, $intent);

        foreach ([
            'register' => $door->registerOptions(new ServerRequest('POST', '/webauthn/register/options')),
            'sign in' => $door->options(new ServerRequest('POST', '/webauthn/authenticate/options')),
            'intent' => $intent->intentOptions($this->post('/webauthn/intent/options', ['operation' => 'identity:grant'])),
        ] as $ceremony => $response) {
            $options = $this->json($response);
            self::assertSame('required', $options['userVerification'] ?? null, $ceremony . ' options');
            self::assertSame(self::RP_ID, $options['rpId'], $ceremony . ' options');
        }
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function undeclaredOrigins(): iterable
    {
        yield 'a string, not a list' => [self::ORIGIN, 'not a non-empty list of strings'];
        yield 'an empty list' => [[], 'not a non-empty list of strings'];
        yield 'a map, not a list' => [['main' => self::ORIGIN], 'not a non-empty list of strings'];
        yield 'a non-string entry' => [[self::ORIGIN, 443], 'not a non-empty list of strings'];
        yield 'a wildcard' => [['https://*.milpa.local'], 'passkey.origins in config/app.php'];
        yield 'plain http off loopback' => [['http://milpa.local'], 'passkey.origins in config/app.php'];
        yield 'a host outside the rpId' => [['https://other.example'], 'passkey.origins in config/app.php'];
        yield 'an origin with a path' => [['https://milpa.local/login'], 'passkey.origins in config/app.php'];
    }

    /**
     * Origins DECLARED wrong are a misconfiguration said at boot — never a door that opens anyway, and
     * never origins guessed from the request. (Origins not declared at all are a house from before 0.201:
     * see the tests below.)
     */
    #[DataProvider('undeclaredOrigins')]
    public function testARelyingPartyWithoutValidOriginsRefusesToBoot(mixed $origins, string $said): void
    {
        [$c] = $this->house(['rpId' => self::RP_ID, 'origins' => $origins]);
        $plugin = new PasskeyPlugin($c);

        try {
            $plugin->boot();
            self::fail('the plugin booted with passkey.origins ' . get_debug_type($origins));
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($said, $e->getMessage());
            self::assertStringContainsString('passkey.origins', $e->getMessage(), 'the message names the key to write');
        }
        self::assertSame([], $plugin->routes(), 'no route is mounted for a half-declared relying party');
        self::assertFalse($c->has(RelyingParty::class) && $c->get(RelyingParty::class) instanceof RelyingParty);
    }

    /**
     * The sentence names the fix a person can type: for `localhost` the origin `php bin/coa serve` answers
     * on, not an `https://localhost` no laptop serves — the same rule the writer declares by.
     */
    public function testTheRefusalShowsTheOriginsTheHouseWouldDeclare(): void
    {
        [$c] = $this->house(['rpId' => 'localhost', 'origins' => 'http://localhost:8000']);

        try {
            (new PasskeyPlugin($c))->boot();
            self::fail('the plugin booted with a string for passkey.origins');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("'origins' => ['http://localhost:8000']", $e->getMessage());
            self::assertStringNotContainsString('https://localhost', $e->getMessage());
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function housesFromBeforeTheOrigins(): iterable
    {
        yield 'a laptop house (0.200.x wrote rpId localhost)' => ['localhost', 'http://localhost:8000', 'http://localhost:8001'];
        yield 'a house on its domain' => [self::RP_ID, self::ORIGIN, 'https://evil.milpa.local'];
    }

    /**
     * A HOUSE FROM BEFORE 0.201 STILL BOOTS (greenhouse decisions/0533). `capabilities:enable identity` on
     * 0.200.x wrote `rpId` and nothing else; 0.201 refused to boot that house, and every writer with a boot
     * check refused with it. With `origins` ABSENT the door is held to exactly the origins a new house gets
     * written — derived from the declared rpId, never from the request — and refuses every other one.
     */
    #[DataProvider('housesFromBeforeTheOrigins')]
    public function testAHouseThatDeclaredOnlyItsRpIdIsHeldToTheOriginsANewHouseGetsWritten(string $rpId, string $origin, string $other): void
    {
        [$c] = $this->house(['rpId' => $rpId]);
        $plugin = new PasskeyPlugin($c);
        $plugin->boot();

        self::assertNotSame([], $plugin->routes(), 'the door is mounted');
        $rp = $c->get(RelyingParty::class);
        self::assertInstanceOf(RelyingParty::class, $rp);
        self::assertSame([$origin], $rp->allowedOrigins);
        self::assertSame(Capabilities::originsFor($rpId), $rp->allowedOrigins, 'one rule: the writer\'s');

        $door = $this->door($c);
        $key = SyntheticPasskey::key();
        $challenge = static fn (): string => SyntheticPasskey::unb64u((string) json_decode((string) $door->registerOptions(new ServerRequest('POST', '/webauthn/register/options'))->getBody(), true)['challenge']);

        $refused = $door->register($this->post('/webauthn/register', SyntheticPasskey::attestation($key, $rpId, $challenge(), 'cred-other', $other)));
        self::assertSame(401, $refused->getStatusCode(), 'an origin the house would not declare is refused');
        self::assertSame([], $this->registered($c)->all());

        // Control: the same key from the derived origin — registered.
        $admitted = $door->register($this->post('/webauthn/register', SyntheticPasskey::attestation($key, $rpId, $challenge(), 'cred-derived', $origin)));
        self::assertSame(201, $admitted->getStatusCode());
        self::assertSame([SyntheticPasskey::b64u('cred-derived')], $this->registered($c)->all());
    }

    /** Loopback may speak plain http, and several origins are one relying party. */
    public function testALoopbackHouseMayListSeveralOrigins(): void
    {
        [$c] = $this->house(['rpId' => 'localhost', 'origins' => ['http://localhost:8000', 'https://localhost']]);
        (new PasskeyPlugin($c))->boot();

        $rp = $c->get(RelyingParty::class);
        self::assertInstanceOf(RelyingParty::class, $rp);
        self::assertSame(['http://localhost:8000', 'https://localhost'], $rp->allowedOrigins);
    }

    /**
     * The relying party and the ledgers are the container's, at the paths they always had — so a host
     * that runs its own ceremony (Surco's signature) asks for them instead of repeating the paths.
     */
    public function testTheRelyingPartyAndTheLedgersAreHandedOutByTheContainer(): void
    {
        [$c, $root] = $this->bootedHouse();
        $door = $this->door($c);

        $rp = $c->get(RelyingParty::class);
        self::assertInstanceOf(RelyingParty::class, $rp);
        self::assertSame(self::RP_ID, $rp->id);
        self::assertSame([self::ORIGIN], $rp->allowedOrigins);

        // What the door registers, the container's credential store and id list see — at var/passkey/.
        [, $credentialId] = $this->enrolledKey($c, $door);
        $credentials = $c->get(PasskeyCredentialStore::class);
        self::assertInstanceOf(FilePasskeyCredentialStore::class, $credentials);
        self::assertNotNull($credentials->find($credentialId));
        self::assertSame([$credentialId], $this->registered($c)->all());
        self::assertNotNull((new FilePasskeyCredentialStore($root . '/var/passkey/credentials.json'))->find($credentialId), 'the path did not move');

        // What the container's enrollment store records is what the ledger at storage/identity/ holds.
        $enrollments = $c->get(EnrollmentStore::class);
        self::assertInstanceOf(EnrollmentStore::class, $enrollments);
        self::assertSame(['milpa.admin'], $enrollments->scopesFor($credentialId));
        self::assertSame(['milpa.admin'], (new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))->scopesFor($credentialId), 'the path did not move');
        self::assertSame($root . '/' . PasskeyPlugin::ENROLLMENTS_PATH, $root . '/storage/identity/enrollments.json');
        self::assertSame($root . '/' . PasskeyPlugin::CREDENTIALS_PATH, $root . '/var/passkey/credentials.json');
    }

    // --- helpers ---

    /** @return array{0: DIContainer, 1: string} */
    private function bootedHouse(): array
    {
        [$c, $root] = $this->house(['rpId' => self::RP_ID, 'origins' => [self::ORIGIN]]);
        (new PasskeyPlugin($c))->boot();

        return [$c, $root];
    }

    /**
     * @param array<string, mixed> $passkey
     *
     * @return array{0: DIContainer, 1: string}
     */
    private function house(array $passkey): array
    {
        $root = sys_get_temp_dir() . '/milpa-rp-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        $this->roots[] = $root;

        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['passkey' => $passkey]));
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => [], 'container' => $c] as $name => $value) {
            (new \ReflectionProperty(Kernel::class, $name))->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);

        return [$c, $root];
    }

    private function door(DIContainer $c): PasskeyController
    {
        $door = $c->get(PasskeyController::class);
        self::assertInstanceOf(PasskeyController::class, $door);

        return $door;
    }

    private function registered(DIContainer $c): RegisteredCredentialIds
    {
        $registered = $c->get(RegisteredCredentialIds::class);
        self::assertInstanceOf(RegisteredCredentialIds::class, $registered);

        return $registered;
    }

    /**
     * A key registered through the real door and enrolled with `milpa.admin` through the container's ledger.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}
     */
    private function enrolledKey(DIContainer $c, PasskeyController $door): array
    {
        $key = SyntheticPasskey::key();
        $response = $door->register($this->post('/webauthn/register', SyntheticPasskey::attestation($key, self::RP_ID, $this->registerChallenge($door), 'cred-' . bin2hex(random_bytes(3)))));
        self::assertSame(201, $response->getStatusCode());
        $credentialId = (string) $this->json($response)['credentialId'];

        $enrollments = $c->get(EnrollmentStore::class);
        self::assertInstanceOf(EnrollmentStore::class, $enrollments);
        $enrollments->record(new IdentityEnrolled($credentialId, ['milpa.admin'], 'key:TEST'));

        return [$key, $credentialId];
    }

    private function registerChallenge(PasskeyController $door): string
    {
        return SyntheticPasskey::unb64u((string) $this->json($door->registerOptions(new ServerRequest('POST', '/webauthn/register/options')))['challenge']);
    }

    private function loginChallenge(PasskeyController $door): string
    {
        return SyntheticPasskey::unb64u((string) $this->json($door->options(new ServerRequest('POST', '/webauthn/authenticate/options')))['challenge']);
    }

    private function intentChallenge(PasskeyIntentController $intent): string
    {
        $options = $intent->intentOptions($this->post('/webauthn/intent/options', ['operation' => 'identity:grant', 'arguments' => ['scope' => 'milpa.admin']]));

        return SyntheticPasskey::unb64u((string) $this->json($options)['challenge']);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): ServerRequest
    {
        return new ServerRequest('POST', $path, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body, 'a JSON object body');

        return $body;
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
