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
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingDecision;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Command\Standing\StandingCall;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Where the judge of standing grants cannot read, cannot write, or has nothing to say, it lends nothing and
 * changes nothing (greenhouse decisions/0615).
 *
 * @guards a grant not honoured while who its signer is today cannot be read; a reinstatement that would restore
 *         nothing refused; an act whose book cannot be written not run; a word lent for the asking taken back when
 *         no grant names the call, leaving the seat exactly what it holds on its own; the book kept out of any trial
 *
 * @refuses a promotion that carries the book of standing grants; writing a line for a call no grant refused or
 *          passed
 *
 * @subject-in milpa/app-runtime
 */
final class WhatTheJudgeCannotReadItDoesNotLendTest extends TestCase
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

    public function testAGrantIsNotHonouredWhileWhoItsSignerIsCannotBeRead(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        $ledger = $this->house->root . '/storage/identity/enrollments.json';
        $whole = (string) file_get_contents($ledger);

        file_put_contents($ledger, '{ not the ledger');
        $fault = $this->house->book()->record('release-line')?->fault;
        file_put_contents($ledger, $whole);

        self::assertStringContainsString('who the signer is today cannot be read', (string) $fault);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]), 'control: readable again, honoured again');
    }

    public function testLiftingTheSuspensionOfAGrantThatIsNotHonouredRestoresNothing(): void
    {
        $this->house->granted();
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped']);
        $suspension = (string) $this->house->book()->record('release-line')?->suspension;
        $this->house->revokeKey(LabHouse::PERSON);

        $refused = $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $suspension]);

        self::assertStringContainsString('«release-line» is not honoured', $refused['error']);
        self::assertStringContainsString('Lifting its suspension would restore nothing', $refused['error']);
        self::assertSame([], $this->house->lines(StandingLedger::REINSTATED));
    }

    public function testAPersonsActThatCannotBeWrittenDownSaysSoAndChangesNothing(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes to a read-only file: this control cannot distinguish there');
        }
        // No book yet, and nowhere to make one.
        chmod($this->house->root . '/storage/identity', 0o500);
        try {
            $given = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document()]);
        } finally {
            chmod($this->house->root . '/storage/identity', 0o755);
        }
        self::assertStringContainsString('nothing was granted: The book of standing grants could not be opened for writing', $given['error']);

        // A book that is there and cannot be written.
        $this->house->granted();
        chmod($this->house->root . '/' . StandingLedger::PATH, 0o400);
        try {
            $withdrawn = $this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line']);
        } finally {
            chmod($this->house->root . '/' . StandingLedger::PATH, 0o600);
        }
        self::assertStringContainsString('could not be opened for writing', $withdrawn['error']);
        self::assertStringContainsString('nothing was withdrawn', $withdrawn['error']);
        self::assertSame('in force', $this->house->book()->record('release-line')?->standsAs($this->house->now));
    }

    public function testAWordLentForTheAskingIsTakenBackAndTheSeatKeepsOnlyWhatItHolds(): void
    {
        // An operation that asks for one of two words. The seat holds one; a grant names the operation for it with
        // other arguments, so the second word is lent for the asking — and must not survive the judgment.
        $twoWords = new Operation(
            'lab:tag',
            'Lab: an operation that asks for one of two words',
            static fn (array $input): array => ['ok' => true],
            ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'line' => ['type' => 'string']]],
            mutating: true,
            scopes: ['lab:scan', 'lab:tag'],
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data),
            actsOn: fn (array $input): ?string => $this->house->digestOf((string) $input['path']),
        );
        PluginAuthoringPolicy::catalogue($this->house->container, [$twoWords]);
        $this->house->granted(['covers' => [['operation' => 'lab:tag', 'arguments' => ['line' => 'stable']]]]);
        $candidate = $this->house->candidate('a.tar', 'candidate a');

        $context = new ToolContext(principal: 'key:' . LabHouse::SEAT, channel: 'cli', scopes: LabHouse::SEAT_SCOPES);
        $lent = $this->house->policy->contextFor($context, 'lab_tag');
        self::assertSame(['lab:tag'], $lent->extra[HouseStandingJudge::LENT]);
        self::assertTrue($lent->hasScope('lab:tag'));

        // Another line: no grant names this call. The seat holds `lab:scan`, which the operation accepts, so it runs
        // — as itself, with the lent word gone before anything else could read it.
        $result = $this->house->policy->execute($twoWords, ['path' => $candidate, 'line' => 'beta'], $lent, static fn (): array => ['ok' => true]);
        self::assertSame(['ok' => true], $result);
        self::assertSame([], $this->house->lines(StandingLedger::REFUSED));
        self::assertSame([], $this->house->lines(StandingLedger::ABSORBED));
        self::assertSame($context->scopes, HouseStandingJudge::withoutLent($lent)->scopes);
        self::assertArrayNotHasKey(HouseStandingJudge::LENT, HouseStandingJudge::withoutLent($lent)->extra);
        self::assertSame($context, HouseStandingJudge::withoutLent($context), 'a context nothing was lent to comes back as it is');
    }

    public function testAGrantForAnotherSeatHasNothingToSayAboutThisOne(): void
    {
        $this->house->enroll(LabHouse::OTHER_SEAT, ['agent:run', 'lab:scan', 'lab:watch'], LabHouse::PERSON);
        $this->house->granted(['id' => 'for-the-other', 'to' => [LabHouse::OTHER_SEAT], 'covers' => [['operation' => 'lab:announce']]]);
        $this->house->granted(['covers' => [['operation' => 'lab:merge']]]);
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]));
        self::assertStringContainsString('Missing required scope', $this->house->as(LabHouse::SEAT, 'lab:announce', ['path' => $candidate])['refused']);
        self::assertStringContainsString('wider than the grant\'s ceiling', $this->house->as(LabHouse::OTHER_SEAT, 'lab:announce', ['path' => $candidate])['refused']);
    }

    public function testNothingIsWrittenForACallNoGrantRefusedOrPassed(): void
    {
        $this->house->granted();
        $judge = $this->house->policy->standing();
        $unnamed = new StandingDecision(new StandingCall('lab:publish', [], LabHouse::SEAT, null, EffectProfile::readOnly(), $this->house->now));
        $before = \count($this->house->ledger()->entries());

        $judge->absorbing($unnamed);
        $judge->absorbed($unnamed, $this->house->operation('lab:publish'));
        $judge->refused($unnamed);

        self::assertFalse($unnamed->named());
        self::assertFalse($unnamed->passes());
        self::assertCount($before, $this->house->ledger()->entries());
        self::assertInstanceOf(HouseStandingJudge::class, (new PluginAuthoringPolicy($this->house->root))->standing(), 'a policy nobody handed a judge has the house\'s own');
    }

    public function testAPromotionNeverCarriesTheBookOfStandingGrants(): void
    {
        mkdir($this->house->root . '/src/Plugins/Blog', 0o755, true);
        $trial = TrialWorkspace::materialize($this->house->root, 'wbook', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        @mkdir($trial->copy . '/storage/identity', 0o755, true);
        file_put_contents($trial->copy . '/' . StandingLedger::PATH, '{"type":"reinstated","written":"in a rehearsal"}' . "\n");
        file_put_contents($trial->copy . '/src/Plugins/Blog/Blog.php', "<?php // the plugin\n");
        $promote = (new TrialOperations(new DIContainer(), root: $this->house->root))->operations()[0];
        self::assertSame('sandbox:promote', $promote->name);

        $promoted = ($promote->handler)(['workspace' => 'wbook'], null, new ToolContext(principal: 'worker', channel: 'cli', scopes: ['*']));

        self::assertFalse($promoted['ok']);
        self::assertStringContainsString('a trial never carries the book of standing grants', $promoted['error']);
        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH);
        self::assertFileDoesNotExist($this->house->root . '/src/Plugins/Blog/Blog.php', 'nothing of that trial crossed');
    }
}
