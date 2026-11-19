<?php

/**
 * This file is part of milpa/app-runtime.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\{ProgressReceipt, SessionStore};
use Milpa\AppRuntime\Agent\{ConsentBridge, JournalAppendWitness, PrerequisiteGate, SessionBookkeeping, SessionDebtReadOperation, SessionHouseDebt, SessionToolGate};
use Milpa\Command\{Effect\EffectProfile, InvocationContext, Operation};
use Milpa\Console\McpProjector;
use Milpa\Container\DIContainer;
use Milpa\EventStore\{Event, EventStoreInterface, InMemoryEventStore};
use Milpa\ToolRuntime\{Contracts\ToolContext, Gate\ToolCallGate, ToolRegistry};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** E1231: real projection, registry, gate and recorder; no model or mock executor. */
final class SessionHouseDebtTest extends TestCase
{
    private InMemoryEventStore $events;
    private SessionStore $sessions;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->sessions->start('root', 'fixture');
        $this->sessions->start('child', 'fixture', parentId: 'root');
    }

    /** @param list<Operation> $extra */
    private function bridge(string $id = 'root', array $extra = [], ?string $root = null, ?EventStoreInterface $writer = null, ?PrerequisiteGate $prerequisite = null): ConsentBridge
    {
        $book = new SessionBookkeeping($this->sessions, $id, $writer ?? $this->events, root: $root);
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll([...$book->operations(), ...$extra], $registry, new DIContainer());
        $gate = new SessionToolGate($this->sessions, $this->sessions->load($id), $extra, contractProducers: [$book], houseRoot: $root, compuertaPrevia: $prerequisite);

        return new ConsentBridge($registry, gate: $gate, recorder: $gate, executions: $gate, root: $root);
    }

    /** @return array<string, mixed> */
    private function claim(string $statement = 'Tried a read; expected status; received a failure; no workaround.'): array
    {
        return ['owner' => 'house', 'kind' => 'tool_error', 'statement' => $statement];
    }

    public function testARealCallIsDiscoverableAndItsReceiptOutranksTheStatement(): void
    {
        $op = new Operation('fixture:read', 'fixture', static fn (): array => ['ok' => false, 'code' => 'ACTUAL_CODE', 'error' => 'actual refusal'], effects: EffectProfile::readOnly(), surfaces: ['mcp']);
        $bridge = $this->bridge(extra: [$op]);
        $bridge->callTool('fixture_read', []);
        $calls = $bridge->callTool('HouseDebtRead', ['mode' => 'calls']);
        self::assertCount(1, $calls['items']);
        $call = $calls['items'][0];
        self::assertSame('ACTUAL_CODE', $call['code']);
        $ack = $bridge->callTool('HouseDebt', $this->claim('The code was INVENTED; the receipt must win.') + ['about' => [$call['id'], $call['id'], 'missing']]);
        self::assertTrue($ack['recorded']);
        self::assertSame(1, $ack['evidence']);
        self::assertSame(['missing'], $ack['ignored_about']);
        $finding = $bridge->callTool('HouseDebtRead', ['id' => $ack['id']])['items'][0];
        self::assertSame('model', $finding['source']);
        self::assertSame('testimony', $finding['owner_basis']);
        self::assertSame('ACTUAL_CODE', $finding['code']);
        self::assertSame($call['id'], $finding['references'][0]['id']);
        self::assertSame($call['sha256'], $finding['references'][0]['sha256']);
        self::assertSame([], $this->bridge('child')->callTool('HouseDebtRead', ['mode' => 'calls'])['items']);
        $foreign = $this->bridge('child')->callTool('HouseDebt', $this->claim() + ['about' => [$call['id']]]);
        self::assertSame(0, $foreign['evidence']);
        self::assertSame([$call['id']], $foreign['ignored_about']);
    }

    public function testReportsQueriesLimitsAndResumeDoNotBuyProgressOrAuthority(): void
    {
        $before = $this->sessions->load('root');
        $ack = null;
        for ($i = 0; $i < 8; ++$i) {
            $bridge = $this->bridge();
            $report = $this->claim('friction ' . $i);
            $ack = $bridge->callTool('HouseDebt', $report);
            self::assertTrue($ack['recorded']);
            self::assertSame($ack, $this->bridge()->callTool('HouseDebt', $report), 'recreating the handler does not duplicate testimony');
            $bridge->callTool('HouseDebtRead', []);
        }
        $this->sessions->recordRunTermination('root', ['reason' => 'steps_exhausted', 'receipt' => null]);
        self::assertSame(['recorded' => false, 'reason' => 'limit'], $this->bridge()->callTool('HouseDebt', $this->claim('ninth')));
        self::assertSame($ack, $this->bridge()->callTool('HouseDebt', $this->claim('friction 7')));
        $events = $this->sessions->stream('root');
        $receipt = ProgressReceipt::of($events, 0, $this->events->nextSeq());
        self::assertSame(ProgressReceipt::STALLED, $receipt->progress);
        self::assertSame(0, $receipt->newArtifacts);
        self::assertSame(0, $receipt->newEvidence);
        self::assertSame(0, $receipt->newDiagnostics);
        self::assertSame(0, $receipt->closedTodos);
        self::assertSame(0, $receipt->newFacts, 'successful bookkeeping is not recorded as a successful work call');
        self::assertEquals($before, $this->sessions->load('root'), 'testimony cannot grant, close, or change session authority');
        $lastReport = max(array_map(static fn (Event $event): int => $event->type === SessionHouseDebt::EVENT ? $event->seq : 0, $events));
        $findings = $this->bridge()->callTool('HouseDebtRead', ['after' => $lastReport]);
        self::assertContains('call_budget_stop', array_column($findings['items'], 'kind'), 'terminal capture is not limited by the model quota');
    }

    public function testTerminalFindingsAndDisputesAreStableAdditiveViews(): void
    {
        foreach (['final_answer', 'context_budget_exhausted', 'failed', 'progress_stalled', 'steps_exhausted', 'tool_refused'] as $reason) {
            $this->sessions->recordRunTermination('root', ['reason' => $reason, 'receipt' => null]);
        }
        $original = $this->sessions->stream('root');
        $bridge = $this->bridge();
        $findings = $bridge->callTool('HouseDebtRead', [])['items'];
        self::assertSame(['no_progress_cut', 'call_budget_stop', 'house_refusal'], array_column($findings, 'kind'));
        self::assertSame($original, $this->sessions->stream('root'), 'a terminal view appends no second fact');
        self::assertSame($findings, $this->bridge()->callTool('HouseDebtRead', [])['items']);
        $dispute = $bridge->callTool('HouseDebt', $this->claim() + ['disputes' => $findings[0]['id']]);
        self::assertTrue($dispute['recorded']);
        self::assertSame($findings[0], $bridge->callTool('HouseDebtRead', ['id' => $findings[0]['id']])['items'][0]);
        self::assertSame($findings[0]['id'], $bridge->callTool('HouseDebtRead', ['id' => $dispute['id']])['items'][0]['disputes']);
        foreach (['missing', $dispute['id']] as $target) {
            self::assertSame(['recorded' => false, 'reason' => 'unknown_dispute'], $bridge->callTool('HouseDebt', $this->claim() + ['disputes' => $target]));
        }
        self::assertSame('unknown_dispute', $this->bridge('child')->callTool('HouseDebt', $this->claim() + ['disputes' => $findings[0]['id']])['reason']);
    }

    public function testWriterIsAbsentWithoutItsStoreAndReaderStatesAvailability(): void
    {
        $ops = (new SessionHouseDebt($this->sessions, 'root'))->operations();
        self::assertSame(['HouseDebtRead'], array_column($ops, 'name'));
        self::assertFalse(($ops[0]->handler)([])['writer_available']);
    }

    public function testReaderMustBelongToThisStoreAndSessionAndStillWorksDuringRecovery(): void
    {
        $this->events->append(new Event(SessionStore::PREFIX . 'root', 'session.progress_stalled', [], $this->events->nextSeq()));
        self::assertTrue($this->bridge()->callTool('HouseDebtRead', [])['ok']);
        $other = new SessionStore(new InMemoryEventStore());
        $other->start('root', 'same spelling, different journal');
        foreach ([[$this->sessions, 'child'], [$other, 'root']] as [$store, $id]) {
            $foreign = new SessionDebtReadOperation(new SessionHouseDebt($store, $id));
            $registry = new ToolRegistry(new NullLogger());
            (new McpProjector())->projectAll([$foreign], $registry, new DIContainer());
            $gate = new SessionToolGate($this->sessions, $this->sessions->load('root'), [$foreign]);
            try {
                (new ConsentBridge($registry, gate: $gate, recorder: $gate))->callTool('HouseDebtRead', []);
                self::fail('foreign reader must not run');
            } catch (\Exception $error) {
                self::assertStringContainsString('another store or session', $error->getMessage());
            }
        }
    }

    public function testReaderPaginatesWithoutCopyingWholeResultsOrInventingCompleteness(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->sessions->recordToolCall('root', 'read', [], str_repeat('x', 600), true, false, $i === 0 ? null : 700);
        }
        $bridge = $this->bridge();
        $first = $bridge->callTool('HouseDebtRead', ['mode' => 'calls']);
        self::assertCount(8, $first['items']);
        self::assertTrue($first['has_more']);
        self::assertNull($first['items'][0]['result_complete']);
        self::assertFalse($first['items'][1]['result_complete']);
        self::assertSame(512, mb_strlen($first['items'][0]['result_excerpt']));
        self::assertTrue($first['items'][0]['excerpt_truncated']);
        $next = $bridge->callTool('HouseDebtRead', ['mode' => 'calls', 'after' => $first['next_seq']]);
        self::assertCount(2, $next['items']);
        self::assertFalse($next['has_more']);
        self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($next['items'], 'id')));
        self::assertFalse($bridge->callTool('HouseDebtRead', ['mode' => 'findings'])['has_more']);
    }

    public function testExactRetryDoesNotDeclareANewMaterializedEffect(): void
    {
        $bridge = $this->bridge();
        $first = $bridge->callTool('HouseDebt', $this->claim());
        $before = $this->events->nextSeq();
        self::assertSame($first, $bridge->callTool('HouseDebt', $this->claim()));
        self::assertSame([], array_values(array_filter($this->sessions->stream('root'), static fn (Event $row): bool => $row->seq >= $before && $row->type === 'session.operation_executed')));
    }

    public function testLimitRefusalDoesNotDeclareAMaterializedEffect(): void
    {
        $bridge = $this->bridge();
        for ($i = 0; $i < 8; ++$i) {
            self::assertTrue($bridge->callTool('HouseDebt', $this->claim('claim ' . $i))['recorded']);
        }
        $before = $this->events->nextSeq();
        self::assertSame(['recorded' => false, 'reason' => 'limit'], $bridge->callTool('HouseDebt', $this->claim('over quota')));
        self::assertSame([], array_values(array_filter($this->sessions->stream('root'), static fn (Event $row): bool => $row->seq >= $before && $row->type === 'session.operation_executed')));
    }

    public function testOwnerValidationAndUnicodeBoundsAreConsistent(): void
    {
        $bridge = $this->bridge();
        self::assertSame('owner', $bridge->callTool('HouseDebt', ['owner' => 'plugin:' . str_repeat('a', 25)] + $this->claim())['reason']);
        self::assertSame('owner', $bridge->callTool('HouseDebt', ['owner' => 'mcp:bad/server'] + $this->claim())['reason']);
        foreach (['plugin:' . str_repeat('a', 24), 'mcp:' . str_repeat('A', 32), 'environment', 'model', 'user', 'unknown'] as $owner) {
            self::assertTrue($bridge->callTool('HouseDebt', ['owner' => $owner] + $this->claim(str_repeat('😀', 2000)))['recorded']);
        }
        $this->expectException(\Exception::class);
        $bridge->callTool('HouseDebt', $this->claim(str_repeat('😀', 2001)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badReports(): iterable
    {
        yield 'extra field' => [['session' => 'child']];
        yield 'missing owner' => [['owner' => null]];
        yield 'invalid kind' => [['kind' => 'made_up']];
        yield 'empty statement' => [['statement' => '']];
        yield 'nine about ids' => [['about' => array_fill(0, 9, 'x')]];
        yield 'map not list' => [['about' => ['id' => 'x']]];
        yield 'non-string member' => [['about' => [42]]];
        yield 'oversize member' => [['about' => [str_repeat('x', 129)]]];
        yield 'null about' => [['about' => null]];
        yield 'oversize dispute' => [['disputes' => str_repeat('x', 65)]];
    }

    #[DataProvider('badReports')]
    public function testDirectAndProjectedMalformedReportsNeverWrite(array $overrides): void
    {
        $input = $overrides + $this->claim();
        $ops = (new SessionHouseDebt($this->sessions, 'root', $this->events))->operations();
        self::assertSame(['recorded' => false, 'reason' => 'unrecorded'], ($ops[0]->handler)($input));
        try {
            $result = $this->bridge()->callTool('HouseDebt', $input);
            self::assertFalse($result['recorded']);
        } catch (\Exception $e) {
            self::assertNotSame('', $e->getMessage(), 'schema layer failures remain separate');
        }
        self::assertSame([], $this->bridge()->callTool('HouseDebtRead', [])['items']);
    }

    public function testAppendFailureOrFalseSuccessCannotReceiveAnAcknowledgement(): void
    {
        foreach ([false, true] as $throws) {
            $writer = $this->createMock(EventStoreInterface::class);
            $writer->method('nextSeq')->willReturn(99);
            $writer->method('append')->willReturnCallback(static function () use ($throws): void {
                if ($throws) {
                    throw new \RuntimeException('write failed');
                }
            });
            $op = (new SessionHouseDebt($this->sessions, 'root', $writer))->operations()[0];
            self::assertSame(['recorded' => false, 'reason' => 'unrecorded'], ($op->handler)($this->claim()));
        }
        self::assertSame([], $this->bridge()->callTool('HouseDebtRead', [])['items']);
    }

    public function testAmbiguousSequenceCannotAttachEvidence(): void
    {
        $seq = $this->sessions->recordToolCall('root', 'fixture', [], 'retained', false);
        $id = $this->bridge()->callTool('HouseDebtRead', ['mode' => 'calls'])['items'][0]['id'];
        $this->events->append(new Event(SessionStore::PREFIX . 'root', 'other', [], $seq));
        $ack = $this->bridge()->callTool('HouseDebt', $this->claim() + ['about' => [$id]]);
        self::assertSame(0, $ack['evidence']);
        self::assertSame([$id], $ack['ignored_about']);
    }

    public function testStatementIsRedactedBeforePersistenceAndReportsThatChange(): void
    {
        $root = sys_get_temp_dir() . '/milpa-debt-' . bin2hex(random_bytes(8));
        mkdir($root . '/.milpa', 0700, true);
        file_put_contents($root . '/.milpa/secrets.json', json_encode(['key' => 'fixture-private-debt-value']));
        try {
            $bridge = $this->bridge(root: $root);
            $ack = $bridge->callTool('HouseDebt', $this->claim('Got fixture-private-debt-value; no workaround.'));
            self::assertTrue($ack['recorded']);
            $finding = $bridge->callTool('HouseDebtRead', [])['items'][0];
            self::assertTrue($finding['statement_redacted']);
            self::assertStringNotContainsString('fixture-private-debt-value', json_encode($this->sessions->stream('root')));
            self::assertStringContainsString('[secret]', $finding['statement']);
        } finally {
            unlink($root . '/.milpa/secrets.json');
            rmdir($root . '/.milpa');
            rmdir($root);
        }
    }

    public function testUnknownReferencesAndOwnerCannotSmuggleKnownSecrets(): void
    {
        $root = sys_get_temp_dir() . '/milpa-debt-' . bin2hex(random_bytes(8));
        mkdir($root . '/.milpa', 0700, true);
        $secret = 'private-debt-key';
        file_put_contents($root . '/.milpa/secrets.json', json_encode(['key' => $secret]));
        try {
            $this->sessions->recordToolCall('root', 'fixture', [], 'ordinary receipt', false);
            $bridge = $this->bridge(root: $root);
            $id = $bridge->callTool('HouseDebtRead', ['mode' => 'calls'])['items'][0]['id'];
            $ack = $bridge->callTool('HouseDebt', $this->claim() + ['about' => [$id, $secret, 'ordinary-unknown']]);
            self::assertTrue($ack['recorded']);
            self::assertSame(1, $ack['evidence']);
            self::assertSame(['[secret]', 'ordinary-unknown'], $ack['ignored_about']);
            self::assertSame(['recorded', 'id', 'signature', 'evidence', 'ignored_about'], array_keys($ack));
            $finding = $bridge->callTool('HouseDebtRead', ['id' => $ack['id']])['items'][0];
            self::assertSame($id, $finding['references'][0]['id']);
            self::assertSame(['recorded' => false, 'reason' => 'unknown_dispute'], $bridge->callTool('HouseDebt', $this->claim() + ['disputes' => $secret]));
            foreach (['plugin:', 'mcp:'] as $prefix) {
                self::assertSame(['recorded' => false, 'reason' => 'owner'], $bridge->callTool('HouseDebt', ['owner' => $prefix . $secret] + $this->claim()));
            }
            self::assertStringNotContainsString($secret, json_encode([$ack, $finding, $this->sessions->stream('root')]));
        } finally {
            unlink($root . '/.milpa/secrets.json');
            rmdir($root . '/.milpa');
            rmdir($root);
        }
    }

    public function testNewReportNeverCopiesSecretsFromAHistoricalReceipt(): void
    {
        $root = sys_get_temp_dir() . '/milpa-debt-' . bin2hex(random_bytes(8));
        mkdir($root . '/.milpa', 0700, true);
        $secret = 'historical-debt-secret';
        $this->sessions->recordToolCall('root', 'fixture-' . $secret, [], json_encode(['ok' => false, 'code' => $secret]), false);
        $original = $this->sessions->stream('root');
        file_put_contents($root . '/.milpa/secrets.json', json_encode(['key' => $secret]));
        try {
            $bridge = $this->bridge(root: $root);
            $call = $bridge->callTool('HouseDebtRead', ['mode' => 'calls'])['items'][0];
            $ack = $bridge->callTool('HouseDebt', $this->claim() + ['about' => [$call['id']]]);
            self::assertTrue($ack['recorded']);
            $event = array_values(array_filter($this->sessions->stream('root'), static fn (Event $row): bool => $row->type === SessionHouseDebt::EVENT))[0];
            self::assertStringNotContainsString($secret, json_encode($event->payload));
            self::assertNull($call['code']);
            self::assertSame('redacted', $call['code_status']);
            self::assertNull($call['tool']);
            self::assertSame('redacted', $call['tool_status']);
            self::assertNull($event->payload['code']);
            self::assertSame('redacted', $event->payload['code_status']);
            self::assertNull($event->payload['operation']);
            self::assertSame('redacted', $event->payload['operation_status']);
            $finding = $bridge->callTool('HouseDebtRead', ['id' => $ack['id']])['items'][0];
            self::assertNull($finding['code']);
            self::assertNull($finding['operation']);
            self::assertSame($original, array_slice($this->sessions->stream('root'), 0, count($original)), 'the original receipt is not rewritten');
            self::assertSame($call['sha256'], $event->payload['references'][0]['sha256']);
        } finally {
            unlink($root . '/.milpa/secrets.json');
            rmdir($root . '/.milpa');
            rmdir($root);
        }
    }

    public function testFindingViewRechecksSecretAvailabilityWithoutChangingHistory(): void
    {
        $root = sys_get_temp_dir() . '/milpa-debt-' . bin2hex(random_bytes(8));
        mkdir($root . '/.milpa', 0700, true);
        $secret = 'later-declared-secret';
        try {
            $this->sessions->recordToolCall('root', 'fixture-' . $secret, [], json_encode(['ok' => false, 'code' => $secret]), false);
            $bridge = $this->bridge(root: $root);
            $id = $bridge->callTool('HouseDebtRead', ['mode' => 'calls'])['items'][0]['id'];
            $ack = $bridge->callTool('HouseDebt', $this->claim('Received ' . $secret) + ['about' => [$id]]);
            self::assertTrue($ack['recorded']);
            $original = $this->sessions->stream('root');
            file_put_contents($root . '/.milpa/secrets.json', json_encode(['key' => $secret]));
            $finding = $bridge->callTool('HouseDebtRead', ['id' => $ack['id']])['items'][0];
            self::assertNull($finding['code']);
            self::assertSame('redacted', $finding['code_status']);
            self::assertNull($finding['operation']);
            self::assertSame('redacted', $finding['operation_status']);
            self::assertTrue($finding['statement_redacted']);
            self::assertStringNotContainsString($secret, json_encode($finding));
            self::assertSame($original, $this->sessions->stream('root'));
        } finally {
            @unlink($root . '/.milpa/secrets.json');
            rmdir($root . '/.milpa');
            rmdir($root);
        }
    }

    public function testAnAlreadyRedactedReceiptDoesNotInventACodeOrOperation(): void
    {
        $this->sessions->recordToolCall('root', 'fixture-[secret]', [], json_encode(['ok' => false, 'code' => 'ERR_[secret]']), false);
        $bridge = $this->bridge();
        $call = $bridge->callTool('HouseDebtRead', ['mode' => 'calls'])['items'][0];
        self::assertNull($call['code']);
        self::assertSame('redacted', $call['code_status']);
        self::assertNull($call['tool']);
        self::assertSame('redacted', $call['tool_status']);
        $ack = $bridge->callTool('HouseDebt', $this->claim() + ['about' => [$call['id']]]);
        self::assertTrue($ack['recorded']);
        $finding = $bridge->callTool('HouseDebtRead', ['id' => $ack['id']])['items'][0];
        self::assertNull($finding['code']);
        self::assertSame('redacted', $finding['code_status']);
        self::assertNull($finding['operation']);
        self::assertSame('redacted', $finding['operation_status']);
    }

    public function testHostPlanModeCannotClaimGenericOrDebtExecutionAndExecuteStillWorks(): void
    {
        $runs = 0;
        $profile = (new SessionBookkeeping($this->sessions, 'root', $this->events))->operations()[0]->effects;
        $operation = new Operation('fixture:append', 'fixture', static function () use (&$runs): array {
            ++$runs;
            return ['ok' => true];
        }, mutating: true, effects: $profile, surfaces: ['mcp']);
        $bridge = $this->bridge(extra: [$operation]);
        $context = new ToolContext(principal: 'fixture', scopes: ['*'], mode: 'plan', extra: ['kept' => 'marker']);
        $bridge->setContext($context);
        self::assertArrayHasKey('plan', $bridge->callTool('fixture_append', []));
        self::assertArrayHasKey('plan', $bridge->callTool('HouseDebt', $this->claim()));
        self::assertSame(0, $runs);
        $planned = $this->sessions->stream('root');
        self::assertCount(0, array_filter($planned, static fn (Event $row): bool => $row->type === 'session.operation_executed'));
        self::assertCount(0, array_filter($planned, static fn (Event $row): bool => $row->type === SessionHouseDebt::EVENT));
        self::assertSame(ProgressReceipt::STALLED, ProgressReceipt::of($planned, 0, $this->events->nextSeq())->progress);
        $bridge->setContext(new ToolContext(principal: 'fixture', scopes: ['*'], mode: 'execute', extra: ['kept' => 'marker']));
        self::assertSame(['ok' => true], $bridge->callTool('fixture_append', []));
        self::assertTrue($bridge->callTool('HouseDebt', $this->claim())['recorded']);
        self::assertSame(1, $runs);
        $executed = $this->sessions->stream('root');
        self::assertCount(2, array_filter($executed, static fn (Event $row): bool => $row->type === 'session.operation_executed'));
        self::assertCount(1, array_filter($executed, static fn (Event $row): bool => $row->type === SessionHouseDebt::EVENT));
        self::assertSame(ProgressReceipt::ADVANCING, ProgressReceipt::of($executed, 0, $this->events->nextSeq())->progress);
    }

    public function testPlanningAPrerequisiteDoesNotSatisfyItUntilTheHandlerRuns(): void
    {
        $reads = 0;
        $operation = new Operation('fixture:next', 'fixture', static function () use (&$reads): array {
            ++$reads;
            return ['ok' => true];
        }, effects: EffectProfile::readOnly(), surfaces: ['mcp']);
        $bridge = $this->bridge(extra: [$operation], prerequisite: new PrerequisiteGate(['plan']));
        $bridge->setContext(new ToolContext(principal: 'fixture', scopes: ['*'], mode: 'plan'));
        self::assertArrayHasKey('plan', $bridge->callTool('plan', ['plan' => 'A first real plan']));
        $bridge->setContext(new ToolContext(principal: 'fixture', scopes: ['*'], mode: 'execute'));
        try {
            $bridge->callTool('fixture_next', []);
            self::fail('planning the prerequisite cannot satisfy it');
        } catch (\Exception $error) {
            self::assertStringContainsString('runs first', $error->getMessage());
        }
        self::assertSame(0, $reads);
        $bridge->callTool('plan', ['plan' => 'A first real plan']);
        self::assertSame(['ok' => true], $bridge->callTool('fixture_next', []));
        self::assertSame(1, $reads);
    }

    public function testWitnessIsBoundToOwnerProducerInputAndOneNewPersistedEvent(): void
    {
        $producer = new SessionHouseDebt($this->sessions, 'root', $this->events);
        $other = new SessionHouseDebt($this->sessions, 'root', $this->events);
        $foreignStore = new SessionStore(new InMemoryEventStore());
        $foreignStore->start('root', 'same id, another store');
        $foreign = new SessionHouseDebt($foreignStore, 'root', $this->events);
        $owner = new \stdClass();
        $input = $this->claim();
        $witness = $producer->witness($owner, $input);
        self::assertNull($witness->enter($other, $input));
        self::assertNull($witness->enter($foreign, $input));
        self::assertNull($witness->enter($producer, $this->claim('different input')));
        self::assertFalse($witness->consume(new \stdClass()));
        self::assertSame('unrecorded', $other->report($input, $witness)['reason']);
        self::assertTrue($producer->report($input, $witness)['recorded']);
        self::assertFalse($witness->consume(new \stdClass()));
        self::assertTrue($witness->consume($owner));
        self::assertFalse($witness->consume($owner), 'one observation cannot be consumed twice');
        self::assertSame('unrecorded', $producer->report($this->claim('copied context'), $witness)['reason']);

        $event = array_values(array_filter($this->sessions->stream('root'), static fn (Event $row): bool => $row->type === SessionHouseDebt::EVENT))[0];
        $later = $producer->witness($owner, $input);
        $entry = $later->enter($producer, $input);
        self::assertNotNull($entry);
        $later->confirm($producer, $entry, $event);
        $invented = new Event($event->streamId, $event->type, $event->payload, $this->events->nextSeq());
        $later->confirm($producer, $entry, $invented);
        self::assertFalse($later->consume($owner), 'an old or unpersisted event proves no append in this invocation');

        $expired = $producer->witness($owner, $this->claim('expired'));
        $expired->expire($owner);
        self::assertSame('unrecorded', $producer->report($this->claim('expired'), $expired)['reason']);
        self::assertFalse($expired->consume($owner));
    }

    public function testNestedCallsRestoreExplicitContextAndReplaceForeignWitnesses(): void
    {
        $bridge = null;
        $seen = [];
        $nested = new Operation('fixture:nested', 'fixture', function (array $input, ?InvocationContext $invocation, ToolContext $context) use (&$bridge, &$seen): array {
            $seen[] = $context;
            $first = $bridge->callTool('HouseDebt', $this->claim('nested first'));
            $second = $bridge->callTool('HouseDebt', $this->claim('nested second'));
            $seen[] = $context;

            return [$first, $second];
        }, scopes: ['fixture:read'], effects: EffectProfile::readOnly(), surfaces: ['mcp']);
        $bridge = $this->bridge(extra: [$nested]);
        $owner = new \stdClass();
        $foreign = (new SessionHouseDebt($this->sessions, 'root', $this->events))->witness($owner, $this->claim('foreign'));
        $context = new ToolContext(
            principal: 'explicit-caller',
            channel: 'cli',
            scopes: ['agent:run', 'fixture:read'],
            request_id: 'explicit-request',
            extra: ['kept' => 'marker', JournalAppendWitness::CONTEXT_KEY => $foreign]
        );
        $bridge->setContext($context);
        self::assertSame([true, true], array_column($bridge->callTool('fixture_nested', []), 'recorded'));
        self::assertFalse($foreign->consume($owner));
        foreach ($seen as $observed) {
            self::assertSame('explicit-caller', $observed->principal);
            self::assertSame(['agent:run', 'fixture:read'], $observed->scopes);
            self::assertSame('explicit-request', $observed->request_id);
            self::assertSame('marker', $observed->extra['kept']);
            self::assertArrayNotHasKey(JournalAppendWitness::CONTEXT_KEY, $observed->extra);
        }
        self::assertSame([true, true], array_column($bridge->callTool('fixture_nested', []), 'recorded'), 'nested exact retries do not borrow previous witnesses');
        self::assertCount(2, array_filter($this->sessions->stream('root'), static fn (Event $row): bool => $row->type === 'session.operation_executed'));
        $bridge->setContext(new ToolContext(principal: 'explicit-caller', channel: 'cli', scopes: []));
        try {
            $bridge->callTool('HouseDebt', $this->claim('no scope'));
            self::fail('explicit empty scopes must not become CLI wildcard');
        } catch (\Exception $error) {
            self::assertStringContainsString('scope', $error->getMessage());
        }
    }

    public function testAppendThrowCannotBorrowPreviousSuccessfulWitness(): void
    {
        $throws = false;
        $writer = $this->createMock(EventStoreInterface::class);
        $writer->method('nextSeq')->willReturnCallback(fn (): int => $this->events->nextSeq());
        $writer->method('append')->willReturnCallback(function (Event $event) use (&$throws): void {
            if ($throws) {
                throw new \RuntimeException('append failed');
            }
            $this->events->append($event);
        });
        $bridge = $this->bridge(writer: $writer);
        self::assertTrue($bridge->callTool('HouseDebt', $this->claim('first'))['recorded']);
        $throws = true;
        self::assertSame(['recorded' => false, 'reason' => 'unrecorded'], $bridge->callTool('HouseDebt', $this->claim('failed')));
        $throws = false;
        self::assertTrue($bridge->callTool('HouseDebt', $this->claim('last'))['recorded']);
        self::assertCount(2, array_filter($this->sessions->stream('root'), static fn (Event $row): bool => $row->type === 'session.operation_executed'));
    }

    public function testSuccessRetryFailureSuccessDoesNotReuseAnEffect(): void
    {
        $bridge = $this->bridge();
        $acks = [];
        foreach ([$this->claim('first'), $this->claim('first'), ['owner' => 'invalid'] + $this->claim(), $this->claim('second')] as $input) {
            $acks[] = $bridge->callTool('HouseDebt', $input);
        }
        self::assertSame([true, true, false, true], array_column($acks, 'recorded'));
        self::assertSame($acks[0], $acks[1]);
        $events = $this->sessions->stream('root');
        self::assertCount(2, array_filter($events, static fn (Event $row): bool => $row->type === SessionHouseDebt::EVENT));
        self::assertCount(2, array_filter($events, static fn (Event $row): bool => $row->type === 'session.operation_executed'));
    }

    public function testCodeIsUnavailableWhenOnlyTheRegistryMetadataHeldIt(): void
    {
        $op = new Operation('fixture:throws', 'fixture', static fn (): never => throw new \RuntimeException('precise failure'), effects: EffectProfile::readOnly(), surfaces: ['mcp']);
        $bridge = $this->bridge(extra: [$op]);
        try {
            $bridge->callTool('fixture_throws', []);
            self::fail('expected failure');
        } catch (\Exception $e) {
            self::assertSame('precise failure', $e->getMessage());
        }
        $calls = $bridge->callTool('HouseDebtRead', ['mode' => 'calls'])['items'];
        self::assertCount(1, $calls, 'normal recorder failure is not appended again at the bridge');
        $call = $calls[0];
        self::assertNull($call['code']);
        self::assertSame('unavailable', $call['code_status']);
        self::assertSame('precise failure', $call['result_excerpt']);
    }

    public function testARealRefusalAndAWrappedGateEachProduceExactlyOneReceiptPerAttempt(): void
    {
        $ran = 0;
        $op = new Operation('fixture:write', 'fixture', static function () use (&$ran): array {
            ++$ran;
            return ['ok' => true];
        }, mutating: true, surfaces: ['mcp']);
        $bridge = $this->bridge(extra: [$op]);
        $messages = [];
        for ($i = 0; $i < 2; ++$i) {
            try {
                $bridge->callTool('fixture_write', []);
                self::fail('a mutation was not authorized');
            } catch (\Exception $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertSame(0, $ran);
        $calls = $bridge->callTool('HouseDebtRead', ['mode' => 'calls'])['items'];
        self::assertCount(2, $calls);
        self::assertNotSame($calls[0]['id'], $calls[1]['id']);
        self::assertSame($messages, array_column($calls, 'result_excerpt'));
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll([$op], $registry, new DIContainer());
        $recorder = new SessionToolGate($this->sessions, $this->sessions->load('root'), [$op]);
        $wrapper = new class () implements ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return 'independent refusal';
            }
        };
        $wrapped = new ConsentBridge($registry, gate: $wrapper, recorder: $recorder);
        try {
            $wrapped->callTool('fixture_write', []);
            self::fail('expected wrapped refusal');
        } catch (\Exception $e) {
            self::assertSame('independent refusal', $e->getMessage());
        }
        self::assertCount(3, $bridge->callTool('HouseDebtRead', ['mode' => 'calls'])['items']);
    }
}
