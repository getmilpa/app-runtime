<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\SessionOptionTable;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * La mesa de una sesión, sostenida por su stream.
 *
 * Lo que se fija aquí no es que filtre —eso vive probado en `milpa/ai-gateway`— sino que quitar
 * APENDE y que leer VUELVE A REDUCIR. Una mesa que viviera en memoria se vería idéntica en una prueba
 * de un solo proceso y se perdería al retomar la sesión mañana, que es justo cuando importa.
 */
final class SessionOptionTableTest extends TestCase
{
    private function almacen(): SessionStore
    {
        return new SessionStore(new InMemoryEventStore());
    }

    /** Quitar una opción es un hecho del stream, no un arreglo en memoria. */
    public function testRemovingAnOptionAppendsItToTheStream(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'lo que sea');

        (new SessionOptionTable($almacen, 's1'))->remove('plugins_disable', 'beyond_request', 'sólo preguntabas');

        self::assertSame(['plugins_disable'], $almacen->load('s1')?->removedOptions);
    }

    /**
     * LEER VUELVE A REDUCIR EL STREAM, siempre.
     *
     * Si esta clase capturara el estado al construirse, el catálogo quedaría congelado en ese momento y
     * retirar una opción no cambiaría nada de lo que el modelo ve. Por eso la misma instancia tiene que
     * contestar distinto después de un `remove`.
     */
    public function testTheTableIsReadFromTheStreamAgainAndNotRemembered(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'lo que sea');

        $mesa = new SessionOptionTable($almacen, 's1');
        self::assertSame([], $mesa->removed());

        $mesa->remove('plugins_disable', 'beyond_request');

        self::assertSame(['plugins_disable'], $mesa->removed(), 'la misma instancia tiene que ver el hecho nuevo');
    }

    /**
     * La mesa sobrevive al objeto que la quitó — porque el estado no vive en él.
     *
     * Es la prueba que separa «un hecho de la sesión» de «un arreglo en memoria»: una instancia nueva,
     * sin haber quitado nada, encuentra la misma mesa.
     */
    public function testAFreshTableOverTheSameSessionSeesWhatAnotherRemoved(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'lo que sea');

        (new SessionOptionTable($almacen, 's1'))->remove('plugins_disable', 'beyond_request');

        self::assertSame(['plugins_disable'], (new SessionOptionTable($almacen, 's1'))->removed());
    }

    /**
     * Una sesión que no existe devuelve la mesa completa, no un error.
     *
     * Quedarse sin catálogo por un fallo de lectura sería apagar al agente, y eso ya se midió peor que
     * correr sin la mejora: un agente que no toca nada porque no hace nada no es más seguro (Q-P17-G).
     */
    public function testAnUnknownSessionRemovesNothing(): void
    {
        self::assertSame([], (new SessionOptionTable($this->almacen(), 'no-existe'))->removed());
    }

    /**
     * EL BRAZO G: el hecho se apenda y la proyección no se entera.
     *
     * Es el control que le faltó al cierre de Q-P19-H. Aquel brazo cambiaba dos cosas a la vez —quitar
     * la opción y dejar seguir la vuelta— así que su 16/16 de observación no tenía dueño. Con esto, la
     * vuelta sigue igual (`wasRemoved` cierto) y la mesa NO cambia (`removed` vacío), que aísla la
     * variable.
     */
    public function testTheRecordOnlyTableAppendsTheFactAndLeavesTheProjectionUntouched(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'lo que sea');

        $mesa = new \Milpa\AppRuntime\Agent\RecordOnlyOptionTable(new SessionOptionTable($almacen, 's1'));
        $mesa->remove('plugins_disable', 'beyond_request');

        self::assertSame(
            ['plugins_disable'],
            $almacen->load('s1')?->removedOptions,
            'el stream conserva el hecho — sin él no habría denominador que contar',
        );
        self::assertTrue($mesa->wasRemoved('plugins_disable'), 'el hecho es cierto, así que la vuelta sigue');
        self::assertSame([], $mesa->removed(), 'y la mesa que ve el modelo no cambia');
    }
}
