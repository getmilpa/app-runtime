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

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Agent\AdmissionAwareGate;
use Milpa\AppRuntime\Agent\AdmissionAwareRegistry;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A word is lent only on a grant that is IN FORCE AND VERIFIED — and the seat still reads why
 * (greenhouse decisions/0615, the two rules the coordinator sent back on 2026-10-11).
 *
 * The house used to hand a seat the operation's words whenever a grant named the call, in whatever state the grant
 * was, so that the refusal would be the judge's. A grant that is suspended, superseded, revoked, expired or not
 * honoured lends nothing now: the gate refuses for the word the seat lacks, and its refusal cites the judge's
 * reason — a refusal that named only the missing word would name the wrong thing to fix.
 *
 * And a book that does not verify stops seats and whatever would write to it; a person's own call and the terminal
 * run as they do in a house with no book, so that the way to repair it stays open.
 *
 * @guards nothing lent on a grant that is not in force and verified, with the judge's reason cited by the refusal
 *         for the missing word, and that refusal written down; a word lent when a grant in force names the call,
 *         whatever the judge then finds; a broken book that refuses every enrolled seat and every standing act that
 *         writes, and lets a person's own call and the terminal through
 *
 * @refuses lending for the asking on a suspended, superseded, revoked, expired or not honoured grant; stopping a
 *          person's signed call because the book of grants is broken
 *
 * @subject-in milpa/app-runtime
 */
final class NothingIsLentOnAGrantThatDoesNotStandTest extends TestCase
{
    private LabHouse $house;

    private string $candidate;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
        $this->house->granted();
        $this->candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->candidate]);
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    /** The seat as the gate is to see it for this very call. */
    private function seenAtTheGate(): ToolContext
    {
        return $this->house->policy->contextFor($this->house->contextOf(LabHouse::SEAT), 'lab_merge', ['path' => $this->candidate]);
    }

    public function testAGrantInForceLendsWhateverTheJudgeThenFinds(): void
    {
        self::assertTrue($this->seenAtTheGate()->hasScope('lab:merge'), 'control: in force and verified, the word is lent');

        // In force, verified — and the candidate is another one: the word is still lent, and the JUDGE refuses.
        $unmeasured = $this->house->candidate('b.tar', 'candidate b');
        $seen = $this->house->policy->contextFor($this->house->contextOf(LabHouse::SEAT), 'lab_merge', ['path' => $unmeasured]);
        self::assertTrue($seen->hasScope('lab:merge'));
        $refused = (string) $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $unmeasured])['refused'];
        self::assertStringStartsWith('Standing grant «release-line» lends nothing to this call: condition «no-host-names» was never measured', $refused);
    }

    /** @return iterable<string, array{string, string}> */
    public static function waysAGrantDoesNotStand(): iterable
    {
        yield 'suspended' => ['suspended', 'Standing grant «release-line» is suspended'];
        yield 'revoked' => ['revoked', 'Standing grant «release-line» is revoked'];
        yield 'superseded' => ['superseded', 'Standing grant «release-line» is superseded'];
        yield 'expired' => ['expired', 'Standing grant «dated» expired at 2026-10-11T12:30:00+00:00'];
        yield 'not honoured: its signer may no longer give it' => ['demoted', 'is not honoured: the key that signed it does not hold `identity:enroll` today'];
        yield 'not honoured: its signature cannot be verified here' => ['unverifiable', 'is not honoured: its signature does not verify here'];
        yield 'not honoured: the operation changed since' => ['drifted', 'is not the operation it was when this grant was given'];
    }

    #[DataProvider('waysAGrantDoesNotStand')]
    public function testNothingIsLentAndTheRefusalForTheMissingWordSaysWhy(string $how, string $says): void
    {
        $house = $this->house;
        $this->unsettle($how);
        $before = \count($house->lines(StandingLedger::REFUSED));

        $seen = $this->seenAtTheGate();
        self::assertFalse($seen->hasScope('lab:merge'), "a word was lent on a grant that is {$how}");
        self::assertSame(LabHouse::SEAT_SCOPES, $seen->scopes);
        self::assertSame([], $seen->extra, 'nothing rides in the context: no word, no mark');

        // The seat is refused for the word it lacks — and reads why the grant that names its call lends nothing.
        $refused = (string) ($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $this->candidate])['refused'] ?? 'RAN');
        self::assertStringStartsWith("Missing required scope for tool 'lab_merge'. Need one of: lab:merge — context has: agent:run, lab:scan, lab:watch.", $refused);
        self::assertStringContainsString($says, $refused);
        self::assertSame([], $house->done('merged'));
        // …and the house wrote that refusal down, once.
        self::assertCount($before + 1, $house->lines(StandingLedger::REFUSED));
    }

    /** Bring the grant these tests start from to a state in which it does not stand. */
    private function unsettle(string $how): void
    {
        $house = $this->house;
        match ($how) {
            'suspended' => $house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped']),
            'revoked' => $house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line']),
            // Replaced by a grant that covers another operation: nothing in force names this call any more.
            'superseded' => $house->granted(['id' => 'second', 'supersedes' => 'release-line', 'covers' => [['operation' => 'lab:publish']]]),
            'expired' => $this->expire(),
            'demoted' => $house->enroll(LabHouse::PERSON, ['lab:merge'], LabHouse::PERSON),
            'unverifiable' => $house->revokeKey(LabHouse::PERSON),
            'drifted' => $this->drift(),
            // One character of one line that is not the first: the lines before it still read as they were.
            'broken' => file_put_contents($house->root . '/' . StandingLedger::PATH, str_replace('"fired":false', '"fired":true ', (string) file_get_contents($house->root . '/' . StandingLedger::PATH))),
        };
    }

    private function expire(): mixed
    {
        // The grant these tests start from has no date; one that has, and is past it.
        $this->house->granted(['id' => 'dated', 'supersedes' => 'release-line', 'expires_at' => '2026-10-11T12:30:00+00:00']);
        $this->house->later(3600);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        return null;
    }

    private function drift(): mixed
    {
        $merge = $this->house->operation('lab:merge');
        $this->house->offer(new \Milpa\Command\Operation('lab:merge', $merge->description, static fn (array $input): array => ['ok' => true, 'acted_on' => null], $merge->inputSchema, mutating: true, requiresConfirmation: true, scopes: $merge->scopes, effects: $merge->effects, actsOn: $merge->actsOn));

        return null;
    }

    /** @return iterable<string, array{string}> */
    public static function waysAToolStopsBeingOffered(): iterable
    {
        foreach (['suspended', 'revoked', 'superseded', 'expired', 'demoted', 'unverifiable', 'drifted', 'broken'] as $how) {
            yield $how => [$how];
        }
    }

    #[DataProvider('waysAToolStopsBeingOffered')]
    public function testAToolIsOfferedOnlyWhileAGrantThatNamesItStands(string $how): void
    {
        // Asked WITHOUT a call in hand — is this tool this caller's to see? — the answer follows the same rule.
        $inner = new PolicyGate();
        $inner->setCallPolicy($this->house->policy);
        $gate = new AdmissionAwareGate($inner);
        $seat = $this->house->contextOf(LabHouse::SEAT);
        self::assertTrue($gate->authorizeScopes($seat, 'lab_merge', ['lab:merge'])->allowed, 'control: while the grant stands, the tool is offered to its seat');
        self::assertFalse($gate->authorizeScopes($this->house->contextOf(LabHouse::OTHER_SEAT), 'lab_merge', ['lab:merge'])->allowed, 'control: and to no seat it does not name');

        $this->unsettle($how);
        $seat = $this->house->contextOf(LabHouse::SEAT);

        self::assertFalse($gate->authorizeScopes($seat, 'lab_merge', ['lab:merge'])->allowed, "the tool is still offered on a grant that is {$how}");
        self::assertSame($seat->scopes, $gate->contextFor($seat, 'lab_merge')->scopes);
    }

    public function testTheSameRefusalIsCitedByEveryDoorAndWrittenOnlyByTheOneThatCalls(): void
    {
        $house = $this->house;
        $seat = $house->contextOf(LabHouse::SEAT);
        $merge = $house->operation('lab:merge');
        $tool = new ToolDefinition('lab_merge', $merge->description, $merge->inputSchema ?? [], $merge->handler, scopes: $merge->scopes, mutating: true);
        $call = ['path' => $this->candidate];
        $asking = new PolicyGate();
        $asking->setCallPolicy($house->policy);
        self::assertTrue((new AdmissionAwareGate($asking))->authorizeCall($seat, $tool, $call)->allowed, 'control: while the grant stands, the same question is answered yes');
        $house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped']);

        // The gate a leg asks before it calls: the question is answered, citing the judge — and nothing is written.
        $inner = new PolicyGate();
        $inner->setCallPolicy($house->policy);
        $gate = new AdmissionAwareGate($inner);
        foreach ([$gate->authorize($seat, $tool, $call), $gate->authorizeCall($seat, $tool, $call)] as $asked) {
            self::assertFalse($asked->allowed);
            self::assertStringStartsWith("Missing required scope for tool 'lab_merge'.", (string) $asked->reason);
            self::assertStringContainsString('Standing grant «release-line» is suspended', (string) $asked->reason);
        }
        self::assertSame([], $house->lines(StandingLedger::REFUSED), 'a question is not a refused act');
        // Asked only whether the tool is offered, nothing is lent either — and there is no call to cite a reason for.
        self::assertFalse($gate->contextFor($seat, 'lab_merge')->hasScope('lab:merge'));
        self::assertSame("Missing required scope for tool 'lab_merge'. Need one of: lab:merge — context has: agent:run, lab:scan, lab:watch.", $gate->authorizeScopes($seat, 'lab_merge', ['lab:merge'])->reason);

        // The registry a leg calls through: the call is refused with the same sentence, and THAT is written down.
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('lab_merge', 'merge', ['type' => 'object'], static fn (): array => ['ok' => true], new ToolOptions(scopes: ['lab:merge']));
        $registry->getPolicyGate()->setCallPolicy($house->policy);
        $result = (new AdmissionAwareRegistry($registry))->call('lab_merge', $call, $seat);

        self::assertFalse($result->success);
        self::assertStringContainsString('Missing required scope', (string) $result->error);
        self::assertStringContainsString('Standing grant «release-line» is suspended', (string) $result->error);
        $written = $house->lines(StandingLedger::REFUSED);
        self::assertCount(1, $written);
        self::assertSame(['release-line', 'suspended'], [$written[0]['grant'], $written[0]['reason']]);
    }

    public function testTheHouseAddsNothingToARefusalThatIsNotItsToExplain(): void
    {
        $house = $this->house;
        $house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped']);
        $policy = $house->policy;
        $seat = $house->contextOf(LabHouse::SEAT);
        $call = ['path' => $this->candidate];
        $seen = $policy->contextFor($seat, 'lab_merge', $call);
        $word = "Missing required scope for tool 'lab_merge'. Need one of: lab:merge — context has: agent:run, lab:scan, lab:watch.";
        self::assertStringStartsWith($word . ' Standing grant «release-line» is suspended', (string) $policy->refusalAtTheGate($seen, 'lab_merge', ['lab:merge'], $call, $word, written: false), 'control: this is a refusal the house explains');

        // The gate refused for something else: its answer stands.
        self::assertNull($policy->refusalAtTheGate($seen, 'lab_merge', ['lab:merge'], $call, 'Rate limit exceeded'));
        // A tool this house's catalogue does not hold is no grant's.
        self::assertNull($policy->refusalAtTheGate($seat, 'not_catalogued', ['lab:merge'], $call));
        // A call no grant names — the grant fixes another channel — is refused for the word, and that is all there is to say.
        $beta = $call + ['channel' => 'beta'];
        self::assertNull($policy->refusalAtTheGate($policy->contextFor($seat, 'lab_publish', $beta), 'lab_publish', ['lab:publish'], $beta));
        self::assertSame("Missing required scope for tool 'lab_publish'. Need one of: lab:publish — context has: agent:run, lab:scan, lab:watch.", $house->as(LabHouse::SEAT, 'lab:publish', $beta)['refused']);
        // The terminal's own operator is nobody's seat.
        self::assertNull($policy->refusalAtTheGate($house->contextOf(null), 'lab_merge', ['lab:merge'], $call));
        self::assertSame([], $house->lines(StandingLedger::REFUSED), 'and none of it was written down as a refusal');

        // Through the registry a leg calls: what does not validate is refused for THAT, and a call that passes passes.
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('lab_merge', 'merge', ['type' => 'object', 'required' => ['path'], 'properties' => ['path' => ['type' => 'string']]], static fn (): array => ['ok' => true], new ToolOptions(scopes: ['lab:merge']));
        $registry->getPolicyGate()->setCallPolicy($policy);
        $door = new AdmissionAwareRegistry($registry);
        $invalid = $door->call('lab_merge', [], $seat);
        self::assertFalse($invalid->success);
        self::assertStringNotContainsString('Standing grant', (string) $invalid->error);
        self::assertStringNotContainsString('Missing required scope', (string) $invalid->error);
        self::assertSame([], $house->lines(StandingLedger::REFUSED));
        self::assertTrue($door->call('lab_merge', $call, $house->contextOf(null))->success, 'the operator, with no seat to speak of');
        self::assertTrue($door->call('lab_merge', $call)->success, 'and a call with no caller named is the operator\'s too');
    }

    public function testTheWordsThatAreAuthorityThemselvesAreNeverLentWhateverTheJudgeAnswered(): void
    {
        // The floors keep a grant off an operation that asks for every scope or for the word that recognizes
        // identities, so no answer of the judge ever reaches this with one. Asked directly, with an answer that says
        // «lend»: those two words are not lent, and the rest is.
        $asks = new \Milpa\Command\Operation('lab:mixed', 'Lab: asks for a word and for authority itself', static fn (array $input): array => ['ok' => true], scopes: ['lab:mixed', 'identity:enroll', '*', 'lab:scan']);
        $call = new \Milpa\Command\Standing\StandingCall('lab:mixed', [], LabHouse::SEAT, null, $asks->ceilingForCall([]), $this->house->now);
        $judge = $this->house->policy->standing();
        $seat = $this->house->contextOf(LabHouse::SEAT);

        self::assertSame(['lab:mixed'], $judge->lends($seat, $asks, new \Milpa\AppRuntime\Standing\StandingDecision($call, lendable: true)));
        self::assertSame([], $judge->lends($seat, $asks, new \Milpa\AppRuntime\Standing\StandingDecision($call)), 'an answer that does not say so lends nothing');
        self::assertSame([], $judge->lends($seat, $asks, null), 'and no answer lends nothing');
        self::assertSame([], $judge->lends($this->house->contextOf(LabHouse::STRANGER), $asks, new \Milpa\AppRuntime\Standing\StandingDecision($call, lendable: true)), 'whoever holds every scope lacks nothing');
    }

    public function testASeatThatHoldsTheWordIsStillGovernedAndTheJudgeAnswersItself(): void
    {
        // Nothing is lent, and nothing needs to be: the seat passes the gate on its own word, and the grant that
        // names its call governs it.
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:merge'], LabHouse::PERSON);
        $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped']);

        $refused = (string) $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $this->candidate])['refused'];

        self::assertStringStartsWith('Standing grant «release-line» is suspended', $refused);
        self::assertCount(1, $this->house->lines(StandingLedger::REFUSED));
    }

    public function testABrokenBookStopsSeatsAndStandingWritesAndLetsAPersonAndTheTerminalThrough(): void
    {
        $house = $this->house;
        $house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped']);
        $path = $house->root . '/' . StandingLedger::PATH;
        $whole = (string) file_get_contents($path);
        file_put_contents($path, 'x' . $whole);
        $broken = (string) file_get_contents($path);

        // Every enrolled seat, whatever it calls — and nothing is lent to it for the asking.
        self::assertSame(LabHouse::SEAT_SCOPES, $this->seenAtTheGate()->scopes);
        foreach ([[LabHouse::SEAT, 'lab:merge', ['path' => $this->candidate]], [LabHouse::SEAT, 'lab:scan', ['path' => $this->candidate]], [LabHouse::OTHER_SEAT, 'lab:watch', []], [LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'x']]] as [$seat, $operation, $input]) {
            $refused = (string) ($house->as($seat, $operation, $input)['refused'] ?? 'RAN');
            self::assertStringContainsString('The book of standing grants does not verify (line 1', $refused, "{$operation} by a seat");
            self::assertStringContainsString('Nothing runs for a seat until a person has looked at it', $refused);
        }
        // The seat that lacks the word reads both things: the word, and why nothing was lent for it.
        self::assertStringStartsWith("Missing required scope for tool 'lab_merge'.", (string) $house->as(LabHouse::SEAT, 'lab:merge', ['path' => $this->candidate])['refused']);

        // A person's own call, signed with their own key, runs as it does in a house with no book: the way to repair
        // it stays open. So does the terminal's, which is nobody's seat.
        self::assertSame(['ok' => true], $house->as(LabHouse::PERSON, 'lab:merge', ['path' => $this->candidate]));
        self::assertSame(['ok' => true], $house->as(null, 'lab:merge', ['path' => $this->candidate]));
        // …and both can READ the book, which says what is wrong with it.
        foreach ([LabHouse::PERSON, null] as $who) {
            self::assertStringContainsString('DOES NOT VERIFY: line 1', (string) ($house->as($who, 'standing:granted')['book'] ?? ''));
        }

        // Whatever would WRITE to the book is refused for everyone, the person and the terminal included — and says
        // that the book does not verify, not that the grant is not there.
        $writes = [];
        foreach ([LabHouse::PERSON, null] as $who) {
            $writes[] = ['suspended', $house->as($who, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'x'])];
        }
        $writes[] = ['granted', $house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $house->document(['id' => 'another'])])];
        $writes[] = ['withdrawn', $house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line'])];
        $writes[] = ['lifted', $house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => 'sha256:x'])];
        foreach ($writes as [$what, $answer]) {
            self::assertFalse($answer['ok'] ?? null, $what);
            self::assertStringStartsWith('the book of standing grants does not verify (line 1', (string) ($answer['error'] ?? ''), $what);
            self::assertStringEndsWith("a person looks at it before anything is added — nothing was {$what}", (string) $answer['error']);
        }
        self::assertSame($broken, (string) file_get_contents($path), 'nothing was added to it');

        // Repaired by hand, the house is as it was: the grant is suspended, and says so.
        file_put_contents($path, $whole);
        self::assertStringContainsString('Standing grant «release-line» is suspended', (string) ($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $this->candidate])['refused'] ?? 'RAN'));
    }

    public function testAKeyThatRecognizesIdentitiesIsNotASeatToABrokenBookEvenWhenAGrantNamesIt(): void
    {
        // THE ONE PLACE WHERE A BROKEN BOOK FREES WHAT A WHOLE ONE REFUSES, said by execution rather than left to be
        // found: a grant may name as its seat a key that also holds the word that recognizes identities. While the
        // book verifies the grant governs that key like any seat. Broken, the house reads who is a person from the
        // ledger of identities alone, and that key goes through on what it holds on its own.
        $house = $this->house;
        $house->enroll(LabHouse::OTHER_SEAT, ['identity:enroll', 'lab:merge'], LabHouse::PERSON);
        $house->granted(['id' => 'for-a-key-that-recognizes', 'to' => [LabHouse::OTHER_SEAT]]);
        $house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'for-a-key-that-recognizes', 'reason' => 'stopped']);
        self::assertStringStartsWith('Standing grant «for-a-key-that-recognizes» is suspended', (string) ($house->as(LabHouse::OTHER_SEAT, 'lab:merge', ['path' => $this->candidate])['refused'] ?? 'RAN'));

        $path = $house->root . '/' . StandingLedger::PATH;
        file_put_contents($path, 'x' . (string) file_get_contents($path));

        self::assertSame(['ok' => true], $house->as(LabHouse::OTHER_SEAT, 'lab:merge', ['path' => $this->candidate]));
    }
}
