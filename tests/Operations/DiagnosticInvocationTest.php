<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\{AgentOrchestrator, LlmService, PlanBoard};
use Milpa\AppRuntime\Agent\{DiagnosticContract, ObservedExecutor, SessionProgressProbe};
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Tests\Agent\DiagnosticJudgeTest;
use Milpa\Container\DIContainer;
use Milpa\EventStore\{EventStoreInterface, InMemoryEventStore};
use Milpa\Runtime\{Config, Kernel};
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DiagnosticInvocationTest extends TestCase
{
    /** @return iterable<string,array{string,array<string,mixed>,string}> */
    public static function refusals(): iterable
    {
        $c = DiagnosticJudgeTest::criterion();
        yield 'output-null' => ['empty', ['diagnostic' => $c + ['output' => null]], 'output'];
        yield 'output-unavailable' => ['output-unsupported', ['diagnostic' => $c + ['output' => 'json_schema']], 'output'];
        yield 'invalid' => ['empty', ['diagnostic' => '{}'], 'diagnostic'];
        yield 'null' => ['empty', ['diagnostic' => null], 'diagnostic'];
        yield 'late' => ['late', ['diagnostic' => $c], 'before'];
        yield 'changed' => ['declared', ['diagnostic' => array_replace($c, ['path' => 'tests/another.json'])], 'immutable'];
        yield 'mixed' => ['empty', ['diagnostic' => $c, 'delivery' => '{}'], 'separate'];
        yield 'opaque-store' => ['opaque', ['diagnostic' => $c], 'durable'];
        yield 'unsupported-gateway' => ['unsupported', ['diagnostic' => $c], 'gateway'];
    }

    #[DataProvider('refusals')]
    public function testRefusalsPrecedeSessionChangesAndTheProvider(string $initial, array $fields, string $reason): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Diagnose');
        if ($initial === 'late') {
            $store->recordTurn('s', 'user', 'Earlier');
        }
        if ($initial === 'declared') {
            DiagnosticContract::record($events, 's', DiagnosticJudgeTest::criterion(), ObservedExecutor::unknown());
        }
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['agent' => ['baseUrl' => 'http://127.0.0.1:1', 'model' => 'fixture']]));
        $container->registerService($initial === 'opaque' ? SessionStore::class : EventStoreInterface::class, $initial === 'opaque' ? $store : $events);
        $ops = new class ($container, $initial !== 'unsupported', $initial !== 'output-unsupported') extends AgentOperations {
            public bool $called = false;
            public function __construct($container, private bool $supported, private bool $outputSupported)
            {
                parent::__construct($container);
            }
            protected function diagnosticOutputAvailable(): bool
            {
                return $this->outputSupported;
            }
            protected function orchestratorAdmitsAnswerJudge(): bool
            {
                return $this->supported;
            }
            protected function orchestrator(LlmService $llm, GatedToolCalls $tools, int $steps, ?PlanBoard $board, bool $lazy, ?SessionProgressProbe $probe): AgentOrchestrator
            {
                $this->called = true;
                throw new \LogicException('Provider reached');
            }
        };
        $op = current(array_filter($ops->operations(), static fn ($o) => $o->name === 'agent'));
        $before = $store->stream('s');
        $r = ($op->handler)(['session' => 's', 'prompt' => 'Diagnose', 'mode' => 'auto'] + $fields);
        self::assertFalse($r['ok']);
        self::assertStringContainsString($reason, $r['error']);
        self::assertSame($before, $store->stream('s'));
        self::assertFalse($ops->called);
    }

    public function testAnOverriddenFactoryCannotSilentlyDropTheDeclaredJudge(): void
    {
        $events = new InMemoryEventStore();
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(Config::class, new Config(['agent' => ['baseUrl' => 'http://127.0.0.1:1', 'model' => 'fixture']]));
        $kernel = Kernel::boot(['root' => dirname(__DIR__, 2), 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturn(['role' => 'assistant', 'content' => '{"required":"cedar","configured":"maple","matches":false}']);
        $ops = new class ($container, $llm) extends AgentOperations {
            public function __construct($container, private LlmService $fixture)
            {
                parent::__construct($container);
            }
            protected function orchestrator(LlmService $llm, GatedToolCalls $tools, int $steps, ?PlanBoard $board, bool $lazy, ?SessionProgressProbe $probe): AgentOrchestrator
            {
                return new AgentOrchestrator($this->fixture, $tools, $steps);
            }
        };
        $op = current(array_filter($ops->operations(), static fn ($o) => $o->name === 'agent'));
        $r = ($op->handler)(['session' => 's', 'prompt' => 'Diagnose', 'diagnostic' => DiagnosticJudgeTest::criterion(), 'first' => '', 'mode' => 'auto']);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('diagnostic', $r['error']);
        self::assertArrayNotHasKey('closure', $r);
    }
}
