<?php

/**
 * This file is part of Milpa Framework — the composer create-project starting point for a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\StepWatcher;
use Milpa\AiGateway\RunInterrupted;
use PHPUnit\Framework\TestCase;

/**
 * El vigía que deja al humano decir «para» a media vuelta.
 *
 * Se prueba contra un archivo temporal y no contra `STDIN`: la propiedad que importa —qué hace con
 * los bytes que encuentra— no depende de que vengan de una terminal, y atarla a una terminal haría
 * que estas pruebas sólo corrieran donde hay una.
 */
final class StepWatcherTest extends TestCase
{
    /**
     * Un teclado de mentiras con estos bytes esperando.
     *
     * `tmpfile()` y no `php://memory`, y no es un detalle: los streams de memoria no tienen descriptor
     * real, así que `stream_select` lanza `ValueError` sobre ellos. Probar contra uno mediría el
     * manejo del error y no el del teclado — que es justamente lo que prueba el caso de abajo, aparte.
     *
     * @return resource
     */
    private function conBytes(string $bytes)
    {
        $r = tmpfile();
        self::assertIsResource($r);
        fwrite($r, $bytes);
        rewind($r);

        return $r;
    }

    /** Sin nada tecleado, los pasos pasan. Un vigía que interrumpe solo sería peor que no tenerlo. */
    public function testSilenceLetsEveryStepThrough(): void
    {
        $vigia = new StepWatcher($this->conBytes(''));

        $vigia->paso(1);
        $vigia->paso(2);
        $vigia->paso(3);

        self::assertSame('', $vigia->tecleadoMientrasTanto());
    }

    /** Un ESC detiene la vuelta, y el mensaje dice EN QUÉ PASO — quien lo lee necesita saber cuánto se hizo. */
    public function testAnEscapeStopsTheTurnAndSaysWhere(): void
    {
        $vigia = new StepWatcher($this->conBytes("\x1b"));

        $this->expectException(RunInterrupted::class);
        $this->expectExceptionMessageMatches('/paso 7/');

        $vigia->paso(7);
    }

    /**
     * PEDIDO UNA VEZ, PEDIDO PARA SIEMPRE — hasta que alguien lo olvide.
     *
     * El ESC se consume al leerlo: sin recordar que se pidió, el paso siguiente encontraría el teclado
     * vacío y la vuelta continuaría como si nada. Una interrupción que sólo detiene un paso no detiene
     * nada.
     */
    public function testTheRequestSurvivesTheStepThatSawIt(): void
    {
        $vigia = new StepWatcher($this->conBytes("\x1b"));

        try {
            $vigia->paso(1);
            self::fail('el primer paso tenía que lanzar');
        } catch (RunInterrupted) {
            // esperado
        }

        $this->expectException(RunInterrupted::class);
        $vigia->paso(2);
    }

    /** Y `olvidar()` lo levanta, para que el mismo vigía sirva en la vuelta siguiente. */
    public function testForgettingClearsTheRequest(): void
    {
        $vigia = new StepWatcher($this->conBytes("\x1b"));

        try {
            $vigia->paso(1);
        } catch (RunInterrupted) {
            // esperado
        }

        $vigia->olvidar();
        $vigia->paso(2);

        self::assertTrue(true, 'el paso siguiente pasó sin lanzar');
    }

    /**
     * LO QUE EL HUMANO TECLEÓ ES SUYO. Si escribió mientras el agente trabajaba, esos bytes se guardan
     * y la pantalla se los devuelve: tirarlos sería comerse lo que escribió, y desde su lado se vería
     * como un teclado que no responde.
     */
    public function testWhatWasTypedMeanwhileIsKept(): void
    {
        $vigia = new StepWatcher($this->conBytes('sigue con eso'));

        $vigia->paso(1);

        self::assertSame('sigue con eso', $vigia->tecleadoMientrasTanto());
    }

    /** Y se entrega UNA vez: devolverlo dos veces lo duplicaría en la entrada. */
    public function testTheTypedTextIsHandedOverOnce(): void
    {
        $vigia = new StepWatcher($this->conBytes('hola'));
        $vigia->paso(1);

        self::assertSame('hola', $vigia->tecleadoMientrasTanto());
        self::assertSame('', $vigia->tecleadoMientrasTanto());
    }

    /**
     * LO DE CONTROL SE TIRA, LO IMPRIMIBLE SE GUARDA.
     *
     * Un `\n` a media vuelta no puede «enviar» nada porque todavía no hay nada que enviar, y dejarlo
     * en el borrador metería un salto de línea en la pregunta siguiente.
     */
    public function testControlBytesAreDroppedAndPrintableIsKept(): void
    {
        $vigia = new StepWatcher($this->conBytes("ab\ncd\t"));

        $vigia->paso(1);

        self::assertSame('abcd', $vigia->tecleadoMientrasTanto());
    }

    /**
     * UN ESC ENTRE TEXTO SIGUE SIENDO UNA ORDEN, y lo tecleado antes no se pierde.
     *
     * Interrumpir con algo ya escrito es el caso normal —el humano ve que el agente va mal, teclea la
     * corrección y aprieta Esc— así que ese texto es exactamente lo que la pantalla debe devolverle.
     */
    public function testAnEscapeAmongTextStillStopsAndKeepsTheText(): void
    {
        $vigia = new StepWatcher($this->conBytes("no,\x1bmejor otra cosa"));

        try {
            $vigia->paso(4);
            self::fail('tenía que lanzar');
        } catch (RunInterrupted) {
            // esperado
        }

        self::assertSame('no,mejor otra cosa', $vigia->tecleadoMientrasTanto());
    }

    /** `olvidar()` también limpia el borrador: lo de la vuelta pasada no es de la siguiente. */
    public function testForgettingAlsoClearsTheDraft(): void
    {
        $vigia = new StepWatcher($this->conBytes('algo'));
        $vigia->paso(1);

        $vigia->olvidar();

        self::assertSame('', $vigia->tecleadoMientrasTanto());
    }

    /**
     * UN STREAM QUE NO SE PUEDE VIGILAR NO INTERRUMPE, Y TAMPOCO EXPLOTA.
     *
     * `stream_select` lanza `ValueError` sobre un stream sin descriptor real. El bucle del agente
     * envuelve `onStep` en un catch que registra y sigue, así que esto no sería fatal — sería peor:
     * el vigía quedaría mudo, y un Esc que deja de funcionar se ve igual que un humano que no apretó
     * nada.
     */
    public function testAStreamThatCannotBeWatchedNeitherStopsNorExplodes(): void
    {
        $memoria = fopen('php://memory', 'r+');
        self::assertIsResource($memoria);
        fwrite($memoria, "\x1b");
        rewind($memoria);

        $vigia = new StepWatcher($memoria);

        $vigia->paso(1);

        self::assertSame('', $vigia->tecleadoMientrasTanto(), 'ni siquiera leyó');
    }
}
