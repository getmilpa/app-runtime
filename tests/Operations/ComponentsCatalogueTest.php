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
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\DeclaresComponents;
use Milpa\Live\ValueObjects\ActionContract;
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

    public function testTheProviderOffersTheCatalogueAsADeclaredOperation(): void
    {
        $operations = (new \Milpa\AppRuntime\Operations\ComponentOperations(new DIContainer()))->operations();

        self::assertCount(1, $operations);
        self::assertSame('components:catalogue', $operations[0]->name);
        self::assertFalse($operations[0]->mutating);
        self::assertSame(['cli', 'tui', 'mcp', 'http'], $operations[0]->surfaces);
    }

    public function testAPluginThatDidNotBootDeclaresNothing(): void
    {
        // A plugin with no metadata cannot have booted: the kernel refuses it before boot. A row
        // for it would describe a house that does not exist.
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);

        $catalogue = (new ComponentsCatalogue())->run(new ComponentDeclarations($container));

        // TWO framework declarers, not one: `milpa/live`'s render-target-agnostic primitives and
        // `milpa/live-web`'s HTML-flavoured ones. The second was invisible to this catalogue until it
        // was declared — `brand-mark` had been on disk since it was written with no row saying so
        // (greenhouse decisions/0299).
        self::assertSame(['Milpa\Live\Components\Library', 'Milpa\Live\Components\WebLibrary'], $catalogue->sources);
    }

    public function testEveryActionRowIsAMapSoNoAgentFacesAUnionType(): void
    {
        // `'start' => []` would encode as `[]` in JSON while `'fire' => [...]` encodes as an object,
        // handing a reader two types in one field. Every row carries at least `payload` and
        // `declaresEffects`, so none of them can ever be an empty list.
        $byName = $this->byName($this->catalogue([OddActionPlugin::class])->components);
        $row = $byName['fixture-odd']['actions']['go'];

        self::assertArrayHasKey('payload', $row);
        self::assertArrayHasKey('declaresEffects', $row);
        self::assertFalse($row['declaresEffects'], 'a bare action declared nothing about its effects');
        self::assertArrayNotHasKey('mutating', $row, 'and nothing may be answered on its behalf');
    }

    public function testADeclaredActionCarriesItsIntentAndAnUndeclaredOneSaysSo(): void
    {
        $byName = $this->byName($this->catalogue([CataloguedComponentsPlugin::class])->components);

        $declared = $byName['fixture-rating']['actions']['set'];
        self::assertTrue($declared['declaresEffects']);
        self::assertTrue($declared['mutating']);
        self::assertSame('Set the rating.', $declared['summary']);
        self::assertSame('value', $declared['namedTarget']);
        self::assertNotSame([], $declared['effects']);

        $bare = $byName['fixture-bare']['actions'] ?? [];
        self::assertSame([], $bare, 'a component that declares no actions carries none');
    }

    public function testWithNoKernelOnlyThisPackagesOwnPrimitivesAnswer(): void
    {
        // A terminal with no booted house still gets the framework's library: the catalogue reads
        // declarations, and this package's declaration does not need a kernel to exist.
        $catalogue = (new ComponentsCatalogue())->run(new ComponentDeclarations(new DIContainer()));

        self::assertTrue($catalogue->ok);
        // TWO framework declarers, not one: `milpa/live`'s render-target-agnostic primitives and
        // `milpa/live-web`'s HTML-flavoured ones. The second was invisible to this catalogue until it
        // was declared — `brand-mark` had been on disk since it was written with no row saying so
        // (greenhouse decisions/0299).
        self::assertSame(['Milpa\Live\Components\Library', 'Milpa\Live\Components\WebLibrary'], $catalogue->sources);
    }

    public function testAPluginThatDeclaresNoComponentsIsSkipped(): void
    {
        $catalogue = $this->catalogue([SilentPlugin::class]);

        // TWO framework declarers, not one: `milpa/live`'s render-target-agnostic primitives and
        // `milpa/live-web`'s HTML-flavoured ones. The second was invisible to this catalogue until it
        // was declared — `brand-mark` had been on disk since it was written with no row saying so
        // (greenhouse decisions/0299).
        self::assertSame(['Milpa\Live\Components\Library', 'Milpa\Live\Components\WebLibrary'], $catalogue->sources);
    }

    public function testAPluginWithoutMetadataCannotHaveBootedAndIsSkipped(): void
    {
        // The kernel refuses a plugin without metadata before boot, so a row for it would describe
        // a house that does not exist.
        $container = new DIContainer();
        $kernel = Kernel::boot(['root' => \dirname(__DIR__, 2), 'container' => $container, 'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);

        $declarations = new ComponentDeclarations($container);
        $reflection = new \ReflectionMethod($declarations, 'hasBooted');

        self::assertFalse($reflection->invoke($declarations, new UnmarkedDeclarer(), ['anything']));
    }

    public function testAContractThatThrowsIsNamedNotFatal(): void
    {
        $catalogue = $this->catalogue([ThrowingContractPlugin::class]);

        self::assertTrue($catalogue->ok);
        self::assertStringContainsString('FixtureThrowingComponent', $catalogue->cannotSay[0]);
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
            actions: ['set' => new ActionContract(
                summary: 'Set the rating.',
                mutating: true,
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Compensatable,
                    authority: Authority::WriteAsUser,
                ),
                namedTarget: 'value',
                payload: ['value' => 'int'],
            )],
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

/** A component whose action spec is not a map — the shape the catalogue must normalise. */
final class FixtureOddComponent implements ComponentDefinitionInterface
{
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'fixture-odd',
            contractVersion: '1',
            actions: ['go' => []],
        );
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, 'fixture-odd', '1', [], []);
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
    name: 'OddActionPlugin',
    type: 'Service',
)]
final class OddActionPlugin implements PluginInterface, DeclaresComponents
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function declaredComponents(): array
    {
        return [FixtureOddComponent::class];
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

/** A declarer the kernel never marked as booted — it carries no plugin metadata at all. */
final class UnmarkedDeclarer implements DeclaresComponents
{
    public function declaredComponents(): array
    {
        return [];
    }
}

/** A component whose contract cannot be built: the catalogue names it instead of dying with it. */
final class FixtureThrowingComponent implements ComponentDefinitionInterface
{
    public static function contract(): ComponentContract
    {
        throw new \RuntimeException('this contract cannot be built');
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, 'never', '1', [], []);
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
    name: 'ThrowingContractPlugin',
    type: 'Service',
)]
final class ThrowingContractPlugin implements PluginInterface, DeclaresComponents
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function declaredComponents(): array
    {
        return [FixtureThrowingComponent::class];
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
    name: 'SilentPlugin',
    type: 'Service',
)]
final class SilentPlugin implements PluginInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
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
