<?php

/**
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\GrantRecord;
use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingBook;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\Canonical;
use Milpa\Command\Standing\GrantState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The book is read line by line, what a reading keeps is what somebody asks of it, and a process verifies it once
 * (greenhouse decisions/0615 — what the fifth review's 5-M2 left standing: a bound on a line moves the cliff, and the
 * cliff was the reader).
 *
 * Measured before this file: every command read and verified the whole book eight to twelve times, each time whole in
 * memory, and a process of 128M stopped between twenty and forty megabytes of it. An act a seat is lent is a line —
 * so a house that works reached that with nobody abusing it.
 *
 * NOTHING HERE LENDS OR TAKES ANYTHING. What a reading lets go of is what no reader reaches — asked below of every
 * reader, over books made of every kind of line in orders nobody chose — and what a process verified is used again
 * only over the very bytes it verified, read again each time.
 */
final class TheBookIsReadByLinesAndVerifiedOncePerProcessTest extends TestCase
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

    // ── what a reading keeps ─────────────────────────────────────────────────────────────────────────────────

    public function testAReadingKeepsNoActASeatWasLentAndTheBookStillHoldsIt(): void
    {
        $path = $this->measured();
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']));

        $ledger = $this->house->ledger();
        $lines = \count($this->house->everyLine());
        self::assertContains(StandingLedger::ABSORBED, self::kinds($this->house->everyLine()), 'control: the act is a line of the book');
        self::assertNotContains(StandingLedger::ABSORBED, self::kinds($ledger->kept()), 'a reading kept an act: a line nobody asks about again');
        self::assertSame(StandingLedger::ABSORBED, $ledger->recent()[\count($ledger->recent()) - 1]['type'], 'and what the book said lately still shows it');
        self::assertNull($ledger->fault());
        // The next line links to the act, though no reading kept it.
        $this->house->writtenByHand(StandingLedger::SUSPENDED, ['grant' => 'release-line', 'by' => 'someone', 'reason' => 'after the act']);
        self::assertNull($this->house->ledger()->fault());
        self::assertCount($lines + 1, $this->house->everyLine());
    }

    public function testOfTheSignsOfCalmOfOneKeyAReadingKeepsTheLast(): void
    {
        $this->house->granted();
        foreach ([1000, 1000] as $seconds) {
            $this->house->later($seconds);
            $this->house->as(LabHouse::SEAT, 'lab:watch');
        }
        $signs = self::ofKind($this->house->everyLine(), StandingLedger::WATCHED);
        self::assertCount(3, $signs, 'control: three signs of calm are lines of the book');

        $kept = self::ofKind($this->house->ledger()->kept(), StandingLedger::WATCHED);
        self::assertCount(1, $kept, 'a reading kept a sign of calm its own key gave again');
        self::assertSame($signs[2]['hash'], $kept[0]['hash'], 'and the one it keeps is the last');
        self::assertSame($signs[2]['at'], $this->house->book()->record('release-line')?->watcherSignalAt?->format(\DATE_ATOM), 'which is the sign the grant stands on');

        // Another key's sign is its own; and so is the same key's with another pin.
        $this->house->writtenByHand(StandingLedger::WATCHED, ['who' => 'key:' . LabHouse::OTHER_SEAT] + $signs[2]['data']);
        $this->house->writtenByHand(StandingLedger::WATCHED, ['pin' => 'sha256:another'] + $signs[2]['data']);
        self::assertCount(3, self::ofKind($this->house->ledger()->kept(), StandingLedger::WATCHED));
    }

    /** @return iterable<string, array{string, array<string, mixed>, list<string>}> */
    public static function linesAfterARefusal(): iterable
    {
        $both = ['release-line', 'another-line'];
        yield 'its grant is stopped' => [StandingLedger::SUSPENDED, ['grant' => 'release-line', 'by' => 'someone', 'reason' => 'a stop'], ['another-line']];
        yield 'a lift of its grant is written' => [StandingLedger::REINSTATED, ['grant' => 'release-line', 'suspension' => 'sha256:none'], ['another-line']];
        yield 'its grant is withdrawn' => [StandingLedger::REVOKED, ['grant' => 'release-line'], ['another-line']];
        yield 'a falsifier fires — anybody\'s' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => true, 'who' => 'someone'], []];
        yield 'a grant is given — any' => [StandingLedger::GRANTED, [], []];
        yield 'control: a sign of calm' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => false, 'who' => 'someone'], $both];
        yield 'control: an act' => [StandingLedger::ABSORBED, ['grant' => 'release-line', 'operation' => 'lab.publish', 'seat' => LabHouse::SEAT], $both];
        yield 'control: another refusal' => [StandingLedger::REFUSED, ['grant' => 'third-line', 'operation' => 'lab.publish', 'seat' => LabHouse::SEAT], [...$both, 'third-line']];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $stillKept the grants whose refusal a reading still keeps after that line
     */
    #[DataProvider('linesAfterARefusal')]
    public function testARefusalIsLetGoOfOnceTheStandingOfItsGrantChangedAndNotBefore(string $type, array $data, array $stillKept): void
    {
        // A seat's refusals are counted back to the last line that changed the standing of their grant: a grant
        // given, a falsifier that fired, or that grant stopped, lifted or withdrawn. What lies before it, nobody reads.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']); // not measured: refused
        $this->house->writtenByHand(StandingLedger::REFUSED, ['grant' => 'another-line', 'operation' => 'lab.merge', 'seat' => LabHouse::SEAT, 'reason' => 'by hand']);
        self::assertCount(2, self::ofKind($this->house->ledger()->kept(), StandingLedger::REFUSED), 'control: a refusal is kept while its grant stands as it stood');

        $type === StandingLedger::GRANTED
            ? $this->house->granted(['id' => 'given-later', 'covers' => [['operation' => 'lab:merge']]], watched: false)
            : $this->house->writtenByHand($type, $data);

        self::assertSame($stillKept, array_column(array_column(self::ofKind($this->house->ledger()->kept(), StandingLedger::REFUSED), 'data'), 'grant'));
        self::assertCount($type === StandingLedger::REFUSED ? 3 : 2, self::ofKind($this->house->everyLine(), StandingLedger::REFUSED), 'and the book still holds them');
    }

    public function testWhatAReadingKeepsDoesNotGrowWithTheActsOfAHouseThatWorks(): void
    {
        $path = $this->measured();
        $before = \count($this->house->ledger()->kept());
        $act = ['grant' => 'release-line', 'operation' => 'lab.publish', 'seat' => LabHouse::SEAT, 'arguments' => ['channel' => 'stable', 'path' => $path]];
        $calm = self::ofKind($this->house->everyLine(), StandingLedger::WATCHED)[0]['data'];
        for ($day = 0; $day < 400; ++$day) {
            $this->house->later(900);
            $this->house->writtenByHand(StandingLedger::ABSORBED, $act);
            $this->house->writtenByHand(StandingLedger::WATCHED, $calm);
        }

        self::assertCount($before + 800, $this->house->everyLine(), 'control: eight hundred lines were written');
        self::assertCount($before, $this->house->ledger()->kept(), 'a reading grew with the acts and the signs of calm of a house that works');
        self::assertSame(
            array_column(\array_slice($this->house->everyLine(), -StandingLedger::RECENT), 'hash'),
            array_column($this->house->ledger()->recent(), 'hash'),
            'what the book said lately is not its last lines, in their order',
        );
        self::assertNull($this->house->ledger()->fault());
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state);
    }

    /** @return iterable<string, array{int}> */
    public static function books(): iterable
    {
        foreach ([11, 67] as $seed) {
            yield "a book nobody chose the order of, seed {$seed}" => [$seed];
        }
    }

    #[DataProvider('books')]
    public function testNoReaderAnswersDifferentlyOfWhatAReadingKeepsThanOfEveryLine(int $seed): void
    {
        // Asked AFTER EVERY LINE, not of the finished book: what a reader asks is about the last lines, and a book
        // that goes on for a hundred more hides what a reading let go of ninety lines ago.
        $house = $this->house;
        $house->granted();
        $house->granted(['id' => 'second-line', 'covers' => [['operation' => 'lab:announce']]], watched: false);
        $path = $house->candidate('good.txt', "clean release\n");
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $calm = self::ofKind($house->everyLine(), StandingLedger::WATCHED)[0]['data'];
        $receipt = self::ofKind($house->everyLine(), StandingLedger::MEASURED)[0]['data'];
        $who = ['key:' . LabHouse::SEAT, 'key:' . LabHouse::OTHER_SEAT, 'key:' . LabHouse::STRANGER];
        $seats = [LabHouse::SEAT, LabHouse::OTHER_SEAT];
        $grants = ['release-line', 'second-line'];
        $contents = [$receipt['subject'], 'sha256:' . str_repeat('1', 64), 'sha256:' . str_repeat('2', 64)];
        $pins = [$calm['pin'], 'sha256:' . str_repeat('3', 64)];
        $called = [[], ['depth' => 1]];
        $watchers = [$calm['watcher'], 'lab.patrol'];

        $book = $house->book();
        $judge = $house->policy->standing();
        $watch = $house->operation('lab:watch');
        $derived = new \ReflectionMethod(StandingBook::class, 'derived');
        $asks = [];
        foreach (['refusalsKept', 'saidCalmJustNow'] as $what) {
            $asks[$what] = new \ReflectionMethod(HouseStandingJudge::class, $what);
        }
        $standing = static fn (array $records): array => array_map(static fn (GrantRecord $record): array => [
            $record->grant->id, $record->state->value, $record->because, $record->suspension, $record->watcherSignalAt?->format(\DATE_ATOM),
            $record->stopped, $record->stoppedBy, $record->fault,
        ], $records);
        $letGo = [StandingLedger::ABSORBED => 0, StandingLedger::WATCHED => 0, StandingLedger::REFUSED => 0];
        $asked = 0;
        $compare = static function (int $line) use ($house, $book, $judge, $watch, $derived, $asks, $standing, &$grants, $seats, $who, $pins, $called, $contents, $watchers, $receipt, &$letGo, &$asked): void {
            $ledger = $house->ledger();
            $every = $house->everyLine();
            $kept = $ledger->kept();
            foreach (array_keys($letGo) as $kind) {
                $letGo[$kind] = max($letGo[$kind], \count(self::ofKind($every, $kind)) - \count(self::ofKind($kept, $kind)));
            }
            // What the book lends: every grant, as it stands.
            self::assertSame($standing($derived->invoke($book, $every, null)), $standing($derived->invoke($book, $kept, null)), "after line {$line}, a grant stands differently over what a reading keeps");
            // What a person signed, every stop and every receipt are all there, in their order.
            foreach ([StandingLedger::GRANTED, StandingLedger::MEASURED, StandingLedger::SUSPENDED, StandingLedger::REINSTATED, StandingLedger::REVOKED] as $kind) {
                self::assertSame(array_column(self::ofKind($every, $kind), 'hash'), array_column(self::ofKind($kept, $kind), 'hash'), "after line {$line}, a reading let go of a «{$kind}» line");
            }
            // What the judge asks before it writes a line that changes nothing.
            $differ = static function (string $what, mixed ...$of) use ($asks, $judge, $every, $kept, $line, &$asked): void {
                ++$asked;
                self::assertSame($asks[$what]->invoke($judge, $every, ...$of), $asks[$what]->invoke($judge, $kept, ...$of), "after line {$line}, «{$what}» answers differently over what a reading keeps: " . substr((string) json_encode($of), 0, 240));
            };
            // A seat's refusals are counted back to the last line that changed the standing of their grant. The house
            // says that rule in ONE place — what a reading keeps — so here it is said again, over every line, and the
            // two must agree.
            foreach ($grants as $grant) {
                foreach ($seats as $seat) {
                    foreach (['refusal 1', 'refusal 4', 'one never written'] as $reason) {
                        $refusal = ['grant' => $grant, 'operation' => 'lab.publish', 'seat' => $seat, 'reason' => $reason];
                        $counted = 0;
                        for ($at = \count($every) - 1; $at >= 0 && $counted !== null; --$at) {
                            $data = $every[$at]['data'];
                            if ($every[$at]['type'] === StandingLedger::GRANTED || ($every[$at]['type'] === StandingLedger::WATCHED && $data['fired'] === true)
                                || (\in_array($every[$at]['type'], [StandingLedger::SUSPENDED, StandingLedger::REINSTATED, StandingLedger::REVOKED], true) && ($data['grant'] ?? null) === $grant)) {
                                break;
                            }
                            if ($every[$at]['type'] === StandingLedger::REFUSED && ($data['seat'] ?? null) === $seat && ($data['grant'] ?? null) === $grant) {
                                $counted = Canonical::json($data) === Canonical::json($refusal) || $counted + 1 >= HouseStandingJudge::REFUSALS_KEPT ? null : $counted + 1;
                            }
                        }
                        ++$asked;
                        self::assertSame($counted, $asks['refusalsKept']->invoke($judge, $kept, $refusal, Canonical::json($refusal)), "after line {$line}, the refusals of {$seat} under «{$grant}» are counted differently over what a reading keeps");
                    }
                }
            }
            foreach ($who as $key) {
                foreach ($watchers as $watcher) {
                    foreach ($pins as $pin) {
                        foreach ($called as $arguments) {
                            $differ('saidCalmJustNow', $watch, $watcher, $key, $pin, $arguments, $house->now);
                        }
                    }
                }
            }
        };

        mt_srand($seed);
        $any = static fn (array $of): mixed => $of[mt_rand(0, \count($of) - 1)];
        for ($line = 0; $line < 80; ++$line) {
            $house->later(mt_rand(0, 400));
            $grant = $any($grants);
            $roll = mt_rand(1, 100);
            if ($roll <= 26) {
                $house->writtenByHand(StandingLedger::ABSORBED, ['grant' => $grant, 'operation' => 'lab.publish', 'seat' => $any($seats)]);
            } elseif ($roll <= 54) {
                $house->writtenByHand(StandingLedger::WATCHED, ['watcher' => $any($watchers), 'who' => $any($who), 'pin' => $any($pins), 'arguments' => $any($called)] + $calm);
            } elseif ($roll <= 74) {
                $house->writtenByHand(StandingLedger::REFUSED, ['grant' => $grant, 'operation' => 'lab.publish', 'seat' => $any($seats), 'reason' => 'refusal ' . mt_rand(1, 6)]);
            } elseif ($roll <= 86) {
                $house->writtenByHand(StandingLedger::MEASURED, ['subject' => $any($contents), 'ok' => mt_rand(0, 1) === 1, 'who' => $any($who), 'instrument' => $any([$receipt['instrument'], $pins[1]])] + $receipt);
            } elseif ($roll <= 90) {
                $house->writtenByHand(StandingLedger::SUSPENDED, ['grant' => $grant, 'by' => $any($who), 'reason' => 'stop ' . $line]);
            } elseif ($roll <= 95) {
                // A lift a person signs, of the stop that stands — or, when none does, one nobody signed: it lifts nothing.
                $stop = $house->book()->record($grant)?->suspension;
                $stop === null
                    ? $house->writtenByHand(StandingLedger::REINSTATED, ['grant' => $grant, 'suspension' => 'sha256:none'])
                    : $house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => $grant, 'suspension' => $stop]);
            } elseif ($roll <= 97) {
                $house->writtenByHand(StandingLedger::WATCHED, ['who' => $any($who), 'fired' => true] + $calm);
            } elseif ($roll <= 98) {
                $house->writtenByHand(StandingLedger::REVOKED, ['grant' => $grant]);
            } else {
                $house->granted(['id' => 'line-' . $line, 'covers' => [['operation' => 'lab:merge']]], watched: false);
                $grants[] = 'line-' . $line;
            }
            $compare($line);
        }

        self::assertNull($house->ledger()->fault(), 'control: the book verifies');
        self::assertSame($standing($derived->invoke($book, $house->everyLine(), null)), $standing($house->book()->records()), 'control: and what was compared is what the book answers');
        foreach ($letGo as $kind => $most) {
            self::assertGreaterThan(3, $most, "control: a reading let go of «{$kind}» lines — otherwise this compares a list with itself");
        }
        self::assertGreaterThan(2000, $asked);
        self::assertSame(false, $asks['saidCalmJustNow']->invoke($judge, $house->ledger()->kept(), $watch, $calm['watcher'], $who[0], $pins[0], [], $house->now->modify('+1 day')), 'control: «just now» is not a day later');
    }

    // ── a process verifies the book once ─────────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{\Closure(LabHouse, string): array<string, mixed>, string}> */
    public static function commands(): iterable
    {
        yield 'an act a seat is lent' => [static fn (LabHouse $h, string $path): array => $h->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']), '"ok":true'];
        yield 'an act a seat is refused' => [static fn (LabHouse $h, string $path): array => $h->as(LabHouse::SEAT, 'lab:publish', ['path' => $h->candidate('other.txt', "not measured\n"), 'channel' => 'stable']), 'lends nothing'];
        yield 'a measurement that says yes' => [static fn (LabHouse $h, string $path): array => $h->as(LabHouse::SEAT, 'lab:scan', ['path' => $h->candidate('new.txt', "another clean one\n")]), '"ok":true'];
        yield 'a measurement that says no' => [static function (LabHouse $h, string $path): array {
            $h->scanner = static fn (string $bytes): array => ['ok' => false];

            return $h->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        }, '"ok":false'];
        yield 'a sign of calm that is written' => [static function (LabHouse $h, string $path): array {
            $h->later(1000);

            return $h->as(LabHouse::SEAT, 'lab:watch');
        }, '"fired":false'];
        yield 'a watcher that says it fired' => [static function (LabHouse $h, string $path): array {
            $h->fired = true;

            return $h->as(LabHouse::SEAT, 'lab:watch');
        }, '"fired":true'];
        yield 'the listing a person asks for' => [static fn (LabHouse $h, string $path): array => $h->signed(LabHouse::PERSON, 'standing:granted', []), 'in force'];
        yield 'a stop by a seat' => [static fn (LabHouse $h, string $path): array => $h->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen by the seat']), 'suspended'];
        yield 'a call no grant has to do with' => [static fn (LabHouse $h, string $path): array => $h->as(LabHouse::PERSON, 'lab:announce', ['path' => $path]), ''];
    }

    /** @param \Closure(LabHouse, string): array<string, mixed> $command */
    #[DataProvider('commands')]
    public function testACommandVerifiesTheBookFromItsFirstLineOnce(\Closure $command, string $answers): void
    {
        $path = $this->measured();
        $lines = \count($this->house->everyLine());
        $before = StandingLedger::passes();

        $answer = $command($this->house, $path); // a process that boots for it, as a command typed at a terminal is

        self::assertStringContainsString($answers, (string) json_encode($answer), 'control: the command did what this row says');
        self::assertSame(1, StandingLedger::passes() - $before, 'one command verified the whole book more than once — or never');
        self::assertNull($this->house->ledger()->fault());
        self::assertGreaterThanOrEqual($lines, \count($this->house->everyLine()));
    }

    /** @return iterable<string, array{bool}> */
    public static function writers(): iterable
    {
        yield 'a process that kept the head' => [true];
        yield 'a process that could not keep the head' => [false];
    }

    #[DataProvider('writers')]
    public function testAProcessThatLivesVerifiesOnlyWhatWasAddedToTheBook(bool $headed): void
    {
        $path = $this->measured();
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $before = StandingLedger::passes();

        // Its own lines, and the lines of another process: one more act, written by hand at the end of the file.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']));
        $this->byAnotherProcess(StandingLedger::SUSPENDED, ['grant' => 'release-line', 'by' => 'key:' . LabHouse::OTHER_SEAT, 'reason' => 'seen from another process'], $headed);
        $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);

        self::assertStringContainsString('seen from another process', (string) json_encode($refused), 'a line another process added was not read: the act it stops ran');
        self::assertCount(1, $this->house->done('published'), 'control: one act ran, before the stop');
        self::assertSame(0, StandingLedger::passes() - $before, 'a process that had verified the book verified all of it again for a line that was added');
    }

    /** @return iterable<string, array{\Closure(string): void, string}> */
    public static function changes(): iterable
    {
        yield 'a line changed in place, the file as long as it was' => [static function (string $book): void {
            $bytes = (string) file_get_contents($book);
            file_put_contents($book, substr_replace($bytes, 'X', (int) strpos($bytes, 'release-line'), 1));
        }, 'does not link'];
        yield 'its last line cut off' => [static function (string $book): void {
            $lines = file($book) ?: [];
            file_put_contents($book, implode('', \array_slice($lines, 0, -1)));
        }, 'emptied, truncated'];
        yield 'an older copy put back, and a line added to it' => [static function (string $book): void {
            $lines = file($book) ?: [];
            file_put_contents($book, implode('', \array_slice($lines, 0, -2)) . $lines[\count($lines) - 1]);
        }, 'does not link'];
        yield 'the last line break taken away' => [static function (string $book): void {
            file_put_contents($book, rtrim((string) file_get_contents($book), "\n"));
        }, 'does not end in a line break'];
        yield 'not a byte of it, and its head made to count more lines than it holds' => [static function (string $book): void {
            $head = json_decode((string) file_get_contents($book . StandingLedger::HEAD), true);
            file_put_contents($book . StandingLedger::HEAD, Canonical::json(['head' => $head['head'], 'lines' => $head['lines'] + 3]) . "\n");
        }, 'emptied, truncated'];
        yield 'not a byte of it, and its head made to name another last line' => [static function (string $book): void {
            $head = json_decode((string) file_get_contents($book . StandingLedger::HEAD), true);
            file_put_contents($book . StandingLedger::HEAD, Canonical::json(['head' => 'sha256:' . str_repeat('0', 64), 'lines' => $head['lines']]) . "\n");
        }, 'is not the line this house wrote there'];
    }

    /** @param \Closure(string): void $change */
    #[DataProvider('changes')]
    public function testAProcessThatLivesReadsABookThatWasChangedUnderItAsTheFaultItIs(\Closure $change, string $fault): void
    {
        // What a process verified is used again over the very bytes it verified, and over no others: it is not asked
        // when the file was written, nor how long it is.
        $path = $this->measured();
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']), 'control: this process acts, and has verified the book');
        $book = $this->house->root . '/' . StandingLedger::PATH;
        [$written, $accessed] = [(int) filemtime($book), (int) fileatime($book)];

        $change($book);
        touch($book, $written, $accessed); // and the date it was written put back

        $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);
        self::assertStringContainsString('does not verify', (string) json_encode($refused), 'a process went on with the book it remembered, over a file that is another one');
        self::assertStringContainsString($fault, (string) $this->house->ledger()->fault());
        self::assertCount(1, $this->house->done('published'), 'and the act ran');
    }

    public function testALineAddedUnderAHeadThatStayedBehindIsNotAFault(): void
    {
        // A book that holds more than its head says verifies — a writer that could not keep the head leaves it one
        // line behind. A process that lives had read past that line: it cannot hold what was added against a head
        // that counts fewer lines than it read without reading the book again, and it must not call it rewritten.
        $path = $this->measured();
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $second = json_decode((file($book) ?: [])[1], true);
        $this->byAnotherProcess(StandingLedger::REFUSED, ['grant' => 'another-line', 'operation' => 'lab.merge', 'seat' => LabHouse::SEAT]);
        file_put_contents($book . StandingLedger::HEAD, Canonical::json(['head' => $second['hash'], 'lines' => 2]) . "\n");

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']), 'a book that verifies was read as a fault');
        self::assertNull($this->house->ledger()->fault());
    }

    public function testALineAddedUnderAHeadThatNamesAnotherLineThereIsAFault(): void
    {
        // What is added to the book is held against its head, as the whole book is: a head that counts the new line
        // and names another one in its place is a book that was rewritten.
        $path = $this->measured();
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $this->byAnotherProcess(StandingLedger::REFUSED, ['grant' => 'another-line', 'operation' => 'lab.merge', 'seat' => LabHouse::SEAT], headed: false);
        file_put_contents($book . StandingLedger::HEAD, Canonical::json(['head' => 'sha256:' . str_repeat('0', 64), 'lines' => \count(file($book) ?: [])]) . "\n");

        $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);
        self::assertStringContainsString('is not the line this house wrote there', (string) json_encode($refused));
        self::assertSame([], $this->house->done('published'));
    }

    public function testALineAddedToABookWithAFaultDoesNotMendIt(): void
    {
        // A process that lives read a book whose second line does not link. A line added after it that links to the
        // last line that did verify is exactly what «only what was added is verified» would take as the next one.
        $path = $this->measured();
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $lines = file($book) ?: [];
        file_put_contents($book, $lines[0] . str_replace('lab.watch', 'lab.witch', $lines[1]) . implode('', \array_slice($lines, 2)));
        self::assertStringContainsString('line 2 does not link', (string) json_encode($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable'])), 'control: this process read the fault');

        $first = json_decode($lines[0], true);
        $entry = ['type' => StandingLedger::REFUSED, 'at' => $this->house->now->format(\DATE_ATOM), 'prev' => $first['hash'], 'data' => ['grant' => 'release-line', 'operation' => 'lab.merge', 'seat' => LabHouse::SEAT]];
        $entry['hash'] = StandingLedger::hashOf($entry);
        file_put_contents($book, Canonical::json($entry) . "\n", \FILE_APPEND); // and its head left as it was

        $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);
        self::assertStringContainsString('line 2 does not link', (string) json_encode($refused), 'a line added after a fault mended the book for a process that had read it');
        self::assertSame([], $this->house->done('published'));
    }

    public function testAProcessThatLivesVerifiesABookWithAFaultOnceToo(): void
    {
        $path = $this->measured();
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $lines = file($book) ?: [];
        file_put_contents($book, $lines[0] . str_replace('lab.watch', 'lab.witch', $lines[1]) . implode('', \array_slice($lines, 2)));
        $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);
        self::assertStringContainsString('line 2 does not link', (string) json_encode($refused), 'control: the book has a fault, and this process read it');
        $before = StandingLedger::passes();

        $again = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);
        self::assertStringContainsString('line 2 does not link', (string) json_encode($again));
        self::assertSame(0, StandingLedger::passes() - $before, 'a book with a fault was verified again by a process that had read it');

        // And the fault is the file's, not the memory of it: put right — a line after the fault is what changes — it verifies.
        file_put_contents($book, implode('', $lines));
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']), 'a book put right went on being a fault');
    }

    public function testTheSameBytesInAnotherFileAreTheSameBook(): void
    {
        // The mirror, so that the row above is not «anything that touches the file is a fault»: a copy of the book put
        // in its place, byte for byte, is the book.
        $path = $this->measured();
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $before = StandingLedger::passes();

        copy($book, $book . '.copy');
        rename($book . '.copy', $book);

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']));
        self::assertSame(0, StandingLedger::passes() - $before);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────

    /** A house with the lab grant given, its watcher heard, and one candidate measured: its path. */
    private function measured(): string
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        return $path;
    }

    /**
     * One line added to the book the way another process adds it: bytes at the end of the file, linked to its last
     * line, and the head kept — with nothing of this process told.
     *
     * @param array<string, mixed> $data
     */
    private function byAnotherProcess(string $type, array $data, bool $headed = true): void
    {
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $lines = file($book) ?: [];
        $last = json_decode($lines[\count($lines) - 1], true);
        $entry = ['type' => $type, 'at' => $this->house->now->format(\DATE_ATOM), 'prev' => $last['hash'], 'data' => $data];
        $entry['hash'] = StandingLedger::hashOf($entry);
        file_put_contents($book, Canonical::json($entry) . "\n", \FILE_APPEND);
        if ($headed) {
            file_put_contents($book . StandingLedger::HEAD, Canonical::json(['head' => $entry['hash'], 'lines' => \count($lines) + 1]) . "\n");
        }
    }

    /**
     * @param list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}> $entries
     *
     * @return list<string>
     */
    private static function kinds(array $entries): array
    {
        return array_column($entries, 'type');
    }

    /**
     * @param list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}> $entries
     *
     * @return list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}>
     */
    private static function ofKind(array $entries, string $kind): array
    {
        return array_values(array_filter($entries, static fn (array $entry): bool => $entry['type'] === $kind));
    }
}
