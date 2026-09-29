<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Identity\ResidentSeat;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Console\OperationRunner;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * A seat that switches a plugin off in a leg's trial can carry that switch into the house (greenhouse decisions/0531).
 *
 * Since 0530 `plugins_disable` / `plugins_enable` run inside a leg's trial and write the registry the boot reads,
 * `storage/plugins.json`. Its promotion was refused for every seat — «Export 'storage/plugins.json' is outside a
 * plugin write set» — so a resident in AUTO could switch a plugin in the rehearsal and never in the house. The
 * registry crosses with the authority of the operations that write it, `plugins:write`, the way `config/plugins.php`
 * crosses with `plugins.config:write`; so does `milpa.lock`, which `plugins.lock` writes under the same scope.
 * Nothing else outside a plugin's tree opens with it, and the crossing still boots the house as it would be first.
 */
final class ASeatPromotesThePluginSwitchTest extends TestCase
{
    private const REGISTRY = 'storage/plugins.json';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-switch-' . bin2hex(random_bytes(6));
        foreach (['/vendor', '/config', '/storage', '/src/Plugins/Hello'] as $dir) {
            mkdir($this->root . $dir, 0o700, true);
        }
        file_put_contents($this->root . '/vendor/autoload.php', '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        // The boot reads the registry, as the skeleton's does: a house whose profile requires `Core` does not boot
        // with it switched off — what milpa/plugin's resolver answers to an open graph.
        file_put_contents($this->root . '/config/boot.php', <<<'PHP'
            <?php
            $registry = json_decode((string) @file_get_contents(__DIR__ . '/../storage/plugins.json'), true);
            foreach ($registry['plugins'] ?? [] as $row) {
                if ($row['name'] === 'Core' && $row['enabled'] === false) {
                    throw new \RuntimeException('the profile requires Core, and Core is off');
                }
            }
            return ["container" => new \Milpa\Container\DIContainer(), "plugins" => []];
            PHP);
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/config/operations.php', '<?php return [];');
        file_put_contents($this->root . '/' . self::REGISTRY, $this->registry(hello: true, core: true));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testASeatPromotesTheSwitchItMadeInItsTrialAndUndoesItWithTheSameScope(): void
    {
        $id = $this->trialThatWrites([self::REGISTRY => $this->registry(hello: false, core: true)]);
        [$promote, , $undo] = $this->doors();

        self::assertTrue($this->policy()->authorize($this->seat(), $this->tool('sandbox_promote'), ['workspace' => $id])->allowed);
        $landed = $this->runner()->run($promote, ['workspace' => $id], 'cli', authority: $this->seat());

        self::assertTrue($landed['ok'], (string) json_encode($landed));
        self::assertSame([self::REGISTRY], $landed['promoted']);
        self::assertSame($this->registry(hello: false, core: true), file_get_contents($this->root . '/' . self::REGISTRY), 'Hello is off in the house');

        self::assertTrue($this->policy()->authorize($this->seat(), $this->tool('sandbox_undo'), ['workspace' => $id])->allowed);
        self::assertTrue($this->runner()->run($undo, ['workspace' => $id], 'cli', authority: $this->seat())['ok']);
        self::assertSame($this->registry(hello: true, core: true), file_get_contents($this->root . '/' . self::REGISTRY), 'and on again after undo');
    }

    public function testASeatWithoutPluginsWriteIsStillRefusedAndTheHouseIsUntouched(): void
    {
        $id = $this->trialThatWrites([self::REGISTRY => $this->registry(hello: false, core: true)]);
        $withoutWrite = new ToolContext(
            principal: 'seat',
            channel: 'cli',
            scopes: array_values(array_diff(ResidentSeat::SCOPES, ['plugins:write'])),
        );

        $verdict = $this->policy()->authorize($withoutWrite, $this->tool('sandbox_promote'), ['workspace' => $id]);
        self::assertFalse($verdict->allowed);
        self::assertSame("Missing required permission 'plugins:write' for the plugin registry.", $verdict->reason);
        self::assertSame('plugins:write', $this->policy()->missingPermission($withoutWrite, 'sandbox_promote', ['workspace' => $id]));

        try {
            $this->runner()->run($this->doors()[0], ['workspace' => $id], 'cli', authority: $withoutWrite);
            self::fail('a seat without plugins:write must not promote the registry');
        } catch (\RuntimeException $refused) {
            self::assertSame("Missing required permission 'plugins:write' for the plugin registry.", $refused->getMessage());
        }
        self::assertSame($this->registry(hello: true, core: true), file_get_contents($this->root . '/' . self::REGISTRY));
    }

    public function testASwitchTheHouseCannotBootWithIsRefusedBeforeItIsWritten(): void
    {
        $id = $this->trialThatWrites([self::REGISTRY => $this->registry(hello: true, core: false)]);

        $said = $this->runner()->run($this->doors()[0], ['workspace' => $id], 'cli', authority: $this->seat());

        self::assertFalse($said['ok'], 'authorized is not admitted: the house as it would be boots first (0512/0515)');
        self::assertStringContainsString('Core is off', (string) json_encode($said));
        self::assertSame($this->registry(hello: true, core: true), file_get_contents($this->root . '/' . self::REGISTRY));
    }

    public function testTheLockCrossesWithTheSameScopeAndNothingElseOutsideAPluginDoes(): void
    {
        file_put_contents($this->root . '/milpa.lock', "{}\n");
        $lock = $this->trialThatWrites(['milpa.lock' => "{\"plugins\": []}\n"]);
        self::assertTrue($this->policy()->authorize($this->seat(), $this->tool('sandbox_promote'), ['workspace' => $lock])->allowed);
        $narrow = new ToolContext(principal: 'seat', channel: 'cli', scopes: ['plugins.config:write']);
        self::assertSame(
            "Missing required permission 'plugins:write' for the plugin lock.",
            $this->policy()->authorize($narrow, $this->tool('sandbox_promote'), ['workspace' => $lock])->reason,
        );

        $mixed = $this->trialThatWrites([
            self::REGISTRY => $this->registry(hello: false, core: true),
            'config/app.php' => "<?php return ['debug' => true];\n",
        ]);
        $verdict = $this->policy()->authorize($this->seat(), $this->tool('sandbox_promote'), ['workspace' => $mixed]);
        self::assertFalse($verdict->allowed, 'plugins:write names the registry and the lock, not the configuration');
        self::assertStringContainsString("Export 'config/app.php' is outside", (string) $verdict->reason);
    }

    /** @param array<string, string> $writes */
    private function trialThatWrites(array $writes): string
    {
        $workspace = TrialWorkspace::materialize($this->root, 'switch-' . bin2hex(random_bytes(3)), dirname(__DIR__, 2) . '/resources/trial-run.php');
        foreach ($writes as $path => $bytes) {
            file_put_contents($workspace->copy . '/' . $path, $bytes);
        }

        return $workspace->id;
    }

    /** What milpa/plugin's FilePluginRegistry writes for these two plugins. */
    private function registry(bool $hello, bool $core): string
    {
        $row = static fn (string $name, bool $enabled): array => ['name' => $name, 'version' => '1.0.0', 'enabled' => $enabled];

        return json_encode(['plugins' => [$row('Hello', $hello), $row('Core', $core)]], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** The resident's seat: the fixed list app-runtime grants it (decisions/0499). */
    private function seat(): ToolContext
    {
        return new ToolContext(principal: 'seat', channel: 'cli', scopes: ResidentSeat::SCOPES);
    }

    private function policy(): PluginAuthoringPolicy
    {
        return new PluginAuthoringPolicy($this->root);
    }

    private function tool(string $name): ToolDefinition
    {
        return new ToolDefinition($name, '', [], static fn (): null => null, mutating: true);
    }

    /** @return array{0: \Milpa\Command\Operation, 1: \Milpa\Command\Operation, 2: \Milpa\Command\Operation} promote, list, undo */
    private function doors(): array
    {
        $operations = (new TrialOperations($this->container(), root: $this->root, observer: null))->operations();

        return [$operations[0], $operations[1], $operations[3]];
    }

    private function runner(): OperationRunner
    {
        return new OperationRunner($this->container());
    }

    private function container(): \Milpa\Interfaces\Di\DIContainerInterface
    {
        $app = new Application($this->root);
        $kernel = (new \ReflectionMethod($app, 'kernel'))->invoke($app);
        PluginAuthoringPolicy::install($kernel->container(), $this->root);

        return $kernel->container();
    }
}
