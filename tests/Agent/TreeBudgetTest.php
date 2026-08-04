<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TreeBudget;
use PHPUnit\Framework\TestCase;

/**
 * El contrato «presupuesto» de P19.3: un árbol de agentes sin techo es un gasto sin fondo.
 *
 * La profundidad ya era 1 por construcción —el hijo no tiene `agent_spawn`—, pero la ANCHURA no
 * estaba acotada por nada: un padre con techo de doce podía delegar diez veces y gastar ciento veinte.
 */
final class TreeBudgetTest extends TestCase
{
    /** Se descuenta lo GASTADO, no lo autorizado: un hijo que terminó pronto deja el resto a sus hermanos. */
    public function testItDiscountsWhatWasSpentAndNotWhatWasAuthorised(): void
    {
        $fondo = new TreeBudget(30);

        $fondo->anota(2);
        $fondo->anota(5);

        self::assertSame(23, $fondo->restante());
        self::assertNull($fondo->motivoParaNoDelegar(), 'con 23 pasos todavía se delega');
    }

    /**
     * En cero se NIEGA con el número adentro, y antes de gastar la vuelta del modelo.
     *
     * «No hay presupuesto» deja a quien delega adivinando si le queda algo para hacerlo por su cuenta.
     */
    public function testWhenTheFundRunsOutItRefusesWithTheNumberInside(): void
    {
        $fondo = new TreeBudget(10);
        $fondo->anota(9);

        $motivo = $fondo->motivoParaNoDelegar();

        self::assertIsString($motivo);
        self::assertStringContainsString('1 paso', $motivo, 'dice cuánto queda');
        self::assertStringContainsString('al menos 3', $motivo, 'y cuánto haría falta');
    }

    /**
     * UN HIJO QUE NO ALCANZA A LEER Y CONTESTAR NO SE LANZA.
     *
     * Gastaría una llamada al proveedor para producir un reporte vacío, que es peor que negarse: el
     * modo de fallo que Q-P19-R persiguió doce llamadas.
     */
    public function testItRefusesBeforeTheFundIsLiterallyEmpty(): void
    {
        $fondo = new TreeBudget(10);
        $fondo->anota(8);

        self::assertSame(2, $fondo->restante());
        self::assertIsString($fondo->motivoParaNoDelegar(), 'dos pasos no alcanzan para un sub-agente');
    }

    /** El techo del siguiente hijo es el suyo, o lo que quede si es menos. */
    public function testTheNextChildsCeilingIsWhicheverIsSmaller(): void
    {
        $fondo = new TreeBudget(30);

        self::assertSame(12, $fondo->techoParaElSiguiente(12), 'con fondo de sobra, el suyo');

        $fondo->anota(25);

        self::assertSame(5, $fondo->techoParaElSiguiente(12), 'con poco fondo, lo que queda');
    }

    /** Un fondo declarado en cero es «no delegues», no «sin techo». */
    public function testAZeroFundMeansDoNotDelegate(): void
    {
        $fondo = new TreeBudget(0);

        self::assertSame(0, $fondo->restante());
        self::assertIsString($fondo->motivoParaNoDelegar());
    }

    /** Gastar de más no deja un restante negativo, que se leería como crédito. */
    public function testOverspendingNeverReadsAsCredit(): void
    {
        $fondo = new TreeBudget(10);
        $fondo->anota(14);

        self::assertSame(0, $fondo->restante());
        self::assertSame(0, $fondo->techoParaElSiguiente(12));
    }
}
