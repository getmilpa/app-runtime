<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ResultBudget;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionArgumentTest extends TestCase
{
    private SessionStore $store;
    private Operation $operation;
    private int $seq;
    private string $body;

    protected function setUp(): void
    {
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('Recover my rejected input', 'first');
        $this->body = str_repeat("é🌽\"\\\n", 1600);
        $this->seq = $this->store->recordToolCall('first', 'implement', [
            'content' => $this->body, 'empty' => '', 'count' => 2, 'nested' => ['content' => 'other'],
        ], 'rejected by the behavioral judge', false, true);
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $this->store);
        $operations = array_values(array_filter(
            (new SessionOperations($container))->operations(),
            static fn (Operation $operation): bool => $operation->name === 'agent:argument'
        ));
        self::assertCount(1, $operations, 'The installed operation must expose recovery, not just a helper.');
        $this->operation = $operations[0];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function read(array $input = [], ?ResultBudget $budget = null): array
    {
        return ($this->operation->handler)([
            'session' => 'first', 'seq' => $this->seq, 'argument' => 'content', ...$input,
        ], null, new ToolContext(scopes: ['agent:read'], resultBudget: $budget));
    }

    public function testWholeArgumentSurvivesPagingAndJournalGrowth(): void
    {
        $budget = ResultBudget::json(1100);
        $content = '';
        $cursor = null;
        $pages = 0;
        $before = $this->store->stream('first');
        do {
            $page = $this->read($cursor === null ? [] : ['cursor' => $cursor], $budget);
            self::assertTrue($page['ok']);
            self::assertTrue($budget->fits($page));
            self::assertSame(strlen($content), $page['offset']);
            self::assertSame(strlen($this->body), $page['total_bytes']);
            self::assertSame(hash('sha256', $this->body), $page['sha256']);
            self::assertSame($this->seq, $page['seq']);
            self::assertSame('implement', $page['tool']);
            self::assertFalse($page['call_ok']);
            self::assertTrue(mb_check_encoding($page['content'], 'UTF-8'));
            $content .= $page['content'];
            self::assertSame(strlen($content), $page['next_offset']);
            $cursor = $page['next_cursor'];
            $this->store->recordToolCall('first', 'agent_argument', [], json_encode($page, JSON_THROW_ON_ERROR));
            self::assertLessThan(300, ++$pages);
        } while ($cursor !== null);
        self::assertGreaterThan(1, $pages);
        self::assertSame($this->body, $content);
        self::assertSame($before, array_slice($this->store->stream('first'), 0, count($before)));
        self::assertCount(count($before) + $pages, $this->store->stream('first'));
    }

    public function testBudgetUsesTheTransportEncoderAndCannotBeExpanded(): void
    {
        $budget = new ResultBudget(1600, static fn (mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR));
        $page = $this->read(['max_chars' => 9000], $budget);
        self::assertTrue($page['ok']);
        self::assertTrue($budget->fits($page));
        $smaller = $this->read(['max_chars' => 1000], $budget);
        self::assertTrue($smaller['ok']);
        self::assertLessThanOrEqual(1000, mb_strlen($budget->encode($smaller)));
        self::assertLessThan(strlen($page['content']), strlen($smaller['content']));
    }

    public function testExplicitBudgetAndEmptyStringAreSupportedWithoutTransport(): void
    {
        $before = $this->store->stream('first');
        $page = $this->read(['argument' => 'empty', 'max_chars' => 1000]);
        self::assertTrue($page['ok']);
        self::assertSame('', $page['content']);
        self::assertSame(0, $page['total_bytes']);
        self::assertNull($page['next_cursor']);
        self::assertSame($before, $this->store->stream('first'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'missing budget' => [[]];
        yield 'tiny bound' => [['max_chars' => 255]];
        yield 'noninteger bound' => [['max_chars' => '2000']];
        yield 'metadata does not fit' => [['max_chars' => 256]];
        yield 'missing session' => [['session' => '', 'max_chars' => 1000]];
        yield 'unknown session' => [['session' => 'absent', 'max_chars' => 1000]];
        yield 'missing event' => [['seq' => 90000, 'max_chars' => 1000]];
        yield 'not a call' => [['seq' => 1, 'max_chars' => 1000]];
        yield 'noninteger event' => [['seq' => '2', 'max_chars' => 1000]];
        yield 'negative event' => [['seq' => -1, 'max_chars' => 1000]];
        yield 'missing argument' => [['argument' => '', 'max_chars' => 1000]];
        yield 'unknown argument' => [['argument' => 'absent', 'max_chars' => 1000]];
        yield 'integer argument' => [['argument' => 'count', 'max_chars' => 1000]];
        yield 'nested argument is not text' => [['argument' => 'nested', 'max_chars' => 1000]];
        yield 'no JSON path interpretation' => [['argument' => 'nested.content', 'max_chars' => 1000]];
        yield 'invalid cursor' => [['cursor' => '?', 'max_chars' => 1000]];
        yield 'empty cursor' => [['cursor' => '', 'max_chars' => 1000]];
        yield 'nonstring cursor' => [['cursor' => [], 'max_chars' => 1000]];
        yield 'oversize cursor' => [['cursor' => str_repeat('a', 17000), 'max_chars' => 1000]];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputFailsWithoutChangingHistory(array $input): void
    {
        $before = $this->store->stream('first');
        $result = $this->read($input);
        self::assertFalse($result['ok']);
        self::assertIsString($result['error']);
        self::assertSame($before, $this->store->stream('first'));
    }

    public function testCursorCannotSelectAnotherCallArgumentOrSession(): void
    {
        $page = $this->read(['max_chars' => 1100]);
        $this->store->start('Another session', 'second');
        $other = $this->store->recordToolCall('second', 'implement', ['content' => $this->body], 'rejected', false);
        $next = $this->store->recordToolCall('first', 'implement', ['content' => $this->body], 'rejected', false);
        foreach ([['session' => 'second', 'seq' => $other], ['seq' => $next], ['argument' => 'empty']] as $change) {
            self::assertFalse($this->read([...$change, 'max_chars' => 1100, 'cursor' => $page['next_cursor']])['ok']);
        }
    }

    public function testForgedCursorCannotChangeDigestOrSplitUtf8(): void
    {
        $page = $this->read(['max_chars' => 1100]);
        $cursor = json_decode(base64_decode(strtr($page['next_cursor'], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        foreach ([['sha256' => str_repeat('0', 64)], ['offset' => -1], ['offset' => strlen($this->body) + 1],
            ['offset' => 1], ['offset' => '0'], ['v' => 99], ['extra' => true]] as $change) {
            $token = rtrim(strtr(base64_encode(json_encode([...$cursor, ...$change], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            self::assertFalse($this->read(['max_chars' => 1100, 'cursor' => $token])['ok']);
        }
    }

    public function testReadDeclaresTheExistingSessionAuthority(): void
    {
        self::assertSame(['agent:read', 'agent:answer'], $this->operation->scopes);
        self::assertFalse($this->operation->mutating);
        self::assertSame(['session', 'seq', 'argument'], $this->operation->inputSchema['required']);
        self::assertSame(['cli', 'tui', 'mcp'], $this->operation->surfaces);
    }
}
