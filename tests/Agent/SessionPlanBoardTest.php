<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Evidence;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\SessionPlanBoard;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * El plan que el modelo ve en cada vuelta: RELEÍDO, no la foto de cuando empezó.
 *
 * Es la doctrina que esta casa lleva midiendo desde Q-P20-B: lo que se le enseña al agente tiene que
 * ser el estado vigente. Un plan capturado al abrir la vuelta le enseña tarjetas que él mismo ya movió.
 */
final class SessionPlanBoardTest extends TestCase
{
    private SessionStore $almacen;

    protected function setUp(): void
    {
        $this->almacen = new SessionStore(new InMemoryEventStore());
    }

    private function tablero(string $id = 'j'): SessionPlanBoard
    {
        return new SessionPlanBoard($this->almacen, $id);
    }

    /**
     * SIN PLAN TODAVÍA ES `null`, NO UN TABLERO VACÍO.
     *
     * Un encabezado con cero tarjetas ocupa contexto para decir nada — y peor, le sugiere al modelo
     * que el tablero es el tema antes de que haya escrito su primer pendiente.
     */
    public function testASessionWithNoPlanYetShowsNothing(): void
    {
        $this->almacen->start('j', 'la tarea', AutonomyMode::Auto);

        self::assertNull($this->tablero()->current());
    }

    /** Y una sesión que no existe tampoco inventa un tablero. */
    public function testAMissingSessionShowsNothing(): void
    {
        self::assertNull($this->tablero('no-existe')->current());
    }

    /** Con plan escrito, el plan aparece bajo su encabezado. */
    public function testTheWrittenPlanAppearsUnderItsHeading(): void
    {
        $this->almacen->start('j', 'x', AutonomyMode::Auto);
        $this->almacen->setPlan('j', "1. crear el plugin\n2. registrarlo");

        $texto = (string) $this->tablero()->current();

        self::assertStringContainsString('estado vigente', $texto);
        self::assertStringContainsString('1. crear el plugin', $texto);
    }

    /**
     * LAS TARJETAS SE PINTAN CON SU ESTADO DE AHORA, que es todo el punto.
     *
     * Se mueve una y se vuelve a leer: si el tablero fuera una foto, seguiría diciendo `pending`.
     */
    public function testACardThatMovedIsReadWithItsCurrentStatus(): void
    {
        $this->almacen->start('j', 'x', AutonomyMode::Auto);
        $this->almacen->setTodo('j', new Todo('t1', 'crear el plugin'));
        $this->almacen->setTodo('j', new Todo('t2', 'registrarlo'));

        $antes = (string) $this->tablero()->current();
        self::assertStringContainsString('crear el plugin', $antes);

        $this->almacen->completeTodo('j', 't1', Evidence::operationOk('e1', 'plugins:register'));

        $despues = (string) $this->tablero()->current();

        self::assertNotSame($antes, $despues, 'releído, no la foto');
        self::assertStringContainsString('t1', $despues);
        self::assertStringContainsString('t2', $despues);
    }

    /** Los cuatro estados tienen marca, y ninguna se repite: si dos se vieran igual, no informarían. */
    public function testEveryStatusHasItsOwnMark(): void
    {
        $this->almacen->start('j', 'x', AutonomyMode::Auto);
        foreach (TodoStatus::cases() as $i => $estado) {
            if ($estado === TodoStatus::Done) {
                // done ya no nace por setTodo (greenhouse decisions/0183): se abre y se cierra por su puerta.
                $this->almacen->setTodo('j', new Todo('t' . $i, 'tarea ' . $i, TodoStatus::Pending));
                $this->almacen->completeTodo('j', 't' . $i, Evidence::testPassed('e' . $i, 'vendor/bin/phpunit'));

                continue;
            }
            $this->almacen->setTodo('j', new Todo('t' . $i, 'tarea ' . $i, $estado));
        }

        $lineas = array_values(array_filter(
            explode("\n", (string) $this->tablero()->current()),
            static fn (string $l): bool => str_starts_with($l, '- ['),
        ));

        self::assertCount(\count(TodoStatus::cases()), $lineas);

        $marcas = array_map(static fn (string $l): string => explode(' · ', $l)[0], $lineas);
        self::assertCount(\count($marcas), array_unique($marcas), 'dos estados que se ven igual no informan');
    }
}
