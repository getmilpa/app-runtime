<?php

/**
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\GrantState;
use PHPUnit\Framework\TestCase;

/**
 * What a sixth independent review found in the house's judge (greenhouse decisions/0615) — ten findings, three of
 * them brought in by the round before: a line that was not written because something was «already said», a line
 * that was not written because something was too long, and a grant given that the book would never honour.
 *
 * So what this round built is what it TOOK OUT. The rules these tests hold are few: the house decides from the lines
 * of its book and never from the state a process derives of them today; a line that narrows is always written; the
 * door answers yes only to what the book will honour; and the stop that stands is the last one written.
 */
final class WhatASixthReviewFoundInTheHouseTest extends TestCase
{
    private const SECOND = LabHouse::STRANGER;

    private LabHouse $house;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    // ── 6-M1 · a second «fired» is written, whoever this process can verify ─────────────────────────────────

    public function testASecondFiredIsWrittenWhenThisProcessCannotVerifyTheLiftOfTheFirst(): void
    {
        // «Already said» was decided from the state this process derives today: one that cannot read the lift of the
        // first «fired» reads the grant as still stopped by it, so the second one «stops nothing» and «this key said
        // so before» — no line, and every process that CAN read the lift lends.
        $this->house->enroll(self::SECOND, ['identity:enroll'], LabHouse::PERSON);
        $path = $this->measured();
        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $this->house->signed(self::SECOND, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->state()?->suspension]);
        $this->house->fired = false;
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertArrayNotHasKey('refused', $this->merge($path), 'control: a second person lifted the first «fired»');

        $this->house->revokeKey(self::SECOND); // this process no longer verifies whoever lifted it
        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertCount(2, $this->fired(), 'the falsifier fired again and the house wrote nothing: «already said», read from what this process derives today');
        $this->house->verifiesAgain(self::SECOND);
        $this->house->fired = false;
        self::assertSame(GrantState::Suspended, $this->state()?->state, 'and for every process that reads the lift, the grant is in force');
        self::assertArrayHasKey('refused', $this->merge($path));
    }

    // ── 6-M2 · a no about a content whose name is long is written ───────────────────────────────────────────

    public function testANoIsWrittenAboutAContentWhateverTheLengthOfItsName(): void
    {
        // A bound meant for what a RUN says it read was applied to the content the HOUSE read: an operation that names
        // what it acts on with five digests got its yes written and its no thrown away.
        $this->house->named = static fn (string $digest): string => implode('+', array_fill(0, 5, $digest));
        $path = $this->measured();
        self::assertGreaterThan(256, \strlen($this->house->digestOf($path)), 'control: the name of the content is longer than a line kept of one');
        self::assertArrayNotHasKey('refused', $this->merge($path), 'control: measured green, the act runs');

        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path, 'strict' => true]);

        $reds = array_filter($this->house->lines(StandingLedger::MEASURED), static fn (array $line): bool => $line['ok'] === false);
        self::assertCount(1, $reds, 'the instrument said no of a content and the house wrote nothing');
        self::assertStringContainsString('said no', (string) ($this->merge($path)['refused'] ?? 'RAN'));
    }

    // ── 6-M4 · one question, asked where a door is built ────────────────────────────────────────────────────

    /** @return iterable<string, array{0: bool, 1: bool, 2: bool, 3: ?string, 4: bool}> the app's own policy, its own boundary, a book, the caller, and whether the door is closed to it */
    public static function whatAHouseHoldsAndWhoCalls(): iterable
    {
        foreach (['the house\'s policy and boundary' => [false, false], 'an app\'s own call policy' => [true, false], 'an app\'s own boundary' => [false, true], 'both the app\'s' => [true, true]] as $holds => [$policy, $boundary]) {
            $unjudged = $policy || $boundary;
            yield "{$holds}, a book, a seat" => [$policy, $boundary, true, LabHouse::SEAT, $unjudged];
            yield "{$holds}, a book, a person" => [$policy, $boundary, true, LabHouse::PERSON, false];
            yield "{$holds}, a book, the operator's own terminal" => [$policy, $boundary, true, null, false];
            yield "{$holds}, no book, a seat" => [$policy, $boundary, false, LabHouse::SEAT, false];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatAHouseHoldsAndWhoCalls')]
    public function testAHouseThatKeepsABookAndDoesNotJudgeItOpensNoDoorToASeat(bool $ownPolicy, bool $ownBoundary, bool $book, ?string $caller, bool $closed): void
    {
        // The check lived in the house's call policy, in its boundary and on the leg: an app that registers both
        // services takes all three out, and a stopped grant stopped nobody in process. One question, where a door
        // is built — the terminal, a leg, the web surface — and it asks the container, not a service an app replaces.
        if ($book) {
            $this->house->granted();
        }
        $container = new \Milpa\Container\DIContainer();
        if ($ownPolicy) {
            $container->registerService(\Milpa\ToolRuntime\Contracts\CallPolicy::class, new class () implements \Milpa\ToolRuntime\Contracts\CallPolicy {
                public function authorize(\Milpa\ToolRuntime\Contracts\ToolContext $context, \Milpa\ToolRuntime\ToolDefinition $tool, array $arguments): \Milpa\ToolRuntime\Policy\AuthorizationResult
                {
                    return \Milpa\ToolRuntime\Policy\AuthorizationResult::allowed();
                }
            });
        }
        if ($ownBoundary) {
            $container->registerService(\Milpa\Console\OperationBoundary::class, new class () implements \Milpa\Console\OperationBoundary {
                public function execute(\Milpa\Command\Operation $operation, array $input, ?\Milpa\ToolRuntime\Contracts\ToolContext $authority, \Closure $next): mixed
                {
                    return $next();
                }
            });
        }
        \Milpa\AppRuntime\Agent\PluginAuthoringPolicy::install($container, $this->house->root);

        $said = \Milpa\AppRuntime\Agent\PluginAuthoringPolicy::closedTo($container, $this->house->root, $this->house->contextOf($caller));

        self::assertSame($closed, $said !== null, (string) $said);
        if ($closed) {
            self::assertStringContainsString('does not judge', (string) $said);
            // …and where no caller is known yet — what the web surface is told it serves — it serves nothing.
            self::assertNotNull(\Milpa\AppRuntime\Agent\PluginAuthoringPolicy::closedTo($container, $this->house->root, null));
        }
    }

    public function testTheWebSurfaceOfAHouseThatKeepsABookAndDoesNotJudgeItIsToldItServesNothing(): void
    {
        // Where the web surface is told what it serves no caller is known yet: such a house serves nothing there.
        $declared = [\Milpa\AppRuntime\Standing\StandingOperations::class];
        $judged = new \Milpa\Container\DIContainer();
        self::assertNotSame([], \Milpa\AppRuntime\Support\Operations::declared($judged, $declared, $this->house->root), 'control: a house with no book serves what it declares');
        $this->house->granted();
        self::assertNotSame([], \Milpa\AppRuntime\Support\Operations::declared(new \Milpa\Container\DIContainer(), $declared, $this->house->root), 'control: and so does one that keeps a book and judges it');

        $own = new \Milpa\Container\DIContainer();
        $own->registerService(\Milpa\Console\OperationBoundary::class, new class () implements \Milpa\Console\OperationBoundary {
            public function execute(\Milpa\Command\Operation $operation, array $input, ?\Milpa\ToolRuntime\Contracts\ToolContext $authority, \Closure $next): mixed
            {
                return $next();
            }
        });

        self::assertSame([], \Milpa\AppRuntime\Support\Operations::declared($own, $declared, $this->house->root));
    }

    public function testTheOneQuestionIsAskedOfAKeyAndOfNobodyElse(): void
    {
        // A grant names keys. The bearer of a token whose actor is no key is nobody a grant could name: the door of a
        // house that does not judge is not closed to it for that — its own words are all it ever had.
        $this->house->granted();
        $container = new \Milpa\Container\DIContainer();
        $container->registerService(\Milpa\Console\OperationBoundary::class, new class () implements \Milpa\Console\OperationBoundary {
            public function execute(\Milpa\Command\Operation $operation, array $input, ?\Milpa\ToolRuntime\Contracts\ToolContext $authority, \Closure $next): mixed
            {
                return $next();
            }
        });
        \Milpa\AppRuntime\Agent\PluginAuthoringPolicy::install($container, $this->house->root);

        self::assertNull(\Milpa\AppRuntime\Agent\PluginAuthoringPolicy::closedTo($container, $this->house->root, new \Milpa\ToolRuntime\Contracts\ToolContext('ci', 'cli', ['lab:merge'])));
        self::assertNotNull(\Milpa\AppRuntime\Agent\PluginAuthoringPolicy::closedTo($container, $this->house->root, new \Milpa\ToolRuntime\Contracts\ToolContext('key:' . LabHouse::SEAT, 'cli', ['lab:merge'])), 'control: and it is closed to a key with the same words');
    }

    // ── 6-M5 · the door answers yes only to what the book will honour ───────────────────────────────────────

    public function testAGrantGivenInAHouseWhoseFirstPersonWasSeatedByItsOperatorIsHonoured(): void
    {
        // The ordinary way to seat a first person: the operator of the house — a key the ledger never recognized —
        // recognizes it. That key is on the line of who answers for every seat, and it has no state in the ledger:
        // the line said «null», the reader asked for a number, and every grant was given and none honoured.
        $this->house->enroll(LabHouse::PERSON, LabHouse::PERSON_SCOPES, self::SECOND);
        $this->house->enroll(LabHouse::SEAT, LabHouse::SEAT_SCOPES, LabHouse::PERSON);
        self::assertNull((new \Milpa\AppRuntime\Identity\FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json'))->scopesFor(self::SECOND), 'control: the operator is a key the ledger never recognized');

        $path = $this->measured();

        self::assertNull($this->state()?->fault, 'the door said yes to a grant the book does not honour');
        self::assertArrayNotHasKey('refused', $this->merge($path));
    }

    public function testAHouseWhoseFirstPersonWasMadeByTheFirstRunBootstrapIsHonoured(): void
    {
        // What the sixth review READ and did not execute, measured: `identity:bootstrap` writes that «bootstrap»
        // recognized the first person, and that is not a key — the line of who answers for a seat ends at the person.
        // Green on the head the review read, too.
        (new \Milpa\AppRuntime\Identity\FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json'))
            ->record(new \Milpa\AppRuntime\Identity\IdentityEnrolled(LabHouse::PERSON, LabHouse::PERSON_SCOPES, 'bootstrap'));
        $this->house->enroll(LabHouse::SEAT, LabHouse::SEAT_SCOPES, LabHouse::PERSON);

        $path = $this->measured();

        self::assertNull($this->state()?->fault);
        self::assertArrayNotHasKey('refused', $this->merge($path));
    }

    public function testTheDayTheLedgerRecognizesAKeyItNeverHeldItIsAnotherRecognition(): void
    {
        // The mirror, so that «no state then, no state today» is not «any state will do».
        $this->house->enroll(LabHouse::PERSON, LabHouse::PERSON_SCOPES, self::SECOND);
        $this->house->enroll(LabHouse::SEAT, LabHouse::SEAT_SCOPES, LabHouse::PERSON);
        $path = $this->measured();
        self::assertArrayNotHasKey('refused', $this->merge($path), 'control: the grant lends');

        $this->house->enroll(self::SECOND, ['identity:enroll'], LabHouse::PERSON);

        self::assertArrayHasKey('refused', $this->merge($path), 'a key of the line was recognized for the first time after the grant, and the grant still lends');
    }

    public function testANoIsWrittenAboutTheContentTheHouseReadsAfterTheRunToo(): void
    {
        // The three contents a no can be about: the one the house read before the run, the one it reads after it, and
        // the one the run says. Here the candidate changes while the instrument runs, and the run says nothing.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $before = $this->house->digestOf($path);
        $this->house->scannerSaysWhatItRead = false;
        $this->house->scanner = function (string $bytes) use ($path): array {
            file_put_contents($this->house->root . '/' . $path, "another release\n");

            return ['ok' => false];
        };

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        self::assertSame([$before, $this->house->digestOf($path)], array_column($this->house->lines(StandingLedger::MEASURED), 'subject'), 'a no about the content the house holds after the run was not written');
    }

    public function testARefusalAboutAContentWhoseNameIsNotTextIsAnsweredAndNotWritten(): void
    {
        // A refusal is only a record: one the book cannot spell as a line stands without its line, and the call that
        // was refused is answered as refused — it does not break.
        $this->house->named = static fn (string $digest): string => "\xB1" . $digest;
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");

        $said = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]);

        self::assertStringContainsString('was never measured', (string) ($said['refused'] ?? 'RAN'), 'the seat was answered with what broke the writing of its refusal, not with why it was refused');
        self::assertSame([], $this->house->lines(StandingLedger::REFUSED));
        self::assertNull($this->house->ledger()->fault());
    }

    // ── 6-M6 · an act a seat is lent is a short line, whatever it was called with ───────────────────────────

    public function testAnActIsLentWhateverItCarriesAndItsLineKeepsTheDigestOfWhatWasLong(): void
    {
        $this->house->offerBeside(new \Milpa\Command\Operation(
            'lab:note',
            'Lab: a covered act with an argument the grant leaves free',
            fn (array $input): array => ['ok' => true, 'acted_on' => $this->house->digestOf((string) $input['path'])],
            ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'note' => ['type' => 'string']], 'required' => ['path']],
            mutating: true,
            requiresConfirmation: true,
            scopes: ['lab:note'],
            effects: new \Milpa\Command\Effect\EffectProfile(\Milpa\Command\Effect\Mutation::Persistent, \Milpa\Command\Effect\Externality::None, \Milpa\Command\Effect\Reversibility::Compensatable, \Milpa\Command\Effect\Authority::WriteAsUser, subject: \Milpa\Command\Effect\Subject::Data),
            actsOn: fn (array $input): ?string => $this->house->digestOf((string) $input['path']),
        ));
        $this->house->granted(['covers' => [['operation' => 'lab:note']]]);
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        $said = $this->house->as(LabHouse::SEAT, 'lab:note', ['path' => $path, 'note' => str_repeat('n', 5000)]);

        self::assertSame(['ok' => true], $said, 'an act the grant covers was not lent for the length of an argument the grant leaves free');
        $act = $this->house->ledger()->recent()[\count($this->house->ledger()->recent()) - 1];
        self::assertSame(StandingLedger::ABSORBED, $act['type']);
        self::assertLessThan(1500, \strlen((string) json_encode($act)), 'the line of an act carries what it was called with, whole');
        self::assertSame(\strlen(\Milpa\Command\Standing\Canonical::json(['path' => $path, 'note' => str_repeat('n', 5000)])), $act['data']['arguments_given']['bytes'] ?? null, 'and does not say how long that was, nor its digest');
        self::assertArrayNotHasKey('undo', $act['data'], 'and repeats what the grant already says');
    }

    public function testTheFaultOfABookTooLongNamesItsHead(): void
    {
        $fault = (new \ReflectionMethod(StandingLedger::class, 'tooLong'))->invoke(null, StandingLedger::LONGEST + 1);

        self::assertStringContainsString(StandingLedger::HEAD, $fault, 'the house tells a person to keep the book somewhere else, and the book kept elsewhere alone leaves the fault: its head goes with it');
    }

    // ── 6-L1 · the stop that stands is the last one written, and the listing shows every one ────────────────

    public function testAStopWrittenOverAFiredIsTheOneThatStandsAndTheOneAPersonLifts(): void
    {
        $path = $this->measured();
        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $fired = (string) $this->state()?->suspension;

        $stopped = $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'SECOND FAULT: the instrument is broken, do not lift']);

        self::assertSame($stopped['suspension'] ?? null, $this->state()?->suspension, 'the door named a stop as written, and it is not the one that stands');
        self::assertStringContainsString('SECOND FAULT', (string) json_encode($this->listed()['grants'][0]), 'what a person is shown of the grant does not say a stop that was written');
        self::assertStringContainsString('fired', (string) json_encode($this->listed()['grants'][0]), 'nor the one before it');
        $this->house->fired = false;
        $lifted = $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $fired]);
        self::assertFalse($lifted['ok'] ?? false, 'a signature that names the first stop lifted the second one, which it never named');
        self::assertSame(GrantState::Suspended, $this->state()?->state);

        // The control: the person lifts the stop that stands — the last, whose name is the name of everything before it.
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->state()?->suspension])['ok'] ?? false);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertArrayNotHasKey('refused', $this->merge($path));
    }

    public function testAFiredDoesNotTakeThePlaceOfAStopThatOnlyANewGrantLifts(): void
    {
        // The last stop stands — among stops a person lifts by name. A withdrawal nobody signed stops a grant for
        // good: a «fired» written after it must not become a stop that a signature naming it could lift.
        $path = $this->measured();
        $this->house->writtenByHand(StandingLedger::REVOKED, ['grant' => 'release-line']);
        self::assertTrue($this->state()?->stopped, 'control: only a new grant lifts this stop');
        $withdrawn = $this->state()?->suspension;

        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $this->house->fired = false;

        self::assertCount(1, $this->fired(), 'control: the «fired» is a line of the book');
        self::assertSame($withdrawn, $this->state()?->suspension, 'a «fired» took the place of a stop that only a new grant lifts');
        self::assertTrue($this->state()?->stopped);
        self::assertArrayHasKey('refused', $this->merge($path));
    }

    public function testAStopWrittenOverTheHousesOwnDoesNotHideIt(): void
    {
        $path = $this->measured();
        $this->house->scannerSaysWhatItRead = true;
        $this->house->consumed = 'sha256:' . str_repeat('0', 64); // the act says it consumed something else: the house stops the grant
        $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);
        self::assertSame(GrantState::Suspended, $this->state()?->state, 'control: the house stopped the grant after the act');

        $this->house->signed(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'routine pause, nothing wrong']);

        $listed = (string) json_encode($this->listed()['grants'][0]);
        self::assertStringContainsString('routine pause', $listed);
        self::assertStringContainsString('the house', $listed, 'a stop any key may write took the place of the house\'s own in what a person is shown');
    }

    // ── 6-L2 · the same yes is written again once it is not the last word ───────────────────────────────────

    public function testAYesIsWrittenAgainAfterTheInstrumentWasAnotherOneForAWhile(): void
    {
        $path = $this->measured();
        $asGranted = $this->house->operation('lab:scan');
        $this->house->hardenScanner();
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]); // a receipt of the other instrument
        $this->house->offer($asGranted);
        self::assertStringContainsString('not that instrument any more', (string) ($this->merge($path)['refused'] ?? 'RAN'), 'control: the last receipt is of another instrument');

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]); // what the refusal says to do

        self::assertArrayNotHasKey('refused', $this->merge($path), '«run it again» wrote nothing: the same key had said that yes before');
    }

    // ── 6-L3 · a stop is never refused for its reason, by any surface ───────────────────────────────────────

    public function testTheReasonOfAStopHasNoTypeASurfaceCouldRefuseItFor(): void
    {
        // Asked of the validator a surface runs before the door is reached (milpa/tool-runtime).
        $schema = $this->house->operation('standing:suspend')->inputSchema ?? [];
        foreach ([123, ['a', 'list'], ['a' => 'map'], true, null, 'plain words'] as $reason) {
            $checked = (new \Milpa\ToolRuntime\SchemaValidator())->validate(['grant' => 'release-line', 'reason' => $reason], $schema);
            self::assertSame([], $checked->errors, 'a surface that checks arguments against the schema refuses a stop for its reason: ' . json_encode($reason));
        }
        $path = $this->measured();
        $stopped = $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => ['a', 'list']]);
        self::assertSame('suspended', $stopped['stands_as'] ?? null, (string) json_encode($stopped));
        self::assertArrayHasKey('refused', $this->merge($path));
    }

    // ── 6-L4 · what the house says is so ────────────────────────────────────────────────────────────────────

    public function testTheLastRefusalAHouseKeepsSaysWhatMakesItKeepThemAgain(): void
    {
        self::assertStringNotContainsString('until the standing of the grant changes', HouseStandingJudge::REFUSALS_END, 'only a LINE starts the count again — a watcher that goes quiet changes the standing and writes none');
        self::assertStringContainsString('line', HouseStandingJudge::REFUSALS_END);
    }

    public function testALineSaysWhetherItsCallerArrivedWithTheSignatureOfItsKey(): void
    {
        // The book wrote «who», «seat» and «by» from the name the caller carried. A token minted by whoever holds every
        // scope carries any actor's name: its bearer, with no signature, was written as the seat.
        $path = $this->measured();                                                      // as() — a name, no signature
        $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);
        $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen']);
        $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->state()?->suspension]);
        $this->house->signed(LabHouse::SEAT, 'lab:watch', []);
        $this->house->later(1000);
        $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'by a bearer']);

        self::assertSame('unsigned', $this->house->lines(StandingLedger::MEASURED)[0]['arrived'] ?? null);
        self::assertSame('unsigned', $this->house->lines(StandingLedger::ABSORBED)[0]['arrived'] ?? null);
        self::assertSame(['unsigned', 'signed'], array_column($this->house->lines(StandingLedger::WATCHED), 'arrived'));
        self::assertSame(['signed', 'unsigned'], array_column($this->house->lines(StandingLedger::SUSPENDED), 'arrived'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────

    private function measured(): string
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        return $path;
    }

    /** @return array<string, mixed> */
    private function merge(string $path): array
    {
        return $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]);
    }

    private function state(): ?\Milpa\AppRuntime\Standing\GrantRecord
    {
        return $this->house->book()->record('release-line');
    }

    /** @return list<array<string, mixed>> */
    private function fired(): array
    {
        return array_values(array_filter($this->house->lines(StandingLedger::WATCHED), static fn (array $line): bool => $line['fired'] === true));
    }

    /** @return array<string, mixed> what a person is shown of the grant */
    private function listed(): array
    {
        return $this->house->signed(LabHouse::PERSON, 'standing:granted', []);
    }
}
