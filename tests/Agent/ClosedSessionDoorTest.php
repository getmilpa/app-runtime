<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Session;
use Milpa\AppRuntime\Agent\ClosedSessionDoor;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What «nothing new was asked» means, read from the stream (greenhouse decisions/0529) — and that it never hides a
 * request: an allow list, so what it does not know reaches the model.
 */
final class ClosedSessionDoorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function continuations(): iterable
    {
        foreach (['continue', 'Continue', '  continue.  ', 'CONTINUE!', 'continue?', 'continue…', 'go on', 'Keep  going', 'continúa', 'Continua.', 'sigue'] as $said) {
            yield $said => [$said];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function requests(): iterable
    {
        yield 'a new ask after the word' => ['continue with comments on each post'];
        yield 'a question' => ['is it done?'];
        yield 'another verb' => ['verify /blog again'];
        yield 'two lines' => ["continue\nand add tags"];
        yield 'a word around it' => ['please continue'];
        yield 'a grant notice' => ['[house] Rod granted plugins.BlogPlugin:write'];
        yield 'empty' => [''];
        yield 'punctuation alone' => ['...'];
        yield 'carry on is not in the list' => ['carry on'];
        yield 'proceed is not in the list' => ['proceed'];
    }

    #[DataProvider('continuations')]
    public function testAPureContinuationAsksNothing(string $said): void
    {
        self::assertTrue(ClosedSessionDoor::isContinuation($said));
    }

    #[DataProvider('requests')]
    public function testAnythingElseIsARequest(string $said): void
    {
        self::assertFalse(ClosedSessionDoor::isContinuation($said));
    }

    public function testTheLegAsksNothingOnlyWithQuietArguments(): void
    {
        self::assertTrue(ClosedSessionDoor::legAsksNothing(['prompt' => 'continue', 'session' => 's', 'steps' => 8, 'mode' => 'auto']));
        foreach (['grant' => 'x', 'first' => 'plan', 'deny' => 'x', 'denyEffects' => 'x', 'delivery' => '{}', 'expectation' => '{}',
            'deliveryCandidate' => 'wabc', 'diagnostic' => '{}', 'something-new' => 1] as $key => $value) {
            self::assertFalse(ClosedSessionDoor::legAsksNothing(['prompt' => 'continue', 'session' => 's', $key => $value]), $key);
        }
        self::assertFalse(ClosedSessionDoor::legAsksNothing(['prompt' => 'add tags', 'session' => 's']));
        self::assertFalse(ClosedSessionDoor::legAsksNothing(['session' => 's']));
    }

    public function testChangingTheModeIsSomethingNew(): void
    {
        $session = new Session('s', 'goal', mode: AutonomyMode::Auto);

        self::assertTrue(ClosedSessionDoor::keepsTheMode(['prompt' => 'continue'], $session));
        self::assertTrue(ClosedSessionDoor::keepsTheMode(['prompt' => 'continue', 'mode' => 'auto'], $session));
        self::assertFalse(ClosedSessionDoor::keepsTheMode(['prompt' => 'continue', 'mode' => 'ask'], $session));
    }

    public function testAVerifiedVerdictFollowedOnlyByQuietEventsStands(): void
    {
        $stream = $this->stream(
            ['session.started', ['goal' => 'Build the blog']],
            [ClosureVerdict::EVENT, ['verified' => true, 'reasons' => []]],
            ['session.compacted', ['summary' => 's']],
            ['session.turn', ['role' => 'user', 'content' => 'continue']],
            ['session.window_composed', ['tokens' => 1]],
            ['session.mode_changed', ['mode' => 'auto']],
            ['session.sequence_authorized', []],
            ['session.authorization_cited', []],
            ['session.authorization_released', []],
            ['session.ownership_asserted', []],
            [ClosedSessionDoor::EVENT, ['prompt' => 'continue', 'closureSeq' => 2]],
        );

        self::assertSame(2, ClosedSessionDoor::standingVerdict($stream));
    }

    public function testTheLastVerdictDecidesNotAnEarlierOne(): void
    {
        self::assertNull(ClosedSessionDoor::standingVerdict($this->stream(
            [ClosureVerdict::EVENT, ['verified' => true, 'reasons' => []]],
            [ClosureVerdict::EVENT, ['verified' => false, 'reasons' => ['1 todo open']]],
        )), 'the last verdict is not verified');
        self::assertSame(2, ClosedSessionDoor::standingVerdict($this->stream(
            [ClosureVerdict::EVENT, ['verified' => false, 'reasons' => ['1 todo open']]],
            [ClosureVerdict::EVENT, ['verified' => true, 'reasons' => []]],
        )));
        self::assertNull(ClosedSessionDoor::standingVerdict($this->stream(['session.started', ['goal' => 'g']])), 'no verdict at all');
        self::assertNull(ClosedSessionDoor::standingVerdict($this->stream(
            [ClosureVerdict::EVENT, ['verified' => 'yes', 'reasons' => []]],
        )), 'verified means true, nothing that looks like it');
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function reopeners(): iterable
    {
        yield 'a new human turn' => ['session.turn', ['role' => 'user', 'content' => 'now add comments']];
        yield 'the grant notice turn' => ['session.turn', ['role' => 'user', 'content' => '[house] granted plugins.BlogPlugin:write']];
        yield 'an assistant turn' => ['session.turn', ['role' => 'assistant', 'content' => 'continue']];
        yield 'a new goal' => ['session.goal_changed', ['goal' => 'Build the shop']];
        yield 'a parked question answered' => ['session.question_answered', ['id' => 'q', 'answer' => 'yes']];
        yield 'a grant' => ['session.permission_granted', ['operation' => 'plugins.register']];
        yield 'a tool call' => ['session.tool_called', ['tool' => 'plugins_register']];
        yield 'a model call' => ['session.model_called', []];
        yield 'a question asked' => ['session.question_asked', ['id' => 'q']];
        yield 'an operation executed' => ['session.operation_executed', ['operation' => 'sandbox.promote']];
        yield 'a type nobody listed' => ['session.something_new', []];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('reopeners')]
    public function testAnythingElseAfterTheVerdictReopensIt(string $type, array $payload): void
    {
        self::assertNull(ClosedSessionDoor::standingVerdict($this->stream(
            [ClosureVerdict::EVENT, ['verified' => true, 'reasons' => []]],
            ['session.turn', ['role' => 'user', 'content' => 'continue']],
            [$type, $payload],
            ['session.turn', ['role' => 'user', 'content' => 'continue']],
        )));
    }

    public function testTheAnswerSaysWhatWasObservedAndWhatReopensIt(): void
    {
        $answer = ClosedSessionDoor::answer(344, ['verified' => true, 'derivedFrom' => ['observation' => ['subject' => '/blog', 'seq' => 315], 'lastChangeSeq' => 315]]);

        self::assertStringContainsString('seq 344', $answer);
        self::assertStringContainsString('It observed /blog served at seq 315', $answer);
        self::assertStringContainsString('no model was called', $answer);
        self::assertStringContainsString('set a new goal', $answer);
        self::assertStringNotContainsString('observed', ClosedSessionDoor::answer(9, ['verified' => true]));
        self::assertStringNotContainsString('observed', ClosedSessionDoor::answer(9, ['verified' => true, 'derivedFrom' => ['observation' => '/blog']]));
    }

    public function testItsFactIsRecordedOnTheSessionsStream(): void
    {
        $events = new InMemoryEventStore();
        ClosedSessionDoor::record($events, 's', 'continue', 7);

        $recorded = $events->replay('agent-session:s');
        self::assertCount(1, $recorded);
        self::assertSame(ClosedSessionDoor::EVENT, $recorded[0]->type);
        self::assertSame(['prompt' => 'continue', 'closureSeq' => 7], $recorded[0]->payload);
    }

    /**
     * @param array{string, array<string, mixed>} ...$events
     *
     * @return list<Event>
     */
    private function stream(array ...$events): array
    {
        $seq = 0;

        return array_map(static function (array $e) use (&$seq): Event {
            return new Event('agent-session:s', $e[0], $e[1], ++$seq);
        }, array_values($events));
    }
}
