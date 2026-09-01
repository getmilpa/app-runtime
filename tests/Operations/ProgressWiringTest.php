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

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\OptionTable;
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\AppRuntime\Agent\DebtSignal;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * How a leg that ended on the forced choice SURFACES (greenhouse decisions/0185): the stalled
 * sentinel becomes an honest named end — `ok:true`, never an error — with the receipt attached,
 * and a `HOUSE_DEBT:` declaration is recorded as a {@see DebtSignal::FRAMEWORK_GAP} signal before
 * the answer reaches whoever asked.
 */
final class ProgressWiringTest extends TestCase
{
    private InMemoryEventStore $events;
    private SessionStore $store;

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function runAgent(AgentOperations $operations, array $input): array
    {
        $previousOpenAi = getenv('OPENAI_API_KEY');
        $previousAnthropic = getenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY=test-key');
        putenv('ANTHROPIC_API_KEY');

        try {
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    return ($operation->handler)($input);
                }
            }
        } finally {
            $previousOpenAi === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previousOpenAi);
            $previousAnthropic === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $previousAnthropic);
        }

        self::fail('the agent operation was not offered');
    }

    private function operationsAnswering(string $respuesta): AgentOperations
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s1', 'close the deadlock');

        $container = new DIContainer();
        $container->registerService(SessionStore::class, $this->store);
        // The captured-store seam: the debt signal and the stall fact append to the SAME stream
        // the session writes, so the store must be reachable the way a composed app reaches it.
        $container->registerService(\Milpa\EventStore\EventStoreInterface::class, $this->events);
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);

        $operations = new ScriptedAnswerAgentOperations($container);
        $operations->respuesta = $respuesta;

        return $operations;
    }

    /**
     * The stalled sentinel surfaces exactly like STEPS_EXHAUSTED does: `ok:true`, the stall NAMED,
     * the receipt attached, and an answer a human can read — an honest end, not an error.
     */
    public function testTheStalledSentinelSurfacesAsAnHonestNamedEnd(): void
    {
        $receipt = ['fromSeq' => 3, 'toSeq' => 19, 'calls' => 4, 'newFacts' => 4, 'newArtifacts' => 0,
            'newEvidence' => 0, 'closedTodos' => 0, 'newHouseDebt' => 0, 'progress' => 'stalled'];
        $operations = $this->operationsAnswering(
            AgentOrchestrator::PROGRESS_STALLED . "\n" . json_encode(['receipt' => $receipt]),
        );

        $result = $this->runAgent($operations, ['prompt' => 'continue', 'session' => 's1']);

        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'the run failed'));
        self::assertTrue($result['stalled'] ?? false, 'the stall is named, never inferred from a string');
        self::assertSame($receipt, $result['receipt'], 'the receipt travels to the surface');
        self::assertNotSame(AgentOrchestrator::PROGRESS_STALLED, $result['answer'], 'the sentinel is not painted as the answer');
        self::assertArrayNotHasKey('closure', $result, 'a stalled end is not a natural end: no closure verdict');
    }

    /**
     * A `HOUSE_DEBT:` final answer records a FRAMEWORK_GAP debt signal — digest, never the raw
     * prose — on the session's own stream, and the declaration still reaches whoever asked.
     */
    public function testAHouseDebtAnswerRecordsAFrameworkGapSignalAndSurfaces(): void
    {
        $declaration = "HOUSE_DEBT: the judge cannot verify a target that boots the judge\nLong "
            . 'trailing prose that must never travel into the signal. ' . str_repeat('x', 500);
        $operations = $this->operationsAnswering($declaration);

        $result = $this->runAgent($operations, ['prompt' => 'continue', 'session' => 's1']);

        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'the run failed'));
        self::assertSame($declaration, $result['answer'], 'the declaration surfaces verbatim');
        self::assertTrue($result['houseDebt'] ?? false, 'the debt is named for the surface');

        $signals = array_values(array_filter(
            $this->events->replay(SessionStore::PREFIX . 's1'),
            static fn (object $event): bool => $event->type === DebtSignal::EVENT,
        ));
        self::assertCount(1, $signals);
        self::assertSame(DebtSignal::FRAMEWORK_GAP, $signals[0]->payload['signal']);
        $summary = (string) $signals[0]->payload['context']['summary'];
        self::assertSame('the judge cannot verify a target that boots the judge', $summary, 'first line only: a digest, never the prose');
    }

    /**
     * The probe is BUILT from the captured seam — session id plus event store — and only from it:
     * without a session there is nothing to measure, and every guard fails toward the
     * byte-identical default path.
     */
    public function testTheProbeIsBuiltFromTheCapturedSeamAndOnlyFromIt(): void
    {
        $operations = $this->operationsAnswering('irrelevant');

        $probeOf = static function (AgentOperations $ops, ?string $session, ?object $events): ?object {
            $p = new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos');
            $p->setValue($ops, $session);
            $e = new \ReflectionProperty(AgentOperations::class, 'sessionEvents');
            $e->setValue($ops, $events);
            $m = new \ReflectionMethod(AgentOperations::class, 'progressProbe');

            return $m->invoke($ops);
        };

        self::assertInstanceOf(
            \Milpa\AppRuntime\Agent\SessionProgressProbe::class,
            $probeOf($operations, 's1', $this->events),
            'session and store captured: the probe exists',
        );
        self::assertNull($probeOf($operations, null, $this->events), 'no session, nothing to measure');
        self::assertNull($probeOf($operations, 's1', null), 'no captured store, nowhere to read or record');
    }

    /** An ordinary answer records no framework-gap signal: the marker decides, nothing else. */
    public function testAnOrdinaryAnswerRecordsNoFrameworkGapSignal(): void
    {
        $operations = $this->operationsAnswering('done, and here is the summary');

        $result = $this->runAgent($operations, ['prompt' => 'continue', 'session' => 's1']);

        self::assertTrue($result['ok'] ?? false);
        self::assertArrayNotHasKey('stalled', $result);
        self::assertArrayNotHasKey('houseDebt', $result);
        $signals = array_values(array_filter(
            $this->events->replay(SessionStore::PREFIX . 's1'),
            static fn (object $event): bool => $event->type === DebtSignal::EVENT,
        ));
        self::assertSame([], $signals);
    }
}

/** The network seam replaced by a scripted final answer. */
final class ScriptedAnswerAgentOperations extends AgentOperations
{
    public string $respuesta = '';

    protected function ask(
        string $prompt,
        int $pasos,
        ToolRegistry $registry,
        string $proveedor,
        string $llave,
        string $modelo,
        callable $onStep,
        array $history = [],
        ?ToolCallGate $gate = null,
        ?OptionTable $mesa = null,
        ?ToolCallRecorder $recorder = null,
        ?PlanBoard $tablero = null,
    ): string {
        $onStep();

        return $this->respuesta;
    }
}
