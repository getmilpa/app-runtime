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
use Milpa\AppRuntime\Support\Operations;
use Milpa\Attributes\PluginMetadata;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The first hour (greenhouse decisions/0216): a newborn app answers «and now what?» with its own voice.
 *
 * `house:start` names only commands the app offers today and its steps change as they are taken; `routes:list`
 * attributes every route to the plugin that declared it; `serve` knows the skeleton's router when it is there
 * and refuses what it cannot serve. Each falsifier carries its positive control.
 */
final class TheFirstHourTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-ar-first-hour-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        $this->declareOperations(['AgentOperations', 'CapabilityOperations', 'FoundationOperations']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function routes_list_attributes_every_route_to_the_plugin_that_declared_it(): void
    {
        $answer = $this->booted()->routesList();

        self::assertTrue($answer['ok']);
        self::assertSame(2, $answer['count']);
        self::assertSame(
            [['GET', '/first-hour/notes'], ['POST', '/first-hour/notes']],
            array_map(static fn (array $row): array => [$row['method'], $row['path']], $answer['routes']),
            'sorted by path then method, so two readers print the same order',
        );
        $get = $answer['routes'][0];
        self::assertSame(['method', 'path', 'name', 'handler', 'middleware', 'plugin'], array_keys($get));
        self::assertSame('first-hour.notes', $get['name']);
        self::assertSame('FirstHourRoutedPlugin::__invoke', $get['handler']);
        self::assertSame(['FirstHourGuard'], $get['middleware']);
        self::assertSame('FirstHourRoutedPlugin', $get['plugin'], 'the declarer, which the router itself has forgotten');
    }

    #[Test]
    public function routes_list_without_a_kernel_says_so(): void
    {
        $answer = (new AgentOperations(new DIContainer()))->routesList();

        self::assertFalse($answer['ok']);
        self::assertStringContainsString('no kernel', $answer['error']);
    }

    #[Test]
    public function house_start_names_only_commands_this_app_offers_today(): void
    {
        $operations = $this->booted();
        $answer = $operations->houseStart();

        self::assertTrue($answer['ok']);
        self::assertSame('first-hour-house', $answer['app']['name']);
        self::assertSame('unfounded', $answer['app']['foundation']);
        self::assertSame(2, $answer['routes']['count']);
        self::assertSame(['/first-hour/notes'], $answer['routes']['paths']);

        $offered = $this->offered($operations);
        self::assertNotSame([], $answer['next'], 'a newborn app always has a next step');
        foreach ($answer['next'] as $step) {
            self::assertSame(['step', 'command', 'why'], array_keys($step));
            self::assertMatchesRegularExpression('/^coa ([a-z:]+)/', $step['command']);
            preg_match('/^coa ([a-z:]+)/', $step['command'], $m);
            self::assertContains($m[1], $offered, sprintf('«%s» must be a command this app offers', $step['command']));
        }
        self::assertSame('coa serve', end($answer['next'])['command'], 'seeing it is always the last step');

        // THE STEPS FOLLOW THE HOUSE: a capability already installed is never recommended again; one still
        // available is. This fixture runs inside app-runtime's own vendor, so it reads what is installed HERE.
        $commands = array_column($answer['next'], 'command');
        self::assertSame(!Capabilities::installed('devtools'), \in_array('coa capabilities:enable milpa/devtools', $commands, true));
        self::assertSame(!Capabilities::installed('identity'), \in_array('coa capabilities:enable milpa/auth', $commands, true));
    }

    #[Test]
    public function house_start_recommends_a_recipe_only_when_the_app_can_apply_one(): void
    {
        mkdir($this->root . '/recipes');
        file_put_contents($this->root . '/recipes/notes.json', '{}');

        $commands = array_column($this->booted()->houseStart()['next'], 'command');
        self::assertNotContains('coa recipe:apply --recipe=notes', $commands, 'a recipe on disk is not a step when recipe:apply is not offered');
        self::assertContains('coa foundation:found', $commands, 'an unfounded app without a recipe to apply is told to found itself');

        // POSITIVE CONTROL: the same recipe, now with recipe:apply offered — the step appears and displaces the founding one.
        $this->declareOperations(['AgentOperations', 'CapabilityOperations', 'FoundationOperations', 'RecipeOperations']);
        $commands = array_column($this->booted()->houseStart()['next'], 'command');
        self::assertContains('coa recipe:apply --recipe=notes', $commands);
        self::assertNotContains('coa foundation:found', $commands);
    }

    #[Test]
    public function house_start_offers_serve_only_when_the_app_offers_it(): void
    {
        $commands = array_column($this->booted()->houseStart()['next'], 'command');
        self::assertContains('coa serve', $commands);

        // POSITIVE CONTROL: an app whose catalogue holds no `serve` (no AgentOperations) is not told to serve —
        // the steps are read from the catalogue, not written by hand.
        $this->declareOperations(['CapabilityOperations', 'FoundationOperations']);
        $commands = array_column($this->booted()->houseStart()['next'], 'command');
        self::assertNotContains('coa serve', $commands);
    }

    #[Test]
    public function serve_builds_the_command_from_what_the_app_ships(): void
    {
        $operations = $this->booted();

        $answer = $operations->serve(['dry_run' => true]);
        self::assertIsArray($answer);
        self::assertFalse($answer['ok']);
        self::assertStringContainsString('no public/', $answer['error']);

        mkdir($this->root . '/public');
        $answer = $operations->serve(['dry_run' => true]);
        self::assertIsArray($answer);
        self::assertTrue($answer['ok']);
        self::assertSame(\PHP_BINARY . ' -S 127.0.0.1:8000 -t public', $answer['command']);
        self::assertSame('http://localhost:8000/', $answer['url'], 'the address the passkey door will accept');
        self::assertNull($answer['router'], 'without a router the built-in server serves files only');

        // POSITIVE CONTROL: the skeleton's router, once shipped, is part of the command.
        file_put_contents($this->root . '/public/router.php', '<?php return false;');
        $answer = $operations->serve(['dry_run' => true, 'host' => '0.0.0.0', 'port' => 8730]);
        self::assertIsArray($answer);
        self::assertSame(\PHP_BINARY . ' -S 0.0.0.0:8730 -t public public/router.php', $answer['command']);
        self::assertSame('http://localhost:8730/', $answer['url'], 'bound everywhere, opened at the relying party');
        self::assertSame('public/router.php', $answer['router']);
    }

    #[Test]
    public function serve_refuses_a_host_or_port_it_cannot_bind(): void
    {
        mkdir($this->root . '/public');
        $operations = $this->booted();

        $answer = $operations->serve(['dry_run' => true, 'port' => 70000]);
        self::assertIsArray($answer);
        self::assertFalse($answer['ok']);
        self::assertStringContainsString('port', $answer['error']);

        $answer = $operations->serve(['dry_run' => true, 'host' => 'a b; rm -rf /']);
        self::assertIsArray($answer);
        self::assertFalse($answer['ok']);
        self::assertStringContainsString('host', $answer['error']);
    }

    #[Test]
    public function serve_refuses_a_malformed_host_port_or_dry_run_instead_of_defaulting(): void
    {
        mkdir($this->root . '/public');
        $operations = $this->booted();

        // Each malformed value would, defaulted, have run something the caller did not ask for. `port: 1`
        // keeps the dry_run cases from hanging if the refusal ever regresses: an unprivileged bind fails at once.
        $malformed = [
            'port' => [['dry_run' => true, 'port' => 'abc'], ['dry_run' => true, 'port' => 3.5], ['dry_run' => true, 'port' => true]],
            'host' => [['dry_run' => true, 'host' => 123], ['dry_run' => true, 'host' => ['127.0.0.1']]],
            'dry_run' => [['dry_run' => '1', 'port' => 1], ['dry_run' => 'true', 'port' => 1], ['dry_run' => 1, 'port' => 1]],
        ];
        foreach ($malformed as $field => $inputs) {
            foreach ($inputs as $input) {
                $answer = $operations->serve($input);
                self::assertIsArray($answer, json_encode($input, \JSON_THROW_ON_ERROR));
                self::assertFalse($answer['ok'], json_encode($input, \JSON_THROW_ON_ERROR) . ' must be refused, not defaulted');
                self::assertStringContainsString($field, $answer['error']);
            }
        }

        // POSITIVE CONTROL: the same fields, well-formed — a digit string port included — are accepted; and a
        // negative string port reaches the range check with the same words as a negative int.
        $answer = $operations->serve(['dry_run' => true, 'host' => 'localhost', 'port' => '8730']);
        self::assertIsArray($answer);
        self::assertTrue($answer['ok']);
        self::assertSame(\PHP_BINARY . ' -S localhost:8730 -t public', $answer['command']);
        self::assertSame($operations->serve(['dry_run' => true, 'port' => -5]), $operations->serve(['dry_run' => true, 'port' => '-5']));
    }

    /** @param list<string> $classes */
    private function declareOperations(array $classes): void
    {
        $lines = array_map(static fn (string $c): string => '    \Milpa\AppRuntime\Operations\\' . $c . '::class,', $classes);
        file_put_contents($this->root . '/config/operations.php', "<?php\n\nreturn [\n" . implode("\n", $lines) . "\n];\n");
    }

    /** @return list<string> */
    private function offered(AgentOperations $operations): array
    {
        $kernel = (new \ReflectionProperty($operations, 'container'))->getValue($operations)->get(Kernel::class);

        return array_map(static fn (Operation $op): string => $op->name, Operations::all($kernel, $this->root));
    }

    private function booted(): AgentOperations
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [FirstHourRoutedPlugin::class],
            'config' => ['app' => ['name' => 'first-hour-house']],
        ]);
        $container->registerService(Kernel::class, $kernel);

        return new AgentOperations($container);
    }
}

/** Stands in for a middleware class in the fixture's route declaration. */
final class FirstHourGuard
{
}

/** A booted plugin declaring two routes on one path — the declarer `routes:list` must remember. */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Milpa App Runtime Tests',
    site: 'https://example.test',
    name: 'FirstHourRoutedPlugin',
    type: 'Service',
)]
final class FirstHourRoutedPlugin implements PluginInterface, RouteProviderInterface
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

    public function routes(): array
    {
        return [
            new Route(path: '/first-hour/notes', methods: HttpMethod::POST, handler: HandlerReference::action(self::class)),
            new Route(path: '/first-hour/notes', methods: HttpMethod::GET, name: 'first-hour.notes', middleware: [FirstHourGuard::class], handler: HandlerReference::action(self::class)),
        ];
    }
}
