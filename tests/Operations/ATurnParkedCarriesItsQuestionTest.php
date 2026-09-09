<?php

/**
 * This file is part of milpa/app-runtime — the runtime a founded Milpa app boots on.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\PendingQuestion;
use Milpa\AppRuntime\Operations\AgentOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Una vuelta pausada dice QUÉ preguntó, no sólo que preguntó (greenhouse decisions/0254).
 *
 * Antes la vuelta traía `paused: true` y un `hint` — y ese `hint` es de la CLI: la línea
 * `contesta con: coa agent:answer …`, escrita para una superficie donde no hay dónde teclear. El
 * Desktop, que sí tiene dónde, la mostraba tal cual, porque no había de dónde sacar la pregunta.
 *
 * La proyección se prueba por reflexión —como {@see \Milpa\AppRuntime\Tests\Operations\GoalInPromptTest}
 * hace con el prompt— porque vive dentro de una vuelta que necesitaría un modelo para correr, y lo que
 * está bajo prueba es la FORMA que cruza a la superficie, no la vuelta.
 */
#[CoversClass(AgentOperations::class)]
final class ATurnParkedCarriesItsQuestionTest extends TestCase
{
    /** 1 · las opciones del agente, su porqué y el código estable llegan enteros. */
    public function testTheQuestionCrossesWithItsOptionsItsWhyAndItsStableCode(): void
    {
        $proyectada = self::project(new PendingQuestion(
            id: 'q-9',
            question: '¿Autorizo escribir fuera del proyecto?',
            options: ['sí', 'no'],
            why: 'la ruta queda arriba de la raíz',
            expiresAt: '2026-09-10T00:00:00+00:00',
            reason: 'permission',
        ));

        self::assertSame([
            'id' => 'q-9',
            'text' => '¿Autorizo escribir fuera del proyecto?',
            'options' => ['sí', 'no'],
            'why' => 'la ruta queda arriba de la raíz',
            'reason' => 'permission',
            'expires_at' => '2026-09-10T00:00:00+00:00',
        ], $proyectada);
    }

    /**
     * 2 · el control: una pregunta SIN opciones ni motivo cruza igual, con nulos honestos.
     *
     * Un `null` es «no se dijo». Rellenarlo con un «sí/no» inventado sería la superficie proponiendo una
     * bifurcación que el agente no ofreció, que es justo lo que este contrato existe para evitar.
     */
    public function testAQuestionThatSaidNoneOfThatStillCrossesWithHonestNulls(): void
    {
        $proyectada = self::project(new PendingQuestion(id: 'q-1', question: '¿Qué hago?'));

        self::assertSame([], $proyectada['options'], 'ninguna opción inventada');
        self::assertNull($proyectada['why']);
        self::assertNull($proyectada['reason']);
        self::assertNull($proyectada['expires_at'], 'sin plazo es una decisión de quien pregunta, no un default escondido');
        self::assertSame('¿Qué hago?', $proyectada['text']);
    }

    /**
     * 3 · el falsificador que puede decir que no: la proyección NO lleva el `hint` de la CLI.
     *
     * Si un día alguien lo mete aquí «por completitud», la superficie con botones vuelve a tener a la
     * mano la instrucción de terminal que este arco quitó de en medio.
     */
    public function testTheProjectionCarriesNoTerminalInstruction(): void
    {
        $proyectada = self::project(new PendingQuestion(id: 'q-2', question: '¿Sigo?', options: ['sí', 'no']));

        self::assertArrayNotHasKey('hint', $proyectada);
        self::assertStringNotContainsString('coa agent:answer', json_encode($proyectada, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function project(PendingQuestion $pregunta): array
    {
        $m = new \ReflectionMethod(AgentOperations::class, 'preguntaPausada');
        $m->setAccessible(true);

        /** @var array<string, mixed> $out */
        $out = $m->invoke(null, $pregunta);

        return $out;
    }
}
