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
use Milpa\Live\Components\Autocomplete\AutocompleteComponent;
use Milpa\Live\Components\Form\CheckboxComponent;
use Milpa\Live\Components\Form\InputComponent;
use Milpa\Live\Components\Form\SelectComponent;
use Milpa\Live\Components\Form\TextareaComponent;
use Milpa\Live\Components\Dashboard\DashboardGridComponent;
use Milpa\Live\Components\Dashboard\DataTableComponent;
use Milpa\Live\Components\StateMachineComponent;
use Milpa\Live\Components\Dashboard\MetricCardComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Data\DataSourceRegistryInterface;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Http\LiveBoot;
use Milpa\AppRuntime\Live\PresentationOverrideStore;
use Milpa\AppRuntime\Operations\PresentationOverrideOperations;
use Milpa\Live\Assets\ComponentAssetOrchestrator;
use Milpa\Live\Assets\ComponentMessages;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Rendering\AutocompleteHtmlRenderer;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\Rendering\FormPrimitiveHtmlRenderer;
use Milpa\Live\Rendering\StateMachineHtmlRenderer;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Security\ContractInteractionAuthorizer;
use Milpa\Live\Security\FileNonceStore;
use Milpa\Live\Security\HmacCsrfGuard;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Support\ClientRuntime;
use Milpa\Live\Support\DesignTokens;
use Milpa\Live\Support\ComponentStyles;
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
     * Default component definitions shipped with the live door. These populate the same mutable registry
     * an app extends with constructed objects; they are not an exclusive list of screen types (0327).
     * The built-in autocomplete uses inline options collected from stored declarations (0165).
     */
    private const DECLARABLE_TYPES = [
        'data-table' => DataTableComponent::class,
        'metric-card' => MetricCardComponent::class,
        'state-machine' => StateMachineComponent::class,
        'autocomplete' => AutocompleteComponent::class,
        'input' => InputComponent::class,
        'textarea' => TextareaComponent::class,
        'select' => SelectComponent::class,
        'checkbox' => CheckboxComponent::class,
        'dashboard-grid' => DashboardGridComponent::class,
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
        $autocomplete = new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), $codec);
        $form = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), $codec);
        // `StateMachineHtmlRenderer` ships in milpa/live-web only from the version that added it (decisions/0164);
        // guard its use so a newer app-runtime still BOOTS against an older live-web instead of a hard fatal —
        // graceful degradation, a state-machine screen simply has no renderer there. The dashboard, autocomplete
        // and form renderers predate it, so they are unconditional.
        $stateMachine = class_exists(StateMachineHtmlRenderer::class)
            ? new StateMachineHtmlRenderer(new AlpineRuntimeAdapter(), $codec)
            : null;
        // Dispatch to a renderer by the component's contract type (greenhouse decisions/0164): the shipped
        // renderers are each single-family and throw for the rest, so the page controller cannot hold one for
        // every component. The endpoint re-renders after an action by the SAME key — the state snapshot's
        // componentName IS the contract name — so both are wired from one pass: a new component type is served
        // on GET and round-trips faithfully on POST.
        $byContract = [
            'autocomplete' => $autocomplete,
            'input' => $form, 'textarea' => $form, 'select' => $form, 'checkbox' => $form,
        ];
        if ($stateMachine !== null) {
            $byContract['state-machine'] = $stateMachine;
        }
        $registeredRenderers = $this->container->has(ComponentRendererRegistry::class)
            ? $this->container->get(ComponentRendererRegistry::class)
            : new ComponentRendererRegistry();
        if (! $registeredRenderers instanceof ComponentRendererRegistry) {
            throw new \LogicException('LivePlugin requires the per-component renderer registry');
        }
        $renderProps = [];
        foreach (self::DASHBOARD_CONTRACTS as $contract) {
            $byContract[$contract] ??= $dashboard;
        }
        foreach ($byContract as $contract => $renderer) {
            if ($registeredRenderers->resolveFor($contract, RenderTarget::HTML) === null) {
                $registeredRenderers->registerFor($contract, $renderer);
            }
            $renderProps[$contract] = ['endpoint' => $route];
        }
        if (! $this->container->has(ComponentRendererRegistry::class)) {
            $this->container->registerService(ComponentRendererRegistry::class, $registeredRenderers);
        }
        $screens = new ScreenComponents($components->registry, $registeredRenderers, $this->screenStore());
        $this->container->registerService(ScreenComponents::class, $screens);
        $pageRenderer = new CompositeHtmlRenderer(
            new RegisteredHtmlRenderer($registeredRenderers),
            fn (string $type, array $props): ?ComponentDefinitionInterface => $type === 'autocomplete'
                && $components->registry->has($type)
                && $components->registry->get($type)::class === AutocompleteComponent::class
                ? $this->autocompleteFor($props)
                : ($components->registry->has($type) ? $components->registry->get($type) : null),
        );
        $endpoint = new LiveEndpoint(
            components: $screens,
            codec: $codec,
            authorizer: new ContractInteractionAuthorizer($components->registry),
            csrf: $csrf,
            route: $route,
            renderers: $registeredRenderers,
            renderProps: $renderProps,
        );

        $this->container->registerService(StateTransferCodecInterface::class, $codec);
        $this->container->registerService(CsrfGuardInterface::class, $csrf);
        if (! $this->container->has(ComponentRegistryInterface::class)) {
            $this->container->registerService(ComponentRegistryInterface::class, $screens);
        }
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
                $screens,
                $pageRenderer,
                $csrf,
                $route,
                $provider,
                $this->layoutStateStore(),
                // The language this house's live pages are read in. Declared, not sniffed from the
                // request: a house serves the language it chose, and a component that has not been
                // translated into it falls back per key rather than per page.
                \is_string($this->config()['locale'] ?? null) ? (string) $this->config()['locale'] : ComponentMessages::DEFAULT_LOCALE,
                new ComponentAssetOrchestrator(overrides: $this->overrideStore()),
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

        $routes = [
            new Route(path: $this->route, methods: HttpMethod::POST, name: 'live', handler: new HandlerReference(LiveController::class, 'handle')),
            new Route(path: $this->route . '/page', methods: HttpMethod::GET, name: 'live.page', handler: new HandlerReference(LiveComponentPageController::class, 'show')),
            new Route(path: $urls[ClientRuntime::LOCAL], methods: HttpMethod::GET, name: 'live.runtime.local', handler: new HandlerReference(LiveAssetsController::class, 'local')),
            new Route(path: $urls[ClientRuntime::REMOTE], methods: HttpMethod::GET, name: 'live.runtime.remote', handler: new HandlerReference(LiveAssetsController::class, 'remote')),
            new Route(path: $urls[ClientRuntime::ALPINE], methods: HttpMethod::GET, name: 'live.runtime.alpine', handler: new HandlerReference(LiveAssetsController::class, 'alpine')),
        ];
        $design = DesignTokens::urls($this->route . '/assets');
        $design[ComponentStyles::FILE] = ComponentStyles::url($this->route . '/assets');
        foreach ($design as $name => $url) {
            $routes[] = new Route(path: $url, methods: HttpMethod::GET, name: 'live.design.' . $name, handler: new HandlerReference(LiveAssetsController::class, 'design'));
        }

        return $routes;
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
        return \Milpa\AppRuntime\Support\AppRoot::of($this->container, 'LivePlugin');
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
        return [
            ...(new ScreenOperations(
                $this->screenStore(),
                array_keys(self::DECLARABLE_TYPES),
                $this->layoutStateStore(),
                fn (): ?ScreenComponents => $this->container->has(ScreenComponents::class) ? $this->container->get(ScreenComponents::class) : null,
            ))->operations(),
            ...(new PresentationOverrideOperations($this->overrideStore()))->operations(),
        ];
    }

    /**
     * The record of who was allowed to change what somebody else's component looks like.
     *
     * Wired into the orchestrator, so the transport asks the LEDGER and never a declaration: a
     * package that could restyle another's component by declaring it would have done the effect
     * before anybody was asked (greenhouse decisions/0246 §2).
     */
    private function overrideStore(): PresentationOverrideStore
    {
        return PresentationOverrideStore::fromConfig($this->config(), $this->root());
    }

    private function screenStore(): ScreenStore
    {
        return ScreenStore::fromConfig($this->config(), $this->root());
    }

    private function layoutStateStore(): LayoutStateStore
    {
        return LayoutStateStore::fromConfig($this->config(), $this->root());
    }

    /**
     * The components this app serves live: the dashboard set by default, or exactly what
     * `live.components` names (name => class-string of a {@see ComponentDefinitionInterface}).
     *
     * @param array<string, mixed> $live
     */
    private function components(array $live): LiveComponents
    {
        $registry = $this->container->has(ComponentRegistryInterface::class)
            ? $this->container->get(ComponentRegistryInterface::class)
            : new InMemoryComponentRegistry();
        if (! $registry instanceof ComponentRegistryInterface) {
            throw new \LogicException('LivePlugin requires a component registry');
        }
        $declared = array_merge(self::DECLARABLE_TYPES, \is_array($live['components'] ?? null) ? $live['components'] : [
            'data-table' => DataTableComponent::class,
            'metric-card' => MetricCardComponent::class,
            'state-machine' => StateMachineComponent::class,
        ]);
        // Screen aliases resolve lazily through ScreenComponents. The built-in autocomplete still needs
        // every stored inline source at boot so its action can resolve the source by name (0165).
        $store = ScreenStore::fromConfig($live, $this->root());
        $sources = new InMemoryDataSourceRegistry();
        foreach ($store->typedNames() as $screen => $type) {
            $props = \is_array($store->screen($screen)['props'] ?? null) ? $store->screen($screen)['props'] : [];
            $this->collectSources($type, $props, $sources);
        }
        $names = [];
        foreach ($declared as $name => $class) {
            if (! \is_string($name) || ! \is_string($class) || ! class_exists($class)) {
                continue;
            }
            $component = $registry->has($name) ? $registry->get($name) : $this->instantiate($class, $sources);
            if ($component === null) {
                continue;
            }
            $registry->register($name, $component);
            $names[] = $name;
        }
        return new LiveComponents($registry, $names);
    }

    /**
     * Build one component from its class (greenhouse decisions/0165). Most types are default-constructable — a
     * declaration gives them DATA, not collaborators. `autocomplete` is the exception the pair closed on: it
     * needs a data source registry, so it is built with the SHARED `$sources` (holding every declared
     * autocomplete's inline options as {@see ArrayDataSource}s) — the data source IS the declaration, no server
     * wiring. A type that still cannot be built is SKIPPED, never fatal: one un-constructable component must not
     * take down every live page.
     */
    private function instantiate(string $class, DataSourceRegistryInterface $sources): ?ComponentDefinitionInterface
    {
        try {
            $component = $class === AutocompleteComponent::class ? new AutocompleteComponent($sources) : new $class();
        } catch (\Throwable) {
            return null;
        }

        return $component instanceof ComponentDefinitionInterface ? $component : null;
    }

    /**
     * Collect inline autocomplete options recursively for the existing data-source contract (0165).
     * Component discovery uses the live registry, independently of these stored data sources.
     *
     * @param array<string, mixed> $props
     */
    private function collectSources(string $type, array $props, InMemoryDataSourceRegistry $sources): void
    {
        $class = self::DECLARABLE_TYPES[$type] ?? null;
        if ($class === AutocompleteComponent::class) {
            $source = \is_string($props['source'] ?? null) ? $props['source'] : '';
            $options = \is_array($props['options'] ?? null) ? array_values(array_filter($props['options'], 'is_array')) : [];
            if ($source !== '') {
                $sources->register(new ArrayDataSource($source, $options));
            }
        }
        $children = \is_array($props['children'] ?? null) ? $props['children'] : [];
        foreach ($children as $child) {
            if (\is_array($child)) {
                $childType = \is_string($child['type'] ?? null) ? $child['type'] : '';
                $childProps = \is_array($child['props'] ?? null) ? $child['props'] : [];
                $this->collectSources($childType, $childProps, $sources);
            }
        }
    }

    /**
     * Build the shipped autocomplete child's inline data source from this declaration (0167).
     * App-supplied definitions resolve directly from the registry and never enter this factory.
     *
     * @param array<string, mixed> $props
     */
    private function autocompleteFor(array $props): AutocompleteComponent
    {
        $sources = new InMemoryDataSourceRegistry();
        $this->collectSources('autocomplete', $props, $sources);

        return new AutocompleteComponent($sources);
    }
}
