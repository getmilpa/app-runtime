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

use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\GrantState;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * The book of standing grants: append-only, each line linked to the one before it, read whole at the moment of
 * every judgment (greenhouse decisions/0615 §5).
 *
 * @guards every absorption and every refusal of a named call left as a line of the house; a line edited by hand
 *         making the whole book lend nothing; a book that reads back what a person can check; the five operations
 *         offered in a house without the agent
 *
 * @refuses adding to a book that does not verify; honouring any grant of a book that does not verify; a line that
 *          is not one, or that claims a link it does not have
 *
 * @subject-in milpa/app-runtime
 */
final class TheBookOfStandingGrantsIsAChainTest extends TestCase
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

    private function path(): string
    {
        return $this->house->root . '/' . StandingLedger::PATH;
    }

    public function testEveryLineCarriesTheDigestOfTheOneBeforeIt(): void
    {
        $ledger = $this->house->ledger();
        self::assertFalse($ledger->exists());
        self::assertSame([], $ledger->entries());
        self::assertNull($ledger->fault());

        $first = $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'reason' => 'one'], $this->house->now);
        $second = $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'reason' => 'two'], $this->house->now);

        self::assertTrue($ledger->exists());
        self::assertNull($first['prev']);
        self::assertSame($first['hash'], $second['prev']);
        self::assertSame(StandingLedger::hashOf($second), $second['hash']);
        self::assertEquals([$first, $second], $ledger->entries());
        self::assertEquals([$first, $second], StandingLedger::forRoot($this->house->root)->entries(), 'and it reads back from disk');
        self::assertSame('2026-10-11T12:00:00+00:00', $first['at']);
    }

    public function testALineEditedByHandBreaksTheBookAndABrokenBookLendsNothing(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]), 'control: the book verifies and the act passes');
        $whole = (string) file_get_contents($this->path());

        // Someone turns a red measurement green — or anything else: one character of one line.
        file_put_contents($this->path(), str_replace('"fired":false', '"fired":true ', $whole));

        $fault = StandingLedger::forRoot($this->house->root)->fault();
        self::assertNotNull($fault);
        self::assertStringContainsString('line 2 does not link to the line before it', $fault);
        $refused = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]);
        self::assertStringContainsString('The book of standing grants does not verify (line 2', $refused['refused']);
        self::assertStringContainsString('Nothing runs for a seat until a person has looked at it', $refused['refused']);
        self::assertSame('not honoured', $this->house->book()->record('release-line')?->standsAs($this->house->now));

        // Nothing is added to a book that does not verify — not even a suspension.
        try {
            StandingLedger::forRoot($this->house->root)->append(StandingLedger::SUSPENDED, ['grant' => 'release-line']);
            self::fail('a line was added to a book that does not verify');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('does not verify, so nothing is added to it', $error->getMessage());
        }
        self::assertStringContainsString('does not verify', (string) ($this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'x'])['refused'] ?? 'SUSPENDED'));
        // From the terminal — which is not a seat, and is where a person looks — the book says what is wrong with it.
        self::assertStringContainsString('nothing was suspended', $this->house->as(null, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'x'])['error']);
        $listed = $this->house->as(null, 'standing:granted');
        self::assertStringContainsString('DOES NOT VERIFY', $listed['book']);
        self::assertSame('not honoured', $listed['grants'][0]['stands_as']);

        // Put back as it was, it verifies again: the fault was the edit, and nothing else was lost.
        file_put_contents($this->path(), $whole);
        self::assertNull(StandingLedger::forRoot($this->house->root)->fault());
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]));
    }

    public function testWhatIsNotALineIsAFaultAndWhatCannotBeReadIsOneToo(): void
    {
        $this->house->ledger()->append(StandingLedger::SUSPENDED, ['grant' => 'a']);

        file_put_contents($this->path(), "\nnot json at all\n", \FILE_APPEND);
        self::assertStringContainsString('line 3 does not link', (string) StandingLedger::forRoot($this->house->root)->fault());
        self::assertCount(1, StandingLedger::forRoot($this->house->root)->entries(), 'what verified before the fault is still read');

        // A line that is well formed and claims a link it does not have. (This asks the CHAIN alone: the head the
        // house keeps beside the book would say at once that the file was rewritten, and it has its own tests.)
        unlink($this->path() . StandingLedger::HEAD);
        $orphan = ['type' => StandingLedger::REINSTATED, 'at' => '2026-10-11T12:00:00+00:00', 'prev' => null, 'data' => ['grant' => 'a', 'suspension' => 'sha256:the-one-it-names']];
        $orphan['hash'] = StandingLedger::hashOf($orphan);
        file_put_contents($this->path(), json_encode($orphan) . "\n");
        self::assertNull(StandingLedger::forRoot($this->house->root)->fault(), 'control: alone, it is a first line');
        file_put_contents($this->path(), json_encode($orphan) . "\n" . json_encode($orphan) . "\n");
        self::assertStringContainsString('line 2 does not link', (string) StandingLedger::forRoot($this->house->root)->fault());

        // A book that is there and cannot be read is a fault, never «nobody granted anything».
        chmod($this->path(), 0);
        $unreadable = StandingLedger::forRoot($this->house->root)->fault();
        chmod($this->path(), 0o600);
        if (!\function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::assertSame('it cannot be read', $unreadable);
        }

        unlink($this->path());
        mkdir($this->path());
        try {
            @StandingLedger::forRoot($this->house->root)->append(StandingLedger::SUSPENDED, []);
            self::fail('a line was added to a book that is a directory');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('could not be opened for writing', $error->getMessage());
        }
    }

    // ── the head the house keeps beside its book (decisions/0615, «Lo que la segunda revisión encontró», 3) ──

    /** Three lines, and the book as it stood after each: what whoever holds the file can put back. */
    private function threeLines(): array
    {
        $ledger = $this->house->ledger();
        $copies = [];
        foreach (['first', 'second', 'third'] as $reason) {
            $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => $reason]);
            $copies[] = (string) file_get_contents($this->path());
        }

        return $copies;
    }

    private function fault(): ?string
    {
        return StandingLedger::forRoot($this->house->root)->fault();
    }

    public function testTheHouseKeepsTheHeadOfItsBookForItselfAlone(): void
    {
        $umask = umask(0o022);
        try {
            $this->threeLines();
        } finally {
            umask($umask);
        }
        clearstatcache();
        $entries = $this->house->ledger()->entries();

        self::assertSame(['head' => $entries[2]['hash'], 'lines' => 3], json_decode((string) file_get_contents($this->path() . StandingLedger::HEAD), true));
        self::assertSame(['600', '600'], [decoct(fileperms($this->path()) & 0o777), decoct(fileperms($this->path() . StandingLedger::HEAD) & 0o777)]);
        self::assertSame([], glob($this->path() . StandingLedger::HEAD . '.*') ?: [], 'the head is put in its place whole, and nothing is left beside it');
    }

    /** @return iterable<string, array{\Closure(string, list<string>): void, string}> */
    public static function howABookStopsBeingTheOneTheHouseWrote(): iterable
    {
        yield 'truncated to a prefix that verifies' => [static fn (string $book, array $copies) => file_put_contents($book, $copies[1]), 'this house wrote 3 line(s) in it and 2 are there'];
        yield 'put back from an older copy' => [static fn (string $book, array $copies) => file_put_contents($book, $copies[0]), 'this house wrote 3 line(s) in it and 1 is there'];
        yield 'emptied' => [static fn (string $book, array $copies) => file_put_contents($book, ''), 'this house wrote 3 line(s) in it and 0 are there'];
        yield 'deleted' => [static fn (string $book, array $copies) => unlink($book), 'this house wrote 3 line(s) in it and 0 are there'];
        yield 'its last line rewritten, with its digest computed again' => [static function (string $book, array $copies): void {
            $second = json_decode(explode("\n", $copies[1])[1], true);
            $forged = ['type' => StandingLedger::REINSTATED, 'at' => '2026-10-11T12:00:00+00:00', 'prev' => $second['hash'], 'data' => ['grant' => 'a', 'suspension' => 'sha256:the-one-it-names']];
            file_put_contents($book, $copies[1] . json_encode($forged + ['hash' => StandingLedger::hashOf($forged)]) . "\n");
        }, 'line 3 is not the line this house wrote there'];
    }

    /** @param \Closure(string, list<string>): void $done */
    #[\PHPUnit\Framework\Attributes\DataProvider('howABookStopsBeingTheOneTheHouseWrote')]
    public function testABookThatIsNotTheOneTheHouseLastWroteDoesNotVerify(\Closure $done, string $says): void
    {
        $copies = $this->threeLines();
        self::assertNull($this->fault(), 'control: as the house wrote it, it verifies');

        $done($this->path(), $copies);

        self::assertStringContainsString($says, (string) $this->fault());
        self::assertTrue(StandingLedger::forRoot($this->house->root)->exists(), 'a house that kept a book has one, whatever became of its file');
        try {
            StandingLedger::forRoot($this->house->root)->append(StandingLedger::REINSTATED, ['grant' => 'a', 'suspension' => 'sha256:the-one-it-names']);
            self::fail('a line was added to a book that is not the one the house wrote');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('does not verify, so nothing is added to it', $error->getMessage());
        }
        // …and put back as it was, it is the house's book again.
        file_put_contents($this->path(), $copies[2]);
        self::assertNull($this->fault());
    }

    public function testAHeadThatCannotBeReadIsAFault(): void
    {
        $this->threeLines();
        $head = $this->path() . StandingLedger::HEAD;
        $whole = (string) file_get_contents($head);

        foreach (['not json', '[]', '{"lines":3}', '{"head":"sha256:x","lines":"3"}', '{"head":"sha256:x","lines":0}', '{"head":7,"lines":3}'] as $broken) {
            file_put_contents($head, $broken);
            self::assertStringContainsString('its head cannot be read', (string) $this->fault(), $broken);
        }
        file_put_contents($head, $whole);
        self::assertNull($this->fault());
    }

    public function testAHeadOneLineBehindIsABookThatHoldsMoreAndTheNextLinePutsItRight(): void
    {
        // What a house that was stopped between writing a line and keeping its head leaves behind.
        $ledger = $this->house->ledger();
        $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'first']);
        $behind = (string) file_get_contents($this->path() . StandingLedger::HEAD);
        $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'second']);
        file_put_contents($this->path() . StandingLedger::HEAD, $behind);

        self::assertNull($this->fault());
        self::assertCount(2, StandingLedger::forRoot($this->house->root)->entries());
        $third = StandingLedger::forRoot($this->house->root)->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'third']);
        self::assertSame(['head' => $third['hash'], 'lines' => 3], json_decode((string) file_get_contents($this->path() . StandingLedger::HEAD), true));
    }

    public function testTheHeadIsNotASealWhoeverRewritesBothFilesIsNotStopped(): void
    {
        // WHAT THIS DOES NOT STOP, measured so that nobody reads more into it. The head is on the same disk and is the
        // same user's: three ways whoever writes the book gets past it.
        $copies = $this->threeLines();
        $head = $this->path() . StandingLedger::HEAD;
        $first = json_decode(explode("\n", $copies[0])[0], true);
        $forged = ['type' => StandingLedger::REINSTATED, 'at' => '2026-10-11T12:00:00+00:00', 'prev' => $first['hash'], 'data' => ['grant' => 'a', 'suspension' => 'sha256:the-one-it-names']];
        $forged += ['hash' => StandingLedger::hashOf($forged)];
        $rewritten = $copies[0] . json_encode($forged) . "\n";

        // 1 · the book rewritten, and the head rewritten to agree with it.
        file_put_contents($this->path(), $rewritten);
        self::assertNotNull($this->fault(), 'control: the book alone, rewritten, does not verify');
        file_put_contents($head, json_encode(['head' => $forged['hash'], 'lines' => 2]));
        self::assertNull($this->fault());

        // 2 · the head deleted: a book with no head is a book from before there were heads.
        file_put_contents($this->path(), $copies[0]);
        self::assertNotNull($this->fault());
        unlink($head);
        self::assertNull($this->fault());

        // 3 · a line added by hand after the last one the house wrote, with a link that verifies.
        $ledger = $this->house->ledger();
        $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'again']);
        $last = $ledger->entries()[1];
        $added = ['type' => StandingLedger::REINSTATED, 'at' => '2026-10-11T12:00:00+00:00', 'prev' => $last['hash'], 'data' => ['grant' => 'a', 'suspension' => 'sha256:the-one-it-names']];
        file_put_contents($this->path(), json_encode($added + ['hash' => StandingLedger::hashOf($added)]) . "\n", \FILE_APPEND);
        self::assertNull($this->fault());
        self::assertCount(3, StandingLedger::forRoot($this->house->root)->entries());
    }

    public function testALineIsWrittenWholeOrNotAtAll(): void
    {
        FillingDisk::mount();
        $book = 'filling://house/storage/identity/standing.jsonl';
        $ledger = new StandingLedger($book);
        $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'a whole line']);
        $whole = FillingDisk::$files[$book];

        // The disk fills twenty bytes into the next line.
        FillingDisk::$room = 20;
        try {
            $ledger->append(StandingLedger::ABSORBED, ['grant' => 'a', 'operation' => 'lab.publish']);
            self::fail('the house was told a line was written of which twenty bytes were');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('could not be written', $error->getMessage());
        }

        self::assertSame($whole, FillingDisk::$files[$book], 'what the disk took of the line was given back');
        self::assertNull((new StandingLedger($book))->fault());
        self::assertSame(['head' => (new StandingLedger($book))->entries()[0]['hash'], 'lines' => 1], json_decode(FillingDisk::$files[$book . StandingLedger::HEAD], true), 'the head still says what the book holds');
        // …and with room again, the book goes on.
        FillingDisk::$room = \PHP_INT_MAX;
        (new StandingLedger($book))->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'room again']);
        self::assertCount(2, (new StandingLedger($book))->entries());
        self::assertNull((new StandingLedger($book))->fault());
    }

    public function testAHeadTheHouseCannotKeepDoesNotUndoTheLine(): void
    {
        FillingDisk::mount();
        $book = 'filling://house/storage/identity/standing.jsonl';
        $ledger = new StandingLedger($book);
        $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'first']);
        $kept = FillingDisk::$files[$book . StandingLedger::HEAD];

        // The line is written, and the head cannot even be started…
        FillingDisk::$closed = StandingLedger::HEAD . '.';
        $entry = $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'second']);
        self::assertSame('second', $entry['data']['reason']);
        // …or it is written beside its place and cannot be put there.
        FillingDisk::$closed = null;
        FillingDisk::$renames = false;
        $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'third']);

        self::assertCount(3, (new StandingLedger($book))->entries(), 'the lines are in the book');
        self::assertNull((new StandingLedger($book))->fault(), 'and a book that holds more than its head says verifies');
        self::assertSame($kept, FillingDisk::$files[$book . StandingLedger::HEAD], 'the head stands behind');
        self::assertSame([$book, $book . StandingLedger::HEAD], array_keys(FillingDisk::$files), 'nothing half written is left beside it');
        // The next line the house can keep the head of puts it right.
        FillingDisk::$renames = true;
        $fourth = $ledger->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'fourth']);
        self::assertSame(['head' => $fourth['hash'], 'lines' => 4], json_decode(FillingDisk::$files[$book . StandingLedger::HEAD], true));
    }

    public function testAHouseWithNowhereToKeepItsBookSaysSo(): void
    {
        file_put_contents($this->house->root . '/blocked', 'a file where a directory should be');

        try {
            (new StandingLedger($this->house->root . '/blocked/standing.jsonl'))->append(StandingLedger::SUSPENDED, []);
            self::fail('a book was written under a file');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('nowhere to keep its standing grants', $error->getMessage());
        }
    }

    public function testAnActIsNotAbsorbedIfTheHouseCannotWriteItDown(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        // The book stops being writable between the judgment and the act.
        $this->house->whilePublishing = static fn () => self::fail('the act ran');
        chmod($this->path(), 0o400);
        try {
            $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']);
        } finally {
            chmod($this->path(), 0o600);
        }

        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes to a read-only file: this control cannot distinguish there');
        }
        self::assertStringContainsString('could not be opened for writing', $refused['refused']);
        self::assertSame([], $this->house->done('published'));
    }

    public function testTheBookReadsBackWhatAPersonCanCheck(): void
    {
        $this->house->granted(['expires_at' => '2027-01-01T00:00:00+00:00']);
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $red = $this->house->candidate('e.tar', 'machine-name: somewhere');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red]);
        $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]);
        $this->house->as(LabHouse::SEAT, 'lab:announce', ['path' => $candidate]);
        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $suspension = (string) $this->house->book()->record('release-line')?->suspension;
        $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $suspension]);

        $listed = $this->house->as(LabHouse::PERSON, 'standing:granted');

        self::assertTrue($listed['ok']);
        self::assertArrayNotHasKey('book', $listed);
        $grant = $listed['grants'][0];
        self::assertSame('release-line', $grant['grant']);
        self::assertSame('in force', $grant['stands_as']);
        self::assertNull($grant['because']);
        self::assertNull($grant['suspension']);
        self::assertSame('key:' . LabHouse::PERSON, $grant['granted_by']);
        self::assertSame([LabHouse::SEAT], $grant['lends_to']);
        self::assertSame('compensatable', $grant['ceiling']['reversibility']);
        self::assertSame(['no-host-names — what is published names no machine — measured by lab:scan (path: any value)'], $grant['conditions']);
        self::assertSame('lab:watch, every 3600 s at most', $grant['watched_by']);
        self::assertNull($grant['watcher_last_sign'], 'a sign from before the suspension does not count');
        self::assertSame('2027-01-01T00:00:00+00:00', $grant['expires_at']);

        $recent = implode("\n", $listed['recent']);
        self::assertStringContainsString('granted  «release-line»', $recent);
        self::assertStringContainsString('watched  lab.watch: quiet', $recent);
        self::assertStringContainsString('measured  lab.scan on ' . $this->house->digestOf($candidate) . ': green', $recent);
        self::assertStringContainsString('measured  lab.scan on ' . $this->house->digestOf($red) . ': red', $recent);
        self::assertStringContainsString('absorbed  «release-line» lab.merge on ' . $this->house->digestOf($candidate), $recent);
        self::assertStringContainsString('refused  «release-line» lab.announce: above_ceiling', $recent);
        self::assertStringContainsString('watched  lab.watch: FIRED', $recent, 'the line that says it fired is the suspension');
        self::assertStringNotContainsString('suspended  «release-line»', $recent);
        self::assertStringContainsString('reinstated  «release-line»', $recent);

        // One grant by its id, and one that does not exist.
        self::assertCount(1, $this->house->as(LabHouse::PERSON, 'standing:granted', ['grant' => 'release-line'])['grants']);
        $none = $this->house->as(LabHouse::PERSON, 'standing:granted', ['grant' => 'another']);
        self::assertSame([], $none['grants']);
        self::assertStringNotContainsString('«release-line»', implode("\n", $none['recent']));
    }

    public function testAStopOnAGrantNobodyGaveNamesNothing(): void
    {
        // A line the house reads, about a grant it never held, lends nothing and refuses nothing.
        $this->house->writtenByHand(StandingLedger::SUSPENDED, ['grant' => 'ghost', 'reason' => 'of nothing']);

        self::assertNull($this->house->ledger()->fault());
        self::assertSame([], $this->house->book()->records());
        self::assertSame([], $this->house->book()->documents());
        self::assertSame([], $this->house->book()->receipts());
        self::assertNull($this->house->book()->record('ghost'));

        // And a real grant given twice under one id is the first one: the second line is not that grant.
        $this->house->granted();
        $again = $this->house->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['undo' => 'another undo'])]);
        $this->house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'release-line', 'digest' => 'x', 'payload' => $again->payload, 'signature' => $again->signature, 'fingerprint' => LabHouse::PERSON]);
        self::assertCount(1, $this->house->book()->records());
        self::assertSame('withdraw the release and publish the one before it', $this->house->book()->record('release-line')?->grant->undo);
        self::assertCount(1, $this->house->book()->documents());
    }

    /** @return iterable<string, array{\Closure(LabHouse): array<string, mixed>, string}> */
    public static function grantedLinesThatAreNotGrants(): iterable
    {
        yield 'bytes that are not a signed call' => [static fn (LabHouse $house): array => ['grant' => 'ghost', 'payload' => 'not an authorization'], 'it carries no document'];
        yield 'no bytes at all' => [static fn (LabHouse $house): array => ['grant' => 'ghost'], 'it carries no document'];
        yield 'a signed document the contract does not read as a grant' => [static function (LabHouse $house): array {
            $empty = $house->signature(LabHouse::PERSON, 'standing:grant', ['grant' => '{"id":"ghost"}']);

            return ['grant' => 'ghost', 'payload' => $empty->payload, 'signature' => $empty->signature, 'fingerprint' => LabHouse::PERSON];
        }, 'standing grant «ghost» needs «ceiling»'];
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function linesThatAreNotWhatTheirKindSays(): iterable
    {
        yield 'a measurement that is not a receipt' => [StandingLedger::MEASURED, ['not' => 'a receipt'], 'it is not a receipt'];
        yield 'a receipt with no content it measured' => [StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => [], 'subject' => ' ', 'ok' => true, 'instrument' => 'sha256:x', 'at' => '2026-10-11T12:00:00+00:00'], 'it is not a receipt'];
        yield 'a sign that does not say whether it fired' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => 'yes'], 'it does not say how the watcher ran, or — as true or false — whether the falsifier «fired»'];
        yield 'a sign that does not say how the watcher ran' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'fired' => true], 'it does not say how the watcher ran'];
        yield 'a sign of no watcher' => [StandingLedger::WATCHED, ['arguments' => [], 'fired' => true], 'it does not say «watcher»'];
        yield 'an act absorbed under no grant' => [StandingLedger::ABSORBED, ['operation' => 'lab.publish', 'seat' => LabHouse::SEAT], 'it does not say «grant»'];
        yield 'an act of no operation' => [StandingLedger::ABSORBED, ['grant' => 'release-line', 'seat' => LabHouse::SEAT], 'it does not say «operation»'];
        yield 'a refusal of no seat' => [StandingLedger::REFUSED, ['grant' => 'release-line', 'operation' => 'lab.publish', 'seat' => ['a', 'list']], 'it does not say «seat»'];
        yield 'a stop that names no grant' => [StandingLedger::SUSPENDED, ['reason' => 'of nothing'], 'it does not say «grant»'];
        yield 'a stop that names a grant as nothing' => [StandingLedger::SUSPENDED, ['grant' => '', 'reason' => 'of nothing'], 'it does not say «grant»'];
        yield 'a reinstatement that names no suspension' => [StandingLedger::REINSTATED, ['grant' => 'release-line'], 'it does not say «suspension»'];
        yield 'a withdrawal that names no grant' => [StandingLedger::REVOKED, ['fingerprint' => LabHouse::PERSON], 'it does not say «grant»'];
        yield 'a kind of line this house does not know' => ['amended', ['grant' => 'release-line'], 'this house does not know what kind of line it is'];
    }

    /** @param array<string, mixed> $data */
    #[\PHPUnit\Framework\Attributes\DataProvider('linesThatAreNotWhatTheirKindSays')]
    public function testAnyLineTheHouseCannotReadIsAFaultOfTheBookAndNeverNothing(string $kind, array $data, string $why): void
    {
        // Not only a grant (fourth review of the contract, L8; decided by Rod under D1): the judge listens to the
        // LAST receipt about a content, so a red one that stops reading leaves the green before it speaking; a sign
        // that says «fired» and cannot be read stops nothing. Whatever a line said, it is not read as nothing.
        $this->house->granted();
        self::assertNull($this->house->ledger()->fault(), 'control: the book verifies');

        $this->house->writtenByHand($kind, $data);

        $fault = (string) $this->house->ledger()->fault();
        self::assertStringContainsString("line 3 is a «{$kind}» line this house cannot read today ({$why}", $fault);
        self::assertStringContainsString('it is not read as nothing', $fault);
        self::assertStringContainsString('does not verify', (string) ($this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->house->candidate('good.txt', "clean release\n")])['refused'] ?? 'RAN'));
    }

    public function testALineThatDoesNotSayWhenItWasWrittenIsAFaultToo(): void
    {
        // A sign that says «fired» at a moment nobody can read was skipped: it stopped nothing.
        $this->house->granted();
        $this->house->writtenByHand(StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => true]);
        $path = $this->house->root . '/' . StandingLedger::PATH;
        $lines = explode("\n", trim((string) file_get_contents($path)));
        self::assertNull($this->house->ledger()->fault(), 'control: written by the house, it verifies');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state, 'control: and it stops the grant');
        // The same line, saying another moment in a way that is not one — chained again by whoever holds the disk.
        $last = json_decode((string) array_pop($lines), true);
        $last['at'] = 'the day before yesterday';
        $last['hash'] = StandingLedger::hashOf($last);
        file_put_contents($path, implode("\n", [...$lines, \Milpa\Command\Standing\Canonical::json($last)]) . "\n");
        unlink($path . StandingLedger::HEAD);

        self::assertStringContainsString('line 3 does not say when it was written', (string) $this->house->ledger()->fault());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('grantedLinesThatAreNotGrants')]
    public function testALineThatSaysAGrantWasGivenAndIsNotOneIsAFaultOfTheBookAndNeverNothing(\Closure $line, string $why): void
    {
        // It was read as nothing: it named no call, so it lent nothing and refused nothing — and whatever it had
        // replaced or stopped, when it could still be read, came back (third review, M4; decided by Rod 2026-10-12).
        $this->house->granted();
        self::assertNull($this->house->ledger()->fault(), 'control: the book verifies');

        $this->house->writtenByHand(StandingLedger::GRANTED, $line($this->house));

        $fault = (string) $this->house->ledger()->fault();
        self::assertStringContainsString('line 3 says a grant was given, and it cannot be read as one today (' . $why, $fault);
        self::assertStringContainsString('it is not read as nothing', $fault);
        self::assertNotNull($this->house->book()->record('release-line')?->fault, 'no grant of a book that does not verify is honoured');
        self::assertStringContainsString('does not verify', (string) ($this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->house->candidate('good.txt', "clean release\n")])['refused'] ?? 'RAN'));
        self::assertStringContainsString('does not verify', (string) $this->house->signed(LabHouse::PERSON, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stop'])['error']);
    }

    public function testAGrantWhoseLineRecordsAnotherDigestOrAnotherKeyIsNotHonoured(): void
    {
        $signed = $this->house->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document()]);
        $this->house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'release-line', 'digest' => 'sha256:of-something-else', 'payload' => $signed->payload, 'signature' => $signed->signature, 'fingerprint' => LabHouse::PERSON]);
        self::assertSame('the digest its line records is not the digest of what was signed', $this->house->book()->record('release-line')?->fault);

        $other = new LabHouse();
        try {
            $signed = $other->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $other->document()]);
            $other->writtenByHand(StandingLedger::GRANTED, ['grant' => 'release-line', 'digest' => 'x', 'payload' => $signed->payload, 'signature' => $signed->signature, 'fingerprint' => LabHouse::SEAT]);
            self::assertSame('the key it names is not the key that signed it', $other->book()->record('release-line')?->fault);
            $other->writtenByHand(StandingLedger::GRANTED, ['grant' => 'unsigned', 'payload' => $other->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $other->document(['id' => 'unsigned'])])->payload]);
            self::assertSame('it carries no signed bytes to verify', $other->book()->record('unsigned')?->fault);
            $revoke = $other->signature(LabHouse::PERSON, 'standing:revoke', ['grant' => $other->document(['id' => 'mislabelled'])]);
            $other->writtenByHand(StandingLedger::GRANTED, ['grant' => 'mislabelled', 'payload' => $revoke->payload, 'signature' => $revoke->signature, 'fingerprint' => LabHouse::PERSON]);
            self::assertSame('what was signed is not a call to `standing:grant`', $other->book()->record('mislabelled')?->fault);
        } finally {
            $other->destroy();
        }
    }

    public function testTheFiveOperationsAreOfferedInAHouseWithoutTheAgentAndOnlyStoppingReachesASeat(): void
    {
        $names = [];
        foreach ((new SessionOperations(new DIContainer()))->operations() as $operation) {
            if (str_starts_with($operation->name, 'standing:')) {
                $names[$operation->name] = $operation;
            }
        }

        self::assertSame(['standing:grant', 'standing:reinstate', 'standing:revoke', 'standing:suspend', 'standing:granted'], array_keys($names));
        foreach (['standing:grant', 'standing:reinstate', 'standing:revoke'] as $personal) {
            self::assertSame(['identity:enroll'], $names[$personal]->scopes, $personal);
            self::assertSame(['cli'], $names[$personal]->surfaces, "{$personal} is never offered on a surface a seat works through");
            self::assertTrue($names[$personal]->requiresConfirmation);
        }
        self::assertSame([], $names['standing:suspend']->scopes, 'stopping asks for no word');
        self::assertFalse($names['standing:suspend']->requiresConfirmation);
        self::assertFalse($names['standing:suspend']->supportsSurface('http'), 'an operation with no scope is not served to a caller nobody judged');
        self::assertTrue($names['standing:suspend']->supportsSurface('mcp'));
        self::assertFalse($names['standing:granted']->mutating);
    }
}
