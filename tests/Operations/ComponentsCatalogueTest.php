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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\ComponentsCatalogue;
use Milpa\AppRuntime\Web\ComponentDeclarations;
use Milpa\Attributes\PluginMetadata;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\DeclaresComponents;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue answers what a host DECLARED, and names what it could not determine.
 *
 * The falsifier this file carries is attribution: a component must be traceable to the plugin that
 * brought it, and the list must SHRINK when that plugin is gone. A catalogue that lists everything
 * regardless of what is installed describes a house that does not exist.
 */
#[CoversClass(ComponentsCatalogue::class)]
#[CoversClass(ComponentDeclarations::class)]
final class ComponentsCatalogueTest extends TestCase
{
    public function testItListsTheFrameworkPrimitivesAndSaysWhoDeclaredThem(): void
    {
        $catalogue = $this->catalogue([]);

        self::assertTrue($catalogue->ok);
        self::assertGreaterThanOrEqual(17, $catalogue->total);
        self::assertContains('Milpa\Live\Components\Library', $catalogue->sources);

        $byName = $this->byName($catalogue->components);
        self::assertArrayHasKey('textarea', $byName);
        self::assertSame('Milpa\Live\Components\Library', $byName['textarea']['declaredBy']);
    }

    public function testAPluginsComponentsAppearAttributedToThatPlugin(): void
    {
        $catalogue = $this->catalogue([CataloguedComponentsPlugin::class]);
        $byName = $this->byName($catalogue->components);

        self::assertArrayHasKey('fixture-rating', $byName);
        self::assertSame(CataloguedComponentsPlugin::class, $byName['fixture-rating']['declaredBy']);
        self::assertContains(CataloguedComponentsPlugin::class, $catalogue->sources);
    }

    public function testTheListShrinksWhenThePluginIsGone(): void
    {
        $with = $this->catalogue([CataloguedComponentsPlugin::class]);
        $without = $this->catalogue([]);

        self::assertSame($with->total - 2, $without->total);
        self::assertArrayNotHasKey('fixture-rating', $this->byName($without->components));
    }

    public function testAnUndeclaredFieldIsNamedRatherThanAnsweredWithADefault(): void
    {
        $byName = $this->byName($this->catalogue([CataloguedComponentsPlugin::class])->components);
        $bare = $byName['fixture-bare'];

        self::assertArrayNotHasKey('summary', $bare);
        self::assertArrayNotHasKey('actions', $bare);
        self::assertContains('summary', $bare['cannotSay']);
        self::assertContains('actions', $bare['cannotSay']);
        self::assertContains('designContract', $bare['cannotSay']);
    }

    public function testTwoDeclarersOfOneNameWithholdTheDeclarer(): void
    {
        $byName = $this->byName(
            $this->catalogue([CataloguedComponentsPlugin::class, ShadowingPlugin::class])->components,
        );

        self::assertArrayNotHasKey('declaredBy', $byName['fixture-rating']);
        self::assertSame(
            [CataloguedComponentsPlugin::class, ShadowingPlugin::class],
            $byName['fixture-rating']['declaredByAny'],
        );
        self::assertContains('declaredBy', $byName['fixture-rating']['cannotSay']);
    }

    public function testAskingForOneComponentAnswersOnlyThatOne(): void
    {
        $catalogue = $this->catalogue([CataloguedComponentsPlugin::class], 'fixture-rating');

        self::assertSame(1, $catalogue->total);
        self::assertSame('fixture-rating', $catalogue->components[0]['name']);
    }

    public function testADeclarationThatCannotBeLoadedIsNamedNotFatal(): void
    {
        $catalogue = $this->catalogue([BrokenDeclarationPlugin::class]);

        self::assertTrue($catalogue->ok);
        self::assertNotSame([], $catalogue->cannotSay);
        self::assertStringContainsString('App\\Nope', $catalogue->cannotSay[0]);
    }

    /**
     * @param list<class-string> $plugins
     */
    private function catalogue(array $plugins, ?string $only = null): \Milpa\AppRuntime\Operations\ComponentCatalogue
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'plugins' => $plugins,
        ]);
        $container->registerService(Kernel::class, $kernel);

        return (new ComponentsCatalogue($only))->run(new ComponentDeclarations($container));
    }

    /**
     * @param list<array<string, mixed>> $components
     *
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $components): array
    {
        $byName = [];
        foreach ($components as $component) {
            $byName[$component['name']] = $component;
        }

        return $byName;
    }
}

/** A component that declares everything a contract can carry. */
final class FixtureRatingComponent implements ComponentDefinitionInterface
{
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'fixture-rating',
            contractVersion: '1',
            summary: 'A star rating, declared by a fixture plugin.',
            designContract: '@milpa/design:primitives/fixture-rating.contract.json',
            defaultTemplate: 'components/fixture-rating.latte',
            propsSchema: ['max' => ['type' => 'int', 'default' => 5]],
            stateSchema: ['value' => ['type' => 'int']],
            actions: ['set' => ['payload' => ['value' => 'int']]],
        );
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, 'fixture-rating', '1', ['value' => 0], []);
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult($request->state);
    }
}

/** A component that declares only what a contract REQUIRES — the rest must be named, not defaulted. */
final class FixtureBareComponent implements ComponentDefinitionInterface
{
    public static function contract(): ComponentContract
    {
        return new ComponentContract(name: 'fixture-bare', contractVersion: '1');
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, 'fixture-bare', '1', [], []);
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult($request->state);
    }
}

#[PluginMetadata(
    version: '1.0.0',
    author: 'Milpa App Runtime Tests',
    site: 'https://example.test',
    name: 'CataloguedComponentsPlugin',
    type: 'Service',
)]
final class CataloguedComponentsPlugin implements PluginInterface, DeclaresComponents
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function declaredComponents(): array
    {
        return [FixtureRatingComponent::class, FixtureBareComponent::class];
    }

    public function boot(): void
    {
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }
}

#[PluginMetadata(
    version: '1.0.0',
    author: 'Milpa App Runtime Tests',
    site: 'https://example.test',
    name: 'ShadowingPlugin',
    type: 'Service',
)]
final class ShadowingPlugin implements PluginInterface, DeclaresComponents
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function declaredComponents(): array
    {
        return [FixtureRatingComponent::class];
    }

    public function boot(): void
    {
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }
}

#[PluginMetadata(
    version: '1.0.0',
    author: 'Milpa App Runtime Tests',
    site: 'https://example.test',
    name: 'BrokenDeclarationPlugin',
    type: 'Service',
)]
final class BrokenDeclarationPlugin implements PluginInterface, DeclaresComponents
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function declaredComponents(): array
    {
        /** @phpstan-ignore-next-line the point of the fixture is a class-string that does not load */
        return ['App\Nope'];
    }

    public function boot(): void
    {
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }
}
