<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\Foundation;
use Milpa\AppRuntime\Support\Operations;
use Milpa\Attributes\PluginMetadata;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\FileEventStore;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * F-HOUSE (greenhouse decisions/0183, primitive #1): `house:context` is the ONE call in which the
 * house explains itself structurally — the aggregate a session used to spend its opening model
 * calls re-deriving.
 *
 * The laws encoded here:
 *
 * - every key is ALWAYS present — the reader never probes which sections exist;
 * - each section is TIED TO ITS ONE AUTHORITY on a booted kernel: plugins to what the kernel
 *   booted, operations to `Operations::all`, capabilities to the capability registry's own
 *   answer, routes to the table the kernel's router holds, storage to the app config bag,
 *   foundation to `Foundation::answer` — a section that drifts from its authority is red here
 *   by construction, because the expectation IS the authority's live answer;
 * - no kernel is a fail-closed verdict (`ok:false`, in words), never a guess (H-GATE-1);
 * - the operation declares the read-only profile its siblings declare, and the catalogue it
 *   sits beside stays untouched.
 */
final class HouseContextReadTest extends TestCase
{
    /** The uniform shape: EVERY key, always — in this order. */
    private const UNIFORM_KEYS = [
        'ok',
        'app',
        'plugins',
        'storage',
        'routes',
        'capabilities',
        'operations',
        'sessionTools',
        'conventions',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-ar-house-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents(
            $this->root . '/config/operations.php',
            "<?php\n\nreturn [\n"
                . '    \Milpa\AppRuntime\Operations\AgentOperations::class,' . "\n"
                . '    \Milpa\AppRuntime\Operations\CapabilityOperations::class,' . "\n"
                . '    \Milpa\AppRuntime\Operations\FoundationOperations::class,' . "\n"
                . "];\n",
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** Boots a real kernel over the fixture root and returns the operations facade plus the kernel. */
    private function booted(): AgentOperations
    {
        $container = new DIContainer();
        // A real event store, so the session notebook exists deterministically in this fixture.
        $container->registerService(EventStoreInterface::class, new FileEventStore($this->root . '/var/events.jsonl'));
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [HouseContextRoutedPlugin::class],
            'config' => [
                'app' => ['name' => 'context-house'],
                'storage' => ['driver' => 'file', 'path' => 'var/data/app.json'],
            ],
        ]);
        $container->registerService(Kernel::class, $kernel);

        return new AgentOperations($container);
    }

    private function kernelOf(AgentOperations $ops): Kernel
    {
        // The same container the facade reads from — the test asks the authority, not a copy.
        $kernel = (new \ReflectionProperty(AgentOperations::class, 'container'))->getValue($ops);
        self::assertInstanceOf(DIContainerInterface::class, $kernel);
        $kernel = $kernel->get(Kernel::class);
        self::assertInstanceOf(Kernel::class, $kernel);

        return $kernel;
    }

    /** Every key, always — and each truth-tied section EQUALS its authority's live answer. */
    public function testItAnswersEveryKeyAndEachSectionEqualsItsAuthority(): void
    {
        $ops = $this->booted();
        $kernel = $this->kernelOf($ops);

        $answer = $ops->houseContext();

        self::assertTrue($answer['ok']);
        self::assertSame(self::UNIFORM_KEYS, array_keys($answer), 'the shape is uniform: every key, always');

        // app — name from the config bag, root from the kernel, foundation from Foundation itself.
        self::assertSame('context-house', $answer['app']['name']);
        self::assertSame($kernel->root(), $answer['app']['root']);
        self::assertSame(Foundation::answer($kernel->root()), $answer['app']['foundation'], 'foundation EQUALS what Foundation answers');

        // plugins — exactly what the kernel booted, in boot order, named by their own metadata.
        self::assertSame(
            $kernel->bootedPluginNames(),
            array_column($answer['plugins'], 'name'),
            'plugin names EQUAL the kernel\'s own booted list',
        );
        self::assertSame(
            [['class' => HouseContextRoutedPlugin::class, 'name' => 'HouseContextRoutedPlugin', 'provides' => [HouseContextCapability::class]]],
            $answer['plugins'],
        );

        // storage — the config bag's own block: driver plus where, and nothing that could be a secret.
        self::assertSame(['driver' => 'file', 'where' => 'var/data/app.json'], $answer['storage']);

        // routes — the table the kernel's router holds: the two the fixture plugin contributed.
        self::assertSame(['count' => 2, 'paths' => ['/context', '/context/{id}']], $answer['routes']);

        // capabilities — the capability registry's OWN answer, verbatim.
        self::assertSame(Capabilities::answer(), $answer['capabilities'], 'capabilities EQUAL the registry\'s answer');

        // operations — count and names straight from the assembled catalogue's registry.
        $declared = array_map(
            static fn (Operation $o): string => $o->name,
            Operations::all($kernel, $kernel->root()),
        );
        self::assertSame(['count' => \count($declared), 'names' => $declared], $answer['operations'], 'operations EQUAL Operations::all');
        self::assertContains('house:context', $declared, 'the house op itself sits in the catalogue it reports');

        // sessionTools — the notebook's names: this fixture stores sessions, so the three exist.
        self::assertSame(['plan', 'todo', 'work:claim-verified'], $answer['sessionTools']);

        // conventions — the layout the generators actually write to, verbatim.
        self::assertSame(
            [
                'plugins' => 'src/Plugins/<Name>/<Name>.php',
                'entities' => 'src/Plugins/<Name>/Entities',
                'controllers' => 'src/Plugins/<Name>/Controllers',
                'config' => 'config/',
            ],
            $answer['conventions'],
        );
    }

    /** H-GATE-1: an app with no kernel says so instead of assembling a plausible house. */
    public function testWithoutAKernelItFailsClosedInWords(): void
    {
        $answer = (new AgentOperations(new DIContainer()))->houseContext();

        self::assertFalse($answer['ok']);
        self::assertStringContainsString('no kernel', (string) $answer['error']);
    }

    /** The operation is declared beside its siblings with the same read-only profile they carry. */
    public function testTheOperationDeclaresTheReadOnlyIdiomOfItsSiblings(): void
    {
        $declared = null;
        foreach ($this->booted()->operations() as $operation) {
            if ($operation->name === 'house:context') {
                $declared = $operation;
            }
        }

        self::assertInstanceOf(Operation::class, $declared);
        self::assertFalse($declared->mutating);
        self::assertNotNull($declared->effects);
        self::assertSame('none', $declared->effects->toArray()['mutation']);
        self::assertSame('none', $declared->effects->toArray()['externality']);
        self::assertSame('guaranteed', $declared->effects->toArray()['reversibility']);
        self::assertSame('read', $declared->effects->toArray()['authority']);
        self::assertSame('nothing-to-roll-back', $declared->effects->toArray()['rollback_contract']);
        self::assertSame(['cli', 'tui', 'mcp'], $declared->surfaces);
        self::assertSame([], $declared->preconditions, 'it demands nothing to be asked');
        self::assertNotNull($declared->observableEvidence);
    }
}

/** The capability the fixture plugin provides — an id the plugins section must carry verbatim. */
interface HouseContextCapability
{
}

/** A booted plugin with metadata, a capability, and two bound routes — the sections' one fixture. */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Milpa App Runtime Tests',
    site: 'https://example.test',
    name: 'HouseContextRoutedPlugin',
    type: 'Service',
    provides: [HouseContextCapability::class],
)]
final class HouseContextRoutedPlugin implements PluginInterface, RouteProviderInterface
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

    /** @return list<Route> */
    public function routes(): array
    {
        return [
            new Route(path: '/context', methods: HttpMethod::GET, handler: HandlerReference::action(self::class)),
            new Route(path: '/context/{id}', methods: HttpMethod::GET, handler: HandlerReference::action(self::class)),
        ];
    }
}
