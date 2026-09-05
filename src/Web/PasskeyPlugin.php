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

namespace Milpa\AppRuntime\Web;

use Milpa\AppRuntime\Agent\PasskeyIntentAdmission;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Web\Controllers\PasskeyController;
use Milpa\AppRuntime\Web\Controllers\PasskeyIntentController;
use Milpa\Attributes\PluginMetadata;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\FileSessionStore;
use Milpa\Auth\WebAuthn\FileChallengeStore;
use Milpa\Auth\WebAuthn\FilePasskeyCredentialStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyLogin;
use Milpa\Auth\WebAuthn\WebAuthnRegistrationVerifier;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * The last mile of the passkey login: the HTTP door that hands the browser a challenge and turns its
 * assertion into a session — where the WEB surface finally carries verified identity (greenhouse
 * decisions/0126, the pin's live rung).
 *
 * FAIL CLOSED WITHOUT AN RP-ID. WebAuthn binds every assertion to a relying-party id; an app that has
 * not declared `passkey.rpId` in `config/app.php` gets NO passkey routes and a quiet boot, never a
 * guessed default — a relying party nobody chose is one nobody can trust.
 *
 * THE DOOR PROVIDES WHAT THE DOOR NEEDS (greenhouse decisions/0206). A session store is where a login
 * lands; this plugin used to fail closed when the host had not registered one — and no host did, so a
 * fresh app mounted zero routes. Now, once the relying party is declared, a missing {@see SessionStore}
 * is provided: a {@see FileSessionStore} under `var/passkey/sessions.json`. A host that registers its own
 * store first keeps it. The same plugin registers {@see PasskeyGateMiddleware} under its class name, so
 * `admin.middleware => [PasskeyGateMiddleware::class]` is all a panel needs to name — identity lives
 * where the ceremony lives, and `milpa/admin` learns nothing about `milpa/auth`.
 *
 * THE CONVERGENCE (decisions/0125): the scopes a passkey is granted come from the SAME enrollment the
 * gpg-key path reads — `scopesFor` here IS {@see FileEnrollmentStore::scopesFor()}. A credential id
 * enrolled by `identity:enroll` is recognized exactly as a fingerprint is, so the CLI and WEB surfaces
 * meet in one identity model rather than two.
 *
 * Config (`config/app.php`):
 *
 *     'passkey' => [
 *         'rpId'     => 'example.com',                  // required — the relying-party id assertions bind to
 *         'cookie'   => 'milpa_session',                // optional — the session cookie name (the gate reads it)
 *         'ttl'      => 3600,                           // optional — session lifetime in seconds
 *         'sessions' => '/abs/path/sessions.json',      // optional — where the provided FileSessionStore writes
 *         'gate'     => ['scope' => 'milpa.admin'],     // optional — the ONE scope PasskeyGateMiddleware requires
 *     ],
 */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'Passkey',
    type: 'Web',
)]
final class PasskeyPlugin implements PluginInterface, RouteProviderInterface
{
    public const DEFAULT_COOKIE = 'milpa_session';

    public const DEFAULT_TTL = 3600;

    public const DEFAULT_GATE_SCOPE = 'milpa.admin';

    private ?string $rpId = null;

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function container(): DIContainerInterface
    {
        return $this->container;
    }

    /** Wire the passkey door from config, or leave it shut when no relying party was declared. */
    public function boot(): void
    {
        if (!class_exists(PasskeyLogin::class)) {
            return;
        }
        $config = $this->config();
        $rpId = $config['rpId'] ?? null;
        if (!\is_string($rpId) || $rpId === '') {
            return; // fail closed: no relying party, no routes (see the class docblock)
        }

        $root = $this->root();

        // The door provides what the door needs (decisions/0206): a host that registered no session
        // store gets a file-backed one, so a fresh app mounts the routes instead of silently none.
        if (!$this->container->has(SessionStore::class)) {
            $sessionsPath = \is_string($config['sessions'] ?? null) && $config['sessions'] !== ''
                ? $config['sessions']
                : $root . '/var/passkey/sessions.json';
            $this->container->registerService(SessionStore::class, new FileSessionStore($sessionsPath));
        }
        $sessions = $this->container->get(SessionStore::class);
        if (!$sessions instanceof SessionStore) {
            return; // fail closed: the host registered something under the store's name that is not one
        }

        $challenges = new FileChallengeStore($root . '/var/passkey/challenges.json');
        $credentialsPath = $root . '/var/passkey/credentials.json';
        $credentials = new FilePasskeyCredentialStore($credentialsPath);
        $registered = new RegisteredCredentialIds($credentialsPath);
        $authenticator = new PasskeyAuthenticator($challenges, $credentials);

        // THE CONVERGENCE: a passkey's scopes come from the same enrollment the gpg-key path reads.
        $enrollments = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $ttl = \is_int($config['ttl'] ?? null) && $config['ttl'] > 0 ? $config['ttl'] : self::DEFAULT_TTL;
        $login = new PasskeyLogin(
            $authenticator,
            $sessions,
            static fn (string $credentialId): ?array => $enrollments->scopesFor($credentialId),
            $ttl,
        );

        $cookie = \is_string($config['cookie'] ?? null) && $config['cookie'] !== '' ? $config['cookie'] : self::DEFAULT_COOKIE;
        $gate = \is_array($config['gate'] ?? null) ? $config['gate'] : [];
        $scope = \is_string($gate['scope'] ?? null) && $gate['scope'] !== '' ? $gate['scope'] : self::DEFAULT_GATE_SCOPE;
        $this->rpId = $rpId;
        $this->container->registerService(
            PasskeyController::class,
            new PasskeyController($authenticator, $login, $challenges, new WebAuthnRegistrationVerifier(), $credentials, $registered, $enrollments, $rpId, $cookie, $scope),
        );

        // THE GATE (decisions/0206): registered under its own class name so a panel can NAME it in
        // `admin.middleware` and the runtime's container resolver produces it — one session, one scope.
        // It reads the same enrollment ledger the login does, on every request: a revocation lands at once.
        $this->container->registerService(
            PasskeyGateMiddleware::class,
            new PasskeyGateMiddleware($sessions, $enrollments, $cookie, $scope),
        );

        // THE INTENT CEREMONY (greenhouse decisions/0187, D-01): the same authenticator, turned toward
        // authorising a concrete operation instead of minting a session. Its challenge→call binding is
        // persistent because the ceremony spans two requests (issue at the pause, admit at the touch).
        $intentChallenges = new FileIntentChallengeStore($root . '/var/passkey/intent-challenges.json');
        $this->container->registerService(
            PasskeyIntentController::class,
            new PasskeyIntentController(new PasskeyIntentAdmission($authenticator, $intentChallenges), $registered, $rpId),
        );
    }

    /** The login, sign-in, enrollment and intent routes, once booted — otherwise none. */
    public function routes(): array
    {
        if ($this->rpId === null) {
            return [];
        }

        return [
            new Route(path: '/webauthn/authenticate/options', methods: HttpMethod::POST, name: 'passkey.authenticate.options', handler: new HandlerReference(PasskeyController::class, 'options')),
            new Route(path: '/webauthn/authenticate', methods: HttpMethod::POST, name: 'passkey.authenticate', handler: new HandlerReference(PasskeyController::class, 'authenticate')),
            new Route(path: '/webauthn/signin', methods: HttpMethod::GET, name: 'passkey.signin.page', handler: new HandlerReference(PasskeyController::class, 'signinPage')),
            new Route(path: '/webauthn/register/options', methods: HttpMethod::POST, name: 'passkey.register.options', handler: new HandlerReference(PasskeyController::class, 'registerOptions')),
            new Route(path: '/webauthn/register', methods: HttpMethod::POST, name: 'passkey.register', handler: new HandlerReference(PasskeyController::class, 'register')),
            new Route(path: '/webauthn/enroll', methods: HttpMethod::GET, name: 'passkey.enroll.page', handler: new HandlerReference(PasskeyController::class, 'enrollPage')),
            new Route(path: '/webauthn/intent/options', methods: HttpMethod::POST, name: 'passkey.intent.options', handler: new HandlerReference(PasskeyIntentController::class, 'intentOptions')),
            new Route(path: '/webauthn/intent/admit', methods: HttpMethod::POST, name: 'passkey.intent.admit', handler: new HandlerReference(PasskeyIntentController::class, 'intentAdmit')),
            new Route(path: '/webauthn/intent', methods: HttpMethod::GET, name: 'passkey.intent.page', handler: new HandlerReference(PasskeyIntentController::class, 'page')),
        ];
    }

    /** No persistent state to create: the ledgers are the app's, under var/. */
    public function install(): void
    {
    }

    /** No persistent state to remove: the ledgers are the app's, under var/. */
    public function uninstall(): void
    {
    }

    /** Enabling is declaring it in config/plugins.php; the door itself is gated by `passkey.rpId`. */
    public function enable(): void
    {
    }

    /** Disabling removes it from config/plugins.php; nothing here to tear down. */
    public function disable(): void
    {
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $passkey = $config instanceof Config ? $config->get('passkey') : null;

        return \is_array($passkey) ? $passkey : [];
    }

    private function root(): string
    {
        $kernel = $this->container->has(\Milpa\Runtime\Kernel::class) ? $this->container->get(\Milpa\Runtime\Kernel::class) : null;

        return $kernel instanceof \Milpa\Runtime\Kernel ? $kernel->root() : (getcwd() ?: '.');
    }
}
