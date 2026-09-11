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
use Milpa\AppRuntime\Support\Operations;
use Milpa\Attributes\PluginMetadata;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
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
            self::assertMatchesRegularExpression('#^php bin/coa ([a-z:]+)#', $step['command']);
            preg_match('#^php bin/coa ([a-z:]+)#', $step['command'], $m);
            self::assertContains($m[1], $offered, sprintf('«%s» must be a command this app offers', $step['command']));
        }
        self::assertSame('php bin/coa serve', end($answer['next'])['command'], 'seeing it is always the last step');

        // THE STEPS FOLLOW THE HOUSE, read from a vendor this test writes: nothing switched on → see
        // what exists FIRST, then the generators, then the door; switched on → neither step, and the
        // answer does not even mention them.
        $bare = array_column($operations->houseStart($this->vendorWith([]))['next'], 'command');
        self::assertSame('php bin/coa capabilities:refresh', $bare[0], 'a house that never looked cannot propose what it has not seen');
        // THE PANEL, IN THE FIRST MINUTE. It is station 2 of the ideal path, and this branch always
        // meant to propose it — it was unreachable in a newborn house only because `milpa/admin` was
        // missing from the offline floor (greenhouse decisions/0247).
        self::assertSame('php bin/coa capabilities:enable milpa/admin --sign', $bare[1], 'the panel is where a human meets the house');
        self::assertSame('php bin/coa capabilities:enable milpa/devtools --sign', $bare[2], 'then the generators');
        self::assertContains('php bin/coa capabilities:enable milpa/auth --sign', $bare);
        // EVERY TAUGHT COMMAND RUNS. A privileged one printed without `--sign` is refused the moment
        // somebody types it, which is worse than not offering it (greenhouse decisions/0241).
        foreach ($bare as $command) {
            if (str_starts_with($command, 'php bin/coa capabilities:enable ')) {
                self::assertStringEndsWith(' --sign', $command, sprintf('«%s» would be refused as printed', $command));
            }
        }
        $grown = array_column($operations->houseStart($this->vendorWith([
            $this->package('milpa/devtools', 'devtools'),
            $this->package('milpa/auth', 'identity'),
        ]))['next'], 'command');
        self::assertNotContains('php bin/coa capabilities:enable milpa/devtools --sign', $grown);
        self::assertNotContains('php bin/coa capabilities:enable milpa/auth --sign', $grown);
    }

    #[Test]
    public function house_start_recommends_a_recipe_only_when_the_app_can_apply_one(): void
    {
        mkdir($this->root . '/recipes');
        file_put_contents($this->root . '/recipes/notes.json', '{}');

        $commands = array_column($this->booted()->houseStart()['next'], 'command');
        self::assertNotContains('php bin/coa recipe:apply --recipe=notes', $commands, 'a recipe on disk is not a step when recipe:apply is not offered');
        self::assertContains('php bin/coa foundation:found', $commands, 'an unfounded app without a recipe to apply is told to found itself');

        // POSITIVE CONTROL: the same recipe, now with recipe:apply offered AND the governed runtime switched on —
        // the step appears and displaces the founding one.
        $this->declareOperations(['AgentOperations', 'CapabilityOperations', 'FoundationOperations', 'RecipeOperations']);
        $grown = $this->vendorWith([$this->package('milpa/agent', 'agent')]);
        $commands = array_column($this->booted()->houseStart($grown)['next'], 'command');
        self::assertContains('php bin/coa recipe:apply --recipe=notes', $commands);
        self::assertNotContains('php bin/coa foundation:found', $commands);

        // Without the session store the recipe would refuse (measured on cattle, evidence/0562), so the house
        // names milpa/agent first and the recipe not yet. The gate is milpa/tool-runtime's (decisions/0225):
        // the model gateway is NOT asked for — a door a human opens needs no model on the other side.
        $bare = array_column($this->booted()->houseStart($this->vendorWith([]))['next'], 'command');
        self::assertNotContains('php bin/coa recipe:apply --recipe=notes', $bare);
        self::assertContains('php bin/coa capabilities:enable milpa/agent --sign', $bare);
        self::assertNotContains('php bin/coa capabilities:enable milpa/ai-gateway', $bare, 'the door does not need the model gateway');
    }

    #[Test]
    public function house_start_offers_serve_only_when_the_app_offers_it(): void
    {
        $commands = array_column($this->booted()->houseStart()['next'], 'command');
        self::assertContains('php bin/coa serve', $commands);

        // POSITIVE CONTROL: an app whose catalogue holds no `serve` (no AgentOperations) is not told to serve —
        // the steps are read from the catalogue, not written by hand.
        $this->declareOperations(['CapabilityOperations', 'FoundationOperations']);
        $commands = array_column($this->booted()->houseStart()['next'], 'command');
        self::assertNotContains('php bin/coa serve', $commands);
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

    #[Test]
    public function routes_of_a_plugin_the_kernel_vetoed_are_not_listed(): void
    {
        // A `plugin.booting` listener stops the slot: the kernel never runs boot() nor mounts the routes.
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe('plugin.booting', static function (string $event, array $payload): void {
            if (($payload['event']->pluginName ?? null) === 'FirstHourRoutedPlugin') {
                $payload['slot']->stop();
            }
        });
        $vetoed = $this->booted($dispatcher);
        self::assertSame([], (new \ReflectionProperty($vetoed, 'container'))->getValue($vetoed)->get(Kernel::class)->bootedPluginNames());

        self::assertSame(0, $vetoed->routesList()['count'], 'a route the kernel will 404 is not a route this app exposes');
        self::assertSame(['count' => 0, 'paths' => []], $vetoed->houseStart()['routes']);

        // POSITIVE CONTROL: the same plugin, booted — two routes, one path.
        $booted = $this->booted();
        self::assertSame(2, $booted->routesList()['count']);
        self::assertSame(['/first-hour/notes'], $booted->houseStart()['routes']['paths']);
    }

    #[Test]
    public function the_first_hour_stays_off_the_http_surface_and_serve_says_it_starts_something(): void
    {
        $byName = [];
        foreach ((new AgentOperations(new DIContainer()))->operations() as $op) {
            $byName[$op->name] = $op;
        }
        // The answers carry the app's filesystem root and its whole route table: not for a route that
        // answers without a principal under `expose: ['*']` (house:context keeps the same surfaces).
        self::assertSame(['cli', 'tui', 'mcp'], $byName['house:start']->surfaces);
        self::assertSame(['cli', 'tui', 'mcp'], $byName['routes:list']->surfaces);
        self::assertSame(['cli'], $byName['serve']->surfaces);
        self::assertTrue($byName['serve']->mutating, '`coa list` sorts by this flag: a server under «They read» is a lie');
        self::assertFalse($byName['house:start']->mutating);
        self::assertFalse($byName['routes:list']->mutating);
    }

    #[Test]
    public function serve_takes_an_ipv6_literal_only_the_way_the_built_in_server_does(): void
    {
        mkdir($this->root . '/public');
        $operations = $this->booted();

        // `php -S ::1:8000` is «Invalid address»; in brackets it binds. A bare literal is bracketed, not refused.
        foreach (['::1', '[::1]'] as $host) {
            $answer = $operations->serve(['dry_run' => true, 'host' => $host, 'port' => 8731]);
            self::assertIsArray($answer);
            self::assertTrue($answer['ok'], $host);
            self::assertSame(\PHP_BINARY . ' -S [::1]:8731 -t public', $answer['command']);
            self::assertSame('http://localhost:8731/', $answer['url']);
        }
        // POSITIVE CONTROLS: what is not an address and not a name is refused by name.
        foreach (['zz::gg', '-x', '[not-v6]'] as $host) {
            $answer = $operations->serve(['dry_run' => true, 'host' => $host]);
            self::assertIsArray($answer);
            self::assertFalse($answer['ok'], $host);
            self::assertStringContainsString('host', $answer['error']);
        }
    }

    #[Test]
    public function the_signal_that_stops_coa_stops_the_server(): void
    {
        if (!\function_exists('pcntl_exec')) {
            self::markTestSkipped('needs pcntl: serve hands its process to the server with pcntl_exec');
        }
        mkdir($this->root . '/public');
        file_put_contents($this->root . '/public/index.php', '<?php echo "first hour";');
        $port = $this->freePort();
        $script = $this->root . '/serve.php';
        file_put_contents($script, '<?php require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . '$container = new \Milpa\Container\DIContainer();' . "\n"
            . '$kernel = \Milpa\Runtime\Kernel::boot(["root" => ' . var_export($this->root, true) . ', "container" => $container, "toolRegistry" => new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger()), "plugins" => [], "config" => []]);' . "\n"
            . '$container->registerService(\Milpa\Runtime\Kernel::class, $kernel);' . "\n"
            . 'var_export((new \Milpa\AppRuntime\Operations\AgentOperations($container))->serve(["port" => ' . $port . ']));');
        $process = proc_open([\PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            $body = $this->awaitAnswer($port);
            self::assertSame('first hour', $body, 'the URL serve printed answers while it runs');

            // THE MEASUREMENT: SIGTERM to the process coa started — the one the terminal, a supervisor or a
            // test harness would signal — and the port is free afterwards. With passthru it stayed taken.
            proc_terminate($process, 15);
            $freed = false;
            for ($i = 0; $i < 50 && !$freed; $i++) {
                usleep(100_000);
                $freed = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2) === false;
            }
            self::assertTrue($freed, 'the server outlived the signal that stopped coa');
        } finally {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($socket, (string) $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private function awaitAnswer(int $port): string
    {
        for ($i = 0; $i < 100; $i++) {
            $body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, stream_context_create(['http' => ['timeout' => 1]]));
            if (\is_string($body)) {
                return $body;
            }
            usleep(100_000);
        }
        self::fail('the server never answered on port ' . $port);
    }

    /** @param list<array<string, mixed>> $packages */
    private function vendorWith(array $packages): string
    {
        $dir = $this->root . '/vendor-' . bin2hex(random_bytes(3));
        mkdir($dir . '/composer', 0o775, true);
        file_put_contents($dir . '/composer/installed.json', json_encode(['packages' => $packages], \JSON_THROW_ON_ERROR));

        return $dir;
    }

    /** @return array<string, mixed> */
    private function package(string $name, string $id): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'extra' => ['milpa' => ['capability' => ['id' => $id, 'title' => $id, 'unlocks' => [], 'provides' => []]]]];
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

    private function booted(?EventDispatcher $dispatcher = null): AgentOperations
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $container,
            'dispatcher' => $dispatcher ?? new EventDispatcher(new NullLogger()),
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
