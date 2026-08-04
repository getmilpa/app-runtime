<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\SterileLoopGuard;
use PHPUnit\Framework\TestCase;

/**
 * El vigía del bucle estéril (Q-P19-R).
 *
 * Lo que se prueba aquí es la distinción que decide todo: repetir algo que FALLÓ no es lo mismo que
 * repetir algo que funcionó, ni que insistir cambiando los argumentos. Un detector que no separe esas
 * tres cosas estorba trabajo legítimo — y ése es el falsificador 2 del pre-registro.
 */
final class SterileLoopGuardTest extends TestCase
{
    private function falla(string $error = 'para «plugin» los dos argumentos nombran el mismo artefacto'): string
    {
        return json_encode(['ok' => false, 'error' => $error], \JSON_UNESCAPED_UNICODE) ?: '';
    }

    /** Con la tolerancia en dos, la tercera idéntica no se ejecuta — y el motivo dice qué falló. */
    public function testTheThirdIdenticalFailingCallIsStopped(): void
    {
        $vigia = new SterileLoopGuard();
        $args = ['what' => 'plugin', 'plugin' => 'Hola', 'name' => 'HolaPlugin'];

        self::assertNull($vigia->motivoParaNoRepetir('make', $args), 'la primera no se toca');
        $vigia->anota('make', $args, $this->falla(), true);

        self::assertNull($vigia->motivoParaNoRepetir('make', $args), 'la segunda tampoco: insistir una vez es legítimo');
        $vigia->anota('make', $args, $this->falla(), true);

        $motivo = $vigia->motivoParaNoRepetir('make', $args);
        self::assertNotNull($motivo, 'la tercera sí');
        self::assertStringContainsString('make', $motivo);
        self::assertStringContainsString('dos veces', $motivo);
        self::assertStringContainsString('nombran el mismo artefacto', $motivo, 'con el error que la herramienta ya dijo');
    }

    /**
     * REPETIR ALGO QUE FUNCIONÓ NO ES UN BUCLE ESTÉRIL.
     *
     * Listar dos veces, releer un archivo, verificar después de escribir: son trabajo, no
     * repetición. Un vigía que los cortara volvería inútil la observación.
     */
    public function testRepeatingSomethingThatWorkedIsNeverStopped(): void
    {
        $vigia = new SterileLoopGuard();
        $ok = json_encode(['ok' => true, 'plugins' => []]) ?: '';

        for ($i = 0; $i < 5; ++$i) {
            $vigia->anota('plugins_list', [], $ok, true);
            self::assertNull($vigia->motivoParaNoRepetir('plugins_list', []));
        }
    }

    /** Cambiar los argumentos ES corregir: la cuenta arranca de cero para la llamada nueva. */
    public function testChangingTheArgumentsStartsOver(): void
    {
        $vigia = new SterileLoopGuard();
        $malos = ['what' => 'plugin', 'plugin' => 'Hola', 'name' => 'HolaPlugin'];
        $vigia->anota('make', $malos, $this->falla(), true);
        $vigia->anota('make', $malos, $this->falla(), true);

        $buenos = ['what' => 'plugin', 'plugin' => 'HolaPlugin', 'name' => 'HolaPlugin'];
        self::assertNull($vigia->motivoParaNoRepetir('make', $buenos), 'corregir no se castiga');
        self::assertNotNull($vigia->motivoParaNoRepetir('make', $malos), 'y la vieja sigue vetada');
    }

    /** El orden de las llaves no hace distinta a una llamada: se compara el contenido, no el JSON. */
    public function testTheKeyOrderDoesNotDisguiseARepetition(): void
    {
        $vigia = new SterileLoopGuard();
        $vigia->anota('make', ['a' => 1, 'b' => 2], $this->falla(), true);
        $vigia->anota('make', ['b' => 2, 'a' => 1], $this->falla(), true);

        self::assertNotNull($vigia->motivoParaNoRepetir('make', ['a' => 1, 'b' => 2]));
    }

    /**
     * UN ÉXITO BORRA LA CUENTA: lo que falló dos veces y luego funcionó no está en bucle.
     *
     * Sin esto, un fallo transitorio —una base ocupada, un archivo en uso— dejaría la llamada vetada
     * para siempre en la sesión, que es peor que el problema que se vino a resolver.
     */
    public function testASuccessForgetsThePastFailures(): void
    {
        $vigia = new SterileLoopGuard();
        $args = ['x' => 1];
        $vigia->anota('make', $args, $this->falla(), true);
        $vigia->anota('make', $args, $this->falla(), true);
        $vigia->anota('make', $args, json_encode(['ok' => true]) ?: '', true);

        self::assertNull($vigia->motivoParaNoRepetir('make', $args));
    }

    /**
     * EL FALLO DE LA HERRAMIENTA TAMBIÉN CUENTA, no sólo el que la operación declara.
     *
     * Son dos niveles distintos: `ok:false` en el payload es la operación diciendo que no pudo, y
     * `$ok === false` es el runtime diciendo que la herramienta ni corrió. Las dos son fallos y las
     * dos se repiten igual.
     */
    public function testARuntimeFailureCountsToo(): void
    {
        $vigia = new SterileLoopGuard();
        $vigia->anota('make', ['x' => 1], 'Validation error: missing what', false);
        $vigia->anota('make', ['x' => 1], 'Validation error: missing what', false);

        $motivo = $vigia->motivoParaNoRepetir('make', ['x' => 1]);
        self::assertNotNull($motivo);
        self::assertStringContainsString('Validation error', $motivo);
    }

    /** Un resultado que no es JSON no se adivina: sin `ok` declarado y con el runtime en verde, pasó. */
    public function testAResultThatIsNotJsonIsNotGuessedToBeAFailure(): void
    {
        $vigia = new SterileLoopGuard();
        $vigia->anota('grep', ['q' => 'x'], 'no matches found', true);
        $vigia->anota('grep', ['q' => 'x'], 'no matches found', true);

        self::assertNull($vigia->motivoParaNoRepetir('grep', ['q' => 'x']), 'no se parsea prosa para inventar un fallo');
    }

    /** La tolerancia es de quien construye el vigía: cero significa cortar a la segunda. */
    public function testTheToleranceIsConfigurable(): void
    {
        $vigia = new SterileLoopGuard(tolerancia: 1);
        $vigia->anota('make', ['x' => 1], $this->falla(), true);

        self::assertNotNull($vigia->motivoParaNoRepetir('make', ['x' => 1]));
    }
}
