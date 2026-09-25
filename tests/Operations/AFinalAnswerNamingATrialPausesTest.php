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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The WIRING of greenhouse decisions/0473: a real `agent` run whose final answer names a living trial of
 * its session ends PAUSED on the promotion question. The gate-level test proves the question; this proves
 * the run asks it — a method nobody calls would keep that test green.
 */
final class AFinalAnswerNamingATrialPausesTest extends TestCase
{
    private const WS = 'wfa12122ae160bf7d';

    private string $root = '';

    protected function setUp(): void
    {
        if (! (new TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $this->root = sys_get_temp_dir() . '/milpa-prose-run-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o777, true);
        mkdir($this->root . '/config');
        file_put_contents($this->root . '/src/A.php', "<?php // a\n");
        // The trial operations, declared the way an app declares them: without them the gate cannot judge
        // a promotion at all, and a refusal is not a question.
        file_put_contents($this->root . '/config/operations.php', "<?php\nreturn [\\Milpa\\AppRuntime\\Operations\\TrialOperations::class];\n");
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->root);
        }
    }

    public function testAnAnswerThatAsksToPromoteInProseEndsPausedOnTheRealQuestion(): void
    {
        $ws = TrialWorkspace::materialize($this->root, self::WS, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($ws->copy . '/config/screens.json', "{\"blog\":{}}\n");

        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'Build the blog page', AutonomyMode::Ask);
        $sessions->recordToolCall('s', 'screen_declare', ['name' => 'blog'], '{"ran_in_trial":true,"workspace":"' . self::WS . '"}');

        $r = $this->runAgent($sessions, $events, 'La declaración corrió en una prueba (`' . self::WS . '`). ¿Quieres que la promueva?');

        self::assertSame('final_answer', $r['termination']['reason'] ?? null, 'the producer cause is kept');
        self::assertTrue($r['paused'] ?? false, json_encode($r) ?: '');
        self::assertSame('perm:sandbox:promote', $sessions->load('s')?->question?->id);
    }

    public function testAnAnswerThatNamesNoTrialEndsAsItAlwaysDid(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'Build the blog page', AutonomyMode::Ask);

        $r = $this->runAgent($sessions, $events, 'The page is served in the house.');

        self::assertArrayNotHasKey('paused', $r);
        self::assertNull($sessions->load('s')?->question);
    }

    /** @return array<string, mixed> */
    private function runAgent(SessionStore $sessions, InMemoryEventStore $events, string $answer): array
    {
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(EventStoreInterface::class, $events);
        $kernel = Kernel::boot(['root' => $this->root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);

        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturn(['role' => 'assistant', 'content' => $answer]);
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'read', 'description' => 'Read', 'inputSchema' => ['type' => 'object']]]);
        $ops = new ProseFixtureOperations($container);
        $ops->loop = new AgentOrchestrator($llm, $tools);

        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($ops->operations() as $op) {
                if ($op->name === 'agent') {
                    return ($op->handler)(['prompt' => 'Continue', 'session' => 's']);
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('agent is not offered');
    }
}

/** The run with its model loop swapped for a fixture — nothing else of AgentOperations changes. */
final class ProseFixtureOperations extends AgentOperations
{
    public AgentOrchestrator $loop;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        return $this->loop;
    }
}
