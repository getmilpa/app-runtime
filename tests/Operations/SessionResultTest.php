<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionResultPage;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ResultBudget;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionResultTest extends TestCase
{
    private InMemoryEventStore $events;
    private SessionStore $store;
    private Operation $operation;
    private int $seq;
    private string $result;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('first', 'Recover the recorded failure');
        $this->result = "Judge: failed behavior\n" . str_repeat("é🌽\"\\\n", 700);
        $this->seq = $this->store->recordToolCall(
            'first',
            'implement',
            ['content' => 'rejected PHP'],
            $this->result,
            false,
            true,
            mb_strlen($this->result)
        );
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $this->store);
        $this->operation = $this->operation($container);
    }

    private function operation(DIContainer $container): Operation
    {
        $operations = array_values(array_filter(
            (new SessionOperations($container))->operations(),
            static fn (Operation $operation): bool => $operation->name === 'agent:result'
        ));
        self::assertCount(1, $operations);

        return $operations[0];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function read(array $input = [], ?ResultBudget $budget = null): array
    {
        return ($this->operation->handler)(
            ['session' => 'first', 'seq' => $this->seq, ...$input],
            null,
            new ToolContext(scopes: ['agent:read'], resultBudget: $budget)
        );
    }

    public function testStoredFailureSurvivesPaginationAndJournalGrowthWithoutBecomingSuccess(): void
    {
        $budget = ResultBudget::json(1600);
        $content = '';
        $cursor = null;
        $pages = 0;
        $callHash = null;
        $before = $this->store->stream('first');
        do {
            $page = $this->read($cursor === null ? [] : ['cursor' => $cursor], $budget);
            self::assertTrue($page['ok']);
            self::assertFalse($page['call_ok']);
            self::assertTrue($page['storage_complete']);
            self::assertSame('implement', $page['tool']);
            self::assertSame($this->seq, $page['seq']);
            self::assertSame(hash('sha256', $this->result), $page['sha256']);
            self::assertSame(strlen($this->result), $page['total_bytes']);
            self::assertSame(mb_strlen($this->result), $page['stored_chars']);
            self::assertSame($page['stored_chars'], $page['declared_chars']);
            self::assertSame(strlen($content), $page['offset']);
            self::assertTrue(mb_check_encoding($page['content'], 'UTF-8'));
            self::assertTrue($budget->fits($page));
            self::assertSame($page, json_decode($budget->encode($page), true, flags: JSON_THROW_ON_ERROR));
            $callHash ??= $page['call_sha256'];
            self::assertSame($callHash, $page['call_sha256']);
            $content .= $page['content'];
            self::assertSame(strlen($content), $page['next_offset']);
            $cursor = $page['next_cursor'];
            $this->store->recordToolCall('first', 'agent_result', [], json_encode($page, JSON_THROW_ON_ERROR));
            self::assertLessThan(100, ++$pages);
        } while ($cursor !== null);
        self::assertGreaterThan(1, $pages);
        self::assertSame($this->result, $content);
        self::assertSame($before, array_slice($this->store->stream('first'), 0, count($before)));
        self::assertCount(count($before) + $pages, $this->store->stream('first'));
    }

    public function testStorageCompletenessRemainsUnknownOrPartialAfterTheFinalPage(): void
    {
        foreach ([[null, null], [20, false], [2, true]] as [$declared, $complete]) {
            $seq = $this->store->recordToolCall('first', 'read', [], 'é🌽', true, false, $declared);
            $page = $this->read(['seq' => $seq, 'max_chars' => 1500]);
            self::assertTrue($page['ok']);
            self::assertSame('é🌽', $page['content']);
            self::assertSame($declared, $page['declared_chars']);
            self::assertSame($complete, $page['storage_complete']);
            self::assertNull($page['next_cursor']);
        }
        $seq = $this->store->recordToolCall('first', 'read', [], '', true, false, 0);
        $empty = $this->read(['seq' => $seq, 'max_chars' => 1500]);
        self::assertTrue($empty['ok']);
        self::assertSame('', $empty['content']);
        self::assertSame(0, $empty['total_bytes']);
        self::assertTrue($empty['storage_complete']);
        self::assertNull($empty['next_cursor']);
    }

    public function testBudgetUsesTransportEncodingAndCannotBeExpanded(): void
    {
        $budget = new ResultBudget(1700, static fn (mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR));
        $page = $this->read(['max_chars' => 9000], $budget);
        self::assertTrue($page['ok']);
        self::assertTrue($budget->fits($page));
        $smaller = $this->read(['max_chars' => 1300], $budget);
        self::assertTrue($smaller['ok']);
        self::assertLessThanOrEqual(1300, mb_strlen($budget->encode($smaller)));
        self::assertLessThan(strlen($page['content']), strlen($smaller['content']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'missing budget' => [[]];
        yield 'tiny bound' => [['max_chars' => 255]];
        yield 'noninteger bound' => [['max_chars' => '2000']];
        yield 'metadata cannot fit' => [['max_chars' => 256]];
        yield 'empty session' => [['session' => '', 'max_chars' => 1500]];
        yield 'unknown session' => [['session' => 'absent', 'max_chars' => 1500]];
        yield 'missing event' => [['seq' => 99999, 'max_chars' => 1500]];
        yield 'not a call' => [['seq' => 1, 'max_chars' => 1500]];
        yield 'noninteger event' => [['seq' => '2', 'max_chars' => 1500]];
        yield 'negative event' => [['seq' => -1, 'max_chars' => 1500]];
        yield 'invalid cursor' => [['cursor' => '?', 'max_chars' => 1500]];
        yield 'empty cursor' => [['cursor' => '', 'max_chars' => 1500]];
        yield 'nonstring cursor' => [['cursor' => [], 'max_chars' => 1500]];
        yield 'oversize cursor' => [['cursor' => str_repeat('a', 17000), 'max_chars' => 1500]];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputDoesNotChangeTheJournal(array $input): void
    {
        $before = $this->store->stream('first');
        $page = $this->read($input);
        self::assertFalse($page['ok']);
        self::assertIsString($page['error']);
        self::assertSame($before, $this->store->stream('first'));
    }

    public function testCursorCannotSelectAnotherCallSessionOrResult(): void
    {
        $page = $this->read(['max_chars' => 1600]);
        $other = $this->store->recordToolCall('second', 'implement', [], $this->result, false);
        $next = $this->store->recordToolCall('first', 'implement', [], $this->result, false);
        foreach ([['session' => 'second', 'seq' => $other], ['seq' => $next]] as $change) {
            self::assertFalse($this->read([...$change, 'max_chars' => 1600, 'cursor' => $page['next_cursor']])['ok']);
        }
        $cursor = json_decode(base64_decode(strtr($page['next_cursor'], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        foreach ([['sha256' => str_repeat('0', 64)], ['call_sha256' => str_repeat('0', 64)], ['offset' => -1],
            ['offset' => strlen($this->result) + 1], ['offset' => strpos($this->result, 'é') + 1],
            ['offset' => '0'], ['v' => 99], ['kind' => 'argument'], ['extra' => true], ['session' => 1]] as $change) {
            $token = rtrim(strtr(base64_encode(json_encode([...$cursor, ...$change], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            self::assertFalse($this->read(['max_chars' => 1600, 'cursor' => $token])['ok']);
        }
    }

    public function testCursorBindsOutcomeAndMetadataEvenWhenResultBytesAreUnchanged(): void
    {
        $page = $this->read(['max_chars' => 1600]);
        foreach ([['ok' => true], ['tool' => 'edit'], ['arguments' => ['content' => 'different code']],
            ['resultChars' => mb_strlen($this->result) + 1]] as $delta) {
            $events = new InMemoryEventStore();
            foreach ($this->store->stream('first') as $event) {
                $row = $event->toArray();
                if ($event->seq === $this->seq) {
                    $row['payload'] = array_replace($row['payload'], $delta);
                }
                $events->append(Event::fromArray($row));
            }
            $reader = new SessionResultPage(new SessionStore($events));
            self::assertFalse($reader->read(['session' => 'first', 'seq' => $this->seq,
                'cursor' => $page['next_cursor']], ResultBudget::json(1600))['ok']);
        }
    }

    public function testMalformedStoredResultsAreNotSilentlyReconstructed(): void
    {
        foreach ([['result' => 12], ['result' => "\xFF"], ['tool' => 9], ['ok' => null],
            ['resultChars' => -1], ['resultChars' => '20000']] as $delta) {
            $events = new InMemoryEventStore();
            $source = $this->store->stream('first')[1]->toArray();
            $source['payload'] = array_replace($source['payload'], $delta);
            $events->append(Event::fromArray($source));
            $reader = new SessionResultPage(new SessionStore($events));
            self::assertFalse($reader->read(['session' => 'first', 'seq' => $this->seq], ResultBudget::json(1600))['ok']);
        }
        $this->events->append($this->store->stream('first')[1]);
        self::assertFalse($this->read(['max_chars' => 1600])['ok']);
    }

    public function testReadUsesExistingAuthorityAndDoesNotOfferAnInvocationSurface(): void
    {
        self::assertSame(['agent:read', 'agent:answer'], $this->operation->scopes);
        self::assertFalse($this->operation->mutating);
        self::assertSame(['session', 'seq'], $this->operation->inputSchema['required']);
        self::assertSame(['cli', 'tui', 'mcp'], $this->operation->surfaces);
        $operation = $this->operation(new DIContainer());
        self::assertSame(
            ['ok' => false, 'error' => 'this app has nowhere to store sessions'],
            ($operation->handler)(['session' => 'first', 'seq' => $this->seq, 'max_chars' => 1600])
        );
    }
}
