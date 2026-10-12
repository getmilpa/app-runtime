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
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Command\Standing\GrantState;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationBoundary;
use Milpa\Container\DIContainer;
use Milpa\McpServer\JsonRpcService;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What a third independent review found, each as its own probe reproduced it (greenhouse decisions/0615, «Lo que la
 * tercera revisión encontró»). It read the fixes of the second one and asked of each: what is the sibling it left?
 *
 * Every test here was red on the head it read. What only made the house do what the acta already said was fixed as
 * such; the rest was decided by Rod on 2026-10-12, on the narrow side each time, and is marked so.
 *
 * @subject-in milpa/app-runtime
 */
final class WhatAThirdReviewFoundInTheHouseTest extends TestCase
{
    private LabHouse $house;

    /** What the debugger's own limit on nested calls was, when a test raised it. */
    private string|false $nesting = false;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
        // A value nested 509 deep is walked level by level, and a debugger that stops a script at 512 nested calls
        // stops this test before the house has done anything: a runner's limit, not the house's.
        $this->nesting = ini_set('xdebug.max_nesting_level', '4096');
    }

    protected function tearDown(): void
    {
        if ($this->nesting !== false) {
            ini_set('xdebug.max_nesting_level', $this->nesting);
        }
        $this->house->destroy();
    }

    // ── M3 · a line the house writes is a line it can read back ─────────────────────────────────────────────

    /**
     * Each value is made by the test and not handed to it: the runner reads what it is handed, level by level.
     *
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function whatDoesNotReadBackTheSame(): iterable
    {
        yield 'a number JSON writes as -0 and reads as 0' => [static fn (): float => -0.0];
        yield 'an argument nested deeper than a line can be read' => [static function (): array {
            $deep = 'x';
            for ($level = 0; $level < 509; ++$level) {
                $deep = [$deep];
            }

            return $deep;
        }];
        yield 'text that is not text' => [static fn (): string => "\xB1\x31"];
    }

    #[DataProvider('whatDoesNotReadBackTheSame')]
    public function testACallTheGrantCoversDoesNotLeaveALineTheHouseCannotReadBack(\Closure $made): void
    {
        $value = $made();
        $this->house->enroll(LabHouse::OTHER_SEAT, ['agent:run', 'lab:scan'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->measured();
        self::assertNull($this->house->ledger()->fault(), 'control: the book verifies');

        // One more argument on a call the grant covers — `covers` is an open match — carrying that value.
        $said = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable', 'zz' => $value]);

        self::assertNull($this->house->ledger()->fault(), 'one call of a seat left the book unreadable for everyone');
        // What the house cannot write down it does not let pass: nothing ran.
        self::assertStringContainsString('could not be written', (string) ($said['refused'] ?? 'RAN'));
        self::assertSame([], $this->house->done('published'));
        // …and the house goes on: another seat works, and a person can still stop the grant.
        self::assertArrayNotHasKey('refused', $this->house->as(LabHouse::OTHER_SEAT, 'lab:scan', ['path' => $path]));
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stop'])['ok'] ?? false);
    }

    #[DataProvider('whatDoesNotReadBackTheSame')]
    public function testACallTheHouseRefusesDoesNotLeaveALineItCannotReadBackEither(\Closure $made): void
    {
        $value = $made();
        $this->house->granted();
        $unmeasured = $this->house->candidate('new.txt', "nobody measured this\n");

        $said = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured, 'channel' => 'stable', 'zz' => $value]);

        self::assertStringContainsString('was never measured', (string) ($said['refused'] ?? 'RAN'), 'control: the refusal stands, with or without its line');
        self::assertNull($this->house->ledger()->fault(), 'a refused call left the book unreadable for everyone');
        self::assertSame([], $this->house->lines(StandingLedger::REFUSED), 'a line that would not read back is not written');
    }

    public function testACallOverMcpDoesNotLeaveALineTheHouseCannotReadBack(): void
    {
        // The door a seat reaches without a terminal: the MCP service itself, fed the line a client sends.
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:publish'], LabHouse::PERSON);
        $this->house->granted();
        $this->house->candidate('new.txt', "nobody measured this\n");
        $this->house->container->registerService(CallPolicy::class, $this->house->policy);
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll([$this->house->operation('lab:publish')], $registry, $this->house->container);
        $line = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"lab_publish","arguments":{"path":"new.txt","channel":"stable","zz":-0.0}}}';

        $answer = (new JsonRpcService($registry))->handle(json_decode($line, true), ToolContext::stdio('1', 'key:' . LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:publish']));

        self::assertStringContainsString('was never measured', (string) json_encode($answer, \JSON_UNESCAPED_UNICODE), 'control: the house refused the call');
        self::assertNull($this->house->ledger()->fault());
    }

    // ── L1 · the head is read before the book ───────────────────────────────────────────────────────────────

    public function testALineThatLandsWhileTheBookIsBeingReadIsNotAFault(): void
    {
        // A judgment reads the book with no lock. Between its two readings — the book and its head — another process
        // writes a line. Whichever of the two is read SECOND, that is the moment.
        FillingDisk::mount();
        $book = 'filling://house/storage/identity/standing.jsonl';
        $head = $book . StandingLedger::HEAD;
        // What the other process leaves — the book and its head with one line more — made beforehand, and put in
        // place at that moment: a file cannot be opened again from inside its own opening.
        (new StandingLedger($book))->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'first']);
        $before = [FillingDisk::$files[$book], FillingDisk::$files[$head]];
        (new StandingLedger($book))->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'while it was being read']);
        $after = [FillingDisk::$files[$book], FillingDisk::$files[$head]];
        [FillingDisk::$files[$book], FillingDisk::$files[$head]] = $before;
        $opened = 0;
        FillingDisk::$opening = static function (string $path) use ($book, $head, $after, &$opened): void {
            if (++$opened === 2) {
                [FillingDisk::$files[$book], FillingDisk::$files[$head]] = $after;
            }
        };

        $reader = new StandingLedger($book);
        $fault = $reader->fault();
        FillingDisk::$opening = null;

        self::assertSame(2, $opened, 'control: the reader read two files, and the line landed between them');
        self::assertNull($fault, 'a book that is whole read as «emptied, truncated, deleted or put back from an older copy»');
        self::assertNull((new StandingLedger($book))->fault(), 'control: read again, it verifies');
        self::assertCount(2, (new StandingLedger($book))->entries());
    }

    public function testALineStillBeingWrittenWhileTheBookIsReadIsNotAFault(): void
    {
        // The sibling of L1, found measuring it by processes: a line that crosses a page of the file is, for an
        // instant, there by halves — and a judgment, which reads with no lock, read a whole book as «edited». Asked
        // of the bytes alone, about twenty readings in three hundred thousand ended in an unfinished line, every one
        // of them exactly at a page boundary.
        FillingDisk::mount();
        $book = 'filling://house/storage/identity/standing.jsonl';
        (new StandingLedger($book))->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'first']);
        (new StandingLedger($book))->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'while it was being read']);
        $whole = FillingDisk::$files[$book];
        $unfinished = substr($whole, 0, (int) strpos($whole, "\n") + 41);
        $opened = 0;
        FillingDisk::$opening = static function (string $path) use ($book, $whole, $unfinished, &$opened): void {
            if ($path === $book) {
                FillingDisk::$files[$book] = ++$opened === 1 ? $unfinished : $whole;
            }
        };

        $reader = new StandingLedger($book);
        $fault = $reader->fault();
        FillingDisk::$opening = null;

        self::assertNull($fault, 'a book that is whole, read while its last line was being written, read as «edited»');
        self::assertSame(2, $opened, 'control: the reader saw half a line, and looked again');
        self::assertCount(2, $reader->entries());
    }

    public function testABookThatEndsInALineNobodyFinishedIsStillAFault(): void
    {
        // The mirror: a line that STAYS unfinished — whoever wrote it died half way — is a fault of the book, said
        // after looking again a bounded number of times, never waited for.
        FillingDisk::mount();
        $book = 'filling://house/storage/identity/standing.jsonl';
        (new StandingLedger($book))->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'first']);
        (new StandingLedger($book))->append(StandingLedger::SUSPENDED, ['grant' => 'a', 'by' => 'someone', 'reason' => 'never finished']);
        FillingDisk::$files[$book] = substr(FillingDisk::$files[$book], 0, (int) strpos(FillingDisk::$files[$book], "\n") + 41);
        $opened = 0;
        FillingDisk::$opening = static function (string $path) use ($book, &$opened): void {
            $opened += $path === $book ? 1 : 0;
        };

        $fault = (new StandingLedger($book))->fault();
        FillingDisk::$opening = null;

        self::assertStringContainsString('line 2 does not link to the line before it', (string) $fault);
        self::assertGreaterThan(1, $opened, 'control: it was looked at again');
        self::assertLessThanOrEqual(21, $opened, 'it was looked at again without end');
    }

    // ── L3 · the listing says what the judge honours ────────────────────────────────────────────────────────

    public function testTheListingDoesNotCallInForceAGrantTheJudgeDoesNotHonour(): void
    {
        $this->house->granted();
        $path = $this->measured();
        $shown = fn (): array => $this->house->as(null, 'standing:granted', ['grant' => 'release-line'])['grants'][0];
        self::assertSame(['in force', null], [$shown()['stands_as'], $shown()['because']], 'control: as granted, it is in force');

        // The instrument is replaced under its name: the judge does not honour the grant any more.
        $this->house->hardenScanner();
        self::assertStringContainsString('is not the operation it was when this grant was given', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable'])['refused'] ?? 'RAN'), 'control: the judge');

        self::assertSame('not honoured', $shown()['stands_as'], 'a person is told «in force» of a grant under which nothing runs');
        self::assertStringContainsString('`lab:scan`, which measures condition «no-host-names», is not the operation it was when this grant was given', (string) $shown()['because']);
        self::assertNull($shown()['not_honoured_for']);
    }

    public function testTheListingSaysWhichOperationsOfAGrantAreNotHonouredWhenOnlySomeAreNot(): void
    {
        // One of the three operations the lab grant covers is replaced under its name; the other two still run.
        $this->house->granted();
        $path = $this->measured();
        $old = $this->house->operation('lab:announce');
        $this->house->offer(new Operation('lab:announce', $old->description, static fn (array $input): array => ['ok' => true, 'acted_on' => 'sha256:other'], $old->inputSchema, mutating: true, requiresConfirmation: true, scopes: $old->scopes, effects: $old->effects, actsOn: $old->actsOn));

        $shown = $this->house->as(null, 'standing:granted', ['grant' => 'release-line'])['grants'][0];

        self::assertSame('in force', $shown['stands_as']);
        self::assertNull($shown['because']);
        self::assertSame(['lab:announce'], array_keys((array) $shown['not_honoured_for']));
        self::assertStringContainsString('`lab:announce` is not the operation it was when this grant was given', (string) $shown['not_honoured_for']['lab:announce']);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: what is still the operation that was granted runs');
        // An operation a grant covers that the house no longer has is not honoured either, and the judge says which.
        $record = $this->house->book()->record('release-line');
        self::assertNotNull($record);
        $gone = $this->house->policy->standing()->notHonoured($record, fn (\Milpa\Command\Consent\OperationId|string $named): ?Operation => (string) $named === 'lab.merge' || $named instanceof \Milpa\Command\Consent\OperationId && $named->canonical === 'lab.merge' ? null : $this->house->policy->operationNamed($named));
        self::assertSame('`lab:merge` is not in this house any more', $gone['lab:merge'] ?? null);
    }

    // ── L5 · edges of the head, of a promotion, and of a name ───────────────────────────────────────────────

    public function testAHeadThatIsNotAFileIsAFault(): void
    {
        // A directory where the head was: the house can never put a head there again, and read as «this house never
        // kept one» that would switch the head off for good, in silence.
        $this->house->granted();
        $head = $this->house->root . '/' . StandingLedger::PATH . StandingLedger::HEAD;
        unlink($head);
        mkdir($head);

        self::assertStringContainsString('its head', (string) $this->house->ledger()->fault());
        self::assertStringContainsString('does not verify', (string) ($this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->house->candidate('good.txt', "clean release\n")])['refused'] ?? 'RAN'));
    }

    /** @return iterable<string, array{\Closure(string): void}> */
    public static function whatStandsWhereTheHeadWas(): iterable
    {
        yield 'a directory' => [static fn (string $head) => mkdir($head)];
        yield 'a link to nothing' => [static fn (string $head) => symlink($head . '.gone', $head)];
        yield 'a link to a file that says what the head said' => [static function (string $head): void {
            copy($head . '.was', $head . '.elsewhere');
            symlink($head . '.elsewhere', $head);
        }];
    }

    #[DataProvider('whatStandsWhereTheHeadWas')]
    public function testWhateverIsNotAFileTheHouseWroteWhereTheHeadWasIsAFaultWithTheBookOrWithoutIt(\Closure $put): void
    {
        $this->house->granted();
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $head = $book . StandingLedger::HEAD;
        rename($head, $head . '.was');
        $put($head);

        self::assertStringContainsString('its head is not a file this house wrote', (string) $this->house->ledger()->fault());
        // …and with the book gone too, it is still a house that kept one: never «nobody granted anything».
        unlink($book);
        self::assertTrue($this->house->ledger()->exists());
        self::assertStringContainsString('its head is not a file this house wrote', (string) $this->house->ledger()->fault());
        self::assertStringContainsString('does not verify', (string) ($this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->house->candidate('good.txt', "clean release\n")])['refused'] ?? 'RAN'));
    }

    public function testAPromotionNeverCarriesTheHeadOfTheBookEither(): void
    {
        mkdir($this->house->root . '/src/Plugins/Blog', 0o755, true);
        $trial = TrialWorkspace::materialize($this->house->root, 'whead', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        @mkdir($trial->copy . '/storage/identity', 0o755, true);
        file_put_contents($trial->copy . '/' . StandingLedger::PATH . StandingLedger::HEAD, '{"head":"sha256:written-in-a-rehearsal","lines":1}' . "\n");
        file_put_contents($trial->copy . '/src/Plugins/Blog/Blog.php', "<?php // the plugin\n");
        $promote = (new TrialOperations(new DIContainer(), root: $this->house->root))->operations()[0];
        self::assertSame('sandbox:promote', $promote->name);

        $promoted = ($promote->handler)(['workspace' => 'whead'], null, new ToolContext(principal: 'worker', channel: 'cli', scopes: ['*']));

        self::assertFalse($promoted['ok']);
        self::assertStringContainsString('a trial never carries the book of standing grants', (string) $promoted['error']);
        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH . StandingLedger::HEAD);
        self::assertFileDoesNotExist($this->house->root . '/src/Plugins/Blog/Blog.php', 'nothing of that trial crossed');
    }

    #[DataProvider('howACopyIsMade')]
    public function testACopyOfTheHouseHoldsNeitherTheBookNorItsHeadAndStaysCurrentWhileTheBookGrows(bool $rsync): void
    {
        // Every copy carried them, mode kept, for a confined process to read (decided by Rod, 2026-10-12).
        $this->house->granted();
        $path = $this->measured();
        self::assertFileExists($this->house->root . '/' . StandingLedger::PATH . StandingLedger::HEAD, 'control: the house keeps both');
        $was = getenv('PATH');
        if (!$rsync) {
            putenv('PATH=/nonexistent'); // a host with no rsync copies by its own walk
        }
        try {
            $trial = TrialWorkspace::materialize($this->house->root, $rsync ? 'wcopy' : 'wwalk', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        } finally {
            putenv('PATH=' . $was);
        }

        self::assertFileExists($trial->copy . '/' . $path, 'control: the copy holds the house');
        self::assertFileExists($trial->copy . '/storage/identity/enrollments.json', 'control: and what sits beside the book');
        self::assertFileDoesNotExist($trial->copy . '/' . StandingLedger::PATH);
        self::assertFileDoesNotExist($trial->copy . '/' . StandingLedger::PATH . StandingLedger::HEAD);
        self::assertSame([], $trial->diff(), 'a copy that leaves the book out does not propose to delete it');
        // The house writes its book with every act, and that is not a change to what a rehearsal read.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
        self::assertTrue($trial->hasCurrentInputs());
        file_put_contents($this->house->root . '/' . $path, "another candidate\n");
        self::assertFalse($trial->hasCurrentInputs(), 'control: what a rehearsal did read, changed, is seen');
    }

    /** @return iterable<string, array{bool}> */
    public static function howACopyIsMade(): iterable
    {
        yield 'with rsync' => [true];
        yield 'by the plain walk, where there is no rsync' => [false];
    }

    public function testTwoOperationsDoNotAnswerToOneNameInAHouse(): void
    {
        // The judge would read the last one registered under a name and the terminal run the first.
        $container = new DIContainer();
        $container->registerService(OperationBoundary::class, new PluginAuthoringPolicy($this->house->root));
        $one = new Operation('lab:twice', 'Lab: one operation', static fn (array $input): array => ['ok' => true, 'which' => 'one'], scopes: ['lab:twice'], effects: EffectProfile::readOnly());
        $other = new Operation('lab_twice', 'Lab: another, under the same identity', static fn (array $input): array => ['ok' => true, 'which' => 'other'], scopes: ['lab:twice'], effects: EffectProfile::readOnly());

        // The same operation declared twice in one catalogue is one operation, and so is a catalogue told again.
        self::assertCount(2, PluginAuthoringPolicy::catalogue($container, [$one, $one]));
        PluginAuthoringPolicy::catalogue($container, [$one]);
        $refused = null;
        try {
            PluginAuthoringPolicy::catalogue($container, [$one, $this->house->operation('lab:scan'), $other]);
        } catch (\RuntimeException $error) {
            $refused = $error->getMessage();
        }

        self::assertStringContainsString('two operations answer to `lab:twice` in this house', (string) $refused, 'a house took two operations under one name, and its judge and its terminal would not mean the same one');
        self::assertNull($container->get(OperationBoundary::class)->operationNamed('lab:scan'), 'a catalogue that is refused is refused whole: nothing of it is kept');
        self::assertSame($one, $container->get(OperationBoundary::class)->operationNamed('lab_twice'));
        // A boundary that is not the house's keeps no catalogue, and has nothing to refuse.
        self::assertCount(2, PluginAuthoringPolicy::catalogue(new DIContainer(), [$one, $other]));
    }

    // ── what Rod decided on 2026-10-12, each reproduced before it was built ─────────────────────────────────

    // M1 · every receipt and every sign carries the pin of what produced it

    public function testAMeasurementTakenByOtherCodeUnderTheInstrumentsNameIsNoReceiptForTheGrant(): void
    {
        // M1, as the review ran it: the file of the instrument is softened, used, and put back byte for byte.
        $strict = new LabGuardStrict($this->house->root);
        $lenient = new LabGuardLenient($this->house->root);
        self::assertSame($strict->scan()->instrumentDigest(), $lenient->scan()->instrumentDigest(), 'control: the contract reads the two as one instrument');
        $this->house->offer($strict->scan());
        $this->house->granted();
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        $publish = fn (): array => $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']);
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad]);
        self::assertStringContainsString('does not hold on this candidate', (string) ($publish()['refused'] ?? 'RAN'), 'control: the instrument measures it red');

        $this->house->offer($lenient->scan());
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad]), 'control: softened, it measures green');
        self::assertStringContainsString('is not the operation it was', (string) ($publish()['refused'] ?? 'RAN'), 'control: while it is softened, the grant is not honoured');
        $this->house->offer($strict->scan());
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertArrayHasKey('refused', $publish(), 'a red candidate was published on what other code measured');
        self::assertSame([], $this->house->done('published'));
    }

    public function testAGrantGivenAgainDoesNotInheritWhatTheInstrumentBeforeItFound(): void
    {
        // M1 with no adversary: a guard that did not see is hardened, and a person grants again over it.
        $strict = new LabGuardStrict($this->house->root);
        $this->house->offer((new LabGuardLenient($this->house->root))->scan());
        $this->house->granted();
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad]), 'control: the guard that does not see');
        $this->house->offer($strict->scan());
        $this->house->granted(['id' => 'release-line-2', 'supersedes' => 'release-line']);

        $published = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']);

        self::assertArrayHasKey('refused', $published, 'the new grant took the receipt of the instrument it replaced');
        self::assertSame([], $this->house->done('published'));
    }

    public function testASignOfLifeGivenByOtherCodeUnderTheWatchersNameDoesNotCount(): void
    {
        // M1 for the watcher, which the review traced: a sign carries no digest at all.
        $this->house->granted(watched: false);
        $path = $this->measured();
        $real = $this->house->operation('lab:watch');
        $this->house->fired = true;
        $this->house->offer(new Operation('lab:watch', $real->description, static fn (array $input): array => ['ok' => true, 'fired' => false], scopes: $real->scopes, effects: EffectProfile::readOnly()));
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $this->house->offer($real);

        $published = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);

        self::assertArrayHasKey('refused', $published, 'the falsifier fired, and a sign of life from a watcher that cannot see it kept the grant alive');
        self::assertSame([], $this->house->done('published'));
    }

    public function testAnUpdateOfThePackageAnOperationShipsInStopsItsGrantsUntilAPersonGivesThemAgain(): void
    {
        // D3: «it changed» is the unit it ships in — here a package, by the reference the house installed it at.
        $this->house->granted();
        $path = $this->measured();
        $publish = fn (): array => $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);

        $this->house->installed('lab-2');

        self::assertStringContainsString('`lab:publish` is not the operation it was when this grant was given', (string) ($publish()['refused'] ?? 'RAN'));
        self::assertSame('not honoured', $this->house->as(null, 'standing:granted', ['grant' => 'release-line'])['grants'][0]['stands_as']);
        $this->house->installed('lab-1');
        self::assertSame(['ok' => true], $publish(), 'at the reference it was given over, it is honoured');
        // A person gives it again over what is installed now — and what was measured before is measured again.
        $this->house->installed('lab-2');
        $this->house->granted(['id' => 'release-line-2', 'supersedes' => 'release-line']);
        $again = $this->house->candidate('again.txt', "another clean release\n");
        self::assertStringContainsString('was never measured', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $again, 'channel' => 'stable'])['refused'] ?? 'RAN'));
        self::assertStringContainsString('it is not that instrument any more', (string) ($publish()['refused'] ?? 'RAN'), 'the receipt of the package as it was is no receipt for the grant given over it as it is');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        self::assertSame(['ok' => true], $publish());
    }

    public function testAGrantIsNotGivenOverAnOperationWhoseUnitCannotBeTold(): void
    {
        // D3: it fails closed. Code that belongs to no package this house installed and to no plugin is code the
        // house could not tell had changed — whether it is what a grant covers, measures by, or is watched by.
        $this->house->installed(null);

        $given = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document()]);

        self::assertStringContainsString('`lab:publish` cannot be pinned: its code belongs to no unit this house can tell', (string) ($given['error'] ?? 'GIVEN'));
        self::assertStringContainsString('nothing was granted', (string) $given['error']);
        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH);
        // …and what was given while it could be told stops being honoured the day it cannot.
        $this->house->installed();
        $this->house->granted();
        $path = $this->measured();
        $signs = \count($this->house->lines(StandingLedger::WATCHED));
        $this->house->installed(null);
        self::assertStringContainsString('is not the operation it was when this grant was given', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable'])['refused'] ?? 'RAN'));
        // A watcher the house cannot pin gives no sign of life, and a measurement by an instrument it cannot pin
        // leaves no receipt — but «it fired» is written whoever answered it: stopping asks for nobody's pin.
        $receipts = \count($this->house->lines(StandingLedger::MEASURED));
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        self::assertSame([$signs, $receipts], [\count($this->house->lines(StandingLedger::WATCHED)), \count($this->house->lines(StandingLedger::MEASURED))]);
        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
    }

    // M2 · «it fired» is read open; a quiet sign is read closed

    public function testAWatcherThatSaysFiredStopsTheGrantWhateverElseItsRunCarried(): void
    {
        // M2: the closed match is right for what speaks FOR a grant. «It fired» only narrows.
        $this->house->granted();
        $path = $this->measured();
        $this->house->fired = true;

        self::assertSame(true, $this->house->as(LabHouse::SEAT, 'lab:watch', ['sign' => '1'])['fired'] ?? null, 'control: the watcher answered that it fired');

        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state, 'the watcher said «fired» and the house wrote nothing');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']));
    }

    // D4, read as D5 was completed (2026-10-12): «a watcher that says it fired ALWAYS stops» — from any run of it

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function runsOfTheWatcherThatAreNotTheOneTheFalsifierDescribes(): iterable
    {
        yield 'without the argument the falsifier fixes' => [[]];
        yield 'the fixed argument at another value' => [['line' => 'beta']];
        yield 'the fixed argument as another type' => [['line' => true]];
        yield 'the fixed argument, and one more' => [['line' => 'release', 'sign' => '1']];
    }

    /** @param array<string, mixed> $how */
    #[DataProvider('runsOfTheWatcherThatAreNotTheOneTheFalsifierDescribes')]
    public function testAWatcherThatSaysFiredStopsTheGrantFromAnyRunOfIt(array $how): void
    {
        $this->house->granted(['falsifier' => ['name' => 'a-published-name', 'statement' => 'a published release names a machine', 'watcher' => ['operation' => 'lab:watch', 'arguments' => ['line' => 'release']], 'within_seconds' => 3600]], watched: false);
        $path = $this->measured();
        // A quiet answer from that run is no sign of life: what speaks FOR a grant is the run its falsifier describes.
        self::assertSame(false, $this->house->as(LabHouse::SEAT, 'lab:watch', $how)['fired'] ?? null, 'control: the watcher answered that all is quiet');
        self::assertSame([], $this->house->lines(StandingLedger::WATCHED), 'a quiet answer from a run nobody described was kept as a sign of life');
        $this->house->as(LabHouse::SEAT, 'lab:watch', ['line' => 'release']);
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state, 'control: watched by the run the falsifier describes, the grant is in force');

        $this->house->fired = true;
        self::assertSame(true, $this->house->as(LabHouse::SEAT, 'lab:watch', $how)['fired'] ?? null, 'control: the watcher answered that it fired');

        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state, 'the watcher said «fired» and the grant is still in force');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']));
    }

    public function testALineThatSaysTheWatcherFiredStopsTheGrantWhateverArgumentsItNames(): void
    {
        // The same reading where the book is read: the line is the suspension, whatever run wrote it.
        $this->house->granted(['falsifier' => ['name' => 'a-published-name', 'statement' => 'a published release names a machine', 'watcher' => ['operation' => 'lab:watch', 'arguments' => ['line' => 'release']], 'within_seconds' => 3600]], watched: false);
        $this->house->as(LabHouse::SEAT, 'lab:watch', ['line' => 'release']);

        $this->house->writtenByHand(StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => ['line' => 'beta'], 'fired' => true]);

        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        // …and «fired» by ANOTHER operation stops nothing here: it is this falsifier's watcher that is listened to.
        $this->house->granted(['id' => 'second-line', 'falsifier' => ['name' => 'a-published-name', 'statement' => 'a published release names a machine', 'watcher' => ['operation' => 'lab:watch', 'arguments' => ['line' => 'release']], 'within_seconds' => 3600]], watched: false);
        $this->house->writtenByHand(StandingLedger::WATCHED, ['watcher' => 'lab.scan', 'arguments' => ['line' => 'release'], 'fired' => true]);
        self::assertSame(GrantState::InForce, $this->house->book()->record('second-line')?->state, 'another operation said «fired», and this grant stopped');
    }

    public function testAQuietSignFromARunWithAnArgumentNobodyDescribedStillDoesNotCount(): void
    {
        // The mirror, and it stays: what speaks FOR a grant is the run its falsifier describes, and no other.
        $this->house->granted(watched: false);
        $path = $this->measured();

        self::assertSame(false, $this->house->as(LabHouse::SEAT, 'lab:watch', ['sign' => '1'])['fired'] ?? null, 'control: the watcher answered that all is quiet');

        self::assertSame([], $this->house->lines(StandingLedger::WATCHED));
        self::assertStringContainsString('has given no sign of life', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable'])['refused'] ?? 'RAN'));
    }

    public function testFiredStopsAGrantWhoeverAnsweredItAndWhateverItsFalsifierFixes(): void
    {
        // Stopping asks for nobody's pin: other code under the watcher's name that says «fired» stops the grant too.
        // «The arguments the falsifier fixes» were still asked, and this test pinned that a run for another scope was
        // not this falsifier firing. It is now (D4 read as D5 was completed, 2026-10-12: «fired» ALWAYS stops, from
        // any run of that operation). What that costs is here on purpose: one watcher that serves two scopes stops
        // the grants of both when it fires for one.
        $real = $this->house->operation('lab:watch');
        $this->house->granted(['falsifier' => ['name' => 'a-published-name', 'statement' => 's', 'watcher' => ['operation' => 'lab:watch', 'arguments' => ['scope' => 'releases']], 'within_seconds' => 3600]], watched: false);
        $this->house->as(LabHouse::SEAT, 'lab:watch', ['scope' => 'releases']);
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state);
        $this->house->offer(new Operation('lab:watch', $real->description, static fn (array $input): array => ['ok' => true, 'fired' => true], scopes: $real->scopes, effects: EffectProfile::readOnly()));

        $this->house->as(LabHouse::SEAT, 'lab:watch', ['scope' => 'another']);

        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state, 'the watcher fired, for another scope, and the grant is still in force');
        self::assertSame(false, $this->house->book()->record('release-line')?->stopped, 'a falsifier that fired is a suspension a person lifts by name, as before');
    }

    // M4 · a line that says a grant was given and cannot be read as one is a fault of the book

    public function testAGrantedLineTheHouseCannotReadIsNotReadAsNothing(): void
    {
        // M4. A line of the book says a person gave a grant, and today's contract does not read its document — the
        // way a document written under an older contract stops being read. What it replaced must not come back.
        $this->house->granted(); // covers publish(stable), merge and announce
        $path = $this->measured();
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: under the wide grant the seat merges');
        $document = (string) json_encode(json_decode($this->house->document(['id' => 'release-line-2', 'supersedes' => 'release-line', 'covers' => [['operation' => 'lab:publish', 'arguments' => ['channel' => 'stable']]]]), true) + ['written_under' => 'another contract']);
        $signed = $this->house->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $document]);
        $this->house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'release-line-2', 'digest' => 'sha256:x', 'payload' => $signed->payload, 'signature' => $signed->signature, 'fingerprint' => LabHouse::PERSON]);

        $refused = (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN');
        self::assertStringContainsString('says a grant was given, and it cannot be read as one today', $refused, 'a grant the house cannot read vanished, and what it had replaced was in force again');
        self::assertCount(1, $this->house->done('merged'));
    }

    public function testAGrantTheHouseCannotReadStopsEverySeatWhateverElseTheBookHolds(): void
    {
        // M4, the other shape the review ran: a stop written on a grant that can no longer be read names nothing,
        // and another grant then lends. A book with such a line does not verify: no grant in it is honoured, and no
        // line is added to it — a person looks.
        $this->house->granted(['id' => 'second-line']);
        $path = $this->measured();
        $document = (string) json_encode(json_decode($this->house->document(['id' => 'first-line']), true) + ['written_under' => 'another contract']);
        $signed = $this->house->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $document]);
        $this->house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'first-line', 'digest' => 'sha256:x', 'payload' => $signed->payload, 'signature' => $signed->signature, 'fingerprint' => LabHouse::PERSON]);

        self::assertStringContainsString('does not verify', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable'])['refused'] ?? 'RAN'), 'a grant the house cannot read was read as nothing');
        self::assertSame([], $this->house->done('published'));
        self::assertStringContainsString('BOOK', strtoupper((string) json_encode(array_keys($this->house->as(null, 'standing:granted')))));
        self::assertStringContainsString('carries "written_under"', (string) $this->house->as(null, 'standing:granted')['book']);
    }

    // L2 · «the same refusal» is the same line, and the count is a seat's under a grant

    public function testARefusalThatDiffersInHowAnArgumentIsWrittenIsAnotherRefusal(): void
    {
        // L2 (a): «the same» was PHP's loose comparison.
        $this->house->granted();
        $this->house->candidate('new.txt', "nobody measured this\n");
        $call = fn (mixed $build): array => $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => 'new.txt', 'channel' => 'stable', 'build' => $build]);
        $call('100');
        self::assertCount(1, $this->house->lines(StandingLedger::REFUSED));

        foreach (['1e2', ' 100', '100.0', 100, '0100'] as $written) {
            $call($written);
        }

        self::assertCount(6, $this->house->lines(StandingLedger::REFUSED));
    }

    public function testOneSeatsRefusalsDoNotSilenceAnothers(): void
    {
        // L2 (b): the cap was the book's, not a seat's.
        $this->house->enroll(LabHouse::OTHER_SEAT, ['agent:run', 'lab:scan', 'lab:watch'], LabHouse::PERSON);
        $this->house->granted();
        $this->house->granted(['id' => 'other-line', 'to' => [LabHouse::OTHER_SEAT], 'covers' => [['operation' => 'lab:merge']]]);
        $this->house->candidate('new.txt', "nobody measured this\n");
        for ($which = 0; $which < HouseStandingJudge::REFUSALS_KEPT + 8; ++$which) {
            $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => 'new.txt', 'channel' => 'stable', 'which' => $which]);
        }

        self::assertArrayHasKey('refused', $this->house->as(LabHouse::OTHER_SEAT, 'lab:merge', ['path' => 'new.txt']), 'control: the other seat is refused under its own grant');

        self::assertCount(1, array_filter($this->house->lines(StandingLedger::REFUSED), static fn (array $line): bool => $line['seat'] === LabHouse::OTHER_SEAT), "one seat's refusals kept the book from saying another seat was refused");
    }

    public function testTheLastRefusalTheBookKeepsSaysTheRestAreNotWritten(): void
    {
        $this->house->granted();
        $this->house->granted(['id' => 'second-line', 'covers' => [['operation' => 'lab:announce']]]);
        $this->house->candidate('new.txt', "nobody measured this\n");
        $refusals = fn (string $grant): array => array_values(array_filter($this->house->lines(StandingLedger::REFUSED), static fn (array $line): bool => $line['grant'] === $grant));
        $publish = fn (int $which): array => $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => 'new.txt', 'channel' => 'stable', 'which' => $which]);
        for ($which = 0; $which < HouseStandingJudge::REFUSALS_KEPT + 8; ++$which) {
            self::assertArrayHasKey('refused', $publish($which), 'the refusal itself is given every time');
        }

        $kept = $refusals('release-line');
        self::assertCount(HouseStandingJudge::REFUSALS_KEPT, $kept);
        self::assertSame(HouseStandingJudge::REFUSALS_END, $kept[HouseStandingJudge::REFUSALS_KEPT - 1]['then'] ?? null, 'a reader of the book is not told where it stops being a count');
        self::assertSame([], array_filter(\array_slice($kept, 0, -1), static fn (array $line): bool => isset($line['then'])), 'only the last one says so');
        // The last one kept, asked again, is still «the same»; and the count is of that seat under THAT grant.
        $publish(HouseStandingJudge::REFUSALS_KEPT - 1);
        self::assertCount(HouseStandingJudge::REFUSALS_KEPT, $refusals('release-line'));
        $this->house->as(LabHouse::SEAT, 'lab:announce', ['path' => 'new.txt']);
        self::assertCount(1, $refusals('second-line'), 'the same seat, refused under another grant, is another count');
        // …and so is another seat refused under the same grant.
        $this->house->enroll(LabHouse::OTHER_SEAT, LabHouse::SEAT_SCOPES, LabHouse::PERSON);
        $this->house->grantedByHand(['id' => 'third-line', 'to' => [LabHouse::SEAT, LabHouse::OTHER_SEAT], 'covers' => [['operation' => 'lab:merge']]]);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        for ($which = 0; $which < HouseStandingJudge::REFUSALS_KEPT + 2; ++$which) {
            $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => 'new.txt', 'which' => $which]);
        }
        $this->house->as(LabHouse::OTHER_SEAT, 'lab:merge', ['path' => 'new.txt']);
        self::assertSame([HouseStandingJudge::REFUSALS_KEPT, 1], [\count(array_filter($refusals('third-line'), static fn (array $line): bool => $line['seat'] === LabHouse::SEAT)), \count(array_filter($refusals('third-line'), static fn (array $line): bool => $line['seat'] === LabHouse::OTHER_SEAT))]);
        self::assertStringContainsString('not written after this one', (string) json_encode($this->house->as(null, 'standing:granted')['recent']));
        // Whatever else is written ends the run, and what is refused after it is a new fact.
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $publish(0);
        self::assertCount(HouseStandingJudge::REFUSALS_KEPT + 1, $refusals('release-line'));
    }

    // L4 · a grant names the recognition it was given over, and only a new grant lifts what a decay stopped

    public function testASeatThatLeftTheHouseAndIsRecognizedAgainDoesNotGetItsGrantBack(): void
    {
        // L4 (E5): recognizing a key again names no grant.
        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->measured();
        $ledger = new FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json');
        $ledger->revoke(LabHouse::SEAT, 'key:' . LabHouse::PERSON);
        self::assertNotNull($this->house->book()->record('release-line')?->fault, 'control: while the seat is out, the grant is not honoured');

        $this->house->enroll(LabHouse::SEAT, LabHouse::SEAT_SCOPES, LabHouse::STRANGER);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'a seat recognized again, by another person, acted under a grant nobody gave it again');
        // …and by the SAME person, it is the same: recognizing a key names no grant.
        (new FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json'))->revoke(LabHouse::SEAT, 'key:' . LabHouse::STRANGER);
        $this->house->enroll(LabHouse::SEAT, LabHouse::SEAT_SCOPES, LabHouse::PERSON);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $refused = (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN');
        self::assertStringContainsString('left this house and was recognized again since this grant was given', $refused);
        self::assertStringContainsString('seat …' . substr(LabHouse::SEAT, -16), $refused);
        self::assertSame('not honoured', $this->house->as(null, 'standing:granted', ['grant' => 'release-line'])['grants'][0]['stands_as']);
        // A person gives it again, to the seat as it is recognized now.
        $this->house->granted(['id' => 'release-line-2', 'supersedes' => 'release-line']);
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
    }

    public function testAfterASeatReturnsTheListingStillSaysWhichGrantsAreGoneAndWhichOneStops(): void
    {
        // Measured on a real leg: once a seat has left and returned, EVERY grant given to it before reads «not
        // honoured» — and the listing said only that, of a grant a person had withdrawn and of one that is stopped
        // and keeps every other grant over the same call from lending. A person reads there what to do next.
        $this->house->granted(['id' => 'withdrawn-line']);
        $this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'withdrawn-line']);
        $this->house->granted();
        $this->house->granted(['id' => 'release-line-2', 'supersedes' => 'release-line']);
        $path = $this->measured();
        $ledger = new FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json');
        $ledger->revoke(LabHouse::SEAT, 'key:' . LabHouse::PERSON);
        $this->house->enroll(LabHouse::SEAT, LabHouse::SEAT_SCOPES, LabHouse::PERSON);
        $shown = [];
        foreach ($this->house->as(null, 'standing:granted')['grants'] as $grant) {
            $shown[$grant['grant']] = $grant;
        }

        // Withdrawn stays «revoked»: nothing brings it back, and that is what a person has to know of it.
        self::assertSame('revoked', $shown['withdrawn-line']['stands_as']);
        // The grant that replaced another no longer holds, so the older one is STOPPED — and says by which stop.
        self::assertSame('stopped', $shown['release-line']['stands_as'], 'a grant that stops every other over the same call read only «not honoured»');
        self::assertStringContainsString('«release-line-2» names it as replaced', (string) $shown['release-line']['because']);
        self::assertStringContainsString('"lifts": "' . $shown['release-line']['suspension'] . '"', (string) $shown['release-line']['because']);
        self::assertStringContainsString('left this house and was recognized again', (string) $shown['release-line']['because'], 'and why it is not honoured either');
        self::assertSame('not honoured', $shown['release-line-2']['stands_as']);
        // What it costs, said as it is: a new grant over the same act lends nothing while that stop stands…
        $this->house->granted(['id' => 'fresh-line']);
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        self::assertStringContainsString('it lends nothing while «release-line» is suspended', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'));
        // …until a person withdraws the stopped one, or gives a grant that names its stop.
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line'])['ok'] ?? false);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
    }

    public function testAPersonWhoLeftAndIsRecognizedAgainDoesNotBringBackTheGrantsTheySigned(): void
    {
        // L4, the signer: a second person of the line gives a grant to a seat they enrolled, leaves, and comes back.
        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll', 'lab:scan', 'lab:watch'], LabHouse::PERSON);
        $this->house->enroll(LabHouse::OTHER_SEAT, LabHouse::SEAT_SCOPES, LabHouse::STRANGER);
        $given = $this->house->signed(LabHouse::STRANGER, 'standing:grant', ['grant' => $this->house->document(['to' => [LabHouse::OTHER_SEAT]])]);
        self::assertTrue($given['ok'] ?? false, (string) json_encode($given));
        $this->house->as(LabHouse::OTHER_SEAT, 'lab:watch');
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::OTHER_SEAT, 'lab:scan', ['path' => $path]);
        $merge = fn (): array => $this->house->as(LabHouse::OTHER_SEAT, 'lab:merge', ['path' => $path]);
        self::assertSame(['ok' => true], $merge(), 'control: under that person\'s grant the seat merges');
        $ledger = new FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json');

        $ledger->revoke(LabHouse::STRANGER, 'key:' . LabHouse::PERSON);
        self::assertArrayHasKey('refused', $merge(), 'control: while the signer is out, the grant is not honoured');
        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll', 'lab:scan', 'lab:watch'], LabHouse::PERSON);
        $this->house->as(LabHouse::OTHER_SEAT, 'lab:watch');

        $refused = (string) ($merge()['refused'] ?? 'RAN');
        self::assertStringContainsString('the key that signed it left this house and was recognized again since this grant was given', $refused);
        self::assertCount(1, $this->house->done('merged'));
    }

    public function testASeatWhoseWordsAreTypedAgainWhileItStaysKeepsItsGrant(): void
    {
        // What «another recognition» is NOT: a key that never left. Who answers for it is asked on its own, every time.
        $this->house->granted();
        $path = $this->measured();

        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:other'], LabHouse::PERSON);

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
    }

    public function testAGrantWhoseLineDoesNotSayWhichRecognitionsItWasGivenOverIsNotHonoured(): void
    {
        // A line written before the house kept them, or by hand without them: it cannot be told which seat it lent to.
        $this->house->grantedByHand(line: ['recognized' => null]);
        self::assertStringContainsString('does not say which recognition', (string) $this->house->book()->record('release-line')?->fault);
        // …or one that says them of other keys, or says another count than the ledger's.
        $other = new LabHouse();
        try {
            $other->grantedByHand(line: ['recognized' => [LabHouse::SEAT => 0]]);
            self::assertStringContainsString('the key that signed it left this house and was recognized again', (string) $other->book()->record('release-line')?->fault);
            $other->grantedByHand(['id' => 'second-line'], line: ['recognized' => [LabHouse::SEAT => 1, LabHouse::PERSON => 0]]);
            self::assertStringContainsString('seat …' . substr(LabHouse::SEAT, -16) . ' left this house', (string) $other->book()->record('second-line')?->fault);
            $other->grantedByHand(['id' => 'third-line']);
            self::assertNull($other->book()->record('third-line')?->fault, 'control: with the recognitions that stand, it is honoured');
        } finally {
            $other->destroy();
        }
    }

    public function testAGrantThatWasReadWithdrawnIsNotInForceAgainByComingsAndGoings(): void
    {
        // L4 (E4): withdrawn by a second person, who leaves, comes back and leaves again.
        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->measured();
        $ledger = new FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json');
        $this->house->signed(LabHouse::STRANGER, 'standing:revoke', ['grant' => 'release-line']);
        $ledger->revoke(LabHouse::STRANGER, 'key:' . LabHouse::PERSON);
        $stop = (string) $this->house->book()->record('release-line')?->suspension;
        self::assertSame('stopped', $this->house->book()->record('release-line')?->standsAs($this->house->now), 'control: the withdrawal decayed, and it stops the grant');

        // The first person lifts that stop by name — which is what brought the grant back. It lifts nothing now.
        $lifted = $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $stop]);
        self::assertStringContainsString('a reinstatement does not lift that', (string) ($lifted['error'] ?? 'LIFTED'));
        self::assertSame([], $this->house->lines(StandingLedger::REINSTATED));
        // …and neither does a reinstatement written by hand, genuinely signed by that person over that very stop.
        $signed = $this->house->signature(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $stop]);
        $this->house->writtenByHand(StandingLedger::REINSTATED, ['grant' => 'release-line', 'suspension' => $stop, 'payload' => $signed->payload, 'signature' => $signed->signature, 'fingerprint' => LabHouse::PERSON]);
        self::assertSame('stopped', $this->house->book()->record('release-line')?->standsAs($this->house->now));

        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll'], LabHouse::PERSON);
        self::assertSame(GrantState::Revoked, $this->house->book()->record('release-line')?->state, 'control: the withdrawal is a person\'s again, and the grant reads withdrawn');
        $ledger->revoke(LabHouse::STRANGER, 'key:' . LabHouse::PERSON);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'a grant that read «withdrawn — nothing brings it back» was in force again');
        self::assertSame('stopped', $this->house->book()->record('release-line')?->standsAs($this->house->now));
        self::assertCount(0, $this->house->done('merged'));
    }

    // ── what this round leaned on, and no test had asked ────────────────────────────────────────────────────

    public function testAnOperationThatDeclaresNoSchemaIsNeverCoveredByAGrant(): void
    {
        // The terminal hands such an operation every flag it was typed with, `--sign` among them — and that is
        // harmless for what a grant COVERS only because no grant covers one. Nothing had asked it.
        $this->house->offer(
            new Operation('lab:bare', 'Lab: an act that declares no input schema', static fn (array $input): array => ['ok' => true], mutating: true, scopes: ['lab:bare'], effects: $this->house->operation('lab:merge')->effects, actsOn: static fn (array $input): ?string => 'sha256:x'),
            new Operation('lab:unreadable', 'Lab: an act whose handler is not written in a file', 'array_values', ['type' => 'object', 'properties' => []], mutating: true, scopes: ['lab:bare'], effects: $this->house->operation('lab:merge')->effects, actsOn: static fn (array $input): ?string => 'sha256:x'),
        );
        $given = fn (string $operation): string => (string) ($this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['covers' => [['operation' => $operation]]])])['error'] ?? 'GIVEN');

        self::assertStringContainsString('`lab:bare` declares no input schema, so a call of it cannot be told from another before it runs', $given('lab:bare'));
        self::assertStringContainsString('`lab:unreadable` cannot be pinned: its handler cannot be read', $given('lab:unreadable'));
        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH);
    }

    public function testTheDoorSaysWhatThereIsToSupersedeAndWhatThereIsToLift(): void
    {
        $this->house->granted();
        $given = fn (array $change): string => (string) ($this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document($change)])['error'] ?? 'GIVEN');

        // A grant that says it lifts a stop of one no stop stands on — what a person reads when the stop they meant
        // to name is gone, or never was.
        self::assertStringContainsString('says it lifts a suspension of «release-line», and no suspension stands on it', $given(['id' => 'second', 'supersedes' => 'release-line', 'lifts' => 'sha256:of-nothing']));
        // …and one that names a grant already withdrawn: there is nothing to take out of the way.
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line'])['ok'] ?? false);
        self::assertStringContainsString('«release-line» is already revoked: there is nothing to supersede', $given(['id' => 'second', 'supersedes' => 'release-line']));
        self::assertCount(1, $this->house->lines(StandingLedger::GRANTED));
    }

    // ── the sibling of D7 in the house: the reason of a stop is written by any seat, and a person reads it ──

    public function testTheReasonOfAStopIsOneLineOfPlainTextOrNothingIsStopped(): void
    {
        // Every text a grant keeps is one line of plain text (decided by Rod, 2026-10-12), because a listing and a
        // refusal print it. The reason of a stop is printed by the same listing and by the judge's own sentence —
        // and whoever writes it is ANY seat the house recognizes.
        $this->house->granted();
        $forged = "seen\x1b[2K\rin force — nothing was stopped.\x1b[8m";

        $said = $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => $forged]);

        self::assertStringContainsString('one line of plain text', (string) ($said['error'] ?? 'SUSPENDED, and the listing prints: ' . json_encode($this->house->as(null, 'standing:granted', ['grant' => 'release-line'])['grants'][0]['because'] ?? null)));
        self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', (string) ($said['error'] ?? '')), 'the refusal says it back raw');
        self::assertSame([], $this->house->lines(StandingLedger::SUSPENDED), 'nothing was written');
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state);
        foreach (["two\nlines", "a\ttab", "turned \u{202e}dnuora"] as $reason) {
            self::assertStringContainsString('one line of plain text', (string) ($this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => $reason])['error'] ?? 'SUSPENDED'));
        }
        // Control: a reason in plain words, in any language, stops the grant as before.
        self::assertTrue($this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'se publicó «build-host-7» — revisar'])['ok'] ?? false);
        self::assertStringContainsString('se publicó «build-host-7» — revisar', (string) $this->house->book()->record('release-line')?->because);
    }

    public function testAReasonWrittenByHandIsSaidBackAsItIsSpelledNeverAsItCame(): void
    {
        // Whoever holds the disk writes what they like: the stop stands, and what a person reads of it is spelled out.
        $this->house->granted();
        $this->house->writtenByHand(StandingLedger::SUSPENDED, ['grant' => 'release-line', 'by' => "key:\x1b[8m", 'reason' => "seen\x1b[2K\rin force."]);

        $record = $this->house->book()->record('release-line');
        self::assertSame(GrantState::Suspended, $record?->state);
        self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', (string) $record?->because), 'the judge\'s own sentence carries a byte a terminal obeys');
        self::assertStringContainsString('"seen\\u001b[2K\\rin force."', (string) $record?->because);
        $recent = implode(' | ', $this->house->as(null, 'standing:granted')['recent']);
        self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $recent), 'the listing prints a byte a terminal obeys');
        self::assertStringContainsString('by "key:\\u001b[8m": "seen\\u001b[2K\\rin force."', $recent);
    }

    public function testATextWrittenByHandIsNotRepairedBeforeItIsShown(): void
    {
        // What is blank around a text was taken off it before it was looked at, so a reason that ended in a line break
        // was printed as if it had not. A text is shown as it is, or spelled out: nothing trims it.
        self::assertSame('seen it', \Milpa\AppRuntime\Standing\StandingBook::said('seen it'));
        self::assertSame('"seen it\n"', \Milpa\AppRuntime\Standing\StandingBook::said("seen it\n"));
        self::assertSame('" seen it"', \Milpa\AppRuntime\Standing\StandingBook::said(' seen it'));
        self::assertSame('"seen\u200bit"', \Milpa\AppRuntime\Standing\StandingBook::said("seen\u{200B}it"), 'and what is not seen is spelled out too');
        self::assertSame('nothing', \Milpa\AppRuntime\Standing\StandingBook::said("  \n", 'nothing'));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function linesWrittenByHandThatCarryWhatATerminalObeys(): iterable
    {
        $esc = "\x1b[2K\rall is well\x1b[8m";
        yield 'a sign, by its watcher' => [StandingLedger::WATCHED, ['watcher' => 'lab.other' . $esc, 'arguments' => [], 'fired' => false]];
        yield 'an act, by its grant' => [StandingLedger::ABSORBED, ['grant' => 'g' . $esc, 'operation' => 'lab.publish', 'seat' => LabHouse::SEAT]];
        yield 'an act, by its operation' => [StandingLedger::ABSORBED, ['grant' => 'g', 'operation' => 'lab.publish' . $esc, 'seat' => LabHouse::SEAT]];
        yield 'an act, by what it acted on' => [StandingLedger::ABSORBED, ['grant' => 'g', 'operation' => 'lab.publish', 'seat' => LabHouse::SEAT, 'subject' => $esc]];
        yield 'a refusal, by its grant' => [StandingLedger::REFUSED, ['grant' => 'g' . $esc, 'operation' => 'lab.publish', 'seat' => LabHouse::SEAT]];
        yield 'a refusal, by its operation' => [StandingLedger::REFUSED, ['grant' => 'g', 'operation' => 'lab.publish' . $esc, 'seat' => LabHouse::SEAT]];
        yield 'a refusal, by its reason' => [StandingLedger::REFUSED, ['grant' => 'g', 'operation' => 'lab.publish', 'seat' => LabHouse::SEAT, 'reason' => $esc]];
        yield 'a refusal, by what it says comes after' => [StandingLedger::REFUSED, ['grant' => 'g', 'operation' => 'lab.publish', 'seat' => LabHouse::SEAT, 'then' => $esc]];
        yield 'a stop, by the grant it names' => [StandingLedger::SUSPENDED, ['grant' => 'g' . $esc, 'by' => 'someone', 'reason' => 'r']];
        yield 'a reinstatement, by the grant it names' => [StandingLedger::REINSTATED, ['grant' => 'g' . $esc, 'suspension' => 'sha256:x']];
    }

    /** @param array<string, mixed> $line */
    #[DataProvider('linesWrittenByHandThatCarryWhatATerminalObeys')]
    public function testTheListingSpellsOutWhateverALineWrittenByHandHolds(string $kind, array $line): void
    {
        $this->house->granted();
        $this->house->writtenByHand($kind, $line);
        self::assertNull($this->house->ledger()->fault(), 'control: the house reads that line');

        $recent = implode(' | ', $this->house->as(null, 'standing:granted')['recent']);

        self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $recent), 'the listing prints a byte a terminal obeys');
        self::assertStringContainsString('\\u001b[2K\\rall is well\\u001b[8m', $recent, 'control: the line is listed, spelled out');
    }

    public function testAReceiptWhoseOperationIsNotOneIsALineTheHouseCannotRead(): void
    {
        // It used to be listed, spelled out. The contract no longer reads it as a receipt at all (its fifth review,
        // L3): a line that names no operation is matched against no condition, so it is a fault of the book — and
        // the fault says where, never what the line held.
        $this->house->granted();
        $this->house->writtenByHand(StandingLedger::MEASURED, ['by' => "lab.scan\x1b[2K\rall is well\x1b[8m", 'arguments' => [], 'subject' => 'sha256:x', 'ok' => false, 'instrument' => 'sha256:y', 'at' => '2026-10-11T12:00:00+00:00']);
        // The same for a receipt whose CONTENT is named with what a terminal obeys, or with a line break after it (the
        // contract's sixth review: nothing is trimmed from the digests of a receipt). It was listed, spelled out.
        foreach (["sha256:x\x1b[2K\rall is well\x1b[8m", "sha256:x\n"] as $digest) {
            $other = new LabHouse();
            $other->granted();
            $other->writtenByHand(StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => [], 'subject' => $digest, 'ok' => false, 'instrument' => 'sha256:y', 'at' => '2026-10-11T12:00:00+00:00']);
            self::assertStringContainsString('is a «measured» line this house cannot read today', (string) $other->ledger()->fault());
            $other->destroy();
        }

        $fault = (string) $this->house->ledger()->fault();

        // …and the listing still shows that line to whoever comes to look at the book — spelled out, never as it came.
        $listed = $this->house->as(null, 'standing:granted');
        $recent = implode(' | ', $listed['recent'] ?? []);
        self::assertStringContainsString('\\u001b[2K\\rall is well\\u001b[8m', $recent, 'control: the line that cannot be read is listed');
        self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $recent), 'the listing prints a byte a terminal obeys');

        self::assertStringContainsString('is a «measured» line this house cannot read today', $fault);
        self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $fault));
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        self::assertStringContainsString('does not verify', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable'])['refused'] ?? 'RAN'));
    }

    public function testWhoRanAWatcherThatFiredIsSpelledOutToo(): void
    {
        $this->house->granted();
        $this->house->writtenByHand(StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => true, 'who' => "key:\x1b[8m"]);

        $because = (string) $this->house->book()->record('release-line')?->because;

        self::assertStringContainsString('its falsifier «a-published-name» fired', $because);
        self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $because));
        self::assertStringContainsString('run by "key:\\u001b[8m"', $because);
    }

    // ── decided by Rod, 2026-10-12 (D5): a red answer from a run that fits a condition open is written down ──

    public function testARunOfTheInstrumentWithOneArgumentMoreThatSaysNoIsWrittenDownAndSpeaks(): void
    {
        $this->house->granted();
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        // The scanner, asked leniently, says yes of it — and that leaves no receipt, as before.
        $this->house->scanner = static fn (string $bytes): array => ['ok' => true];
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad, 'lenient' => true]);
        self::assertSame([], $this->house->lines(StandingLedger::MEASURED), 'control: a yes from a run nobody described is not a receipt');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']), 'control: measured green by the run the condition describes, it is published');

        // Asked again with one argument more, it says no of that very content.
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad, 'deep' => true]);

        $receipts = $this->house->lines(StandingLedger::MEASURED);
        self::assertCount(2, $receipts, 'the instrument said no of this content, and the house wrote nothing');
        self::assertSame([false, ['deep' => true, 'path' => $bad]], [$receipts[1]['ok'], $receipts[1]['arguments']]);
        self::assertStringContainsString('does not hold on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable'])['refused'] ?? 'RAN'));
        self::assertCount(1, $this->house->done('published'));
        // …until the run the condition describes measures it again.
        $this->house->scanner = static fn (string $bytes): array => ['ok' => true];
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']));
    }

    // ── decided by Rod, 2026-10-12 (D5, complete): a NO counts from ANY run of the instrument on that content ──

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function runsThatAreNotTheOneTheConditionDescribes(): iterable
    {
        yield 'without the argument the condition fixes' => [[]];
        yield 'the fixed argument at another value' => [['strict' => false]];
        yield 'the fixed argument as another type' => [['strict' => 'yes']];
        yield 'the fixed argument, and one more' => [['strict' => true, 'deep' => true]];
    }

    /** @param array<string, mixed> $how */
    #[DataProvider('runsThatAreNotTheOneTheConditionDescribes')]
    public function testANoFromAnyRunOfTheInstrumentOnThatContentIsWrittenDownAndStopsTheAct(array $how): void
    {
        $this->house->granted(['conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'lab:scan', 'arguments' => ['strict' => true], 'free' => ['path']]]]]);
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        $this->house->scanner = static fn (string $bytes): array => ['ok' => true];
        // A YES from that run is not this condition measured, and leaves no receipt.
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad] + $how);
        self::assertSame([], $this->house->lines(StandingLedger::MEASURED), 'control: a yes from a run nobody described is not a receipt');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad, 'strict' => true]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']), 'control: measured green by the run the condition describes, it is published');

        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad] + $how);

        $receipts = $this->house->lines(StandingLedger::MEASURED);
        self::assertCount(2, $receipts, 'the instrument said no of this content, and the house wrote nothing');
        self::assertFalse($receipts[1]['ok']);
        self::assertStringContainsString('does not hold on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable'])['refused'] ?? 'RAN'));
        self::assertCount(1, $this->house->done('published'));
        // …until the run the condition describes measures it again.
        $this->house->scanner = static fn (string $bytes): array => ['ok' => true];
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad, 'strict' => true]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']));
    }

    public function testANoFromAnotherOperationOrAboutOtherContentWritesNothingForThisCondition(): void
    {
        $this->house->granted(['conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'lab:scan', 'arguments' => ['strict' => true], 'free' => ['path']]]]]);
        $good = $this->house->candidate('good.txt', "clean release\n");
        $other = $this->house->candidate('other.txt', "machine-name: build-host-7\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good, 'strict' => true]);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $other]);

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $good, 'channel' => 'stable']), 'a no about other content stopped this one');
    }

    // ── decided by Rod, 2026-10-12 (D8): a fixed value is shown as it is spelled ──────────────────────────────

    public function testTheListingShowsAFixedValueAsItIsSpelledSoThatTrueAndTheWordTrueAreTwo(): void
    {
        $this->house->granted(['conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'lab:scan', 'arguments' => ['strict' => true, 'mode' => 'true', 'level' => 7, 'tag' => '7'], 'free' => ['path']]]]]);

        $listed = $this->house->as(null, 'standing:granted')['grants'][0];

        self::assertStringEndsWith('measured by lab:scan (level=7, mode="true", strict=true, tag="7")', $listed['conditions'][0]);
        self::assertSame('lab:publish (channel="stable")', $listed['covers'][0]);
    }

    public function testAnAnswerThatIsNotAnAnswerFromSuchARunLeavesNoReceipt(): void
    {
        // «No» is the run saying `ok` is not true — in an answer the house can read. A run that returns nothing it
        // can read, or does not say which bytes it read, measured nothing either way.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->scannerSaysWhatItRead = false;
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path, 'deep' => true]);

        self::assertSame([], $this->house->lines(StandingLedger::MEASURED));
    }

    private function measured(string $name = 'good.txt', string $bytes = "clean release\n"): string
    {
        $path = $this->house->candidate($name, $bytes);
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        return $path;
    }
}
