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

    public function testRealMultipartImplementationKeepsPhpIntactUntilVerifiedPromotion(): void
    {
        file_put_contents($this->root . '/config/operations.php', '<?php return [\\Milpa\\DevTools\\Operations\\DevToolsOperations::class];');
        // This miniature fixture shares the package autoloader; bind its own root explicitly.
        file_put_contents($this->root . '/config/boot.php', <<<'PHP'
<?php
$container = new \Milpa\Container\DIContainer();
$container->registerService(\Milpa\DevTools\Support\RootResolver::class, new \Milpa\DevTools\Support\RootResolver(dirname(__DIR__)));
return ['container' => $container, 'plugins' => []];
PHP);
        $file = $this->root . '/src/Plugins/Owned/PartsProbe.php';
        $initial = "<?php\ndeclare(strict_types=1);\nnamespace App\\Plugins\\Owned;\nfinal class PartsProbe { public function answer(): int { return 0; } }\n";
        file_put_contents($file, $initial);
        $operations = (new \Milpa\DevTools\Operations\DevToolsOperations())->operations();
        $implement = array_values(array_filter($operations, static fn ($op) => $op->name === 'implement'))[0];
        $invoke = fn (array $input): array => $this->policy->execute($implement, ['plugin' => 'Owned', 'class' => 'PartsProbe'] + $input, $this->context, static fn () => self::fail('no host fallback'));
        $app = new Application($this->root);
        $kernel = (new \ReflectionMethod($app, 'kernel'))->invoke($app);
        PluginAuthoringPolicy::install($kernel->container(), $this->root);
        $promote = (new TrialOperations($kernel->container(), root: $this->root))->operations()[0];
        $runner = new OperationRunner($kernel->container());

        $head = "<?php\ndeclare(strict_types=1);\nnamespace App\\Plugins\\Owned;\nfinal class PartsProbe {\n/* ";
        $started = $invoke(['mode' => 'start', 'content' => $head]);
        self::assertTrue($started['ok'], json_encode($started));
        self::assertSame(['src/Plugins/Owned/PartsProbe.php.milpa-part' => 'added'], $started['changed']);
        self::assertArrayNotHasKey('verified', $started['output']);
        self::assertSame($initial, file_get_contents($file));
        self::assertFileDoesNotExist($file . '.milpa-part');
        try {
            $runner->run($promote, ['workspace' => $started['workspace']], 'cli', authority: new ToolContext(scopes: []));
            self::fail('revocation must refuse even a non-executable partial');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Missing required permission', $error->getMessage());
        }
        self::assertFileDoesNotExist($file . '.milpa-part');
        self::assertTrue($runner->run($promote, ['workspace' => $started['workspace']], 'cli', authority: $this->context)['ok']);
        self::assertSame($head, file_get_contents($file . '.milpa-part'));

        $bad = $invoke(['mode' => 'finish']);
        self::assertFalse($bad['ok']);
        self::assertArrayNotHasKey('to_apply', $bad);
        self::assertSame($initial, file_get_contents($file));
        self::assertSame($head, file_get_contents($file . '.milpa-part'));

        $tail = str_repeat('comment ', 600) . "*/\npublic function answer(): int { return 42; }\n}\n";
        foreach (str_split($tail, 2000) as $section) {
            $appended = $invoke(['mode' => 'append', 'content' => $section]);
            self::assertTrue($appended['ok'], json_encode($appended));
            self::assertTrue($runner->run($promote, ['workspace' => $appended['workspace']], 'cli', authority: $this->context)['ok']);
            self::assertSame($initial, file_get_contents($file));
        }
        self::assertGreaterThan(4000, strlen((string) file_get_contents($file . '.milpa-part')));
        $finished = $invoke(['mode' => 'finish']);
        self::assertTrue($finished['ok'], json_encode($finished));
        self::assertSame($initial, file_get_contents($file));
        self::assertTrue($runner->run($promote, ['workspace' => $finished['workspace']], 'cli', authority: $this->context)['ok']);
        self::assertFileDoesNotExist($file . '.milpa-part');
        self::assertSame($head . $tail, file_get_contents($file));
        require $file;
        $class = 'App\\Plugins\\Owned\\PartsProbe';
        self::assertSame(42, (new $class())->answer());
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
