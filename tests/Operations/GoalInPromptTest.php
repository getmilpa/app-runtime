<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The system prompt speaks for the session's standing goal, and in the automatic mode it says what
 * the goal buys — and what it never buys (greenhouse decisions/0202).
 *
 * The doctrine boundary this battery guards: `AutonomyMode` says «auto means "do not ask me about
 * the reversible", never "do not ask me"». A signature and a third-party egress stop in every mode,
 * and neither the goal nor the mode pre-consents them. The prompt that tells the model to stop
 * asking must say that in the same breath, or a model reads «act» as «act on everything».
 */
final class GoalInPromptTest extends TestCase
{
    private const GOAL = 'write the todo-app plugin';

    /** 1 · without a session, nothing about a goal is said — the prompt of a sessionless run is untouched. */
    public function testWithoutASessionNoGoalIsSpokenFor(): void
    {
        $prompt = $this->promptFor(null);

        self::assertStringNotContainsString('standing goal', $prompt);
        self::assertStringNotContainsString('automatic mode', $prompt);
    }

    /** 2 · with a goal in `ask`, the goal is stated and the auto instruction is NOT given. */
    public function testTheGoalIsStatedAndAskModeDoesNotStopTheQuestions(): void
    {
        $prompt = $this->promptFor($this->session(self::GOAL, AutonomyMode::Ask));

        self::assertStringContainsString('The standing goal of this session, declared by the human: ' . self::GOAL, $prompt);
        self::assertStringContainsString('standing ask', $prompt);
        self::assertStringNotContainsString('automatic mode', $prompt);
        self::assertStringNotContainsString('Do not ask about anything', $prompt);
    }

    /** 3 · `acknowledge` is not `auto`: the goal is stated, the instruction to stop asking is not. */
    public function testAcknowledgeIsNotAutoEither(): void
    {
        $prompt = $this->promptFor($this->session(self::GOAL, AutonomyMode::Acknowledge));

        self::assertStringContainsString(self::GOAL, $prompt);
        self::assertStringNotContainsString('automatic mode', $prompt);
    }

    /**
     * 4 · goal + auto: the explicit instruction — act on what the goal entails, ask only outside it —
     * AND the doctrine sentence in the same part: signatures and third-party egress still stop, and
     * nothing pre-consents them.
     */
    public function testGoalPlusAutoStopsTheQuestionsTheGoalEntailsAndRestatesWhatNothingPreConsents(): void
    {
        $prompt = $this->promptFor($this->session(self::GOAL, AutonomyMode::Auto));

        self::assertStringContainsString(self::GOAL, $prompt);
        self::assertStringContainsString('chose the automatic mode', $prompt);
        self::assertStringContainsString('Do not ask about anything that reaching the goal necessarily entails', $prompt);
        self::assertStringContainsString('Ask only about what lies outside the goal', $prompt);
        // The boundary, in the same part as the instruction — never in another paragraph the model
        // could read apart from it.
        self::assertStringContainsString('requires a signature', $prompt);
        self::assertStringContainsString('reaches a third party', $prompt);
        self::assertStringContainsString('nothing pre-consents them', $prompt);
    }

    /** 5 · a cleared goal in `auto` buys nothing: no goal, no instruction — auto alone never stopped a question here. */
    public function testAClearedGoalInAutoSaysNothing(): void
    {
        $prompt = $this->promptFor($this->session('', AutonomyMode::Auto));

        self::assertStringNotContainsString('standing goal', $prompt);
        self::assertStringNotContainsString('automatic mode', $prompt);
    }

    /** 6 · the goal spoken for is the FOLDED one: after `setGoal`, the prompt carries the latest, not the opening prompt. */
    public function testThePromptCarriesTheLatestGoalOfTheFold(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'the opening prompt');
        $store->setGoal('s1', self::GOAL);
        $session = $store->load('s1');
        self::assertInstanceOf(Session::class, $session);

        $prompt = $this->promptFor($session);

        self::assertStringContainsString(self::GOAL, $prompt);
        self::assertStringNotContainsString('the opening prompt', $prompt);
    }

    /** 7 · the control: the rest of the prompt is the same either way — this adds one part, it rewrites nothing. */
    public function testTheRestOfThePromptIsUnchanged(): void
    {
        $without = $this->promptFor(null);
        $with = $this->promptFor($this->session(self::GOAL, AutonomyMode::Auto));

        self::assertStringContainsString('You are the agent of this Milpa app', $without);
        self::assertStringContainsString('You are the agent of this Milpa app', $with);
        self::assertStringContainsString('How this app is built:', $without);
        self::assertStringContainsString('How this app is built:', $with);
        self::assertGreaterThan(mb_strlen($without), mb_strlen($with), 'the goal part adds text');
    }

    /** A session folded from a real store over an in-memory stream, with the goal and mode given. */
    private function session(string $goal, AutonomyMode $mode): Session
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'the opening prompt', $mode);
        $store->setGoal('s1', $goal);
        $session = $store->load('s1');
        self::assertInstanceOf(Session::class, $session);
        self::assertSame($goal, $session->goal);
        self::assertSame($mode, $session->mode);

        return $session;
    }

    private function promptFor(?Session $session): string
    {
        $ops = new AgentOperations(new DIContainer());

        $m = new \ReflectionMethod($ops, 'systemPrompt');
        $m->setAccessible(true);

        return (string) $m->invoke($ops, [], $session);
    }
}
