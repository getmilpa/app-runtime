<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\PrerequisiteGate;
use PHPUnit\Framework\TestCase;

/**
 * La obligación de ORDEN, ejecutada — la única pregunta que la serie Q-P20 dejó abierta.
 *
 * `deny` cubre lo que se puede quitar y se cumplió 8/8; `must` entrega lo que sólo se puede pedir y
 * gobernó 0/8. Esto pone a prueba que «planea antes de empezar» no era irreducible: es «no empieces
 * sin plan», y por eso admite la misma traducción.
 */
final class PrerequisiteGateTest extends TestCase
{
    /** Sin obligación, la mesa está abierta — es el comportamiento de siempre y no se paga nada. */
    public function testWithoutAnObligationNothingIsHeldBack(): void
    {
        $compuerta = new PrerequisiteGate();

        self::assertNull($compuerta->motivoParaEsperar('make'));
    }

    /** Con obligación pendiente, lo demás NO procede — y la negativa dice qué falta. */
    public function testWhatIsRequiredFirstHoldsBackEverythingElse(): void
    {
        $compuerta = new PrerequisiteGate(['plan']);

        $motivo = $compuerta->motivoParaEsperar('make');

        self::assertIsString($motivo);
        self::assertStringContainsString('plan', $motivo, 'la negativa nombra lo que falta');
        self::assertNull($compuerta->motivoParaEsperar('plan'), 'lo obligado sí procede, o no se podría cumplir');
    }

    /** Y en cuanto corre, la mesa abre. */
    public function testTheTableOpensOnceTheObligationHasRun(): void
    {
        $compuerta = new PrerequisiteGate(['plan']);
        $compuerta->anota('plan', true);

        self::assertNull($compuerta->motivoParaEsperar('make'));
        self::assertSame([], $compuerta->pendientes());
    }

    /**
     * UN INTENTO NO ES EL HECHO.
     *
     * Un `plan` que falló no dejó plan. Abrir con eso sería tratar la llamada como su efecto — el
     * certificado sustituto que la revisión de P-0001 tardó siete generaciones en matar, aquí en
     * miniatura.
     */
    public function testAFailedAttemptDoesNotSatisfyTheObligation(): void
    {
        $compuerta = new PrerequisiteGate(['plan']);
        $compuerta->anota('plan', false);

        self::assertIsString($compuerta->motivoParaEsperar('make'));
        self::assertSame(['plan'], $compuerta->pendientes());
    }

    /** Varias obligaciones se enumeran, y cada una se descuenta por su cuenta. */
    public function testSeveralObligationsAreNamedAndDiscountedOneByOne(): void
    {
        $compuerta = new PrerequisiteGate(['plan', 'todo']);

        self::assertStringContainsString('plan', (string) $compuerta->motivoParaEsperar('make'));
        self::assertStringContainsString('todo', (string) $compuerta->motivoParaEsperar('make'));

        $compuerta->anota('plan', true);

        self::assertSame(['todo'], $compuerta->pendientes());
        self::assertIsString($compuerta->motivoParaEsperar('make'), 'todavía falta una');

        $compuerta->anota('todo', true);

        self::assertNull($compuerta->motivoParaEsperar('make'));
    }

    /**
     * CUMPLIR NO SE DESHACE.
     *
     * Si un `plan` posterior fallara, la mesa no se vuelve a cerrar: el plan de la primera vez sigue
     * escrito. Una compuerta que se re-cierra convertiría un fallo transitorio en un hijo que ya no
     * puede trabajar y no sabe por qué.
     */
    public function testOnceSatisfiedItDoesNotCloseAgain(): void
    {
        $compuerta = new PrerequisiteGate(['plan']);
        $compuerta->anota('plan', true);
        $compuerta->anota('plan', false);

        self::assertNull($compuerta->motivoParaEsperar('make'));
    }
}
