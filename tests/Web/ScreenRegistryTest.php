<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\AppRuntime\Web\ScreenComponents;
use Milpa\AppRuntime\Web\Controllers\LiveComponentPageController;
use Milpa\Container\DIContainer;
use Milpa\Live\Components\Dashboard\AbstractDashboardComponent;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\ValueObjects\{ComponentContract, RenderTarget, RenderRequest, RenderResult};
use Milpa\Runtime\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ScreenRegistryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/screen-registry-' . bin2hex(random_bytes(8)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function container(): DIContainer
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['live' => ['secret' => str_repeat('a', 32), 'screens_path' => $this->file]]));

        return $c;
    }

    public function testLateRegistrationReachesAlreadyCreatedOperationsAndRootAndNestedPages(): void
    {
        $c = $this->container();
        $plugin = new LivePlugin($c);
        $plugin->boot();
        $ops = [];
        foreach ($plugin->operations() as $op) {
            $ops[$op->name] = $op;
        }
        self::assertArrayNotHasKey('enum', $ops['screen:declare']->inputSchema['properties']['type']);
        self::assertSame('screen:types', $ops['screen:declare']->inputSchema['properties']['type']['x-milpa-source']['tool']);
        $component = new RegisteredTask('collaborator supplied by the plugin');
        $registry = $c->get(ComponentRegistryInterface::class);
        $registry->register('registered-task', $component);
        self::assertNotContains('registered-task', array_column(($ops['screen:types']->handler)([])['types'], 'name'));
        $c->get(ComponentRendererRegistry::class)->registerFor('registered-task', new RegisteredTaskRenderer());
        self::assertContains('registered-task', array_column(($ops['screen:types']->handler)([])['types'], 'name'));
        foreach ([['name' => 'task', 'type' => 'registered-task'], ['name' => 'tasks', 'type' => 'dashboard-grid', 'props' => ['children' => [['type' => 'registered-task']]]]] as $input) {
            self::assertTrue(($ops['screen:declare']->handler)($input)['ok']);
            $request = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => $input['name']]);
            $response = $c->get(LiveComponentPageController::class)->show($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('collaborator supplied by the plugin', (string) $response->getBody());
        }
        self::assertSame($component, $c->get(ScreenComponents::class)->get('task'));
        self::assertContains('task', $c->get(ScreenComponents::class)->names());
    }

    public function testMissingRendererAliasAndNameCollisionAreRefusedWithoutReplacingTheScreen(): void
    {
        $c = $this->container();
        $plugin = new LivePlugin($c);
        $plugin->boot();
        $ops = [];
        foreach ($plugin->operations() as $op) {
            $ops[$op->name] = $op;
        }
        $declare = $ops['screen:declare']->handler;
        self::assertTrue($declare(['name' => 'kept'])['ok']);
        $before = file_get_contents($this->file);
        $registry = $c->get(ComponentRegistryInterface::class);
        $registry->register('registered-task', new RegisteredTask('first'));
        $registry->register('alias', new RegisteredTask('second'));
        foreach (['registered-task', 'alias', 'missing'] as $type) {
            self::assertFalse($declare(['name' => 'kept', 'type' => $type])['ok']);
            self::assertSame($before, file_get_contents($this->file));
        }
        self::assertFalse($declare(['name' => 'data-table', 'type' => 'input'])['ok']);
        self::assertFalse($declare(['name' => ' data-table ', 'type' => 'input'])['ok']);
        self::assertSame($before, file_get_contents($this->file));
        self::assertCount(2, ($ops['screen:types']->handler)([])['unavailable']);
        self::assertFalse($c->get(ScreenComponents::class)->has('missing'));
        $this->expectException(\RuntimeException::class);
        $c->get(ScreenComponents::class)->get('missing');
    }

    public function testPrewiredRegistriesArePreserved(): void
    {
        $c = $this->container();
        $registry = new InMemoryComponentRegistry();
        $component = new RegisteredTask('prewired');
        $registry->register('registered-task', $component);
        $renderers = new ComponentRendererRegistry();
        $renderers->registerFor('registered-task', new RegisteredTaskRenderer());
        $c->registerService(ComponentRegistryInterface::class, $registry);
        $c->registerService(ComponentRendererRegistry::class, $renderers);
        (new LivePlugin($c))->boot();
        self::assertSame($registry, $c->get(ComponentRegistryInterface::class));
        self::assertSame($renderers, $c->get(ComponentRendererRegistry::class));
        self::assertSame($component, $c->get(ScreenComponents::class)->get('registered-task'));
    }

    public function testRendererAssetsSurviveNestedCompositionAndMissingRendererRefusesLegacyData(): void
    {
        $c = $this->container();
        $plugin = new LivePlugin($c);
        $plugin->boot();
        $c->get(ComponentRegistryInterface::class)->register('registered-task', new RegisteredTask('custom'));
        $renderers = $c->get(ComponentRendererRegistry::class);
        $store = new \Milpa\AppRuntime\Web\ScreenStore($this->file);
        $store->declare(['name' => 'custom', 'type' => 'registered-task']);
        $request = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'custom']);
        self::assertSame(422, $c->get(LiveComponentPageController::class)->show($request)->getStatusCode());
        $store->declare(['name' => 'custom', 'type' => 'dashboard-grid', 'props' => ['children' => [
            ['type' => 'registered-task'], ['type' => 'registered-task'],
        ]]]);
        $invalid = $c->get(LiveComponentPageController::class)->show($request);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('props.children.0.type', json_decode((string) $invalid->getBody(), true)['path']);
        $renderers->registerFor('registered-task', new AssetTaskRenderer());
        $response = $c->get(LiveComponentPageController::class)->show($request);
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertSame(1, substr_count($html, 'src="/custom-task.js"'));
        self::assertSame(1, substr_count($html, 'href="/custom-task.css"'));
        self::assertSame(1, substr_count($html, 'href="/render-result.css"'));
        self::assertTrue((new \Milpa\AppRuntime\Web\RegisteredHtmlRenderer($renderers))->supportsTarget(RenderTarget::HTML));
    }

    public function testBuiltInAutocompleteStillMountsItsInlineSourceWhenNested(): void
    {
        $c = $this->container();
        $plugin = new LivePlugin($c);
        $plugin->boot();
        $store = new \Milpa\AppRuntime\Web\ScreenStore($this->file);
        $store->declare(['name' => 'search', 'type' => 'dashboard-grid', 'props' => ['children' => [[
            'type' => 'autocomplete', 'props' => ['source' => 'tasks', 'options' => [['id' => 'one', 'label' => 'One']]],
        ]]]]);
        $response = $c->get(LiveComponentPageController::class)->show((new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'search']));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-milpa-state="search-0"', (string) $response->getBody());
        self::assertStringContainsString('milpaAutocomplete', (string) $response->getBody());
    }

    public function testAnOperationCollectedBeforeBootRefusesUntilTheLiveDoorExists(): void
    {
        $c = $this->container();
        $plugin = new LivePlugin($c);
        $ops = [];
        foreach ($plugin->operations() as $op) {
            $ops[$op->name] = $op;
        }
        self::assertFalse(($ops['screen:declare']->handler)(['name' => 'task'])['ok']);
        self::assertSame([], ($ops['screen:types']->handler)([])['types']);
        $plugin->boot();
        self::assertTrue(($ops['screen:declare']->handler)(['name' => 'task'])['ok']);
    }
}

final class AssetTaskRenderer implements ComponentRendererInterface, \Milpa\Live\Contracts\Rendering\DeclaresClientAssets
{
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }
    public function clientAssets(): \Milpa\Live\ValueObjects\ClientAssets
    {
        return new \Milpa\Live\ValueObjects\ClientAssets(['/custom-task.js'], ['/custom-task.css']);
    }
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        return new RenderResult('<p>Custom task</p>', clientAssets: new \Milpa\Live\ValueObjects\ClientAssets(styles: ['/render-result.css']));
    }
}

final class RegisteredTask extends AbstractDashboardComponent
{
    public function __construct(public readonly string $label)
    {
        parent::__construct();
    }

    public static function contract(): ComponentContract
    {
        return new ComponentContract('registered-task', '1');
    }
}

final class RegisteredTaskRenderer implements ComponentRendererInterface
{
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        return new RenderResult('<p>' . ($component instanceof RegisteredTask ? $component->label : '') . '</p>');
    }
}
