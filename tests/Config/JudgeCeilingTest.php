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

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\JudgeCeiling;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * The rule greenhouse decisions/0027 decided, exercised where it has to hold.
 *
 * The second case is the control and it carries the whole argument: adding a MILDER operation must
 * not lower the borrowed ceiling. A fold that averaged, or that took the last value, would pass the
 * first case and quietly hand the judge's editor a lighter ceiling than the worst thing it enables
 * — which is the key that opens its own lock.
 */
final class JudgeCeilingTest extends TestCase
{
    /** 1 · the borrowed ceiling is the heaviest of what the criterion governs. */
    public function testItBorrowsTheHeaviestCeilingItGoverns(): void
    {
        $techo = JudgeCeiling::prestado([
            $this->op('reads', new EffectProfile(mutation: Mutation::None, authority: Authority::Read, subject: Subject::None)),
            $this->op('installs', new EffectProfile(mutation: Mutation::Persistent, authority: Authority::Privileged, subject: Subject::Executable)),
        ]);

        self::assertSame(Subject::Executable, $techo->subject);
        self::assertSame(Authority::Privileged, $techo->authority);
    }

    /**
     * 2 · THE CONTROL: a milder operation cannot lower it.
     *
     * join() is monotonic by construction, and this is what proves the fold uses it rather than
     * something that looks like it.
     */
    public function testAMilderOperationDoesNotLowerIt(): void
    {
        $pesada = $this->op('installs', new EffectProfile(mutation: Mutation::Persistent, authority: Authority::Privileged, subject: Subject::Executable));
        $suave = $this->op('reads', new EffectProfile(mutation: Mutation::None, authority: Authority::Read, subject: Subject::None));

        $solaLaPesada = JudgeCeiling::prestado([$pesada]);
        $conLaSuaveAlFinal = JudgeCeiling::prestado([$pesada, $suave]);

        self::assertSame($solaLaPesada->subject, $conLaSuaveAlFinal->subject);
        self::assertSame($solaLaPesada->authority, $conLaSuaveAlFinal->authority);
    }

    /** 3 · a criterion governing nothing known is not free — GOV-05 makes it unbounded. */
    public function testAnEmptyCatalogueIsUnclassifiedRatherThanHarmless(): void
    {
        self::assertSame(Subject::Unknown, JudgeCeiling::prestado([])->subject);
    }

    /** 4 · which keys borrow, and the one that deliberately does not. */
    public function testOnlyTheJudgesCriteriaBorrow(): void
    {
        self::assertTrue(JudgeCeiling::esCriterioDelJuez('agent.transitions.frontier'));
        self::assertFalse(JudgeCeiling::esCriterioDelJuez('agent.instructions'));
        self::assertFalse(
            JudgeCeiling::esCriterioDelJuez('agent.permissionWindow'),
            'decisions/0027 left it undecided on purpose: it moves a consequence, not a verdict',
        );
    }

    /** 5 · and the payoff — under S2, editing that criterion demands consent. */
    public function testEditingTheCriterionLandsOnTheConsentSideOfS2(): void
    {
        $techo = JudgeCeiling::prestado([
            $this->op('installs', new EffectProfile(mutation: Mutation::Persistent, authority: Authority::Privileged, subject: Subject::Executable)),
        ]);

        $s2 = \in_array($techo->subject, [Subject::Executable, Subject::Unknown], true)
            && $techo->authority === Authority::Privileged;

        self::assertTrue($s2, 'the borrowed ceiling has to reach S2, or the rule changed no decision');
    }

    private function op(string $name, EffectProfile $effects): Operation
    {
        return new Operation(
            name: $name,
            description: 'una operación del catálogo que este criterio puede habilitar',
            handler: static fn (): array => [],
            effects: $effects,
        );
    }
}
