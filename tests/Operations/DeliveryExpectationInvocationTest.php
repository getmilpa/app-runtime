<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\{DeliveryExpectation, ObservedExecutor};
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Tests\Agent\{DeliveryExpectationTest, DeliveryScopeTest};
use Milpa\Container\DIContainer;
use Milpa\EventStore\{EventStoreInterface, InMemoryEventStore};
use Milpa\Runtime\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Refusal belongs before mode changes, session turns and provider construction. */
final class DeliveryExpectationInvocationTest extends TestCase
{
    /** @return iterable<string,array{string,array<string,mixed>,string}> */
    public static function refusals(): iterable
    {
        yield 'invalid-expectation' => ['empty', ['expectation' => '{}'], 'expectation'];
        yield 'null-expectation' => ['empty', ['expectation' => null], 'expectation'];
        yield 'mixed-inputs' => ['empty', ['expectation' => DeliveryExpectationTest::target(), 'delivery' => DeliveryScopeTest::scope()], 'mix'];
        yield 'invalid-candidate' => ['empty', ['deliveryCandidate' => false], 'workspace'];
        yield 'late-expectation' => ['turn', ['expectation' => DeliveryExpectationTest::target()], 'before'];
        $changed = DeliveryExpectationTest::target();
        $changed['test']['filter'] = 'OnlyOne';
        yield 'changed-expectation' => ['expected', ['expectation' => $changed], 'immutable'];
        yield 'legacy-bypass' => ['expected', ['delivery' => DeliveryScopeTest::scope()], 'mix'];
        yield 'store-without-ledger' => ['opaque', ['expectation' => DeliveryExpectationTest::target()], 'event store'];
    }

    /** No rejected input may append a turn, alter the mode or reach the provider factory. */
    #[DataProvider('refusals')]
    public function testRefusedInputDoesNotMutateOrReachProvider(string $initial, array $fields, string $reason): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        if ($initial !== 'empty') {
            $sessions->start('s', 'Build');
        }
        if ($initial === 'expected') {
            DeliveryExpectation::record($events, 's', DeliveryExpectationTest::target(), ObservedExecutor::unknown());
        }
        if ($initial === 'turn') {
            $sessions->recordTurn('s', 'user', 'An earlier attempt');
        }
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['agent' => ['baseUrl' => 'http://127.0.0.1:1', 'model' => 'fixture']]));
        if ($initial === 'opaque') {
            $container->registerService(SessionStore::class, $sessions);
        } else {
            $container->registerService(EventStoreInterface::class, $events);
        }
        $ops = new class ($container) extends AgentOperations {
            public bool $reachedProvider = false;
            protected function orchestrator(\Milpa\AiGateway\LlmService $modeloRemoto, \Milpa\ToolRuntime\Gate\GatedToolCalls $cliente, int $pasos, ?\Milpa\AiGateway\PlanBoard $tablero, bool $lazyTools, ?\Milpa\AppRuntime\Agent\SessionProgressProbe $sonda): \Milpa\AiGateway\AgentOrchestrator
            {
                $this->reachedProvider = true;
                throw new \LogicException('Unexpected provider factory');
            }
        };
        $operation = current(array_filter($ops->operations(), static fn ($op) => $op->name === 'agent'));
        $before = $sessions->stream('s');
        $result = ($operation->handler)(['prompt' => 'Continue', 'session' => 's', 'mode' => 'auto'] + $fields);
        self::assertFalse($result['ok']);
        self::assertStringContainsString($reason, $result['error']);
        self::assertSame($before, $sessions->stream('s'));
        self::assertFalse($ops->reachedProvider);
    }
}
