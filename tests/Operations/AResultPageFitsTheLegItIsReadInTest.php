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

use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\RunLease;
use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ResultBudget;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * greenhouse decisions/0538 §3: inside a leg, `agent:result` refuses a page the leg's window cannot hold, saying
 * how much is left — evidence/1071's third leg paged 28.9 KB of old results into a window 2k tokens from its wall.
 *
 * The window is Rod's: 49,152 tokens, 40,960 of input. The room is the provider's last count on the stream.
 *
 * @internal
 */
final class AResultPageFitsTheLegItIsReadInTest extends TestCase
{
    private InMemoryEventStore $events;

    private SessionStore $store;

    private string $root;

    private int $seq;

    private ?RunLease $lease = null;

    private string|false $context = false;

    private string|false $baseUrl = false;

    protected function setUp(): void
    {
        $this->context = getenv('MILPA_AGENT_CONTEXT_TOKENS');
        $this->baseUrl = getenv('MILPA_AGENT_BASE_URL');
        putenv('MILPA_AGENT_CONTEXT_TOKENS');
        putenv('MILPA_AGENT_BASE_URL');
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
        $this->root = sys_get_temp_dir() . '/leg-page-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o777, true);
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'build the blog');
        $this->seq = $this->store->recordToolCall('s', 'source_read', ['path' => 'config/app.php'], str_repeat('x', 12000), resultChars: 12000);
    }

    protected function tearDown(): void
    {
        $this->lease?->release();
        $this->context === false ? putenv('MILPA_AGENT_CONTEXT_TOKENS') : putenv('MILPA_AGENT_CONTEXT_TOKENS=' . $this->context);
        $this->baseUrl === false ? putenv('MILPA_AGENT_BASE_URL') : putenv('MILPA_AGENT_BASE_URL=' . $this->baseUrl);
        AgentEndpoint::useProviderFetcher(null);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** 2,000 tokens left: half of it is 3,000 characters — the page is cut to that, not to the loop's 8,000. */
    public function testAPageIsCutToHalfTheRoomLeft(): void
    {
        $this->running();
        $this->modelReturned(38000, 960);

        $page = $this->read(ResultBudget::json(8000));

        self::assertTrue($page['ok'], (string) ($page['error'] ?? ''));
        self::assertLessThanOrEqual(3000, mb_strlen(json_encode($page, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)));
        self::assertNotNull($page['next_cursor'], 'the rest stays reachable');
    }

    /** 1,000 tokens left: a 1,500-character page is not worth a step — refused, with the numbers. */
    public function testAPageTheLegCannotHoldIsRefusedSayingWhatIsLeft(): void
    {
        $this->running();
        $this->modelReturned(39500, 460);

        $page = $this->read(ResultBudget::json(8000));

        self::assertFalse($page['ok']);
        self::assertSame(1000, $page['room_tokens']);
        self::assertSame(2667, $page['needed_tokens'], 'the loop\'s 8,000-character page, at three characters a token');
        self::assertStringContainsString("1000 tokens left in this leg's window", (string) $page['error']);
        self::assertStringContainsString('the next leg starts with room', (string) $page['error']);
    }

    /** The NEWEST count speaks: an earlier, emptier call does not lend room the leg no longer has. */
    public function testTheNewestCountIsTheRoom(): void
    {
        $this->running();
        $this->modelReturned(10000, 500);
        $this->modelReturned(39500, 460);

        self::assertFalse($this->read(ResultBudget::json(8000))['ok']);
    }

    /** CONTROL · plenty of room: the page is the loop's own, as before. */
    public function testWithRoomThePageIsTheLoopsOwn(): void
    {
        $this->running();
        $this->modelReturned(12000, 400);

        $page = $this->read(ResultBudget::json(8000));

        self::assertTrue($page['ok']);
        self::assertGreaterThan(7000, mb_strlen(json_encode($page, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)));
    }

    /** CONTROL · no run holds the session: a person reading it gets the page as before. */
    public function testOutsideALegNothingChanges(): void
    {
        $this->modelReturned(39500, 460);

        self::assertTrue($this->read(ResultBudget::json(8000))['ok']);
    }

    /** CONTROL · no result budget: the call did not come from the loop. */
    public function testWithoutTheLoopsBudgetNothingChanges(): void
    {
        $this->running();
        $this->modelReturned(39500, 460);

        self::assertTrue($this->read(null, ['max_chars' => 4000])['ok']);
    }

    /** CONTROL · no count on the stream, or no window known: nothing to measure the room by. */
    public function testWithoutACountOrAWindowNothingChanges(): void
    {
        $this->running();
        self::assertTrue($this->read(ResultBudget::json(8000))['ok'], 'no model_returned yet');

        $this->modelReturned(39500, 460);
        self::assertTrue($this->read(ResultBudget::json(8000), window: null)['ok'], 'no window');
    }

    private function running(): void
    {
        $this->lease = RunLease::take($this->root, 's');
        self::assertNotNull($this->lease);
    }

    private function modelReturned(int $prompt, int $completion): void
    {
        $this->events->append(new Event(
            streamId: SessionStore::PREFIX . 's',
            type: SessionEvent::ModelReturned->value,
            payload: ['model' => 'qwen3.8-27b', 'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion]],
            seq: $this->events->nextSeq(),
        ));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function read(?ResultBudget $budget, array $extra = [], ?int $window = 49152): array
    {
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $this->store);
        $container->registerService(Config::class, new Config($window === null ? [] : ['agent' => ['contextTokens' => $window]]));
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);
        $operation = array_values(array_filter(
            (new SessionOperations($container))->operations(),
            static fn (Operation $operation): bool => $operation->name === 'agent:result',
        ))[0];

        $page = ($operation->handler)(
            ['session' => 's', 'seq' => $this->seq, ...$extra],
            null,
            new ToolContext(scopes: ['agent:read'], resultBudget: $budget),
        );
        self::assertIsArray($page);

        return $page;
    }
}
