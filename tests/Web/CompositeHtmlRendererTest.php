<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\CompositeHtmlRenderer;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The composer that turns a declared container screen into a rendered LAYOUT (greenhouse decisions/0167):
 * it renders each declared child and assembles their HTML into the container's `childrenHtml`. What this
 * fixes: a container's children compose into one page, a leaf passes straight through, and an unknown child
 * type is refused instead of silently omitted.
 */
final class CompositeHtmlRendererTest extends TestCase
{
    /** A renderer that echoes the contract it rendered and the childrenHtml it was handed. */
    private function inner(): ComponentRendererInterface
    {
        return new class () implements ComponentRendererInterface {
            public function supportsTarget(RenderTarget $target): bool
            {
                return $target === RenderTarget::HTML;
            }

            public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
            {
                $name = $component::contract()->name;
                $children = (string) ($request->props['childrenHtml'] ?? '');

                return new RenderResult(output: "<{$name}>{$children}</{$name}>", state: null, assets: [], format: RenderTarget::HTML);
            }
        };
    }

    public function testAContainerComposesItsChildrenIntoOnePage(): void
    {
        // Use real leaf/container components so contract() names are correct.
        $factory = fn (string $type, array $props): ?ComponentDefinitionInterface => match ($type) {
            'leaf-a', 'leaf-b' => $this->realComponent($type),
            default => null,
        };
        $renderer = new CompositeHtmlRenderer($this->inner(), $factory);

        $context = new ComponentContext('panel', route: '/live');
        $props = ['children' => [
            ['type' => 'leaf-a', 'props' => []],
            ['type' => 'leaf-b', 'props' => []],
        ]];
        $out = $renderer->render($this->realComponent('dashboard-grid'), new RenderRequest($context, $props))->output;

        self::assertSame('<dashboard-grid><leaf-a></leaf-a><leaf-b></leaf-b></dashboard-grid>', $out);
    }

    /** An inner renderer that echoes the `nombre` of each row its child was handed — so filtering is visible. */
    private function rowsEcho(): ComponentRendererInterface
    {
        return new class () implements ComponentRendererInterface {
            public function supportsTarget(RenderTarget $target): bool
            {
                return $target === RenderTarget::HTML;
            }

            public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
            {
                $rows = \is_array($request->props['rows'] ?? null) ? $request->props['rows'] : [];
                $names = implode(',', array_map(static fn (mixed $r): string => \is_array($r) ? (string) ($r['nombre'] ?? '') : '', $rows));
                $children = (string) ($request->props['childrenHtml'] ?? '');

                return new RenderResult(output: "[{$names}]{$children}", state: null, assets: [], format: RenderTarget::HTML);
            }
        };
    }

    public function testAReaderChildIsFilteredByTheLayoutState(): void
    {
        $factory = fn (string $type, array $props): ?ComponentDefinitionInterface => $this->realComponent('leaf-a');
        $renderer = new CompositeHtmlRenderer($this->rowsEcho(), $factory);
        $context = new ComponentContext('panel', route: '/live');
        $rows = [['nombre' => 'Rod', 'rol' => 'fundador'], ['nombre' => 'Ana', 'rol' => 'agente']];
        $props = [
            'layoutState' => ['role' => 'fundador'],
            'children' => [
                ['type' => 'data-table', 'props' => ['filterBy' => ['state' => 'role', 'column' => 'rol'], 'rows' => $rows]],
            ],
        ];

        $out = $renderer->render($this->realComponent('dashboard-grid'), new RenderRequest($context, $props))->output;

        self::assertStringContainsString('[Rod]', $out);   // only the fundador row survives
        self::assertStringNotContainsString('Ana', $out);
    }

    public function testTheNeutralLayoutStateValueLeavesAllRows(): void
    {
        $factory = fn (string $type, array $props): ?ComponentDefinitionInterface => $this->realComponent('leaf-a');
        $renderer = new CompositeHtmlRenderer($this->rowsEcho(), $factory);
        $context = new ComponentContext('panel', route: '/live');
        $rows = [['nombre' => 'Rod', 'rol' => 'fundador'], ['nombre' => 'Ana', 'rol' => 'agente']];
        $props = [
            'layoutState' => ['role' => ''],   // neutral: the whole, unfiltered truth
            'children' => [
                ['type' => 'data-table', 'props' => ['filterBy' => ['state' => 'role', 'column' => 'rol'], 'rows' => $rows]],
            ],
        ];

        $out = $renderer->render($this->realComponent('dashboard-grid'), new RenderRequest($context, $props))->output;

        self::assertStringContainsString('Rod', $out);
        self::assertStringContainsString('Ana', $out);
    }

    public function testALeafPassesStraightThrough(): void
    {
        $factory = fn (string $type, array $props): ?ComponentDefinitionInterface => null;
        $renderer = new CompositeHtmlRenderer($this->inner(), $factory);
        $context = new ComponentContext('m', route: '/live');

        $out = $renderer->render($this->realComponent('metric-card'), new RenderRequest($context, ['value' => '42']))->output;

        self::assertSame('<metric-card></metric-card>', $out);
    }

    public function testAnUnknownNestedChildRefusesTheWholeRender(): void
    {
        $renderer = new CompositeHtmlRenderer($this->inner(), fn (string $type): ?ComponentDefinitionInterface => $type === 'dashboard-grid' ? $this->realComponent($type) : null);
        $this->expectException(\Milpa\AppRuntime\Web\InvalidScreenTree::class);
        $this->expectExceptionMessage('unknown component type: missing');
        $renderer->render($this->realComponent('dashboard-grid'), new RenderRequest(new ComponentContext('screen'), ['children' => [
            ['type' => 'dashboard-grid', 'props' => ['children' => [['type' => 'missing']]]],
        ]]));
    }

    public function testNestedClientAssetsAndContractsSurviveComposition(): void
    {
        $inner = new class () implements ComponentRendererInterface {
            public function supportsTarget(RenderTarget $target): bool
            {
                return true;
            }
            public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
            {
                return new RenderResult(
                    output: (string) ($request->props['childrenHtml'] ?? ''),
                    assets: ['route' => $request->context->route],
                    clientAssets: new \Milpa\Live\ValueObjects\ClientAssets(scripts: ['/shared.js', '/' . $component::contract()->name . '.js']),
                );
            }
        };
        $renderer = new CompositeHtmlRenderer($inner, fn (string $type): ComponentDefinitionInterface => $this->realComponent($type));
        $result = $renderer->render($this->realComponent('dashboard-grid'), new RenderRequest(new ComponentContext('screen', route: '/ui'), ['children' => [
            ['type' => 'dashboard-grid', 'props' => ['children' => [['type' => 'leaf-a'], ['type' => 'leaf-b']]]],
        ]]));
        self::assertSame(['/shared.js', '/dashboard-grid.js', '/leaf-a.js', '/leaf-b.js'], $result->clientAssets()->scripts);
        self::assertSame(['dashboard-grid', 'dashboard-grid', 'leaf-a', 'leaf-b'], array_map(fn ($contract) => $contract->name, $result->assets['componentContracts']));
        self::assertSame('/ui', $result->assets['route']);
    }

    public function testADeclaringRendererContributesItsFilesThroughTheDispatcher(): void
    {
        $declaring = new class () implements ComponentRendererInterface, \Milpa\Live\Contracts\Rendering\DeclaresClientAssets {
            public function supportsTarget(RenderTarget $target): bool
            {
                return true;
            }
            public function clientAssets(): \Milpa\Live\ValueObjects\ClientAssets
            {
                return new \Milpa\Live\ValueObjects\ClientAssets(scripts: ['/leaf.js'], styles: ['/leaf.css']);
            }
            public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
            {
                return new RenderResult(output: '<leaf></leaf>');
            }
        };
        $dispatch = new \Milpa\AppRuntime\Web\DispatchingHtmlRenderer(['leaf-a' => $declaring], $this->inner());
        foreach ([$dispatch, $declaring] as $inner) {
            $renderer = new CompositeHtmlRenderer($inner, fn (string $type): ComponentDefinitionInterface => $this->realComponent($type));
            $out = $renderer->render($this->realComponent('dashboard-grid'), new RenderRequest(new ComponentContext('screen'), ['children' => [['type' => 'leaf-a'], ['type' => 'leaf-a']]]));
            self::assertSame(['/leaf.js'], $out->clientAssets()->scripts);
            self::assertSame(['/leaf.css'], $out->clientAssets()->styles);
        }
    }

    private function realComponent(string $contract): ComponentDefinitionInterface
    {
        // An anonymous ComponentDefinition whose static contract() returns the given name.
        return match ($contract) {
            'dashboard-grid' => new class () extends AnonContract {
                protected const N = 'dashboard-grid';
            },
            'leaf-a' => new class () extends AnonContract {
                protected const N = 'leaf-a';
            },
            'leaf-b' => new class () extends AnonContract {
                protected const N = 'leaf-b';
            },
            default => new class () extends AnonContract {
                protected const N = 'metric-card';
            },
        };
    }
}

abstract class AnonContract implements ComponentDefinitionInterface
{
    protected const N = 'x';

    public static function contract(): ComponentContract
    {
        return new ComponentContract(static::N, '1.0');
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, static::N, '1.0');
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        throw new \LogicException('not exercised');
    }
}
