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
use Milpa\Command\CommandProvider;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Dashboard\DataTableComponent;
use Milpa\Live\Components\StateMachineComponent;
use Milpa\Live\Components\Dashboard\MetricCardComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Http\LiveBoot;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\Rendering\StateMachineHtmlRenderer;
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
 *       'screens_path' => 'var/screens.json',  // optional; where `screen:declare` stores runtime-declared screens
 *   ]
 */
#[PluginMetadata(version: '0.1.0', author: 'Rodrigo Vicente - TeamX Agency', site: 'https://teamx.agency', name: 'Live', type: 'Web')]
final class LivePlugin implements PluginInterface, RouteProviderInterface, CommandProvider
{
    public const DEFAULT_ROUTE = '/live';

    public const DEFAULT_NONCE_PATH = 'var/live-nonces.json';

    /** The contract names {@see DashboardHtmlRenderer} renders — its own list is private, so the door names them once here. */
    private const DASHBOARD_CONTRACTS = [
        'dashboard-shell', 'dashboard-sidebar', 'dashboard-main', 'dashboard-topbar', 'dashboard-grid',
        'dashboard-panel', 'dashboard-page-header', 'dashboard-action-button', 'dashboard-alert-list',
        'metric-card', 'data-table',
    ];

    /**
     * The SDK component types a screen may be DECLARED as (greenhouse decisions/0163, 0164): a declaration
     * names one of these, and `LivePlugin` registers the screen under the mapped class. Two conditions gate a
     * type in here — measured, not assumed (evidence/0425, 0426):
     *   1. it is DEFAULT-CONSTRUCTABLE (a declaration supplies data, not collaborators), and
     *   2. a renderer the page controller dispatches to can render it AS A PAGE.
     * `data-table` and `metric-card` render via {@see DashboardHtmlRenderer}; `state-machine` via
     * {@see StateMachineHtmlRenderer}, dispatched by {@see DispatchingHtmlRenderer} (decisions/0164) — its
     * machine is declared by data (initial + transitions, decisions/0095).
     *
     * DELIBERATELY ABSENT: `autocomplete` needs a registered data source injected into its constructor, which
     * a declaration cannot provide — authoring one is a separate slice (declare the data source too).
     */
    private const DECLARABLE_TYPES = [
        'data-table' => DataTableComponent::class,
        'metric-card' => MetricCardComponent::class,
        'state-machine' => StateMachineComponent::class,
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
        $dashboard = new DashboardHtmlRenderer(new AlpineRuntimeAdapter(), $codec);
        $stateMachine = new StateMachineHtmlRenderer(new AlpineRuntimeAdapter(), $codec);
        // Dispatch to a renderer by the component's contract type (greenhouse decisions/0164): the shipped
        // renderers are each single-family and throw for the rest, so the page controller cannot hold one for
        // every component. The endpoint re-renders after an action by the SAME key — the state snapshot's
        // componentName IS the contract name — so both are wired from one pass: a new component type is served
        // on GET and round-trips faithfully on POST.
        $pageRenderer = new DispatchingHtmlRenderer(['state-machine' => $stateMachine], $dashboard);
        $renderers = [];
        $renderProps = [];
        foreach ($components->names() as $name) {
            if (! $components->registry->has($name)) {
                continue;
            }
            $contract = $components->registry->get($name)::contract()->name;
            $renderer = $contract === 'state-machine'
                ? $stateMachine
                : (\in_array($contract, self::DASHBOARD_CONTRACTS, true) ? $dashboard : null);
            if ($renderer !== null) {
                $renderers[$contract] = $renderer;
                $renderProps[$contract] = ['endpoint' => $route];
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
        $this->container->registerService(DashboardHtmlRenderer::class, $dashboard);
        $this->container->registerService(LiveEndpoint::class, $endpoint);
        $this->container->registerService(LiveController::class, new LiveController($endpoint, (bool) ($live['anonymous'] ?? false)));
        $this->container->registerService(LiveAssetsController::class, new LiveAssetsController());

        // The shipped interactive render path: a page that carries ownership by construction (decisions/0092).
        // The app owns DATA via a registered LivePageProvider; the framework owns OWNERSHIP via LiveRender.
        $appProvider = $this->container->has(LivePageProvider::class) ? $this->container->get(LivePageProvider::class) : null;
        $declaredScreens = new DeclaredScreensPageProvider(ScreenStore::fromConfig($live, $this->root()));
        // The runtime always serves the screens the agent declared at runtime (decisions/0158). When the app
        // owns no provider, that IS the provider (registered, so `screen:declare`'d screens are served with
        // zero wiring). When the app DID register one, the container forbids replacing it (decisions/0157), so
        // the two are chained INTO the page controller — the plugin's own fresh service — instead: the app's
        // provider first, the declared screens filling in only what it declines (decisions/0159, the chain).
        if ($appProvider instanceof LivePageProvider) {
            $provider = new ChainedLivePageProvider($appProvider, $declaredScreens);
        } else {
            $provider = $declaredScreens;
            $this->container->registerService(LivePageProvider::class, $declaredScreens);
        }
        $this->container->registerService(
            LiveComponentPageController::class,
            new LiveComponentPageController(
                $components->registry,
                $pageRenderer,
                $csrf,
                $route,
                $provider,
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
     * The operations the live door contributes to the command table (greenhouse decisions/0158):
     * `screen:declare`, the primitive that lets the agent author a live screen MATERIALLY. Because
     * LivePlugin is a booted {@see CommandProvider}, enabling the door in `config/plugins.php` enables
     * the author-material loop too — the operation, the component registration and the store-backed
     * serving arrive as ONE capability, with no per-app wiring.
     *
     * @return list<\Milpa\Command\Operation>
     */
    public function operations(): array
    {
        return (new ScreenOperations($this->screenStore(), array_keys(self::DECLARABLE_TYPES)))->operations();
    }

    private function screenStore(): ScreenStore
    {
        return ScreenStore::fromConfig($this->config(), $this->root());
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
            'state-machine' => StateMachineComponent::class,
        ];
        // Runtime-declared screens (greenhouse decisions/0158, typed in 0163): every screen the agent authored
        // through `screen:declare` is registered under the class for ITS component type — data-table,
        // state-machine, metric-card, autocomplete — so the live door serves it with no code deploy. A
        // configured component of the same name wins (the app's declaration is explicit); an unknown type is
        // skipped rather than registered against a class that does not exist.
        foreach (ScreenStore::fromConfig($live, $this->root())->typedNames() as $screen => $type) {
            $class = self::DECLARABLE_TYPES[$type] ?? null;
            if ($class !== null) {
                $declared[$screen] ??= $class;
            }
        }
        $names = [];
        foreach ($declared as $name => $class) {
            if (! \is_string($name) || ! \is_string($class) || ! class_exists($class)) {
                continue;
            }
            // A component that cannot be default-constructed (one that needs a collaborator injected, e.g. a
            // data source) is SKIPPED, never fatal: one un-constructable type must not take down every live
            // page. That is also why such types are not offered as declarable (decisions/0163).
            try {
                $component = new $class();
            } catch (\Throwable) {
                continue;
            }
            if (! $component instanceof ComponentDefinitionInterface) {
                continue;
            }
            $registry->register($name, $component);
            $names[] = $name;
        }

        return new LiveComponents($registry, $names);
    }
}
