<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Tests\Fixtures\AuthoringOperations;
use Milpa\Command\Operation;
use Milpa\Console\OperationBoundary;
use Milpa\Console\OperationRunner;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;

/** End-to-end operation boundary: trial, current-grant promotion, undo, and hostile exports. */
final class PluginAuthoringBoundaryTest extends TestCase
{
    private string $root;
    private PluginAuthoringPolicy $policy;
    private ToolContext $context;

    protected function setUp(): void
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('The kernel cannot impose the measured namespace.');
        }
        $this->root = sys_get_temp_dir() . '/milpa-authoring-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Plugins/Owned', 0o700, true);
        mkdir($this->root . '/src/Plugins/Other', 0o700, true);
        mkdir($this->root . '/vendor');
        mkdir($this->root . '/config');
        file_put_contents($this->root . '/vendor/autoload.php', '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => new \\Milpa\\Container\\DIContainer(), "plugins" => []];');
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/config/operations.php', '<?php return [\\Milpa\\AppRuntime\\Tests\\Fixtures\\AuthoringOperations::class];');
        $this->policy = new PluginAuthoringPolicy($this->root);
        $this->context = new ToolContext(principal: 'worker', channel: 'cli', scopes: ['plugins.Owned:write']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function make(array $extra = []): array
    {
        return $this->policy->execute(
            (new AuthoringOperations())->operations()[0],
            ['plugin' => 'Owned'] + $extra,
            $this->context,
            static fn () => self::fail('The unrestricted host handler must not run')
        );
    }

    public function testActualPromotionAndUndoUseTheCurrentGrant(): void
    {
        $made = $this->make();
        self::assertTrue($made['ok']);
        self::assertTrue($made['ran_in_trial']);
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Owned/control.txt');
        $app = new Application($this->root);
        $kernel = (new \ReflectionMethod($app, 'kernel'))->invoke($app);
        PluginAuthoringPolicy::install($kernel->container(), $this->root);
        self::assertSame($kernel->container()->get(CallPolicy::class), $kernel->container()->get(OperationBoundary::class));
        $operations = (new TrialOperations($kernel->container(), root: $this->root))->operations();
        $runner = new OperationRunner($kernel->container());
        $input = ['workspace' => $made['workspace']];
        try {
            $runner->run($operations[0], $input, 'cli', authority: new ToolContext(scopes: []));
            self::fail('a revoked grant must not promote the old trial');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Missing required permission', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Owned/control.txt');
        self::assertTrue($runner->run($operations[0], $input, 'cli', authority: $this->context)['ok']);
        self::assertSame('owned', file_get_contents($this->root . '/src/Plugins/Owned/control.txt'));
        try {
            $runner->run($operations[3], $input, 'cli', authority: new ToolContext(scopes: []));
            self::fail('a revoked grant must not undo');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Missing required permission', $error->getMessage());
        }
        self::assertTrue($runner->run($operations[3], $input, 'cli', authority: $this->context)['ok']);
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Owned/control.txt');
    }

    public function testFailedAndEmptyTrialsDoNotAdvertisePromotion(): void
    {
        $failed = $this->make(['fail' => true]);
        self::assertFalse($failed['ok']);
        self::assertSame('fixture refused its behavior', $failed['error']);
        self::assertArrayNotHasKey('to_apply', $failed);
        $empty = $this->make(['empty' => true]);
        self::assertTrue($empty['ok']);
        self::assertSame([], $empty['changed']);
        self::assertArrayNotHasKey('to_apply', $empty);
        $read = new Operation('read', '', static fn (): string => 'read');
        self::assertSame('read', $this->policy->execute($read, [], $this->context, static fn (): string => 'read'));
        self::assertSame('local', $this->policy->execute($read, [], null, static fn (): string => 'local'));
    }

    public function testAPermittedButUnconfineableOperationCannotFallBackToTheHost(): void
    {
        $operation = new Operation('make', '', static fn () => self::fail('no fallback'), mutating: true, requiresConfirmation: true);
        $this->expectExceptionMessage('cannot run in a confined trial');
        $this->policy->execute($operation, ['plugin' => 'Owned'], $this->context, static fn () => self::fail('no fallback'));
    }

    public function testAnAuthorizedTrialCanBeDiscardedButNotExportForeignFilesOrLinks(): void
    {
        $made = $this->make();
        $workspace = TrialWorkspace::open($this->root, $made['workspace']);
        self::assertNotNull($workspace);
        $discard = new ToolDefinition('sandbox_discard', '', [], static fn (): null => null, mutating: true);
        self::assertTrue($this->policy->authorize($this->context, $discard, ['workspace' => $workspace->id])->allowed);
        self::assertFalse($this->policy->authorize(new ToolContext(scopes: []), $discard, ['workspace' => $workspace->id])->allowed);
        $promote = new ToolDefinition('sandbox_promote', '', [], static fn (): null => null, mutating: true);
        file_put_contents($workspace->copy . '/src/Plugins/Other/out.txt', 'outside');
        self::assertFalse($this->policy->authorize($this->context, $promote, ['workspace' => $workspace->id])->allowed);
        unlink($workspace->copy . '/src/Plugins/Other/out.txt');
        symlink($this->root . '/config/app.php', $workspace->copy . '/src/Plugins/Owned/alias.php');
        self::assertStringContainsString('symbolic link', (string) $this->policy->authorize($this->context, $promote, ['workspace' => $workspace->id])->reason);
        unlink($workspace->copy . '/src/Plugins/Owned/alias.php');
        file_put_contents($workspace->copy . '/foreign.txt', 'outside');
        self::assertStringContainsString('outside a plugin', (string) $this->policy->authorize($this->context, $promote, ['workspace' => $workspace->id])->reason);
        foreach (['../escape', 'missing'] as $id) {
            self::assertFalse($this->policy->authorize($this->context, $promote, ['workspace' => $id])->allowed);
            $undo = new ToolDefinition('sandbox_undo', '', [], static fn (): null => null, mutating: true);
            self::assertFalse($this->policy->authorize($this->context, $undo, ['workspace' => $id])->allowed);
        }
    }
}
