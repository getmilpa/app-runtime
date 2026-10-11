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

use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What an independent security review found in the house's judge of standing grants, each reproduced by a test
 * that was RED on the reviewed code before anything was fixed (greenhouse decisions/0615, «Lo que la revisión
 * encontró»). The reviewer traced code and ran nothing, so every finding arrived as a hypothesis.
 *
 * @guards a lent word that reaches nothing but the gate's question about that one tool; a call no grant names
 *         handed nothing; a covered operation refused once it is no longer the one that was granted, or no longer
 *         grantable; a suspended grant that only a person's act naming its suspension takes out of the way; a fired
 *         falsifier that suspends by the line that says so; a granted build that still runs in its trial; a book
 *         that does not verify refusing every seat; an act written down under the lock the book was read under
 *
 * @refuses a nested call running on a word lent for the call around it; an operation that gives authority, hands
 *          its caller's authority on, or asks for every scope; superseding a suspended grant without naming its
 *          suspension, or by a key that does not answer for its seats; a receipt for bytes the instrument did not
 *          say it read; an act that does not say what it consumed
 *
 * @subject-in milpa/app-runtime
 */
final class WhatAReviewFoundInTheHouseTest extends TestCase
{
    private LabHouse $house;

    private string $bwrap;

    protected function setUp(): void
    {
        $this->bwrap = sys_get_temp_dir() . '/milpa-standing-bwrap-' . bin2hex(random_bytes(6));
        file_put_contents($this->bwrap, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($this->bwrap, 0o755);
        $this->house = new LabHouse(new TrialRunner(bwrap: $this->bwrap));
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
        @unlink($this->bwrap);
    }

    private function effect(Authority $authority = Authority::WriteAsUser): EffectProfile
    {
        return new EffectProfile(Mutation::Persistent, Externality::Public, Reversibility::Compensatable, $authority, subject: Subject::Data);
    }

    /** @return \Closure(array<string, mixed>): ?string */
    private function actsOnThePath(): \Closure
    {
        return fn (array $input): ?string => is_file($this->house->root . '/' . ($input['path'] ?? '')) ? $this->house->digestOf((string) $input['path']) : null;
    }

    private const PATH = ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]];

    // ── 1 · what was lent for one call reaches nothing else ─────────────────────────────────────────────────

    public function testAWordLentForOneCallDoesNotReachACallItsHandlerMakes(): void
    {
        // A driver: an operation whose handler is handed its caller's authority and calls another operation with it,
        // rebuilding who it calls for the way a door does — the principal and the scopes, and nothing else.
        $house = $this->house;
        $house->offer(new Operation(
            'lab:drive',
            'Lab: a driver that carries its caller\'s authority on',
            static function (array $input, mixed $invocation = null, ?ToolContext $authority = null) use ($house): array {
                $carried = new ToolContext(principal: $authority?->principal, channel: 'cli', scopes: $authority->scopes ?? []);

                return $house->through($carried, 'lab:publish', ['path' => $input['then'], 'channel' => 'stable']);
            },
            ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'then' => ['type' => 'string']]],
            mutating: true,
            scopes: ['lab:publish'],
            effects: $this->effect(),
            actsOn: $this->actsOnThePath(),
        ));
        // Signed by the person and written into the book by whoever holds the disk: what `standing:grant` would refuse.
        $house->grantedByHand(['covers' => [['operation' => 'lab:drive']]], line: ['operations' => ['lab.drive' => $house->operation('lab:drive')->instrumentDigest()]]);
        $house->as(LabHouse::SEAT, 'lab:watch');
        $measured = $house->candidate('a.tar', 'candidate a');
        $unmeasured = $house->candidate('b.tar', 'candidate b');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $measured]);

        $result = $house->as(LabHouse::SEAT, 'lab:drive', ['path' => $measured, 'then' => $unmeasured]);

        self::assertSame([], $house->done('published'), 'a candidate nobody measured was published on a word lent for another call: ' . json_encode($result));
        self::assertStringContainsString('is handed its caller\'s authority', (string) ($result['refused'] ?? ''));
        // And nobody can give such a grant in the first place.
        $given = $house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $house->document(['id' => 'over-a-driver', 'covers' => [['operation' => 'lab:drive']]])]);
        self::assertStringContainsString('is handed its caller\'s authority', (string) ($given['error'] ?? ''));
    }

    public function testTheHousesOwnDoorCallsForItsCallerWithoutWhatWasLent(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        $lent = $this->house->policy->contextFor($this->house->contextOf(LabHouse::SEAT), 'lab_merge', ['path' => $candidate]);
        self::assertTrue($lent->hasScope('lab:merge'), 'control: the gate is asked with the word in hand');

        // The door a recipe or a sequence opens with its caller's authority: what it calls for is that caller as it is
        // on its own. The word lent for `lab:merge` does not make `lab:merge` — or anything else — callable through it.
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('lab_merge', 'merge', ['type' => 'object'], static fn (): array => ['ok' => true], new ToolOptions(scopes: ['lab:merge']));
        $registry->register('lab_scan', 'scan', ['type' => 'object'], static fn (): array => ['ok' => true], new ToolOptions(scopes: ['lab:scan']));

        self::assertSame(['lab_merge'], (new ConsentBridge($registry, authority: $lent))->notOfferedToThisCaller());
    }

    public function testACallNoGrantNamesHandsItsHandlerNothingThatWasLent(): void
    {
        // An operation that asks for one of two words. The seat holds one; a grant names the operation with OTHER
        // arguments. The call is the seat's own — and its handler must be handed the seat as it is on its own.
        $house = $this->house;
        $house->offer(new Operation(
            'lab:tag',
            'Lab: an operation that asks for one of two words and looks at who calls',
            static function (array $input, mixed $invocation = null, ?ToolContext $authority = null) use ($house): array {
                $house->seen = $authority;

                return ['ok' => true];
            },
            ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'line' => ['type' => 'string']]],
            mutating: true,
            scopes: ['lab:scan', 'lab:tag'],
            effects: $this->effect(),
            actsOn: $this->actsOnThePath(),
        ));
        $house->grantedByHand(['covers' => [['operation' => 'lab:tag', 'arguments' => ['line' => 'stable']]]], line: ['operations' => ['lab.tag' => $house->operation('lab:tag')->instrumentDigest()]]);
        $candidate = $house->candidate('a.tar', 'candidate a');

        self::assertSame(['ok' => true], $house->as(LabHouse::SEAT, 'lab:tag', ['path' => $candidate, 'line' => 'beta']));

        self::assertNotNull($house->seen);
        self::assertFalse($house->seen->hasScope('lab:tag'), 'the handler of a call no grant named was handed a lent word');
        self::assertSame(LabHouse::SEAT_SCOPES, $house->seen->scopes);
        self::assertArrayNotHasKey(HouseStandingJudge::LENT, $house->seen->extra);
    }

    public function testAWordLentWhenOnlyTheToolWasAskedAboutDoesNotCarryACallNoGrantNames(): void
    {
        // Asked only whether the tool is offered, the house hands its words to a seat a grant names for it. If a call
        // then arrives with that context, and no grant names THAT call, the gate's question is asked again of what
        // the seat holds on its own.
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $offered = $this->house->policy->contextFor($this->house->contextOf(LabHouse::SEAT), 'lab_publish');
        self::assertTrue($offered->hasScope('lab:publish'), 'control: the tool is offered');
        $publish = $this->house->operation('lab:publish');
        $tool = new \Milpa\ToolRuntime\ToolDefinition('lab_publish', $publish->description, $publish->inputSchema ?? [], $publish->handler, scopes: $publish->scopes, mutating: true);

        $verdict = $this->house->policy->authorize($offered, $tool, ['path' => $candidate, 'channel' => 'beta']);

        self::assertFalse($verdict->allowed);
        self::assertSame("Missing required scope for tool 'lab_publish'. Need one of: lab:publish — context has: agent:run, lab:scan, lab:watch.", $verdict->reason);
    }

    public function testEveryDoorTellsTheHouseWhichCallItIsAskingAbout(): void
    {
        // The gate a leg asks and the registry it calls through hand the policy the call's arguments — or the house
        // would lend by the tool's name alone, to calls no grant names.
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $seat = $this->house->contextOf(LabHouse::SEAT);
        $publish = $this->house->operation('lab:publish');
        $tool = new \Milpa\ToolRuntime\ToolDefinition('lab_publish', $publish->description, $publish->inputSchema ?? [], $publish->handler, scopes: $publish->scopes, mutating: true);
        $named = ['path' => $candidate, 'channel' => 'stable'];
        $unnamed = ['path' => $candidate, 'channel' => 'beta'];

        $inner = new class () extends \Milpa\ToolRuntime\PolicyGate {
            /** @var list<ToolContext> */
            public array $asked = [];

            public function authorize(ToolContext $ctx, \Milpa\ToolRuntime\ToolDefinition $tool, array $arguments = []): \Milpa\ToolRuntime\Policy\AuthorizationResult
            {
                $this->asked[] = $ctx;

                return \Milpa\ToolRuntime\Policy\AuthorizationResult::allowed();
            }

            public function authorizeCall(ToolContext $ctx, \Milpa\ToolRuntime\ToolDefinition $tool, array $arguments): \Milpa\ToolRuntime\Policy\AuthorizationResult
            {
                $this->asked[] = $ctx;

                return \Milpa\ToolRuntime\Policy\AuthorizationResult::allowed();
            }
        };
        $inner->setCallPolicy($this->house->policy);
        $gate = new \Milpa\AppRuntime\Agent\AdmissionAwareGate($inner);

        $gate->authorize($seat, $tool, $named);
        $gate->authorize($seat, $tool, $unnamed);
        $gate->authorizeCall($seat, $tool, $named);
        $gate->authorizeCall($seat, $tool, $unnamed);
        self::assertSame([true, false, true, false], array_map(static fn (ToolContext $asked): bool => $asked->hasScope('lab:publish'), $inner->asked));
        self::assertTrue($gate->contextFor($seat, 'lab_publish')->hasScope('lab:publish'), 'asked only about the tool, it is offered');

        $registry = new class (new NullLogger()) extends ToolRegistry {
            /** @var list<?ToolContext> */
            public array $called = [];

            public function call(string $name, array $args, ?ToolContext $ctx = null): \Milpa\ToolRuntime\ToolResult
            {
                $this->called[] = $ctx;

                return \Milpa\ToolRuntime\ToolResult::success(['ok' => true]);
            }
        };
        $registry->getPolicyGate()->setCallPolicy($this->house->policy);
        $wrapped = new \Milpa\AppRuntime\Agent\AdmissionAwareRegistry($registry);

        $wrapped->call('lab_publish', $named, $seat);
        $wrapped->call('lab_publish', $unnamed, $seat);
        self::assertSame([true, false], array_map(static fn (?ToolContext $called): bool => $called?->hasScope('lab:publish') === true, $registry->called));
    }

    // ── 2 · a nested call does not inherit what was lent for the call around it ─────────────────────────────

    public function testWhatWasLentForOneToolIsTakenBackBeforeAnotherToolIsAsked(): void
    {
        $house = $this->house;
        $two = new Operation('lab:two', 'Lab: asks for one of two words', static fn (array $input): array => ['ok' => true, 'acted_on' => null], self::PATH + ['properties' => ['path' => ['type' => 'string'], 'line' => ['type' => 'string']]], mutating: true, scopes: ['lab:merge', 'lab:two'], effects: $this->effect(), actsOn: $this->actsOnThePath());
        $house->offer($two);
        $house->granted();
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        // A line nobody signed names `lab:two` for the seat, with other arguments: enough, before, for the house to hand
        // over `lab:two`'s words and then take back only the ones it had just added.
        $house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'unsigned', 'payload' => $house->signature(LabHouse::STRANGER, 'standing:grant', ['grant' => $house->document(['id' => 'unsigned', 'covers' => [['operation' => 'lab:two', 'arguments' => ['line' => 'stable']]]])])->payload]);

        // The context a call to `lab:merge` runs with — `lab:merge` lent under the real grant — is what a nested call
        // would start from.
        $outer = $house->policy->contextFor($house->contextOf(LabHouse::SEAT), 'lab_merge', ['path' => $candidate]);
        self::assertTrue($outer->hasScope('lab:merge'));

        $nested = $house->through($outer, 'lab:two', ['path' => $candidate, 'line' => 'beta']);

        self::assertStringContainsString('Missing required scope', (string) ($nested['refused'] ?? json_encode($nested)));
        self::assertStringContainsString('context has: agent:run, lab:scan, lab:watch.', (string) ($nested['refused'] ?? ''), 'and the refusal shows the caller as it is on its own');
        self::assertFalse($house->policy->contextFor($outer, 'lab_two', ['path' => $candidate, 'line' => 'beta'])->hasScope('lab:merge'));
    }

    // ── 4 · the floors hold at the moment of the call, and by the axis ──────────────────────────────────────

    public function testACoveredOperationThatIsNoLongerTheOneThatWasGrantedIsLentNothing(): void
    {
        $house = $this->house;
        $house->granted();
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        self::assertSame(['ok' => true], $house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]), 'control: as it was granted, it runs');

        // The operation is replaced under the same name — an update, a plugin registered again — and now asks for the
        // word that recognizes identities.
        $merge = $house->operation('lab:merge');
        $house->offer(new Operation('lab:merge', $merge->description, $merge->handler, $merge->inputSchema, mutating: true, requiresConfirmation: true, scopes: ['identity:enroll'], effects: $merge->effects, actsOn: $merge->actsOn));

        $context = $house->policy->contextFor($house->contextOf(LabHouse::SEAT), 'lab_merge', ['path' => $candidate]);
        self::assertFalse($context->hasScope('identity:enroll'), 'the seat was lent the word that recognizes identities');
        $refused = $house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]);
        self::assertArrayHasKey('refused', $refused);
        self::assertCount(1, $house->done('merged'));
    }

    public function testAnOperationThatChangedSinceItWasGrantedIsRefusedAndSaysSo(): void
    {
        $house = $this->house;
        $house->granted();
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        // The same name, the same words — and another handler.
        $merge = $house->operation('lab:merge');
        $house->offer(new Operation('lab:merge', $merge->description, static fn (array $input): array => ['ok' => true, 'did' => 'something else'], $merge->inputSchema, mutating: true, requiresConfirmation: true, scopes: $merge->scopes, effects: $merge->effects, actsOn: $merge->actsOn));

        $refused = $house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]);
        self::assertStringContainsString('is not the operation it was when this grant was given', (string) ($refused['refused'] ?? json_encode($refused)));
    }

    public function testNoGrantIsGivenOverAnOperationThatGivesAuthorityByItsAxisOrAsksForEveryScope(): void
    {
        $house = $this->house;
        $house->offer(
            // The word a list would look for is not here: only the axis says what this operation is.
            new Operation('lab:institute', 'Lab: decides what the house does, under an ordinary word', static fn (array $input): array => ['ok' => true], self::PATH, mutating: true, scopes: ['lab:institute'], effects: $this->effect(Authority::Privileged), actsOn: $this->actsOnThePath()),
            new Operation('lab:everything', 'Lab: asks for every scope', static fn (array $input): array => ['ok' => true], self::PATH, mutating: true, scopes: ['*'], effects: $this->effect(), actsOn: $this->actsOnThePath()),
        );

        $institutional = $house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $house->document(['covers' => [['operation' => 'lab:institute']]])]);
        self::assertStringContainsString('itself gives authority', (string) ($institutional['error'] ?? 'GIVEN'));

        $every = $house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $house->document(['covers' => [['operation' => 'lab:everything']]])]);
        self::assertStringContainsString('every scope', (string) ($every['error'] ?? 'GIVEN'));
        self::assertFileDoesNotExist($house->root . '/' . StandingLedger::PATH);

        // And written into the book by hand, signed: at the moment of the call the floor is asked again, and `*` is
        // never among what is lent.
        $house->grantedByHand(['covers' => [['operation' => 'lab:everything']]], line: ['operations' => ['lab.everything' => $house->operation('lab:everything')->instrumentDigest()]]);
        $candidate = $house->candidate('a.tar', 'candidate a');
        self::assertFalse($house->policy->contextFor($house->contextOf(LabHouse::SEAT), 'lab_everything', ['path' => $candidate])->hasScope('*'));
        self::assertArrayHasKey('refused', $house->as(LabHouse::SEAT, 'lab:everything', ['path' => $candidate]));
    }

    // ── 6 · superseding a suspended grant is growing back ───────────────────────────────────────────────────

    public function testSupersedingASuspendedGrantLiftsNothingUnlessItNamesTheSuspension(): void
    {
        $house = $this->house;
        $house->granted();
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        $house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped']);
        $suspension = (string) $house->book()->record('release-line')?->suspension;

        // Through the door: refused, and told what a superseding grant has to say.
        $blind = $house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $house->document(['id' => 'second', 'supersedes' => 'release-line'])]);
        self::assertStringContainsString('is suspended', (string) ($blind['error'] ?? 'GIVEN'));
        self::assertStringContainsString($suspension, (string) ($blind['error'] ?? ''));
        $stale = $house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $house->document(['id' => 'second', 'supersedes' => 'release-line', 'lifts' => 'sha256:another'])]);
        self::assertStringContainsString('is suspended', (string) ($stale['error'] ?? 'GIVEN'));

        // Written into the book by hand — a genuine signature that names no suspension, say one made before it: the
        // suspended grant stays suspended, and under it the call stays refused.
        $house->grantedByHand(['id' => 'second', 'supersedes' => 'release-line'], line: ['operations' => $this->digestsOfTheLabGrant()]);
        $house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame('suspended', $house->book()->record('release-line')?->standsAs($house->now));
        self::assertStringContainsString('Standing grant «release-line» is suspended', (string) ($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused'] ?? 'RAN'));

        // The control: a grant that names the suspension it lifts.
        $named = $house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $house->document(['id' => 'third', 'supersedes' => 'release-line', 'lifts' => $suspension])]);
        self::assertTrue($named['ok'] ?? false, (string) ($named['error'] ?? ''));
        $house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame('superseded', $house->book()->record('release-line')?->standsAs($house->now));
        self::assertSame(['ok' => true], $house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]));
    }

    public function testAGrantIsSupersededOnlyByAKeyThatAnswersForItsSeats(): void
    {
        $house = $this->house;
        $house->granted();
        // Another person, with the word that recognizes identities, who enrolled a seat of their own — and not ours.
        $house->enroll(LabHouse::STRANGER, ['identity:enroll'], LabHouse::STRANGER);
        $house->enroll(LabHouse::OTHER_SEAT, ['agent:run'], LabHouse::STRANGER);

        $given = $house->signed(LabHouse::STRANGER, 'standing:grant', ['grant' => $house->document(['id' => 'theirs', 'to' => [LabHouse::OTHER_SEAT], 'supersedes' => 'release-line'])]);
        self::assertStringContainsString('does not answer for seat', (string) ($given['error'] ?? 'GIVEN'));

        $house->grantedByHand(['id' => 'theirs', 'to' => [LabHouse::OTHER_SEAT], 'supersedes' => 'release-line'], LabHouse::STRANGER, ['operations' => $this->digestsOfTheLabGrant()]);
        self::assertSame('in force', $house->book()->record('release-line')?->standsAs($house->now), 'a key that does not answer for a grant\'s seats took that grant away');
    }

    public function testAGrantWhoseOwnLineDoesNotHoldSupersedesNothing(): void
    {
        $house = $this->house;
        $house->granted();

        // Signed by the person, who answers for the seat — and its line records another digest than what was signed.
        $house->grantedByHand(['id' => 'second', 'supersedes' => 'release-line'], line: ['digest' => 'sha256:of-something-else', 'operations' => $this->digestsOfTheLabGrant()]);

        self::assertNotNull($house->book()->record('second')?->fault);
        self::assertSame('in force', $house->book()->record('release-line')?->standsAs($house->now));
    }

    /** @return array<string, string> */
    private function digestsOfTheLabGrant(): array
    {
        $digests = [];
        foreach (['lab:publish', 'lab:merge', 'lab:announce'] as $name) {
            $digests[str_replace(':', '.', $name)] = (string) $this->house->operation($name)->instrumentDigest();
        }

        return $digests;
    }

    // ── 7 · a fired falsifier suspends by the line that says so ─────────────────────────────────────────────

    public function testAWatcherThatReportedItsFalsifierFiredHasSuspendedTheGrantByThatLineAlone(): void
    {
        $this->house->granted();
        // The house wrote what the watcher saw — and nothing after it: the second write never happened.
        $this->house->writtenByHand(StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => true, 'who' => 'key:' . LabHouse::SEAT]);

        $record = $this->house->book()->record('release-line');
        self::assertSame('suspended', $record?->standsAs($this->house->now));
        self::assertStringContainsString('fired', (string) $record?->because);
        self::assertNotNull($record?->suspension);
        // And it is lifted the way any suspension is: by a person, naming it.
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $record?->suspension])['ok'] ?? false);
        self::assertSame('in force', $this->house->book()->record('release-line')?->standsAs($this->house->now));
    }

    // ── 8 · a granted build still runs in its trial ─────────────────────────────────────────────────────────

    public function testABuildCallThatAGrantCoversIsNotRunDirectlyOnTheHouse(): void
    {
        $house = $this->house;
        $ranOnTheHouse = false;
        mkdir($house->root . '/src/Plugins/Blog', 0o755, true);
        $house->offer(new Operation(
            'make',
            'Lab: stands in for the scaffolder',
            static function (array $input) use (&$ranOnTheHouse): array {
                $ranOnTheHouse = true;

                return ['ok' => true, 'acted_on' => null];
            },
            ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'plugin' => ['type' => 'string'], 'what' => ['type' => 'string']]],
            mutating: true,
            scopes: ['lab:make'],
            effects: $this->effect(),
            actsOn: $this->actsOnThePath(),
        ));
        $house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'plugins.Blog:write'], LabHouse::PERSON);
        $house->granted(['covers' => [['operation' => 'make']]]);
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        $result = $house->as(LabHouse::SEAT, 'make', ['path' => $candidate, 'plugin' => 'Blog', 'what' => 'page']);

        self::assertFalse($ranOnTheHouse, 'a build call a grant covers wrote to the house instead of going to its trial: ' . json_encode($result));
    }

    // ── 9 · a book that does not verify from its first line ─────────────────────────────────────────────────

    public function testABookThatDoesNotVerifyFromItsFirstLineRefusesEverySeat(): void
    {
        $house = $this->house;
        // The seat holds the word on its own, and a grant governs it — suspended.
        $house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:merge'], LabHouse::PERSON);
        $house->granted();
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        $house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped']);
        self::assertStringContainsString('is suspended', (string) ($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused'] ?? 'RAN'), 'control');
        $path = $house->root . '/' . StandingLedger::PATH;
        $whole = (string) file_get_contents($path);

        // One character of the FIRST line: nothing of the book reads any more — which must not mean «nobody granted».
        file_put_contents($path, 'x' . $whole);
        $refused = $house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]);
        self::assertStringContainsString('book of standing grants does not verify', (string) ($refused['refused'] ?? 'RAN: the suspension vanished with the book'));
        self::assertStringContainsString('book of standing grants does not verify', (string) ($house->as(LabHouse::OTHER_SEAT, 'lab:watch')['refused'] ?? 'RAN'), 'every seat, whatever it calls');
        // The terminal's own operator is not a seat: a person repairs the book from there.
        self::assertSame(['ok' => true], $house->as(null, 'lab:merge', ['path' => $candidate]) + ['ok' => true]);

        if (!\function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            file_put_contents($path, $whole);
            chmod($path, 0);
            $unreadable = $house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]);
            chmod($path, 0o600);
            self::assertStringContainsString('book of standing grants does not verify', (string) ($unreadable['refused'] ?? 'RAN'));
        }
        file_put_contents($path, $whole);
        self::assertStringContainsString('is suspended', (string) ($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused'] ?? 'RAN'));
    }

    // ── 10 · the act is written under the lock the book was read under ──────────────────────────────────────

    public function testASuspensionThatLandsAfterTheBookWasReadStillStopsTheAct(): void
    {
        $house = $this->house;
        $house->granted();
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        // Inside the boundary the book is read and the call judged. While the keyring verifies during THAT reading —
        // the lines are already read — someone else stops the grant: a suspension the judgment did not see.
        $landed = false;
        $house->whileVerifying = static function () use ($house, &$landed): void {
            if ($house->atTheBoundary && !$landed) {
                $landed = true;
                $house->whileVerifying = null;
                $house->writtenByHand(StandingLedger::SUSPENDED, ['grant' => 'release-line', 'by' => 'key:' . LabHouse::OTHER_SEAT, 'reason' => 'stopped while the call was being judged']);
            }
        };

        $result = $house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]);

        self::assertTrue($landed, 'the instrument did not land its suspension: this test proves nothing');
        self::assertSame([], $house->done('merged'), 'the act ran though the grant had been suspended before it was written down: ' . json_encode($result));
        self::assertSame([], $house->lines(StandingLedger::ABSORBED));
    }

    // ── 3 · A → B → A: the bytes an instrument or an act consumed ───────────────────────────────────────────

    public function testAReceiptIsNotWrittenForBytesTheInstrumentDidNotSayItRead(): void
    {
        $house = $this->house;
        $house->granted();
        $dirty = $house->candidate('a.tar', "candidate a\nmachine-name: somewhere\n");
        // Whoever writes the candidate shows the instrument OTHER bytes, and puts the first ones back: the digest the
        // house takes before and after is the same, and what was measured is not what it names.
        $house->scanner = static function (string $bytes) use ($house): array {
            $shown = 'a clean candidate';
            $verdict = ['ok' => !str_contains($shown, 'machine-name:'), 'acted_on' => 'sha256:' . hash('sha256', $shown)];
            $house->candidate('a.tar', "candidate a\nmachine-name: somewhere\n");

            return $verdict;
        };

        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $dirty]);

        self::assertSame([], $house->lines(StandingLedger::MEASURED), 'a green receipt was written for bytes the instrument never read');
        self::assertStringContainsString('never measured', (string) ($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $dirty])['refused'] ?? 'RAN'));

        // And an instrument that does not say what it read measures nothing.
        $house->scanner = static fn (string $bytes): array => ['ok' => true];
        $house->scannerSaysWhatItRead = false;
        $clean = $house->candidate('c.tar', 'candidate c');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $clean]);
        self::assertSame([], $house->lines(StandingLedger::MEASURED));
    }

    public function testAnActThatConsumedOtherBytesThanTheOnesJudgedSuspendsTheGrant(): void
    {
        $house = $this->house;
        $house->granted();
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        // A → B → A around the act: the digest before and after is A, and the act says it consumed B.
        $house->consumed = 'sha256:' . hash('sha256', 'swapped under the act, and swapped back');

        self::assertArrayHasKey('ok', $house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']));

        $suspended = $house->lines(StandingLedger::SUSPENDED);
        self::assertCount(1, $suspended, 'the act consumed other bytes than the ones judged and nothing noticed');
        self::assertStringContainsString('said it consumed', $suspended[0]['reason']);
        self::assertStringContainsString('is suspended', (string) ($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused'] ?? 'RAN'));
    }

    public function testContentThatChangedUnderAnActSuspendsTheGrantWhateverTheActSays(): void
    {
        $house = $this->house;
        $house->granted();
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        $judged = $house->digestOf($candidate);
        // The act says it consumed exactly what was judged — and what is there afterwards is something else. The
        // house's own second reading is not given up for the act's word.
        $house->consumed = $judged;
        $house->whilePublishing = static fn () => $house->candidate('a.tar', 'rewritten under the act');

        $house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']);

        $suspended = $house->lines(StandingLedger::SUSPENDED);
        self::assertCount(1, $suspended);
        self::assertStringContainsString($judged . ' was judged', $suspended[0]['reason']);
        self::assertStringContainsString('was there afterwards', $suspended[0]['reason']);
    }

    public function testAnActThatDoesNotSayWhatItConsumedSuspendsTheGrantAndOneThatThrewIsReadByTheHouseAlone(): void
    {
        $house = $this->house;
        $silent = new Operation('lab:silent', 'Lab: an act that does not say what it consumed', static fn (array $input): array => ['ok' => true], self::PATH, mutating: true, scopes: ['lab:silent'], effects: $this->effect(), actsOn: $this->actsOnThePath());
        $failing = new Operation('lab:failing', 'Lab: an act that fails on its way', static function (array $input): array {
            throw new \RuntimeException('the network went away');
        }, self::PATH, mutating: true, scopes: ['lab:failing'], effects: $this->effect(), actsOn: $this->actsOnThePath());
        $house->offer($silent, $failing);
        $house->granted(['covers' => [['operation' => 'lab:silent'], ['operation' => 'lab:failing']]]);
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        // It threw: it said nothing, and the house's own two readings agree — nothing to suspend for.
        self::assertSame(['refused' => 'the network went away'], $house->as(LabHouse::SEAT, 'lab:failing', ['path' => $candidate]));
        self::assertSame([], $house->lines(StandingLedger::SUSPENDED));

        // It returned without saying what it consumed: the house cannot tell, and stops lending.
        self::assertSame(['ok' => true], $house->as(LabHouse::SEAT, 'lab:silent', ['path' => $candidate]));
        $suspended = $house->lines(StandingLedger::SUSPENDED);
        self::assertCount(1, $suspended);
        self::assertStringContainsString('did not say what it consumed', $suspended[0]['reason']);
    }

    // ── 5 · a watcher's sign of life is the call the falsifier describes ────────────────────────────────────

    public function testAWatcherRunWithAnArgumentNobodyDescribedIsNotASignOfLife(): void
    {
        $house = $this->house;
        $house->granted(watched: false);
        $candidate = $house->candidate('a.tar', 'candidate a');
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        $house->as(LabHouse::SEAT, 'lab:watch', ['only' => 'the easy part']);
        self::assertSame([], $house->lines(StandingLedger::WATCHED), 'a run nobody described is not written down as the watcher');
        self::assertStringContainsString('no sign of life', (string) ($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused'] ?? 'RAN'));
        // And were such a line in the book — written by an older house, or by hand — it would still not be read as one.
        $house->writtenByHand(StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => ['only' => 'the easy part'], 'fired' => false, 'who' => 'key:' . LabHouse::SEAT]);
        self::assertStringContainsString('no sign of life', (string) ($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused'] ?? 'RAN'));
        self::assertNull($house->book()->record('release-line')?->watcherSignalAt);
        // The same for the instrument: one receipt, from the run the condition describes.
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate, 'lenient' => true]);
        self::assertCount(1, $house->lines(StandingLedger::MEASURED));

        $house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame(['ok' => true], array_intersect_key($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]), ['ok' => true]));
    }

    // ── 11 · who may stop a grant ───────────────────────────────────────────────────────────────────────────

    public function testStoppingAGrantIsForAPrincipalTheHouseRecognizes(): void
    {
        $this->house->granted();
        // A client nobody authenticated arrives under a surface's default name, with nothing in hand.
        $anonymous = new ToolContext(principal: 'mcp', channel: 'mcp', scopes: []);

        $refused = $this->house->through($anonymous, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'because I can']);

        self::assertArrayHasKey('refused', $refused);
        self::assertSame([], $this->house->lines(StandingLedger::SUSPENDED));
        self::assertTrue($this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'a recognized seat still may'])['ok'] ?? false);
    }
}
