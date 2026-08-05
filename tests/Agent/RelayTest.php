<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\Artifact\ArtifactCheck;
use Milpa\AppRuntime\Agent\Artifact\ArtifactRegistry;
use Milpa\AppRuntime\Agent\Relay\Relay;
use Milpa\AppRuntime\Agent\Relay\RelayRunner;
use Milpa\AppRuntime\Agent\Role\AgentRole;
use Milpa\AppRuntime\Agent\Role\RoleRegistry;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A relay: specialists in a fixed order, each handing the next a CHECKED baton.
 *
 * The artifact is the baton — handed over in person, checked at the handover, and if it drops the
 * race stops. And the order lives in the declaration, not in the model: asked explicitly to delegate,
 * a real model once spent 25 steps without calling `agent_spawn` at all. An order the model chooses
 * is an order nobody can reproduce.
 */
final class RelayTest extends TestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('parent', 'the big task', AutonomyMode::Auto);
    }

    private function roles(): RoleRegistry
    {
        return new RoleRegistry([
            new AgentRole('planner', 'You plan.', produces: 'plan', origin: 'test'),
            new AgentRole('implementer', 'You build.', produces: 'changes', origin: 'test'),
            new AgentRole('reviewer', 'You review.', produces: 'review', origin: 'test'),
        ]);
    }

    /** @var list<string> the brief each leg received, in order */
    private array $briefs = [];

    /** @param list<string> $answers what each leg's child returns, in order */
    private function runner(array $answers, ?RoleRegistry $roles = null): RelayRunner
    {
        $turn = 0;
        $this->briefs = [];

        return new RelayRunner(new SubAgentSpawner(
            $this->store,
            'parent',
            function (string $brief) use (&$turn, $answers): array {
                $this->briefs[] = $brief;

                return ['answer' => $answers[$turn++] ?? 'nothing', 'steps' => 1];
            },
            null,
            new ArtifactRegistry(),
            new ArtifactCheck(),
            $roles ?? $this->roles(),
        ));
    }

    private function threeLegs(): Relay
    {
        return Relay::named('add-feature')
            ->leg('plan', role: 'planner', brief: 'plan the feature')
            ->leg('build', role: 'implementer', takes: 'plan')
            ->leg('review', role: 'reviewer', takes: 'build');
    }

    /** The three legs run in the declared order, and each baton is kept under its leg's name. */
    public function testEveryLegRunsInOrderAndItsBatonIsKept(): void
    {
        $run = $this->runner([
            '{"goal":"ship it","steps":[{"what":"read the router"}]}',
            '{"changed":["src/Router.php"],"verified_with":["phpunit"]}',
            '{"verdict":"pass","reasons":[]}',
        ])->run($this->threeLegs());

        self::assertTrue($run['ok']);
        self::assertSame(['plan', 'build', 'review'], array_column($run['legs'], 'leg'));
        self::assertSame('plan', $run['artifacts']['plan']['kind']);
        self::assertSame('ship it', $run['artifacts']['plan']['payload']['goal']);
        self::assertSame('review', $run['artifacts']['review']['kind']);
    }

    /**
     * THE BATON REACHES THE NEXT LEG AS DATA, AND NAMED.
     *
     * Not as prose «the planner said…»: the receiving specialist has to read it by field, which is
     * the entire reason the artifact has a contract. And named, because a relay of four legs hands
     * over several and «the previous one» stops being an answer.
     */
    public function testTheBatonArrivesAsDataUnderTheNameOfTheLegThatProducedIt(): void
    {
        $this->runner([
            '{"goal":"ship it","steps":[{"what":"read the router"}]}',
            '{"changed":["src/Router.php"]}',
            '{"verdict":"pass","reasons":[]}',
        ])->run($this->threeLegs());

        self::assertStringNotContainsString('ship it', $this->briefs[0], 'the first leg receives no baton');
        self::assertStringContainsString('«plan» leg', $this->briefs[1], 'and the second is told which leg produced it');
        self::assertStringContainsString('"goal": "ship it"', $this->briefs[1], 'as data, readable by field');
    }

    /**
     * A DROPPED BATON STOPS THE RACE — and the run says where and why.
     *
     * Carrying on would let the next specialist work from nothing, which is how a relay reports four
     * confident stages built on a first one that failed.
     */
    public function testWhenALegDoesNotDeliverItsArtifactTheRelayStops(): void
    {
        $run = $this->runner([
            'I thought about it and I think we should start with the router.',
            'still prose',
        ])->run($this->threeLegs());

        self::assertFalse($run['ok']);
        self::assertSame('plan', $run['stopped_at']);
        self::assertCount(1, $run['legs'], 'the following legs did not run');
        self::assertSame([], $run['artifacts']);
    }

    /** And what DID happen comes back: a relay that stopped is a result, not an error. */
    public function testAStoppedRelayStillReportsEverythingThatHappened(): void
    {
        $run = $this->runner([
            '{"goal":"ship it","steps":[{"what":"x"}]}',
            'prose, no artifact',
            'prose again',
        ])->run($this->threeLegs());

        self::assertFalse($run['ok']);
        self::assertSame('build', $run['stopped_at']);
        self::assertArrayHasKey('plan', $run['artifacts'], 'the first baton survives');
        self::assertSame(['plan', 'build'], array_column($run['legs'], 'leg'));
    }

    /**
     * A ROLE WITH NO CONTRACT IS FINE UNTIL SOMEBODY IS WAITING ON ITS BATON.
     *
     * A specialist that declares no `produces` answers in prose, and that is legitimate — a last leg
     * whose result nobody consumes has nothing to drop. But the moment a later leg `takes` from it,
     * the relay stops: the next specialist would be starting from a baton that can never exist.
     */
    public function testALegWithNoContractOnlyStopsTheRelayWhenSomebodyTakesFromIt(): void
    {
        $roles = new RoleRegistry([
            new AgentRole('talker', 'You answer in prose.', origin: 'test'),
            new AgentRole('reviewer', 'You review.', produces: 'review', origin: 'test'),
        ]);

        // Nobody consumes it: prose is a valid answer and the relay finishes.
        $alone = $this->runner(['just some prose'], $roles)
            ->run(Relay::named('just-ask')->leg('note', role: 'talker'));
        self::assertTrue($alone['ok']);
        self::assertSame([], $alone['artifacts']);

        // Somebody does: the baton can never exist, so the race stops before the next leg runs.
        $consumed = $this->runner(['just some prose', '{"verdict":"pass","reasons":[]}'], $roles)
            ->run(Relay::named('ask-then-review')
                ->leg('note', role: 'talker')
                ->leg('review', role: 'reviewer', takes: 'note'));

        self::assertFalse($consumed['ok']);
        self::assertSame('note', $consumed['stopped_at']);
        self::assertCount(1, $consumed['legs'], 'the next leg did not start');
    }

    /**
     * A LEG THAT TAKES A BATON NOBODY PRODUCES IS REFUSED AT DECLARATION.
     *
     * It would look fine until the day it ran, and then fail somewhere the cause is not visible.
     * Declaring it is when the mistake is cheapest to see.
     */
    public function testALegThatTakesFromNowhereIsRefusedWhileBeingWritten(): void
    {
        $this->expectExceptionMessageMatches('/no earlier leg produces/');

        Relay::named('broken')
            ->leg('build', role: 'implementer', takes: 'plan');
    }

    /** And taking from a LATER leg is the same mistake: the baton has not been run for yet. */
    public function testALegCannotTakeFromOneThatRunsAfterIt(): void
    {
        $this->expectExceptionMessageMatches('/no earlier leg produces/');

        Relay::named('backwards')
            ->leg('build', role: 'implementer')
            ->leg('plan', role: 'planner')
            ->leg('again', role: 'implementer', takes: 'nope');
    }

    /** Two legs with the same name are refused: `takes` names a leg, so a duplicate is ambiguous. */
    public function testTwoLegsCannotShareAName(): void
    {
        $this->expectExceptionMessageMatches('/two legs called/');

        Relay::named('collide')
            ->leg('build', role: 'implementer')
            ->leg('build', role: 'reviewer');
    }

    /** An unnamed relay cannot be found in a log afterwards, so it is refused. */
    public function testARelayNeedsAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Relay::named('   ');
    }

    /** The declaration serialises whole, so a surface can show the race before it runs. */
    public function testTheDeclarationCanBeReadWithoutRunningIt(): void
    {
        self::assertSame([
            'name' => 'add-feature',
            'legs' => [
                ['name' => 'plan', 'role' => 'planner', 'takes' => null, 'brief' => 'plan the feature'],
                ['name' => 'build', 'role' => 'implementer', 'takes' => 'plan', 'brief' => null],
                ['name' => 'review', 'role' => 'reviewer', 'takes' => 'build', 'brief' => null],
            ],
        ], $this->threeLegs()->toArray());
    }

    /** An unknown role stops the race at that leg — refused by the registry, with the real names. */
    public function testAnUnknownRoleStopsTheRelayAtThatLeg(): void
    {
        $run = $this->runner(['{"goal":"x","steps":[{"what":"y"}]}'])
            ->run(Relay::named('typo')->leg('plan', role: 'planeador'));

        self::assertFalse($run['ok']);
        self::assertSame('plan', $run['stopped_at']);
        self::assertStringContainsString('planner', (string) $run['why'], 'and it names the ones that exist');
    }
}
