<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse evidence/0172 froze before this could be silenced.
 *
 * The system prompt told the agent, in three lines, to write a plan with `plan` and add a `todo` per
 * part — and in an app where those tools are not registered, they do not travel. Measured on the
 * wire: twenty-eight tools and neither of the two. The agent was left with an impossible order and
 * the measurement described it as disobedient, zero plans in twenty calls, when the cause was that
 * it could not.
 *
 * The second case is the control, and it decides whether this fixed an impossible order or deleted a
 * capability: where the tools DO travel, the instruction has to stay.
 */
final class PlanInstructionTest extends TestCase
{
    /** 1 · without the tools, the prompt does not order a plan. */
    public function testWithoutTheToolsTheOrderIsNotGiven(): void
    {
        $prompt = $this->promptCon([]);

        self::assertStringNotContainsString('`plan`', $prompt);
        self::assertStringNotContainsString('`todo`', $prompt);
    }

    /**
     * 2 · THE CONTROL: with the tools travelling, the instruction stays.
     *
     * If silencing the order also silenced apps that do have the tools, nothing was fixed — a
     * capability was deleted.
     */
    public function testWithTheToolsTheInstructionStays(): void
    {
        $prompt = $this->promptCon(['plan', 'todo', 'plugins_list']);

        self::assertStringContainsString('`plan`', $prompt);
        self::assertStringContainsString('`todo`', $prompt);
    }

    /** 3 · one of the two is not enough: the order names both, so both have to be there. */
    public function testOneOfTheTwoIsNotEnough(): void
    {
        self::assertStringNotContainsString('`plan`', $this->promptCon(['plan']));
        self::assertStringNotContainsString('`todo`', $this->promptCon(['todo']));
    }

    /** 4 · the rest of the prompt is the same either way — this silences one order, not the doctrine. */
    public function testTheRestOfThePromptIsUnchanged(): void
    {
        $sin = $this->promptCon([]);
        $con = $this->promptCon(['plan', 'todo']);

        self::assertStringContainsString('You are the agent of this Milpa app', $sin);
        self::assertStringContainsString('You are the agent of this Milpa app', $con);
        self::assertLessThan(mb_strlen($con), mb_strlen($sin), 'callar la orden tiene que quitar texto');
    }

    /** @param list<string> $herramientas */
    private function promptCon(array $herramientas): string
    {
        $ops = new AgentOperations(new DIContainer());

        $m = new \ReflectionMethod($ops, 'systemPrompt');
        $m->setAccessible(true);

        return (string) $m->invoke($ops, $herramientas);
    }
}
