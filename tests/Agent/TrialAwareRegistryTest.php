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
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The executor asks the SAME router the gate asked (greenhouse decisions/0069 §11): with a plan the
 * call runs in the trial and leaves `session.trial_run_recorded`; without one it reaches the
 * registered tool untouched. The host does not change either way (B1).
 */
final class TrialAwareRegistryTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    /** @var list<string> */
    private array $stubs = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
        foreach ($this->stubs as $stub) {
            @unlink($stub);
        }
    }

    public function testACallWithAPlanRunsInTheTrialBranchAndIsRecorded(): void
    {
        // A fake bwrap that ignores its flags and execs the command: it proves the DECORATOR routes a
        // planned call into the runner and records it, without needing a namespace the CI lacks. The
        // CONFINEMENT itself — host untouched, network unreachable — is the next test, under real bwrap.
        $runner = new TrialRunner(bwrap: $this->fakeExecBwrap());
        $root = $this->root();
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s-1', 'goal', AutonomyMode::Ask);
        $llamadas = 0;
        $registro = $this->registry($root, $runner, $almacen, $llamadas);

        $result = $registro->call('config_set', ['key' => 'a']);

        self::assertTrue($result->success, (string) $result->error);
        self::assertSame(0, $llamadas, 'the registered handler did not run on the host: the trial did');
        self::assertIsArray($result->data);
        // THE RESULT TELLS THE AGENT IT RAN IN A TRIAL AND HOW TO APPLY IT — else the agent reports
        // false success and the human's intent is silently dropped into a disposable copy (measured
        // on llama.local, greenhouse evidence/0274).
        self::assertTrue($result->data['ran_in_trial'] ?? null);
        self::assertFalse($result->data['applied'] ?? null, 'a trial is not applied until it is promoted');
        self::assertSame('added', $result->data['changed']['touched.txt'] ?? null, 'the agent sees WHAT the trial changed');
        self::assertSame('sandbox:promote', $result->data['to_apply']['operation'] ?? null);
        self::assertSame($result->data['workspace'], $result->data['to_apply']['arguments']['workspace'] ?? null);
        self::assertSame('sandbox:discard', $result->data['to_discard']['operation'] ?? null);
        self::assertStringContainsString('sandbox:promote', (string) ($result->data['note'] ?? ''));
        self::assertSame('config:set', $result->data['output']['operation'] ?? null, 'the operation output is kept under `output`');
        self::assertIsArray($result->meta['trial'] ?? null);
        self::assertSame(0, $result->meta['trial']['exit']);
        self::assertArrayHasKey('touched.txt', $result->meta['trial']['report']);

        $hechos = array_values(array_filter($eventos->replay('agent-session:s-1'), static fn ($e): bool => $e->type === 'session.trial_run_recorded'));
        self::assertCount(1, $hechos);
        self::assertSame($result->meta['trial']['workspace'], $hechos[0]->payload['workspace']);
        self::assertSame('config:set', $hechos[0]->payload['operation']);
        self::assertSame(['fs' => 'ro-root+rw-copy', 'net' => 'unshared', 'pid' => 'unshared'], $hechos[0]->payload['bounds']);
        self::assertSame(0, $hechos[0]->payload['exit']);
        self::assertSame('added', $hechos[0]->payload['report']['touched.txt']['status']);
        self::assertNotEmpty($hechos[0]->payload['arguments_digest']);
    }

    public function testACallWithoutAPlanReachesTheRegisteredToolUntouched(): void
    {
        $root = $this->root();
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s-1', 'goal', AutonomyMode::Ask);
        $llamadas = 0;
        $registro = $this->registry($root, new TrialRunner(bwrap: '/nonexistent/bwrap'), $almacen, $llamadas);

        $result = $registro->call('config_set', ['key' => 'a']);

        self::assertTrue($result->success);
        self::assertSame(1, $llamadas, 'no sandbox, no plan: the tool itself ran');
        self::assertSame('a', $result->data['written']['key'], 'the registered tool ran on the host with the call args');
        self::assertArrayNotHasKey('trial', $result->meta);
    }

    public function testTheDecoratorForwardsEveryPublicMethodOfTheRegistry(): void
    {
        $propios = [];
        foreach ((new \ReflectionClass(TrialAwareRegistry::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === TrialAwareRegistry::class) {
                $propios[] = $m->getName();
            }
        }
        $faltan = [];
        foreach ((new \ReflectionClass(ToolRegistry::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if (! $m->isStatic() && $m->getName() !== '__construct' && ! \in_array($m->getName(), $propios, true)) {
                $faltan[] = $m->getName();
            }
        }

        self::assertSame([], $faltan, 'a decorator that forgets a method answers from an empty registry');
    }

    public function testEveryForwardingMethodAnswersFromTheWrappedRegistry(): void
    {
        $root = $this->root();
        $llamadas = 0;
        $registro = $this->registry($root, new TrialRunner(bwrap: '/nonexistent/bwrap'), null, $llamadas);

        // register goes through: the tool lands in the inner registry, and every reader sees it
        $registro->register('extra_tool', 'another', ['type' => 'object'], static fn (): array => ['ok' => true]);
        self::assertTrue($registro->has('extra_tool'));
        self::assertNotNull($registro->getDefinition('extra_tool'));
        self::assertCount(2, $registro->getToolDefinitions());
        self::assertCount(2, $registro->getToolSummaries());
        self::assertNotEmpty($registro->getToolsByScopes([]));
        self::assertIsArray($registro->getToolsByPrefix('config'));
        self::assertIsArray($registro->getToolsWithinBudget('gpt-4'));
        self::assertIsString($registro->getTokenUsageReport('gpt-4'));
        self::assertIsArray($registro->estimateTokens());
        self::assertIsArray($registro->checkTokenBudget('gpt-4'));
        self::assertInstanceOf(\Milpa\ToolRuntime\TokenEstimator::class, $registro->getTokenEstimator());
        self::assertIsBool($registro->hasRateLimiter());
        self::assertIsBool($registro->hasDispatcher());
        self::assertInstanceOf(\Milpa\ToolRuntime\PolicyGate::class, $registro->getPolicyGate());
        self::assertInstanceOf(\Milpa\ToolRuntime\ConfirmationTokenStore::class, $registro->getConfirmationStore());

        $limiter = new class () implements \Milpa\ToolRuntime\RateLimiting\RateLimiterInterface {
            public function consume(string $key, int $cost = 1, int $windowSeconds = 60, int $maxTokens = 100): \Milpa\ToolRuntime\RateLimiting\RateLimitResult
            {
                return new \Milpa\ToolRuntime\RateLimiting\RateLimitResult(true, $maxTokens, 0, 0);
            }

            public function getUsage(string $key): int
            {
                return 0;
            }

            public function reset(string $key): void
            {
            }
        };
        $registro->setRateLimiter($limiter);
        self::assertSame($limiter, $registro->getRateLimiter());
    }

    public function testLookupsAnswerFromTheWrappedRegistry(): void
    {
        $root = $this->root();
        $llamadas = 0;
        $registro = $this->registry($root, new TrialRunner(bwrap: '/nonexistent/bwrap'), null, $llamadas);

        self::assertTrue($registro->has('config_set'));
        self::assertNotNull($registro->getDefinition('config_set'));
        self::assertCount(1, $registro->getToolDefinitions());
    }

    public function testAConfinedCallLeavesTheHostUntouchedAndReachesNoNetwork(): void
    {
        $runner = new TrialRunner();
        if (! $runner->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $root = $this->root();
        $llamadas = 0;
        $registro = $this->registry($root, $runner, null, $llamadas);

        $result = $registro->call('config_set', ['key' => 'a', 'host' => $root]);

        self::assertTrue($result->success, (string) $result->error);
        self::assertFileDoesNotExist($root . '/host-touched.txt', 'B1: the host tree is untouched');
        self::assertFalse($result->data['output']['net'] ?? true, 'the trial reached no network');
        self::assertSame(0, $llamadas);
    }

    private function fakeExecBwrap(): string
    {
        $path = sys_get_temp_dir() . '/fake-exec-bwrap-' . bin2hex(random_bytes(4));
        // Skip every bwrap flag up to `--`, then exec the command that follows.
        file_put_contents($path, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($path, 0o755);
        $this->stubs[] = $path;

        return $path;
    }

    private function registry(string $root, TrialRunner $runner, ?SessionStore $almacen, int &$llamadas): TrialAwareRegistry
    {
        $interno = new ToolRegistry(new NullLogger());
        $interno->register(
            'config_set',
            'sets a key',
            ['type' => 'object'],
            static function (array $args) use (&$llamadas, $root): array {
                ++$llamadas;
                file_put_contents($root . '/host-touched.txt', "x\n");

                return ['written' => $args];
            },
        );
        $op = new Operation(
            name: 'config:set',
            description: 'sets a key',
            handler: static fn (array $i): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Compensatable,
                authority: Authority::WriteAsUser,
                subject: Subject::Configuration,
            ),
        );
        $router = new TrialRouter($root, $runner, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');

        return new TrialAwareRegistry($interno, $router, [$op], $almacen, $almacen === null ? null : 's-1');
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-trial-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        $this->roots[] = $root;

        return $root;
    }

    private static function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }
}
