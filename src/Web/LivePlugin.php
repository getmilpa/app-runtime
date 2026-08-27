<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\AppRuntime\Web\Controllers\LiveAssetsController;
use Milpa\AppRuntime\Web\Controllers\LiveComponentPageController;
use Milpa\AppRuntime\Web\Controllers\LiveController;
use Milpa\Attributes\PluginMetadata;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Dashboard\DataTableComponent;
use Milpa\Live\Components\Dashboard\MetricCardComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Http\LiveBoot;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Security\ContractInteractionAuthorizer;
use Milpa\Live\Security\FileNonceStore;
use Milpa\Live\Security\HmacCsrfGuard;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Support\ClientRuntime;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * THE ONE DOOR of the live wire (greenhouse decisions/0083): mounts `POST /live` on the
 * {@see LiveEndpoint} and serves the three client files, built once from the app's own config.
 *
 * `milpa/live` and `milpa/live-web` ship every piece — the components, the endpoint, the signed
 * state codec, the CSRF guard, the nonce store, the renderers, the runtimes — and nothing mounted
 * them in a fresh app (evidence/0320, RED: `POST /live` 404). This plugin is the assembly, owned by
 * the runtime that already owns the app's identity ({@see \Milpa\AppRuntime\Auth\LivePrincipal}) and
 * its other web surface ({@see BoardPlugin}), so the loop closes the same way in every app instead
 * of being rebuilt by hand per host — the shape decisions/0059 chose for the gate.
 *
 * FAIL CLOSED WITHOUT A SECRET. The HMAC secret signs the state the client echoes back; an app that
 * has not set `live.secret` in `config/app.php` gets NO live routes and a boot-time notice, never a
 * generated or default secret: a secret nobody chose is a secret nobody can rotate.
 *
 * Config (`config/app.php`):
 *   'live' => [
 *       'secret'     => '…32+ random bytes…',   // required — signs state and CSRF tokens
 *       'route'      => '/live',                // optional
 *       'nonce_path' => 'var/live-nonces.json', // optional, relative to the app root
 *       'components' => [ 'data-table' => \Milpa\Live\Components\Dashboard\DataTableComponent::class, … ], // optional; the dashboard set by default
 *   ]
 */
#[PluginMetadata(version: '0.1.0', author: 'Rodrigo Vicente - TeamX Agency', site: 'https://teamx.agency', name: 'Live', type: 'Web')]
final class LivePlugin implements PluginInterface, RouteProviderInterface
{
    public const DEFAULT_ROUTE = '/live';

    public const DEFAULT_NONCE_PATH = 'var/live-nonces.json';

    /** The contract names {@see DashboardHtmlRenderer} renders — its own list is private, so the door names them once here. */
    private const DASHBOARD_CONTRACTS = [
        'dashboard-shell', 'dashboard-sidebar', 'dashboard-main', 'dashboard-topbar', 'dashboard-grid',
        'dashboard-panel', 'dashboard-page-header', 'dashboard-action-button', 'dashboard-alert-list',
        'metric-card', 'data-table',
    ];

    private ?string $route = null;

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function container(): DIContainerInterface
    {
        return $this->container;
    }

    /**
     * Builds the door once from config; registers the endpoint, its parts and the controllers in the
     * container so a page can issue its {@see LiveBoot} with the SAME codec and guard the endpoint
     * verifies against.
     */
    public function boot(): void
    {
        if (! class_exists(LiveEndpoint::class)) {
            return; // milpa/live-web is not installed: no live wire, and nothing pretends there is one
        }
        $live = $this->config();
        $secret = $live['secret'] ?? null;
        if (! \is_string($secret) || \strlen($secret) < 16) {
            return; // fail closed: no secret, no routes (see the class docblock)
        }
        $declaredRoute = $live['route'] ?? null;
        $route = \is_string($declaredRoute) && $declaredRoute !== '' ? $declaredRoute : self::DEFAULT_ROUTE;
        $noncePath = \is_string($live['nonce_path'] ?? null) ? $live['nonce_path'] : self::DEFAULT_NONCE_PATH;
        $noncePath = str_starts_with($noncePath, '/') ? $noncePath : $this->root() . '/' . $noncePath;
        @mkdir(\dirname($noncePath), 0o777, true);

        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($secret), new FileNonceStore($noncePath));
        $csrf = new HmacCsrfGuard($secret);
        $components = $this->components($live);
        $renderer = new DashboardHtmlRenderer(new AlpineRuntimeAdapter(), $codec);
        $renderers = [];
        $renderProps = [];
        foreach ($components->names() as $name) {
            if (\in_array($name, self::DASHBOARD_CONTRACTS, true)) {
                $renderers[$name] = $renderer;
                $renderProps[$name] = ['endpoint' => $route];
            }
        }
        $endpoint = new LiveEndpoint(
            components: $components->registry,
            codec: $codec,
            authorizer: new ContractInteractionAuthorizer($components->registry),
            csrf: $csrf,
            route: $route,
            renderers: $renderers,
            renderProps: $renderProps,
        );

        $this->container->registerService(StateTransferCodecInterface::class, $codec);
        $this->container->registerService(CsrfGuardInterface::class, $csrf);
        $this->container->registerService(ComponentRegistryInterface::class, $components->registry);
        $this->container->registerService(DashboardHtmlRenderer::class, $renderer);
        $this->container->registerService(LiveEndpoint::class, $endpoint);
        $this->container->registerService(LiveController::class, new LiveController($endpoint, (bool) ($live['anonymous'] ?? false)));
        $this->container->registerService(LiveAssetsController::class, new LiveAssetsController());

        // The shipped interactive render path: a page that carries ownership by construction (decisions/0092).
        // The app owns DATA via a registered LivePageProvider; the framework owns OWNERSHIP via LiveRender.
        $provider = $this->container->has(LivePageProvider::class) ? $this->container->get(LivePageProvider::class) : null;
        $this->container->registerService(
            LiveComponentPageController::class,
            new LiveComponentPageController(
                $components->registry,
                $renderer,
                $csrf,
                $route,
                $provider instanceof LivePageProvider ? $provider : null,
            ),
        );
        $this->route = $route;
    }

    /** Issues the boot a page embeds — the same guard the endpoint verifies, or null when the door is not mounted. */
    public function bootFor(?string $authorization = null): ?LiveBoot
    {
        if ($this->route === null || ! $this->container->has(CsrfGuardInterface::class)) {
            return null;
        }
        $csrf = $this->container->get(CsrfGuardInterface::class);

        return $csrf instanceof CsrfGuardInterface ? LiveBoot::issue($csrf, $this->route, $authorization) : null;
    }

    /**
     * The endpoint and the three client files — or nothing at all when the door is not mounted.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        if ($this->route === null) {
            return [];
        }
        $urls = ClientRuntime::defaultUrls();

        return [
            new Route(path: $this->route, methods: HttpMethod::POST, name: 'live', handler: new HandlerReference(LiveController::class, 'handle')),
            new Route(path: $this->route . '/page', methods: HttpMethod::GET, name: 'live.page', handler: new HandlerReference(LiveComponentPageController::class, 'show')),
            new Route(path: $urls[ClientRuntime::LOCAL], methods: HttpMethod::GET, name: 'live.runtime.local', handler: new HandlerReference(LiveAssetsController::class, 'local')),
            new Route(path: $urls[ClientRuntime::REMOTE], methods: HttpMethod::GET, name: 'live.runtime.remote', handler: new HandlerReference(LiveAssetsController::class, 'remote')),
            new Route(path: $urls[ClientRuntime::ALPINE], methods: HttpMethod::GET, name: 'live.runtime.alpine', handler: new HandlerReference(LiveAssetsController::class, 'alpine')),
        ];
    }

    /** Live keeps no persistent state of its own beyond the nonce file it creates on demand. */
    public function install(): void
    {
    }

    /** Nothing to remove: the nonce file is the app's, under var/. */
    public function uninstall(): void
    {
    }

    /** Enabling is declaring it in config/plugins.php; the door itself is gated by `live.secret`. */
    public function enable(): void
    {
    }

    /** Disabling removes the routes at the next boot; nothing else to undo. */
    public function disable(): void
    {
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $live = $config instanceof Config ? $config->get('live') : null;

        return \is_array($live) ? $live : [];
    }

    private function root(): string
    {
        $kernel = $this->container->has(\Milpa\Runtime\Kernel::class) ? $this->container->get(\Milpa\Runtime\Kernel::class) : null;

        return $kernel instanceof \Milpa\Runtime\Kernel ? $kernel->root() : (getcwd() ?: '.');
    }

    /**
     * The components this app serves live: the dashboard set by default, or exactly what
     * `live.components` names (name => class-string of a {@see ComponentDefinitionInterface}).
     *
     * @param array<string, mixed> $live
     */
    private function components(array $live): LiveComponents
    {
        $registry = new InMemoryComponentRegistry();
        $declared = \is_array($live['components'] ?? null) ? $live['components'] : [
            'data-table' => DataTableComponent::class,
            'metric-card' => MetricCardComponent::class,
        ];
        $names = [];
        foreach ($declared as $name => $class) {
            if (! \is_string($name) || ! \is_string($class) || ! class_exists($class)) {
                continue;
            }
            $component = new $class();
            if (! $component instanceof ComponentDefinitionInterface) {
                continue;
            }
            $registry->register($name, $component);
            $names[] = $name;
        }

        return new LiveComponents($registry, $names);
    }
}
