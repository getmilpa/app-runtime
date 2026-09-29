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
use Milpa\AiGateway\OptionTable;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\LegMemory;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\FileEventStore;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\Gate\ToolCallRecorder;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A resident leg fits in its memory (greenhouse decisions/0511).
 *
 * Measured (evidence/1045): one leg of one step built EIGHT `FileEventStore`s over the same ledger — one per
 * `sessions()` question, and one more for the console's receipt — and each read the 21 MB session again and kept
 * its own copy: four alive at once, 123 MB of PHP's 128. And the process ran with whatever php.ini said.
 *
 * @guards every sessions() of a process composes over ONE log of a given ledger; the leg declares its memory limit
 *         before its first read and reports it
 *
 * @refuses sharing a log across two ledgers; lowering a limit that is higher, or unlimited
 *
 * @subject-in milpa/app-runtime
 */
final class ALegFitsInItsMemoryTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    private string $limit;

    protected function setUp(): void
    {
        $this->limit = (string) \ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->limit);
        foreach ($this->roots as $root) {
            @unlink($root . '/var/agent-sessions.jsonl');
            @rmdir($root . '/var');
            @rmdir($root);
        }
    }

    public function testEverySessionsOfTheProcessComposesOverOneLog(): void
    {
        $root = $this->house();

        $leg = $this->logOf(new AgentOperations($this->containerOver($root)));
        $again = $this->logOf(new AgentOperations($this->containerOver($root)));

        self::assertInstanceOf(FileEventStore::class, $leg);
        self::assertSame($leg, $again, 'the leg and the console\'s receipt — two AgentOperations — share one log');
    }

    public function testAnotherLedgerIsAnotherLog(): void
    {
        $first = $this->logOf(new AgentOperations($this->containerOver($this->house())));
        $other = $this->logOf(new AgentOperations($this->containerOver($this->house())));

        self::assertNotSame($first, $other, 'a log is never shared across two ledgers');
    }

    public function testWhatAnotherProcessWritesIsReadThroughTheSharedLog(): void
    {
        $root = $this->house();
        $operations = new AgentOperations($this->containerOver($root));
        $sessions = (new \ReflectionMethod($operations, 'sessions'))->invoke($operations);
        self::assertInstanceOf(SessionStore::class, $sessions);
        $sessions->start('s1', 'first goal');
        self::assertSame('first goal', $sessions->load('s1')?->goal);

        (new SessionStore(new FileEventStore($root . '/var/agent-sessions.jsonl')))->setGoal('s1', 'the goal another process set');

        $again = (new \ReflectionMethod($operations, 'sessions'))->invoke($operations);
        self::assertInstanceOf(SessionStore::class, $again);
        self::assertSame('the goal another process set', $again->load('s1')?->goal);
    }

    public function testTheLegDeclaresItsMemoryBeforeItRunsAndSaysSo(): void
    {
        ini_set('memory_limit', '128M');

        $result = $this->runAgent();

        self::assertSame(['declared' => '256M', 'before' => '128M', 'effective' => '256M'], $result['memoryLimit'] ?? null);
        self::assertSame('256M', \ini_get('memory_limit'));
    }

    public function testALegNeverLowersWhatTheProcessAlreadyHas(): void
    {
        ini_set('memory_limit', '1G');

        $result = $this->runAgent();

        self::assertSame(['declared' => '256M', 'before' => '1G', 'effective' => '1G'], $result['memoryLimit'] ?? null);
    }

    #[DataProvider('limits')]
    public function testOnlyALowerLimitIsRaised(string $current, bool $raised): void
    {
        self::assertSame($raised, LegMemory::raises($current, LegMemory::DECLARED));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function limits(): iterable
    {
        yield 'php\'s default' => ['128M', true];
        yield 'in bytes' => ['134217728', true];
        yield 'in kilobytes' => ['131072k', true];
        yield 'just under' => ['268435455', true];
        yield 'the same' => ['256M', false];
        yield 'more' => ['1G', false];
        yield 'no limit' => ['-1', false];
        yield 'unreadable' => ['', false];
    }

    public function testSizesReadLikePhpIni(): void
    {
        self::assertSame(256 * 1024 * 1024, LegMemory::bytes('256M'));
        self::assertSame(2 * 1024 * 1024 * 1024, LegMemory::bytes('2g'));
        self::assertSame(512 * 1024, LegMemory::bytes('512K'));
        self::assertSame(1000, LegMemory::bytes(' 1000 '));
        self::assertSame(0, LegMemory::bytes('-1'));
    }

    private function house(): string
    {
        $root = sys_get_temp_dir() . '/milpa-leg-memory-' . bin2hex(random_bytes(5));
        mkdir($root . '/var', 0o777, true);
        $this->roots[] = $root;

        return $root;
    }

    private function containerOver(string $root): DIContainer
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => $root,
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);

        return $container;
    }

    private function logOf(AgentOperations $operations): ?EventStoreInterface
    {
        (new \ReflectionMethod($operations, 'sessions'))->invoke($operations);
        $log = (new \ReflectionProperty(AgentOperations::class, 'sessionEvents'))->getValue($operations);

        return $log instanceof EventStoreInterface ? $log : null;
    }

    /** @return array<string, mixed> */
    private function runAgent(): array
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'do the work');
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(SessionStore::class, $store);
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);
        $operations = new MemoryReportingAgentOperations($container);

        $previousOpenAi = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=test-key');

        try {
            $result = null;
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    $result = ($operation->handler)(['prompt' => 'continue', 'session' => 's1']);
                }
            }
        } finally {
            $previousOpenAi === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previousOpenAi);
        }

        self::assertIsArray($result);
        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'agent run failed'));

        return $result;
    }
}

/** The provider call replaced with a no-op, so the run reaches its report without a model. */
final class MemoryReportingAgentOperations extends AgentOperations
{
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

        return 'done';
    }
}
