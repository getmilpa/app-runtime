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
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Container\DIContainer;
use Milpa\Http\Routing\Route;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/** The passkey door is a declared surface that fails closed: no relying party, or nowhere to mint a session, means no routes. */
final class PasskeyPluginTest extends TestCase
{
    public function testItFailsClosedWithoutARelyingParty(): void
    {
        // A container with a session store but no passkey.rpId in config.
        $plugin = new PasskeyPlugin($this->container(rpId: null, withSessions: true));
        $plugin->boot();

        self::assertSame([], $plugin->routes(), 'no relying party, no routes');
    }

    public function testItFailsClosedWithoutASessionStore(): void
    {
        $plugin = new PasskeyPlugin($this->container(rpId: 'milpa.local', withSessions: false));
        $plugin->boot();

        self::assertSame([], $plugin->routes(), 'nowhere to mint a session, no routes');
    }

    public function testItMountsTheLoginAndIntentRoutesAndControllersWhenConfigured(): void
    {
        $container = $this->container(rpId: 'milpa.local', withSessions: true);
        $plugin = new PasskeyPlugin($container);
        $plugin->boot();

        $routes = $plugin->routes();
        self::assertCount(8, $routes);
        $paths = array_map(static fn (Route $r): string => $r->path, $routes);
        // Login + enrollment ceremony.
        self::assertContains('/webauthn/authenticate/options', $paths);
        self::assertContains('/webauthn/authenticate', $paths);
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

    private function container(?string $rpId, bool $withSessions): DIContainer
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config($rpId === null ? [] : ['passkey' => ['rpId' => $rpId]]));

        $root = sys_get_temp_dir() . '/milpa-pkp-' . bin2hex(random_bytes(4));
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

        return $c;
    }
}
