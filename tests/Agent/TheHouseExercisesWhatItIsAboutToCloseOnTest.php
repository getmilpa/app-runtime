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
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\CapabilityExercise;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\HouseExecutedWork;
use Milpa\AppRuntime\Agent\LegClosure;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * THE CLOSURE EXERCISES WHAT IT IS ABOUT TO GIVE AS WHOLE (greenhouse decisions/0605, R1).
 *
 * Measured on twenty houses a build station left (greenhouse evidence/1166): the house closed six `verified`, and in
 * one of them three of its four operations call a method that does not exist — they fail the first time anyone uses
 * them. The house had read what they declare and called nothing, and said so: `exercised: "unjudged"`.
 *
 * So before it says `verified` on a capability, the house runs it once, in a trial that is discarded. What it found is
 * a receipt in the session's own stream, and the verdict reads that receipt — at the natural end, and between steps
 * too: it never runs anything between steps, and what it already saw stands there.
 */
final class TheHouseExercisesWhatItIsAboutToCloseOnTest extends TestCase
{
    private const GOAL = 'Build a plugin named Ledger to open an account and to list the accounts.';
    private const OPEN = 'src/Plugins/Ledger/Operations/OpenAccount.php';
    private const LISTS = 'src/Plugins/Ledger/Operations/ListAccounts.php';
    private const UNDEFINED = 'Call to undefined method App\Plugins\Ledger\Accounts::add()';

    private InMemoryEventStore $events;

    private SessionStore $store;

    private int $trials = 0;

    /** @var list<string> the capabilities the house was asked to exercise, in order */
    private array $asked = [];

    /** Whether each writer of this house says its own verification of what it wrote ({@see built()}). */
    private bool $verified = false;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', self::GOAL, AutonomyMode::Auto);
        $this->asked = [];
        $this->verified = false;
    }

    public function testBeforeItClosesTheHouseRunsWhatItSawDeclaredWhole(): void
    {
        $seq = $this->built();
        self::assertSame('unjudged', $this->verdict()['derivedFrom']['observation']['capability']['exercised'], 'the control: read from the declarations alone');

        $closure = $this->atTheEnd($this->ran());

        self::assertSame(['Ledger'], $this->asked);
        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('house_observation', $closure['scope']);
        self::assertSame(
            ['subject' => 'Ledger', 'seq' => $seq, 'capability' => ['operations' => 2, 'exercised' => 'ran', 'calls' => 4, 'answered' => 1, 'refused' => 1, 'behavior' => 'unjudged']],
            $closure['derivedFrom']['observation'],
            'it ran, with its counts — and whether it does what was asked is still not judged',
        );
    }

    public function testWhatThrewDoesNotCloseAndTheReasonNamesTheOperationTheClassAndTheFirstLine(): void
    {
        $seq = $this->built();

        $closure = $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));

        self::assertFalse($closure['verified']);
        self::assertSame(
            ["the house ran «Ledger» in a trial before closing on it (seq {$seq}) and 1 of its 2 operations threw: «ledger:open» threw Error: " . self::UNDEFINED
                . ' — an operation that throws is not whole: fix it with implement'],
            $this->seen($closure),
        );
        self::assertArrayNotHasKey('derivedFrom', $closure);
    }

    /** Decided by Rod (decisions/0605, question 3): everything thrown stops the closure, not only the engine's errors. */
    public function testAnExceptionOfTheAppsStopsItToo(): void
    {
        $this->built();

        $closure = $this->atTheEnd($this->threw([
            ['operation' => 'ledger:list', 'class' => 'DomainException', 'kind' => 'app', 'line' => 'no accounts yet', 'pass' => 1],
            ['operation' => 'ledger:open', 'class' => 'TypeError', 'kind' => 'engine', 'line' => 'Accounts::add(): Argument #1 must be of type string, int given', 'pass' => 2],
        ]));

        self::assertFalse($closure['verified']);
        self::assertStringContainsString('2 of its 2 operations threw: «ledger:list» threw DomainException: no accounts yet; «ledger:open» threw TypeError: Accounts::add(): Argument #1', $this->seen($closure)[0]);
    }

    /**
     * Without this the session could never repair what the house just told it: the next leg's first step would read
     * «declared whole, nothing changed», the epilogue would open, and two model calls would be left.
     */
    public function testBetweenStepsNothingRunsAndWhatTheHouseAlreadySawStands(): void
    {
        $this->built();
        self::assertTrue($this->betweenSteps()['verified'], 'before the natural end the house has not run it, and says so');
        self::assertSame('unjudged', $this->betweenSteps()['derivedFrom']['observation']['capability']['exercised']);
        self::assertSame([], $this->asked, 'reading the verdict between steps runs nothing');

        $end = $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));

        $next = $this->betweenSteps();
        self::assertFalse($next['verified'], 'the next leg does not open its epilogue over what threw');
        self::assertSame($this->seen($end), $this->seen($next));
        self::assertSame(['Ledger'], $this->asked);
    }

    public function testWhatIsDeclaredAgainIsExercisedAgainAndTheOldThrowNoLongerSpeaks(): void
    {
        $this->built();
        $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));

        $seq = $this->fill('OpenAccount', self::OPEN, $this->both());

        // The receipt no longer speaks of this house; what the house owes is its look. Between steps nothing runs, so
        // the epilogue does not open over a capability it last saw throw (decided by Rod, 2026-10-09).
        $between = $this->betweenSteps();
        self::assertFalse($between['verified']);
        self::assertCount(1, $between['reasons']);
        self::assertStringStartsWith('the house ran «Ledger» and 1 of its 2 operations threw', $between['reasons'][0]);
        self::assertSame('unjudged', $between['derivedFrom']['observation']['capability']['exercised'], 'the repaired declaration has not been run yet');

        $closure = $this->atTheEnd($this->ran());

        self::assertSame(['Ledger', 'Ledger'], $this->asked);
        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame($seq, $closure['derivedFrom']['observation']['seq']);
        self::assertSame('ran', $closure['derivedFrom']['observation']['capability']['exercised']);
    }

    /**
     * A repair — or a break — can land outside what the capability declares: a shared class, its entity, a service.
     * It declares nothing again, so a receipt bound only to the declaration would outlive the house it spoke of.
     */
    public function testAnyChangeThatLandsAfterTakesTheReceiptBackWhateverItSaid(): void
    {
        $seq = $this->built();
        $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));

        $elsewhere = $this->landElsewhere();

        $threwAt = $this->receiptSeq();
        $after = $this->verdict();
        self::assertFalse($after['verified']);
        // What threw is no longer said of a house that is not the one that was run — the receipt is taken back. What
        // IS said, and first, is what the house owes: it last saw it throw, and has not looked again (decided by Rod,
        // 2026-10-09).
        self::assertSame([
            "the house ran «Ledger» and 1 of its 2 operations threw (seq {$threwAt}); a change landed after that (seq {$elsewhere}) and the house has not run it since: it does not close on what it last saw throw",
            "the house changed at seq {$elsewhere} after its last observation (seq {$seq})",
        ], $this->seen($after));
        self::assertSame([], $this->verdict()['derivedFrom'] ?? [], 'and the house derives nothing from it');

        $this->setUp();
        $seq = $this->built();
        self::assertTrue($this->atTheEnd($this->ran())['verified']);
        $elsewhere = $this->landElsewhere();
        $after = $this->verdict();
        self::assertFalse($after['verified'], 'and what ran is no longer said either');
        self::assertSame(["the house changed at seq {$elsewhere} after its last observation (seq {$seq})"], $this->seen($after));
    }

    /**
     * It is the house's own act: not a change, not a trial of the session's, not work in the domain, and in nobody's
     * name. A reader of any of those must see the stream it saw before.
     */
    public function testTheReceiptIsNoChangeNoTrialAndNoWorkOfTheSession(): void
    {
        $seq = $this->built();
        $before = $this->store->stream('s');
        $lastChange = $this->verdict()['derivedFrom']['lastChangeSeq'];

        $closure = $this->atTheEnd($this->ran());

        $added = \array_slice($this->store->stream('s'), \count($before));
        self::assertCount(1, $added, 'one fact, and nothing else: no tool call, no trial run, no executed operation');
        self::assertSame(CapabilityExercise::EVENT, $added[0]->type);
        self::assertSame($seq, $added[0]->payload['last_change'], 'it says the house it ran on');
        self::assertSame([], array_intersect(array_keys($added[0]->payload), ['executed_by', 'seat', 'principal', 'tool', 'workspace']), 'it names no seat and no trial');
        self::assertSame($lastChange, $closure['derivedFrom']['lastChangeSeq'], 'the house did not change');
        self::assertSame(HouseExecutedWork::calls($before), HouseExecutedWork::calls($this->store->stream('s')), 'no work in the domain');
    }

    /**
     * A promotion that writes nothing shows the house the same capability again. It is the house that was run: what
     * was found of it stands, and it is not run again — nor does a second look take back what threw.
     */
    public function testSeenAgainWithNothingLandedSinceWhatTheHouseFoundStands(): void
    {
        $this->built();
        $thrown = $this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]);
        $end = $this->atTheEnd($thrown);

        $this->seenAgain();

        self::assertSame($this->seen($end), $this->seen($this->betweenSteps()), 'looking again is not a repair');
        $this->atTheEnd($thrown);
        self::assertSame(['Ledger'], $this->asked);

        $this->setUp();
        $this->built();
        $this->atTheEnd($this->ran());
        $again = $this->seenAgain();
        $closure = $this->atTheEnd($this->ran());
        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame($again, $closure['derivedFrom']['observation']['seq']);
        self::assertSame('ran', $closure['derivedFrom']['observation']['capability']['exercised']);
        self::assertSame(['Ledger'], $this->asked);
    }

    /** «Before it says verified»: a verdict that other facts keep open is not about to close on anything. */
    public function testAVerdictSomethingElseKeepsOpenRunsNothing(): void
    {
        $this->built();
        $this->store->setTodo('s', new Todo('t1', 'write the manual', TodoStatus::Pending));
        $open = $this->verdict();
        self::assertFalse($open['verified'], 'the control: a todo is open');
        self::assertSame('unjudged', $open['derivedFrom']['observation']['capability']['exercised'] ?? null, 'and the house did see the capability whole');

        $this->atTheEnd($this->ran());

        self::assertSame([], $this->asked);
        self::assertSame([], $this->receipts());
    }

    /**
     * Said in EVERY form the verdict takes. A session that planned with todos and closed them is judged by its own
     * record when the house does not derive its closure — and what the house saw is said there too, first. Without
     * that, closing the todos would make what threw vanish.
     */
    public function testWithTodosClosedWhatThrewIsStillSaidAndSaidFirst(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'Build Ledger', TodoStatus::Pending));
        $seq = $this->built();
        $this->store->completeTodo('s', 't1', Evidence::operationOk('e1', 'sandbox_promote'));
        self::assertTrue($this->verdict()['verified'], 'the control: with its todo closed and the capability whole, it would close');

        $closure = $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));

        self::assertFalse($closure['verified']);
        self::assertStringStartsWith("the house ran «Ledger» in a trial before closing on it (seq {$seq}) and 1 of its 2 operations threw", $closure['reasons'][0]);
        self::assertCount(1, $this->seen($closure));

        // A landing in that same session takes the receipt back in this form too: a session with todos whose house
        // observation does not stand is judged by its own record alone (decisions/0509 §3). What the house says there
        // then is what it OWES — it last saw the capability throw and has not looked again — so that record does not
        // close over it ({@see testALandingThatRepairsNothingDoesNotLetTheRecordCloseOverWhatLastThrew()}).
        $this->landElsewhere();
        $after = $this->verdict();
        self::assertCount(1, $this->seen($after));
        self::assertStringStartsWith('the house ran «Ledger» and 1 of its 2 operations threw', $after['reasons'][0]);
        self::assertFalse($after['verified']);
    }

    /**
     * THE HOUSE DOES NOT CLOSE ON WHAT IT LAST SAW THROW (greenhouse decisions/0605, decided by Rod on 2026-10-09).
     *
     * The hole, in what was published: a session planned with todos is judged by its own record when the house's
     * observation does not stand (decisions/0509 §3) — and any landing takes the house's receipt back. So a session
     * with its todos closed and every class verified by its own writer closed `verified` after a landing that
     * repaired nothing took back what threw. The last thing the house KNEW of that capability was that it threw, and
     * it had not looked again.
     */
    public function testALandingThatRepairsNothingDoesNotLetTheRecordCloseOverWhatLastThrew(): void
    {
        $this->plannedBuiltAndClosed();
        $thrown = $this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]);
        self::assertFalse($this->atTheEnd($thrown)['verified'], 'the control: the house ran it and it threw');
        $threwAt = $this->receiptSeq();

        $elsewhere = $this->landElsewhere();

        $after = $this->verdict();
        self::assertSame('recorded_work', $after['scope'], 'the control: the house no longer observes what landed, so the record judges');
        self::assertSame(
            ["the house ran «Ledger» and 1 of its 2 operations threw (seq {$threwAt}); a change landed after that (seq {$elsewhere}) and the house has not run it since: it does not close on what it last saw throw"],
            $after['reasons'],
            'and that record is whole: the one thing that holds the closure is what the house owes',
        );
        self::assertFalse($after['verified']);
    }

    /** At that natural end the house runs it again — once per landing. It throws: no closure, and the reason is true of THIS house. */
    public function testAtThatEndTheHouseRunsItAgainAndWhatStillThrowsDoesNotClose(): void
    {
        $seq = $this->plannedBuiltAndClosed();
        $thrown = $this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]);
        $this->atTheEnd($thrown);
        $elsewhere = $this->landElsewhere();
        $this->asked = [];

        $closure = $this->atTheEnd($thrown);

        self::assertSame(['Ledger'], $this->asked);
        self::assertFalse($closure['verified']);
        self::assertStringStartsWith("the house ran «Ledger» in a trial before closing on it (seq {$seq}) and 1 of its 2 operations threw: «ledger:open» threw Error", $closure['reasons'][0]);
        self::assertCount(1, $closure['reasons'], 'what it saw of the house as it is now, and nothing of what it owed');
        self::assertSame($elsewhere, $this->receipts()[1]['last_change'], 'the receipt says the house it ran on');

        $this->atTheEnd($thrown);
        self::assertSame(['Ledger'], $this->asked, 'once per landing: a leg that ends again reads the receipt');
    }

    /** It runs: what the house owed is paid, and the record judges as it did. */
    public function testWhenItRunsTheRecordJudgesAsItDid(): void
    {
        $this->plannedBuiltAndClosed();
        $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));
        $this->landElsewhere();
        $this->asked = [];

        $closure = $this->atTheEnd($this->ran());

        self::assertSame(['Ledger'], $this->asked);
        self::assertSame(['verified' => true, 'reasons' => [], 'scope' => 'recorded_work'], $closure);
        self::assertSame($closure, $this->atTheEnd($this->ran()));
        self::assertSame(['Ledger'], $this->asked);
    }

    /** «Before it says verified», as ever: with something else holding the closure the house runs nothing — and says what it owes. */
    public function testWhatTheHouseOwesIsRunOnlyWhenNothingElseHoldsTheClosure(): void
    {
        $this->plannedBuiltAndClosed();
        $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));
        $this->landElsewhere();
        $this->store->setTodo('s', new Todo('t2', 'write the manual', TodoStatus::Pending));
        $this->asked = [];

        $closure = $this->atTheEnd($this->ran());

        self::assertSame([], $this->asked);
        self::assertCount(2, $closure['reasons']);
        self::assertStringStartsWith('the house ran «Ledger» and 1 of its 2 operations threw', $closure['reasons'][0], 'what the house saw is said first');
        self::assertSame('1 todo open', $closure['reasons'][1]);
        self::assertFalse($closure['houseOwes']['holdsAlone']);
    }

    /**
     * The house cannot run it again — trials switched off since, no confinement. It tried and learned nothing: the
     * last thing it knows is still that it threw. Closed, with why; and not tried again until something lands.
     */
    public function testAHouseThatCannotRunItAgainDoesNotCloseAndSaysWhy(): void
    {
        $this->plannedBuiltAndClosed();
        $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));
        $threwAt = $this->receiptSeq();
        $elsewhere = $this->landElsewhere();
        $this->asked = [];
        $cannot = ['exercised' => 'unjudged', 'why' => 'this house runs no trial: they are switched off, or it cannot confine a process', 'operations' => 2, 'calls' => 0];

        $closure = $this->atTheEnd($cannot);

        self::assertSame(['Ledger'], $this->asked);
        self::assertSame(
            ["the house ran «Ledger» and 1 of its 2 operations threw (seq {$threwAt}); after the change at seq {$elsewhere} it could not run it again — this house runs no trial: they are switched off, or it cannot confine a process: it does not close on what it last saw throw"],
            $closure['reasons'],
        );
        $this->atTheEnd($cannot);
        self::assertSame(['Ledger'], $this->asked, 'once per landing, whatever it found');

        // The next landing is another house: it looks again, and this time it can.
        $this->landElsewhere();
        self::assertTrue($this->atTheEnd($this->ran())['verified']);
        self::assertSame(['Ledger', 'Ledger'], $this->asked);
    }

    /** In every form the verdict takes — here the house's own, with no todo — and between steps, where nothing runs. */
    public function testWhatTheHouseOwesHoldsEveryFormAndBetweenStepsNothingRuns(): void
    {
        $this->built();
        $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));
        $threwAt = $this->receiptSeq();
        $again = $this->fill('OpenAccount', self::OPEN, $this->both());
        $this->asked = [];

        $between = $this->betweenSteps();

        self::assertFalse($between['verified'], 'declared whole again is not seen running again');
        self::assertSame(["the house ran «Ledger» and 1 of its 2 operations threw (seq {$threwAt}); a change landed after that (seq {$again}) and the house has not run it since: it does not close on what it last saw throw"], $between['reasons']);
        self::assertSame('house_observation', $between['scope']);
        self::assertSame([], $this->asked);

        $closure = $this->atTheEnd($this->ran());

        self::assertSame(['Ledger'], $this->asked);
        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('ran', $closure['derivedFrom']['observation']['capability']['exercised']);
        self::assertArrayNotHasKey('houseOwes', $closure);
    }

    /** It is owed only for what THREW. What ran, and was taken back by a landing, the record closes over as it did. */
    public function testWhatRanAndWasTakenBackOwesNothing(): void
    {
        $this->plannedBuiltAndClosed();
        self::assertTrue($this->atTheEnd($this->ran())['verified']);
        $this->landElsewhere();
        $this->asked = [];

        $closure = $this->atTheEnd($this->ran());

        self::assertSame(['verified' => true, 'reasons' => [], 'scope' => 'recorded_work'], $closure);
        self::assertSame([], $this->asked);
    }

    /** Last seen NOT whole, that is what is said, and there is nothing to run. Whole again, the house still owes its look. */
    public function testACapabilityLastSeenNotWholeIsNotRunAndWholeAgainItIsOwed(): void
    {
        $this->plannedBuiltAndClosed();
        $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));
        $scaffolded = $this->scaffold('OpenAccount', self::OPEN, $this->both());
        $this->asked = [];

        $closure = $this->atTheEnd($this->ran());

        self::assertSame([], $this->asked);
        self::assertSame(["the house observed «Ledger» declaring 2 operations, 1 of them still the scaffold «make» landed (seq {$scaffolded}): ledger:open — fill it with implement"], $this->seen($closure));

        $this->fill('OpenAccount', self::OPEN, $this->both());
        self::assertStringStartsWith('the house ran «Ledger» and 1 of its 2 operations threw', $this->verdict()['reasons'][0]);
        self::assertTrue($this->atTheEnd($this->ran())['verified']);
        self::assertSame(['Ledger'], $this->asked);
    }

    /**
     * ONE END, EVERYTHING IT IS ABOUT TO CLOSE ON. Paying what it owed can leave the verdict about to close on
     * something the house has not run either: that is run too, before the verdict says `verified` — and nothing is
     * ever run twice at one end, whatever the verdict keeps saying.
     */
    public function testAtOneEndTheHouseRunsAllItIsAboutToCloseOnAndNothingTwice(): void
    {
        $owes = ['verified' => false, 'reasons' => ['the house ran «Ledger» and …'], 'scope' => 'recorded_work',
            'houseOwes' => ['capabilities' => [['subject' => 'Ledger', 'seq' => 7, 'threwAt' => 9, 'changedAt' => 11, 'tried' => false]], 'holdsAlone' => true]];
        $unrun = ['verified' => true, 'reasons' => [], 'scope' => 'house_observation',
            'derivedFrom' => ['observation' => ['subject' => 'Vault', 'seq' => 12, 'capability' => ['operations' => 1, 'exercised' => 'unjudged']], 'lastChangeSeq' => 12]];
        $whole = ['verified' => true, 'reasons' => [], 'scope' => 'house_observation',
            'derivedFrom' => ['observation' => ['subject' => 'Vault', 'seq' => 12, 'capability' => ['operations' => 1, 'exercised' => 'ran']], 'lastChangeSeq' => 12]];
        $verdicts = [$owes, $unrun, $whole];
        $ran = [];
        $exercise = static function (string $capability, int $seq) use (&$ran): array {
            $ran[] = [$capability, $seq];

            return ['exercised' => 'ran', 'operations' => 1, 'calls' => 2, 'answered' => 1, 'refused' => 0, 'threw' => 0, 'thrown' => []];
        };

        $closure = CapabilityExercise::atTheEnd($this->events, 's', static function () use (&$verdicts): array {
            return \count($verdicts) > 1 ? array_shift($verdicts) : $verdicts[0];
        }, $exercise);

        self::assertSame([['Ledger', 7], ['Vault', 12]], $ran);
        self::assertSame($whole, $closure);
        self::assertSame([11, 12], array_column($this->receipts(), 'last_change'));

        // A verdict that keeps saying it owes the same look is not run in a loop.
        $ran = [];
        $closure = CapabilityExercise::atTheEnd($this->events, 's', static fn (): array => $owes, $exercise);
        self::assertSame([['Ledger', 7]], $ran);
        self::assertSame($owes, $closure);

        // What it tried and could not run is not owed again; nor is anything while something else holds the closure.
        $tried = $owes;
        $tried['houseOwes']['capabilities'][0]['tried'] = true;
        $held = $owes;
        $held['houseOwes']['holdsAlone'] = false;
        $ran = [];
        CapabilityExercise::atTheEnd($this->events, 's', static fn (): array => $tried, $exercise);
        CapabilityExercise::atTheEnd($this->events, 's', static fn (): array => $held, $exercise);
        self::assertSame([], $ran);
    }

    public function testADeclarationIsExercisedOnce(): void
    {
        $this->built();
        $first = $this->atTheEnd($this->ran());
        $again = $this->atTheEnd($this->ran());

        self::assertSame(['Ledger'], $this->asked, 'a leg that ends again over the same declaration reads the receipt');
        self::assertSame($first, $again);

        $this->setUp();
        $this->built();
        $thrown = $this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]);
        $this->atTheEnd($thrown);
        $this->atTheEnd($thrown);
        self::assertSame(['Ledger'], $this->asked, 'and so does one that ends again over what threw');
    }

    public function testTheHouseRunsNothingItIsNotAboutToClose(): void
    {
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $this->scaffold('ListAccounts', self::LISTS, $this->both());
        $this->fill('OpenAccount', self::OPEN, $this->both());

        $closure = $this->atTheEnd($this->ran());

        self::assertFalse($closure['verified'], 'the control: one scaffold is left');
        self::assertSame([], $this->asked);
        self::assertSame([], $this->receipts());
    }

    public function testAHouseThatCannotRunItSaysSoAndClosesAsItDid(): void
    {
        $seq = $this->built();

        $closure = $this->atTheEnd(['exercised' => 'unjudged', 'why' => 'this house cannot confine a process', 'operations' => 2, 'calls' => 0]);

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(
            ['subject' => 'Ledger', 'seq' => $seq, 'capability' => ['operations' => 2, 'exercised' => 'unjudged', 'why' => 'this house cannot confine a process']],
            $closure['derivedFrom']['observation'],
        );
        $this->atTheEnd($this->ran());
        self::assertSame(['Ledger'], $this->asked, 'asked once: what it could not do is a receipt too');
    }

    public function testAHouseWithNothingToRunItWithLeavesNoReceipt(): void
    {
        $this->built();
        $before = $this->verdict();

        $closure = $this->atTheEnd(null);

        self::assertSame($before, $closure);
        self::assertSame([], $this->receipts());
    }

    public function testAReceiptOfAnotherDeclarationSaysNothingOfThisOne(): void
    {
        $seq = $this->built();
        CapabilityExercise::record(
            $this->events,
            's',
            ['subject' => 'Ledger', 'seq' => $seq - 2],
            $this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]])
        );
        CapabilityExercise::record(
            $this->events,
            's',
            ['subject' => 'Other', 'seq' => $seq],
            $this->threw([['operation' => 'other:do', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]])
        );

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('unjudged', $closure['derivedFrom']['observation']['capability']['exercised']);
    }

    /**
     * What is run is what the promotion the house closes on declared — read from its receipt, because the leg that
     * built a capability booted before any of it landed.
     */
    public function testTheOperationsAreThoseOfTheDeclarationTheHouseClosesOn(): void
    {
        $first = $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $this->scaffold('ListAccounts', self::LISTS, $this->both());
        $this->fill('OpenAccount', self::OPEN, $this->both());
        $last = $this->fill('ListAccounts', self::LISTS, $this->both());
        $stream = $this->store->stream('s');

        self::assertSame(['ledger:open'], CapabilityExercise::declaredAt($stream, 'Ledger', $first));
        self::assertSame(['ledger:open', 'ledger:list'], CapabilityExercise::declaredAt($stream, 'Ledger', $last));
        self::assertSame([], CapabilityExercise::declaredAt($stream, 'Other', $last), 'a capability that promotion did not declare');
        self::assertSame([], CapabilityExercise::declaredAt($stream, 'Ledger', $last - 1), 'the trial before it declared nothing');
    }

    public function testTheReceiptIsAFactOfTheSessionsOwnStream(): void
    {
        $seq = $this->built();
        $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));

        $receipts = $this->receipts();

        self::assertCount(1, $receipts);
        self::assertSame('Ledger', $receipts[0]['subject']);
        self::assertSame($seq, $receipts[0]['observation']);
        self::assertSame('threw', $receipts[0]['exercised']);
        self::assertSame([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]], $receipts[0]['thrown']);
        self::assertNotNull($this->store->load('s'), 'the session folds as before: the reducer skips a fact it does not know');
    }

    /**
     * Measured on a house a build run left (greenhouse evidence/1171): a session that does not close is also told of
     * every class it wrote that no call verified — eight lines there, before the one that says what threw. The verdict
     * says sixteen facts and counts the rest; what the house saw must never be among the counted.
     */
    public function testHoweverManyFactsAreRecordedWhatThrewIsAmongTheOnesSaid(): void
    {
        foreach (range(1, 18) as $i) {
            $this->fill("Extra{$i}", "src/Plugins/Ledger/Support/Extra{$i}.php", $this->both());
        }
        $this->built();

        $closure = $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));

        self::assertFalse($closure['verified']);
        self::assertCount(16, $closure['reasons']);
        self::assertStringStartsWith('… and ', (string) end($closure['reasons']));
        self::assertCount(1, $this->seen($closure), implode("\n", $closure['reasons']));
        self::assertStringContainsString('«ledger:open» threw Error: ' . self::UNDEFINED, $this->seen($closure)[0]);

        // And so is anything else the house says of itself: here, that it changed after it was last seen.
        $this->setUp();
        foreach (range(1, 18) as $i) {
            $this->fill("Extra{$i}", "src/Plugins/Ledger/Support/Extra{$i}.php", $this->both());
        }
        $seq = $this->built();
        $elsewhere = $this->landElsewhere();
        $stale = $this->verdict();
        self::assertCount(16, $stale['reasons']);
        self::assertSame(["the house changed at seq {$elsewhere} after its last observation (seq {$seq})"], $this->seen($stale));
    }

    /**
     * Measured blind on the house a build run left closed with three operations that throw (greenhouse evidence/1171):
     * the verdict carried ten reasons, and the one a resident can act on was the ninth — after eight lines of classes
     * no call verified. What the house saw is said FIRST.
     */
    public function testWhatTheHouseSawIsTheFirstThingSaid(): void
    {
        $this->built();

        $closure = $this->atTheEnd($this->threw([['operation' => 'ledger:open', 'class' => 'Error', 'kind' => 'engine', 'line' => self::UNDEFINED, 'pass' => 1]]));

        self::assertGreaterThan(1, \count($closure['reasons']), 'the control: the session\'s own record has something to say too');
        self::assertStringStartsWith('the house ran «Ledger» in a trial before closing on it', $closure['reasons'][0]);
        self::assertSame([0], array_keys($this->among($closure)), 'and it is said once');
    }

    public function testALongFirstLineIsCutAndManyThrowsAreCounted(): void
    {
        $this->built();
        $thrown = [];
        foreach (range(1, 9) as $i) {
            $thrown[] = ['operation' => "ledger:op{$i}", 'class' => 'Error', 'kind' => 'engine', 'line' => str_repeat('x', 400), 'pass' => 1];
        }

        $closure = $this->atTheEnd(['exercised' => 'threw', 'operations' => 9, 'calls' => 18, 'answered' => 0, 'refused' => 0, 'threw' => 9, 'thrown' => $thrown]);

        $said = $this->seen($closure)[0];
        self::assertStringContainsString('9 of its 9 operations threw', $said);
        self::assertStringContainsString('«ledger:op4» threw Error: ' . str_repeat('x', 200) . '…;', $said);
        self::assertStringNotContainsString('ledger:op5', $said);
        self::assertStringContainsString('; and 5 more — ', $said);
    }

    /** @return array<string, mixed> */
    private function ran(): array
    {
        return ['exercised' => 'ran', 'operations' => 2, 'calls' => 4, 'answered' => 1, 'refused' => 1, 'threw' => 0, 'thrown' => []];
    }

    /**
     * @param list<array<string, mixed>> $thrown
     *
     * @return array<string, mixed>
     */
    private function threw(array $thrown): array
    {
        $names = array_unique(array_column($thrown, 'operation'));

        return ['exercised' => 'threw', 'operations' => 2, 'calls' => 4, 'answered' => 2 - \count($names), 'refused' => 0, 'threw' => \count($names), 'thrown' => $thrown];
    }

    /**
     * Two operations scaffolded and filled: a capability the house sees declared whole. The last promotion's seq.
     *
     * `$verified`: every writer says its own verification of what it wrote, green — so the session's record holds a
     * current verification for each class, and that record alone can close a session planned with todos.
     */
    private function built(bool $verified = false): int
    {
        $this->verified = $verified;
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $this->scaffold('ListAccounts', self::LISTS, $this->both());
        $this->fill('OpenAccount', self::OPEN, $this->both());

        return $this->fill('ListAccounts', self::LISTS, $this->both());
    }

    /** The seq of the last receipt the house left of an exercise. */
    private function receiptSeq(): int
    {
        $seq = null;
        foreach ($this->store->stream('s') as $event) {
            $seq = $event->type === CapabilityExercise::EVENT ? $event->seq : $seq;
        }
        self::assertNotNull($seq);

        return $seq;
    }

    /** A session that planned its work with one todo, built the capability with every class verified, and closed the todo. */
    private function plannedBuiltAndClosed(): int
    {
        $this->store->setTodo('s', new Todo('t1', 'Build Ledger', TodoStatus::Pending));
        $seq = $this->built(verified: true);
        $this->store->completeTodo('s', 't1', Evidence::operationOk('e1', 'sandbox_promote'));

        return $seq;
    }

    /**
     * The natural end of a leg, with a house whose exercise answers `$receipt`.
     *
     * @param array<string, mixed>|null $receipt
     *
     * @return array<string, mixed>
     */
    private function atTheEnd(?array $receipt): array
    {
        return CapabilityExercise::atTheEnd(
            $this->events,
            's',
            fn (): array => $this->verdict(),
            function (string $capability) use ($receipt): ?array {
                $this->asked[] = $capability;

                return $receipt;
            },
        );
    }

    /** @return array<string, mixed> */
    private function betweenSteps(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);
        $verdict = LegClosure::betweenSteps($session, $this->store->stream('s'));
        self::assertNotNull($verdict);

        return $verdict;
    }

    /** @return list<array<string, mixed>> */
    private function receipts(): array
    {
        $receipts = [];
        foreach ($this->store->stream('s') as $event) {
            if ($event->type === CapabilityExercise::EVENT) {
                $receipts[] = $event->payload;
            }
        }

        return $receipts;
    }

    /** A promotion that wrote nothing, recorded as the house records one: no change, and the capability read again. */
    private function seenAgain(): int
    {
        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w' . $this->trials], (string) json_encode([
            'ok' => true,
            'promoted' => [],
            'capabilities' => [['predicate' => 'declared', 'subject' => 'Ledger', 'environment' => ['kind' => 'house'], 'operations' => $this->both()]],
        ]), mutating: false);
    }

    /** A promotion that lands a file outside the plugin: the house changes, and nothing is declared again. Its seq. */
    private function landElsewhere(): int
    {
        $workspace = 'w' . ++$this->trials;
        $changed = ['src/Support/Clock.php' => 'modified'];
        $this->store->recordToolCall('s', 'edit', ['class' => 'Clock'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => $workspace, 'changed' => $changed, 'output' => ['ok' => true],
        ] + $this->itsOwnVerification()), mutating: true);

        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => $workspace], (string) json_encode([
            'ok' => true,
            'promoted' => array_keys($changed),
            'evidence' => ['predicate' => 'promoted', 'subject' => $workspace, 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => $workspace], 'paths' => array_keys($changed)],
        ]), mutating: true);
    }

    /** @param list<array<string, mixed>> $operations */
    private function scaffold(string $class, string $file, array $operations): int
    {
        return $this->land('make', ['what' => 'operation', 'plugin' => 'Ledger', 'name' => $class], [$file => 'added'], $operations);
    }

    /** @param list<array<string, mixed>> $operations */
    private function fill(string $class, string $file, array $operations): int
    {
        return $this->land('implement', ['plugin' => 'Ledger', 'class' => $class], [$file => 'modified'], $operations);
    }

    /**
     * One writer rehearsed in its own trial, then that trial promoted with what the house read of the capability.
     *
     * @param array<string, mixed>       $arguments
     * @param array<string, string>      $changed
     * @param list<array<string, mixed>> $operations
     */
    private function land(string $tool, array $arguments, array $changed, array $operations): int
    {
        $workspace = 'w' . ++$this->trials;
        $this->store->recordToolCall('s', $tool, $arguments, (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => $workspace, 'changed' => $changed, 'output' => ['ok' => true],
        ] + $this->itsOwnVerification()), mutating: true);
        $paths = array_keys($changed);

        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => $workspace], (string) json_encode([
            'ok' => true,
            'promoted' => $paths,
            'evidence' => ['predicate' => 'promoted', 'subject' => $workspace, 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => $workspace], 'paths' => $paths],
            'capabilities' => [['predicate' => 'declared', 'subject' => 'Ledger', 'environment' => ['kind' => 'house'], 'operations' => $operations]],
        ]), mutating: true);
    }

    /**
     * What a writer adds to its answer when it verified what it wrote, in the words each one uses: `make` says
     * `verify`, `implement` and `edit` say what verified it.
     *
     * @return array<string, mixed>
     */
    private function itsOwnVerification(): array
    {
        return $this->verified ? ['ok' => true, 'verify' => ['ok' => true], 'verified' => 'its own test, green'] : [];
    }

    /** @return list<array<string, mixed>> */
    private function both(): array
    {
        return [$this->operation('ledger:open', self::OPEN), ['mutating' => false] + $this->operation('ledger:list', self::LISTS)];
    }

    /** @return array<string, mixed> */
    private function operation(string $name, ?string $file): array
    {
        return ['name' => $name, 'file' => $file, 'mutating' => true, 'effects' => true, 'scoped' => true];
    }

    /**
     * @param array<string, mixed> $closure
     *
     * @return list<string>
     */
    private function seen(array $closure): array
    {
        return array_values(array_filter($closure['reasons'], static fn (string $why): bool => str_starts_with($why, 'the house ')));
    }

    /**
     * The reasons the house gives of what it saw, at the places they hold among all of them.
     *
     * @param array<string, mixed> $closure
     *
     * @return array<int, string>
     */
    private function among(array $closure): array
    {
        return array_filter($closure['reasons'], static fn (string $why): bool => str_starts_with($why, 'the house '));
    }

    /** @return array<string, mixed> */
    private function verdict(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $this->store->facts('s'), $this->store->stream('s'));
    }
}
