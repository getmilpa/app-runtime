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
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\ConfinedWork;
use Milpa\AppRuntime\Agent\HouseWork;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A leg of the house runs work in the house (greenhouse decisions/0588): its trial layer, its gate and its receipt
 * are handed the same work layer — or none of them is.
 *
 * @guards a leg of a house with trials hands its trial layer the work layer and what runs a call confined; a house
 *         that switched confinement off runs everything as before; work runs in the house only over a session
 *         store whose receipt can say where an execution ran
 *
 * @refuses running work in the house where the house could not record that it did
 *
 * @subject-in milpa/app-runtime
 */
final class ALegRunsWorkInTheHouseTest extends TestCase
{
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';

    private string $root;
    private string $bwrap;

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/milpa-work-leg-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var', 0o755, true);
        $this->bwrap = $this->root . '-bwrap';
        file_put_contents($this->bwrap, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($this->bwrap, 0o755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->bwrap);
    }

    public function testALegHandsItsTrialLayerTheWorkLayer(): void
    {
        self::needsAReceiptThatSaysWhereItRan();
        $loop = $this->leg();

        $registry = self::registryOf($loop);
        self::assertInstanceOf(TrialAwareRegistry::class, $registry);
        $router = (new \ReflectionProperty(TrialAwareRegistry::class, 'router'))->getValue($registry);
        self::assertInstanceOf(HouseWork::class, (new \ReflectionProperty(TrialRouter::class, 'work'))->getValue($router), 'no trial is planned for work');
        self::assertInstanceOf(ConfinedWork::class, (new \ReflectionProperty(TrialAwareRegistry::class, 'confinedWork'))->getValue($registry), 'and there is what runs it in the house');
        self::assertSame($work = (new \ReflectionProperty(TrialRouter::class, 'work'))->getValue($router), self::workOfTheGate($loop), 'the gate judges with the SAME work layer the registry runs');
        self::assertInstanceOf(HouseWork::class, $work);
        self::assertNotNull((new \ReflectionProperty(\Milpa\AppRuntime\Agent\ConsentBridge::class, 'landed'))->getValue($loop->door), 'and the receipt can ask what the house saw');
    }

    public function testAHouseThatSwitchedConfinementOffRunsEverythingAsBefore(): void
    {
        $loop = $this->leg(config: ['agent' => ['trialWorkspace' => false]]);

        self::assertNotInstanceOf(TrialAwareRegistry::class, self::registryOf($loop));
        self::assertNull(self::workOfTheGate($loop), 'and its gate judges as before');
        self::assertNull((new \ReflectionProperty(\Milpa\AppRuntime\Agent\ConsentBridge::class, 'landed'))->getValue($loop->door));
    }

    public function testWorkRunsInTheHouseOnlyOverAStoreThatCanSayWhereItRan(): void
    {
        $withoutTheFact = new class () {
            public function recordExecution(string $id, string $operation, ?Principal $executedBy, string $executorSource, ?array $authorizedBy, string $argumentsDigest): void
            {
            }
        };

        self::assertFalse(HouseWork::canBeRecordedBy($withoutTheFact), 'a store that keeps only «it executed»');
        self::assertFalse(HouseWork::canBeRecordedBy(new \stdClass()));
        $withTheFact = new class () {
            public function recordExecution(string $id, string $operation, ?Principal $executedBy, string $executorSource, ?array $authorizedBy, string $argumentsDigest, ?array $landed = null): void
            {
            }
        };
        self::assertTrue(HouseWork::canBeRecordedBy($withTheFact));
    }

    public function testOverAStoreThatCannotSayWhereItRanTheLegIsWhatItWas(): void
    {
        if (HouseWork::canBeRecordedBy(new SessionStore(new InMemoryEventStore()))) {
            self::markTestSkipped('the milpa/agent installed here can say where an execution ran');
        }
        $registry = self::registryOf($this->leg());

        self::assertInstanceOf(TrialAwareRegistry::class, $registry);
        $router = (new \ReflectionProperty(TrialAwareRegistry::class, 'router'))->getValue($registry);
        self::assertNull((new \ReflectionProperty(TrialRouter::class, 'work'))->getValue($router), 'work is rehearsed, as before');
    }

    /** The receipt's facts ride what milpa/agent adds to the execution fact: over an older one there is nothing to read. */
    private static function needsAReceiptThatSaysWhereItRan(): void
    {
        if (! HouseWork::canBeRecordedBy(new SessionStore(new InMemoryEventStore()))) {
            self::markTestSkipped('the milpa/agent installed here keeps only «it executed»: where an execution ran rides the receipt its next release adds');
        }
    }


    /** The work layer the leg's gate judges with. */
    private static function workOfTheGate(LoopThatAnswersForWork $loop): ?HouseWork
    {
        $gate = (new \ReflectionProperty(\Milpa\AppRuntime\Agent\ConsentBridge::class, 'gate'))->getValue($loop->door);
        self::assertInstanceOf(\Milpa\AppRuntime\Agent\SessionToolGate::class, $gate);

        return (new \ReflectionProperty(\Milpa\AppRuntime\Agent\SessionToolGate::class, 'houseWork'))->getValue($gate);
    }

    private static function registryOf(LoopThatAnswersForWork $loop): ?ToolRegistry
    {
        return $loop->door === null ? null : (new \ReflectionProperty(GatedToolCalls::class, 'registry'))->getValue($loop->door);
    }

    /** @param array<string, mixed> $config */
    private function leg(array $config = []): LoopThatAnswersForWork
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Lend the drill', AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, true));
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(Config::class, new Config($config));
        $container->registerService(Kernel::class, Kernel::boot(['root' => $this->root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]));
        $operations = new WorkFixtureOperations($container);
        if (($config['agent']['trialWorkspace'] ?? null) !== false) {
            (new \ReflectionProperty(AgentOperations::class, 'trialRouterMemo'))->setValue(
                $operations,
                new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php'),
            );
        }
        $loop = new LoopThatAnswersForWork($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class));
        $operations->loop = $loop;
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    $result = ($operation->handler)(['prompt' => 'continue', 'session' => 'bv'], new InvocationContext('key:' . self::SEAT, true));
                    self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'the leg failed'));
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }

        return $loop;
    }
}

/** A leg whose orchestrator is the test's. */
final class WorkFixtureOperations extends AgentOperations
{
    public ?LoopThatAnswersForWork $loop = null;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->loop !== null);
        $this->loop->door = $cliente;

        return $this->loop;
    }
}

/** A loop that answers and remembers the door it was given. */
final class LoopThatAnswersForWork extends AgentOrchestrator
{
    public ?GatedToolCalls $door = null;

    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        return 'Continued.';
    }
}
