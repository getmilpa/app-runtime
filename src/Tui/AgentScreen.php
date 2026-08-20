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

namespace Milpa\AppRuntime\Tui;

use Milpa\Agent\Session;
use Milpa\Agent\TodoStatus;
use Milpa\Live\Tui\NodeRenderers\BoxRenderer;
use Milpa\Live\Tui\NodeRenderers\StatusBarRenderer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\Live\Contracts\Tui\TerminalInterface;
use Milpa\Live\Tui\RetainedTuiLoop;
use Milpa\Live\Tui\RetainedTuiRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Contracts\Tui\MeasurableTuiNodeRendererInterface;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * La conversación con el agente de esta app, en la terminal.
 *
 * Escribes lo que quieres, Enter, y el agente trabaja con las operaciones de esta app. Lo que esta
 * pantalla agrega sobre `coa agent "…"` no es cosmético: se VE la conversación acumulada y se ve
 * cuántos pasos lleva, así que preguntar dos cosas seguidas no obliga a volver a explicar la primera.
 *
 * ── EL BUCLE NO VIVE AQUÍ ───────────────────────────────────────────────────────────────────────
 *
 * La pantalla recibe una función que responde. `AgentOperations` es quien sabe armar el orquestador,
 * elegir proveedor y catálogo, y decir qué falta cuando no hay llave — repetirlo aquí sería un
 * segundo camino a lo mismo, que es como se llega a que la terminal y el TUI contesten distinto.
 *
 * ── ES SÍNCRONA, Y SE DICE ──────────────────────────────────────────────────────────────────────
 *
 * Mientras el agente piensa, la terminal no repinta: PHP no tiene hilos aquí y este bucle es de un
 * solo camino. Por eso el frame ANTES de preguntar dice «pensando…», y no un spinner con reloj
 * propio: una interfaz que finge actividad que no ocurre entrena a no creerle.
 *
 * Lo que SÍ ocurre es que los hechos llegan por el broadcaster mientras el agente trabaja, y el
 * indicador avanza un cuadro con cada uno. **Cada giro es un evento**: si se queda quieto no es que
 * la pantalla se colgó, es que todavía no ha pasado nada — y eso también es información.
 */
final class AgentScreen implements SurfaceBroadcaster
{
    /**
     * Los cuadros del indicador mientras hay trabajo.
     *
     * Braille y no puntos: ocupa una celda, gira sin saltar, y se distingue de cualquier carácter que
     * el modelo pueda escribir — un spinner que se confunde con el contenido deja de informar.
     */
    private const PULSO = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * Cuál de las tres afordancias está elegida cuando hay una pregunta abierta.
     *
     * `0` y `1` son las respuestas de una palabra que la operación ya declara; `2` es escribir la
     * tuya. Antes había que TECLEAR «sí» —y una respuesta de dos letras que se puede equivocar es una
     * fricción que ningún humano debería pagar por autorizar algo—; ahora son dos teclas y Enter.
     *
     * No se usan `s` y `n` como atajo directo a propósito: chocarían con escribir una respuesta que
     * empiece con esas letras, y «sí» empieza con `s`. Las flechas eligen, escribir se va solo a la
     * tercera.
     */
    private int $opcionPregunta = 0;

    /** La próxima respuesta es una CONTRAOFERTA (la opción «la tuya»), no una respuesta que otorga. */
    private bool $contraofertando = false;

    private readonly RetainedTuiLoop $loop;

    private string $entrada = '';

    /** @var list<array{quien: string, texto: string, voz?: string}> */
    private array $conversation = [];

    /**
     * En qué está la pantalla AHORA: `null` cuando espera al humano, o la línea que describe el
     * trabajo en curso.
     *
     * Deja de ser un booleano porque «pensando» y «corriendo `plugins_list`» son hechos distintos, y
     * el segundo es el que le dice a quien mira que el sistema NO está colgado: un nombre de
     * herramienta cambiando es actividad observable, un texto fijo no.
     */
    private ?string $actividad = null;

    /**
     * Cuántos granos de la M van encendidos. Trece es la M completa.
     *
     * Arranca completa a propósito: la animación se ve si alguien la mira crecer, y quien abre el
     * chat ya estaba mirando. Lo que germina en cada frame es el spinner del trabajo, no el logo —
     * una marca que se re-dibuja sola cada vez distrae de lo que sí está cambiando.
     */
    private int $granos = 0;

    /** Late en cada tick. De aquí sale el cuadro del spinner mientras el agente trabaja. */
    private int $pulso = 0;

    /**
     * Lo último que una herramienta contestó, ya con forma de tabla, o `null` si no la tenía.
     *
     * @var array{columnas: list<string>, filas: list<array<string, string>>}|null
     */
    private ?array $tabla = null;

    /** De qué herramienta salió la tabla de arriba. */
    private ?string $tablaDe = null;

    /** Los tokens que esta sesión lleva gastados, tal como los reporta cada vuelta. */
    private int $tokensGastados = 0;

    /** Si ya se trajo a la pantalla lo que la sesión traía — una sola vez por pantalla. */
    private bool $rehidratada = false;

    /** Si la pantalla está en el selector de `/sessions`. */
    /**
     * How many rows the reader has scrolled UP from the bottom of the transcript.
     *
     * Zero means the newest is on screen, which is where a conversation belongs. greenhouse
     * evidence/0167: the screen announced how many rows were above and no key reached them, so a
     * long answer's opening was unreachable forever — a notice that leads nowhere, which teaches
     * the human that something is lost and that their terminal does not answer.
     */
    private int $desplazamiento = 0;

    /** Rows the transcript can show right now — what a page step is worth. */
    private int $alturaTranscript = 0;

    /** Rows the whole conversation occupies, so the scroll knows where the top is. */
    private int $filasTotales = 0;

    private bool $eligiendoSesion = false;

    /** Cuál fila del selector está marcada. */
    private int $cursorSesiones = 0;

    /**
     * Cómo se pinta un frame sin salir del bucle.
     *
     * Existe porque esta pantalla es SÍNCRONA: mientras el agente trabaja, el bucle no vuelve a
     * pasar. Sin esto, el frame que dice «pensando…» se calcula y nunca se escribe — que es
     * exactamente lo que pasaba: el docblock de arriba lo afirmaba y el código lo desmentía, con la
     * bandera puesta y quitada dentro del mismo tick.
     */
    private ?\Closure $repintar = null;

    /** Lo que costó la última vuelta, ya redactado — `null` mientras no haya habido ninguna. */
    private ?string $ultimoCosto = null;

    /**
     * @param \Closure(string): array{ok: bool, answer?: string, steps?: int, tools?: int, compacted?: bool, error?: string, hint?: string, paused?: bool, exhausted?: bool, interrupted?: bool} $responder
     * @param \Closure(): (Session|null)|null                                                                                                                                                    $sesion    la sesión en curso, releída
     *                                                                                                                                                                                                      en cada frame — cambia
     *                                                                                                                                                                                                      después de cada vuelta
     * @param \Closure(string): array{ok: bool, granted?: string|null, error?: string}|null                                                                                                      $contestar cómo se responde una
     *                                                                                                                                                                                                      pregunta pendiente
     */
    public function __construct(
        private readonly \Closure $responder,
        private readonly ?\Closure $sesion = null,
        private readonly ?\Closure $contestar = null,
        // El ancho SE GUARDA, no sólo se pasa al loop: la conversación necesita preguntarle a cada
        // renderer cuántos renglones ocupa su nodo A ESTE ANCHO, y sin el dato el cálculo sería una
        // suposición sobre la terminal de alguien más.
        private readonly int $width = 80,
        private readonly int $height = 24,
        bool $ansi = true,
        /**
         * Con qué se abre: modelo, herramientas disponibles, id de la sesión.
         *
         * Se recibe armado y no se deduce aquí a propósito — quién es el proveedor y cuántas
         * herramientas tiene esta app lo sabe quien montó el agente, y esta pantalla sólo lo pinta.
         *
         * @var array{model?: string, tools?: int, session?: string, nueva?: bool}
         */
        private readonly array $bienvenida = [],
        /** Cómo se enumeran las sesiones para `/sessions`; `null` si esta app no guarda ninguna. */
        private readonly ?\Closure $catalogo = null,
        /** Cómo se cambia de sesión al elegir una en `/sessions`. */
        private readonly ?\Closure $continuar = null,
        /**
         * La pregunta abierta de un sub-agente de esta sesión, releída al preguntar y al pintar
         * (Q-P19-Q). `null` si esta app no delega. El tipo es laxo A PROPÓSITO: lo provee el host,
         * y esta pantalla valida la forma en vez de confiarla — un closure ajeno que devuelva otra
         * cosa no puede romper el pintado.
         *
         * @var \Closure(): (array<string, mixed>|null)|null
         */
        private readonly ?\Closure $preguntaDeHijo = null,
        /**
         * Cómo se le contesta a un sub-agente: (sesión hija, respuesta) → eco.
         *
         * @var \Closure(string, string): array{ok: bool, granted?: string|null, error?: string}|null
         */
        private readonly ?\Closure $contestarHijo = null,
        /**
         * Cómo se CONTRAOFERTA una pregunta abierta: el texto libre del humano se manda como `counter`
         * — no autoriza, re-encola para que el agente re-proponga con esa restricción (decisions/0064).
         * `null` hace que la opción «la tuya» caiga a `contestar` como antes.
         *
         * @var \Closure(string): array{ok: bool, countered?: string, granted?: string|null, error?: string}|null
         */
        private readonly ?\Closure $contraofertar = null,
    ) {
        $this->loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), self::renderers()),
            fn (): TuiNode => $this->tree(),
            ['prompt'],
            'prompt',
            $width,
            $this->height,
            $ansi,
            fn (string $key, RetainedTuiLoop $loop): bool => $this->handleKey($key, $loop),
            // EL LATIDO. Sin él la pantalla sólo se redibuja cuando alguien teclea: la M salía
            // completa y quieta —trece granos de golpe— y el estado no podía tener movimiento. Una
            // marca que no germina es un dibujo; germinando es lo que esta casa dice de sí misma.
            tick: function (): void {
                if ($this->granos < 13) {
                    ++$this->granos;
                }
                ++$this->pulso;
            },
            // SIN `escape` Y SIN `q`.
            //
            // `q` porque aquí se teclea texto: con ella, una `q` escrita en un campo cerraba la
            // pantalla y no había forma de teclear «query» ni «plugin».
            //
            // `escape` porque Esc es la tecla de INTERRUMPIR, no la de salir. Mientras el agente
            // trabaja, {@see \Milpa\AppRuntime\Agent\StepWatcher} la lee entre pasos y detiene la vuelta; con la
            // pantalla en reposo, limpia lo escrito. Salir es Ctrl-C, que es lo que una terminal ya
            // enseña. Tenerla como salida hacía que el gesto natural para frenar al agente cerrara la
            // sesión — el peor mapeo posible, porque el error es irreversible y frecuente.
            quitKeys: ['ctrl+c'],
        );
    }

    /**
     * Cuántos renglones ocupa este nodo al ancho de la pantalla.
     *
     * Se lo pregunta al renderer que va a pintarlo. El que no sepa contestar —`instanceof` falla—
     * recibe UN renglón: es la respuesta conservadora, y una entrada que se ve corta de más se nota
     * al instante, mientras que una que se pasa de alto empuja al resto fuera de la pantalla sin
     * síntoma. Hoy los dos tipos que usa la conversación saben medirse.
     */
    private function heightOf(TuiNode $nodo): int
    {
        $renderer = self::renderers()->resolve($nodo);

        return $renderer instanceof MeasurableTuiNodeRendererInterface
            ? max(1, $renderer->measureHeight($nodo, $this->width))
            : 1;
    }

    private static function renderers(): TuiNodeRendererRegistry
    {
        $registry = new TuiNodeRendererRegistry();
        // TODO LO QUE LA SUPERFICIE PUEDE PINTAR, registrado.
        //
        // `live-tui` publica veinticuatro renderers y esta pantalla usaba cinco: la respuesta del
        // agente llegaba como texto plano —sin negritas, sin listas, sin bloques de código— porque
        // `MarkdownRenderer` nunca se registró, y no había forma de enseñar una tabla ni un estado
        // con color. Un árbol que declara un nodo que ningún renderer atiende se pinta como nada, y
        // ésa es la manera más silenciosa de que una capacidad no llegue a nadie (ADR-0038).
        //
        // Operar a un agente es ENTENDER qué está haciendo, y para eso la pantalla tiene que poder
        // distinguir: lo que él dice (markdown), lo que una herramienta devolvió (tabla), en qué
        // estado está (badge con color) y cuánto lleva (barra).
        $registry->register(new TextRenderer());
        // El campo donde escribe el humano. Sin registrarlo, el nodo `text-input` no lo atiende
        // NADIE y se pinta como nada — la manera más silenciosa de que una capacidad no llegue.
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\TextInputRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\MilpaLogoRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\SpacerRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\MarkdownRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\DataTableRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\BadgeRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\DividerRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\LoaderRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\ProgressBarRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\ContainerRenderer());
        $registry->register(new BoxRenderer());
        // La barra de estado viene del catálogo de componentes de `milpa/live-tui`, no de aquí: una
        // pantalla que dibuja su propia barra es una barra que se ve distinta en cada pantalla.
        $registry->register(new StatusBarRenderer());

        return $registry;
    }

    /** El loop armado, para correrlo contra una terminal. */
    public function loop(): RetainedTuiLoop
    {
        return $this->loop;
    }

    /** La pantalla completa como texto, sin necesitar una terminal. */
    /**
     * Un hecho de la sesión, ya traducido, mientras está pasando.
     *
     * ── ES LA MISMA TUBERÍA QUE EL TABLERO ──────────────────────────────────────────────────────
     *
     * Esta pantalla no tiene un canal propio: se registra como {@see SurfaceBroadcaster} y recibe lo
     * que `BroadcastingEventStore` empuja al apendar cada hecho — exactamente lo que recibiría una
     * página web suscrita al mismo tópico. Una segunda vía para «lo que el agente está haciendo»
     * sería la copia que la spec del tablero prohíbe, y divergiría en el hecho que nadie probó.
     *
     * Filtra `activity` y **ignora lo demás** a propósito. Una tarjeta que se mueve es del tablero;
     * aquí no hay dónde pintarla, y fingir que sí sería inventar una vista.
     */
    public function broadcast(string $topic, array $payload): void
    {
        if (($payload['kind'] ?? null) !== 'activity') {
            return;
        }

        $estado = $payload['activity']['state'] ?? null;
        $detalle = $payload['activity']['detail'] ?? null;

        // LA PANTALLA ARMA LA VISTA DEL DATO, no repite la transcripción del modelo.
        //
        // Un `plugins_list` devuelve una lista de objetos con las mismas llaves — eso es una tabla, y
        // enseñarla como tabla es la diferencia entre mirar datos y leer el JSON que el modelo copió
        // en su respuesta. Si no parsea o no tiene forma de tabla, no se inventa ninguna: se queda
        // como estaba.
        if ($estado === 'tool') {
            $this->tabla = $this->tablaDe($payload['activity']['result'] ?? null);
            $this->tablaDe = \is_string($detalle) ? $detalle : null;
        }

        $this->anunciar(match ($estado) {
            'tool' => '  ' . (\is_string($detalle) ? $detalle : 'herramienta')
                . (($payload['activity']['mutating'] ?? false) === true ? ' · toca algo' : '') . '…',
            'ready' => 'ordenando la respuesta…',
            // `thinking` y cualquier estado que este paquete todavía no conozca caen aquí: decir «el
            // modelo tiene la palabra» ante algo desconocido es menos preciso que un nombre, pero es
            // cierto — el hecho llegó, así que algo está pasando.
            default => 'el modelo tiene la palabra…',
        });
    }

    /**
     * Decir en qué se está, y ENSEÑARLO.
     *
     * Las dos mitades son una sola cosa: cambiar el estado sin pintar es lo que hacía la versión
     * anterior, y desde afuera es indistinguible de no haber cambiado nada. Si nadie dio con qué
     * pintar —una tubería, una prueba— el estado igual queda escrito y `render()` lo muestra.
     */
    private function anunciar(string $que): void
    {
        $this->actividad = $que;

        // EL PULSO AVANZA CON EL HECHO, no con un reloj — y ésa es toda la diferencia.
        //
        // El indicador se quedaba clavado en el mismo cuadro: `anunciar()` repintaba y nadie movía el
        // contador, así que la pantalla enseñaba un spinner congelado y eso se lee como «se colgó».
        // Rod lo reportó desde el uso real.
        //
        // La tentación era animarlo con un temporizador, y este archivo ya explica por qué no: la
        // vuelta es SÍNCRONA —PHP no tiene hilos aquí— así que un spinner con reloj propio giraría
        // igual con el agente trabajando que con el proceso muerto. *Una interfaz que finge actividad
        // que no ocurre entrena a no creerle.*
        //
        // Avanzarlo aquí conserva esa honestidad y arregla el síntoma: `anunciar()` sólo se llama
        // cuando llegó un hecho del stream —una herramienta que corrió, un cambio de estado—, así que
        // **cada giro es un evento**. Si no gira, no es que se colgó la pantalla: es que no ha pasado
        // nada, y eso también es información.
        ++$this->pulso;

        if ($this->repintar !== null) {
            ($this->repintar)();
        }
    }

    /** Quien tiene la terminal dice cómo escribir un frame; la pantalla no la conoce (ADR-0025). */
    public function paintWith(\Closure $repintar): void
    {
        $this->repintar = $repintar;
    }

    /**
     * La forma normal de lo anterior: pintar en ESTA terminal.
     *
     * Recibe la terminal, no la busca — la pantalla sigue sin saber si hay una, que es lo que la hace
     * probable sin ella. Y usa `nextFrameBytes()`, el mismo diff que escribe el bucle en cada vuelta:
     * un frame pintado a media espera no pelea con el siguiente, ES el siguiente, adelantado.
     */
    public function paintOn(TerminalInterface $terminal): void
    {
        $this->paintWith(function () use ($terminal): void {
            $bytes = $this->loop->nextFrameBytes();
            if ($bytes !== '') {
                $terminal->write($bytes);
            }
        });
    }

    /**
     * La pantalla completa, en texto, lista para que el pintor le ponga color.
     *
     * Se devuelve una CADENA y no se escribe en ninguna salida: quien decide dónde va —la terminal,
     * una prueba, un archivo— es quien llama. Los marcadores de actor (`›` humano, `■` sistema,
     * sangría para el modelo, `└` sub-agente) viajan en el texto porque son lo que el pintor lee
     * para saber de quién salió cada bloque; si se pintaran aquí, la pantalla sólo serviría en una
     * terminal ANSI.
     */
    public function render(): string
    {
        return $this->loop->renderScreen();
    }

    /** Manda una tecla, como si alguien la hubiera tecleado. */
    public function press(string $key): bool
    {
        return $this->loop->dispatchKey($key);
    }

    /**
     * Lo dicho hasta ahora, por turnos.
     *
     * @return list<array{quien: string, texto: string}>
     */
    public function conversation(): array
    {
        return $this->conversation;
    }

    /**
     * Sobre QUÉ se está pidiendo permiso, en castellano y no en JSON.
     *
     * `SessionPolicy` guarda los argumentos con `json_encode` porque es un dato y ahí tiene que ser
     * exacto — el stream lo relee, y una frase no se puede reproyectar. Pero pedirle a un humano que
     * autorice algo enseñándole `{"name":"HelloPlugin"}` le cobra el trabajo de parsear justo en el
     * momento en que tiene que decidir. **Pintar el dato es trabajo de la superficie**, que es la
     * misma razón por la que el resultado crudo de las herramientas dejó de anexarse a la respuesta.
     *
     * Si no parsea, se enseña tal cual: inventar una frase sobre algo que no se entendió sería peor
     * que el JSON, porque el JSON al menos es cierto.
     */
    private function sobreQue(?string $why): string
    {
        if ($why === null || $why === '') {
            return 'sin argumentos';
        }

        $datos = json_decode($why, true);
        if (!\is_array($datos) || $datos === []) {
            return $why;
        }

        $partes = [];
        foreach ($datos as $clave => $valor) {
            $partes[] = \is_scalar($valor) || $valor === null
                ? $clave . ' ' . var_export($valor, true)
                // Un valor compuesto se deja en JSON: aplanarlo perdería la estructura sobre la que
                // justamente se está autorizando.
                : $clave . ' ' . (string) json_encode($valor, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        return implode(' · ', $partes);
    }

    /**
     * Las tres afordancias, con la elegida marcada — dos respuestas de una palabra y la tuya.
     *
     * Las dos primeras salen de lo que la operación DECLARA en su pregunta, no de constantes de esta
     * pantalla: una pregunta que ofrezca «continuar/abortar» pinta esas, no «sí/no». Si no declara
     * ninguna, quedan las dos de siempre — una pregunta sin opciones sigue siendo contestable.
     *
     * @param list<string> $opciones
     */
    private function afordancias(array $opciones): string
    {
        $etiquetas = [$opciones[0] ?? 'sí', $opciones[1] ?? 'no', 'escribir la mía'];
        $pintadas = [];
        foreach ($etiquetas as $i => $etiqueta) {
            $pintadas[] = $i === $this->opcionPregunta ? "▸ {$etiqueta}" : "  {$etiqueta}";
        }

        return implode('   ', $pintadas) . '   · ← → elegir · Enter';
    }

    /**
     * Las opciones de la pregunta abierta —propia o de un sub-agente—, o `null` si no hay ninguna.
     *
     * Una sola fuente para las teclas y para el pintado: dos lecturas de «¿hay pregunta?» acabarían
     * pintando botones que no responden, o respondiendo botones que no se ven.
     *
     * @return list<string>|null
     */
    private function preguntaAbierta(): ?array
    {
        $propia = $this->sesionActual()?->question;
        if ($propia !== null) {
            return $propia->options;
        }

        $hijo = $this->preguntaDeHijoPendiente();

        return $hijo === null ? null : $hijo['options'];
    }

    /**
     * Move the reading window, and report whether the key did anything at all.
     *
     * Returning false when nothing moved is what keeps the control honest: a scroll that swallows a
     * key at the top of the transcript looks exactly like one that works, and the human loses the
     * key without learning they are already at the edge.
     */
    private function desplazar(int $a): bool
    {
        if ($a === $this->desplazamiento) {
            return false;
        }

        $this->desplazamiento = $a;
        $this->loop->repintarTodo();

        return true;
    }

    private function handleKey(string $key, RetainedTuiLoop $loop): bool
    {
        // LEER HACIA ARRIBA, que es lo que la pantalla llevaba anunciando sin permitir.
        //
        // Va ANTES del selector de sesiones a propósito: cuando el selector está abierto, up/down
        // son suyas y este bloque no debe robárselas — por eso pregunta primero si está cerrado.
        if (! $this->eligiendoSesion && $this->alturaTranscript > 0) {
            $paso = max(1, $this->alturaTranscript - 1);
            $tope = max(0, $this->filasTotales - $this->alturaTranscript);

            $movido = match ($key) {
                'up' => $this->desplazar(min($tope, $this->desplazamiento + 1)),
                'down' => $this->desplazar(max(0, $this->desplazamiento - 1)),
                'pageup' => $this->desplazar(min($tope, $this->desplazamiento + $paso)),
                'pagedown' => $this->desplazar(max(0, $this->desplazamiento - $paso)),
                'home' => $this->desplazar($tope),
                'end' => $this->desplazar(0),
                default => null,
            };

            if ($movido !== null) {
                return $movido;
            }
        }

        // ESC EN REPOSO LIMPIA LO ESCRITO.
        //
        // Su trabajo grande es interrumpir, y eso pasa mientras el agente trabaja —lo lee
        // {@see \Milpa\AppRuntime\Agent\StepWatcher} entre pasos, porque en ese rato esta pantalla está bloqueada
        // y no ve teclas—. Con la pantalla en reposo no hay nada que interrumpir, así que hace lo que
        // Esc hace en cualquier campo de texto: borra el borrador.
        //
        // Se comprueba que el selector esté cerrado porque con la lista abierta Esc la cierra, que es
        // lo que ya hacía y sigue siendo lo correcto.
        if (!$this->eligiendoSesion && $key === 'escape') {
            if ($this->entrada !== '') {
                $this->entrada = '';
                $this->loop->repintarTodo();
            }

            return true;
        }

        // EL SELECTOR MANDA MIENTRAS ESTÁ ABIERTO. Escribir con una lista enfrente sería teclear a
        // ciegas contra dos destinos a la vez.
        if ($this->eligiendoSesion) {
            $filas = $this->catalogo !== null ? ($this->catalogo)() : [];

            if ($key === 'escape') {
                $this->eligiendoSesion = false;
                $this->loop->repintarTodo();

                return true;
            }
            if ($key === 'down') {
                $this->cursorSesiones = min(max(0, \count($filas) - 1), $this->cursorSesiones + 1);

                return true;
            }
            if ($key === 'up') {
                $this->cursorSesiones = max(0, $this->cursorSesiones - 1);

                return true;
            }
            if ($key === 'enter') {
                $elegida = $filas[$this->cursorSesiones]['id'] ?? null;
                if (\is_string($elegida) && $this->continuar !== null) {
                    ($this->continuar)($elegida);
                    // La conversación se limpia: lo que se ve tiene que ser de la sesión que se
                    // acaba de abrir. Dejar los turnos de la anterior mezclaría dos trabajos en una
                    // pantalla, que es justo lo que separar las sesiones vino a evitar.
                    $this->conversation = [];
                    $this->tokensGastados = 0;
                    // Y se vuelve a permitir traer lo que la sesión elegida ya vivió: sin esto, la
                    // pantalla mostraría vacía una conversación que sí existe.
                    $this->rehidratada = false;
                }
                $this->eligiendoSesion = false;
                $this->loop->repintarTodo();

                return true;
            }

            return true;
        }

        // ── CON UNA PREGUNTA ABIERTA, LAS FLECHAS ELIGEN Y ENTER ACTÚA ──────────────────────────
        //
        // Tres afordancias, que son las tres cosas que un humano quiere hacer ante una pregunta del
        // agente: decir que sí, decir que no, o contestar otra cosa —corregir, dar contexto, poner
        // una condición—. Antes las tres pasaban por teclear, y las dos primeras no tienen por qué.
        $abierta = $this->preguntaAbierta();
        if ($abierta !== null) {
            if ($key === 'left' || $key === 'right') {
                $this->opcionPregunta = $key === 'left'
                    ? max(0, $this->opcionPregunta - 1)
                    : min(2, $this->opcionPregunta + 1);
                $this->loop->repintarTodo();

                return true;
            }

            if ($key === 'enter') {
                $eleccion = match ($this->opcionPregunta) {
                    0 => $abierta[0] ?? 'sí',
                    1 => $abierta[1] ?? 'no',
                    // La tuya: si no escribiste nada, no se manda una cadena vacía como respuesta.
                    default => trim($this->entrada),
                };

                // NO SE CALLA. Antes esta rama devolvía true y no pasaba absolutamente nada: ni
                // avanzaba, ni avisaba, ni marcaba error, y el humano pulsaba Enter tres veces
                // creyendo que la terminal se había colgado (greenhouse evidence/0165).
                if ($eleccion === '') {
                    $this->conversation[] = [
                        'quien' => 'agente',
                        'voz' => 'sistema',
                        'texto' => '· escribe tu respuesta antes de Enter, o elige «' . ($abierta[0] ?? 'sí')
                            . '» o «' . ($abierta[1] ?? 'no') . '» con ← →',
                    ];
                    $this->loop->repintarTodo();

                    return true;
                }

                // La opción «la tuya» (2) es una CONTRAOFERTA; «sí»/«no» (0/1) son respuestas.
                $this->contraofertando = $this->opcionPregunta === 2;
                $this->entrada = $eleccion;
                $this->opcionPregunta = 0;
                $this->preguntar();

                return true;
            }
        }

        if ($key === 'enter') {
            $this->preguntar();

            return true;
        }

        if ($key === 'backspace') {
            $this->entrada = mb_substr($this->entrada, 0, -1);

            return true;
        }

        // El crudo, no el nombre canónico: éste viene en minúscula para que un atajo declarado como
        // `l` case con `L`, y una pregunta al agente sin mayúsculas se lee como un telegrama.
        $crudo = $loop->lastRawKey();
        if (mb_strlen($crudo) === 1 && preg_match('/^[[:print:]]$/u', $crudo) === 1) {
            $this->entrada .= $crudo;
            // ESCRIBIR ES ELEGIR LA TERCERA. Nadie teclea una respuesta para después tener que
            // caminar hasta la opción de escribirla.
            if ($this->preguntaAbierta() !== null) {
                $this->opcionPregunta = 2;
            }
            $this->loop->repintarTodo();

            return true;
        }

        return false;
    }

    private function preguntar(): void
    {
        $pregunta = trim($this->entrada);
        if ($pregunta === '') {
            return;
        }

        // `/sessions` es de la PANTALLA, no del agente: preguntarle a un modelo cuáles sesiones hay
        // sería pedirle que adivine algo que el stream sabe con certeza.
        if ($pregunta === '/sessions' || $pregunta === '/sesiones') {
            $this->entrada = '';
            $this->eligiendoSesion = true;
            $this->cursorSesiones = 0;
            // El árbol cambia de FORMA, no sólo de contenido: la lista tiene menos filas que la
            // conversación que tapa, y el pintado por diferencias dejaría las de abajo intactas —
            // el selector saldría montado sobre la charla anterior.
            $this->loop->repintarTodo();

            return;
        }

        // PREGUNTAR DEVUELVE LA VISTA AL FINAL. Es una conversación, no un archivo: quien acaba de
        // preguntar quiere ver la respuesta, y el aviso paga el costo diciendo qué quedó arriba.
        $this->desplazamiento = 0;
        $this->conversation[] = ['quien' => 'tú', 'texto' => $pregunta];
        $this->entrada = '';

        // CONTESTAR es lo primero. Con una pregunta abierta, la sesión no es corrible: mandarla al
        // agente devolvería «está esperando una respuesta» y habría que salirse del TUI a correr
        // `coa agent:answer`. El lugar natural para contestar es donde te la están preguntando.
        $sesion = $this->sesionActual();
        $esContra = $this->contraofertando;
        $this->contraofertando = false;

        // CONTRAOFERTAR: el texto libre (la opción «la tuya») no autoriza —correcto— pero YA NO se
        // pierde. Se manda como `counter`: no otorga, siembra la restricción, y RE-CORRE para que el
        // agente re-proponga con ella (decisions/0064). La diferencia con una respuesta que no
        // concedió nada: aquélla se paraba; ésta sigue, porque el humano SÍ pidió algo concreto.
        if ($sesion?->question !== null && $esContra && $this->contraofertar !== null) {
            $eco = ($this->contraofertar)($pregunta);
            if (!$eco['ok']) {
                $this->conversation[] = ['quien' => 'agente', 'voz' => 'sistema', 'texto' => '✗ ' . ($eco['error'] ?? '')];

                return;
            }
            $this->conversation[] = [
                'quien' => 'agente',
                'voz' => 'sistema',
                'texto' => '✓ contraoferta enviada — el agente la re-propone, y volverá a pedirte permiso',
            ];
            $this->correrVuelta($this->peticionInterrumpida($sesion));

            return;
        }

        if ($sesion?->question !== null && $this->contestar !== null) {
            $eco = ($this->contestar)($pregunta);

            if (!$eco['ok']) {
                $this->conversation[] = ['quien' => 'agente', 'voz' => 'sistema', 'texto' => '✗ ' . ($eco['error'] ?? '')];

                return;
            }

            $concedido = $eco['granted'] ?? null;

            $this->conversation[] = [
                'quien' => 'agente',
                'voz' => 'sistema',
                'texto' => $concedido !== null
                    ? '✓ contestado · autorizado: ' . $concedido
                    : '✓ contestado — pero tu respuesta NO autorizó ninguna operación',
            ];

            // UNA RESPUESTA QUE NO CONCEDIÓ NADA NO SE VUELVE A CORRER. Retomar el objetivo con la
            // compuerta igual de cerrada devuelve LA MISMA pregunta, palabra por palabra — medido
            // tres veces seguidas en greenhouse evidence/0165, y sin nada en pantalla que dijera por
            // qué. Un bucle que no se explica se lee como que la app no escucha.
            //
            // Se para y se dice qué haría avanzar. El texto queda contestado —el agente lo tiene
            // como contexto— y la decisión de autorizar sigue siendo del humano, que es el punto.
            if ($concedido === null) {
                $this->conversation[] = [
                    'quien' => 'agente',
                    'voz' => 'sistema',
                    'texto' => '· para autorizarla, elige «' . ($sesion->question->options[0] ?? 'sí')
                        . '» con ← → y Enter; para seguir sin ella, pide otra cosa',
                ];
                $this->loop->repintarTodo();

                return;
            }

            // ── Y SIGUE SOLO, QUE ES LO QUE CONTESTAR SIGNIFICA ─────────────────────────────────
            //
            // Antes decía «pídeme que siga» y se quedaba esperando. Rod lo reportó desde el uso real:
            // ya dijiste que sí, pedirte que lo pidas otra vez es un paso de más — y peor, deja al
            // agente parado con la autorización en la mano.
            //
            // La operación `agent:answer` NO retoma el bucle, y así debe seguir: contestar y correr son
            // dos hechos distintos, y otras superficies necesitan el primero sin el segundo. Lo que
            // cambia es ESTA pantalla, que sí sabe qué venía después — es la misma distinción de
            // siempre entre el primitivo y la superficie que lo usa.
            //
            // Se retoma con LA PETICIÓN QUE LA COMPUERTA INTERRUMPIÓ, y no con lo que acabas de
            // teclear: «sí» no es una instrucción, es un consentimiento, y mandarlo como prompt le
            // pediría al agente que interprete un monosílabo.
            //
            // Y TAMPOCO CON EL `goal`, QUE ES LO QUE HACÍA. El goal es el PRIMER prompt de la sesión,
            // así que autorizar una mutación en el segundo turno reinyectaba la petición de apertura:
            // el agente rehacía el trabajo del primer turno antes de volver a lo pendiente. Medido en
            // una sesión real —la primera pregunta viajando dos veces en la ventana y `plugins_list`
            // corriendo dos veces byte por byte— y reportado como DOS hallazgos separados hasta que
            // se encontró esta línea (greenhouse evidence/0206).
            //
            // En una sesión de un turno las dos cadenas son la misma y el defecto es invisible. Desde
            // el segundo divergen. El «sí» no compite: `agent:answer` no lo graba como turno.
            $this->correrVuelta($this->peticionInterrumpida($sesion));

            return;
        }

        // LA PREGUNTA DEL HIJO SE CONTESTA AQUÍ TAMBIÉN (Q-P19-Q): salirse del TUI a correr
        // `agent:answer --session=<hijo>` era el hueco que el smoke de Q-P19-P dejó a la vista. La
        // del padre va primero —bloquea ESTA pantalla—; la del hijo se atiende con la propia libre.
        $hijo = $this->preguntaDeHijoPendiente();
        if ($hijo !== null && $this->contestarHijo !== null) {
            $eco = ($this->contestarHijo)($hijo['session'], $pregunta);
            $this->conversation[] = [
                'quien' => 'agente',
                'voz' => 'sistema',
                'texto' => ($eco['ok'])
                    ? '✓ contestado al sub-agente ' . $hijo['session']
                        . (($eco['granted'] ?? null) !== null ? ' · autorizado: ' . $eco['granted'] : '')
                    : '✗ ' . ($eco['error'] ?? ''),
            ];
            if ($eco['ok']) {
                // EL RETOME SE PROPONE, NO SE DISPARA: queda escrito en el renglón de entrada para
                // que el humano lo mande, lo edite o lo tire. Autoría honesta — la vuelta del padre
                // la pide alguien, no un efecto colateral de contestar (criterio al usuario).
                $this->entrada = 'Retoma al sub-agente ' . $hijo['session'] . ' con agent_resume.';
            }

            return;
        }

        $this->correrVuelta($pregunta);
    }

    /**
     * Correr una vuelta del agente y pintar lo que devuelva.
     *
     * Vive aparte desde que contestar continúa el flujo solo: la pantalla necesita el mismo camino
     * desde dos lugares —lo que tecleas y lo que sigue después de autorizar— y dos copias de «correr
     * y pintar» divergirían en el primer arreglo que se le hiciera a una.
     */
    private function correrVuelta(string $pregunta): void
    {
        // UN SOLO ESTADO, Y ES EL QUE SE PUEDE SOSTENER.
        //
        // Lo que hace falta de verdad son cuatro —enviando, esperando al modelo, corriendo tal
        // herramienta, listo— y los tres primeros ya son hechos que el stream escribe. Pero llegan
        // por el `EventStore`, no por el valor de retorno, y traerlos hasta aquí es el siguiente
        // corte (promesa `tui-says-what-it-is-doing`).
        //
        // Se anuncia UNO en vez de fingir cuatro: un estado que no cambia mientras el agente llama
        // tres herramientas dice menos de lo que quisiéramos, pero no miente. Un spinner que gira
        // sin saber nada sí — y entrena a no creerle a la pantalla.
        $this->anunciar('preguntando al agente… (Ctrl-C para salir)');

        $respuesta = ($this->responder)($pregunta);

        $this->actividad = null;

        // REPINTAR ENTERO AL VOLVER, y no es paranoia: mientras el agente trabajaba corrió código de
        // terceros —herramientas, plugins, el cliente del modelo— y cualquiera pudo escribir en la
        // terminal. El bucle pinta por diferencias contra lo que cree que hay; una línea ajena deja
        // esas filas «sin cambios» y por tanto sin repintar nunca. Aquí es donde se sabe que hubo
        // riesgo, así que aquí se olvida lo que se creía y se vuelve a pintar todo.
        $this->loop->repintarTodo();

        if ($respuesta['ok']) {
            // ESTIMADOS, y por eso se dicen con «≈». El proveedor sabe el número exacto y la
            // operación no lo devuelve; inventar precisión sería peor que dar la magnitud. Cuatro
            // caracteres por token es la regla de dedo de siempre — sirve para saber si una sesión
            // va por cien o por cien mil, que es la pregunta real.
            $this->tokensGastados += (int) ceil((mb_strlen($pregunta) + mb_strlen((string) ($respuesta['answer'] ?? ''))) / 4);
            $this->ultimoCosto = 'última vuelta: ' . (int) ($respuesta['steps'] ?? 0) . ' paso(s) · '
                . (int) ($respuesta['tools'] ?? 0) . ' herramientas'
                . (($respuesta['compacted'] ?? false) ? ' · se compactó' : '');
        }

        $paso = '   [' . (int) ($respuesta['steps'] ?? 0) . ' paso(s) · ' . (int) ($respuesta['tools'] ?? 0) . ' herramientas]';

        // UNA PREGUNTA ABIERTA NO SE PINTA DOS VECES.
        //
        // Cuando la vuelta termina pausada, el texto de la respuesta ES la pregunta — y abajo el
        // widget la pinta otra vez, con sus opciones. Salían las dos, idénticas, y la de arriba sin
        // botones. Aquí se deja SÓLO el widget, que es el que se puede contestar.
        //
        // Se decide por `paused`, que la operación declara, y no leyendo la cadena: adivinar el estado
        // por el contenido del texto es el tipo de inferencia que se rompe cuando alguien reescribe una
        // frase.
        // INTERRUMPIR NO ES FALLAR, y se pinta como lo que fue: una decisión del humano. Con la voz
        // del sistema, porque esto no lo dijo el modelo.
        if (($respuesta['interrupted'] ?? false) === true) {
            $this->conversation[] = [
                'quien' => 'agente',
                'voz' => 'sistema',
                'texto' => '⏹ la vuelta se interrumpió' . $paso
                    . (($respuesta['hint'] ?? null) !== null ? "\n  " . $respuesta['hint'] : ''),
            ];

            return;
        }

        if (($respuesta['paused'] ?? false) === true) {
            $this->conversation[] = [
                'quien' => 'agente',
                'voz' => 'sistema',
                'texto' => '⏸ la vuelta se detuvo a preguntar' . $paso,
            ];

            return;
        }

        $this->conversation[] = $respuesta['ok']
            ? [
                'quien' => 'agente',
                // AGOTAR EL TECHO NO TIENE LA VOZ DEL AGENTE. Es un estado del sistema, y pintarlo
                // como prosa del modelo lo volvía indistinguible de algo que el agente decidió decir.
                'voz' => ($respuesta['exhausted'] ?? false) === true ? 'sistema' : null,
                'texto' => (($respuesta['exhausted'] ?? false) === true ? '⚠ ' : '')
                    . (string) ($respuesta['answer'] ?? '')
                    . ((($respuesta['exhausted'] ?? false) === true && ($respuesta['hint'] ?? null) !== null)
                        ? "\n  " . $respuesta['hint'] : '')
                    . $paso,
            ]
            : [
                'quien' => 'agente',
                // `sistema` y no `agente`: esto no lo escribió el modelo, y rendirlo como markdown
                // se comería justo lo que hay que copiar — un nombre de variable, una ruta.
                'voz' => 'sistema',
                // El motivo y la pista, tal cual vienen: quien las lee necesita esa frase para saber
                // qué arreglar, y reformularla la empeora.
                'texto' => '✗ ' . ($respuesta['error'] ?? 'no pudo contestar')
                    . (($respuesta['hint'] ?? null) !== null ? "\n  " . $respuesta['hint'] : ''),
            ];
    }

    /**
     * La pregunta pendiente de un sub-agente, si la sesión propia no tiene una.
     *
     * LA DEL PADRE VA PRIMERO: con las dos abiertas, lo tecleado contesta la propia — es la que
     * bloquea esta pantalla. La del hijo espera su turno, visible en cuanto la propia se libere.
     *
     * @return array{session: string, question: string, options: list<string>}|null
     */
    private function preguntaDeHijoPendiente(): ?array
    {
        if ($this->preguntaDeHijo === null || $this->sesionActual()?->question !== null) {
            return null;
        }

        $hijo = ($this->preguntaDeHijo)();

        return \is_array($hijo)
            && \is_string($hijo['session'] ?? null)
            && \is_string($hijo['question'] ?? null)
            ? ['session' => $hijo['session'], 'question' => $hijo['question'], 'options' => \is_array($hijo['options'] ?? null) ? $hijo['options'] : []]
            : null;
    }

    /**
     * Si la sesión no tiene todavía nada que mostrar.
     *
     * La portada reemplaza a la pantalla vacía, NO al estado: una sesión retomada con plan,
     * pendientes o una pregunta esperando tiene que enseñar eso en cuanto abre — es lo que hace
     * retomable una jornada larga, y taparlo con una bienvenida sería perder el hilo por decorar.
     */
    private function sesionEnBlanco(): bool
    {
        $sesion = $this->sesionActual();

        return ($sesion === null
            || ($sesion->turns === []
                && $sesion->question === null
                && $sesion->plan === null
                && $sesion->todos === []))
            // La pausa de un sub-agente ES estado que enseñar: taparla con la portada dejaría a un
            // hijo esperando detrás de una bienvenida (Q-P19-Q).
            && $this->preguntaDeHijoPendiente() === null;
    }

    /**
     * Cómo se llama lo que está pasando — dos palabras, no una frase.
     *
     * El badge es angosto a propósito: es lo que se lee de reojo. La frase completa vive en la barra
     * de al lado, que sí tiene sitio para el nombre de la herramienta.
     */
    private function etiquetaDeEstado(): string
    {
        if ($this->actividad === null) {
            return 'listo';
        }
        if (str_contains($this->actividad, 'preguntando')) {
            return 'pensando';
        }

        return str_contains($this->actividad, 'toca algo') ? 'mutando' : 'mirando';
    }

    /**
     * El rol semántico del estado, que es lo que le da color.
     *
     * `warning` para lo que MUTA y `info` para lo que sólo mira: la distinción que este programa
     * lleva un mes midiendo tiene que verse sin leer. Verde cuando no está haciendo nada, porque
     * «listo» es el único estado en que nadie tiene que vigilar la pantalla.
     */
    private function rolDeEstado(): string
    {
        return match ($this->etiquetaDeEstado()) {
            'listo' => 'success',
            'mutando' => 'warning',
            'pensando' => 'neutral',
            default => 'info',
        };
    }

    /**
     * Convierte lo que una herramienta contestó en una tabla, o devuelve `null` si no la tiene.
     *
     * Reconoce la forma, no la herramienta: **una lista de objetos con llaves en común es una
     * tabla**, venga de `plugins_list`, de `capabilities` o de una operación que un plugin declaró
     * ayer. Atarlo a nombres conocidos habría dejado fuera todo lo que esta app no conoce todavía,
     * que es justo lo que un framework extensible no puede hacer.
     *
     * Devuelve `null` sin ruido cuando el texto no parsea —viene recortado por el stream—, cuando no
     * hay ninguna lista, o cuando sus elementos no comparten llaves. Un resultado a medias es un
     * texto: fingir una tabla sería inventar filas que nadie devolvió.
     *
     * @return array{columnas: list<string>, filas: list<array<string, string>>}|null
     */
    private function tablaDe(mixed $crudo): ?array
    {
        if (!\is_string($crudo) || trim($crudo) === '') {
            return null;
        }

        $doc = json_decode($crudo, true);
        if (!\is_array($doc)) {
            return null;
        }

        // La lista puede ser la raíz o vivir bajo una llave —`{"plugins": [...]}`— y se toma la
        // PRIMERA que sirva: el resultado de una operación trae su veredicto al lado (`ok`, `total`)
        // y la lista es lo único que se puede tabular.
        $candidatas = array_is_list($doc) ? [$doc] : array_values(array_filter($doc, '\is_array'));

        foreach ($candidatas as $lista) {
            if (!array_is_list($lista) || \count($lista) === 0) {
                continue;
            }

            /** @var list<string> $columnas */
            $columnas = [];
            /** @var list<array<string, string>> $filas */
            $filas = [];
            $primera = true;
            foreach ($lista as $fila) {
                if (!\is_array($fila) || array_is_list($fila)) {
                    continue 2;   // una lista de escalares no es una tabla
                }
                $planas = array_filter($fila, static fn ($v): bool => !\is_array($v));
                $llaves = array_map('strval', array_keys($planas));
                $columnas = $primera ? $llaves : array_values(array_intersect($columnas, $llaves));
                $primera = false;
                $filas[] = array_map(
                    static fn ($v): string => \is_bool($v) ? ($v ? 'sí' : 'no') : (string) ($v ?? '—'),
                    $planas,
                );
            }

            if ($columnas === []) {
                continue;
            }

            // Cuatro columnas como mucho: una tabla que no cabe en la pantalla deja de ser una
            // tabla. Las que quedan son las primeras que la herramienta declaró, que es el orden en
            // que ella misma las considera importantes.
            $columnas = \array_slice($columnas, 0, 4);

            return [
                'columnas' => $columnas,
                'filas' => array_map(
                    static fn (array $f): array => array_intersect_key($f, array_flip($columnas)),
                    \array_slice($filas, 0, 12),
                ),
            ];
        }

        return null;
    }

    /** La portada: la M germinando, con qué se está trabajando, y por dónde empezar. */
    private function portada(): TuiNode
    {
        $modelo = (string) ($this->bienvenida['model'] ?? 'sin modelo declarado');
        $herramientas = (int) ($this->bienvenida['tools'] ?? 0);
        $id = (string) ($this->bienvenida['session'] ?? '');
        $nueva = (bool) ($this->bienvenida['nueva'] ?? true);

        // SE ARMA POR PRESUPUESTO, no por deseo. Esta pantalla mide lo que mide, y una portada que
        // no cabe colapsa el árbol entero — el mismo defecto que este archivo ya tuvo con las
        // respuestas largas, cometido otra vez al decorar. Lo que cede primero es el adorno: el
        // logo y el aire; lo que nunca cede es el estado, el prompt y con qué se está trabajando.
        $datos = [
            new TuiNode('m-modelo', 'text', props: ['text' => '  modelo        ' . $modelo]),
            new TuiNode('m-tools', 'text', props: ['text' => '  herramientas  ' . $herramientas . ' operaciones de esta app']),
            new TuiNode('m-tokens', 'text', props: ['text' => '  gastado       ≈' . $this->tokensGastados . ' tokens (estimado) en esta sesión']),
            new TuiNode('m-sesion', 'text', props: [
                'text' => '  sesión        ' . ($id !== '' ? $id : '—') . ($nueva ? '  (nueva)' : '  (retomada)'),
            ]),
        ];

        $pie = [
            new TuiNode('estado', 'badge', props: [
                'label' => $this->etiquetaDeEstado(),
                'role' => $this->rolDeEstado(),
                'width' => 16,
            ]),
            new TuiNode('prompt', 'text', props: ['text' => '› ' . $this->entrada . '▏'], focusable: true),
            new TuiNode('pie', 'text', props: ['text' => '[Enter] preguntar · [Esc] limpiar · [Ctrl-C] salir']),
        ];

        $lema = [
            new TuiNode('lema', 'text', props: [
                'text' => 'El agente de esta app · trabaja con TUS operaciones',
                'align' => 'center',
            ]),
        ];

        $ayudas = [
            new TuiNode('sep-2', 'text', props: ['text' => str_repeat('─', 44)]),
            new TuiNode('ayuda-1', 'text', props: ['text' => '  escribe y Enter para preguntar']),
            new TuiNode('ayuda-2', 'text', props: ['text' => '  /sessions  elegir otra sesión y continuarla']),
            new TuiNode('ayuda-3', 'text', props: ['text' => '  coa chat --continue  retoma la última al abrir']),
        ];

        // La M germinando: cinco filas de granos que crecen de las esquinas de abajo al centro. Es
        // lo primero que se sacrifica si la terminal es chica — una marca que tapa el estado deja
        // de ser marca y pasa a ser estorbo.
        $logo = [
            // `height: 5` porque la M es una rejilla de 5×5 y el renderer RECORTA a lo que el
            // layout le dé: sin declararlo le tocaban tres filas y la marca salía descabezada.
            new TuiNode('logo', 'milpa-logo', props: [
                'frame' => $this->granos,
                'grain' => '◆',
                'empty' => ' ',
                'align' => 'center',
                'height' => 5,
                'lines' => 5,
            ]),
            new TuiNode('aire-2', 'spacer', props: ['height' => 1, 'lines' => 1]),
        ];

        $fijo = \count($datos) + \count($pie) + \count($lema) + 1;
        $hijos = $lema;
        $hijos[] = new TuiNode('sep', 'text', props: ['text' => str_repeat('─', 44)]);

        // 5 filas del logo más su aire; sólo si sobra sitio para él Y para las ayudas.
        if ($this->height >= $fijo + 6 + \count($ayudas)) {
            $hijos = [new TuiNode('aire-1', 'spacer', props: ['height' => 1, 'lines' => 1]), ...$logo, ...$hijos];
        }

        $hijos = [...$hijos, ...$datos];

        if ($this->height >= \count($hijos) + \count($ayudas) + \count($pie) + 1) {
            $hijos = [...$hijos, ...$ayudas];
        }

        return new TuiNode('raiz', 'container', props: ['direction' => 'vertical'], children: [...$hijos, ...$pie]);
    }

    /** El selector de `/sessions`: cuál retomar, con lo que hace falta para reconocerla. */
    private function selectorDeSesiones(): TuiNode
    {
        $filas = $this->catalogo !== null ? ($this->catalogo)() : [];
        $hijos = [
            new TuiNode('sel-titulo', 'text', props: ['text' => 'sesiones de esta app — ↑↓ para elegir, Enter para continuar, Esc para volver']),
            new TuiNode('sel-sep', 'text', props: ['text' => str_repeat('─', 60)]),
        ];

        if ($filas === []) {
            $hijos[] = new TuiNode('sel-vacio', 'text', props: ['text' => '  (ninguna todavía — la de ahora es la primera)']);
        }

        // LA VENTANA SIGUE AL CURSOR. Antes se cortaba SIEMPRE desde la primera fila, así que al
        // bajar más allá de la última visible el marcador salía de pantalla: no se veía nada
        // seleccionado y Enter abría una sesión que el humano NUNCA VIO (greenhouse evidence/0165).
        //
        // Es el mismo defecto que el transcript acababa de tener, en la pantalla de al lado: una
        // lista que se puede recorrer y no se puede ver.
        $caben = max(3, $this->height - 8);
        $desde = max(0, min($this->cursorSesiones - intdiv($caben - 1, 2), \count($filas) - $caben));

        if ($desde > 0) {
            $hijos[] = new TuiNode('sel-arriba', 'text', props: ['text' => sprintf('  ↑ %d más arriba', $desde)]);
        }

        // LA SONDA: el estado interno, en una línea, y sólo si alguien lo pide.
        //
        // Medir esta pantalla desde una captura no funcionó. El marcador es un carácter que `grep`
        // no encontraba, la captura plana escondía y `cat -A` sí mostraba, y tres corridas dieron
        // tres respuestas que no concordaban (greenhouse evidence/0169). No se puede afirmar que la
        // ventana sigue al cursor mirando píxeles: hay que poder LEER el cursor.
        //
        // Va detrás de una variable de entorno porque es un instrumento, no una función: un humano
        // no tiene por qué ver índices, y un instrumento que se cuela en la pantalla de todos deja
        // de ser instrumento y pasa a ser ruido.
        if (TuiProbe::pedida()) {
            $hijos[] = new TuiNode('sel-sonda', 'text', props: [
                'text' => sprintf(
                    '  [sonda] cursor=%d ventana=%d..%d caben=%d total=%d',
                    $this->cursorSesiones,
                    $desde,
                    min($desde + $caben - 1, max(0, \count($filas) - 1)),
                    $caben,
                    \count($filas),
                ),
            ]);
        }

        // SIN `preserve_keys`, y el índice real se suma. Conservar las claves parecía lo correcto
        // —el cursor cuenta sobre la lista entera— y dejó el marcador SIN PINTAR: `$filas` no viene
        // indexada 0..n, así que `$i` no era un entero comparable con el cursor. Se veía como una
        // lista sin nada seleccionado, que es peor que el defecto que esto vino a arreglar.
        //
        // Lo cazó el ganado, no la suite: 407 pruebas verdes y el marcador ausente en la pantalla.
        foreach (\array_slice($filas, $desde, $caben) as $j => $fila) {
            $i = $desde + $j;
            $marca = $i === $this->cursorSesiones ? '›' : ' ';
            $objetivo = (string) $fila['goal'];
            $hijos[] = new TuiNode("sel:{$i}", 'text', props: [
                'text' => sprintf(
                    '%s %-22s %-14s %2d turnos  %s',
                    $marca,
                    (string) $fila['id'],
                    (string) $fila['state'],
                    (int) $fila['turns'],
                    mb_strlen($objetivo) > 40 ? mb_substr($objetivo, 0, 39) . '…' : $objetivo,
                ),
            ]);
        }

        $hijos[] = new TuiNode('sel-pie', 'text', props: ['text' => '[Enter] continuar · [Esc] volver']);

        return new TuiNode('raiz', 'container', props: ['direction' => 'vertical'], children: $hijos);
    }

    /**
     * Trae a la pantalla lo que la sesión ya vivió — retomar sin ver es no retomar.
     *
     * La conversación es del PROCESO y los turnos son del STREAM: al abrir sobre una sesión que ya
     * existe, la pantalla arrancaba en blanco y `--continue` se sentía igual que empezar de cero,
     * aunque el agente sí llevara su historial. Se lee del stream porque es la única fuente — y se
     * traen los últimos, no todos: lo que cabe es la cola, igual que en el resto de esta pantalla.
     */
    private function rehidratar(): void
    {
        $sesion = $this->sesionActual();
        if ($sesion === null || $this->conversation !== [] || $this->rehidratada) {
            return;
        }

        $this->rehidratada = true;
        // Los turnos de HERRAMIENTA no se traen como conversación. El stream los guarda —y hace
        // bien, son la evidencia de lo que el agente miró— pero su contenido es el JSON crudo que la
        // herramienta contestó: pegado en la charla es ruido que tapa lo que el humano vino a leer.
        // La sesión no pierde nada; `agent:show` y `agent:timeline` siguen enseñándolos enteros.
        $conversables = array_values(array_filter(
            $sesion->turns,
            static fn (array $t): bool => \in_array($t['role'], ['user', 'assistant'], true) && trim($t['content']) !== '',
        ));

        foreach (\array_slice($conversables, -6) as $turno) {
            $this->conversation[] = [
                'quien' => $turno['role'] === 'user' ? 'tú' : 'agente',
                'texto' => $turno['content'],
            ];
        }
    }

    /** La sesión ahora mismo, o `null` si esta pantalla no corre sobre una. */
    /**
     * La petición que la compuerta interrumpió: el último turno del usuario.
     *
     * Cae al `goal` sólo si la sesión no tiene turnos —una que se pausó antes de decir nada— y a una
     * frase neutra si tampoco hay goal. El orden importa: el goal es un respaldo, no la respuesta.
     */
    private function peticionInterrumpida(Session $sesion): string
    {
        foreach (array_reverse($sesion->turns) as $turno) {
            if ($turno['role'] === 'user' && $turno['content'] !== '') {
                return (string) $turno['content'];
            }
        }

        return $sesion->goal !== '' ? $sesion->goal : 'continúa con lo que estabas haciendo';
    }

    private function sesionActual(): ?Session
    {
        if ($this->sesion === null) {
            return null;
        }

        $sesion = ($this->sesion)();

        return $sesion instanceof Session ? $sesion : null;
    }

    private function tree(): TuiNode
    {
        $this->rehidratar();

        // LA PORTADA, mientras nadie haya escrito nada. No es decoración: es donde se contesta «¿con
        // qué modelo estoy hablando y qué puede tocar?» sin teclear un comando. Un agente que no
        // dice con qué está pensando obliga a confiar sin poder verificar.
        if ($this->conversation === [] && !$this->eligiendoSesion && $this->actividad === null && $this->sesionEnBlanco()) {
            return $this->portada();
        }

        if ($this->eligiendoSesion) {
            return $this->selectorDeSesiones();
        }

        $sesion = $this->sesionActual();

        $hijos = [new TuiNode('titulo', 'text', props: [
            'text' => $sesion !== null
                ? "sesión {$sesion->id} · {$sesion->mode->value}"
                : 'El agente de esta app · trabaja con tus operaciones',
        ])];

        // EL ESTADO ANTES QUE LA CONVERSACIÓN, y eso es P16.7 entero. Lo que hace usable una corrida
        // de cuarenta pasos no es releer lo que dijo: es ver en qué va. La transcripción es lo que
        // sobra cuando la pantalla es chica, no lo que se conserva.
        if ($sesion !== null) {
            foreach ($this->estado($sesion) as $i => $linea) {
                $hijos[] = new TuiNode("estado:{$i}", 'text', props: ['text' => $linea]);
            }
            $hijos[] = new TuiNode('sep-estado', 'text', props: ['text' => str_repeat('─', 40)]);
        }

        if ($this->conversation === []) {
            $hijos[] = new TuiNode('vacio', 'text', props: [
                'text' => $sesion !== null && $sesion->question !== null
                    ? 'Está esperando tu respuesta.'
                    : 'Pregúntale algo. Por ejemplo: «¿qué plugins están encendidos?»',
            ]);
        }

        // Sólo la COLA de la conversación. Una sesión larga no cabe en la pantalla, y la parte que
        // importa es la de abajo — la de arriba ya está resumida en el estado.
        // RECORTADA POR LÍNEAS Y NO POR TURNOS, y ésa era la falla: seis turnos caben o no según lo
        // que midan, y una sola respuesta de modelo trae veinte a cuarenta líneas de markdown.
        // Pasado el alto del terminal el árbol colapsaba y la pantalla quedaba con la línea de ayuda
        // y nada más — sin excepción y sin stderr. El trabajo se había hecho bien y quien miraba
        // veía una pantalla en blanco, que es el peor modo de falla que puede tener una superficie.
        //
        // Se conserva el FINAL: lo último que dijo el agente es lo que hay que leer. Lo que se va no
        // se pierde —está en el stream, que es la bitácora— pero la pantalla tiene un tamaño.
        // CADA VOZ SE VE DISTINTA, y no es cosmética: en una pantalla donde todo se pinta igual hay
        // que LEER para saber quién habló, y operar un agente es poder distinguir de un vistazo lo
        // que uno pidió de lo que él contestó. Lo del humano lleva su marca `›`; lo del agente se
        // rinde como markdown —negritas, listas, bloques de código— que es como el modelo escribe.
        $lineas = [];
        foreach (\array_slice($this->conversation, -6) as $i => $turno) {
            // TRES VOCES Y NO DOS. Lo que dice el MODELO es markdown y se rinde como tal; lo que
            // dice el SISTEMA —un error, una confirmación— es literal y se pinta crudo. Mezclarlas
            // costó ver `ANTHROPIC_API_KEY` convertido en `ANTHROPICAPIKEY`: markdown leyó los
            // guiones bajos como énfasis y se comió el nombre de la variable que hacía falta poner.
            $voz = $turno['voz'] ?? ($turno['quien'] === 'tú' ? 'humano' : 'agente');

            // ── WHAT THE MODEL SAYS TRAVELS WHOLE, AS ONE BLOCK ────────────────────────────────
            //
            // It used to be split on `\n`, one markdown node per line. Rod caught it in a capture:
            // `### Main operations:` was showing its hashes.
            //
            // Two causes, both from the splitting:
            //
            //   · the two-space indent that marks «the model said this» lived INSIDE the text, and
            //     an indented markdown heading stops being a heading — the bullet survived and the
            //     `###` did not, which is why markdown looked like it worked «sometimes»;
            //   · and an ordered list cannot renumber itself when every row is its own list of one
            //     item: the model wrote `1.` six times and six `1.` came out.
            //
            // Whole, the renderer sees the entire block: headings, lists that number themselves,
            // fenced code. The indent travels as `paddingX`, which the renderer applies AFTER
            // parsing — same look, nothing broken.
            if ($voz === 'agente') {
                $lineas[] = ["turno:{$i}", $turno['texto'], $voz];

                continue;
            }

            foreach (explode("\n", $turno['texto']) as $j => $linea) {
                $lineas[] = [
                    "turno:{$i}:{$j}",
                    // EL MARCADOR DICE DE QUIÉN ES LA LÍNEA (spec-lenguaje-visual-tui.md).
                    //
                    // `›` lo tuyo, `■` lo que dijo el SISTEMA, y el modelo **sin marcador**: lo que
                    // se marca es lo que NO es prosa suya. Así, cualquier cosa que escriba —incluida
                    // una imitación perfecta de una pregunta de permiso, que es lo que hizo el
                    // 2026-08-04— sale sin `■`, y no puede fabricarlo porque el marcador no está en
                    // su texto: lo pone esta pantalla según de dónde vino el turno.
                    //
                    // Va en la primera línea nomás: repetirlo en cada renglón de un párrafo haría
                    // ruido y el bloque ya se lee como uno solo por la sangría.
                    ($j === 0 ? match ($voz) {
                        'humano' => '› ',
                        'sistema' => '■ ',
                        default => '  ',
                    } : '  ') . $linea,
                    $voz,
                ];
            }
        }

        // El presupuesto: el alto menos lo que SIEMPRE se pinta —título, estado, separadores, prompt
        // y ayuda— con holgura. Nunca menos de tres: una pantalla diminuta debe mostrar algo, no
        // vaciarse por aritmética.
        // EL CHROME SE CUENTA, NO SE ADIVINA. Este margen era una constante —8— y ya me mordió tres
        // veces: cada vez que la pantalla gana una línea fija (la barra de estado, el badge, una
        // ayuda) el número queda corto y el árbol desborda, que en este motor no es un recorte sino
        // una pantalla en blanco. Se derivan las que se van a pintar DESPUÉS de la conversación.
        // LA TABLA, si la última herramienta devolvió una. Va DESPUÉS de la conversación y antes
        // del estado: es lo más reciente que se sabe del sistema, y lo que alguien mira para decidir
        // qué pedir después. Cuesta su alto —cabecera, filas, caption— y por eso entra en el chrome:
        // una tabla que desborda deja la pantalla en blanco, y ya van tres veces con ese cálculo.
        $altoTabla = $this->tabla !== null ? min(\count($this->tabla['filas']), 8) + 3 : 0;

        // separador · badge · barra de estado · prompt · ayuda, más aire; y tres más si hay una
        // pregunta esperando —propia o de un sub-agente—, que se pinta con su motivo y sus opciones.
        $deHijo = $sesion?->question === null ? $this->preguntaDeHijoPendiente() : null;
        // EL CAMPO CUENTA POR LO QUE MIDE, no por uno. Es lo único del chrome que cambia de tamaño
        // mientras alguien escribe, y darlo por fijo devuelve el desborde: el árbol pasa del alto de
        // la terminal y este motor no recorta, deja la pantalla en blanco. Ya van cuatro veces con
        // este cálculo, y ésta es la primera en que uno de los sumandos respira.
        $fieldHeight = $this->heightOf(new TuiNode('prompt', 'text-input', props: [
            'value' => $this->entrada,
            'prompt' => '› ',
            'multiline' => true,
            'maxLines' => 6,
        ]));
        $chrome = 6 + $fieldHeight + (($sesion?->question !== null || $deHijo !== null) ? 3 : 0) + $altoTabla;
        $presupuesto = max(3, $this->height - \count($hijos) - $chrome);

        // LAS TECLAS NECESITAN SABER CUÁNTO CABE Y CUÁNTO HAY. Sin esto, «una página» sería un
        // número inventado y el tope del desplazamiento no existiría: se podría subir para siempre
        // por encima del principio de la conversación (greenhouse evidence/0167).
        $this->alturaTranscript = $presupuesto;
        $this->filasTotales = 0;
        foreach ($lineas as [$idT, $textoT, $vozT]) {
            $this->filasTotales += $this->heightOf($vozT === 'agente'
                ? new TuiNode($idT, 'markdown', props: ['content' => $textoT, 'wrap' => true, 'paddingX' => 2])
                : new TuiNode($idT, 'text', props: ['text' => $textoT]));
        }
        $this->desplazamiento = min($this->desplazamiento, max(0, $this->filasTotales - $presupuesto));

        // Lo que el lector dejó abajo al subir: se salta desde el final antes de empezar a llenar.
        $porSaltar = $this->desplazamiento;
        $rowsBelow = 0;
        /** @var list<string> $saltados */
        $saltados = [];

        // ── UNA CONVERSACIÓN SE DESPLAZA; NO SE REPARTE ────────────────────────────────────────
        //
        // El presupuesto era de ENTRADAS y el motor repartía el alto en partes iguales entre ellas,
        // así que a más dicho, menos se veía de cada cosa — justo cuando más hay que leer. Con la
        // pantalla llena, cada bloque acababa en una o dos líneas, cortadas a media palabra y sin
        // nada que lo dijera. Rod lo vio en el uso real; visto en tmux, es exactamente eso.
        //
        // Ahora el presupuesto es de RENGLONES y se gasta desde lo más nuevo hacia atrás: lo último
        // dicho se ve entero y lo viejo sale por arriba, que es como se lee una conversación.
        //
        // Cada nodo declara su `height`. Es lo que apaga el reparto: el motor sólo divide entre los
        // hijos que NO lo declaran. Y el alto se lo pregunta al renderer que va a pintarlo
        // —`measureHeight`— porque contarlo aquí sería una segunda implementación del salto de línea
        // que coincide con la primera hasta el día que no, y ese desacuerdo se ve como texto que
        // falta sin que nadie sepa que faltaba.
        $visible = [];
        $spent = 0;
        $allFits = true;
        foreach (array_reverse($lineas) as [$id, $texto, $voz]) {
            // SE SALTA LO QUE EL LECTOR DEJÓ ABAJO. Va aquí y no en un `array_slice` previo
            // porque el salto se cuenta en RENGLONES pintados, no en entradas: una respuesta del
            // modelo es UNA entrada y puede medir media pantalla.
            $altoSalto = $this->heightOf($voz === 'agente'
                ? new TuiNode($id, 'markdown', props: ['content' => $texto, 'wrap' => true, 'paddingX' => 2])
                : new TuiNode($id, 'text', props: ['text' => $texto]));

            if ($porSaltar > 0) {
                $porSaltar -= $altoSalto;
                $rowsBelow += $altoSalto;
                $saltados[] = $id;
                $allFits = false;

                continue;
            }

            $nodo = $voz === 'agente'
                // `markdown` y no `text`: el modelo escribe con negritas, listas y bloques de
                // código, y pintarlo plano descarta información que él sí puso.
                //
                // `paddingX` rather than two spaces in the text: the indent is the mark saying the
                // model said this, and inside the content it breaks the markdown it claims to render.
                ? new TuiNode($id, 'markdown', props: ['content' => $texto, 'wrap' => true, 'paddingX' => 2])
                : new TuiNode($id, 'text', props: ['text' => $texto]);

            $height = $this->heightOf($nodo);

            // THE NEWEST IS SHOWN EVEN IF IT DOES NOT FIT — by its TAIL, which is what was said last.
            //
            // A model turn is one block now, and one block can be taller than the screen. Under a
            // strict «whole or nothing» rule the long answer — the one most worth reading — would be
            // the only one nobody ever sees.
            if ($visible === [] && $height > $presupuesto && $voz === 'agente') {
                $visible[] = new TuiNode($nodo->id, $nodo->type, props: [
                    ...$nodo->props,
                    'height' => $presupuesto,
                    'scrollToBottom' => true,
                ]);
                $spent = $presupuesto;
                $allFits = false;

                break;
            }

            if ($spent + $height > $presupuesto) {
                // NINGÚN NODO SE PINTA A MEDIAS. Un nodo es UNA línea del texto original, que al
                // ancho de la terminal puede ocupar varios renglones; dejarlo entrar con la mitad de
                // sus renglones es la forma de corte que no se distingue de «ahí terminó», y es el
                // defecto que esta rebanada existe para quitar.
                //
                // Lo que NO afirma: que un turno completo entre o no entre. Un turno se parte en una
                // línea por nodo, así que la cola puede empezar a media respuesta del modelo. Eso se
                // ve —hay contenido arriba y el contador lo dice— y es distinto de un corte mudo.
                $allFits = false;
                break;
            }
            $spent += $height;
            $visible[] = new TuiNode(
                $nodo->id,
                $nodo->type,
                props: [...$nodo->props, 'height' => $height],
            );
        }

        // Y SE DICE LO QUE NO SE VE. Una pantalla que corta sin avisar es peor que una que no cabe:
        // quien lee no tiene cómo distinguir «terminó ahí» de «no cupo».
        // ROWS ARE COUNTED — the one unit that means the same thing for every block.
        //
        // This counter has already lied TWICE in its noun, both times with the right number. It said
        // «entries» when the unit was the line, and «lines» when the model's turn became a single
        // block. A correct number under the wrong name is worse than a wrong one: it reads without
        // raising suspicion.
        //
        // A row does not depend on how the conversation happens to be chopped up inside, and it is
        // what the reader wants to know: how much is left to see, not how many pieces we store.
        $rowsAbove = 0;
        if (!$allFits) {
            $placed = [];
            foreach ($visible as $node) {
                $placed[$node->id] = true;
            }
            // LO DE ABAJO NO CUENTA COMO DE ARRIBA. Este bucle contaba TODO lo que no se pintó, y al
            // subir del todo eso incluye lo que quedó por debajo del lector: el aviso decía «103
            // arriba · 103 abajo» estando en el principio, donde arriba no queda nada. Un número
            // correcto bajo el nombre equivocado se lee sin levantar sospecha, que es justo lo que
            // este contador ya había hecho dos veces con su sustantivo.
            foreach ($saltados as $idSaltado) {
                $placed[$idSaltado] = true;
            }
            foreach ($lineas as [$id, $texto, $voz]) {
                if (isset($placed[$id])) {
                    continue;
                }
                $rowsAbove += $this->heightOf($voz === 'agente'
                    ? new TuiNode($id, 'markdown', props: ['content' => $texto, 'wrap' => true, 'paddingX' => 2])
                    : new TuiNode($id, 'text', props: ['text' => $texto]));
            }
        }
        // EL AVISO DICE LAS DOS DIRECCIONES, y ahora nombra la tecla que lo resuelve.
        //
        // Mientras no se podía subir, «N más arriba» era toda la verdad porque siempre estabas al
        // final. En cuanto se puede, callar lo de abajo deja al humano sin saber que se está
        // perdiendo lo más nuevo — y un aviso que no dice cómo llegar es el defecto que este slice
        // vino a quitar, sólo que en su otra mitad (greenhouse evidence/0167).
        if ($rowsAbove > 0 || $rowsBelow > 0) {
            $partes = [];
            if ($rowsAbove > 0) {
                $partes[] = sprintf('↑ %d renglón(es) más arriba', $rowsAbove);
            }
            if ($rowsBelow > 0) {
                $partes[] = sprintf('↓ %d más abajo', $rowsBelow);
            }
            $partes[] = $rowsBelow > 0 ? '[End] al final' : '[↑] subir';

            $hijos[] = new TuiNode('conv-arriba', 'text', props: [
                'text' => '  ' . implode(' · ', $partes),
                'height' => 1,
            ]);
        }

        foreach (array_reverse($visible) as $nodo) {
            $hijos[] = $nodo;
        }

        // UN SOLO HIJO ELÁSTICO, Y A PROPÓSITO. Con todos los altos declarados el árbol ya no se
        // estira, así que una conversación corta dejaba el campo y la barra flotando a media
        // pantalla en vez de abajo. Este espaciador se come lo que sobre: es la única pieza que
        // reparte, y reparte con uno solo, que es el caso donde el reparto no le quita a nadie.
        $hijos[] = new TuiNode('empuje', 'spacer', props: []);

        $hijos[] = new TuiNode('separador', 'text', props: ['text' => str_repeat('─', 40)]);

        // Si está esperando, la PREGUNTA ocupa el lugar del prompt: es lo que hay que hacer, no un
        // aviso al costado.
        $pendiente = $sesion?->question;
        if ($pendiente !== null) {
            $hijos[] = new TuiNode('pregunta', 'text', props: ['text' => '■ ⏸ ' . $pendiente->question]);
            if ($pendiente->why !== null) {
                $hijos[] = new TuiNode('pregunta-por', 'text', props: ['text' => '  con ' . $this->sobreQue($pendiente->why)]);
            }
            $hijos[] = new TuiNode('pregunta-op', 'text', props: [
                'text' => '  ' . $this->afordancias($pendiente->options),
            ]);
        } elseif ($deHijo !== null) {
            // La pausa del sub-agente ocupa el mismo lugar que ocuparía la propia: es lo que hay que
            // hacer. El id va adentro para que quien conteste sepa A QUIÉN le está contestando.
            $hijos[] = new TuiNode('pregunta', 'text', props: [
                // `└` porque es trabajo de OTRA sesión, con su propio techo de autonomía: la esquina
                // dice subordinación sin explicarla, y confundir al hijo con el principal ya costó un
                // defecto —`--continue` abría la sesión de un hijo y quedabas hablando con el hijo de
                // nadie—.
                'text' => '└ ⏸ (sub-agente ' . $deHijo['session'] . ') ' . $deHijo['question'],
            ]);
            $hijos[] = new TuiNode('pregunta-op', 'text', props: [
                            'text' => '  ' . $this->afordancias($deHijo['options']),
                        ]);
        }

        // LA ACTIVIDAD SUBE A SU PROPIA BARRA, y el renglón de entrada deja de prestarse.
        //
        // Compartirlo tenía un costo que no se ve hasta que pasa: mientras el agente trabaja, lo que
        // uno escribió desaparece de la pantalla. La barra dice en qué está el sistema; el renglón de
        // abajo sigue siendo tuyo.
        // EL ESTADO CON COLOR Y CON MOVIMIENTO, porque «¿sigue vivo?» se contesta mirando, no
        // leyendo. El badge lleva el rol semántico —quien pinta le da su color— y el indicador gira
        // mientras hay trabajo: un carácter que cambia es actividad observable; uno fijo, no.
        if ($this->tabla !== null && $altoTabla > 0) {
            $hijos[] = new TuiNode('tabla', 'data-table', props: [
                'columns' => array_map(
                    static fn (string $c): array => ['key' => $c, 'label' => $c],
                    $this->tabla['columnas'],
                ),
                'rows' => \array_slice($this->tabla['filas'], 0, 8),
                'caption' => $this->tablaDe !== null ? 'lo que devolvió ' . $this->tablaDe : '',
                'showHeader' => true,
                'height' => $altoTabla,
            ]);
        }

        $hijos[] = new TuiNode('estado-insignia', 'badge', props: [
            'label' => $this->etiquetaDeEstado(),
            'role' => $this->rolDeEstado(),
            'width' => 16,
        ]);
        $hijos[] = new TuiNode('estado-barra', 'status-bar', props: [
            'indicator' => $this->actividad !== null ? self::PULSO[$this->pulso % \count(self::PULSO)] : '○',
            // THE LEFT IS THE ACTIVITY, and at rest there is none — so it stays empty.
            //
            // It read `?? 'listo'` and the screen ended with «listo» twice in a row: once in the
            // badge, which exists to name the state and give it colour, and once here. Rod caught it
            // in a capture. Two pieces saying the same thing do not reinforce each other: they teach
            // the reader that one of them is not worth reading, and then nobody knows which.
            'left' => $this->actividad ?? '',
            // A la derecha lo que costó la última vuelta, que es la otra pregunta que uno se hace
            // mirando una pantalla: no sólo «¿está viva?», también «¿cuánto lleva?».
            'right' => $this->ultimoCosto ?? '',
        ]);

        // ── DONDE ESCRIBE EL HUMANO: UN CAMPO QUE CRECE, NO UN RENGLÓN ─────────────────────────
        //
        // Era un nodo `text` de una línea, así que una respuesta larga se salía por la derecha sin
        // decirlo: exactamente el mismo corte mudo que esta rebanada quitó de la conversación, en el
        // único sitio donde el corte se lo hace uno a sí mismo mientras escribe.
        //
        // Y ahí es donde más falta hace el espacio. La tercera afordancia de una pregunta —después
        // de sí y de no— es «escribo la mía»: corregir, poner una condición, dar contexto. Nada de
        // eso cabe en una línea.
        //
        // Crece hacia ARRIBA sin que nadie lo programe: va debajo de la conversación en una pila
        // vertical, así que cada renglón que toma es un renglón que la conversación cede. El tope de
        // 6 no es estético — sin él, pegar un párrafo se comería la conversación que está
        // contestando.
        $field = new TuiNode('prompt', 'text-input', props: [
            'value' => $this->entrada,
            'prompt' => '› ',
            'placeholder' => $pendiente !== null ? 'tu respuesta…' : 'escribe tu pregunta…',
            'multiline' => true,
            'maxLines' => 6,
            'focused' => true,
        ]);
        $hijos[] = new TuiNode(
            $field->id,
            $field->type,
            props: [...$field->props, 'height' => $this->heightOf($field)],
        );
        $hijos[] = new TuiNode('ayuda', 'text', props: [
            'text' => $pendiente !== null
                ? '  [Enter] contestar · [Esc] limpiar · [Ctrl-C] salir'
                : '  [Enter] preguntar · [Esc] limpiar · [Ctrl-C] salir',
        ]);

        // ── TODO NODO DECLARA SU ALTO, Y NO ES UN DETALLE ──────────────────────────────────────
        //
        // El motor reparte el espacio sobrante SÓLO entre los hijos que no lo declaran. Basta con
        // que uno se quede sin declararlo para que se lleve todo lo que los demás dejaron libre — y
        // eso fue exactamente lo que pasó: la conversación y el campo declararon el suyo, los nodos
        // del encabezado no, y el encabezado se comió la pantalla hasta empujar al campo fuera. En
        // pantalla se veía como que el campo «no crecía»; en realidad no le quedaba dónde.
        //
        // Medirlos a todos apaga el reparto por completo. Es la misma regla que hizo que la
        // conversación se desplazara en vez de dividirse, aplicada al árbol entero.
        return new TuiNode('root', 'box', props: ['title' => 'coa · agent'], children: array_map(
            fn (TuiNode $n): TuiNode => isset($n->props['height']) || $n->id === 'empuje'
                ? $n
                : new TuiNode($n->id, $n->type, props: [...$n->props, 'height' => $this->heightOf($n)], children: $n->children),
            $hijos,
        ));
    }

    /**
     * Las líneas del estado: objetivo, plan, pendientes, permisos y qué costó la última vuelta.
     *
     * Los pendientes van con su marca —`[x]`, `[~]`, `[!]`, `[ ]`— porque lo que se busca de un
     * vistazo es cuáles faltan, y un estado escrito con palabras obliga a leer cada renglón para
     * saberlo.
     *
     * @return list<string>
     */
    private function estado(Session $sesion): array
    {
        $lineas = ['  ' . $sesion->goal];

        if ($sesion->plan !== null && trim($sesion->plan) !== '') {
            foreach (explode("\n", trim($sesion->plan)) as $linea) {
                $lineas[] = '  ' . $linea;
            }
        }

        foreach ($sesion->todos as $todo) {
            $marca = match ($todo->status) {
                TodoStatus::Done => '[x]',
                TodoStatus::InProgress => '[~]',
                TodoStatus::Blocked => '[!]',
                TodoStatus::Pending => '[ ]',
            };
            $lineas[] = "  {$marca} {$todo->text}";
        }

        if ($sesion->permissions !== []) {
            $lineas[] = '  autorizado: ' . implode(', ', $sesion->permissions);
        }

        // Lo que se está gastando. Los pasos son el presupuesto de una vuelta y las herramientas son
        // el alcance que tuvo — sin los dos, «el agente contestó» no distingue entre haber trabajado
        // y haber contestado de memoria.
        if ($this->ultimoCosto !== null) {
            $lineas[] = '  ' . $this->ultimoCosto;
        }

        if ($sesion->compactedThrough > 0) {
            // Que se haya compactado se DICE: la sesión empieza a contestar sobre un resumen y no
            // sobre lo que se lee arriba, y no saberlo se depura durante una hora.
            $lineas[] = '  (compactada — el registro completo sigue en la sesión)';
        }

        return $lineas;
    }
}
