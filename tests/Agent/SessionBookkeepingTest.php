<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\Command\Effect\Mutation;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\Agent\TodoStatus;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Las herramientas con las que el agente escribe su propio plan (P16.3).
 *
 * El vocabulario ya existía en `milpa/agent` desde P16.1 —se apendaba y se reducía— y no lo escribía
 * nadie: el plan vivía en el stream porque una prueba lo ponía ahí. Esto es lo que lo pone en manos
 * del agente, y `Session::stateBriefing()` es lo que se lo devuelve a leer.
 */
final class SessionBookkeepingTest extends TestCase
{
    private SessionStore $almacen;

    protected function setUp(): void
    {
        $this->almacen = new SessionStore(new InMemoryEventStore());
        $this->almacen->start('s1', 'migrar');
    }

    /** @return array<string, mixed> */
    private function llamar(string $nombre, array $entrada): array
    {
        foreach ((new SessionBookkeeping($this->almacen, 's1'))->operations() as $operacion) {
            if ($operacion->name === $nombre) {
                $handler = $operacion->handler;
                self::assertIsCallable($handler);

                /** @var array<string, mixed> $r */
                $r = $handler($entrada);

                return $r;
            }
        }

        self::fail("no existe «{$nombre}»");
    }

    /** El plan se escribe y vuelve en la ventana: un plan que el modelo no relee es media función. */
    public function testThePlanIsWrittenAndComesBackInTheWindow(): void
    {
        $this->llamar('plan', ['plan' => '1. entidad  2. controller']);

        $sesion = $this->almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertStringContainsString('controller', (string) $sesion->plan);

        $ventana = $sesion->window();
        self::assertSame('system', $ventana[0]['role']);
        self::assertStringContainsString('1. entidad', $ventana[0]['content'], 'el modelo lo vuelve a ver');
    }

    /** Un pendiente nuevo recibe su id de la app, no del modelo. */
    public function testANewTodoGetsItsIdFromTheApp(): void
    {
        $r = $this->llamar('todo', ['text' => 'escribir la entidad']);

        self::assertTrue($r['ok']);
        self::assertSame('t1', $r['todo']['id']);
        self::assertSame('pending', $r['todo']['status']);
    }

    /**
     * Mover uno que existe CONSERVA su texto si no se manda otro.
     *
     * Un `todo` que sólo venía a marcar `done` no puede borrar la descripción de lo que se hizo: eso
     * es justo lo que después aparece en el resumen al compactar, y perderlo dejaría un «ya hecho:»
     * sin qué.
     */
    public function testMovingATodoKeepsItsTextWhenNoneIsGiven(): void
    {
        $this->llamar('todo', ['text' => 'escribir la entidad']);

        $r = $this->llamar('todo', ['id' => 't1', 'status' => 'done']);

        self::assertTrue($r['ok']);
        self::assertSame('escribir la entidad', $r['todo']['text']);
        self::assertSame('done', $r['todo']['status']);

        $sesion = $this->almacen->load('s1');
        self::assertCount(1, $sesion?->todos ?? [], 'moverlo no crea otro');
        self::assertSame(TodoStatus::Done, $sesion?->todos[0]->status);
    }

    /** Mover uno que no existe se dice, en vez de crear uno con ese id a escondidas. */
    public function testMovingATodoThatDoesNotExistIsRefused(): void
    {
        $r = $this->llamar('todo', ['id' => 't9', 'status' => 'done']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('t9', (string) $r['error']);
    }

    /** Sin texto y sin id no hay nada que hacer, y la negativa dice cuál de los dos falta. */
    public function testWithoutTextOrIdItSaysWhichOneIsMissing(): void
    {
        $r = $this->llamar('todo', []);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('text', (string) $r['error']);
        self::assertStringContainsString('id', (string) $r['error']);
    }

    /**
     * La contabilidad NO pasa por la compuerta de permisos — y AHORA por su CONTRATO, no por su nombre.
     *
     * Declara que muta, porque apenda. Pero pedir permiso para anotar un plan es pedir permiso para
     * ser legible, y una compuerta que se pide también para eso se aprueba sin leer — que es como se
     * pierde la que sí importaba.
     *
     * Lo que cambió (greenhouse decisions/0078): antes la compuerta dejaba pasar `plan`/`todo` porque
     * NO eran una operación suya —`operationFor` daba `null` y `null` valía ALLOW—. Eso se retiró: hoy
     * la ausencia de contrato falla cerrado. Lo que las sigue dejando pasar es su CONTRATO, resuelto
     * desde su productor autorizado: `SessionBookkeeping` es un {@see ContractProducer}, la compuerta
     * le pregunta el contrato de `plan`/`todo`, y su perfil —bitácora propia, sin externalidad, sin
     * autoridad, sujeto Data— es autolegibilidad, no una mutación del mundo. Por eso hay que cablear el
     * productor: es el camino general, no una exención por nombre.
     */
    public function testBookkeepingIsNotGatedByPermissions(): void
    {
        $sesion = $this->almacen->load('s1');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate(
            $this->almacen,
            $sesion,
            [
                new Operation('make', 'Andamia', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true),
            ],
            // El productor que declara el contrato de la bitácora: por él la compuerta la resuelve y
            // la juzga, en vez de negarla como algo que no puede juzgar.
            contractProducers: [new SessionBookkeeping($this->almacen, 's1')],
        );

        // La sesión está en `ask`: `make` sí se detiene, y la contabilidad no.
        self::assertNull($compuerta->refuse('plan', ['plan' => 'x']), 'el plan pasa por su contrato de bitácora propia');
        self::assertNull($compuerta->refuse('todo', ['text' => 'x']), 'y el pendiente igual');
        self::assertNotNull($compuerta->refuse('make', []), 'lo que toca archivos sigue gateado');
    }

    /**
     * Y TAMPOCO se apunta como llamada a herramienta.
     *
     * Su efecto ya está en el stream con su propio evento y el reductor lo devuelve como estado.
     * Registrarla además como turno diría lo mismo dos veces y le cobraría a la ventana el doble justo
     * en las sesiones largas, donde el espacio es lo que escasea.
     */
    public function testBookkeepingIsNotAlsoRecordedAsAToolCall(): void
    {
        $sesion = $this->almacen->load('s1');
        self::assertNotNull($sesion);
        $compuerta = new SessionToolGate($this->almacen, $sesion, []);

        $compuerta->recorded('plan', [], '{"ok":true}', true);
        $compuerta->recorded('make', [], '{"ok":true}', true);

        $turnos = $this->almacen->load('s1')?->turns ?? [];
        self::assertCount(1, $turnos, 'sólo `make` dejó turno');
        self::assertStringContainsString('make', $turnos[0]['content']);
    }

    /** Las dos son de MCP y de nada más: escriben en la sesión que está corriendo. */
    public function testBothAreAgentSurfaceOnly(): void
    {
        foreach ((new SessionBookkeeping($this->almacen, 's1'))->operations() as $operacion) {
            self::assertSame(['mcp'], $operacion->surfaces, "«{$operacion->name}» no es de otra superficie");
            self::assertTrue($operacion->mutating, 'apenda, y lo declara');
        }
    }

    /** El estado de una sesión sin plan ni pendientes es `null`, no un encabezado vacío. */
    public function testASessionWithNothingToShowRendersNothing(): void
    {
        self::assertNull((new Session('s1', 'x'))->stateBriefing());
    }

    // ── EL CUADERNO DICE LO QUE ES (greenhouse evidence/0189) ──────────────────────────────────
    //
    // `plan` y `todo` declaraban `mutating: true` y NINGÚN perfil de efecto. Esta casa cableó S2 de
    // modo que lo no clasificado exige consentimiento —premiar el silencio volvería opcional todo
    // techo (decisions/0028)— así que la compuerta las negaba y el agente no podía planear. Probado
    // mutando: con perfil declarado, el pendiente aterriza.
    //
    // La doctrina llevaba tiempo escrita en el docblock de esta clase: «pedir permiso para anotar un
    // plan es pedir permiso para ser legible». Lo que faltaba era que fuera DATO.

    /** @return list<\Milpa\Command\Operation> */
    private function operaciones(): array
    {
        return (new SessionBookkeeping(new SessionStore(new InMemoryEventStore()), 's1'))->operations();
    }

    public function testTheNotebookDeclaresWhatItDoes(): void
    {
        foreach ($this->operaciones() as $op) {
            self::assertNotNull($op->effects, "«{$op->name}» declara su perfil");
        }
    }

    /** Un perfil que se declarara sin mutación sería la mentira cómoda: compra menos escrutinio. */
    public function testItDoesNotBuyLessScrutinyByLyingAboutMutating(): void
    {
        foreach ($this->operaciones() as $op) {
            self::assertTrue($op->mutating, "«{$op->name}» apenda, y sigue diciéndolo");
            self::assertNotSame(Mutation::None, $op->effects?->mutation, "«{$op->name}» no finge que no muta");
        }
    }

    /** Lo que este cuaderno NO es: nada suyo sale de la app ni pide privilegio. */
    public function testItsReachStopsAtItsOwnSession(): void
    {
        foreach ($this->operaciones() as $op) {
            self::assertSame(\Milpa\Command\Effect\Externality::None, $op->effects?->externality, "«{$op->name}»");
            self::assertSame(\Milpa\Command\Effect\Authority::None, $op->effects?->authority, "«{$op->name}»");
        }
    }
}
