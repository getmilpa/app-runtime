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
        self::assertStringContainsString('is not honoured: the book of standing grants does not verify (line 2', $refused['refused']);
        self::assertSame('not honoured', $this->house->book()->record('release-line')?->standsAs($this->house->now));

        // Nothing is added to a book that does not verify — not even a suspension.
        try {
            StandingLedger::forRoot($this->house->root)->append(StandingLedger::SUSPENDED, ['grant' => 'release-line']);
            self::fail('a line was added to a book that does not verify');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('does not verify, so nothing is added to it', $error->getMessage());
        }
        self::assertStringContainsString('nothing was suspended', $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'x'])['error']);
        $listed = $this->house->as(LabHouse::PERSON, 'standing:granted');
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

        // A line that is well formed and claims a link it does not have.
        $orphan = ['type' => StandingLedger::REINSTATED, 'at' => '2026-10-11T12:00:00+00:00', 'prev' => null, 'data' => ['grant' => 'a']];
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
        self::assertSame(['no-host-names — what is published names no machine — measured by lab:scan'], $grant['conditions']);
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
        self::assertStringContainsString('watched  lab.watch: FIRED', $recent);
        self::assertStringContainsString('suspended  «release-line» by the house: its falsifier «a-published-name» fired', $recent);
        self::assertStringContainsString('reinstated  «release-line»', $recent);

        // One grant by its id, and one that does not exist.
        self::assertCount(1, $this->house->as(LabHouse::PERSON, 'standing:granted', ['grant' => 'release-line'])['grants']);
        $none = $this->house->as(LabHouse::PERSON, 'standing:granted', ['grant' => 'another']);
        self::assertSame([], $none['grants']);
        self::assertStringNotContainsString('«release-line»', implode("\n", $none['recent']));
    }

    public function testALineThatHoldsNoGrantNamesNothing(): void
    {
        // A `granted` line whose bytes are not a grant lends nothing and refuses nothing: it names no call.
        $this->house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'ghost', 'payload' => 'not an authorization']);
        $this->house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'ghost']);
        $empty = $this->house->signature(LabHouse::PERSON, 'standing:grant', ['grant' => '{"id":"ghost"}']);
        $this->house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'ghost', 'payload' => $empty->payload, 'signature' => $empty->signature, 'fingerprint' => LabHouse::PERSON]);
        $this->house->writtenByHand(StandingLedger::MEASURED, ['not' => 'a receipt']);
        $this->house->writtenByHand(StandingLedger::SUSPENDED, ['grant' => 'ghost', 'reason' => 'of nothing']);

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
