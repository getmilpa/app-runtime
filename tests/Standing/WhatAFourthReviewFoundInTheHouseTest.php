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

use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\AppRuntime\Tests\Standing\Plugins\Shelf\LabShelf;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Command\Standing\GrantState;
use Milpa\Console\McpProjector;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a fourth independent review found in the house, each through the door its own probe used (greenhouse
 * decisions/0615, «Lo que la cuarta revisión encontró en el anfitrión»). It read the head the third review's fixes
 * left and asked the same question of every one: which sibling did it leave?
 *
 * Every test here was red on the head it read, unless it says it is a guard of what the fix must NOT do.
 *
 * @subject-in milpa/app-runtime
 */
final class WhatAFourthReviewFoundInTheHouseTest extends TestCase
{
    private LabHouse $house;

    private ?LabLeg $leg = null;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
    }

    protected function tearDown(): void
    {
        $this->leg?->destroy();
        $this->house->destroy();
    }

    // ── H1 · a stop and a no are heard wherever the leg runs what says them ───────────────────────────────────

    /** @return iterable<string, array{string, string}> the watcher, and the mark of where the leg ran it */
    public static function whereALegRunsAWatcher(): iterable
    {
        yield 'in its own process' => ['shelf:glance', 'fired'];
        yield 'as work confined in the house' => ['shelf:patrol', 'ran_in_house'];
        yield 'rehearsed in a copy' => ['shelf:survey', 'ran_in_trial'];
    }

    #[DataProvider('whereALegRunsAWatcher')]
    public function testAWatcherThatSaysFiredStopsTheGrantWhereverALegRunsIt(string $watcher, string $ranAs): void
    {
        $leg = $this->shelfLine($watcher);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => 'good.txt']), 'control: watched and measured, the covered act runs');
        touch($this->house->root . '/shelf-fired');

        $said = $leg->on->call(McpProjector::toolName($watcher), [], $leg->seat());

        self::assertTrue($said->success, (string) $said->error);
        self::assertArrayHasKey($ranAs, (array) $said->data, 'control: this is where a leg runs this watcher');
        self::assertTrue(self::answered($said)['fired'] ?? null, 'control: the seat was handed «fired»');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state, 'the seat was told the falsifier fired and the house heard nothing');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => 'good.txt']));
        self::assertCount(1, $this->house->done('merged'));
    }

    /** @return iterable<string, array{string, string}> the instrument, and the mark of where the leg ran it */
    public static function whereALegRunsAnInstrument(): iterable
    {
        yield 'in its own process' => ['shelf:check', 'acted_on'];
        yield 'as work confined in the house' => ['shelf:weigh', 'ran_in_house'];
        yield 'rehearsed in a copy' => ['shelf:audit', 'ran_in_trial'];
    }

    #[DataProvider('whereALegRunsAnInstrument')]
    public function testAnInstrumentThatSaysNoIsHeardWhereverALegRunsIt(string $instrument, string $ranAs): void
    {
        $leg = $this->shelfLine('shelf:glance', $instrument);
        // A stricter run of the same instrument — an argument no condition describes — says no of the candidate that
        // has a green receipt.
        $said = $leg->on->call(McpProjector::toolName($instrument), ['path' => 'good.txt', 'strict' => true], $leg->seat());

        self::assertFalse(self::answered($said)['ok'] ?? null, 'control: the seat was handed a no');
        self::assertStringContainsString($ranAs, (string) json_encode([$said->data, $said->error, $said->meta]), 'control: this is where a leg runs this instrument');
        $receipts = $this->house->lines(StandingLedger::MEASURED);
        self::assertSame([true, false], array_column($receipts, 'ok'), 'the instrument said no of that very content and the house wrote nothing');
        self::assertStringContainsString('does not hold on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => 'good.txt'])['refused'] ?? 'RAN'));
        self::assertSame([], $this->house->done('merged'));
    }

    /** @return iterable<string, array{string}> */
    public static function whatLeavesTheLegsProcess(): iterable
    {
        yield 'work confined in the house' => ['shelf:weigh'];
        yield 'a rehearsal in a copy' => ['shelf:audit'];
    }

    /** A GUARD of the fix, not a finding: a YES said out of the leg's process is never a receipt. */
    #[DataProvider('whatLeavesTheLegsProcess')]
    public function testAYesFromARunThatLeftTheLegsProcessIsNoReceipt(string $instrument): void
    {
        $leg = $this->shelfLine('shelf:glance', $instrument);
        $this->house->candidate('other.txt', "another clean release\n");

        $said = $leg->on->call(McpProjector::toolName($instrument), ['path' => 'other.txt'], $leg->seat());

        self::assertTrue(self::answered($said)['ok'] ?? null, 'control: the run the condition describes said yes');
        self::assertCount(1, $this->house->lines(StandingLedger::MEASURED), 'a yes from a process the house did not run in is written as a receipt');
        self::assertStringContainsString('was never measured on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => 'other.txt'])['refused'] ?? 'RAN'));
    }

    /** @return iterable<string, array{string}> */
    public static function watchersThatLeaveTheLegsProcess(): iterable
    {
        yield 'work confined in the house' => ['shelf:patrol'];
        yield 'a rehearsal in a copy' => ['shelf:survey'];
    }

    /** A GUARD of the fix, not a finding: a sign of calm from out of the leg's process keeps no grant alive. */
    #[DataProvider('watchersThatLeaveTheLegsProcess')]
    public function testAQuietSignFromARunThatLeftTheLegsProcessKeepsNoGrantAlive(string $watcher): void
    {
        $leg = $this->shelfLine($watcher, watched: false);

        $said = $leg->on->call(McpProjector::toolName($watcher), [], $leg->seat());

        self::assertFalse(self::answered($said)['fired'] ?? null, 'control: the watcher ran and found nothing');
        self::assertSame([], $this->house->lines(StandingLedger::WATCHED), 'a sign of calm from a process the house did not run in is written down');
        self::assertStringContainsString('has given no sign of life', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => 'good.txt'])['refused'] ?? 'RAN'));
    }

    public function testTheDoorThatGivesAGrantSaysWhenALegRunsItsWatcherOrItsInstrumentElsewhere(): void
    {
        $plugin = new LabShelf($this->house->root);
        $this->house->offer(...$plugin->operations());
        $given = fn (string $id, string $watcher, string $instrument): array => $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(self::watchedAndMeasuredBy($id, $watcher, $instrument))]);

        $here = $given('here', 'shelf:glance', 'shelf:check');
        $elsewhere = $given('elsewhere', 'shelf:patrol', 'shelf:audit');

        self::assertTrue(($here['ok'] ?? false) && ($elsewhere['ok'] ?? false), (string) json_encode([$here, $elsewhere]));
        $said = implode(' · ', $elsewhere['warnings'] ?? []);
        self::assertStringContainsString('`shelf:patrol`', $said, 'the door gave a grant whose watcher a leg runs out of its process, and said nothing of it');
        self::assertStringContainsString('`shelf:audit`', $said);
        self::assertStringContainsString('outside its own process', $said);
        self::assertStringNotContainsString('outside its own process', implode(' · ', $here['warnings'] ?? []), 'control: nothing is said of what a leg runs in its own process');
    }

    // ── the sibling the sweep of every door found: a leg whose gate holds the house's policy inside another ────

    /** @return iterable<string, array{string, string}> the covered act, and the mark of where the leg ran it */
    public static function coveredActsThatLeaveTheLegsProcess(): iterable
    {
        yield 'work confined in the house' => ['shelf:lend', 'ran_in_house'];
        yield 'a rehearsal in a copy' => ['shelf:label', 'ran_in_trial'];
    }

    #[DataProvider('coveredActsThatLeaveTheLegsProcess')]
    public function testACoveredActCrossesTheJudgeOnALegWhoseCatalogueHoldsAnOperationTypedByAPermission(string $act, string $ranAs): void
    {
        // The projector wraps the gate's policy the moment one operation typed by `permission` is told to a
        // registry, and a leg asked «is the gate's policy the house's?» of the wrapper. The seat holds the word on
        // its own here: a grant governs the seat it names whatever that seat holds.
        $leg = $this->coveredShelf(self::permissioned());
        self::assertNotInstanceOf(\Milpa\AppRuntime\Agent\PluginAuthoringPolicy::class, $leg->off->getPolicyGate()->getCallPolicy(), 'control: on this leg the gate holds the house\'s policy inside another');

        $said = $leg->on->call(McpProjector::toolName($act), ['path' => 'good.txt'], $leg->seat());

        self::assertTrue($said->success, (string) $said->error);
        self::assertArrayHasKey($ranAs, (array) $said->data, 'control: this is where a leg runs this act');
        $absorbed = $this->house->lines(StandingLedger::ABSORBED);
        self::assertCount(1, $absorbed, 'an act a grant covers ran on a leg with nothing written before it');
        self::assertSame('shelf-line', $absorbed[0]['grant']);
    }

    #[DataProvider('coveredActsThatLeaveTheLegsProcess')]
    public function testAnActThatDoesNotSayWhatItConsumedSuspendsTheGrantOnThatLegToo(string $act, string $ranAs): void
    {
        $leg = $this->coveredShelf(self::permissioned());

        $said = $leg->on->call(McpProjector::toolName($act), ['path' => 'good.txt', 'quiet' => true], $leg->seat());

        self::assertArrayHasKey($ranAs, (array) $said->data, 'control: this is where a leg runs this act');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state, 'the house did not look at the act again');
    }

    public function testAGrantStillLendsOnALegWhoseCatalogueHoldsAnOperationTypedByAPermission(): void
    {
        // The other face of the same question: asked of the wrapper, a leg lent nothing — a seat that holds no word
        // of its own was refused for the word, under a grant in force with everything measured.
        $plugin = new LabShelf($this->house->root);
        $this->house->offer(...$plugin->operations());
        $this->house->granted(['id' => 'shelf-line', 'covers' => [['operation' => 'shelf:lend']]]);
        $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => 'good.txt']);
        $leg = $this->leg = $this->legOver([...$plugin->operations(), self::permissioned()], $plugin);

        $said = $leg->on->call('shelf_lend', ['path' => 'good.txt'], $leg->seat());

        self::assertTrue($said->success, 'a grant in force lent nothing on this leg: ' . $said->error);
        self::assertCount(1, $this->house->lines(StandingLedger::ABSORBED));
    }

    public function testControlWithNoOperationTypedByAPermissionTheSameLegCrossesTheJudge(): void
    {
        $leg = $this->coveredShelf();
        self::assertInstanceOf(\Milpa\AppRuntime\Agent\PluginAuthoringPolicy::class, $leg->off->getPolicyGate()->getCallPolicy());

        self::assertTrue($leg->on->call('shelf_lend', ['path' => 'good.txt'], $leg->seat())->success);

        self::assertCount(1, $this->house->lines(StandingLedger::ABSORBED));
    }

    public function testAWatcherThatSaysFiredIsHeardOnThatLegToo(): void
    {
        $leg = $this->shelfLine('shelf:patrol', also: [self::permissioned()]);
        touch($this->house->root . '/shelf-fired');

        $said = $leg->on->call('shelf_patrol', [], $leg->seat());

        self::assertTrue(self::answered($said)['fired'] ?? null, 'control: the seat was handed «fired»');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state);
    }

    // ── D14, its edge: a no is never lost, whatever content the run that said it had read ────────────────────

    public function testANoFromARunThatReadOtherContentThanTheHouseHoldsStillStopsTheHousesCandidate(): void
    {
        // A rehearsal measures a COPY, and a copy can hold other bytes than the house: the run says no, and says it
        // read something else. A no only narrows — it is kept for the content the house itself reads for that call,
        // AND for the content the run says it read.
        $this->shelfLine('shelf:glance', 'shelf:audit');
        $audit = $this->house->operation('shelf:audit');
        $elsewhere = 'sha256:' . hash('sha256', "what the copy held\n");
        $seat = $this->house->contextOf(LabHouse::SEAT);

        $this->house->policy->crossing($seat, $audit, ['path' => 'good.txt'], static fn (): array => ['ok' => false, 'acted_on' => $elsewhere], static fn (array $answer): array => $answer);

        $red = array_values(array_filter($this->house->lines(StandingLedger::MEASURED), static fn (array $receipt): bool => $receipt['ok'] === false));
        self::assertEqualsCanonicalizing([$this->house->digestOf('good.txt'), $elsewhere], array_column($red, 'subject'), 'a no said of other content than the house holds was lost');
        self::assertStringContainsString('does not hold on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => 'good.txt'])['refused'] ?? 'RAN'));
        self::assertSame([], $this->house->done('merged'));
        // …AND IT REACHES THE OTHER ONE (asserted since the fifth review: this test said «both receipts» and the act
        // over the second candidate had never been made). The content the copy held, measured green in the house
        // before: the no the run said of it stops an act over it too.
        $other = new LabHouse();
        try {
            $other->granted();
            $a = $other->candidate('good.txt', "clean release\n");
            $b = $other->candidate('other.txt', "what the copy held\n");
            $other->as(LabHouse::SEAT, 'lab:scan', ['path' => $a]);
            $other->as(LabHouse::SEAT, 'lab:scan', ['path' => $b]);
            self::assertArrayNotHasKey('refused', $other->as(LabHouse::SEAT, 'lab:merge', ['path' => $b]), 'control: measured green in the house, the act over the second candidate passes');
            $other->policy->crossing($other->contextOf(LabHouse::SEAT), $other->operation('lab:scan'), ['path' => $a], static fn (): array => ['ok' => false, 'acted_on' => $other->digestOf('other.txt')], static fn (array $answer): array => $answer);
            self::assertStringContainsString('does not hold on this candidate', (string) ($other->as(LabHouse::SEAT, 'lab:merge', ['path' => $b])['refused'] ?? 'RAN'), 'a no said of the content a copy held did not reach the candidate that holds it');
            self::assertStringContainsString('does not hold on this candidate', (string) ($other->as(LabHouse::SEAT, 'lab:merge', ['path' => $a])['refused'] ?? 'RAN'));
        } finally {
            $other->destroy();
        }
    }

    public function testANoThatDoesNotSayWhatItReadStillStopsTheCandidateTheHouseHolds(): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $this->house->scannerSaysWhatItRead = false;

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        self::assertSame([true, false], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'), 'the instrument said no without saying which bytes it read, and the no was lost');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
    }

    public function testANoFromAnInstrumentTheHouseCannotPinTodayIsKeptAllTheSame(): void
    {
        // Stopping asks for nobody's pin: a receipt of an instrument that is not the grant's refuses the act too.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->installed(null);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        $kept = $this->house->lines(StandingLedger::MEASURED);
        self::assertSame([true, false], array_column($kept, 'ok'), 'a no was dropped because the house could not say which code said it');
        self::assertSame(\Milpa\AppRuntime\Standing\HouseStandingJudge::NOT_PINNED, $kept[1]['instrument']);
        $this->house->installed();
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
        self::assertNull($this->house->ledger()->fault());
    }

    public function testAYesThatDoesNotSayWhatItReadIsStillNoReceipt(): void
    {
        // The guard of that edge: what turns a candidate green is asked for everything, as it always was.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->scannerSaysWhatItRead = false;

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]));
        $this->house->policy->crossing($this->house->contextOf(LabHouse::SEAT), $this->house->operation('lab:scan'), ['path' => $path], fn (): array => ['ok' => true, 'acted_on' => $this->house->digestOf('good.txt')], static fn (array $answer): array => $answer);

        self::assertSame([], $this->house->lines(StandingLedger::MEASURED));
    }

    public function testANoThatNamesNoContentWhereTheHouseReadsNoneLeavesNoReceipt(): void
    {
        // What is said, and stays so: a receipt is about a content, and here there is none to stop.
        $this->house->granted();
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $this->house->scannerSaysWhatItRead = false;

        $this->house->policy->crossing($this->house->contextOf(LabHouse::SEAT), $this->house->operation('lab:scan'), ['path' => 'not-in-the-house.txt'], static fn (): array => ['ok' => false], static fn (array $answer): array => $answer);

        self::assertSame([], $this->house->lines(StandingLedger::MEASURED));
        self::assertNull($this->house->ledger()->fault());
    }

    // ── every other door a call reaches a handler by, asked the same (the sweep made before H1 was fixed) ─────

    /** @return iterable<string, array{string, ?ToolContext}> the surface, and the caller it hands the runner */
    public static function surfacesThatHandARunToTheRunner(): iterable
    {
        yield 'a terminal, a seat signing' => ['cli', new ToolContext(principal: 'key:' . LabHouse::SEAT, channel: 'cli', scopes: [...LabHouse::SEAT_SCOPES, 'shelf:write'])];
        yield 'MCP over stdio' => ['mcp', ToolContext::stdio('1', 'key:' . LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'shelf:write'])];
        yield 'HTTP, nobody recognized' => ['http', new ToolContext(principal: null, channel: 'web', scopes: [])];
        yield 'the shell of the terminal, which hands no caller' => ['tui', null];
    }

    #[DataProvider('surfacesThatHandARunToTheRunner')]
    public function testWhateverSurfaceHandsARunToTheRunnerTheHouseHearsAFiredAndANo(string $surface, ?ToolContext $caller): void
    {
        // Even of an operation a leg would run elsewhere: a surface that hands the run to the runner runs it here.
        $this->shelfLine('shelf:patrol', 'shelf:weigh');
        $runner = new \Milpa\Console\OperationRunner($this->house->container);
        touch($this->house->root . '/shelf-fired');

        $no = $runner->run($this->house->operation('shelf:weigh'), ['path' => 'good.txt', 'strict' => true], $surface, null, $caller);
        self::assertFalse($no['ok']);
        self::assertSame([true, false], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'), 'a no said through this surface was not written down');
        $fired = $runner->run($this->house->operation('shelf:patrol'), [], $surface, null, $caller);
        self::assertTrue($fired['fired']);
        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state, 'a «fired» said through this surface did not stop the grant');
    }

    public function testTheShellOfTheTerminalWrapsAnOperationAndWhatItSaysStillNarrows(): void
    {
        // The shell runs a COPY of each operation with a door in front of its handler, and hands the runner that copy:
        // it is pinned as another operation, so its yes and its sign of calm speak for no grant — and what narrows
        // is heard all the same.
        $this->shelfLine('shelf:glance', 'shelf:check');
        $runner = new \Milpa\Console\OperationRunner($this->house->container);
        $behindADoor = fn (string $name): Operation => \Milpa\AppRuntime\Console\UnsignedTerminal::withHandler($this->house->operation($name), fn (array $input, mixed ...$rest): mixed => ($this->house->operation($name)->handler)($input, ...$rest));
        $this->house->candidate('other.txt', "another clean release\n");

        $runner->run($behindADoor('shelf:check'), ['path' => 'other.txt'], 'tui');
        self::assertCount(1, $this->house->lines(StandingLedger::MEASURED), 'a yes said by the copy the shell runs was written as a receipt');
        $runner->run($behindADoor('shelf:check'), ['path' => 'good.txt', 'strict' => true], 'tui');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => 'good.txt']), 'a no said at the shell did not stop the act');
        touch($this->house->root . '/shelf-fired');
        $runner->run($behindADoor('shelf:glance'), [], 'tui');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state);
    }

    public function testWithTrialsOffAndOnASubAgentWhatALegWouldRunElsewhereRunsHereAndIsHeard(): void
    {
        // The registry of a leg with trials off, and of a sub-agent — which has no trial layer at all.
        $leg = $this->shelfLine('shelf:patrol', 'shelf:weigh');
        touch($this->house->root . '/shelf-fired');

        $no = $leg->off->call('shelf_weigh', ['path' => 'good.txt', 'strict' => true], $leg->seat());
        self::assertSame([true, false], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'), (string) $no->error);
        $leg->off->call('shelf_patrol', [], $leg->seat());
        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state);
    }

    public function testTheMcpServiceItselfHandsAFiredToTheHouse(): void
    {
        $this->shelfLine('shelf:patrol');
        $this->house->container->registerService(\Milpa\ToolRuntime\Contracts\CallPolicy::class, $this->house->policy);
        $registry = new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger());
        (new McpProjector())->projectAll([$this->house->operation('shelf:patrol')], $registry, $this->house->container);
        touch($this->house->root . '/shelf-fired');

        $answer = (new \Milpa\McpServer\JsonRpcService($registry))->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'shelf_patrol', 'arguments' => []]], ToolContext::stdio('1', 'key:' . LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'shelf:write']));

        self::assertStringContainsString('fired', (string) json_encode($answer), 'control: the client was handed the answer');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state);
    }

    /** @return iterable<string, array{string, string}> */
    public static function whatSpeaksForAGrant(): iterable
    {
        yield 'the instrument of a condition' => ['conditions', 'condition «no-host-names» is measured by `test`'];
        yield 'its watcher' => ['falsifier', 'falsifier «a-published-name» is watched by `test`'];
    }

    #[DataProvider('whatSpeaksForAGrant')]
    public function testAGrantIsNotMeasuredOrWatchedByAnAuthoringCall(string $as, string $said): void
    {
        // A call named as the authoring tools are runs in a trial inside the boundary, and its answer comes back
        // wrapped in the trial's own: a «fired» in it is not where the house reads one.
        $this->house->offer(new Operation('test', 'Lab: stands in for the test runner', static fn (array $input): array => ['ok' => true, 'fired' => false], ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], scopes: ['lab:watch'], effects: EffectProfile::readOnly(), actsOn: static fn (array $input): ?string => 'sha256:' . hash('sha256', 'x')));
        $change = $as === 'conditions'
            ? ['conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'test', 'free' => ['path']]]]]
            : ['falsifier' => ['name' => 'a-published-name', 'statement' => 'a published release names a machine', 'watcher' => ['operation' => 'test'], 'within_seconds' => 3600]];

        $given = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document($change)]);

        self::assertFalse($given['ok'] ?? null, (string) json_encode($given));
        self::assertStringContainsString($said . ', an authoring call', (string) ($given['error'] ?? ''));
        self::assertFalse($this->house->ledger()->exists());
    }

    // ── M1 · what counts is what the process that answered had LOADED, not what the disk holds when it writes ──

    public function testAYesFromCodeThisProcessLoadedBeforeTheInstrumentWasPutBackIsNoReceipt(): void
    {
        // Scenario B of the review: the instrument's file is softened, a process loads it, and the file is put back.
        // What the process runs is the soft code; what the disk — and the grant — hold is the strict one.
        $guard = $this->loaded('true');
        $this->house->offer($guard['scan']);
        $this->aProcessThatLivesBoots();
        $this->onDisk($guard, "!str_contains(\$bytes, 'machine-name:')");
        $this->house->granted(['conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'guard:scan', 'free' => ['path']]]]]);
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        self::assertSame(\Milpa\AppRuntime\Standing\OperationPin::of($guard['scan'], $this->house->root), $this->house->book()->record('release-line')?->instruments['guard.scan'] ?? null, 'control: the grant is given over what the disk holds, and the disk holds that now');

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'guard:scan', ['path' => $bad]), 'control: the code this process loaded does not see');
        $published = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']);

        self::assertArrayHasKey('refused', $published, 'a red candidate was published on a yes from code the grant was never given over');
        self::assertSame([], $this->house->done('published'));
        self::assertSame([], $this->house->lines(StandingLedger::MEASURED), 'the yes was written as a receipt under the grant\'s own pin');
    }

    public function testAGrantGivenOverAHardenedInstrumentIsNotServedByAProcessThatLoadedTheOneBeforeIt(): void
    {
        // Scenario A, with no adversary: a process is alive, a person hardens the instrument and grants again over it.
        $guard = $this->loaded('true');
        $this->house->offer($guard['scan']);
        $measured = ['conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'guard:scan', 'free' => ['path']]]]];
        $this->house->granted($measured);
        $this->aProcessThatLivesBoots();
        $this->onDisk($guard, "!str_contains(\$bytes, 'machine-name:')");
        $this->house->granted($measured + ['id' => 'release-line-2', 'supersedes' => 'release-line']);
        // …and a process that is told its catalogue AGAIN while it lives — a server does, at every request — has
        // not loaded anything again: what it loaded is what it read the first time.
        $this->house->offer($guard['scan']);
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'guard:scan', ['path' => $bad]), 'control: the process that outlived the update still runs the code that does not see');
        $published = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']);

        self::assertArrayHasKey('refused', $published, 'the new grant was served by the instrument it replaced');
        self::assertSame([], $this->house->done('published'));
    }

    public function testASignOfCalmFromCodeThisProcessLoadedBeforeTheWatcherWasPutBackKeepsNoGrantAlive(): void
    {
        $guard = $this->loaded('true', sees: 'false');
        $this->house->offer($guard['watch']);
        $this->aProcessThatLivesBoots();
        $this->onDisk($guard, 'true', sees: "is_file(\$this->root . '/guard-fired')");
        $this->house->granted(['falsifier' => ['name' => 'a-published-name', 'statement' => 'a published release names a machine', 'watcher' => ['operation' => 'guard:watch'], 'within_seconds' => 3600]], watched: false);
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        touch($this->house->root . '/guard-fired');

        self::assertSame(['ok' => true, 'fired' => false], $this->house->as(LabHouse::SEAT, 'guard:watch'), 'control: the code this process loaded cannot see the falsifier');
        $published = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);

        self::assertStringContainsString('has given no sign of life', (string) ($published['refused'] ?? 'RAN'), 'a watcher that cannot see kept the grant alive under the pin of the one that can');
    }

    public function testAnActIsNotRunUnderAGrantByAProcessThatLoadedAnotherVersionOfIt(): void
    {
        $guard = $this->loaded('true', does: "'the code before the update'");
        $this->house->offer($guard['ship']);
        $this->aProcessThatLivesBoots();
        $this->onDisk($guard, 'true', does: "'the code the grant was given over'");
        $this->house->granted(['covers' => [['operation' => 'guard:ship']]]);
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        $shipped = $this->house->as(LabHouse::SEAT, 'guard:ship', ['path' => $path]);

        self::assertArrayHasKey('refused', $shipped, 'a covered act ran, under the grant, as code the grant was never given over: ' . json_encode($shipped));
        self::assertStringContainsString('this process loaded', (string) $shipped['refused']);
        self::assertSame([], $this->house->lines(StandingLedger::ABSORBED), 'and the house had written that it let it pass');
    }

    public function testControlAProcessThatLoadedWhatTheGrantWasGivenOverMeasuresAndActs(): void
    {
        $guard = $this->loaded("!str_contains(\$bytes, 'machine-name:')", does: "'the code the grant was given over'");
        $this->house->offer($guard['scan'], $guard['ship']);
        $this->house->granted(['covers' => [['operation' => 'guard:ship']], 'conditions' => [['name' => 'no-host-names', 'statement' => 'what is shipped names no machine', 'by' => ['operation' => 'guard:scan', 'free' => ['path']]]]]);
        $path = $this->house->candidate('good.txt', "clean release\n");

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'guard:scan', ['path' => $path]));
        self::assertSame(['ok' => true, 'ran' => 'the code the grant was given over'], $this->house->as(LabHouse::SEAT, 'guard:ship', ['path' => $path]));
    }

    public function testAProcessThatStartedBeforeItsHouseKeptABookDoesNotSpeakForAGrantGivenSince(): void
    {
        // It has no record of what it loaded: in a house where nobody granted anything, nothing is read.
        $this->house->aProcessPerCall = false;
        $this->house->granted(watched: false);
        $path = $this->house->candidate('good.txt', "clean release\n");
        // Told its catalogue again after the house got its book, it still has not read what it loaded WHEN it loaded it.
        $this->house->offer($this->house->operation('lab:watch'), $this->house->operation('lab:scan'), $this->house->operation('lab:merge'));

        self::assertSame(['ok' => true, 'fired' => false], $this->house->as(LabHouse::SEAT, 'lab:watch'));
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]));
        self::assertSame([], [...$this->house->lines(StandingLedger::WATCHED), ...$this->house->lines(StandingLedger::MEASURED)], 'a process that cannot say what it loaded spoke for a grant');
        $this->house->writtenByHand(StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => false, 'who' => 'key:' . LabHouse::SEAT, 'pin' => $this->house->book()->record('release-line')?->instruments['lab.watch']]);
        $this->house->writtenByHand(StandingLedger::MEASURED, ['by' => 'lab:scan', 'arguments' => ['path' => $path], 'subject' => $this->house->digestOf($path), 'ok' => true, 'instrument' => $this->house->book()->record('release-line')?->instruments['lab.scan'], 'who' => 'key:' . LabHouse::SEAT, 'at' => $this->house->now->format(\DATE_ATOM)]);
        self::assertStringContainsString('before this house kept a book', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'), 'and it ran an act under one');

        $this->house->boots();
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: started again, it runs what the grant was given over');
    }

    public function testAFiredAndANoAreHeardFromAProcessThatCannotSayWhatItLoaded(): void
    {
        $this->house->aProcessPerCall = false;
        $this->house->granted(watched: false);
        $path = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        $this->house->fired = true;

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertSame([false], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'), 'a no only narrows: it asks for nobody\'s pin');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
    }

    // ── M2 · a reinstatement does not come back with whoever signed it ───────────────────────────────────────

    public function testAReinstatementDoesNotComeBackWhenItsSignerLeavesTheHouseAndIsRecognizedAgain(): void
    {
        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $merge = fn (): array => $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]);
        $suspension = (string) $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen'])['suspension'];
        $lifted = $this->house->signed(LabHouse::STRANGER, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $suspension]);
        self::assertTrue($lifted['ok'] ?? false, (string) json_encode($lifted));
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame(['ok' => true], $merge(), 'control: a second person of the line lifted the suspension, by name');
        $ledger = new \Milpa\AppRuntime\Identity\FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json');

        $ledger->revoke(LabHouse::STRANGER, 'key:' . LabHouse::PERSON);
        self::assertArrayHasKey('refused', $merge(), 'control: while that person is out of the house, what they lifted is stopped again');
        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll'], LabHouse::PERSON);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertArrayHasKey('refused', $merge(), 'recognizing a key names no grant, and the grant is in force again');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        self::assertCount(1, $this->house->done('merged'));
    }

    public function testAReinstatementWhoseLineDoesNotSayWhichRecognitionOfItsSignerItWasSignedOverLiftsNothing(): void
    {
        $this->house->granted();
        $suspension = (string) $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen'])['suspension'];
        $signed = $this->house->signature(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $suspension]);

        $this->house->writtenByHand(StandingLedger::REINSTATED, ['grant' => 'release-line', 'suspension' => $suspension, 'payload' => $signed->payload, 'signature' => $signed->signature, 'fingerprint' => LabHouse::PERSON]);

        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state, 'a genuinely signed line that keeps no recognition lifted the suspension');
    }

    // ── M3 · stopping a grant is bounded ─────────────────────────────────────────────────────────────────────

    public function testAStopIsNeverRefusedForItsReasonAndTheHouseKeepsALengthOfIt(): void
    {
        $this->house->granted();
        $long = str_repeat('seen it ', 15000);

        $stopped = $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => $long]);

        self::assertTrue($stopped['ok'] ?? false, (string) json_encode($stopped));
        $kept = (string) $this->house->lines(StandingLedger::SUSPENDED)[0]['reason'];
        self::assertLessThanOrEqual(256, mb_strlen($kept), 'the book keeps a reason of any length, and every call of every seat reads the whole book');
        self::assertStringStartsWith('seen it seen it', $kept);
        self::assertStringContainsString('256', (string) json_encode($stopped), 'the answer does not say that only a length of the reason was kept');
    }

    /** @return iterable<string, array{string, int}> the reason, and how many bytes of it the book is to keep */
    public static function longReasonsThatAreNotAscii(): iterable
    {
        yield 'a letter two bytes long' => [str_repeat('ñ', 300), 512];
        yield 'a letter three bytes long' => [str_repeat('字', 300), 768];
        yield 'a cut that falls between a letter and the mark over it' => ['a' . str_repeat("e\u{0301}", 200), 1 + 127 * 3 + 1];
    }

    #[DataProvider('longReasonsThatAreNotAscii')]
    public function testWhatTheBookKeepsOfAReasonIsCountedInCharactersAndSaysWhatItWasCutFrom(string $reason, int $bytes): void
    {
        // D15, its edge: «256 characters» are code points of the text, never bytes — and a line that keeps only a
        // length of a reason says, by its digest, which reason that was.
        $this->house->granted();

        $stopped = $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => $reason]);

        $line = $this->house->lines(StandingLedger::SUSPENDED)[0];
        self::assertSame(256, mb_strlen((string) $line['reason']), 'the reason is not counted in characters');
        self::assertSame($bytes, \strlen((string) $line['reason']));
        self::assertTrue(mb_check_encoding((string) $line['reason'], 'UTF-8') && \Milpa\Command\Standing\Canonical::oneLine((string) $line['reason']), 'what was kept is not text a person reads');
        self::assertSame(['characters' => mb_strlen($reason), 'sha256' => 'sha256:' . hash('sha256', $reason)], $line['reason_given'] ?? null, 'the line does not say which reason it keeps a length of');
        self::assertNull($this->house->ledger()->fault(), 'the book does not verify');
        // …and the line reads and verifies as any other: a person lifts that very stop by name.
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $stopped['suspension']])['ok'] ?? false);
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state);
    }

    public function testAReasonKeptAsItCameSaysNothingMore(): void
    {
        $this->house->granted();

        $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => str_repeat('ñ', 256)]);

        self::assertSame(['by', 'grant', 'reason'], array_keys($this->house->lines(StandingLedger::SUSPENDED)[0]), 'a reason that fits is the whole of what the line says of it');
    }

    /** @return iterable<string, array{mixed}> */
    public static function reasonsThatUsedToRefuseAStop(): iterable
    {
        yield 'no reason at all' => [null];
        yield 'an empty one' => ['   '];
        yield 'bytes a terminal obeys' => ["in force\x1b[2K\rOK: nothing was stopped\x1b[8m"];
        yield 'two lines' => ["seen\nand seen again"];
        yield 'not text' => [['seen' => true]];
    }

    #[DataProvider('reasonsThatUsedToRefuseAStop')]
    public function testAStopLandsWhateverItsReasonIs(mixed $reason): void
    {
        $this->house->granted();

        $stopped = $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line'] + ($reason === null ? [] : ['reason' => $reason]));

        self::assertTrue($stopped['ok'] ?? false, 'a stop was refused for its reason: ' . json_encode($stopped));
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        $because = (string) $this->house->book()->record('release-line')?->because;
        self::assertTrue(\Milpa\Command\Standing\Canonical::oneLine($because), 'what the house says of the stop is not one line of plain text');
        self::assertStringNotContainsString("\x1b", (string) json_encode($this->house->as(null, 'standing:granted'), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    public function testTheDoorOfAStopDoesNotAskForAReasonBeforeTheHouseIsAsked(): void
    {
        // Found on cattle, at the real terminal: the operation declared `reason` as required, so a stop with none
        // was sent back by the surface's own check of the input — before the house had a say.
        $schema = (array) $this->house->operation('standing:suspend')->inputSchema;

        self::assertSame(['grant'], $schema['required'] ?? null, 'a surface refuses a stop that carries no reason');
        self::assertArrayHasKey('reason', (array) ($schema['properties'] ?? []), 'control: a reason is still what a person reads, and is taken');
    }

    public function testASecondStopByTheSameKeyOnAGrantItAlreadyStoppedWritesNoLine(): void
    {
        $this->house->granted();
        $first = $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen']);
        $lines = \count($this->house->ledger()->entries());

        $again = $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen again']);

        self::assertTrue($again['ok'] ?? false, (string) json_encode($again));
        self::assertSame($lines, \count($this->house->ledger()->entries()), 'a key stopped again what it had already stopped, and the book grew');
        self::assertSame($first['suspension'], $again['suspension'], 'the answer names the stop that stands');
        // Another key's stop is another fact; and after a person lifts them, the first key stops it again.
        self::assertTrue($this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen by another'])['ok'] ?? false);
        self::assertSame($lines + 1, \count($this->house->ledger()->entries()), 'control: a stop by another key is written');
        $standing = (string) $this->house->book()->record('release-line')?->suspension;
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $standing])['ok'] ?? false);
        // After the lift nobody has stopped it: whoever stops it next is written, and so is the first key after them.
        self::assertTrue($this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen by another, after the lift'])['ok'] ?? false);
        self::assertTrue($this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen once more'])['ok'] ?? false);
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        self::assertSame($lines + 4, \count($this->house->ledger()->entries()), 'a key that had stopped the grant before a person lifted it was still counted as having stopped it');
    }

    // ── M4 · a house with no grant behaves as the published runtime, with two operations under one identity too ─

    public function testAHouseThatKeepsNoBookRunsWhenTwoOperationsAnswerToOneIdentity(): void
    {
        $twin = new Operation('lab_watch', 'Lab: another operation under the identity of lab:watch', static fn (array $input): array => ['ok' => true, 'twin' => true], scopes: ['lab:watch'], effects: EffectProfile::readOnly());

        $this->house->offerBeside($twin);

        self::assertFalse($this->house->ledger()->exists(), 'control: nobody granted anything here');
        self::assertSame(['ok' => true, 'fired' => false], $this->house->as(LabHouse::SEAT, 'lab:watch'), 'a house with no grant refused a command for the catalogue it was told');
    }

    /** @return iterable<string, array{string, array<string, mixed>}> the identity that gains a twin, and the grant that names it */
    public static function whatAGrantNames(): iterable
    {
        yield 'the operation it covers' => ['lab_merge', []];
        yield 'the instrument of a condition' => ['lab_scan', []];
        yield 'its watcher' => ['lab_watch', []];
    }

    #[DataProvider('whatAGrantNames')]
    public function testAGrantIsNotGivenOverAnIdentityTwoOperationsAnswerTo(string $twin): void
    {
        $this->house->offerBeside(new Operation($twin, 'Lab: another operation under an identity already taken', static fn (array $input): array => ['ok' => true], scopes: ['lab:watch'], effects: EffectProfile::readOnly()));

        $given = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document()]);

        self::assertFalse($given['ok'] ?? null, 'a grant was given over a name that two operations answer to: ' . json_encode($given));
        self::assertStringContainsString('two operations answer to', (string) ($given['error'] ?? ''));
        self::assertFalse($this->house->ledger()->exists());
    }

    #[DataProvider('whatAGrantNames')]
    public function testAGrantWhoseOperationInstrumentOrWatcherGainedATwinIsNotHonoured(string $twin): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $merge = fn (): array => $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]);
        self::assertSame(['ok' => true], $merge(), 'control: the grant lends');

        $this->house->offerBeside(new Operation($twin, 'Lab: another operation under an identity already taken', static fn (array $input): array => ['ok' => true], scopes: ['lab:watch'], effects: EffectProfile::readOnly()));

        $refused = (string) ($merge()['refused'] ?? 'RAN');
        self::assertStringContainsString('two operations answer to', $refused, 'a grant went on being honoured over a name that now means two operations');
        self::assertCount(1, $this->house->done('merged'));
        $listed = $this->house->as(null, 'standing:granted')['grants'][0];
        self::assertStringContainsString('two operations answer to', (string) json_encode([$listed['because'], $listed['not_honoured_for']]), 'the listing does not say why');
    }

    // ── L1 · a line that only narrows is not dropped because of what its run carried ─────────────────────────

    /** @return iterable<string, array{\Closure(): mixed}> a value a line of the book cannot hold */
    public static function whatALineCannotHold(): iterable
    {
        yield 'negative zero' => [static fn (): mixed => -0.0];
        yield 'bytes that are not text' => [static fn (): mixed => "\xff\xfe not text"];
        yield 'a value nested 510 deep' => [static function (): mixed {
            $value = 'leaf';
            for ($i = 0; $i < 510; ++$i) {
                $value = [$value];
            }

            return $value;
        }];
    }

    #[DataProvider('whatALineCannotHold')]
    public function testAWatcherThatSaysFiredStopsTheGrantWhateverItsRunCarried(\Closure $made): void
    {
        $this->house->granted();
        $this->house->fired = true;

        $this->house->as(LabHouse::SEAT, 'lab:watch', ['stray' => $made()]);

        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state, 'the falsifier fired, and the line that says so was dropped for an argument of the run');
        self::assertNull($this->house->ledger()->fault());
    }

    #[DataProvider('whatALineCannotHold')]
    public function testAnInstrumentThatSaysNoIsWrittenDownWhateverItsRunCarried(\Closure $made): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path, 'stray' => $made()]);

        self::assertSame([true, false], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'), 'the instrument said no, and its receipt was dropped for an argument of the run');
        self::assertStringContainsString('does not hold on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'));
        self::assertNull($this->house->ledger()->fault());
    }

    public function testAnActThatSaysItConsumedWhatIsNotTextSuspendsTheGrant(): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->consumed = "\xff\xfe not text";

        $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);

        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state, 'the act said it consumed something else, and the stop was dropped because what it said is not text');
        self::assertNull($this->house->ledger()->fault());
    }

    // ── L2 · a book whose last line lost its line break ───────────────────────────────────────────────────────

    public function testABookThatDoesNotEndInALineBreakIsAFaultAndTakesNoLine(): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $whole = (string) file_get_contents($book);
        file_put_contents($book, rtrim($whole, "\n"));

        self::assertStringContainsString('does not end in a line break', (string) $this->house->ledger()->fault(), 'a book whose last line has no line break verifies: the next line would be written onto it');
        $merged = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]);

        self::assertArrayHasKey('refused', $merged, 'an act ran, and its line was glued to the line before it');
        self::assertSame([], $this->house->done('merged'));
        self::assertSame(rtrim($whole, "\n"), file_get_contents($book), 'nothing is added to a book that does not verify');
        file_put_contents($book, $whole);
        self::assertNull($this->house->ledger()->fault(), 'control: with its line break back, it verifies');
    }

    // ── L3 · the listing says the whole description of a measuring run ───────────────────────────────────────

    public function testTheListingSaysWhichArgumentsAMeasuringOrAWatchingRunMayCarry(): void
    {
        $narrow = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document()]);
        $wide = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document([
            'id' => 'release-line-wide',
            'conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'lab:scan', 'free' => ['path', 'lenient', 'skip']]]],
            'falsifier' => ['name' => 'a-published-name', 'statement' => 'a published release names a machine', 'watcher' => ['operation' => 'lab:watch', 'free' => ['quiet']], 'within_seconds' => 3600],
        ])]);

        self::assertNotSame($narrow['conditions'], $wide['conditions'], 'two grants whose instruments may be run differently are one line to whoever has just signed');
        self::assertNotSame($narrow['watched_by'], $wide['watched_by']);
        self::assertStringContainsString('lenient', $wide['conditions'][0]);
        self::assertStringContainsString('quiet', $wide['watched_by']);
        $listed = array_column($this->house->as(null, 'standing:granted')['grants'], null, 'grant');
        self::assertStringContainsString('skip', $listed['release-line-wide']['conditions'][0]);
        self::assertStringNotContainsString('skip', $listed['release-line']['conditions'][0]);
        self::assertStringContainsString('quiet', $listed['release-line-wide']['watched_by']);
    }

    // ── what the acta says of the head, as it is ──────────────────────────────────────────────────────────────

    /** NOT a finding closed: the limit the acta now says. A book that holds lines and no head is read as one that never kept a head. */
    public function testSaidNotClosedABookCutBackWithItsHeadDeletedVerifies(): void
    {
        $this->house->granted();
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $before = (string) file_get_contents($book);
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line'])['ok'] ?? false);
        self::assertSame(GrantState::Revoked, $this->house->book()->record('release-line')?->state);

        file_put_contents($book, $before);
        self::assertNotNull($this->house->ledger()->fault(), 'control: cut back with its head in place, the book does not verify');
        unlink($book . StandingLedger::HEAD);

        self::assertNull($this->house->ledger()->fault(), 'this is the limit the acta says: whoever writes that folder needs no digest');
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state);
    }

    /** What the act itself answered, read out of what a leg hands back — whichever way the leg ran it. */
    private static function answered(ToolResult $said): array
    {
        $data = \is_array($said->data) ? $said->data : [];
        if (\is_array($data['output'] ?? null)) {
            return $data['output'];
        }
        if ($data !== []) {
            return $data;
        }
        // A run that did not succeed carries what the act answered as the text of its error.
        $error = json_decode((string) $said->error, true);

        return \is_array($error) ? $error : [];
    }

    /** @return array<string, mixed> the keys of a grant whose watcher and whose instrument are these */
    private static function watchedAndMeasuredBy(string $id, string $watcher, string $instrument): array
    {
        return [
            'id' => $id,
            'covers' => [['operation' => 'lab:merge']],
            'conditions' => [['name' => 'no-host-names', 'statement' => 'what is merged names no machine', 'by' => ['operation' => $instrument, 'free' => ['path']]]],
            'falsifier' => ['name' => 'a-shelf-out-of-place', 'statement' => 'the shelf is out of place', 'watcher' => ['operation' => $watcher], 'within_seconds' => 3600],
        ];
    }

    /**
     * A leg over a house whose grant «shelf-line» covers `lab:merge`, is watched by this watcher and measured by this
     * instrument — both run so far at the terminal's door, in the house's own process — with `good.txt` measured green.
     */
    private function shelfLine(string $watcher, string $instrument = 'shelf:check', bool $watched = true, array $also = []): LabLeg
    {
        $plugin = new LabShelf($this->house->root);
        $this->house->offer(...$plugin->operations());
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'shelf:write'], LabHouse::PERSON);
        $this->house->granted(self::watchedAndMeasuredBy('shelf-line', $watcher, $instrument), watched: false);
        $this->house->candidate('good.txt', "clean release\n");
        self::assertTrue($this->house->as(LabHouse::SEAT, $instrument, ['path' => 'good.txt'])['ok'] ?? false);
        if ($watched) {
            self::assertSame(['ok' => true, 'fired' => false], $this->house->as(LabHouse::SEAT, $watcher));
        }

        return $this->leg = $this->legOver([...$plugin->operations(), $this->house->operation('lab:merge'), ...$also], $plugin);
    }

    /**
     * A leg over a house whose grant «shelf-line» covers the two acts of the shelf a leg does not run in its own
     * process, for a seat that holds their word on its own, with `good.txt` measured green.
     */
    private function coveredShelf(Operation ...$also): LabLeg
    {
        $plugin = new LabShelf($this->house->root);
        $this->house->offer(...$plugin->operations());
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'shelf:write'], LabHouse::PERSON);
        $this->house->granted(['id' => 'shelf-line', 'covers' => [['operation' => 'shelf:lend'], ['operation' => 'shelf:label']]]);
        $this->house->candidate('good.txt', "clean release\n");
        self::assertTrue($this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => 'good.txt'])['ok'] ?? false);

        return $this->leg = $this->legOver([...$plugin->operations(), ...$also], $plugin);
    }

    /**
     * @param list<Operation> $operations
     */
    private function legOver(array $operations, LabShelf $plugin): LabLeg
    {
        // The house's own judge of permissions, as `PluginAuthoringPolicy::install()` registers one: with it, an
        // operation typed by `permission` is served — and the projector wraps the gate's policy to guard it.
        $this->house->container->registerService(\Milpa\Console\OperationPermissionPolicy::class, new class () implements \Milpa\Console\OperationPermissionPolicy {
            public function enforce(Operation $op, \Milpa\ToolRuntime\Contracts\ToolContext $caller, array $arguments): \Milpa\ToolRuntime\Policy\AuthorizationResult
            {
                return \Milpa\ToolRuntime\Policy\AuthorizationResult::allowed();
            }
        });

        return new LabLeg($this->house, $operations, $plugin);
    }

    /**
     * A process of this house that LIVES — a leg, a server — boots now, in a house that already keeps a book: from
     * here on every call goes through that one process, and what it loaded is what the disk holds at this moment.
     */
    private function aProcessThatLivesBoots(): void
    {
        if (!$this->house->ledger()->exists()) {
            $this->house->granted(['id' => 'earlier', 'covers' => [['operation' => 'lab:announce']]], watched: false);
        }
        $this->house->aProcessPerCall = false;
        $this->house->boots();
    }

    /**
     * A plugin written under this house and LOADED BY THIS PROCESS: an instrument, a watcher and an act, each one
     * line long so that the file can be written again with other code and the same layout. What it returns is the
     * operations as this process holds them — whatever the disk comes to hold afterwards.
     *
     * @return array{file: string, class: string, scan: Operation, watch: Operation, ship: Operation}
     */
    private function loaded(string $rule, string $sees = 'false', string $does = "'nothing'"): array
    {
        $name = 'Guard' . bin2hex(random_bytes(4));
        $file = $this->house->root . '/packages/' . strtolower($name) . '/Guard.php';
        mkdir(\dirname($file), 0o755, true);
        $guard = ['file' => $file, 'class' => 'Lab\\Plugins\\' . $name . '\\Guard'];
        $this->onDisk($guard, $rule, $sees, $does);
        require $file;
        $loaded = new ($guard['class'])($this->house->root);

        return $guard + ['scan' => $loaded->scan(), 'watch' => $loaded->watch(), 'ship' => $loaded->ship()];
    }

    /**
     * What the file of that plugin holds NOW. The process that loaded it goes on running what it loaded.
     *
     * @param array{file: string, class: string} $guard
     */
    private function onDisk(array $guard, string $rule, string $sees = 'false', string $does = "'nothing'"): void
    {
        $namespace = substr($guard['class'], 0, (int) strrpos($guard['class'], '\\'));
        file_put_contents($guard['file'], <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Milpa\Command\Effect\Authority;
            use Milpa\Command\Effect\EffectProfile;
            use Milpa\Command\Effect\Externality;
            use Milpa\Command\Effect\Mutation;
            use Milpa\Command\Effect\Reversibility;
            use Milpa\Command\Effect\Subject;
            use Milpa\Command\Operation;

            final class Guard
            {
                public function __construct(private readonly string \$root)
                {
                }

                public function scan(): Operation
                {
                    return new Operation('guard:scan', 'Lab: measure a candidate', fn (array \$input): array => \$this->measure((string) file_get_contents(\$this->root . '/' . \$input['path'])), \$this->path(), scopes: ['lab:scan'], effects: EffectProfile::readOnly(), actsOn: \$this->actsOn(...));
                }

                public function watch(): Operation
                {
                    return new Operation('guard:watch', 'Lab: watch the falsifier', fn (array \$input): array => ['ok' => true, 'fired' => \$this->sees()], scopes: ['lab:watch'], effects: EffectProfile::readOnly());
                }

                public function ship(): Operation
                {
                    return new Operation('guard:ship', 'Lab: ship a candidate', fn (array \$input): array => \$this->shipped((string) file_get_contents(\$this->root . '/' . \$input['path'])), \$this->path(), mutating: true, requiresConfirmation: true, scopes: ['lab:publish'], effects: new EffectProfile(Mutation::Persistent, Externality::Public, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data), actsOn: \$this->actsOn(...));
                }

                private function measure(string \$bytes): array
                {
                    return ['ok' => {$rule}, 'acted_on' => 'sha256:' . hash('sha256', \$bytes)];
                }

                private function sees(): bool
                {
                    return {$sees};
                }

                private function shipped(string \$bytes): array
                {
                    return ['ok' => true, 'ran' => {$does}, 'acted_on' => 'sha256:' . hash('sha256', \$bytes)];
                }

                private function actsOn(array \$input): ?string
                {
                    return is_file(\$this->root . '/' . (\$input['path'] ?? '')) ? 'sha256:' . hash_file('sha256', \$this->root . '/' . \$input['path']) : null;
                }

                private function path(): array
                {
                    return ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']];
                }
            }

            PHP);
    }

    /** An operation typed by `permission`, as a plugin may declare one. */
    private static function permissioned(): Operation
    {
        return new Operation('lab:census', 'Lab: an operation typed by a permission', static fn (array $input): array => ['ok' => true], effects: EffectProfile::readOnly(), permission: 'lab.census');
    }
}
