<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\Controllers\LiveComponentPageController;
use Milpa\AppRuntime\Web\LivePageProvider;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Container\DIContainer;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Runtime\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The shipped interactive render path (greenhouse decisions/0092): `GET /live/page?component=<name>` renders
 * a live component bound to the request's verified actor — the state is born owned by `actor:<id>` without the
 * app touching the format — and answers 404 rather than paint a page with data no provider supplied.
 */
final class LiveComponentPageControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(LiveEndpoint::class)) {
            self::markTestSkipped('milpa/live-web is not installed');
        }
    }

    private function bootedController(?LivePageProvider $provider): LiveComponentPageController
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['live' => ['secret' => str_repeat('k', 32)]]));
        if ($provider !== null) {
            $c->registerService(LivePageProvider::class, $provider);
        }
        (new LivePlugin($c))->boot();

        return $c->get(LiveComponentPageController::class);
    }

    private function dataTableProvider(): LivePageProvider
    {
        return new class () implements LivePageProvider {
            public function propsFor(string $component, ServerRequestInterface $request): ?array
            {
                if ($component !== 'data-table') {
                    return null;
                }

                return [
                    'columns' => [['key' => 'n', 'label' => 'Nombre']],
                    'rows' => [['id' => 'a', 'n' => 'Acme']],
                ];
            }
        };
    }

    private function asActor(string $id): ServerRequestInterface
    {
        return (new ServerRequest('GET', '/live/page?component=data-table'))
            ->withQueryParams(['component' => 'data-table'])
            ->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::authenticated(new Actor($id, ActorType::Service, ['milpa:component:data-table:*'])));
    }

    public function testTheStateIsBornOwnedByTheRequestActor(): void
    {
        $response = $this->bootedController($this->dataTableProvider())->show($this->asActor('rod'));

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertMatchesRegularExpression('/<milpa-state/', $html, 'the signed state is embedded');

        preg_match('#>([A-Za-z0-9+/=]+)</milpa-state>#', $html, $m);
        $state = json_decode(base64_decode($m[1] ?? ''), true);
        self::assertSame('actor:rod', $state['meta']['principal'] ?? null, 'the owner is the verified actor, not a hand-written string');
        self::assertStringContainsString('milpa-live-boot', $html, 'the boot the endpoint verifies is embedded');
    }

    public function testAnAnonymousRequestRendersAnOwnerlessState(): void
    {
        $req = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'data-table']);
        $html = (string) $this->bootedController($this->dataTableProvider())->show($req)->getBody();
        preg_match('#>([A-Za-z0-9+/=]+)</milpa-state>#', $html, $m);
        $state = json_decode(base64_decode($m[1] ?? ''), true);
        self::assertNull($state['meta']['principal'] ?? null, 'no verified actor → ownerless state');
    }

    /**
     * The page carries what its component declared, so it never has to know what one is made of.
     *
     * `data-table` declares a message catalogue and no stylesheet, which is the shape that matters:
     * the page must emit the words WITHOUT emitting an empty `<style>` for a component that has no
     * look of its own (greenhouse decisions/0246).
     */
    public function testThePageCarriesTheWordsItsComponentDeclared(): void
    {
        $req = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'data-table']);
        $html = (string) $this->bootedController($this->dataTableProvider())->show($req)->getBody();

        self::assertStringContainsString('id="milpa-messages"', $html, 'the declared words never reached the page');
        self::assertStringContainsString('data-table.selected', $html, 'the words are namespaced by component');
        self::assertStringContainsString('"data-table.selected":"selected"', $html, 'English is the default');
        self::assertStringNotContainsString('data-milpa-assets="components"', $html, 'data-table declares no stylesheet and must not get an empty one');
    }

    /**
     * Control — the same page under `live.locale = es` carries Spanish, so the locale is read.
     *
     * Declared in config rather than sniffed from the request: a house serves the language it chose,
     * and a component untranslated into it falls back per key rather than leaving the page half-dead.
     */
    public function testTheSamePageUnderASpanishLocaleCarriesSpanish(): void
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['live' => ['secret' => str_repeat('k', 32), 'locale' => 'es']]));
        $c->registerService(LivePageProvider::class, $this->dataTableProvider());
        (new LivePlugin($c))->boot();

        $req = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'data-table']);
        $spanish = (string) $c->get(LiveComponentPageController::class)->show($req)->getBody();
        $english = (string) $this->bootedController($this->dataTableProvider())->show($req)->getBody();

        self::assertStringContainsString('"data-table.selected":"seleccionados"', $spanish);
        self::assertStringContainsString('"data-table.selected":"selected"', $english, 'undeclared stays English');
    }

    /**
     * F4 END TO END — the page changes because somebody authorized it, and only then.
     *
     * The two halves have to be asserted together. A page that never changes proves nothing about
     * governance, and a page that changes proves nothing about it either unless the SAME page, from
     * the SAME installed packages, was unchanged a moment before the grant. That gap is where the
     * defect would live: a package that could restyle another's component by declaring it would
     * already have done so by the time anybody was asked (greenhouse decisions/0246 §2).
     */
    public function testTheLiveseamHonoursAnOverrideOnlyAfterItIsGranted(): void
    {
        $root = sys_get_temp_dir() . '/milpa-live-override-' . bin2hex(random_bytes(6));
        mkdir($root . '/resources', 0o775, true);
        file_put_contents($root . '/resources/words.php', "<?php\n\nreturn ['en' => ['selected' => 'picked']];\n");

        $container = $this->containerRootedAt($root);
        (new LivePlugin($container))->boot();
        $request = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'data-table']);

        $before = (string) $container->get(LiveComponentPageController::class)->show($request)->getBody();
        self::assertStringContainsString('"data-table.selected":"selected"', $before, 'nothing is overridden before anybody said so');

        \Milpa\AppRuntime\Live\PresentationOverrideStore::fromConfig([], $root)
            ->grant('data-table', null, $root . '/resources/words.php', 'acme/theme', 'passkey:rod');

        $afterContainer = $this->containerRootedAt($root);
        (new LivePlugin($afterContainer))->boot();
        $after = (string) $afterContainer->get(LiveComponentPageController::class)->show($request)->getBody();

        self::assertStringContainsString('"data-table.selected":"picked"', $after, 'the authorized override never reached the page');
        self::assertStringNotContainsString('"data-table.selected":"selected"', $after);

        array_map('unlink', (array) glob($root . '/{,*/}*.{php,json}', \GLOB_BRACE));
        array_map('rmdir', [$root . '/resources', $root . '/var', $root]);
    }

    private function containerRootedAt(string $root): DIContainer
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['live' => ['secret' => str_repeat('k', 32)]]));
        $c->registerService(LivePageProvider::class, $this->dataTableProvider());

        $kernel = (new \ReflectionClass(\Milpa\Runtime\Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $property = new \ReflectionProperty(\Milpa\Runtime\Kernel::class, $name);
            $property->setAccessible(true);
            $property->setValue($kernel, $value);
        }
        $c->registerService(\Milpa\Runtime\Kernel::class, $kernel);

        return $c;
    }

    public function testThePageIsACompleteDocumentWithOneRuntimeAndLocalDesignAssets(): void
    {
        $response = $this->bootedController($this->dataTableProvider())->show($this->asActor('rod'));
        $html = (string) $response->getBody();
        self::assertStringStartsWith('<!doctype html><html lang="en"', $html);
        self::assertStringContainsString('name="viewport"', $html);
        self::assertStringContainsString('/live/assets/milpa-components.css', $html);
        self::assertStringContainsString('/live/assets/milpa-fonts.css', $html);
        self::assertStringContainsString('/live/assets/milpa-tokens.css', $html);
        self::assertStringContainsString('rel="icon"', $html);
        self::assertSame(1, substr_count($html, 'src="/milpa-live.js"'));
        self::assertSame(1, substr_count($html, 'src="/milpa-live-remote.js"'));
        self::assertSame(1, substr_count($html, 'src="/vendor/alpine.min.js"'));
        self::assertLessThan(strpos($html, 'src="/vendor/alpine.min.js"'), strpos($html, 'src="/milpa-live-remote.js"'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testNestedChildMessagesTravelToThePageAndInvalidStoredChildrenAre422(): void
    {
        $provider = new class () implements LivePageProvider {
            public array $children = [['type' => 'dashboard-grid', 'props' => ['children' => [['type' => 'data-table', 'props' => ['rows' => [], 'columns' => []]]]]]];
            public function propsFor(string $component, ServerRequestInterface $request): ?array
            {
                return ['children' => $this->children];
            }
        };
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['live' => ['secret' => str_repeat('k', 32), 'route' => '/ui', 'locale' => 'es', 'components' => ['screen' => \Milpa\Live\Components\Dashboard\DashboardGridComponent::class]]]));
        $c->registerService(LivePageProvider::class, $provider);
        (new LivePlugin($c))->boot();
        $controller = $c->get(LiveComponentPageController::class);
        $request = (new ServerRequest('GET', '/ui/page'))->withQueryParams(['component' => 'screen']);
        $html = (string) $controller->show($request)->getBody();
        self::assertStringContainsString('lang="es"', $html);
        self::assertStringContainsString('/ui/assets/milpa-components.css', $html);
        self::assertStringContainsString('data-table.selected', $html);
        self::assertStringContainsString('seleccionados', $html);
        $provider->children = [['type' => 'missing']];
        $refused = $controller->show($request);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('props.children.0.type', json_decode((string) $refused->getBody(), true)['path']);
    }

    public function testAnUnknownComponentIs404(): void
    {
        $req = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'nope']);
        self::assertSame(404, $this->bootedController($this->dataTableProvider())->show($req)->getStatusCode());
    }

    public function testNoProviderForTheComponentIs404NotAnInventedPage(): void
    {
        // provider present but declines 'metric-card' → 404, never a page with invented data
        $req = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'metric-card']);
        self::assertSame(404, $this->bootedController($this->dataTableProvider())->show($req)->getStatusCode());
    }
}
