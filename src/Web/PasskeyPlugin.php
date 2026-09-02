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
use Milpa\Auth\Contracts\SessionStore;
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
 * FAIL CLOSED WITHOUT AN RP-ID AND A SESSION STORE. WebAuthn binds every assertion to a relying-party id;
 * an app that has not declared `passkey.rpId` in `config/app.php` gets NO passkey routes and a quiet
 * boot, never a guessed default — a relying party nobody chose is one nobody can trust. And with nowhere
 * to write a session ({@see SessionStore} absent from the container), a login could verify and then
 * vanish, so the door stays shut.
 *
 * THE CONVERGENCE (decisions/0125): the scopes a passkey is granted come from the SAME enrollment the
 * gpg-key path reads — `scopesFor` here IS {@see FileEnrollmentStore::scopesFor()}. A credential id
 * enrolled by `identity:enroll` is recognized exactly as a fingerprint is, so the CLI and WEB surfaces
 * meet in one identity model rather than two.
 *
 * Config (`config/app.php`):
 *
 *     'passkey' => [
 *         'rpId'   => 'example.com',       // required — the relying-party id assertions bind to
 *         'cookie' => 'milpa_session',     // optional — the session cookie name (StartSession reads it)
 *     ],
 */
final class PasskeyPlugin implements PluginInterface, RouteProviderInterface
{
    public const DEFAULT_COOKIE = 'milpa_session';

    private ?string $rpId = null;

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function container(): DIContainerInterface
    {
        return $this->container;
    }

    /** Wire the passkey door from config, or leave it shut when the relying party or session store is missing. */
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
        if (!$this->container->has(SessionStore::class)) {
            return; // fail closed: nowhere to mint a session
        }
        $sessions = $this->container->get(SessionStore::class);
        if (!$sessions instanceof SessionStore) {
            return;
        }

        $root = $this->root();
        $challenges = new FileChallengeStore($root . '/var/passkey/challenges.json');
        $credentials = new FilePasskeyCredentialStore($root . '/var/passkey/credentials.json');
        $authenticator = new PasskeyAuthenticator($challenges, $credentials);

        // THE CONVERGENCE: a passkey's scopes come from the same enrollment the gpg-key path reads.
        $enrollments = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $login = new PasskeyLogin(
            $authenticator,
            $sessions,
            static fn (string $credentialId): ?array => $enrollments->scopesFor($credentialId),
        );

        $cookie = \is_string($config['cookie'] ?? null) && $config['cookie'] !== '' ? $config['cookie'] : self::DEFAULT_COOKIE;
        $this->rpId = $rpId;
        $this->container->registerService(
            PasskeyController::class,
            new PasskeyController($authenticator, $login, $challenges, new WebAuthnRegistrationVerifier(), $credentials, $rpId, $cookie),
        );

        // THE INTENT CEREMONY (greenhouse decisions/0187, D-01): the same authenticator, turned toward
        // authorising a concrete operation instead of minting a session. Its challenge→call binding is
        // persistent because the ceremony spans two requests (issue at the pause, admit at the touch).
        $intentChallenges = new FileIntentChallengeStore($root . '/var/passkey/intent-challenges.json');
        $this->container->registerService(
            PasskeyIntentController::class,
            new PasskeyIntentController(new PasskeyIntentAdmission($authenticator, $intentChallenges), $rpId),
        );
    }

    /** The two authentication routes, once booted — otherwise none. */
    public function routes(): array
    {
        if ($this->rpId === null) {
            return [];
        }

        return [
            new Route(path: '/webauthn/authenticate/options', methods: HttpMethod::POST, name: 'passkey.authenticate.options', handler: new HandlerReference(PasskeyController::class, 'options')),
            new Route(path: '/webauthn/authenticate', methods: HttpMethod::POST, name: 'passkey.authenticate', handler: new HandlerReference(PasskeyController::class, 'authenticate')),
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
