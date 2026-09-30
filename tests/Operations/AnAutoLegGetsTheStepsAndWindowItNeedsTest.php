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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Compactor;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\AppRuntime\Operations\AgentOperations;
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
 * greenhouse decisions/0538, driven through the real `run()`: what the orchestrator of ONE leg is
 * handed — its step ceiling (B1) and the history it inherits (B4) — on the window of Rod's live run
 * (evidence/1071: 49,152 tokens, 40,960 of input).
 *
 * The provider is never reached: the orchestrator seam records what it was built with and answers.
 *
 * @internal
 */
final class AnAutoLegGetsTheStepsAndWindowItNeedsTest extends TestCase
{
    private InMemoryEventStore $events;

    private SessionStore $store;

    private string|false $openAi = false;

    private string|false $anthropic = false;

    private string|false $context = false;

    private string|false $baseUrl = false;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->openAi = getenv('OPENAI_API_KEY');
        $this->anthropic = getenv('ANTHROPIC_API_KEY');
        $this->context = getenv('MILPA_AGENT_CONTEXT_TOKENS');
        $this->baseUrl = getenv('MILPA_AGENT_BASE_URL');
        putenv('OPENAI_API_KEY=test-key');
        putenv('ANTHROPIC_API_KEY');
        putenv('MILPA_AGENT_CONTEXT_TOKENS');
        putenv('MILPA_AGENT_BASE_URL');
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
    }

    protected function tearDown(): void
    {
        $this->openAi === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $this->openAi);
        $this->anthropic === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $this->anthropic);
        $this->context === false ? putenv('MILPA_AGENT_CONTEXT_TOKENS') : putenv('MILPA_AGENT_CONTEXT_TOKENS=' . $this->context);
        $this->baseUrl === false ? putenv('MILPA_AGENT_BASE_URL') : putenv('MILPA_AGENT_BASE_URL=' . $this->baseUrl);
        AgentEndpoint::useProviderFetcher(null);
    }

    /** B1 · Rod's script: AUTO, no `--steps`, the live run's window → 40 steps, not 12. */
    public function testAnAutoLegWithNoStepsTakesItsCeilingFromTheWindow(): void
    {
        $this->store->start('s', 'build the blog', AutonomyMode::Auto);

        self::assertSame(40, $this->leg('s', window: 49152)->steps);
    }

    /** B1 · the same leg, turned AUTO by the call itself (`--mode=auto`, as Rod's script does), derives too. */
    public function testTheModeTheCallSetsIsTheModeThatDerives(): void
    {
        $this->store->start('s', 'build the blog');

        self::assertSame(40, $this->leg('s', window: 49152, extra: ['mode' => 'auto'])->steps);
    }

    /** CONTROL · someone typed `--steps`: it wins. */
    public function testATypedCeilingWins(): void
    {
        $this->store->start('s', 'build the blog', AutonomyMode::Auto);

        self::assertSame(12, $this->leg('s', window: 49152, extra: ['steps' => 12])->steps);
    }

    /** CONTROL · ASK keeps today's 12: a person answers each step. */
    public function testAnAskLegKeepsTwelve(): void
    {
        $this->store->start('s', 'build the blog');

        self::assertSame(12, $this->leg('s', window: 49152)->steps);
    }

    /** CONTROL · no window known: nothing to derive from. */
    public function testWithoutAWindowAnAutoLegKeepsTwelve(): void
    {
        $this->store->start('s', 'build the blog', AutonomyMode::Auto);

        self::assertSame(12, $this->leg('s', window: null)->steps);
    }

    /**
     * B4 · a `continue` on a long session inherits a fifth of the input limit, not 60 % of the window.
     *
     * 1071's legs re-entered at 22–31k of 40,960; the composed history alone was over 9k tokens after
     * a compaction whose facts took ~35k characters. Here the same kind of session composes under
     * 8,191 estimated tokens (32,764 characters), the newest turn protected.
     */
    public function testAContinueInheritsAFifthOfTheInputLimit(): void
    {
        $this->longSession('s');

        $leg = $this->leg('s', window: 49152);

        self::assertLessThanOrEqual(8191 * 4, $this->chars($leg->history), 'the composed share of the inherited context');
        self::assertGreaterThan(0, $this->chars($leg->history), 'it still inherits something');
    }

    /**
     * B4 · Rod's session as it stands: its summaries were already written for the whole window (~35k characters).
     * The leg composes them down to its share — the facts elided oldest first, named — and the stream keeps them whole.
     */
    public function testASummaryWrittenForTheWholeWindowIsComposedDownToTheShare(): void
    {
        $this->longSession('s');
        $session = $this->store->load('s');
        self::assertNotNull($session);
        (new Compactor(windowBudget: 49152))->compactIfNeeded($this->store, $session);
        $written = (string) $this->store->load('s')?->summary;
        self::assertGreaterThan(30000, mb_strlen($written), 'the 1071-sized summary is on the stream');
        // It covers every turn, as right after 1071's compactions: nothing left for this leg to compact again.
        $stream = $this->store->stream('s');
        $this->store->compact('s', $written, $stream[array_key_last($stream)]->seq);

        $leg = $this->leg('s', window: 49152);

        self::assertLessThanOrEqual((819 + 2457) * 4 + 200, $this->chars($leg->history), 'prose + facts shares, and the label');
        self::assertSame($written, $this->store->load('s')?->summary, 'the stream keeps the summary whole');
    }

    /** CONTROL · no window known: the composition is today's, unbounded by any leg share. */
    public function testWithoutAWindowTheInheritedHistoryIsTodays(): void
    {
        $this->longSession('s');

        $leg = $this->leg('s', window: null);

        self::assertGreaterThan(8191 * 4, $this->chars($leg->history));
    }

    /** B4 · the summary is WRITTEN for the share it will be read in: the compaction's facts fit, no defensive cut. */
    public function testTheCompactionIsWrittenForTheInheritedShare(): void
    {
        $this->longSession('s');

        $this->leg('s', window: 49152);

        $summary = $this->store->load('s')?->summary;
        self::assertNotNull($summary, 'the long tail compacted');
        self::assertLessThanOrEqual((819 + 2457) * 4, mb_strlen($summary), 'prose + facts shares of the inherited context');
    }

    /**
     * A 1071-like session in AUTO: 40 turns of ~3k characters and 60 recorded calls with sizeable
     * arguments and results — well past any share of a 49,152-token window, and enough operational
     * facts for a whole-window compaction to write them at the ~35k characters 1071 measured.
     */
    private function longSession(string $id): void
    {
        $this->store->start($id, 'build the blog', AutonomyMode::Auto);
        for ($i = 0; $i < 60; ++$i) {
            if ($i < 40) {
                $this->store->recordTurn($id, $i % 2 === 0 ? 'user' : 'assistant', "turn {$i}: " . str_repeat('lorem ipsum ', 250));
            }
            $this->store->recordToolCall(
                $id,
                $i % 3 === 0 ? 'source_read' : 'make',
                ['path' => "src/Plugins/Blog/File{$i}.php", 'content' => str_repeat("line {$i} of the file\n", 60)],
                str_repeat("result {$i} ", 300),
                mutating: $i % 3 !== 0,
            );
        }
    }

    /** @param list<array<string, mixed>> $history */
    private function chars(array $history): int
    {
        return array_sum(array_map(static fn (array $m): int => mb_strlen((string) ($m['content'] ?? '')), $history));
    }

    /** @param array<string, mixed> $extra */
    private function leg(string $session, ?int $window, array $extra = []): LegCapturingOrchestrator
    {
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $this->events);
        $container->registerService(SessionStore::class, $this->store);
        $container->registerService(Config::class, new Config($window === null ? [] : ['agent' => ['contextTokens' => $window]]));
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);
        $ops = new LegCapturingAgentOperations($container);

        $result = null;
        foreach ($ops->operations() as $operation) {
            if ($operation->name === 'agent') {
                $result = ($operation->handler)(['prompt' => 'continue', 'session' => $session, ...$extra]);
            }
        }
        self::assertIsArray($result);
        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'agent run failed'));
        self::assertNotNull($ops->leg);

        return $ops->leg;
    }
}

/** The real `run()` and `ask()`, with only the orchestrator seam replaced. */
final class LegCapturingAgentOperations extends AgentOperations
{
    public ?LegCapturingOrchestrator $leg = null;

    protected function orchestrator(
        LlmService $modeloRemoto,
        GatedToolCalls $cliente,
        int $pasos,
        ?PlanBoard $tablero,
        bool $lazyTools,
        ?SessionProgressProbe $sonda,
    ): AgentOrchestrator {
        return $this->leg = new LegCapturingOrchestrator($modeloRemoto, $cliente, $pasos);
    }
}

/** Records the ceiling it was built with and the history it was handed, and answers without a model. */
final class LegCapturingOrchestrator extends AgentOrchestrator
{
    /** @var list<array<string, mixed>> */
    public array $history = [];

    public function __construct(LlmService $llm, GatedToolCalls $client, public readonly int $steps)
    {
        parent::__construct($llm, $client, $steps);
    }

    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        $this->history = $history;

        return 'done';
    }
}
