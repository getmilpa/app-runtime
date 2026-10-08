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
use Milpa\Agent\Evidence;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\LegClosure;
use Milpa\Command\Consent\OperationId;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * THE HOUSE CLOSES A SESSION OF WORK ON THE RECEIPTS OF WHAT IT EXECUTED ITSELF (greenhouse decisions/0599).
 *
 * Measured on cattle (greenhouse evidence/1145 §6), app-runtime 0.213.0: three stations of work did what was asked —
 * every act ran in the house, confined, with the seat as principal, and the store ended as expected — and
 * `closure_derived` said `verified: false`: «nothing observed served in the house». The closure looked for something
 * served, and work serves nothing. But the house had already produced the observation when it executed: every act of
 * work the domain accepted left `session.operation_executed`, a fact of the house's own. The closure reads those.
 *
 * The streams here are the shape those stations left: a call, then the house's fact of having executed it.
 */
final class TheHouseClosesASessionOfWorkOnItsOwnReceiptsTest extends TestCase
{
    private const SEAT = 'key:SEAT';
    private const STATE = 'var/accounts.json';

    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'Open the account 1 and post an entry to it.', AutonomyMode::Auto);
    }

    public function testWorkThatRanInTheHouseCloses(): void
    {
        $this->work('ledger_open', 'a0', 'a1');
        $this->work('ledger_post', 'a1', 'a2');

        self::assertFalse($this->verdict(admitted: false)['verified'], 'the control: a house that is not told what was admitted says what the published one said');

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('house_execution', $closure['scope']);
        self::assertSame(
            ['work' => ['executed' => 2, 'refused_by_the_domain' => 0, 'state' => [['path' => self::STATE, 'after' => 'sha256:a2']], 'asked' => 'unjudged']],
            $closure['derivedFrom'],
            'the house saw what was done and the state it left — not whether that was what was asked',
        );
    }

    public function testWithoutAReceiptItDoesNotClose(): void
    {
        $this->store->recordToolCall('s', 'ledger_open', ['number' => 1], (string) json_encode(['ran_in_house' => true, 'changed' => true, 'output' => ['ok' => true]]), mutating: true);

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'what a tool answers is not the house\'s fact');
        self::assertContains('«ledger_open» was accepted and the house has no receipt of having run it (seq 2): only what the house executed is work', $closure['reasons']);
    }

    public function testAReceiptOfATrialDoesNotClose(): void
    {
        $seq = $this->work('ledger_open', 'a0', 'a1', environment: 'trial');

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("«ledger.open» ran in a trial, not in the house (seq {$seq}): a rehearsal is not work", $closure['reasons']);
    }

    public function testAnAcceptedCallThatDidNotRunInTheHouseStopsIt(): void
    {
        $this->work('ledger_open', 'a0', 'a1');
        $seq = $this->store->recordToolCall('s', 'ledger_post', ['number' => 1], (string) json_encode(['ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'changed' => [], 'output' => ['ok' => true]]), mutating: true);

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'one act in the house does not carry another that only ran in a copy');
        self::assertContains("«ledger_post» was accepted and the house has no receipt of having run it (seq {$seq}): only what the house executed is work", $closure['reasons']);
    }

    public function testWorkOutsideWhatAPersonAdmittedDoesNotClose(): void
    {
        $seq = $this->work('ledger_open', 'a0', 'a1', principal: 'key:SOMEONE');

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'the admission is what bounds the work a seat may do');
        self::assertContains("«ledger.open» ran in the house for «key:SOMEONE» outside any admission (seq {$seq}): only work a person admitted closes", $closure['reasons']);
    }

    public function testAPrincipalTheHouseDidNotVerifyHoldsNoAdmission(): void
    {
        $this->store->recordToolCall('s', 'ledger_open', ['number' => 1], (string) json_encode(['ran_in_house' => true, 'changed' => true, 'output' => ['ok' => true]]), mutating: true);
        $this->store->recordExecution('s', 'ledger.open', new Principal(self::SEAT, false), 'claimed', null, 'sha256:x', [
            'environment' => 'house', 'confined' => true, 'changed' => true, 'pre_image' => 'k1', 'state' => [['path' => self::STATE, 'before' => 'sha256:a0', 'after' => 'sha256:a1']],
        ]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'a name the house did not verify is a name anybody can say');
        self::assertContains('«ledger.open» ran in the house for «nobody the house verified» outside any admission (seq 3): only work a person admitted closes', $closure['reasons']);
    }

    public function testOneActOutsideWhatWasAdmittedStopsTheRest(): void
    {
        $this->work('ledger_open', 'a0', 'a1');
        $seq = $this->work('ledger_close', 'a1', 'a2');

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains('«ledger.close» ran in the house for «' . self::SEAT . "» outside any admission (seq {$seq}): only work a person admitted closes", $closure['reasons']);
    }

    public function testOnlyActsTheDomainRefusedCloseNothing(): void
    {
        $this->refusedByTheDomain('ledger_post');
        $this->refusedByTheDomain('ledger_post');

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains('the work of this session changed nothing the house keeps: 2 acts, all refused by the domain', $closure['reasons']);
    }

    public function testOnlyReadsAreJudgedAsBefore(): void
    {
        $this->store->recordToolCall('s', 'ledger_list', [], (string) json_encode(['ok' => true, 'accounts' => []]), mutating: false);

        self::assertSame($this->verdict(admitted: false), $this->verdict(), 'a read is not work: it neither closes nor stands in the way');
    }

    public function testAnActTheDomainRefusedLetsItClose(): void
    {
        $this->work('ledger_open', 'a0', 'a1');
        $this->refusedByTheDomain('ledger_post');

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(1, $closure['derivedFrom']['work']['refused_by_the_domain'], 'refusing well is working well: it is counted and said');
        self::assertSame(1, $closure['derivedFrom']['work']['executed']);
    }

    public function testTheReceiptsHaveToChain(): void
    {
        $first = $this->work('ledger_open', 'a0', 'a1');
        $second = $this->work('ledger_post', 'aX', 'a2');

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'somebody else changed that state between the two acts');
        self::assertContains('the state at «' . self::STATE . "» was changed by someone else between the acts at seq {$first} and seq {$second}", $closure['reasons']);
    }

    public function testAnActThatLeftTheHouseAsItWasIsNotWork(): void
    {
        $this->work('ledger_open', 'a0', 'a0', changed: false);

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'whatever the verb answered, the digests say nothing changed');
        self::assertArrayNotHasKey('derivedFrom', $closure);
    }

    public function testACallNobodyAdmittedIsStillWaiting(): void
    {
        $this->work('ledger_open', 'a0', 'a1');
        $seq = $this->store->recordToolCall('s', 'ledger_post', ['number' => 1], '«ledger:post» is a verb of the capability «Ledger», built in this house, and no person has admitted it for this seat: it is admitted under \'ledger:write\' of «Ledger».', mutating: true, ok: false);

        $waiting = $this->verdict();
        self::assertFalse($waiting['verified']);
        self::assertContains("a call of «ledger_post» was refused for lack of an admission, and nobody has admitted it (seq {$seq})", $waiting['reasons']);

        $this->events->append(new Event(
            streamId: SessionStore::PREFIX . 's',
            type: 'session.refusal_granted',
            payload: ['seq' => $seq, 'tool' => 'ledger_post', 'permission' => 'ledger:write', 'authorized_by' => 'key:OWNER'],
            seq: $this->events->nextSeq()
        ));
        self::assertTrue($this->verdict()['verified'], 'a person admitted it: it no longer waits on anybody');
    }

    public function testWithTodosItSaysBothRecords(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'Open the account', TodoStatus::Pending));
        $this->work('ledger_open', 'a0', 'a1');
        $this->store->completeTodo('s', 't1', Evidence::operationOk('e1', 'ledger_open'));

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('recorded_work_and_house_execution', $closure['scope']);
    }

    public function testWorkDoesNotMakeTheObservationOfACapabilityStale(): void
    {
        $this->store = new SessionStore($this->events = new InMemoryEventStore());
        $this->store->start('s', 'Build a plugin named Ledger to open an account, and open the account 1.', AutonomyMode::Auto);
        $file = 'src/Plugins/Ledger/Operations/OpenAccount.php';
        $this->store->recordToolCall('s', 'implement', ['plugin' => 'Ledger', 'class' => 'OpenAccount'], (string) json_encode(['ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'changed' => [$file => 'modified'], 'output' => ['ok' => true]]), mutating: true);
        $seen = $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode(['ok' => true, 'promoted' => [$file],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w1', 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => 'w1'], 'paths' => [$file]],
            'capabilities' => [['predicate' => 'declared', 'subject' => 'Ledger', 'environment' => ['kind' => 'house'],
                'operations' => [['name' => 'ledger:open', 'file' => $file, 'mutating' => true, 'effects' => true, 'scoped' => true]]]]]), mutating: true);
        $this->work('ledger_open', 'a0', 'a1');

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], 'work changes data, not what the capability declares: ' . implode('; ', $closure['reasons']));
        self::assertSame('house_observation', $closure['scope']);
        self::assertSame($seen, $closure['derivedFrom']['observation']['seq']);
        self::assertSame(1, $closure['derivedFrom']['work']['executed'], 'and the verdict carries both: what the house saw declared and what it executed');
    }

    public function testOnlyAReceiptOfTheHouseMakesACallWork(): void
    {
        $this->store = new SessionStore($this->events = new InMemoryEventStore());
        $this->store->start('s', 'Build a plugin named Ledger to open an account, and open the account 1.', AutonomyMode::Auto);
        $file = 'src/Plugins/Ledger/Operations/OpenAccount.php';
        $seen = $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode(['ok' => true, 'promoted' => [$file],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w1', 'environment' => ['kind' => 'house'], 'paths' => [$file]],
            'capabilities' => [['predicate' => 'declared', 'subject' => 'Ledger', 'environment' => ['kind' => 'house'],
                'operations' => [['name' => 'ledger:open', 'file' => $file, 'mutating' => true, 'effects' => true, 'scoped' => true]]]]]), mutating: true);
        $call = $this->work('ledger_open', 'a0', 'a1', environment: 'trial') - 1;

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains(
            "the house changed at seq {$call} after its last observation (seq {$seen})",
            $closure['reasons'],
            'a call whose receipt is not of the house is no work in the house: it is a change like any other, and what the house saw is stale'
        );
    }

    public function testWorkDoesMakeTheObservationOfAPageStale(): void
    {
        $this->store = new SessionStore($this->events = new InMemoryEventStore());
        $this->store->start('s', 'Serve GET /accounts listing the accounts, and open the account 1.', AutonomyMode::Auto);
        $seen = $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode(['ok' => true, 'promoted' => ['config/screens.json'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w1', 'environment' => ['kind' => 'house'], 'paths' => ['config/screens.json']],
            'observed' => [['predicate' => 'served', 'route' => 'GET /accounts', 'subject' => '/accounts', 'status' => 200, 'environment' => ['kind' => 'house'], 'servedAt' => '/accounts', 'bytes' => 9, 'sha256' => str_repeat('c', 64)]]]), mutating: true);
        $worked = $this->work('ledger_open', 'a0', 'a1') - 1;

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'a page lists that data: what the house saw of it is no longer what it serves');
        self::assertContains("the state of the house changed at seq {$worked} after it observed «/accounts» (seq {$seen})", $closure['reasons']);
    }

    /**
     * Measured on cattle (greenhouse evidence/1150): read between steps, the receipts opened the epilogue after the
     * FIRST act of a station that asks for six, and the house closed it verified with three done. The house cannot
     * tell the first act of a request from its last.
     */
    public function testBetweenStepsWorkDoesNotCloseTheSession(): void
    {
        $this->work('ledger_open', 'a0', 'a1');
        $session = $this->store->load('s');
        self::assertNotNull($session);
        $stream = $this->store->stream('s');

        $between = LegClosure::betweenSteps($session, $stream);
        $atTheEnd = LegClosure::atTheEnd($session, $stream, static fn (array $contract): array => [], null, $this->admitted());

        self::assertNotNull($between);
        self::assertFalse($between['verified'], 'more acts may be coming: the session says when it is done, not the house');
        self::assertTrue($atTheEnd['verified'], implode('; ', $atTheEnd['reasons']));
        self::assertSame('house_execution', $atTheEnd['scope']);
    }

    /**
     * One act of work as the house records it: the call, then its own fact of having executed it. Returns the seq of
     * that fact.
     */
    private function work(string $tool, string $before, string $after, bool $changed = true, string $environment = 'house', string $principal = self::SEAT): int
    {
        $this->store->recordToolCall('s', $tool, ['number' => 1], (string) json_encode(['ran_in_house' => true, 'changed' => $changed, 'output' => ['ok' => true]]), mutating: true);
        $this->store->recordExecution('s', (new OperationId($tool))->canonical, new Principal($principal, true), 'signature', null, 'sha256:' . md5($tool . $after), [
            'environment' => $environment, 'confined' => true, 'changed' => $changed, 'pre_image' => 'k1',
            'state' => [['path' => self::STATE, 'before' => 'sha256:' . $before, 'after' => 'sha256:' . $after]],
        ]);
        $stream = $this->store->stream('s');

        return $stream[array_key_last($stream)]->seq;
    }

    /**
     * An act the domain itself refused, as the house records it (measured, greenhouse evidence/1145): the verb ran
     * and said no — a call that failed, with what it left: nothing. It leaves no fact of execution.
     */
    private function refusedByTheDomain(string $tool): int
    {
        return $this->store->recordToolCall('s', $tool, ['number' => 9], (string) json_encode(['ok' => false, 'ran_in_house' => true, 'changed' => false,
            'state' => [['path' => self::STATE, 'before' => 'sha256:a1', 'after' => 'sha256:a1']], 'pre_image' => null, 'code' => 'not_open', 'message' => 'That account is not open.']), mutating: true, ok: false);
    }

    /**
     * The house's reading of what a person admitted: the seat holds `ledger:open`, `ledger:post` and `ledger:list`;
     * `ledger:close` is a built verb nobody admitted to it; anything else is no verb of a built capability.
     */
    private function admitted(): \Closure
    {
        return static function (string $operation, ?string $principal): ?bool {
            $verb = (new OperationId($operation))->canonical;
            if (!\in_array($verb, ['ledger.open', 'ledger.post', 'ledger.list', 'ledger.close'], true)) {
                return null;
            }

            return $principal === self::SEAT && $verb !== 'ledger.close';
        };
    }

    /** @return array<string, mixed> */
    private function verdict(bool $admitted = true): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $this->store->facts('s'), $this->store->stream('s'), null, $admitted ? $this->admitted() : null);
    }
}
