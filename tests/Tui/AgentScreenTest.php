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

namespace Milpa\AppRuntime\Tests\Tui;

use Milpa\AppRuntime\Tui\AgentScreen;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Session;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use PHPUnit\Framework\TestCase;

/**
 * La conversación con el agente, en la terminal.
 *
 * Lo que esta pantalla agrega sobre `coa agent "…"` no es cosmético: se ve la conversación acumulada
 * y cuántos pasos llevó cada respuesta. Y lo que NO hace es armar el orquestador — eso lo sabe
 * `AgentOperations`, y repetirlo aquí sería el camino a que la terminal y el TUI contesten distinto.
 */
final class AgentScreenTest extends TestCase
{
    /** @param \Closure(string): array<string, mixed> $responder */
    private function pantalla(\Closure $responder): AgentScreen
    {
        return new AgentScreen($responder, null, null, 74, 16, false);
    }

    private function teclear(AgentScreen $pantalla, string $texto): void
    {
        foreach (preg_split('//u', $texto, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $caracter) {
            $pantalla->press($caracter);
        }
    }

    /** Se escribe, Enter, y la respuesta queda en la conversación con lo que costó. */
    public function testAskingLeavesBothTurnsWithWhatItTook(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => [
            'ok' => true, 'answer' => 'Hay 3 encendidos.', 'steps' => 2, 'tools' => 8,
        ]);

        $this->teclear($pantalla, 'Que plugins hay?');
        $pantalla->press('enter');

        $turnos = $pantalla->conversation();
        self::assertCount(2, $turnos);
        self::assertSame('Que plugins hay?', $turnos[0]['texto'], 'la pregunta se guarda tal cual, con mayúsculas');
        self::assertStringContainsString('Hay 3 encendidos.', $pantalla->render(), 'y la conversación se ve');
        self::assertStringContainsString('Hay 3 encendidos.', $turnos[1]['texto']);
        self::assertStringContainsString('2 paso(s)', $turnos[1]['texto']);
        self::assertStringContainsString('8 herramientas', $turnos[1]['texto']);
    }

    /**
     * Los dos números no son adorno.
     *
     * Sin ellos, «el agente contestó» no distingue entre haber usado las herramientas de esta app y
     * haber contestado de memoria.
     */
    public function testZeroStepsIsVisibleToo(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'de memoria', 'steps' => 0, 'tools' => 8]);
        $this->teclear($pantalla, 'hola');
        $pantalla->press('enter');

        self::assertStringContainsString('0 paso(s)', $pantalla->conversation()[1]['texto']);
    }

    /** Un fallo se muestra con su motivo y su pista, tal cual vienen. */
    public function testAFailureShowsItsReasonAndHint(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => [
            'ok' => false,
            'error' => 'no hay API key configurada',
            'hint' => 'exporta ANTHROPIC_API_KEY',
        ]);

        $this->teclear($pantalla, 'hola');
        $pantalla->press('enter');

        $respuesta = $pantalla->conversation()[1]['texto'];
        self::assertStringContainsString('no hay API key configurada', $respuesta);
        self::assertStringContainsString('ANTHROPIC_API_KEY', $respuesta);

        // Y las dos líneas se PINTAN: una pista que sólo existe en el arreglo no le sirve a nadie.
        $texto = $pantalla->render();
        self::assertStringContainsString('no hay API key configurada', $texto);
        self::assertStringContainsString('ANTHROPIC_API_KEY', $texto);
    }

    /**
     * Una pantalla con una pregunta abierta, y el contrato que hay que modelar para llegar a ella.
     *
     * ── LO QUE COSTÓ DESCUBRIR, Y POR ESO VIVE AQUÍ ─────────────────────────────────────────────
     *
     * Contestar y RETOMAR son dos hechos, y el segundo sólo ocurre si el primero CONCEDIÓ algo: la
     * pantalla lee `granted` de lo que devuelve `agent:answer`. Una falsa que sólo devuelve
     * `['ok' => true]` contesta y no retoma — y se ve exactamente igual que un banco que no alcanza
     * el código, que fue la conclusión equivocada que costó una rebanada entera (evidence/0206).
     *
     * *El banco nunca faltó. Faltaba modelar el contrato, y eso se escribe donde el siguiente lo lee.*
     *
     * @param list<array{role: string, content: string, seq: int}> $turnos
     */
    private function conPreguntaAbierta(array $turnos, ?string $concede, ?string &$pedido): AgentScreen
    {
        $sesion = new Session(
            id: 's1',
            goal: 'lista los plugins instalados',
            turns: $turnos,
            question: new PendingQuestion(
                id: 'perm:config:set',
                question: '¿lo autorizas?',
                options: ['sí', 'no'],
                reason: 'permission',
            ),
        );

        return new AgentScreen(
            static function (string $q) use (&$pedido): array {
                $pedido = $q;

                return ['ok' => true, 'answer' => 'hecho'];
            },
            static fn (): Session => $sesion,
            static fn (string $r): array => $concede === null
                ? ['ok' => true]
                : ['ok' => true, 'granted' => $concede],
            74,
            16,
            false,
        );
    }

    /**
     * RETOMAR SIGUE LA PETICIÓN INTERRUMPIDA, NO LA DE APERTURA.
     *
     * Autorizar reinyectaba el `goal` de la sesión —el PRIMER prompt— así que desde el segundo turno
     * el agente rehacía el trabajo de apertura antes de volver a lo pendiente. Medido en una sesión
     * real: la primera pregunta viajaba dos veces en la ventana y `plugins_list` corría dos veces
     * byte por byte, reportados como dos hallazgos hasta que resultaron ser esta línea
     * (greenhouse evidence/0206).
     */
    public function testResumingAfterAGrantFollowsTheInterruptedRequest(): void
    {
        $pedido = null;
        $pantalla = $this->conPreguntaAbierta([
            ['role' => 'user', 'content' => 'lista los plugins instalados', 'seq' => 1],
            ['role' => 'assistant', 'content' => 'son tres', 'seq' => 2],
            ['role' => 'user', 'content' => 'usa config_set para poner agent.treeBudget en 7', 'seq' => 3],
        ], 'config:set', $pedido);

        $pantalla->press('enter');

        self::assertSame('usa config_set para poner agent.treeBudget en 7', $pedido);
        self::assertNotSame('lista los plugins instalados', $pedido, 'la de apertura ya se contestó');
    }

    /** Sin turnos que retomar cae al objetivo, que para eso está: es respaldo, no respuesta. */
    public function testWithNoTurnsItFallsBackToTheGoal(): void
    {
        $pedido = null;
        $pantalla = $this->conPreguntaAbierta([], 'config:set', $pedido);

        $pantalla->press('enter');

        self::assertSame('lista los plugins instalados', $pedido);
    }

    /**
     * UNA RESPUESTA QUE NO CONCEDIÓ NADA NO SE VUELVE A CORRER.
     *
     * Retomar con la compuerta igual de cerrada devuelve LA MISMA pregunta palabra por palabra — se
     * midió tres veces seguidas (evidence/0165). La rama existía sin prueba, y es la que hace que
     * una falsa incompleta se vea idéntica a un banco roto.
     */
    public function testAnAnswerThatGrantedNothingDoesNotResume(): void
    {
        $pedido = null;
        $pantalla = $this->conPreguntaAbierta([
            ['role' => 'user', 'content' => 'usa config_set', 'seq' => 1],
        ], null, $pedido);

        $pantalla->press('enter');

        self::assertNull($pedido, 'contestar y correr son dos hechos, y el segundo pide autorización');
    }

    /** Enter con el campo vacío no le pregunta nada a nadie. */
    public function testEnterOnAnEmptyPromptAsksNobody(): void
    {
        $veces = 0;
        $pantalla = $this->pantalla(static function (string $q) use (&$veces): array {
            ++$veces;

            return ['ok' => true, 'answer' => 'x'];
        });

        $pantalla->press('enter');

        self::assertSame(0, $veces);
        self::assertSame([], $pantalla->conversation());
    }

    /** Preguntar limpia el campo: la segunda pregunta empieza en blanco, no encima de la primera. */
    public function testTheFieldIsClearedAfterAsking(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'ok:' . $q]);

        $this->teclear($pantalla, 'uno');
        $pantalla->press('enter');
        $this->teclear($pantalla, 'dos');
        $pantalla->press('enter');

        $turnos = $pantalla->conversation();
        self::assertCount(4, $turnos);
        self::assertSame('dos', $turnos[2]['texto']);
        self::assertStringContainsString('ok:dos', $turnos[3]['texto']);
    }

    /**
     * La letra `q` se teclea.
     *
     * El tier trae `q` entre sus teclas de salida —lo que un dashboard quiere— y aquí cerraba la
     * pantalla a media pregunta. «¿qué plugins hay?» no se puede escribir sin ella.
     */
    public function testTheLetterQDoesNotCloseTheScreen(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'ok']);

        $this->teclear($pantalla, 'que');
        $pantalla->press('enter');

        self::assertSame('que', $pantalla->conversation()[0]['texto']);
    }

    /** El loop existe para correrlo contra una terminal de verdad. */
    public function testItExposesTheLoopToRunAgainstATerminal(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'x']);

        self::assertSame('prompt', $pantalla->loop()->focusedId());
    }

    /**
     * Antes de preguntar, la pantalla ABRE CON SU PORTADA en vez de un panel vacío.
     *
     * Y la portada contesta lo que alguien necesita antes de teclear nada: con qué modelo está
     * hablando, cuántas operaciones puede tocar el agente, y en qué sesión está parado. Un agente
     * que no dice con qué está pensando obliga a confiar sin poder verificar.
     */
    public function testTheScreenOpensWithItsCoverAndSaysWhatItIsWorkingWith(): void
    {
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true],
            null,
            null,
            74,
            24,
            false,
            ['model' => 'local:qwen3-coder:30b', 'tools' => 16, 'session' => 'chat-0802-a3f1', 'nueva' => true],
        );

        $texto = preg_replace('/\e\[[0-9;]*m/', '', $pantalla->render()) ?? '';

        self::assertStringContainsString('local:qwen3-coder:30b', $texto, 'con qué modelo se está hablando');
        self::assertStringContainsString('16 operaciones', $texto, 'qué puede tocar');
        self::assertStringContainsString('chat-0802-a3f1', $texto, 'en qué sesión está parado');
        self::assertStringContainsString('(nueva)', $texto, 'y que es nueva, no una retomada por accidente');
        self::assertStringContainsString('/sessions', $texto, 'y cómo llegar a las otras');
    }

    /** Pero una sesión CON estado no se tapa con la portada: el hilo va antes que la bienvenida. */
    public function testASessionWithStateSkipsTheCover(): void
    {
        $sesion = new Session('s1', 'migrar Inventario', plan: 'un plan que ya existe');
        $texto = $this->pantallaDe($sesion, static fn (string $q): array => ['ok' => true])->render();

        self::assertStringContainsString('un plan que ya existe', $texto);
    }

    /**
     * @param \Closure(string): array<string, mixed>      $responder
     * @param \Closure(string): array<string, mixed>|null $contestar
     */
    private function pantallaDe(Session $sesion, \Closure $responder, ?\Closure $contestar = null): AgentScreen
    {
        return new AgentScreen(
            $responder,
            static fn (): Session => $sesion,
            $contestar,
            74,
            24,
            false,
        );
    }

    /**
     * LA SESIÓN ANTES QUE LA CONVERSACIÓN — que es P16.7 entero.
     *
     * Lo que hace usable una corrida de cuarenta pasos no es releer lo que dijo: es ver en qué va. La
     * transcripción es lo que sobra cuando la pantalla es chica, no lo que se conserva.
     */
    public function testTheScreenShowsTheSessionState(): void
    {
        $sesion = new Session(
            id: 'jornada',
            goal: 'migrar Inventario a sqlite',
            mode: AutonomyMode::Auto,
            plan: '1. entidad  2. controller',
            todos: [
                new Todo('t1', 'escribir la entidad', TodoStatus::Done),
                new Todo('t2', 'escribir el controller'),
                new Todo('t3', 'migrar los datos', TodoStatus::Blocked),
            ],
            permissions: ['make', 'test'],
        );

        $texto = $this->pantallaDe($sesion, static fn (string $q): array => ['ok' => true])->render();

        self::assertStringContainsString('jornada', $texto);
        self::assertStringContainsString('auto', $texto, 'con cuánta autonomía corre');
        self::assertStringContainsString('migrar Inventario a sqlite', $texto);
        self::assertStringContainsString('1. entidad', $texto);
        self::assertStringContainsString('[x] escribir la entidad', $texto);
        self::assertStringContainsString('[ ] escribir el controller', $texto);
        self::assertStringContainsString('[!] migrar los datos', $texto);
        self::assertStringContainsString('autorizado: make, test', $texto);
    }

    /**
     * Una sesión ESPERANDO enseña la pregunta donde iba el prompt.
     *
     * Es lo que hay que hacer, no un aviso al costado: la sesión no es corrible hasta que alguien
     * conteste, así que ofrecer el prompt normal invitaría a lo único que no va a funcionar.
     */
    public function testAWaitingSessionShowsTheQuestionWhereThePromptGoes(): void
    {
        $sesion = new Session(
            id: 's1',
            goal: 'x',
            question: new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no'], '{"what":"plugin"}'),
        );

        $texto = $this->pantallaDe($sesion, static fn (string $q): array => ['ok' => true])->render();

        self::assertStringContainsString('¿autorizas make?', $texto);
        // LAS TRES AFORDANCIAS, no dos palabras para teclear. Antes decía «sí · no» y había que
        // ESCRIBIR una de las dos: una respuesta de dos letras que se puede equivocar es fricción que
        // nadie debería pagar por autorizar algo. Ahora se eligen con flechas y la tercera —escribir
        // la tuya— es para corregir o dar contexto, que es la que no se puede reducir a un botón.
        self::assertStringContainsString('▸ sí', $texto, 'la primera viene elegida');
        self::assertStringContainsString('no', $texto);
        self::assertStringContainsString('escribir la mía', $texto, 'y la tercera existe');
        self::assertStringContainsString('← → elegir', $texto, 'con cómo se usa');
        self::assertStringContainsString('what', $texto, 'y sobre qué');
        self::assertStringContainsString('[Enter] contestar', $texto, 'la ayuda cambia con la situación');
    }

    /**
     * Y ENTER CONTESTA, en vez de mandarle la respuesta al agente.
     *
     * Con una pregunta abierta la sesión no es corrible: mandarla al agente devolvería «está esperando
     * una respuesta» y habría que salirse del TUI a correr `coa agent:answer`. El lugar natural para
     * contestar es donde te la están preguntando.
     */
    public function testEnterAnswersThePendingQuestionInsteadOfAskingTheAgent(): void
    {
        $sesion = new Session(
            id: 's1',
            goal: 'x',
            question: new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']),
        );

        $alAgente = 0;
        $contestado = null;
        $pantalla = $this->pantallaDe(
            $sesion,
            static function (string $q) use (&$alAgente): array {
                ++$alAgente;

                return ['ok' => true];
            },
            static function (string $r) use (&$contestado): array {
                $contestado = $r;

                return ['ok' => true, 'granted' => 'make'];
            },
        );

        $this->teclear($pantalla, 'sí');
        $pantalla->press('enter');

        self::assertSame('sí', $contestado);
        self::assertStringContainsString('autorizado: make', $pantalla->conversation()[1]['texto']);

        // Y SIGUE SOLO. Esta prueba afirmaba lo contrario —«no se le mandó nada al agente»— y lo
        // afirmaba correctamente sobre el comportamiento de entonces: contestar dejaba el hint
        // «pídeme que siga» y se quedaba esperando. Rod lo reportó desde el uso real y tiene razón:
        // ya dijiste que sí, pedirte que lo pidas otra vez es un paso de más, y deja al agente parado
        // con la autorización en la mano.
        //
        // Lo que NO cambió es `agent:answer`: contestar y correr siguen siendo dos hechos distintos,
        // y otras superficies necesitan el primero sin el segundo. Cambió esta pantalla, que sí sabe
        // qué venía después.
        self::assertSame(1, $alAgente, 'contestar continúa el flujo, no lo deja esperando');
    }

    /** Sin sesión, la pantalla es la de antes: la memoria es opcional y no puede romper lo que servía. */
    public function testWithoutASessionTheScreenIsWhatItWas(): void
    {
        $texto = $this->pantalla(static fn (string $q): array => ['ok' => true])->render();

        self::assertStringContainsString('El agente de esta app', $texto);
        self::assertStringContainsString('[Enter] preguntar', $texto);
    }

    /**
     * Lo que costó la última vuelta se ve, y que se haya compactado también.
     *
     * Los dos números distinguen haber trabajado de haber contestado de memoria; y una compactación
     * que no se anuncia deja a la sesión contestando sobre un resumen sin que nadie sepa por qué.
     */
    public function testWhatTheLastRoundCostIsVisibleIncludingACompaction(): void
    {
        $sesion = new Session(id: 's1', goal: 'x', compactedThrough: 12);
        $pantalla = $this->pantallaDe($sesion, static fn (string $q): array => [
            'ok' => true, 'answer' => 'listo', 'steps' => 7, 'tools' => 14, 'compacted' => true,
        ]);

        $this->teclear($pantalla, 'sigue');
        $pantalla->press('enter');

        $texto = $pantalla->render();
        self::assertStringContainsString('7 paso(s)', $texto);
        self::assertStringContainsString('14 herramientas', $texto);
        self::assertStringContainsString('se compactó', $texto);
        self::assertStringContainsString('compactada', $texto, 'y la sesión lo dice de por sí');
    }

    /**
     * Backspace borra el último carácter de lo que se va escribiendo.
     *
     * Suena trivial y es la diferencia entre un campo y una máquina de escribir: sin él, un error de
     * dedo obliga a mandar la pregunta mal o a salir de la pantalla.
     */
    public function testBackspaceDeletesTheLastCharacter(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'ok']);

        $this->teclear($pantalla, 'hola');
        $pantalla->press('backspace');
        $pantalla->press('enter');

        self::assertSame('hol', $pantalla->conversation()[0]['texto'], 'backspace borró uno');
    }

    /**
     * Mientras el agente trabaja, la pantalla LO DICE y PINTA — las dos mitades, o ninguna.
     *
     * Es el defecto que Rod reportó con una captura: la bandera existía, el docblock afirmaba que el
     * frame anterior decía «pensando…», y el código la ponía y la quitaba dentro del mismo tick. Desde
     * afuera es idéntico a no haber cambiado nada, y ~16 segundos de pantalla igual son
     * indistinguibles de un proceso colgado.
     *
     * La prueba mira las dos cosas porque son inseparables: el estado escrito sin pintar no existe
     * para quien mira, que es el único que importa.
     */
    public function testWhileTheAgentWorksTheScreenSaysSoAndPaints(): void
    {
        $frames = [];
        $visto = null;

        $pantalla = new AgentScreen(function (string $p) use (&$visto): array {
            // DENTRO de la llamada bloqueante: aquí es donde la pantalla tiene que estar diciendo algo.
            $visto = $this->render($p);

            return ['ok' => true, 'answer' => 'listo', 'steps' => 1, 'tools' => 2];
        });

        $pantalla->paintWith(static function () use (&$frames, $pantalla): void {
            $frames[] = $pantalla->render();
        });

        $this->pantallaActual = $pantalla;
        $pantalla->loop()->dispatchKey('h');
        $pantalla->loop()->dispatchKey("\r");

        self::assertNotEmpty($frames, 'no pintó nada mientras trabajaba');
        self::assertStringContainsString('preguntando al agente', (string) $frames[0]);
        self::assertStringNotContainsString('preguntando al agente', $pantalla->render(), 'y al terminar, se quita');
    }

    /**
     * Sin nadie que le diga cómo pintar, el estado IGUAL queda escrito.
     *
     * Es el caso de una tubería o una prueba: la pantalla no puede saber si hay terminal —eso lo sabe
     * quien tiene el stream (ADR-0025)— así que no puede depender de que le hayan dado con qué
     * pintar.
     */
    public function testWithoutAPainterTheStateIsStillWritten(): void
    {
        $pantalla = new AgentScreen(static fn (string $p): array => ['ok' => true, 'answer' => 'ok']);

        $pantalla->loop()->dispatchKey('x');
        self::assertStringContainsString('x', $pantalla->render());
    }

    /** Y pintando en una terminal de verdad, los bytes llegan a ella. */
    public function testPaintingOnATerminalWritesToIt(): void
    {
        $terminal = new class () implements \Milpa\Live\Contracts\Tui\TerminalInterface {
            public string $escrito = '';

            public function start(callable $onInput, callable $onResize): void
            {
            }

            public function stop(): void
            {
            }

            public function write(string $data): void
            {
                $this->escrito .= $data;
            }

            public function pollInput(): string
            {
                return '';
            }

            public function columns(): int
            {
                return 80;
            }

            public function rows(): int
            {
                return 24;
            }

            public function atEndOfInput(): bool
            {
                return true;
            }

            public function moveBy(int $lines): void
            {
            }

            public function hideCursor(): void
            {
            }

            public function showCursor(): void
            {
            }

            public function clearLine(): void
            {
            }

            public function clearFromCursor(): void
            {
            }

            public function clearScreen(): void
            {
            }

            public function moveTo(int $row, int $column): void
            {
            }

            public function setTitle(string $title): void
            {
            }
        };

        $pantalla = new AgentScreen(static fn (string $p): array => ['ok' => true, 'answer' => 'ok']);
        $pantalla->paintOn($terminal);
        $pantalla->loop()->dispatchKey('h');
        $pantalla->loop()->dispatchKey("\r");

        self::assertNotSame('', $terminal->escrito, 'la terminal no recibio un solo byte');
    }

    /**
     * La pantalla recibe la actividad POR EL MISMO PUENTE que alimentaría a una página web.
     *
     * No hay canal propio: se registra como `SurfaceBroadcaster` y `BroadcastingEventStore` le empuja
     * cada hecho ya traducido. Y filtra — una tarjeta que se mueve es del tablero; aquí no hay dónde
     * pintarla, y fingir que sí sería inventar una vista.
     */
    public function testTheScreenReceivesActivityThroughTheSameBridgeAsAnyOtherSurface(): void
    {
        $frames = [];
        $pantalla = new AgentScreen(static fn (string $p): array => ['ok' => true, 'answer' => 'ok']);
        $pantalla->paintWith(static function () use (&$frames, $pantalla): void {
            $frames[] = $pantalla->render();
        });

        // El almacén real, con la pantalla como superficie: exactamente el cableado de `coa chat`.
        $almacen = new \Milpa\Agent\SessionStore(
            new \Milpa\AppRuntime\Agent\BroadcastingEventStore(new \Milpa\EventStore\InMemoryEventStore(), $pantalla),
        );

        $almacen->start('chat', 'x');
        $almacen->recordToolCall('chat', 'plugins_list', [], 'ok');

        self::assertNotEmpty($frames);
        self::assertStringContainsString('plugins_list', (string) end($frames), 'el nombre de la herramienta llega a la pantalla');

        // Y una tarjeta —que es del tablero— no cambia nada aquí.
        $antes = \count($frames);
        $almacen->setTodo('chat', new \Milpa\Agent\Todo('t1', 'algo', \Milpa\Agent\TodoStatus::Pending));
        self::assertSame($antes, \count($frames), 'lo que no es actividad no repinta esta pantalla');
    }

    /**
     * La actividad vive en su BARRA, y el renglón de entrada sigue siendo del humano.
     *
     * Compartirlos tenía un costo que no se ve hasta que pasa: mientras el agente trabajaba, lo que
     * uno había escrito desaparecía de la pantalla. La barra dice en qué está el sistema; abajo sigue
     * lo tuyo.
     */
    public function testActivityLivesInItsOwnBarAndThePromptStaysTheHumansd(): void
    {
        $pantalla = new AgentScreen(static fn (string $p): array => ['ok' => true, 'answer' => 'ok']);
        $pantalla->loop()->dispatchKey('h');

        $sinColor = static fn (string $s): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $s);

        // El estado es un BADGE con rol semántico: quien pinta le da color, y se lee de reojo.
        self::assertStringContainsString('listo', $sinColor($pantalla->render()), 'en reposo lo dice');

        $pantalla->broadcast('t', ['kind' => 'activity', 'activity' => [
            'state' => 'tool', 'detail' => 'plugins_list', 'mutating' => true,
        ]]);

        $pintado = $sinColor($pantalla->render());
        self::assertStringContainsString('plugins_list', $pintado, 'qué herramienta corre');
        self::assertStringContainsString('mutando', $pintado, 'el badge nombra el estado, y su rol le da color');
        self::assertStringContainsString('toca algo', $pintado, 'y se distingue la que muta');
        self::assertMatchesRegularExpression(
            '/[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏]/u',
            $pintado,
            'el indicador GIRA: un carácter que cambia es actividad observable, uno fijo no',
        );
        self::assertStringContainsString('› h', $pintado, 'lo escrito NO desaparece mientras trabaja');
    }

    /**
     * UNA RESPUESTA LARGA NO PUEDE VACIAR LA PANTALLA.
     *
     * Encontrado corriendo `coa chat` contra un modelo real: el agente llamaba su herramienta,
     * redactaba su respuesta —todo escrito en el stream— y la pantalla quedaba con la línea de
     * ayuda y nada más. Sin excepción, sin stderr: el árbol crecía un nodo por línea de respuesta,
     * pasaba del alto del terminal, y el motor de layout colapsaba en silencio.
     *
     * Es el peor modo de falla posible para esta superficie: el trabajo se hizo bien y quien mira
     * ve una pantalla en blanco. Un markdown de modelo trae veinte a cuarenta líneas con listas y
     * negritas, así que el caso no era raro — era el normal.
     */
    public function testALongAnswerDoesNotBlankTheScreen(): void
    {
        foreach ([30, 60, 200] as $lineas) {
            $pantalla = $this->pantalla(static fn (string $p): array => [
                'ok' => true,
                'answer' => implode("\n", array_fill(0, $lineas, 'linea de una respuesta larga')),
                'steps' => 2,
                'tools' => 21,
            ]);
            $this->teclear($pantalla, 'que plugins hay');
            $pantalla->press('enter');

            $pintado = preg_replace('/\e\[[0-9;]*m/', '', $pantalla->render()) ?? '';
            $vivas = array_filter(explode("\n", $pintado), static fn (string $l): bool => trim($l) !== '');

            self::assertGreaterThan(
                3,
                \count($vivas),
                "una respuesta de {$lineas} lineas dejo la pantalla en blanco",
            );
            self::assertStringContainsString(
                'linea de una respuesta larga',
                $pintado,
                "una respuesta de {$lineas} lineas no se ve por ningun lado",
            );
        }
    }

    /** Y lo que se ve es la COLA: lo último que dijo el agente, no el principio perdido. */
    public function testALongAnswerShowsItsTailNotItsHead(): void
    {
        $pantalla = $this->pantalla(static fn (string $p): array => [
            'ok' => true,
            'answer' => implode("\n", array_map(static fn (int $i): string => "renglon-{$i}", range(1, 80))),
            'steps' => 1,
            'tools' => 1,
        ]);
        $this->teclear($pantalla, 'dime');
        $pantalla->press('enter');

        $pintado = preg_replace('/\e\[[0-9;]*m/', '', $pantalla->render()) ?? '';

        self::assertStringContainsString('renglon-80', $pintado, 'lo ultimo es lo que importa');
        self::assertStringNotContainsString('renglon-1 ', $pintado, 'y lo viejo cede el lugar');
    }

    /**
     * LO QUE UNA HERRAMIENTA DEVUELVE SE ARMA COMO TABLA, no se transcribe.
     *
     * Es la diferencia entre mirar datos y leer el JSON que el modelo copió en su respuesta. Se
     * reconoce por FORMA —una lista de objetos con llaves en común— y no por el nombre de la
     * herramienta: atarlo a nombres conocidos dejaría fuera todo lo que un plugin declare mañana.
     */
    public function testAToolResultWithTableShapeIsPaintedAsATable(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true]);
        $pantalla->loop()->dispatchKey('h');

        $pantalla->broadcast('t', ['kind' => 'activity', 'activity' => [
            'state' => 'tool',
            'detail' => 'plugins_list',
            'result' => json_encode(['plugins' => [
                ['name' => 'HelloPlugin', 'version' => '0.1.0', 'enabled' => true],
                ['name' => 'OperationsHttp', 'version' => '0.1.0', 'enabled' => false],
            ]]),
        ]]);

        $pintado = (string) preg_replace('/\e\[[0-9;]*m/', '', $pantalla->render());

        self::assertStringContainsString('HelloPlugin', $pintado);
        self::assertStringContainsString('OperationsHttp', $pintado, 'las dos filas, no sólo la primera');
        self::assertStringContainsString('name', $pintado, 'con la cabecera que la herramienta declaró');
        self::assertStringContainsString('plugins_list', $pintado, 'y de qué herramienta salió');
    }

    /** Un resultado sin forma de tabla NO inventa una: un texto es un texto. */
    public function testAResultWithoutTableShapeInventsNothing(): void
    {
        foreach (['no soy json', '{"ok":true}', '{"lista":[1,2,3]}', '', '{"a":{"b":1}}'] as $crudo) {
            $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true]);
            $pantalla->broadcast('t', ['kind' => 'activity', 'activity' => [
                'state' => 'tool', 'detail' => 'algo', 'result' => $crudo,
            ]]);

            $pintado = (string) preg_replace('/\e\[[0-9;]*m/', '', $pantalla->render());

            self::assertStringNotContainsString(
                'lo que devolvió',
                $pintado,
                "«{$crudo}» no tiene forma de tabla y no debe producir una",
            );
        }
    }

    /** Un resultado RECORTADO por el stream tampoco: media tabla seria filas inventadas. */
    public function testATruncatedResultIsNotATable(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true]);
        $pantalla->broadcast('t', ['kind' => 'activity', 'activity' => [
            'state' => 'tool',
            'detail' => 'plugins_list',
            // Como lo deja `SessionToolGate::MAX_RESULT` cuando el JSON pasa de 600 caracteres.
            'result' => '{"plugins":[{"name":"HelloPlugin","version":"0.1.0"},{"name":"Operations',
        ]]);

        self::assertStringNotContainsString(
            'lo que devolvió',
            (string) preg_replace('/\e\[[0-9;]*m/', '', $pantalla->render()),
        );
    }

    private ?AgentScreen $pantallaActual = null;

    private function render(string $prompt): string
    {
        return $this->pantallaActual?->render() ?? '';
    }

    /**
     * UNA PREGUNTA ABIERTA NO SE PINTA DOS VECES.
     *
     * Cuando la vuelta termina pausada, el texto de la respuesta ES la pregunta — y abajo el widget la
     * pinta otra vez con sus opciones. Salían las dos, idénticas, y la de arriba sin nada que apretar.
     * Aquí queda sólo el widget, que es el que se contesta.
     */
    public function testAPausedTurnLeavesOnlyTheWidget(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => [
            'ok' => true,
            'answer' => '¿Confirmas plugins.enable sobre «Inventario»?',
            'paused' => true,
            'steps' => 5,
            'tools' => 19,
            'hint' => 'contesta con: coa agent:answer --session=x --answer=<sí|no>',
        ]);

        $this->teclear($pantalla, 'enciende el plugin');
        $pantalla->press('enter');

        $turnos = $pantalla->conversation();
        self::assertStringContainsString('se detuvo a preguntar', $turnos[1]['texto']);
        self::assertStringNotContainsString('¿Confirmas', $turnos[1]['texto'], 'la pregunta la pinta el widget');
        self::assertStringNotContainsString('agent:answer', $turnos[1]['texto'], 'y el hint es de la CLI, no de aquí');
        self::assertSame('sistema', $turnos[1]['voz'] ?? null, 'esto no lo dijo el modelo');
        self::assertStringContainsString('5 paso(s)', $turnos[1]['texto'], 'pero lo que costó sí se dice');
    }

    /**
     * AGOTAR EL TECHO NO ES CONTESTAR.
     *
     * Se devolvía como texto, así que la pantalla lo pintaba con la voz del agente — indistinguible de
     * algo que el modelo decidió decir. Es un estado del sistema y se pinta como tal, con la pista de
     * qué hacer.
     */
    public function testRunningOutOfStepsIsNotAnAnswer(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => [
            'ok' => true,
            'answer' => 'La vuelta se quedó sin pasos antes de terminar.',
            'exhausted' => true,
            'steps' => 12,
            'tools' => 19,
            'hint' => 'pídele que siga, o dale más pasos con `--steps`',
        ]);

        $this->teclear($pantalla, 'haz algo largo');
        $pantalla->press('enter');

        $turnos = $pantalla->conversation();
        self::assertSame('sistema', $turnos[1]['voz'] ?? null);
        self::assertStringContainsString('⚠', $turnos[1]['texto']);
        self::assertStringContainsString('sin pasos', $turnos[1]['texto']);
        self::assertStringContainsString('--steps', $turnos[1]['texto'], 'y dice qué hacer');
    }

    /** INTERRUMPIR NO ES FALLAR: es una decisión del humano, y la sesión sigue viva. */
    public function testAnInterruptedTurnIsNotAFailure(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => [
            'ok' => true,
            'answer' => 'La vuelta se interrumpió.',
            'interrupted' => true,
            'steps' => 6,
            'tools' => 19,
            'hint' => 'dile qué cambió y pídele que siga',
        ]);

        $this->teclear($pantalla, 'construye todo');
        $pantalla->press('enter');

        $turnos = $pantalla->conversation();
        self::assertSame('sistema', $turnos[1]['voz'] ?? null);
        self::assertStringContainsString('se interrumpió', $turnos[1]['texto']);
        self::assertStringContainsString('6 paso(s)', $turnos[1]['texto'], 'cuánto alcanzó a hacer');
        self::assertStringContainsString('pídele que siga', $turnos[1]['texto']);
        self::assertStringNotContainsString('✗', $turnos[1]['texto'], 'no es un error');
    }

    /**
     * ESC EN REPOSO LIMPIA EL BORRADOR Y NO CIERRA NADA.
     *
     * Su trabajo grande es interrumpir, y eso pasa mientras el agente trabaja —donde esta pantalla
     * está bloqueada y no ve teclas—. Con la pantalla libre hace lo que Esc hace en cualquier campo.
     *
     * Tenerlo como salida hacía que el gesto natural para frenar al agente cerrara la sesión: el peor
     * mapeo posible, porque el error es irreversible y frecuente.
     */
    public function testEscapeClearsTheDraftInsteadOfClosing(): void
    {
        $llamadas = 0;
        $pantalla = $this->pantalla(function (string $q) use (&$llamadas): array {
            ++$llamadas;

            return ['ok' => true, 'answer' => 'ok', 'steps' => 1, 'tools' => 1];
        });

        $this->teclear($pantalla, 'esto se borra');
        self::assertStringContainsString('esto se borra', $pantalla->render());

        self::assertTrue($pantalla->press('escape'), 'la tecla se consume aquí, no cierra el bucle');
        self::assertStringNotContainsString('esto se borra', $pantalla->render());

        $pantalla->press('enter');
        self::assertSame(0, $llamadas, 'y con el campo vacío no se le pregunta a nadie');
    }

    /** El selector se abre con `/sessions` y enseña lo que hay, sin salir de la pantalla. */
    public function testTheSessionPickerListsWhatThereIs(): void
    {
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'ok'],
            null,
            null,
            74,
            24,
            false,
            catalogo: static fn (): array => [
                ['id' => 'chat-0802-a1', 'goal' => 'un inventario', 'state' => 'viva', 'turns' => 4],
                ['id' => 'chat-0801-b2', 'goal' => 'otra cosa', 'state' => 'terminada', 'turns' => 12],
            ],
        );

        $this->teclear($pantalla, '/sessions');
        $pantalla->press('enter');

        // LAS CLAVES SON LAS QUE PRODUCE `Application::sesionesParaElegir()` — `state` y `turns`, no
        // traducciones. La primera versión de esta prueba las inventó en español y la pantalla avisó
        // con «Undefined array key»: un catálogo con otra forma pinta filas a medias y no falla.
        $pintado = $pantalla->render();
        self::assertStringContainsString('chat-0802-a1', $pintado);
        self::assertStringContainsString('viva', $pintado, 'el estado de cada una');
        self::assertStringContainsString('4 turnos', $pintado, 'y cuánto lleva');
        self::assertStringContainsString('chat-0801-b2', $pintado);
        self::assertStringContainsString('↑↓', $pintado, 'dice cómo se usa');
    }

    /** Sin sesiones que ofrecer, lo dice en vez de enseñar una lista vacía. */
    public function testAnEmptyPickerSaysSo(): void
    {
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'ok'],
            null,
            null,
            74,
            24,
            false,
            catalogo: static fn (): array => [],
        );

        $this->teclear($pantalla, '/sessions');
        $pantalla->press('enter');

        self::assertStringContainsString('ninguna todavía', $pantalla->render());
    }

    /** Y con Esc se vuelve, que es lo que ya hacía y sigue siendo correcto con la lista abierta. */
    public function testEscapeClosesThePicker(): void
    {
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'ok'],
            null,
            null,
            74,
            24,
            false,
            catalogo: static fn (): array => [['id' => 'chat-x', 'goal' => 'algo', 'state' => 'viva', 'turns' => 2]],
        );

        $this->teclear($pantalla, '/sessions');
        $pantalla->press('enter');
        self::assertStringContainsString('chat-x', $pantalla->render());

        $pantalla->press('escape');
        self::assertStringNotContainsString('↑↓', $pantalla->render(), 'el selector se cerró');
    }

    /** El cursor se mueve con las flechas, y no se sale de la lista por ninguno de los dos extremos. */
    public function testThePickerCursorStaysInsideTheList(): void
    {
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'ok'],
            null,
            null,
            74,
            24,
            false,
            catalogo: static fn (): array => [
                ['id' => 'uno', 'goal' => 'a', 'state' => 'viva', 'turns' => 1],
                ['id' => 'dos', 'goal' => 'b', 'state' => 'viva', 'turns' => 1],
            ],
        );

        $this->teclear($pantalla, '/sessions');
        $pantalla->press('enter');

        $pantalla->press('up');
        self::assertStringContainsString('› uno', $pantalla->render(), 'arriba del primero sigue el primero');

        $pantalla->press('down');
        $pantalla->press('down');
        self::assertStringContainsString('› dos', $pantalla->render(), 'y abajo del último sigue el último');
    }

    /**
     * ELEGIR UNA SESIÓN LA CONTINÚA Y LIMPIA LO DE LA ANTERIOR.
     *
     * Cambiar de sesión no es una pregunta: es cambiar el sujeto de la conversación. Dejar en pantalla
     * los turnos de la que se dejó atrás sería enseñar el historial de una sesión bajo el nombre de
     * otra — y quien lo lea mañana no tendría cómo notarlo.
     */
    public function testPickingASessionContinuesItAndClearsWhatWasOnScreen(): void
    {
        $continuada = null;
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'de la vieja', 'steps' => 1, 'tools' => 1],
            null,
            null,
            74,
            24,
            false,
            catalogo: static fn (): array => [
                ['id' => 'chat-nueva', 'goal' => 'lo otro', 'state' => 'viva', 'turns' => 3],
            ],
            continuar: function (string $id) use (&$continuada): void {
                $continuada = $id;
            },
        );

        $this->teclear($pantalla, 'algo');
        $pantalla->press('enter');
        self::assertNotSame([], $pantalla->conversation(), 'había conversación');

        $this->teclear($pantalla, '/sessions');
        $pantalla->press('enter');
        $pantalla->press('enter');

        self::assertSame('chat-nueva', $continuada, 'se continuó la elegida');
        self::assertSame([], $pantalla->conversation(), 'y la pantalla quedó limpia');
        self::assertStringNotContainsString('↑↓', $pantalla->render(), 'el selector se cerró solo');
    }

    /**
     * LA M GERMINA: el latido la va llenando en vez de pintarla completa de golpe.
     *
     * Sin el `tick` la pantalla sólo se redibuja cuando alguien teclea, y una marca que aparece entera
     * y quieta es un dibujo. Trece granos, y de ahí no pasa.
     */
    public function testTheLogoGrowsWithTheHeartbeatAndThenStops(): void
    {
        // CON PORTADA, que es donde vive la M. Sin bienvenida no hay marca que germinar.
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'ok'],
            null,
            null,
            74,
            24,
            false,
            ['model' => 'local:qwen', 'tools' => 3, 'session' => 'chat-x', 'nueva' => true],
        );

        // EL LATIDO SE INVOCA COMO LO INVOCA EL BUCLE. `RetainedTuiLoop::tick()` es privado —el bucle
        // se lo llama a sí mismo—, así que lo que esta prueba puede alcanzar es el callback que la
        // pantalla le entregó. Es la misma función, tomada del mismo sitio.
        $latir = (new \ReflectionProperty($pantalla->loop(), 'tick'))->getValue($pantalla->loop());
        self::assertIsCallable($latir);

        $primera = $pantalla->render();
        for ($i = 0; $i < 20; ++$i) {
            $latir();
        }
        $despues = $pantalla->render();

        self::assertNotSame($primera, $despues, 'el latido cambió lo que se ve');

        // Y NO SIGUE CRECIENDO. Un contador sin techo desbordaría el dibujo con el tiempo.
        for ($i = 0; $i < 50; ++$i) {
            $latir();
        }
        self::assertSame(mb_strlen($despues), mb_strlen($pantalla->render()), 'la M ya está completa');
    }

    /**
     * UNA SESIÓN RETOMADA ENSEÑA DE QUÉ SE ESTABA HABLANDO, y sólo la cola.
     *
     * Abrir una jornada larga con la pantalla en blanco obliga a preguntar «¿en qué íbamos?» a un
     * sistema que ya lo sabe. Se rehidrata UNA vez: hacerlo en cada frame duplicaría los turnos.
     */
    public function testAResumedSessionShowsWhatWasBeingTalkedAbout(): void
    {
        $turnos = [];
        foreach (range(1, 9) as $n) {
            $turnos[] = ['role' => 'user', 'content' => "pregunta {$n}", 'seq' => $n * 2 - 1];
            $turnos[] = ['role' => 'assistant', 'content' => "respuesta {$n}", 'seq' => $n * 2];
        }

        $sesion = new Session('vieja', 'un objetivo', turns: $turnos);
        $pantalla = $this->pantallaDe($sesion, static fn (string $q): array => ['ok' => true, 'answer' => 'ok']);

        $pintado = $pantalla->render();
        self::assertStringContainsString('respuesta 9', $pintado, 'lo último sí');
        self::assertStringNotContainsString('pregunta 1 ', $pintado, 'lo viejo no — la cola, no todo');

        // UNA SOLA VEZ. Volver a pintar no vuelve a rehidratar.
        $cuantos = \count($pantalla->conversation());
        $pantalla->render();
        self::assertSame($cuantos, \count($pantalla->conversation()));
    }

    /**
     * LA PAUSA DEL SUB-AGENTE SE VE Y SE CONTESTA SIN SALIR (Q-P19-Q, falsificador 5).
     *
     * La respuesta va A LA SESIÓN DEL HIJO —no a la propia— y el retome se PROPONE en el renglón de
     * entrada, no se dispara: la vuelta del padre la pide alguien, no un efecto colateral.
     */
    public function testAChildsQuestionIsAnsweredToTheChildAndTheResumeIsProposed(): void
    {
        $sesion = new Session('jornada', 'migrar Inventario');
        $contestado = null;
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'no debería llegar aquí'],
            static fn (): Session => $sesion,
            static function (string $r): array {
                self::fail('la respuesta era para el hijo, no para la sesión propia');
            },
            74,
            24,
            false,
            preguntaDeHijo: static fn (): array => [
                'session' => 'jornada.sub-abc123',
                'question' => 'El agente quiere correr «make». ¿Lo autorizas?',
                'options' => ['sí', 'no'],
            ],
            contestarHijo: static function (string $hijo, string $respuesta) use (&$contestado): array {
                $contestado = [$hijo, $respuesta];

                return ['ok' => true, 'granted' => 'make'];
            },
        );

        self::assertStringContainsString('(sub-agente jornada.sub-abc123)', $pantalla->render(), 'la pausa del hijo se ve, con su dueño');

        $this->teclear($pantalla, 'sí');
        $pantalla->press('enter');

        self::assertSame(['jornada.sub-abc123', 'sí'], $contestado, 'la respuesta viajó a la sesión del hijo');
        $ultimo = $pantalla->conversation()[\count($pantalla->conversation()) - 1];
        self::assertStringContainsString('contestado al sub-agente jornada.sub-abc123', $ultimo['texto']);
        self::assertStringContainsString('autorizado: make', $ultimo['texto']);
        self::assertStringContainsString(
            'Retoma al sub-agente jornada.sub-abc123 con agent_resume.',
            $pantalla->render(),
            'el retome quedó propuesto en el renglón, listo para mandarse o editarse',
        );
    }

    /** Con las dos preguntas abiertas, lo tecleado contesta la PROPIA: es la que bloquea esta pantalla. */
    public function testTheParentsQuestionWinsOverTheChilds(): void
    {
        $sesion = new Session('jornada', 'x', question: new PendingQuestion(
            id: 'perm:make',
            question: '¿autorizas make aquí?',
            options: ['sí', 'no'],
            why: '{}',
        ));
        $contestadoPropio = null;
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true],
            static fn (): Session => $sesion,
            static function (string $respuesta) use (&$contestadoPropio): array {
                $contestadoPropio = $respuesta;

                return ['ok' => true];
            },
            74,
            24,
            false,
            preguntaDeHijo: static function (): array {
                self::fail('con la propia abierta, la del hijo ni se consulta');
            },
            contestarHijo: static function (): array {
                self::fail('la respuesta era para la sesión propia');
            },
        );

        $this->teclear($pantalla, 'sí');
        $pantalla->press('enter');

        self::assertSame('sí', $contestadoPropio);
    }

    /**
     * LAS FLECHAS ELIGEN Y ENTER ACTÚA, sin teclear una palabra.
     *
     * Antes había que ESCRIBIR «sí»: una respuesta de dos letras que se puede equivocar es fricción
     * que nadie debería pagar por autorizar algo.
     */
    public function testArrowsChooseTheAnswerAndEnterSendsIt(): void
    {
        $sesion = new Session(
            id: 's1',
            goal: 'x',
            question: new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']),
        );

        $contestado = null;
        $pantalla = $this->pantallaDe(
            $sesion,
            static fn (string $q): array => ['ok' => true],
            static function (string $r) use (&$contestado): array {
                $contestado = $r;

                return ['ok' => true];
            },
        );

        $pantalla->press('right');
        $pantalla->press('enter');

        self::assertSame('no', $contestado, 'la segunda afordancia contesta «no» sin teclearlo');
    }

    /** Y con la tercera elegida y nada escrito, Enter no manda una respuesta vacía. */
    public function testTheThirdAffordanceWithNothingTypedSendsNothing(): void
    {
        $sesion = new Session(
            id: 's1',
            goal: 'x',
            question: new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']),
        );

        $contestado = null;
        $pantalla = $this->pantallaDe(
            $sesion,
            static fn (string $q): array => ['ok' => true],
            static function (string $r) use (&$contestado): array {
                $contestado = $r;

                return ['ok' => true];
            },
        );

        $pantalla->press('right');
        $pantalla->press('right');
        $pantalla->press('enter');

        self::assertNull($contestado, 'una respuesta vacía no es una respuesta');
    }

    /**
     * LA OPCIÓN «LA TUYA» CONTRAOFERTA, no responde (decisions/0064).
     *
     * El texto libre no autoriza —correcto— pero ya no se pierde: va como `counter`, no como `answer`,
     * y la pantalla RE-CORRE para que el agente re-proponga con esa restricción. «sí»/«no» (0/1) siguen
     * siendo respuestas; sólo la tercera afordancia —escribir la tuya— es la contraoferta.
     */
    public function testTheFreeTextOptionCountersAndReRunsInsteadOfAnswering(): void
    {
        $sesion = new Session('s1', 'cobrarle al cliente', question: new PendingQuestion('perm:charge', '¿autorizas charge(250)?', ['sí', 'no']));
        $llamadas = ['answer' => 0, 'counter' => 0, 'run' => 0];
        $promptDeReCorrida = null;
        $responder = function (string $q) use (&$llamadas, &$promptDeReCorrida): array {
            ++$llamadas['run'];
            $promptDeReCorrida = $q;

            return ['ok' => true, 'answer' => 'hecho'];
        };
        $contestar = function (string $a) use (&$llamadas): array {
            ++$llamadas['answer'];

            return ['ok' => true, 'granted' => null];
        };
        $contraofertar = function (string $c) use (&$llamadas): array {
            ++$llamadas['counter'];

            return ['ok' => true, 'countered' => $c, 'granted' => null];
        };

        $pantalla = new AgentScreen($responder, static fn (): Session => $sesion, $contestar, 74, 24, false, contraofertar: $contraofertar);
        $pantalla->loop()->dispatchKey('right');   // 0 (sí) -> 1 (no)
        $pantalla->loop()->dispatchKey('right');   // 1 (no) -> 2 (la tuya)
        foreach (str_split('usa 200') as $c) {
            $pantalla->loop()->dispatchKey($c);
        }
        $pantalla->loop()->dispatchKey("\r");

        self::assertSame(1, $llamadas['counter'], 'la opción «la tuya» CONTRAOFERTA');
        self::assertSame(0, $llamadas['answer'], 'no pasa por contestar — no otorga ni veta');
        self::assertSame(1, $llamadas['run'], 'y RE-CORRE para que el agente re-proponga — no se para');
        // Y RE-CORRE NEUTRO, no con la petición interrumpida: re-inyectar la intención vieja
        // («cobrarle al cliente») la haría re-proponer LO MISMO en vez de la contraoferta (evidence/0267).
        self::assertSame('continúa', $promptDeReCorrida, 'el re-run es neutro — no la intención que la contraoferta reemplazó');
    }
    /**
     * THE BOARD LIVES IN THE CHAT, PAINTED BY THE ONE COMPONENT (greenhouse evidence/0297).
     *
     * AgentScreen used to have nowhere to paint a card. Now it hosts a `component` node that renders
     * BoardComponent through BoardTuiRenderer — the SAME definition the browser serves (0296) — from
     * the agent:board fold a closure hands it. The card text painted here proves the chat renders the
     * board via the component path, not a bespoke tree.
     */
    public function testTheBoardRegionRendersInTheChatFromTheComponent(): void
    {
        $sesion = new Session(
            id: 's1',
            goal: 'inspecciona los plugins',
            turns: [['role' => 'user', 'content' => 'hola', 'seq' => 1]],
        );
        $fold = ['ok' => true, 'session' => 's1', 'columns' => [
            'done' => [['id' => 'turn:15', 'text' => 'tarjeta-de-prueba', 'origin' => 'turn']],
        ]];

        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true],
            static fn (): Session => $sesion,
            null,
            74,
            20,
            false,
            tablero: static fn (): array => $fold,
        );

        $salida = $pantalla->render();
        self::assertStringContainsString('tarjeta-de-prueba', $salida, 'the board card is painted in the chat, from the component');
        self::assertStringContainsString('DONE', $salida, 'and the column heading with it');
    }

    /**
     * REGRESIÓN (greenhouse evidence/0307): con un board presente Y una respuesta más alta que el
     * viewport, la barra de entrada desaparecía. El presupuesto del transcript restaba `count($hijos)`
     * —un nodo por cabecera— pero el nodo `tablero` pinta `heightFor` filas (aquí 14, no 1), así que
     * sobre-asignaba el transcript y el motor recortaba a alto 0 la barra de entrada y el pie. El
     * humano quedaba sin poder teclear el siguiente turno. El chrome se CUENTA, no se adivina — y la
     * cabecera es chrome también.
     */
    public function testTheInputBarSurvivesABoardPlusATallAnswer(): void
    {
        $sesion = new Session(
            id: 's1',
            goal: 'inspecciona los plugins',
            turns: [['role' => 'user', 'content' => 'hola', 'seq' => 1]],
        );
        $fold = ['ok' => true, 'session' => 's1', 'columns' => [
            'in_progress' => [
                ['id' => 'turn:1', 'text' => 'plugins_list', 'origin' => 'turn'],
                ['id' => 'turn:2', 'text' => 'plugins_deps', 'origin' => 'turn'],
                ['id' => 'turn:3', 'text' => 'plugins_show', 'origin' => 'turn'],
            ],
            'done' => [
                ['id' => 'turn:4', 'text' => 'plugins_verify', 'origin' => 'turn'],
                ['id' => 'turn:5', 'text' => 'plugins_architecture', 'origin' => 'turn'],
                ['id' => 'turn:6', 'text' => 'config', 'origin' => 'turn'],
                ['id' => 'turn:7', 'text' => 'plugins_list', 'origin' => 'turn'],
            ],
        ]];

        $pantalla = new AgentScreen(
            static fn (string $q): array => [
                'ok' => true,
                'answer' => implode("\n", array_fill(0, 80, 'renglon de una respuesta muy larga')),
                'steps' => 2,
                'tools' => 30,
            ],
            static fn (): Session => $sesion,
            null,
            74,
            55,
            false,
            tablero: static fn (): array => $fold,
        );

        $this->teclear($pantalla, 'lee la configuracion del agente');
        $pantalla->press('enter');

        $pintado = preg_replace('/\e\[[0-9;]*m/', '', $pantalla->render()) ?? '';

        self::assertStringContainsString(
            '[Ctrl-C] salir',
            $pintado,
            'la barra de ayuda (el pie) debe seguir en pantalla tras una respuesta larga con board',
        );
        self::assertStringContainsString(
            'escribe tu pregunta',
            $pintado,
            'el campo de entrada debe seguir visible: sin el, el humano no puede teclear el siguiente turno',
        );
    }


    /**
     * REGRESIÓN (greenhouse evidence/0307, arista 2/2): en una terminal DIMINUTA, la cabecera con el
     * tablero más el chrome no dejaban sitio y el piso `max(3, …)` del transcript forzaba filas que
     * desbordaban, recortando otra vez la barra de entrada. La barra es innegociable: en pantalla
     * chica se puede prescindir de VER el trabajo (el tablero cede), nunca de PODER teclear.
     */
    public function testTheInputBarSurvivesABoardOnATinyTerminal(): void
    {
        $sesion = new Session(
            id: 's1',
            goal: 'x',
            turns: [['role' => 'user', 'content' => 'hola', 'seq' => 1]],
        );
        $fold = ['ok' => true, 'session' => 's1', 'columns' => [
            'in_progress' => [
                ['id' => 'turn:1', 'text' => 'plugins_list', 'origin' => 'turn'],
                ['id' => 'turn:2', 'text' => 'plugins_deps', 'origin' => 'turn'],
                ['id' => 'turn:3', 'text' => 'plugins_show', 'origin' => 'turn'],
            ],
            'done' => [
                ['id' => 'turn:4', 'text' => 'plugins_verify', 'origin' => 'turn'],
                ['id' => 'turn:5', 'text' => 'plugins_architecture', 'origin' => 'turn'],
                ['id' => 'turn:6', 'text' => 'config', 'origin' => 'turn'],
                ['id' => 'turn:7', 'text' => 'plugins_list', 'origin' => 'turn'],
            ],
        ]];

        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'ok'],
            static fn (): Session => $sesion,
            null,
            74,
            16,
            false,
            tablero: static fn (): array => $fold,
        );

        $pintado = preg_replace('/\e\[[0-9;]*m/', '', $pantalla->render()) ?? '';

        self::assertStringContainsString(
            'escribe tu pregunta',
            $pintado,
            'en una terminal chica el campo de entrada debe seguir visible: el tablero cede, la barra no',
        );
        self::assertStringContainsString('[Ctrl-C] salir', $pintado, 'y el pie con él');
    }


    public function testWithoutABoardClosureTheChatIsUnchanged(): void
    {
        $sesion = new Session(id: 's1', goal: 'x', turns: [['role' => 'user', 'content' => 'hola', 'seq' => 1]]);
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true],
            static fn (): Session => $sesion,
            null,
            74,
            20,
            false,
        );

        // No tablero closure: the board region simply does not appear — the chat is exactly as before.
        self::assertStringNotContainsString('(sin trabajo aún)', $pantalla->render());
    }
    public function testTypingEffectAxesTIGHTENSViaAnEnvelopeInsteadOfCountering(): void
    {
        $sesion = new Session('s1', 'cobrarle al cliente', question: new PendingQuestion('perm:charge', '¿autorizas charge(250)?', ['sí', 'no']));
        $llamadas = ['counter' => 0, 'apretar' => 0, 'run' => 0];
        $sobreRecibido = null;
        $promptDeReCorrida = null;
        $responder = function (string $q) use (&$llamadas, &$promptDeReCorrida): array {
            ++$llamadas['run'];
            $promptDeReCorrida = $q;

            return ['ok' => true, 'answer' => 'hecho'];
        };
        $contraofertar = function (string $c) use (&$llamadas): array {
            ++$llamadas['counter'];

            return ['ok' => true, 'countered' => $c, 'granted' => null];
        };
        $apretar = function (array $sobre) use (&$llamadas, &$sobreRecibido): array {
            ++$llamadas['apretar'];
            $sobreRecibido = $sobre;

            return ['ok' => true, 'granted' => 'charge', 'tightened' => ['authority'], 'envelope' => ['authority' => 'read']];
        };

        $pantalla = new AgentScreen($responder, static fn (): Session => $sesion, null, 74, 24, false, contraofertar: $contraofertar, apretar: $apretar);
        foreach (str_split('authority=read') as $c) {
            $pantalla->loop()->dispatchKey($c);
        }
        $pantalla->loop()->dispatchKey("
");

        self::assertSame(1, $llamadas['apretar'], 'typing effect axes goes to the STRUCTURAL counter (envelope)');
        self::assertSame(0, $llamadas['counter'], 'not the value counter — «authority=read» names an axis');
        self::assertSame(['authority' => 'read'], $sobreRecibido, 'the envelope carries the tightened axis');
        self::assertSame(1, $llamadas['run'], 'the granted call runs — the session is runnable now');
        self::assertSame('continúa', $promptDeReCorrida, 'the re-run is neutral');
        self::assertStringContainsString('apretado', $pantalla->render(), 'the adjudication is shown');
    }

    public function testValueTextStillCountersNotTightens(): void
    {
        $sesion = new Session('s1', 'x', question: new PendingQuestion('perm:charge', '¿autorizas?', ['sí', 'no']));
        $counter = 0;
        $apretar = 0;
        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'ok'],
            static fn (): Session => $sesion,
            null,
            74,
            24,
            false,
            contraofertar: function (string $c) use (&$counter): array {
                ++$counter;
                return ['ok' => true, 'countered' => $c, 'granted' => null];
            },
            apretar: function (array $s) use (&$apretar): array {
                ++$apretar;
                return ['ok' => true];
            },
        );
        foreach (str_split('usa 200 no 250') as $c) {
            $pantalla->loop()->dispatchKey($c);
        }
        $pantalla->loop()->dispatchKey("
");

        self::assertSame(1, $counter, 'prose names no axis: it is a value counter');
        self::assertSame(0, $apretar, 'it does not masquerade as an envelope');
    }
    public function testTheGateSurfacesItsCeilingAxesAndTheTightenHint(): void
    {
        $why = (string) json_encode([
            'operation' => 'config:set',
            'base' => ['mutation' => 'persistent', 'externality' => 'same_principal', 'reversibility' => 'manual_recovery', 'authority' => 'write_as_user', 'subject' => 'configuration'],
        ]);
        $sesion = new Session('s1', 'x', question: new PendingQuestion('perm:config:set', '¿autorizas config:set?', ['sí', 'no'], why: $why));

        $pantalla = new AgentScreen(
            static fn (string $q): array => ['ok' => true],
            static fn (): Session => $sesion,
            null,
            90,
            24,
            false,
        );

        $salida = $pantalla->render();
        self::assertStringContainsString('techo:', $salida, 'the human sees the declared ceiling in axes');
        self::assertStringContainsString('authority=write_as_user', $salida);
        self::assertStringContainsString('aprieta con', $salida, 'and that they can tighten it with «eje=nivel»');
    }
}
