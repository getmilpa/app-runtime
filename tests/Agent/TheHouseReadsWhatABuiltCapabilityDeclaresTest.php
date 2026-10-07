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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * THE HOUSE READS WHAT A BUILT CAPABILITY DECLARES WHEN A PROMOTION LANDS IN IT (greenhouse decisions/0595 §1).
 *
 * A real house that boots, a real trial and a real promotion: the receipt is what a FRESH process of the house said
 * of the plugin as it now is — the operations, where each one's class lives, whether it says what it does and the
 * scope it spends. It read their declarations and called none of them.
 */
final class TheHouseReadsWhatABuiltCapabilityDeclaresTest extends TestCase
{
    private const OPEN = 'src/Plugins/Ledger/Operations/OpenAccount.php';
    private const LISTS = 'src/Plugins/Ledger/Operations/ListAccounts.php';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-house-capability-' . bin2hex(random_bytes(4));
        foreach (['/src/Plugins/Ledger/Operations', '/src/Plugins/Pages', '/src/Packaged', '/vendor', '/config', '/public', '/var'] as $dir) {
            mkdir($this->root . $dir, 0o777, true);
        }
        file_put_contents($this->root . '/vendor/autoload.php', '<?php
$loader = require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . "/../src/" . str_replace("\\\\", "/", substr($class, 4)) . ".php";
    if (str_starts_with($class, "App\\\\") && is_file($file)) {
        require $file;
    }
});
return $loader;
');
        file_put_contents($this->root . '/config/plugins.php', '<?php return [App\Plugins\Ledger\Ledger::class, App\Plugins\Pages\Pages::class, App\Packaged\Packaged::class];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => new \Milpa\Container\DIContainer(), "plugins" => require __DIR__ . "/plugins.php"];');
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/public/index.php', '<?php http_response_code(404);');

        file_put_contents($this->root . '/' . self::OPEN, $this->declared('OpenAccount', 'ledger:open', "#[Mutates(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Data)]\n#[Needs(scopes: ['ledger:write'])]"));
        file_put_contents($this->root . '/' . self::LISTS, $this->declared('ListAccounts', 'ledger:list', "#[Reads]\n#[Needs(scopes: ['ledger:read'])]"));
        $this->plugin('Plugins\Ledger', 'Ledger', [
            '\Milpa\Command\Declaration\DeclaredOperation::from(Operations\OpenAccount::class)',
            '\Milpa\Command\Declaration\DeclaredOperation::from(Operations\ListAccounts::class)',
        ]);
        $this->plugin('Plugins\Pages', 'Pages', null);
        $this->plugin('Packaged', 'Packaged', ["new \Milpa\Command\Operation(name: 'packaged:run', description: 'Not built here.', handler: static fn (array \$in): array => ['ok' => true])"]);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->root);
    }

    public function testAPromotionSaysWhatTheCapabilityItLandedInDeclares(): void
    {
        $receipt = $this->promote([self::OPEN => (string) file_get_contents($this->root . '/' . self::OPEN) . "\n// filled\n"]);

        self::assertTrue($receipt['ok'] ?? false, (string) json_encode($receipt));
        self::assertSame([[
            'predicate' => 'declared', 'subject' => 'Ledger', 'environment' => ['kind' => 'house'],
            'operations' => [
                ['name' => 'ledger:list', 'file' => self::LISTS, 'mutating' => false, 'effects' => true, 'scoped' => true],
                ['name' => 'ledger:open', 'file' => self::OPEN, 'mutating' => true, 'effects' => true, 'scoped' => true],
            ],
        ]], $receipt['capabilities'] ?? null, 'the capability it touched, by the name of its directory — not the plugin without operations, not the one that is no plugin of this house');
        self::assertStringContainsString('The house read what the capabilities built here declare, and called none of it: «Ledger» declares 2 operations.', (string) $receipt['note']);
    }

    public function testItCallsNothing(): void
    {
        $this->promote([self::OPEN => (string) file_get_contents($this->root . '/' . self::OPEN) . "\n// filled\n"]);

        self::assertFileDoesNotExist($this->root . '/var/ran', 'a declaration is read, never run: run() leaves this file when anybody calls it');
    }

    public function testAnOperationDeclaredByHandSaysWhatItDoesNotDeclare(): void
    {
        $this->plugin('Plugins\Ledger', 'Ledger', [
            '\Milpa\Command\Declaration\DeclaredOperation::from(Operations\OpenAccount::class)',
            "new \Milpa\Command\Operation(name: 'ledger:audit', description: 'By hand.', handler: static fn (array \$in): array => ['ok' => true], mutating: true)",
        ]);

        $receipt = $this->promote(['src/Plugins/Ledger/README.md' => 'x']);

        self::assertSame([
            ['name' => 'ledger:audit', 'file' => null, 'mutating' => true, 'effects' => false, 'scoped' => false],
            ['name' => 'ledger:open', 'file' => self::OPEN, 'mutating' => true, 'effects' => true, 'scoped' => true],
        ], $receipt['capabilities'][0]['operations'] ?? null, 'it has no class of its own, says nothing of what it does, and mutates with no scope');
    }

    public function testAPluginThatDeclaresNoOperationIsNoCapability(): void
    {
        $receipt = $this->promote(['src/Plugins/Pages/README.md' => 'x']);

        self::assertTrue($receipt['ok'] ?? false, (string) json_encode($receipt));
        self::assertArrayNotHasKey('capabilities', $receipt);
        self::assertStringNotContainsString('capabilities built here', (string) $receipt['note']);
    }

    public function testWhatDoesNotLiveInTheHousesPluginTreeIsNotBuiltHere(): void
    {
        $receipt = $this->promote(['config/plugins.php' => (string) file_get_contents($this->root . '/config/plugins.php') . "\n"]);

        self::assertSame(['Ledger'], array_column($receipt['capabilities'] ?? [], 'subject'), 'every plugin of the house was looked at, and the packaged one declares an operation too');
    }

    public function testAHouseWithNoFrontControllerStillSaysWhatItDeclares(): void
    {
        unlink($this->root . '/public/index.php');

        $receipt = $this->promote(['src/Plugins/Ledger/README.md' => 'x']);

        self::assertSame(['Ledger'], array_column($receipt['capabilities'] ?? [], 'subject'), 'a catalogue needs no front controller');
        self::assertArrayNotHasKey('observed', $receipt);
        self::assertArrayNotHasKey('observation_error', $receipt);
    }

    public function testTheHouseClosesOnWhatItRead(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build a plugin named Ledger to open an account and to list the accounts.', AutonomyMode::Auto);
        $receipt = $this->promote(['src/Plugins/Ledger/README.md' => 'x']);
        $seq = $store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode($receipt), mutating: true);
        $session = $store->load('s');
        self::assertNotNull($session);

        $closure = ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => 'Ledger', 'seq' => $seq, 'capability' => ['operations' => 2, 'exercised' => 'unjudged']], $closure['derivedFrom']['observation'] ?? null);
    }

    /**
     * Land files through a real trial and a real promotion, and return the receipt.
     *
     * @param array<string, string> $files
     *
     * @return array<string, mixed>
     */
    private function promote(array $files): array
    {
        $ws = TrialWorkspace::materialize($this->root, 'w1', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        foreach ($files as $path => $contents) {
            file_put_contents($ws->copy . '/' . $path, $contents);
        }
        foreach ((new TrialOperations(new DIContainer(), null, $this->root))->operations() as $op) {
            if ($op->name === 'sandbox:promote') {
                return ($op->handler)(['workspace' => 'w1']);
            }
        }
        self::fail('no sandbox:promote');
    }

    /** A declared operation whose `run()` leaves `var/ran` behind when anybody calls it. */
    private function declared(string $class, string $name, string $attributes): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\Ledger\Operations;
use Milpa\Command\Declaration\Mutates;
use Milpa\Command\Declaration\Needs;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Declaration\Reads;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
#[Operation(name: \'' . $name . '\', description: \'It does one thing.\')]
' . $attributes . '
final class ' . $class . '
{
    public function run(): array
    {
        touch(' . var_export($this->root . '/var/ran', true) . ');

        return [\'ok\' => true];
    }
}
';
    }

    /**
     * A plugin of this house under `src/<namespace>/`; with `$operations` it is a CommandProvider that declares them.
     *
     * @param list<string>|null $operations
     */
    private function plugin(string $namespace, string $name, ?array $operations): void
    {
        $provides = $operations === null ? '' : ', \Milpa\Command\CommandProvider';
        $declares = $operations === null ? '' : '    public function operations(): array { return [' . implode(', ', $operations) . ']; }' . "\n";
        file_put_contents($this->root . '/src/' . str_replace('\\', '/', $namespace) . "/{$name}.php", '<?php
declare(strict_types=1);
namespace App\\' . $namespace . ';
#[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "' . $name . '", type: "Service")]
final class ' . $name . ' implements \Milpa\Interfaces\Plugin\PluginInterface' . $provides . '
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
    public function boot(): void {}
    public function install(): void {}
    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}
' . $declares . '}
');
    }
}
