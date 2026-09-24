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
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\RunEnd;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A child session keeps runtime outcomes out of its assistant channel, exactly as the parent does.
 *
 * The parent loop stopped writing «step budget exhausted», a gate refusal or a context pause as
 * assistant turns, because a resumed model read them as its own previous answer and repeated the error
 * instead of continuing. The spawner that runs CHILDREN kept doing it — including a literal
 * «La vuelta falló: …» on every child that threw (greenhouse evidence/0996). A fix that is not swept
 * comes back through the path it did not reach.
 *
 * @guards the child's assistant channel holds model prose only
 *
 * @fires  on every agent_spawn and agent_resume of a child
 *
 * @refuses nothing — it withholds a turn, it does not refuse a call
 *
 * @subject-in milpa/app-runtime
 */
#[CoversClass(SubAgentSpawner::class)]
final class AChildKeepsRuntimeOutcomesOutOfItsVoiceTest extends TestCase
{
    private SessionStore $sessions;

    protected function setUp(): void
    {
        $this->sessions = new SessionStore(new InMemoryEventStore());
        $this->sessions->start('parent', 'the big task', AutonomyMode::Auto);
    }

    public function testARuntimeOutcomeIsNotRecordedAsTheChildsAnswer(): void
    {
        foreach ([RunEnd::StepsExhausted, RunEnd::ContextBudgetExhausted, RunEnd::ToolRefused, RunEnd::ProgressStalled, RunEnd::Blocked] as $end) {
            $child = $this->spawn(['answer' => 'the runtime said ' . $end->value, 'steps' => 3, 'termination' => $end->value]);

            self::assertSame([], $this->assistantTurns($child), "a «{$end->value}» outcome is not the child's voice");
        }
    }

    public function testWhatTheModelSaidIsStillRecorded(): void
    {
        // THE CONTROL: a rule that silenced every child would pass the test above and erase the one
        // thing the assistant channel exists to hold.
        foreach ([RunEnd::FinalAnswer, RunEnd::HouseDebt] as $end) {
            $child = $this->spawn(['answer' => 'the model wrote this', 'steps' => 2, 'termination' => $end->value]);

            self::assertSame(['the model wrote this'], $this->assistantTurns($child), "a «{$end->value}» is the model's own words");
        }
    }

    public function testAnOlderRunnerThatReportsNoTerminationKeepsThePreviousBehaviour(): void
    {
        $child = $this->spawn(['answer' => 'no termination reported', 'steps' => 1]);

        self::assertSame(['no termination reported'], $this->assistantTurns($child));
    }

    public function testAChildThatThrowsDoesNotSpeakItsOwnFailure(): void
    {
        $spawner = new SubAgentSpawner($this->sessions, 'parent', static function (): array {
            throw new \RuntimeException('the provider went away');
        });

        $result = ($spawner->operation()->handler)(['brief' => 'go']);

        self::assertFalse($result['ok'], 'the failure is still reported — to the parent');
        self::assertStringContainsString('the provider went away', (string) $result['error']);
        self::assertSame([], $this->assistantTurns((string) $result['sub_session']), '«La vuelta falló» is not the child speaking');
    }

    public function testTheValuesTheSpawnerComparesAreTheEnumsOwn(): void
    {
        // The spawner compares by VALUE so it never reaches for a class of an optional package. That
        // is only safe while the values match the enum — this is what keeps them from drifting.
        $model = (new \ReflectionClass(SubAgentSpawner::class))->getConstants();

        self::assertSame(RunEnd::FinalAnswer->value, $model['FINAL_ANSWER']);
        self::assertSame(RunEnd::HouseDebt->value, $model['HOUSE_DEBT']);
    }

    /** @param array<string, mixed> $run */
    private function spawn(array $run): string
    {
        $result = ((new SubAgentSpawner($this->sessions, 'parent', static fn (): array => $run))->operation()->handler)(['brief' => 'go']);
        self::assertIsString($result['sub_session'] ?? null, 'the child session was created');

        return $result['sub_session'];
    }

    /** @return list<string> */
    private function assistantTurns(string $child): array
    {
        return array_values(array_map(
            static fn (array $turn): string => $turn['content'],
            array_filter($this->sessions->load($child)?->turns ?? [], static fn (array $turn): bool => $turn['role'] === 'assistant'),
        ));
    }
}
