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

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\AppRuntime\Standing\StandingOperations;
use Milpa\Console\OperationBoundary;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A standing grant is given by a person, with a signature over the whole document — and some grants cannot be
 * given by anyone (greenhouse decisions/0615, «Decidido por Rod»).
 *
 * @guards a grant written into the book with the bytes that were signed, by a key that holds `identity:enroll`,
 *         answers for every seat it lends to and is none of them; a warning when a seat already holds the word; a
 *         house born with no grant and no book
 *
 * @refuses a grant without the signature that names who gives it, or under a signature over another document; one
 *          a seat signs, one a key nobody recognized signs, one that lends to its own signer; a grant over an
 *          operation that itself gives authority, that declares no scope, that does not say what it acts on, or
 *          that does not declare its effects; a condition no instrument of this house can measure, a falsifier no
 *          operation of this house watches; a ceiling above compensatable; a line nothing reads; an id already taken
 *
 * @subject-in milpa/app-runtime
 */
final class OnlyAPersonGivesAStandingGrantTest extends TestCase
{
    private LabHouse $house;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    public function testAHouseIsBornWithNoGrantAndNoBook(): void
    {
        $listed = $this->house->as(LabHouse::PERSON, 'standing:granted');

        self::assertTrue($listed['ok']);
        self::assertSame([], $listed['grants']);
        self::assertStringContainsString('Nobody has given a standing grant', $listed['note']);
        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH, 'reading writes nothing');
    }

    public function testAPersonGivesAGrantWithASignatureOverTheWholeDocument(): void
    {
        $document = $this->house->document();

        $given = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $document]);

        self::assertTrue($given['ok'], (string) ($given['error'] ?? ''));
        self::assertSame('release-line', $given['grant']);
        self::assertSame('key:' . LabHouse::PERSON, $given['granted_by']);
        self::assertSame([LabHouse::SEAT], $given['lends_to']);
        self::assertSame(['lab:publish (channel="stable")', 'lab:merge', 'lab:announce'], $given['covers']);
        self::assertSame(['no-host-names — measured by lab:scan'], $given['conditions']);
        self::assertSame('lab:watch, every 3600 s at most', $given['watched_by']);
        self::assertStringContainsString('lends nothing until its watcher has given a sign of life', $given['next']);
        self::assertStringContainsString('cannot bound what a call spends', $given['next']);
        self::assertCount(1, $given['warnings']);
        self::assertStringContainsString('`lab:announce` can do more than this grant\'s ceiling allows', $given['warnings'][0]);

        // What the book keeps is what was signed, byte for byte, and the grant is read back out of those bytes.
        $line = $this->house->lines(StandingLedger::GRANTED)[0];
        self::assertSame($given['digest'], $line['digest']);
        self::assertSame(LabHouse::PERSON, $line['fingerprint']);
        self::assertStringContainsString(json_encode($document), $line['payload']);
        $record = $this->house->book()->record('release-line');
        self::assertNotNull($record);
        self::assertNull($record->fault);
        self::assertSame($given['digest'], $record->grant->digest());
        self::assertSame('in force', $record->standsAs($this->house->now));
    }

    public function testASeatThatAlreadyHoldsTheWordIsToldTheGrantGovernsItFromNowOn(): void
    {
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:merge'], LabHouse::PERSON);

        $given = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document()]);

        self::assertTrue($given['ok']);
        self::assertStringContainsString('already holds «lab:merge» on its own: from now on this grant governs its calls to `lab:merge`', implode("\n", $given['warnings']));
    }

    public function testWithoutTheSignatureThatNamesWhoGivesItNothingIsGranted(): void
    {
        $document = $this->house->document();

        $unsigned = $this->house->as(LabHouse::PERSON, 'standing:grant', ['grant' => $document]);
        self::assertStringContainsString('requires the signature that names WHO decides', $unsigned['error']);

        // A signature over ANOTHER document — one that lends to a second seat — does not cover this one.
        $other = $this->house->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['to' => [LabHouse::SEAT, LabHouse::OTHER_SEAT]])]);
        $swapped = $this->house->as(LabHouse::PERSON, 'standing:grant', ['grant' => $document], $other);
        self::assertStringContainsString('does not cover THIS standing:grant', $swapped['error']);

        // Nor does a signature over another operation.
        $elsewhere = $this->house->as(LabHouse::PERSON, 'standing:grant', ['grant' => $document], $this->house->signature(LabHouse::PERSON, 'standing:revoke', ['grant' => $document]));
        self::assertStringContainsString('does not cover THIS standing:grant', $elsewhere['error']);

        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH);
    }

    public function testNoSeatGivesAGrantAndNoKeyTheHouseNeverRecognized(): void
    {
        $document = $this->house->document();

        // A seat does not hold the word that recognizes identities: the gate stops it before anything is read.
        $seat = $this->house->signed(LabHouse::SEAT, 'standing:grant', ['grant' => $document]);
        self::assertStringContainsString("Missing required scope for tool 'standing_grant'. Need one of: identity:enroll", $seat['refused']);

        // A key nobody recognized runs at the terminal with every scope — measured (decisions/0615). It still gives
        // no grant: it answers for no seat.
        $stranger = $this->house->signed(LabHouse::STRANGER, 'standing:grant', ['grant' => $document]);
        self::assertFalse($stranger['ok']);
        self::assertStringContainsString('does not answer for seat', $stranger['error']);
        self::assertStringContainsString('nothing was granted', $stranger['error']);

        // And nobody lends to themselves — not even a person.
        $self = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['to' => [LabHouse::PERSON]])]);
        self::assertStringContainsString('nobody lends to themselves', $self['error']);

        // A person who holds the word and did not enroll the seat does not lend to it either.
        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll'], LabHouse::STRANGER);
        $another = $this->house->signed(LabHouse::STRANGER, 'standing:grant', ['grant' => $document]);
        self::assertStringContainsString('does not answer for seat', $another['error']);

        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH);
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('grantsNobodyCanGive')]
    public function testSomeGrantsCannotBeGivenByAnyone(array $change, string $says): void
    {
        $given = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document($change)]);

        self::assertFalse($given['ok'] ?? true);
        self::assertStringContainsString($says, $given['error']);
        self::assertStringContainsString('nothing was granted', $given['error']);
        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function grantsNobodyCanGive(): iterable
    {
        $ceiling = ['mutation' => 'persistent', 'externality' => 'public', 'reversibility' => 'compensatable', 'authority' => 'write_as_user', 'subject' => 'executable'];
        $condition = ['name' => 'c', 'statement' => 's'];
        $falsifier = ['name' => 'f', 'statement' => 's', 'within_seconds' => 60];

        // The first floor: nothing a grant lends may itself give authority.
        yield 'over an operation that asks for the word that recognizes identities' => [['covers' => [['operation' => 'lab:enroll']]], '`lab:enroll` asks for `identity:enroll`: it is an operation that itself gives authority, and no grant lends one'];
        yield 'over the act that restores a grant' => [['covers' => [['operation' => 'standing:reinstate']]], 'nothing a grant lends may give, restore, withdraw or stop a grant'];
        yield 'over the act that gives a grant' => [['covers' => [['operation' => 'standing:grant']]], 'nothing a grant lends may give, restore, withdraw or stop a grant'];
        // The second: a grant never lends what cannot at least be compensated.
        yield 'a ceiling that admits the irreversible' => [['ceiling' => ['reversibility' => 'irreversible'] + $ceiling], 'the highest it carries is «compensatable»'];
        yield 'a ceiling with an unclassified axis' => [['ceiling' => ['authority' => 'unknown'] + $ceiling], 'an unclassified ceiling admits everything'];
        // Money: there is no axis, and a line the judge would ignore is a limit nobody keeps.
        yield 'a budget' => [['budget' => 50], 'carries "budget", which nothing reads'];
        // What this house could never judge.
        yield 'an operation this house does not have' => [['covers' => [['operation' => 'lab:nowhere']]], 'this house has no operation `lab:nowhere` to grant'];
        yield 'an operation that declares no scope' => [['covers' => [['operation' => 'lab:wordless']]], '`lab:wordless` declares no scope, so there is nothing for a grant to lend'];
        yield 'an operation that does not say what it acts on' => [['covers' => [['operation' => 'lab:blind']]], '`lab:blind` does not say what content a call acts on'];
        yield 'an operation that does not declare its effects' => [['covers' => [['operation' => 'lab:undeclared']]], '`lab:undeclared` does not declare its effects'];
        yield 'a condition measured by nothing this house has' => [['conditions' => [$condition + ['by' => ['operation' => 'lab:nowhere']]]], 'this house cannot use it as an instrument'];
        yield 'a condition measured by an operation that cannot be an instrument' => [['conditions' => [$condition + ['by' => ['operation' => 'lab:watch']]]], 'A condition nothing can measure does not fit in a grant'];
        yield 'a falsifier nothing here watches' => [['falsifier' => $falsifier + ['watcher' => ['operation' => 'lab:nowhere']]], 'A position nothing watches is not granted'];
        // The rest of what the document has to say.
        yield 'a seat not written as the house shows it' => [['to' => [strtolower(LabHouse::SEAT)]], 'write each seat\'s key as the house shows it'];
        yield 'a seat this house does not recognize' => [['to' => ['EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE']], 'is not recognized in this house today'];
        yield 'superseding a grant that does not exist' => [['supersedes' => 'the-one-before'], 'this house has no such grant'];
    }

    public function testWhatAPersonSignedIsNeverEditedAChangedGrantTakesANewId(): void
    {
        $this->house->granted();

        $again = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['undo' => 'something else'])]);
        self::assertStringContainsString('a grant «release-line» already exists in this house', $again['error']);

        $next = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['id' => 'release-line-2', 'supersedes' => 'release-line'])]);
        self::assertTrue($next['ok'], (string) ($next['error'] ?? ''));
        self::assertSame('release-line', $next['supersedes']);
        self::assertSame('superseded', $this->house->book()->record('release-line')?->standsAs($this->house->now));
        self::assertStringContainsString('«release-line-2» replaced it', (string) $this->house->book()->record('release-line')?->because);
    }

    public function testInAHouseWhoseBoundaryIsNotTheOneThatJudgesGrantsNothingIsGranted(): void
    {
        // A grant in a house that would not enforce it is a sentence nobody keeps.
        $container = new DIContainer();
        $operations = (new StandingOperations($container, $this->house->root, $this->house))->operations();
        $container->registerService(\Milpa\ToolRuntime\Identity\GrantedAuthorization::class, $this->house->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document()]));

        $given = ($operations[0]->handler)(['grant' => $this->house->document()]);

        self::assertFalse($given['ok']);
        self::assertStringContainsString('this house does not judge standing grants', $given['error']);

        // And with no root to keep a book in, the readers and the other acts say so instead of guessing one.
        $nowhere = new StandingOperations(new DIContainer());
        self::assertSame([], ($nowhere->operations()[4]->handler)([])['grants']);
        self::assertStringContainsString('no standing grant', ($nowhere->operations()[3]->handler)(['grant' => 'x', 'reason' => 'y'])['error']);
        self::assertInstanceOf(PluginAuthoringPolicy::class, $this->house->container->get(OperationBoundary::class));
    }

    public function testABookThatDoesNotVerifyTakesNoNewGrant(): void
    {
        $this->house->granted();
        $path = $this->house->root . '/' . StandingLedger::PATH;
        $broken = str_replace('release-line', 'release-lime', (string) file_get_contents($path));
        file_put_contents($path, $broken);

        // The person's signed call is not stopped at the door — a broken book stops seats, and the person is how it
        // gets repaired — and the act itself refuses to add to a book that does not verify.
        $given = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['id' => 'another'])]);
        self::assertFalse($given['ok'] ?? null);
        self::assertStringStartsWith('the book of standing grants does not verify (line 1', (string) ($given['error'] ?? ''));
        self::assertStringEndsWith('— nothing was granted', (string) $given['error']);
        self::assertSame($broken, (string) file_get_contents($path), 'nothing was added to it');
    }
}
