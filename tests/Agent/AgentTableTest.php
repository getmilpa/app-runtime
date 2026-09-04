<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\AgentTable;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Operation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use PHPUnit\Framework\TestCase;

/**
 * What is actually on the agent's table, asked once.
 *
 * Measured on cattle: withdrawing by effect class declared thirteen removals while eight tools
 * stopped travelling. The five phantoms were never on the table — three of them kept off it on
 * purpose. The rule that decides already existed and was split in two, and the matcher called
 * neither: the same app deciding twice what is on the table, one of the two times not knowing half
 * the rule.
 */
final class AgentTableTest extends TestCase
{
    private function operation(string $name, ?array $surfaces, bool $mutating = true): Operation
    {
        return new Operation(
            name: $name,
            effects: new EffectProfile(
                $mutating ? Mutation::Persistent : Mutation::None,
                Externality::None,
                Reversibility::Guaranteed,
                $mutating ? Authority::WriteAsUser : Authority::Read,
                subject: Subject::None,
                // La casa exige que una reversibilidad garantizada nombre qué la respalda: una
                // afirmación que baja el escrutinio no puede certificarse sola. Aplica también a un
                // fixture — y que el framework rechace el mío es el invariante funcionando.
                rollbackContract: 'nothing-to-roll-back',
            ),
            description: 'x',
            handler: static fn (): array => ['ok' => true],
            inputSchema: ['type' => 'object'],
            mutating: $mutating,
            surfaces: $surfaces,
        );
    }

    public function testAnOperationThatDidNotOptIntoTheSurfaceIsNotOnTheTable(): void
    {
        self::assertFalse(AgentTable::offers($this->operation('agent', ['cli'])));
    }

    public function testAnOperationThatDidOptInIs(): void
    {
        self::assertTrue(AgentTable::offers($this->operation('config:set', ['cli', 'mcp'])));
    }

    /**
     * The three that adjudicate are off the table on purpose: answering the question that paused a
     * session, changing how much autonomy it has, and closing it are not tools OF the session that is
     * waiting. Withdrawing them would record a removal for something the agent never held.
     */
    public function testTheOnesThatAdjudicateAreNotOnItEitherHoweverTheyDeclareThemselves(): void
    {
        // `agent:goal` sits with them (greenhouse decisions/0202): the goal is the standing ask the
        // gate compares targets against, and a session must not widen its own.
        foreach (['agent:answer', 'agent:mode', 'agent:goal', 'agent:discard'] as $nombre) {
            self::assertFalse(
                AgentTable::offers($this->operation($nombre, ['cli', 'tui', 'mcp'])),
                "{$nombre} adjudica y no es herramienta de la sesión que espera",
            );
        }
    }

    /**
     * THE CONTROL, and it points at the dangerous side.
     *
     * A fix that narrows too far would contain LESS while saying nothing — the same harm as the
     * defect it repairs, with better intentions. An ordinary mutating operation that opted into the
     * surface must stay a candidate for withdrawal.
     */
    public function testAnOrdinaryMutatingOperationStaysWithdrawable(): void
    {
        self::assertTrue(AgentTable::offers($this->operation('plugins:enable', ['cli', 'mcp'])));
        self::assertTrue(AgentTable::offers($this->operation('foundation:found', ['mcp'])));
    }

    /**
     * `surfaces: null` means every surface — the projector's rule, called rather than guessed. An
     * empty list is the opposite and must not be confused with it: it names no surface at all.
     */
    public function testNamingNoSurfaceAtAllIsNotTheSameAsNamingThemAll(): void
    {
        self::assertTrue(AgentTable::offers($this->operation('make', null)));
        self::assertFalse(AgentTable::offers($this->operation('make', [])));
    }
}
