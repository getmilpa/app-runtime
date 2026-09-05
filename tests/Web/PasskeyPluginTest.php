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
use Milpa\AppRuntime\Web\PasskeyGateMiddleware;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Auth\ActorType;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\FileSessionStore;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\SessionRecord;
use Milpa\Auth\WebAuthn\FilePasskeyCredentialStore;
use Milpa\Auth\WebAuthn\RegisteredCredential;
use Milpa\Container\DIContainer;
use Milpa\Http\Routing\Route;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The passkey door is a declared surface that fails closed on the relying party — and PROVIDES what it
 * needs otherwise (greenhouse decisions/0206): a session store when the host registered none, the gate
 * middleware under its class name, the config knobs reaching the ceremony.
 */
final class PasskeyPluginTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmdir($root);
        }
    }

    /** A house that declares the plugin without the package it is made of hears it at boot, not as a mute 500 (greenhouse evidence/0519). */
    public function testItRefusesToBootWithoutMilpaAuthAndNamesTheFix(): void
    {
        [$container] = $this->container(rpId: 'milpa.local', withSessions: true);
        $plugin = new PasskeyPlugin($container, authInstalled: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require milpa/auth');
        $plugin->boot();
    }

    public function testItFailsClosedWithoutARelyingParty(): void
    {
        // A container with a session store but no passkey.rpId in config.
        [$container] = $this->container(rpId: null, withSessions: true);
        $plugin = new PasskeyPlugin($container);
        $plugin->boot();

        self::assertSame([], $plugin->routes(), 'no relying party, no routes');
        self::assertFalse($container->has(PasskeyGateMiddleware::class), 'a shut door registers no gate');
    }

    public function testItProvidesAFileSessionStoreWhenTheHostRegisteredNone(): void
    {
        // The regression of decisions/0206: a fresh app registers no SessionStore, and the plugin used
        // to mount ZERO routes for it. Now the door provides the store it needs.
        [$container, $root] = $this->container(rpId: 'milpa.local', withSessions: false);
        $plugin = new PasskeyPlugin($container);
        $plugin->boot();

        self::assertCount(9, $plugin->routes(), 'the routes mount without a host-registered store');
        self::assertTrue($container->has(SessionStore::class));
        $store = $container->get(SessionStore::class);
        self::assertInstanceOf(FileSessionStore::class, $store);
        self::assertTrue($container->has(PasskeyGateMiddleware::class), 'the gate is registered under its class name');
        self::assertInstanceOf(PasskeyGateMiddleware::class, $container->get(PasskeyGateMiddleware::class));

        // And it writes where the docblock says: var/passkey/sessions.json under the app root.
        $store->write($this->record('s-1'));
        self::assertFileExists($root . '/var/passkey/sessions.json');
        self::assertNotNull($store->read('s-1'));
    }

    public function testAHostRegisteredSessionStoreIsKept(): void
    {
        [$container] = $this->container(rpId: 'milpa.local', withSessions: true);
        $own = $container->get(SessionStore::class);
        (new PasskeyPlugin($container))->boot();

        self::assertSame($own, $container->get(SessionStore::class), 'the host\'s store is not replaced');
    }

    public function testItFailsClosedWhenTheStoreNameHoldsSomethingElse(): void
    {
        [$container] = $this->container(rpId: 'milpa.local', withSessions: false);
        $container->registerService(SessionStore::class, new \stdClass());
        $plugin = new PasskeyPlugin($container);
        $plugin->boot();

        self::assertSame([], $plugin->routes(), 'not a store under the store\'s name: no routes');
    }

    public function testTheConfigKnobsReachTheStorePathTheGateScopeAndTheSessionTtl(): void
    {
        [$container, $root] = $this->container(rpId: 'milpa.local', withSessions: false);
        $sessionsPath = $root . '/elsewhere/sessions.json';
        // The full knob set, declared once the root is known (the sessions path points under it).
        $container->replaceService(Config::class, new Config(['passkey' => [
            'rpId' => 'milpa.local', 'sessions' => $sessionsPath, 'ttl' => 120, 'gate' => ['scope' => 'ops.panel'], 'cookie' => 'panel_session',
        ]]));
        (new PasskeyPlugin($container))->boot();

        $gate = $container->get(PasskeyGateMiddleware::class);
        self::assertInstanceOf(PasskeyGateMiddleware::class, $gate);
        self::assertSame('ops.panel', $gate->scope());
        self::assertSame('panel_session', $gate->cookieName());

        // The ttl reaches PasskeyLogin (it used to be unreachable): a real login mints a session that
        // expires exactly ttl seconds after it was created, in the store at the configured path.
        $key = SyntheticPasskey::key();
        (new FilePasskeyCredentialStore($root . '/var/passkey/credentials.json'))->register(new RegisteredCredential('cred-ttl', SyntheticPasskey::pem($key), 0));
        (new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))->record(new IdentityEnrolled('cred-ttl', ['ops.panel'], 'key:TEST'));
        $controller = $container->get(PasskeyController::class);
        self::assertInstanceOf(PasskeyController::class, $controller);
        $opt = json_decode((string) $controller->options(new ServerRequest('POST', '/webauthn/authenticate/options'))->getBody(), true);
        $res = $controller->authenticate(new ServerRequest('POST', '/webauthn/authenticate', [], (string) json_encode(
            SyntheticPasskey::assertion($key, 'milpa.local', SyntheticPasskey::unb64u($opt['challenge']), 'cred-ttl'),
        )));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringStartsWith('panel_session=', $res->getHeaderLine('Set-Cookie'));
        $id = explode(';', explode('=', $res->getHeaderLine('Set-Cookie'), 2)[1])[0];

        self::assertFileExists($sessionsPath, 'the store writes at passkey.sessions');
        $session = (new FileSessionStore($sessionsPath))->read($id);
        self::assertNotNull($session);
        self::assertSame(120, $session->expiresAt->getTimestamp() - $session->createdAt->getTimestamp(), 'passkey.ttl reached PasskeyLogin');
    }

    public function testItMountsTheLoginSignInAndIntentRoutesAndControllersWhenConfigured(): void
    {
        [$container] = $this->container(rpId: 'milpa.local', withSessions: true);
        $plugin = new PasskeyPlugin($container);
        $plugin->boot();

        $routes = $plugin->routes();
        self::assertCount(9, $routes);
        $paths = array_map(static fn (Route $r): string => $r->path, $routes);
        // Login + sign-in page + enrollment ceremony.
        self::assertContains('/webauthn/authenticate/options', $paths);
        self::assertContains('/webauthn/authenticate', $paths);
        self::assertContains('/webauthn/signin', $paths);
        self::assertContains('/webauthn/enroll', $paths);
        // Intent ceremony (greenhouse decisions/0187, D-01): challenge bound to a call, admit, page.
        self::assertContains('/webauthn/intent/options', $paths);
        self::assertContains('/webauthn/intent/admit', $paths);
        self::assertContains('/webauthn/intent', $paths);
        self::assertTrue($container->has(PasskeyController::class), 'the login controller is registered');
        self::assertTrue($container->has(\Milpa\AppRuntime\Web\Controllers\PasskeyIntentController::class), 'the intent controller is registered');
    }

    public function testItDeclaresPluginMetadataSoItCanBeListedInConfigPlugins(): void
    {
        // Kernel::boot() requires #[PluginMetadata] on every declared plugin; without it a config/plugins.php
        // that lists PasskeyPlugin throws AttributeNotFoundException at boot (greenhouse evidence/0489). The
        // other tests instantiate the plugin directly and so never exercised the real boot path that reads it.
        $attributes = (new \ReflectionClass(PasskeyPlugin::class))->getAttributes(\Milpa\Attributes\PluginMetadata::class);

        self::assertCount(1, $attributes, 'PasskeyPlugin must carry #[PluginMetadata] to be declarable in config/plugins.php');
        self::assertInstanceOf(\Milpa\Attributes\PluginMetadata::class, $attributes[0]->newInstance());
    }

    /** @return array{0: DIContainer, 1: string} */
    private function container(?string $rpId, bool $withSessions): array
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config($rpId === null ? [] : ['passkey' => ['rpId' => $rpId]]));

        $root = sys_get_temp_dir() . '/milpa-pkp-' . bin2hex(random_bytes(4));
        $this->roots[] = $root;
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setAccessible(true);
            $p->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);

        if ($withSessions) {
            $c->registerService(SessionStore::class, new InMemorySessionStore());
        }

        return [$c, $root];
    }

    private function record(string $id): SessionRecord
    {
        $now = new \DateTimeImmutable();

        return new SessionRecord($id, 'passkey:x', ActorType::User, $now, $now->add(new \DateInterval('PT1H')), ['milpa.admin']);
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
