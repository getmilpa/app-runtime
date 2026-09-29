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

namespace Milpa\AppRuntime\Console;

use Milpa\AppRuntime\Config\MachineOverlay;
use Milpa\AppRuntime\Config\SecretOverlay;
use Milpa\AppRuntime\Auth\PresentedToken;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\SignerAuthority;
use Milpa\AppRuntime\Policy\PolicyConfig;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\AppRuntime\Agent\SurfaceComposition;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\KernelDefinition;
use Milpa\AppRuntime\Support\PhpBinary;
use Milpa\DevTools\Doctor\Repair;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;
use Milpa\Command\RollbackContracts;
use Milpa\Console\CliProjector;
use Milpa\Console\CliRunner;
use Milpa\Console\McpProjector;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\Console\Rendering\JsonCliRenderer;
use Milpa\Console\Rendering\PlainTextCliRenderer;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\Runtime\Config;
use Milpa\AppRuntime\Tui\AgentScreen;
use Milpa\Agent\Session;
use Milpa\DevTools\Doctor\AppDoctor;
use Milpa\Console\State\InspectableSections;
use Milpa\Console\Tui\ConsoleScreen;
use Milpa\Console\Tui\OperationsScreen;
use Milpa\Live\Tui\StreamTerminal;
use Milpa\Runtime\Kernel;

/**
 * El `coa` de esta app: arranca el kernel, junta los átomos y despacha uno.
 *
 * ── POR QUÉ ESTO CABE EN DOSCIENTAS LÍNEAS ──────────────────────────────────────────────────────
 *
 * Porque no sabe hacer nada. No trae `doctor`, ni `validate`, ni `make`, ni `inspect`: los recibe.
 * Cada capacidad es una {@see Operation} que alguien declaró —un paquete, un plugin, esta app— y
 * este archivo sólo la proyecta a la terminal y la corre.
 *
 * El punto de entrada anterior de la familia, `milpa/skeleton`, escribía quince comandos a mano en
 * 1402 líneas. La diferencia no es de estilo: allá agregar una capacidad significaba editar el
 * despachador, y exponerla a un agente significaba escribirla otra vez. Aquí un plugin que implementa
 * {@see CommandProvider} aparece en la terminal, en MCP y en la TUI sin que este archivo se entere.
 *
 * ── SIN SYMFONY CONSOLE, A PROPÓSITO ────────────────────────────────────────────────────────────
 *
 * La tesis de este paquete es un piso mínimo. `milpa/console` publica la proyección y el runner sin
 * atarse a ningún renderer —ésa es la cláusula 2 de ADR-0035— así que un despachador de argv de este
 * tamaño basta. Meter `symfony/console` traería un framework de comandos entero para volver a
 * declarar lo que el átomo ya declara: su nombre, su descripción y sus entradas.
 *
 * Quien quiera esa ergonomía la instala; que sea una decisión suya y no una herencia es la misma
 * regla que rige el resto de esta app.
 *
 * ── LOS NOMBRES ─────────────────────────────────────────────────────────────────────────────────
 *
 * Un `_` o un `.` del átomo se escriben `:` en la terminal: `plugins.list` se invoca `plugins:list`
 * y `fs_read` se invoca `fs:read`. Los dos separadores dicen jerarquía y la familia usa los dos —el
 * host escribe `_`, `milpa/plugin` escribe `.`—; traducir aquí deja que cada paquete conserve su
 * convención y que la terminal tenga la suya. No hay prefijo `coa:` porque el binario ya se llama así.
 */
final class Application
{
    /** The recovery doors that run when the house does not boot (greenhouse decisions/0506) — nothing else does. */
    private const RECUPERACION = ['sandbox:undo', 'plugins:disable-unsafe'];

    /** @var list<Operation>|null resueltos una vez por corrida */
    private ?array $operations = null;

    /** The watch of the screen running now, when a supervisor started it — what a chat asks before it sends (0519). */
    private ?StaleWatchTerminal $vigia = null;

    /** Sobre cuál sesión corre `coa chat`. Se fija al despachar el comando. */
    private string $sesionDelChatId = 'chat';

    /**
     * El id de una sesión nueva: la fecha y un sufijo corto.
     *
     * Legible a propósito —`chat-0802-a3f1`— porque quien la va a retomar la elige de una lista y
     * no de un hash: un identificador que nadie puede leer obliga a abrir cada sesión para saber
     * cuál era. La fecha ordena; el sufijo evita el choque de dos chats del mismo día.
     */
    private function sesionNueva(): string
    {
        return 'chat-' . date('md') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    }

    /**
     * La última sesión que se tocó, o `null` si esta app no tiene ninguna.
     *
     * «Última» es por el evento más reciente y no por el nombre: una sesión que se retomó ayer está
     * más viva que otra que se creó hoy y nadie usó. El almacén sabe el orden porque el stream lo
     * sabe — preguntarle es más barato que recordarlo aparte, y no puede desincronizarse.
     */
    private function ultimaSesion(): ?string
    {
        $almacen = $this->almacenDeSesiones();
        if ($almacen === null) {
            return null;
        }

        $ultima = null;
        $masReciente = -1;
        // ONE read of the log, not one per session — the same quadratic as the /sessions selector
        // (greenhouse evidence/0265). `coa chat --continue` runs this at startup, so a many-session
        // store made even opening the last chat hang.
        foreach ($almacen->loadAll() as $id => $sesion) {
            // UNA HIJA NO ES UNA CONVERSACIÓN QUE ALGUIEN TUVO, y por eso no se retoma.
            //
            // Visto manejando el TUI el 2026-08-04: `coa chat --continue` abrió
            // `jornada-f73aba.sub-real` —una sesión de sub-agente que dejó una prueba unitaria— y el
            // humano se encontró conversando con el hijo de nadie. La última sesión del almacén no es
            // la última CHARLA: los hijos escriben en el mismo stream y suelen ser los más recientes,
            // porque nacen a mitad de una vuelta.
            //
            // Un hijo se retoma con `agent_resume`, que es otro verbo y otra autoridad. Aquí se busca
            // con quién estabas hablando tú.
            if ($sesion->parentId !== null) {
                continue;
            }

            $seq = $sesion->turns === [] ? 0 : (int) ($sesion->turns[\count($sesion->turns) - 1]['seq'] ?? 0);
            if ($seq >= $masReciente) {
                $masReciente = $seq;
                $ultima = $id;
            }
        }

        return $ultima;
    }

    /**
     * Con qué modelo se va a hablar, dicho como lo declara el entorno.
     *
     * `proveedor:modelo` y no sólo el modelo: `qwen3-coder:30b` en un endpoint local y el mismo
     * nombre contra un proxy remoto no son la misma cosa para quien va a pagar la corrida.
     */
    private function modeloDelAgente(): string
    {
        // THE BANNER DOES NOT RESOLVE ANY MORE, and that is the fix rather than a refactor.
        //
        // It used to read getenv() and nothing else, while the call itself read the governed
        // configuration first. A human who configured their agent through `config:set` opened this
        // screen and was told they had configured nothing, while the request went exactly where
        // they had asked — measured on the wire in greenhouse evidence/0165. A lie on the first
        // screen is worse than a wrong value: it teaches that the governed path does not work.
        return AgentEndpoint::describe($this->configDelAgente());
    }

    /** The configuration the agent resolves against, or null before the kernel can answer. */
    private function configDelAgente(): ?Config
    {
        try {
            $contenedor = $this->kernel()->container();
        } catch (\Throwable) {
            return null;
        }

        if (! $contenedor->has(Config::class)) {
            return null;
        }

        $config = $contenedor->get(Config::class);

        return $config instanceof Config ? $config : null;
    }

    /** El almacén de sesiones de esta app, o `null` si el paquete no está. */
    private function almacenDeSesiones(): ?\Milpa\Agent\SessionStore
    {
        if (!class_exists(\Milpa\Agent\SessionStore::class)) {
            return null;
        }

        $agente = new AgentOperations($this->kernel()->container());
        $m = new \ReflectionMethod($agente, 'sessions');

        $almacen = $m->invoke($agente);

        return $almacen instanceof \Milpa\Agent\SessionStore ? $almacen : null;
    }

    /**
     * Las sesiones de esta app, de la más reciente a la más vieja, para que `/sessions` las muestre.
     *
     * @return list<array{id: string, goal: string, turns: int, state: string, seq: int}>
     */
    private function sesionesParaElegir(): array
    {
        $almacen = $this->almacenDeSesiones();
        if ($almacen === null) {
            return [];
        }

        // ONE read of the log, not one per session. `load()` in a loop over `ids()` replayed the whole
        // log per session — O(sessions × events), which froze the `/sessions` selector in the TUI even
        // after the same fix landed in the `agent:sessions` operation. `loadAll()` reads it once
        // (greenhouse evidence/0265: the CLI and the TUI selector were two paths, and only the pin's
        // terminal method caught the one left behind).
        $filas = [];
        foreach ($almacen->loadAll() as $id => $sesion) {
            $filas[] = [
                'id' => $id,
                'goal' => $sesion->goal,
                'turns' => \count($sesion->turns),
                'state' => $sesion->endedBecause !== null
                    ? 'terminada'
                    : ($sesion->question !== null ? 'espera respuesta' : 'viva'),
                'seq' => $sesion->turns === [] ? 0 : (int) ($sesion->turns[\count($sesion->turns) - 1]['seq'] ?? 0),
            ];
        }

        usort($filas, static fn (array $a, array $b): int => $b['seq'] <=> $a['seq']);

        return $filas;
    }

    /**
     * @param string                                             $root        where the app lives
     * @param \Milpa\Console\OperationSigner|null                $firmante    who signs a `--sign` on either door — the ordinary one and the one
     *                                                                        without a kernel ({@see recuperarSinKernel()}); null is gpg
     * @param \Milpa\ToolRuntime\Identity\SignatureVerifier|null $verificador who verifies it; null is gpg. Both exist so the two doors can be
     *                                                                        asked the same question with the same key (greenhouse decisions/0506)
     */
    public function __construct(
        private readonly string $root,
        private readonly ?\Milpa\Console\OperationSigner $firmante = null,
        private readonly ?\Milpa\ToolRuntime\Identity\SignatureVerifier $verificador = null,
    ) {
    }

    /**
     * Corre el comando que traiga `$argv` y devuelve el código de salida del proceso.
     *
     * Es la única puerta del CLI: todo —`coa` a secas, un comando con nombre, la TUI, el chat de una
     * sola vuelta— entra por aquí. El código de retorno es el contrato con la shell, así que ninguna
     * rama puede terminar sin darlo, ni siquiera las que sólo imprimen ayuda.
     *
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            return $this->dispatch($argv);
        } catch (\Throwable $e) {
            // Un arranque que se niega —una operación protegida expuesta sin política, un plugin que
            // pide algo que nadie provee— tiene un mensaje que dice qué arreglar. Sin este catch ese
            // mensaje sale enterrado bajo una traza, y el error se lee como un defecto del framework
            // en vez de como una configuración por corregir.
            $this->line('✗ ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * @param list<string> $argv
     */
    private function dispatch(array $argv): int
    {
        $comando = $argv[1] ?? null;

        if ($comando === null || \in_array($comando, ['help', '--help', '-h', 'list'], true)) {
            return $this->help();
        }

        // PEDIR AYUDA DE UN COMANDO NO PUEDE CORRER EL COMANDO.
        //
        // `--help` sólo se atendía cuando ERA el comando, así que `coa chat --help` caía en el
        // despacho de `chat` y metía a quien preguntaba dentro de una sesión interactiva — en una
        // terminal eso no es ayuda, es una trampa (greenhouse evidence/0199). Y no era sólo `chat`:
        // cualquier `coa <lo que sea> --help` corría la cosa.
        //
        // Quien necesite pasar el texto `--help` como VALOR lo hace con `--clave=--help`, que es la
        // forma con la que ya viajan los valores.
        if (\in_array('--help', $argv, true) || \in_array('-h', $argv, true)) {
            return $this->help();
        }

        // ANTES DE BOOTEAR, y ésa es toda la razón de que viva aquí y no en `config/operations.php`.
        // Una operación se despacha con el kernel arriba; si el grafo de capacidades no cierra, el
        // kernel no arranca y NINGUNA operación corre — incluidas las de diagnóstico. Medido en esta
        // misma app con una capacidad sin proveedor: `plugins:list`, `validate` y `test` caídas, y una
        // línea de error como único dato. La herramienta que explica por qué no arranca no puede
        // necesitar que arranque.
        // Las dos guardas de abajo dicen lo mismo y a propósito no comparten un helper: cada una
        // nombra SU capability y SU `composer require`, y un helper genérico las habría vuelto un
        // «falta algo, mira el catálogo» que obliga a un segundo paso para saber cuál.
        // THE MCP SURFACE, as an operation of the runtime and not a file the skeleton copied (greenhouse
        // decisions/0507): a copied `bin/mcp-server.php` never receives a fix, and the one every house received
        // booted a different kernel than this one — without the machine's config or its secrets.
        if ($comando === 'mcp') {
            return $this->mcp($argv);
        }

        if ($comando === 'doctor' && !Capabilities::installed('devtools')) {
            return $this->faltaCapability(
                '`doctor` lives in the dev tools, and this app does not have them yet.',
                'composer require milpa/devtools',
            );
        }

        if ($comando === 'doctor') {
            return $this->doctor();
        }

        // ── REPARAR TAMPOCO PUEDE NECESITAR QUE ARRANQUE ────────────────────────────────────────
        //
        // `repair` existe también como operación, y ahí la ve el agente. Pero una operación se
        // despacha con el kernel arriba, y el caso que esta reparación atiende es justo aquel en que
        // el kernel NO levanta: medido el 2026-08-04, con una capacidad requerida sin proveedor
        // mueren `coa:doctor`, `coa repair` y cualquier otra operación, las tres con el mismo
        // `[Initialization Error]`.
        //
        // Es el argumento que `doctor` ya había ganado aquí arriba, un paso más adelante: si la
        // herramienta que explica por qué algo no arranca no puede necesitar que arranque, la que lo
        // arregla tampoco.
        //
        // La DECISIÓN es la misma en las dos superficies —`Milpa\DevTools\Doctor\Repair`, junto al
        // doctor que la recomienda— porque dos versiones de
        // «¿procede reparar esto?» discreparían el día que importa: el CLI diciendo que sí y el
        // agente que no, sobre la misma app.
        // Y CON SU GUARDA, como `doctor` tres líneas arriba: la reparación vive en las dev tools, que
        // son OPT-IN. Sin ellas esto reventaba con un `Class not found` — un fatal en vez de una
        // instrucción, que es el peor modo de decir «te falta algo». Lo cazó la ceremonia de release
        // corriendo la suite contra el paquete instalado desde Packagist, no una prueba local.
        // ACTUALIZAR TAMBIÉN CORRE SIN KERNEL, por lo mismo que `doctor` y `repair`: una app que no
        // arranca es justo la que puede necesitar mover sus versiones.
        if ($comando === 'update' && !Capabilities::installed('devtools')) {
            return $this->faltaCapability(
                '`update` lives in the dev tools, and this app does not have them yet.',
                'composer require milpa/devtools',
            );
        }

        if ($comando === 'update') {
            return $this->actualizarSinKernel(\array_slice($argv, 2));
        }

        if ($comando === 'repair' && !Capabilities::installed('devtools')) {
            return $this->faltaCapability(
                '`repair` lives in the dev tools, and this app does not have them yet.',
                'composer require milpa/devtools',
            );
        }

        if ($comando === 'repair') {
            return $this->repararSinKernel(\array_slice($argv, 2));
        }

        // ── DESHACER TAMPOCO PUEDE NECESITAR QUE ARRANQUE (greenhouse decisions/0506) ────────────
        //
        // Lo mismo que `doctor`, `repair` y `update`, medido en evidence/1038 (n5): una promoción que
        // rompe el arranque deja a `sandbox:undo` muriendo del mismo fatal que vino a deshacer. Un fatal
        // no se atrapa: se pregunta ANTES, en un proceso aparte, si la casa arranca. Si arranca, nada
        // cambia; si no, la reversa corre sin tocar un solo archivo de la app.
        // Y la otra vía de recuperación que la casa ya ofrecía, `plugins:disable-unsafe` («Recovery only»):
        // en evidence/1036 (R1) también murió al arrancar, igual que `coa list`.
        if (\in_array($comando, self::RECUPERACION, true)) {
            $porQue = (new \Milpa\AppRuntime\Support\BootProbe())->whyNot($this->root);
            if ($porQue !== null) {
                return $this->recuperarSinKernel($comando, \array_slice($argv, 2), $porQue);
            }
        }

        // Las dos pantallas. No son operaciones y no lo fingen: una operación se ejecuta con lo que
        // trae y contesta, y esto CONVERSA — captura teclas hasta que alguien sale. Que vivan aquí y
        // no en `config/operations.php` es la misma distinción que dejó fuera a `coa:run`.
        if ($comando === 'shell') {
            // THE SHELL OUTLIVES WHAT DEFINES IT TOO (greenhouse decisions/0519, the same split as the panel's in
            // 0507): its list is the catalogue of the kernel it booted, so a plugin disabled in another terminal kept
            // its operations on screen. On a terminal the process the person sees holds no kernel; a child does, and
            // a clean one opens on the operation that had the focus.
            if (!\in_array('--child', $argv, true) && \function_exists('stream_isatty') && @stream_isatty(\STDIN)) {
                return KernelSupervisor::terminal(
                    fn (?string $foco): array => [PhpBinary::path(), $this->coa(), 'shell', '--child'],
                    $this->root,
                    surface: 'shell',
                );
            }

            $definicion = \in_array('--child', $argv, true) ? KernelDefinition::before($this->root) : null;
            $foco = $definicion !== null ? KernelSupervisor::handedOver() : null;
            $shell = new OperationsScreen(
                $this->all(),
                $this->kernel()->container(),
                ...$this->tamano(),
                dispatcher: $this->kernel()->dispatcher(),
            );
            $definicion?->takeInIncluded();
            // Only an operation this kernel still offers: one that left with its plugin is not a place to reopen on.
            foreach ($shell->operations() as $operacion) {
                if ('op:' . $operacion->name === $foco) {
                    $shell->loop()->focus($foco);
                }
            }

            return $this->pantalla($shell, $definicion);
        }

        // THE THIRD SCREEN: the app's own panel, in the terminal.
        //
        // `milpa/console` has carried this dashboard — sections, navigation, a state table per section —
        // with no command to open it since the panel it came from was rewritten (greenhouse
        // decisions/0220). Built, tested, unreachable: the shape decisions/0213 names as debt that looks
        // like a capability.
        //
        // It shows whatever exposes state, and `milpa/admin` exposes EVERY section of the panel — its own
        // and every plugin's — so a plugin that declared a section for the web gains this one without
        // saying a word about a terminal. With nothing installed that exposes state, the screen says so
        // rather than pretending: that is why the help below only announces it when the panel is here.
        if ($comando === 'panel') {
            $json = \in_array('--json', $argv, true);
            $hijo = \in_array('--child', $argv, true);
            $pedida = \is_string($argv[2] ?? null) && !str_starts_with($argv[2], '-') ? $argv[2] : null;

            // THE PANEL OUTLIVES WHAT DEFINES IT (greenhouse decisions/0507). A person keeps it open while a plugin
            // is installed, disabled or promoted and a config is written; the kernel it booted saw none of it — a
            // disabled plugin's section stayed on screen (evidence/1040, p0). So on a terminal the process the
            // person sees holds no kernel: it opens a child that does, and opens a clean one, on the same section,
            // whenever that child finds itself stale.
            if (!$json && !$hijo && \function_exists('stream_isatty') && @stream_isatty(\STDIN)) {
                return KernelSupervisor::terminal(
                    fn (?string $mostrando): array => [PhpBinary::path(), $this->coa(), 'panel', ...($mostrando !== null ? [$mostrando] : []), '--child'],
                    $this->root,
                    $pedida,
                );
            }

            $definicion = $hijo ? KernelDefinition::before($this->root) : null;
            $secciones = new InspectableSections($this->kernel()->plugins());

            // THE TWO AUDIENCES, ONE ENGINE. `InspectableSections`' own docblock says it exists so the
            // JSON an agent reads and the dashboard a person navigates come from the same place —
            // building them twice is how the two end up answering differently about one app. Only the
            // dashboard was ever wired; this is the other half.
            if (\in_array('--json', $argv, true)) {
                return $this->panelEnJson($secciones);
            }

            $definicion?->takeInIncluded();

            return $this->pantalla(new ConsoleScreen($secciones, ...$this->tamano(), initialSection: $pedida), $definicion);
        }

        if ($comando === 'chat' && !Capabilities::installed('agent')) {
            return $this->faltaCapability(
                '`chat` needs the agent, and this app does not have it yet.',
                'composer require milpa/agent milpa/ai-gateway milpa/tool-runtime',
            );
        }

        if ($comando === 'chat') {
            // ── THE CHAT OUTLIVES WHAT DEFINES IT (greenhouse decisions/0519) ────────────────────────
            //
            // A person keeps a chat open for an afternoon; the kernel it booted offered the agent the same tools and
            // the same model all along — a plugin disabled elsewhere kept answering, a model written with
            // `config:set` never reached a turn (evidence/1053, p0). The split of 0507: on a terminal the process
            // the person sees holds no kernel; a child does, and when it finds itself stale a clean one opens on the
            // SAME session, with what the person was typing.
            $hijo = \in_array('--child', $argv, true);
            if (!$hijo && \function_exists('stream_isatty') && @stream_isatty(\STDIN)) {
                $pedidos = array_values(array_filter(\array_slice($argv, 2), static fn (string $a): bool => $a !== '--child'));

                return KernelSupervisor::terminal(
                    function (?string $entrega) use ($pedidos): array {
                        $sesion = ChatHandoff::decode($entrega)?->session;

                        return [PhpBinary::path(), $this->coa(), 'chat', ...($sesion !== null ? [$sesion] : $pedidos), '--child'];
                    },
                    $this->root,
                    surface: 'chat',
                    held: ChatHandoff::lostOnClose(...),
                );
            }

            $definicionDelChat = $hijo ? KernelDefinition::before($this->root) : null;
            $entregada = $definicionDelChat !== null ? ChatHandoff::decode(KernelSupervisor::handedOver()) : null;

            // ── CADA CHAT ES UNA SESIÓN NUEVA, salvo que digas lo contrario ─────────────────────
            //
            // Antes todo caía en una sesión llamada `chat`, y eso mezclaba trabajos que no tenían
            // nada que ver: preguntar por los plugins un martes y depurar un plugin el jueves
            // compartían plan, pendientes y permisos concedidos. Un permiso otorgado para una tarea
            // no debería seguir vigente en otra que nadie relacionó con ella.
            //
            //   coa chat              → sesión nueva
            //   coa chat --continue   → retoma la última que se tocó
            //   coa chat <id>         → ésa, exista o no (nombrarla es crearla)
            //
            // Lo viejo NO se pierde: `/sessions` las lista y deja elegir, y `agent:sessions` sigue
            // siendo la vía por fuera del TUI.
            $pedido = \is_string($argv[2] ?? null) ? trim($argv[2]) : '';
            $this->sesionDelChatId = match (true) {
                $pedido === '--continue' || $pedido === '-c' => $this->ultimaSesion() ?? $this->sesionNueva(),
                $pedido !== '' && !str_starts_with($pedido, '-') => $pedido,
                default => $this->sesionNueva(),
            };

            [$ancho, $alto] = $this->tamano();

            // EL VIGÍA SE REGISTRA ANTES DE ABRIR EL CHAT, y sólo aquí.
            //
            // Es lo que le deja al humano decir «para» a media vuelta: la operación lo consulta entre
            // pasos. Va en el contenedor porque la pantalla no tiene handle sobre la operación —la
            // llama por el registro, que devuelve un arreglo—, y es la misma vía por la que `Config` y
            // `SessionStore` llegan hasta ahí.
            //
            // Sólo en `chat`: una corrida de `coa agent` desde un script no tiene humano mirando, y un
            // vigía leyendo su STDIN se comería la entrada de la tubería.
            $this->kernel()->container()->registerService(\Milpa\AppRuntime\Agent\StepWatcher::class, new \Milpa\AppRuntime\Agent\StepWatcher());

            $chat = new AgentScreen(
                $this->preguntarAlAgente(...),
                $this->sesionDelChat(...),
                $this->contestarEnElChat(...),
                $ancho,
                $alto,
                bienvenida: [
                    // Con qué se está trabajando, contestado ANTES de que alguien teclee. Sale de
                    // las mismas fuentes que el agente usa: la credencial declarada y el catálogo
                    // de operaciones — no de una constante que pueda envejecer aparte.
                    'model' => $this->modeloDelAgente(),
                    'tools' => \count($this->all()),
                    'session' => $this->sesionDelChatId,
                    'nueva' => $pedido !== '--continue' && $pedido !== '-c' && $entregada === null,
                ],
                catalogo: $this->sesionesParaElegir(...),
                continuar: function (string $id): void {
                    $this->sesionDelChatId = $id;
                },
                preguntaDeHijo: $this->preguntaDeHijoPausado(...),
                contestarHijo: $this->contestarAlHijo(...),
                contraofertar: $this->contraofertarEnElChat(...),
                apretar: $this->apretarEnElChat(...),
                // The board region in the chat, from the ONE Live component (greenhouse evidence/0297):
                // hand the screen a closure that folds the CURRENT session via agent:board each frame,
                // so it follows the stream — the same fold the web host renders.
                tablero: function (): array {
                    foreach ((new \Milpa\AppRuntime\Operations\SessionOperations($this->kernel()->container()))->operations() as $op) {
                        if ($op->name === 'agent:board') {
                            return ($op->handler)(['session' => $this->sesionDelChatId]);
                        }
                    }

                    return ['ok' => false, 'error' => 'no board'];
                },
                vigente: $definicionDelChat !== null ? fn (): bool => $this->vigia?->staleNow() === null : null,
                borrador: $entregada->draft ?? '',
                enviarAlAbrir: $entregada->send ?? false,
                contraoferta: $entregada->counter ?? false,
            );

            // THE SCREEN REGISTERS AS A SURFACE, which is how it receives what the agent does while
            // it does it: `AgentOperations` builds the store with `BroadcastingEventStore` as soon
            // as it finds a `SurfaceBroadcaster` in the container. No new channel — it is the same
            // bridge a web page learns from.
            //
            // AND FOR THAT VERY REASON IT NEVER REGISTERS ALONE. The screen and whatever transport
            // the app declared (a Surface registers one from config during boot) are audiences of
            // the same fact, not rivals for the channel: {@see SurfaceComposition} fans them out and
            // says so, instead of the bare screen displacing the transport — or colliding with it,
            // which is what a real `coa chat` with `transport: websocket` did (greenhouse evidence/0294).
            //
            // It goes BEFORE running: the store is built on the first question, and a broadcaster
            // registered later would be late to its own session.
            $contenedor = $this->kernel()->container();
            if ($contenedor instanceof \Milpa\Container\DIContainer) {
                SurfaceComposition::compose($contenedor, $chat);
            }
            $definicionDelChat?->takeInIncluded();

            return $this->pantalla($chat, $definicionDelChat);
        }

        $operacion = $this->find($comando);
        if ($operacion === null) {
            $this->line("✗ no such command «{$comando}»");
            $this->line('');
            $this->help();

            // A NAME THAT IS NOT THERE HAS TO LEAVE A FAILING STATUS BEHIND IT.
            //
            // The catalogue still prints, because showing what DOES exist is the useful answer. What
            // this used to return was `help()`'s zero, so the sentence above was the only trace the
            // failure left: text, and nothing a shell, a Makefile, a CI step or an agent chaining
            // `coa a && coa b` can read. `set -e` does not catch a success.
            //
            // It matters more here than in most runtimes, because this one's whole claim is that an
            // agent can drive it. Asking for a name the app does not carry got told so in prose and
            // told everything was fine by the exit code, and those two answers disagreed.
            return 1;
        }

        $resto = \array_slice($argv, 2);
        $renderer = \in_array('--json', $resto, true) ? new JsonCliRenderer() : new PlainTextCliRenderer();

        // The caller's declared scope is judged before this surface asks for a signature.
        // Use the SAME scope verdict as the agent door; consent remains CliRunner's concern
        // (greenhouse decisions/0314). An absent/unverifiable token keeps 0311's local default.
        $identity = PresentedToken::identity($this->kernel()->container());
        $base = ToolContext::cli();
        $scope = (new PolicyGate())->authorizeScopes(
            new ToolContext(
                principal: $identity->actor->id ?? $base->principal,
                channel: $base->channel,
                scopes: PresentedToken::scopes($identity, $base->scopes),
            ),
            McpProjector::toolName($operacion->name),
            $operacion->scopes,
        );
        if (!$scope->allowed) {
            foreach ($renderer->presentError((string) $scope->reason) as $line) {
                $this->line($line);
            }

            return 1;
        }

        // ONE SIGNATURE PER SEQUENCE (greenhouse decisions/0458, 0500): the first signed call of
        // a session keeps its receipt there, and the calls that continue it cite it instead of
        // signing again. Without the agent package there are no sessions, and nothing to cite.
        $receipts = class_exists(\Milpa\Agent\SessionStore::class)
            ? new \Milpa\AppRuntime\Agent\SessionSequenceReceipts(fn (): ?\Milpa\Agent\SessionStore => $this->almacenDeSesiones())
            : null;
        $tokens = $this->tokens($operacion, $resto);
        $caller = new ToolContext(
            principal: $identity->actor->id ?? $base->principal,
            channel: $base->channel,
            scopes: PresentedToken::scopes($identity, $base->scopes),
        );
        // AN UNSIGNED CALL NEVER RUNS AS THE TERMINAL'S WILDCARD (greenhouse decisions/0522). With no signature and
        // no token, it continues a sequence whose receipt stands — the runner cites it or refuses — or it runs
        // reading only; what asks for more is refused here, before anything runs. Measured (evidence/1050): with the
        // seat's receipt released, 21 build operations of the resident ran as `local-shell` with `*`.
        if ($identity === null && !\in_array('--sign', $tokens, true)) {
            $container = $this->kernel()->container();
            try {
                $input = $operacion->inputSchema !== null ? (new CliRunner())->deriveInput($operacion, $tokens) : [];
            } catch (\Throwable) {
                $input = null; // the runner says what is wrong with the arguments, and runs nothing
            }
            if ($input !== null && !UnsignedTerminal::runnerDecides($operacion, $input, $receipts)) {
                $policy = $container->has(\Milpa\ToolRuntime\Contracts\CallPolicy::class)
                    ? $container->get(\Milpa\ToolRuntime\Contracts\CallPolicy::class) : null;
                $refusal = UnsignedTerminal::refusal(
                    $operacion,
                    $input,
                    $policy instanceof \Milpa\ToolRuntime\Contracts\CallPolicy ? $policy : null,
                    $receipts,
                );
                if ($refusal !== null) {
                    foreach ($renderer->presentError(implode("\n", $refusal)) as $line) {
                        $this->line($line);
                    }

                    return 1;
                }
                $caller = UnsignedTerminal::authority($operacion);
            }
        }

        return (new CliRunner(
            signer: $this->firmante,
            renderer: $renderer,
            verifier: $this->verificador,
            callerAuthority: $caller,
            signerAuthority: fn (VerifiedSigner $signer): ?ToolContext => $this->autoridadDelFirmante($signer, $identity),
            // El despachador del kernel viaja al runner: sin él, un listener que audita operaciones
            // las vería por MCP y no por la terminal — que es el hueco que el runner vino a cerrar.
            dispatcher: $this->kernel()->dispatcher(),
            receipts: $receipts,
        ))->run($operacion, $tokens, $this->kernel()->container(), $this->line(...));
    }

    /**
     * Corre una pantalla contra la terminal — o pinta un frame y sale, si no hay terminal.
     *
     * Si el destino no es una terminal —una tubería, un redirect, CI— no hay con qué ser
     * interactivo. Es un hecho del DESTINO y lo sabe quien tiene el stream (ADR-0025): la pantalla
     * no se entera, y por eso se puede probar sin una.
     */
    /**
     * The panel as one document — every section, its title and its state — for a program to read.
     *
     * The same sections, in the same order, with the state read the same way: what the dashboard paints
     * is what this prints.
     */
    /**
     * `coa mcp`: the house's operations over MCP on stdio — a supervisor that holds the pipe, and a child with the kernel.
     *
     * STDOUT is the protocol, one JSON-RPC message per line; everything a person reads goes to STDERR. The process
     * the client started never boots a kernel ({@see KernelSupervisor}): `--child` is the one that does, and it
     * leaves with {@see KernelSupervisor::STALE} when what it read changed (greenhouse decisions/0507).
     *
     * @param list<string> $argv
     */
    private function mcp(array $argv): int
    {
        // The MCP surface is opt-in: `milpa/mcp-server` does not ship with the app. An app without it is the honest
        // default, not an error — the same exit the skeleton's `bin/mcp-server.php` always gave, on STDERR because
        // STDOUT belongs to the protocol.
        if (!Capabilities::installed('mcp') || !class_exists(\Milpa\McpServer\JsonRpcService::class)) {
            fwrite(\STDERR, 'MCP surface not enabled. Run: php bin/coa capabilities:enable milpa/mcp-server  (or: composer require milpa/mcp-server)' . \PHP_EOL);

            return 0;
        }

        if (\in_array('--child', $argv, true)) {
            // A kernel that refuses to boot must not say so on STDOUT: `run()` would print its `✗` there, and there
            // is the protocol. It goes to STDERR, where the supervisor reads the last line to tell the client why.
            try {
                return $this->mcpChild();
            } catch (\Throwable $e) {
                fwrite(\STDERR, '✗ ' . $e->getMessage() . \PHP_EOL);

                return 1;
            }
        }

        $hijo = [PhpBinary::path(), $this->coa(), 'mcp', '--child'];
        fwrite(\STDERR, 'milpa · ' . Capabilities::CLI . 'mcp — MCP stdio server ready (close stdin to stop)' . \PHP_EOL);

        return (new KernelSupervisor(static fn (): array => $hijo, $this->root, \STDIN, \STDOUT, \STDERR))->relay();
    }

    /**
     * The child of `coa mcp`: boots the SAME kernel as `coa` and serves until the client closes or the house changes.
     *
     * It asks {@see KernelDefinition} twice per request. BEFORE: if another process changed the house, this child
     * does not run the request — it leaves, and the supervisor hands the same line to a clean child. AFTER: if the
     * request itself changed the house (a promotion, a plugin enabled, a config written), it has already answered;
     * it leaves so the next request meets the kernel of now.
     */
    private function mcpChild(): int
    {
        $definicion = KernelDefinition::before($this->root);
        $kernel = $this->kernel();
        $registro = $kernel->toolRegistry();
        if (!$registro instanceof \Milpa\ToolRuntime\ToolRegistry) {
            fwrite(\STDERR, 'milpa · ' . Capabilities::CLI . 'mcp — no tool registry wired, exiting.' . \PHP_EOL);

            return 1;
        }
        // Everything the app declares, not only what the plugins booted — the same catalogue the skeleton's
        // `bin/mcp-server.php` projected, so a client sees the same tools it always saw.
        (new McpProjector())->projectAll(\Milpa\AppRuntime\Support\Operations::all($kernel, $this->root), $registro, $kernel->container());
        $servicio = new \Milpa\McpServer\JsonRpcService($registro);
        $definicion->takeInIncluded();

        $escribir = static function (array $respuesta): void {
            fwrite(\STDOUT, json_encode($respuesta, \JSON_UNESCAPED_SLASHES) . "\n");
            fflush(\STDOUT);
        };

        while (($linea = fgets(\STDIN)) !== false) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }
            if (($porque = $definicion->staleBecause()) !== null) {
                return $this->mcpSale($definicion, $porque, 'before running a request');
            }

            $pedido = json_decode($linea, true);
            if (!\is_array($pedido)) {
                $escribir(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error'], 'id' => null]);
                continue;
            }

            if (($pedido['method'] ?? null) === 'ping') {
                // MCP's liveness check — and the supervisor's way to ask, in silence, whether this kernel is current.
                if (\array_key_exists('id', $pedido)) {
                    $escribir(['jsonrpc' => '2.0', 'id' => $pedido['id'], 'result' => new \stdClass()]);
                }
            } else {
                // This transport carries no auth: ToolContext::stdio() is the context for exactly this case.
                /** @var array<string, mixed> $pedido */
                $respuesta = $servicio->handle($pedido, ToolContext::stdio((string) ($pedido['id'] ?? uniqid('mcp-', true))));
                if ($respuesta !== null) {
                    $escribir($respuesta);
                }
            }

            if (($porque = $definicion->staleBecause()) !== null) {
                return $this->mcpSale($definicion, $porque, 'after the request that changed it');
            }
        }

        return 0;
    }

    /** A stale child of `coa mcp` leaves: it says why on STDERR and lets the next process compile what changed. */
    private function mcpSale(KernelDefinition $definicion, string $porque, string $cuando): int
    {
        $definicion->forgetCompiled();
        fwrite(\STDERR, 'milpa · ' . Capabilities::CLI . "mcp — the house changed ({$porque}), {$cuando}: a clean process takes over" . \PHP_EOL);

        return KernelSupervisor::STALE;
    }

    /** The script a supervisor starts its children with — this app's `bin/coa`. */
    private function coa(): string
    {
        $script = $this->root . '/bin/coa';

        return is_file($script) ? $script : (string) (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: $script);
    }

    private function panelEnJson(InspectableSections $secciones): int
    {
        $salida = [];
        foreach ($secciones->all() as $seccion) {
            $salida[] = [
                'id' => $seccion['id'],
                'title' => $seccion['title'],
                'state' => $seccion['provider']->state(),
            ];
        }

        $this->line((string) json_encode(['sections' => $salida], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return 0;
    }

    private function pantalla(OperationsScreen|AgentScreen|ConsoleScreen $pantalla, ?KernelDefinition $definicion = null): int
    {
        if (!(\function_exists('stream_isatty') && @stream_isatty(\STDIN))) {
            $this->line($pantalla->render());

            return 0;
        }

        // LA PANTALLA ES DE LA PANTALLA, Y NADIE MÁS ESCRIBE EN ELLA.
        //
        // Un TUI pinta con secuencias ANSI sobre la terminal completa, así que CUALQUIER escritura
        // ajena la destruye: un `PHP Warning` de una operación, un `echo` olvidado en un plugin, un
        // aviso de deprecación de una dependencia. No es hipotético — un desajuste de versiones
        // escupió un stack trace de PHP encima de la conversación y la pantalla quedó ilegible, con
        // el agente funcionando perfectamente detrás.
        //
        // Los avisos NO se pierden: van al log de la app, que es donde se leen sin pelearse por el
        // mismo espacio. Lo que se apaga es el canal, no el hecho. Y sólo mientras el TUI corre —
        // fuera de él, la CLI sigue diciendo lo que tenga que decir por su salida de siempre.
        $mostrarErrores = \ini_get('display_errors');
        \ini_set('display_errors', '0');
        $bitacora = \ini_get('error_log');
        $log = $this->root . '/var/coa-tui.log';
        if (@is_dir(\dirname($log)) || @mkdir(\dirname($log), 0o775, true)) {
            \ini_set('error_log', $log);
        }

        // Y SE LE AVISA A QUIEN ESCRIBE A PROPÓSITO. `display_errors` sólo gobierna los avisos que
        // PHP emite; un `fwrite(STDERR)` explícito pasa por encima — verificado. El logger de esta
        // app es justo eso, así que se le dice que la pantalla está tomada.
        \Milpa\AppRuntime\Support\StderrLogger::pantallaTomada(true);

        try {
            return $this->correrPantalla($pantalla, $definicion);
        } finally {
            \Milpa\AppRuntime\Support\StderrLogger::pantallaTomada(false);
            // Se restaura PASE LO QUE PASE: dejar los avisos apagados después de salir del TUI
            // convertiría un arreglo de superficie en una ceguera del proceso entero.
            \ini_set('display_errors', $mostrarErrores === false ? '1' : $mostrarErrores);
            if ($bitacora !== false) {
                \ini_set('error_log', $bitacora);
            }
        }
    }

    /** El bucle en sí, para que el restaurador de arriba tenga un `finally` que lo abrace. */
    private function correrPantalla(OperationsScreen|AgentScreen|ConsoleScreen $pantalla, ?KernelDefinition $definicion = null): int
    {
        $terminal = new StreamTerminal('coa');
        $vigia = $definicion !== null ? new StaleWatchTerminal($terminal, $definicion) : null;
        $this->vigia = $vigia;

        // CON QUÉ PINTAR SIN SALIR DEL BUCLE: la pantalla del agente es síncrona y, mientras el
        // agente trabaja, el bucle no vuelve a pasar. Sin esto se queda idéntica los ~16 segundos que
        // tarda una vuelta, y quedarse idéntica es indistinguible de estar colgada.
        if ($pantalla instanceof AgentScreen) {
            $pantalla->paintOn($terminal);
        }

        $pantalla->loop()->runOn($vigia ?? $terminal);

        // A stale child leaves with what it was showing, so its supervisor opens the next one there: the section of
        // the panel, the operation the shell had the focus on, the chat's session and what the person had typed —
        // and whether it was already sent and held (greenhouse decisions/0507, 0519).
        if ($vigia !== null && $vigia->staleBecause() !== null) {
            $definicion->forgetCompiled();
            $mostrando = match (true) {
                $pantalla instanceof ConsoleScreen => $pantalla->currentSectionId(),
                $pantalla instanceof OperationsScreen => $pantalla->loop()->focusedId(),
                default => (new ChatHandoff($this->sesionDelChatId, $pantalla->borrador(), $pantalla->retenida(), $pantalla->contraofertaRetenida()))->encode(),
            };
            $entrega = @fopen('php://fd/3', 'w');
            if (\is_resource($entrega)) {
                fwrite($entrega, $mostrando . "\n" . ($vigia->settled() ? KernelSupervisor::SETTLED : ''));
                fclose($entrega);
            }

            return KernelSupervisor::STALE;
        }

        return 0;
    }

    /**
     * El ancho y el alto de la terminal, o un tamaño razonable si no hay una.
     *
     * @return array{0: int, 1: int}
     */
    private function tamano(): array
    {
        $terminal = new StreamTerminal('coa');

        return [$terminal->columns(), $terminal->rows()];
    }

    /**
     * Le pregunta al agente, por el MISMO camino que `coa agent`.
     *
     * La pantalla no arma el orquestador ni elige proveedor: eso lo sabe `AgentOperations`, y
     * repetirlo aquí sería un segundo camino a lo mismo — como se llega a que la terminal y el TUI
     * contesten distinto.
     *
     * @return array{ok: bool, answer?: string, steps?: int, tools?: int, error?: string, hint?: string}
     */
    private function preguntarAlAgente(string $prompt): array
    {
        // `/sessions` y `/sessions <id>` SE ATIENDEN AQUÍ, antes de llegar al agente.
        //
        // Cambiar de sesión no es una pregunta: es cambiar el sujeto de la conversación. Mandarlo al
        // modelo gastaría una vuelta y una llamada al proveedor para que conteste sobre algo que el
        // sistema ya sabe — y peor, lo contestaría interpretando en vez de haciendo.
        //
        // Y traer «todo el contexto» no cuesta nada: el chat relee la sesión en cada frame, así que
        // cambiar el id ES traerse el plan, los pendientes, los permisos y la pregunta abierta de la
        // otra. El estado no vive en la pantalla.
        if (str_starts_with($prompt, '/sessions')) {
            return $this->cambiarDeSesion(trim(mb_substr($prompt, \strlen('/sessions'))));
        }

        /** @var array{ok: bool, answer?: string, steps?: int, tools?: int, compacted?: bool, error?: string, hint?: string, paused?: bool, exhausted?: bool, interrupted?: bool} $r */
        $r = $this->correr('agent', ['prompt' => $prompt, 'session' => $this->sesionDelChatId])
            ?? ['ok' => false, 'error' => 'esta app no declara la operación `agent`'];

        return $r;
    }

    /**
     * Lista las sesiones, o se cambia a una — el `/sessions` del chat.
     *
     * Sin argumento enumera con lo que hace falta para elegir: en qué va cada una, cuántos pendientes
     * tiene, y **cuánto quedó sin explicar** ({@see SessionOperations}). Con un id, cambia — y si ese
     * id no existe, lo dice en vez de abrir una sesión vacía con ese nombre, que es lo que haría un
     * default silencioso y dejaría a alguien hablándole a una sesión que creía que ya tenía historia.
     *
     * @return array{ok: bool, answer?: string, error?: string}
     */
    private function cambiarDeSesion(string $id): array
    {
        /** @var array{ok: bool, sessions?: list<array<string, mixed>>}|null $listado */
        $listado = $this->correr('agent:sessions', []);
        $sesiones = \is_array($listado) && \is_array($listado['sessions'] ?? null) ? $listado['sessions'] : [];

        if ($id === '') {
            if ($sesiones === []) {
                return ['ok' => true, 'answer' => 'no hay sesiones todavía. Escribe algo y ésta se abre sola.'];
            }

            $lineas = ['sesiones — escribe `/sessions <id>` para cambiarte:'];
            foreach ($sesiones as $s) {
                $sin = \is_int($s['unexplained'] ?? null) ? $s['unexplained'] : 0;
                $lineas[] = sprintf(
                    '  %-18s %-20s %d pendiente(s)%s%s',
                    (string) ($s['session'] ?? '?'),
                    (string) ($s['state'] ?? '?'),
                    \is_int($s['pending'] ?? null) ? $s['pending'] : 0,
                    $sin > 0 ? sprintf(' · %d cambio(s) sin explicar', $sin) : '',
                    ((string) ($s['session'] ?? '')) === $this->sesionDelChatId ? '   ← estás aquí' : '',
                );
            }

            return ['ok' => true, 'answer' => implode("\n", $lineas)];
        }

        $existe = false;
        foreach ($sesiones as $s) {
            if (((string) ($s['session'] ?? '')) === $id) {
                $existe = true;

                break;
            }
        }

        if (!$existe) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}» — `/sessions` te las lista"];
        }

        $this->sesionDelChatId = $id;

        return ['ok' => true, 'answer' => "ahora estás en «{$id}». Su plan y sus pendientes ya están aquí."];
    }

    /**
     * La sesión sobre la que corre el chat, releída en cada frame.
     *
     * Se relee y no se guarda porque cambia con cada vuelta: el plan que el agente acaba de escribir,
     * el pendiente que acaba de cerrar, la pregunta que acaba de dejar abierta. Una copia guardada en
     * la pantalla sería la de hace un rato, y una interfaz que enseña un estado viejo con cara de
     * actual es peor que una que no lo enseña.
     */
    private function sesionDelChat(): ?Session
    {
        // El mismo almacén que resuelve la operación `agent`, por la misma vía: dos lugares que
        // decidan dónde viven las sesiones son dos lugares donde pueden dejar de coincidir, y el día
        // que lo hicieran el TUI pintaría una sesión que el agente no está escribiendo.
        return (new AgentOperations($this->kernel()->container()))
            ->sessionStore()
            ?->load($this->sesionDelChatId);
    }

    /**
     * @return array{ok: bool, granted?: string|null, error?: string}
     */
    /**
     * La pregunta abierta de un sub-agente DIRECTO de la sesión del chat, o `null` (Q-P19-Q).
     *
     * Se relee del almacén en cada consulta — reproyección, no foto: un hijo que pausó mientras el
     * humano leía tiene que aparecer sin reabrir nada. Sólo hijos directos: la profundidad 1 es la
     * del spawner, y esta lectura no debe ver más árbol que el que existe.
     *
     * @return array{session: string, question: string, options: list<string>}|null
     */
    private function preguntaDeHijoPausado(): ?array
    {
        $almacen = $this->almacenDeSesiones();
        if ($almacen === null) {
            return null;
        }

        foreach ($almacen->ids() as $id) {
            if (!str_starts_with($id, $this->sesionDelChatId . '.sub-')) {
                continue;
            }
            $hijo = $almacen->load($id);
            // `parentId` del stream, no el prefijo del nombre: el nombre es convención del spawner,
            // la filiación es un hecho apendado — y es el hecho el que decide.
            if ($hijo === null || $hijo->parentId !== $this->sesionDelChatId || $hijo->question === null) {
                continue;
            }

            return [
                'session' => $id,
                'question' => $hijo->question->question,
                'options' => $hijo->question->options,
            ];
        }

        return null;
    }

    /**
     * Contesta la pregunta de un sub-agente, por la MISMA operación que contesta las propias.
     *
     * @return array{ok: bool, granted?: string|null, error?: string}
     */
    private function contestarAlHijo(string $hijo, string $respuesta): array
    {
        /** @var array{ok: bool, granted?: string|null, error?: string} $r */
        $r = $this->correr('agent:answer', ['session' => $hijo, 'answer' => $respuesta])
            ?? ['ok' => false, 'error' => 'esta app no declara la operación `agent:answer`'];

        return $r;
    }

    /**
     * Contesta la pregunta pendiente de la sesión del chat — el `agent:answer` sin salir del TUI.
     *
     * @return array{ok: bool, granted?: string|null, error?: string}
     */
    private function contestarEnElChat(string $respuesta): array
    {
        /** @var array{ok: bool, granted?: string|null, error?: string} $r */
        $r = $this->correr('agent:answer', ['session' => $this->sesionDelChatId, 'answer' => $respuesta])
            ?? ['ok' => false, 'error' => 'esta app no declara la operación `agent:answer`'];

        return $r;
    }

    /**
     * Cómo contraoferta el chat: el texto libre va como `counter`, no como `answer` (decisions/0064).
     *
     * @return array{ok: bool, countered?: string, granted?: string|null, error?: string}
     */
    private function contraofertarEnElChat(string $contra): array
    {
        /** @var array{ok: bool, countered?: string, granted?: string|null, error?: string} $r */
        $r = $this->correr('agent:answer', ['session' => $this->sesionDelChatId, 'counter' => $contra])
            ?? ['ok' => false, 'error' => 'esta app no declara la operación `agent:answer`'];

        return $r;
    }

    /**
     * The chat's «sí, pero…»: a structural envelope goes to agent:answer as `envelope`, which meets it
     * against the gate's declared ceiling AT THE GATE and runs the same call if it fits — the TUI
     * projection of the structural counter (greenhouse evidence/0299, decisions/0067).
     *
     * @param array<string, string> $sobre
     *
     * @return array{ok: bool, granted?: string|null, envelope?: array<string, mixed>, tightened?: list<string>, error?: string}
     */
    private function apretarEnElChat(array $sobre): array
    {
        /** @var array{ok: bool, granted?: string|null, envelope?: array<string, mixed>, tightened?: list<string>, error?: string} $r */
        $r = $this->correr('agent:answer', ['session' => $this->sesionDelChatId, 'envelope' => $sobre])
            ?? ['ok' => false, 'error' => 'esta app no declara la operación `agent:answer`'];

        return $r;
    }

    /**
     * Corre una operación por nombre, o `null` si esta app no la declara.
     *
     * @param array<string, mixed> $entrada
     *
     * @return array<string, mixed>|null
     */
    private function correr(string $nombre, array $entrada): ?array
    {
        foreach ($this->all() as $operacion) {
            if ($operacion->name !== $nombre) {
                continue;
            }

            $handler = $operacion->handler;
            if (\is_callable($handler)) {
                $r = $handler($entrada);

                return \is_array($r) ? $r : null;
            }
        }

        return null;
    }

    /**
     * Lo que esta app sabe hacer, agrupado por si muta.
     *
     * La lista se DERIVA de los átomos, así que no puede quedar desactualizada respecto de lo que
     * realmente hay. Una ayuda escrita a mano es el primer archivo que miente cuando alguien instala
     * un plugin.
     */
    /**
     * Explica el estado arquitectónico de esta app SIN bootearla.
     *
     * Aquí sólo se RENDERIZA: el diagnóstico lo produce {@see AppDoctor} como valor, para que el mismo
     * cálculo sirva a una terminal, a un TUI y a un agente sin que ninguno tenga que parsear lo que
     * otro imprimió — y para que se pueda probar sin capturar salida.
     */
    /**
     * `coa update [paquete…] [--dry-run]` — sin kernel, y en seco por defecto no: se pide.
     *
     * @param list<string> $resto
     */
    private function actualizarSinKernel(array $resto): int
    {
        $seco = \in_array('--dry-run', $resto, true);
        $paquetes = array_values(array_filter($resto, static fn (string $a): bool => !str_starts_with($a, '-')));

        $r = \Milpa\DevTools\Doctor\Update::apply($this->root, $seco, $paquetes);

        foreach ($r as $clave => $valor) {
            if ($clave === 'would' && \is_array($valor)) {
                $this->line('would:');
                foreach ($valor as $linea) {
                    $this->line('  ' . (string) $linea);
                }

                continue;
            }
            if ($clave === 'changed' && \is_array($valor)) {
                $this->line('changed:' . ($valor === [] ? ' nada se movió' : ''));
                foreach ($valor as $nombre => $par) {
                    $this->line(sprintf('  %-26s %s → %s', $nombre, (string) ($par['from'] ?? '—'), (string) ($par['to'] ?? '—')));
                }

                continue;
            }
            $this->line(sprintf('%-12s %s', $clave . ':', \is_scalar($valor) ? (string) $valor : (string) json_encode($valor)));
        }

        return ($r['ok'] ?? false) === true ? 0 : 1;
    }

    /**
     * `coa repair <paquete>` — sin kernel, por lo mismo que {@see doctor()}.
     *
     * @param list<string> $resto
     */
    private function repararSinKernel(array $resto): int
    {
        $paquete = '';
        $seco = false;
        foreach ($resto as $arg) {
            if (str_starts_with($arg, '--package=')) {
                $paquete = substr($arg, 10);
            } elseif ($arg === '--dry-run') {
                $seco = true;
            } elseif ($paquete === '' && !str_starts_with($arg, '-')) {
                // POSICIONAL TAMBIÉN, como el resto de esta CLI: lo obligatorio se teclea sin bandera.
                $paquete = $arg;
            }
        }

        if ($paquete === '') {
            $this->line('usage: php bin/coa repair <package>   — the one `coa doctor` names in its `action`');

            return 1;
        }

        $r = Repair::apply($this->root, $paquete, $seco);

        foreach ($r as $clave => $valor) {
            $this->line(sprintf('%-12s %s', $clave . ':', \is_scalar($valor) ? (string) $valor : (string) json_encode($valor)));
        }

        return ($r['ok'] ?? false) === true ? 0 : 1;
    }

    private function doctor(): int
    {
        $declarados = $this->root . '/config/plugins.php';
        if (!is_file($declarados)) {
            $this->line('✗ no config/plugins.php — this app declares no plugins');

            return 1;
        }

        /** @var list<string> $clases */
        $clases = require $declarados;
        $reporte = (new AppDoctor())->diagnose($clases, $this->root);

        $this->line('coa doctor · ' . \count($clases) . ' plugin(s) declared');
        $this->line('');

        // LO QUE LOS DOS ARCHIVOS DECLARAN A LA VEZ (greenhouse evidence/0145).
        //
        // `.milpa/agent.json` gana sobre `config/app.php`, así que una llave declarada en ambos deja
        // de tener efecto en el archivo que la persona abre — y sin esto se entera cambiando el valor
        // y viendo que no pasa nada. Prohibir la edición a mano no está a nuestro alcance; que la
        // divergencia sea invisible sí lo estaba.
        $enAmbos = MachineOverlay::divergencias(
            \is_array($delApp = @include $this->root . '/config/app.php') ? $delApp : [],
            $this->root,
        );

        foreach ($enAmbos as $ruta) {
            $this->line("  ! «{$ruta}» está en config/app.php Y en .milpa/agent.json — gana el segundo");
        }

        if ($enAmbos !== []) {
            $this->line('');
        }

        foreach ($reporte->unreadable as $ilegible) {
            $this->line('  ✗ ' . $ilegible);
        }

        foreach ($reporte->plugins as $plugin) {
            $provee = $plugin['provides'] === [] ? '—' : implode(', ', $plugin['provides']);
            $pide = $plugin['requires'] === [] ? '—' : implode(', ', $plugin['requires']);
            $this->line(sprintf('  %-22s provee: %-24s pide: %s', $plugin['name'], $provee, $pide));
        }

        $this->line('');

        foreach ($reporte->missing as $falta) {
            $id = \is_string($falta['id'] ?? null) ? $falta['id'] : (string) json_encode($falta);
            $this->line("  ✗ nadie provee «{$id}»");
        }

        // Lo aprendible del resolver, tal cual viene: qué pasó, POR QUÉ, cómo se arregla y a qué
        // lección lleva. Reformularlo aquí sería empeorarlo — y las `recommendedActions` son lo que un
        // agente puede aplicar sin interpretar nada, que es la diferencia entre un error que se lee y
        // uno que se opera.
        foreach ($reporte->errors as $error) {
            $this->line('');
            $this->line('  ' . (string) $error['code'] . ': ' . (string) $error['message']);
            $this->line('    why:   ' . (string) $error['why']);
            foreach ((array) $error['fixes'] as $arreglo) {
                $this->line('    fix:   ' . (string) $arreglo);
            }
            foreach ((array) $error['recommendedActions'] as $accion) {
                $this->line('    action: ' . (string) json_encode($accion));
            }
            $aprende = (array) $error['learn'];
            $academia = $aprende['academy'] ?? null;
            if (\is_array($academia) && \is_string($academia['es'] ?? null)) {
                $this->line('    learn: ' . $academia['es']);
            }
        }

        $this->line('');
        $this->line($reporte->ok() ? '✓ el grafo cierra' : '✗ esta app no va a arrancar así');

        // LAS PROMESAS QUE ESTA APP NO PUEDE CUMPLIR, cuando hay tabla que preguntar.
        //
        // `Reversibility::Guaranteed` compra menos escrutinio, y el perfil ya rechaza la prosa. Lo que
        // un perfil solo NO puede ver es si el nombre que declaró existe: `nothing-to-roll-back` es un
        // nombre bien formado para una operación que nadie escribió. Eso lo sabe la tabla.
        //
        // Va aquí y no al arranque porque es una pregunta de GRADUACIÓN: una app que prometió algo que
        // no puede hacer no está rota, está en deuda, y el doctor es donde esta casa dice en qué estado
        // arquitectónico está. Y va DESPUÉS del veredicto del grafo, envuelta, porque el doctor existe
        // justamente para el caso en que el kernel NO levanta — y una sección que necesita la tabla no
        // puede quitarle esa razón de ser.
        $this->promesasDeReversa();

        return $reporte->ok() ? 0 : 1;
    }

    /**
     * Reports the guaranteed rollbacks whose inverse this app does not offer.
     *
     * Silent when everything resolves: a report that prints a heading to say «nothing» trains people to
     * skip it. Silent too when the table cannot be composed — the graph verdict above already said the
     * app will not boot, and repeating it as a second failure would read as a second defect.
     */
    private function promesasDeReversa(): void
    {
        try {
            $rotas = RollbackContracts::findings($this->all());
        } catch (\Throwable $noSePudo) {
            // NO DEBER NADA Y NO HABER PODIDO PREGUNTAR NO SE VEN IGUAL.
            //
            // Esto callaba en los dos casos, así que un control positivo —«con la casa limpia, calla»—
            // no distinguía una casa sin deudas de una tabla que no se pudo componer. Un control que no
            // separa esas dos no controla nada, y `PluginManagementPlugin::operations()` lanza a
            // propósito cuando no hay registro en el contenedor: el silencio era alcanzable de verdad.
            $this->line('');
            $this->line('  ? no se pudieron leer las promesas de reversa: ' . $noSePudo->getMessage());

            return;
        }

        if ($rotas === []) {
            return;
        }

        $this->line('');
        $this->line('  promesas de reversa que esta app no puede cumplir:');
        foreach ($rotas as $hallazgo) {
            $this->line('  ✗ ' . $hallazgo);
        }
    }

    private function help(): int
    {
        $this->line('coa — the runtime of this app. Every command is a declared operation.');
        $this->line('');

        $lee = [];
        $muta = [];
        foreach ($this->all() as $operacion) {
            $fila = [$this->commandName($operacion), $operacion->description, $operacion->requiresConfirmation];
            $operacion->mutating ? $muta[] = $fila : $lee[] = $fila;
        }

        $this->section('They read', $lee);
        $this->section('They change something', $muta);

        // `doctor`, `shell` y `chat` NO son operaciones y por eso la lista derivada no las trae: se
        // enumeran aquí, que es la única excepción honesta a «la ayuda se deriva». Una capacidad que
        // existe y no se anuncia no la encuentra nadie — y `doctor` es justamente la que hace falta
        // cuando lo demás no corre.
        $this->line('  Also:');
        if (Capabilities::installed('devtools')) {
            $this->line('    doctor           Explain the architectural state of this app WITHOUT booting it');
        }
        $this->line('    shell            Every operation, on one screen');
        // `panel` only with the admin: it is what puts sections in the terminal. Without it the screen
        // would open on an empty state, and announcing a screen with nothing to show teaches that the
        // help lies — the same rule `chat` follows one line below.
        if (Capabilities::installed('admin')) {
            $this->line('    panel            Every section of this app, on one screen (or one: `panel <section>`, `--json`)');
        }
        // `chat` sólo si el agente está instalado. Este framework es tiny por default: anunciar una
        // pantalla que no puede abrirse enseñaría que la ayuda miente, y `coa capabilities` es donde
        // se ve lo que falta con el `composer require` que lo enciende.
        if (Capabilities::installed('agent')) {
            $this->line('    chat [<session>] The agent, in a session that outlives the process');
        }
        $this->line('');

        $this->line('  An operation that demands a signature runs with --sign; --json turns the output');
        $this->line('  into a one-line document, for a program.');

        return 0;
    }

    /**
     * Lo que falta, cómo se enciende, y dónde está el resto.
     *
     * Se dice **al intentar usarlo**, que es el único momento en que a alguien le importa. Un
     * mensaje que sólo dijera «no existe ese comando» dejaría a quien pregunta —persona o agente—
     * creyendo que se equivocó de nombre, cuando lo que pasa es que la app todavía no crece hasta ahí.
     */
    private function faltaCapability(string $queFalta, string $comando): int
    {
        $this->line('  ' . $queFalta);
        $this->line('');
        $this->line('    ' . $comando);
        $this->line('');
        $this->line('  `php bin/coa capabilities` lists everything this app can switch on.');

        return 1;
    }

    /**
     * @param list<array{0: string, 1: string, 2: bool}> $filas
     */
    private function section(string $titulo, array $filas): void
    {
        if ($filas === []) {
            return;
        }

        usort($filas, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $ancho = max(array_map(static fn (array $f): int => mb_strlen($f[0]), $filas));

        $this->line($titulo . ':');
        foreach ($filas as [$nombre, $descripcion, $firma]) {
            $this->line(\sprintf(
                '  %s  %s%s',
                $nombre . str_repeat(' ', $ancho - mb_strlen($nombre)),
                $descripcion,
                $firma ? '  [firma]' : '',
            ));
        }
        $this->line('');
    }

    /**
     * Rearma los tokens que el runner espera, traduciendo de vuelta los nombres de la terminal.
     *
     * Lo OBLIGATORIO se escribe posicional —`make entity MiPlugin Cosa`— porque es la convención de
     * cualquier CLI; lo opcional va como `--bandera`. Y una llave `dry_run` del esquema se escribe
     * `--dry-run`: un esquema JSON no lleva guiones en sus llaves y una terminal no lleva guiones
     * bajos en sus opciones, y traducir cuesta menos que pedirle a alguna de las dos que ceda.
     *
     * @param list<string> $argv
     *
     * @return list<string>
     */
    private function tokens(Operation $operacion, array $argv): array
    {
        $modelo = (new CliProjector())->project($operacion);

        $posicionales = [];
        foreach ($modelo->flags as $nombre => $definicion) {
            if ($definicion['required']) {
                $posicionales[] = $nombre;
            }
        }

        $tokens = [];
        $siguiente = 0;

        foreach ($argv as $token) {
            if (!str_starts_with($token, '--')) {
                if (isset($posicionales[$siguiente])) {
                    $tokens[] = '--' . $posicionales[$siguiente] . '=' . $token;
                    ++$siguiente;
                }

                continue;
            }

            [$clave, $valor] = str_contains($token, '=')
                ? explode('=', substr($token, 2), 2)
                : [substr($token, 2), null];

            // `--sign` es de la compuerta de consentimiento, no del esquema: viaja tal cual.
            if ($clave === 'sign') {
                $tokens[] = '--sign';

                continue;
            }
            if ($clave === 'json') {
                continue;
            }

            $enEsquema = str_replace('-', '_', $clave);
            $tokens[] = $valor === null ? '--' . $enEsquema : '--' . $enEsquema . '=' . $valor;
        }

        return $tokens;
    }

    private function find(string $comando): ?Operation
    {
        foreach ($this->all() as $operacion) {
            if ($this->commandName($operacion) === $comando) {
                return $operacion;
            }
        }

        return null;
    }

    private function commandName(Operation $operacion): string
    {
        return str_replace(['_', '.'], ':', $operacion->name);
    }

    /**
     * Los átomos de las dos fuentes: los plugins que arrancaron y lo que esta app enlista.
     *
     * @return list<Operation>
     */
    private function all(): array
    {
        if ($this->operations !== null) {
            return $this->operations;
        }

        $kernel = $this->kernel();
        \Milpa\AppRuntime\Agent\PluginAuthoringPolicy::install($kernel->container(), $this->root);
        /** @var list<Operation> $operaciones */
        $operaciones = $kernel->commands();

        $declarados = $this->root . '/config/operations.php';
        if (is_file($declarados)) {
            /** @var list<class-string<CommandProvider>> $proveedores */
            $proveedores = require $declarados;
            foreach ($proveedores as $proveedor) {
                if (!class_exists($proveedor)) {
                    continue;
                }
                $reflexion = new \ReflectionClass($proveedor);
                /** @var CommandProvider $instancia */
                $instancia = ($reflexion->getConstructor()?->getNumberOfParameters() ?? 0) > 0
                    ? $reflexion->newInstance($kernel->container())
                    : $reflexion->newInstance();

                foreach ($instancia->operations() as $operacion) {
                    $operaciones[] = $operacion;
                }
            }
        }

        return $this->operations = $operaciones;
    }

    private ?Kernel $booted = null;

    /**
     * What a verified signer may do in this house — one judgment for every door of the terminal.
     *
     * The enrollment ledger and the policy file are read from disk, never from the kernel: the same
     * judgment has to hold when the kernel does not boot ({@see recuperarSinKernel()}), and two copies
     * of it would disagree the day it matters.
     */
    private function autoridadDelFirmante(VerifiedSigner $signer, ?\Milpa\Auth\AuthContext $identity): ?ToolContext
    {
        $signed = (new SignerAuthority(
            new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'),
            PolicyConfig::load($this->root),
        ))->forSigner($signer);
        // A second credential cannot widen a presented token for delegated tools.
        $tokenScopes = PresentedToken::scopes($identity, ['*']);
        if ($signed === null && $identity === null) {
            return null;
        }
        $signedScopes = $signed->scopes ?? ['*'];
        $scopes = \in_array('*', $tokenScopes, true) ? $signedScopes
            : (\in_array('*', $signedScopes, true) ? $tokenScopes : array_values(array_intersect($tokenScopes, $signedScopes)));

        return new ToolContext(principal: 'key:' . $signer->fingerprint, channel: 'cli', scopes: $scopes);
    }

    /**
     * `sandbox:undo` and `plugins:disable-unsafe` for a house that does not boot — the ways back that used to exist only by hand.
     *
     * Measured in greenhouse evidence/1038 (n5): a promotion registered a plugin whose class misses an
     * interface method, and `coa sandbox:undo` booted that same house and died of the same compile fatal.
     * The pre-image (decisions/0069) was there, and only a person with a shell and the know-how could
     * put it back; on the published train the same happened to `plugins:disable-unsafe` (evidence/1036, R1).
     * A fatal cannot be caught; it can be avoided: this path boots nothing of the app — it reads `vendor/`,
     * `var/trials/`, `storage/` and the identity files, and `config/plugins.php` only as a list of names.
     *
     * THE SAME AUTHORITY, NEVER A WIDER ONE (greenhouse decisions/0506). The call goes through the same
     * `CliRunner` as always: the consent gate asks for `--sign`, the signer is judged by the same
     * enrollment ledger and policy file ({@see autoridadDelFirmante()}), and the house's own boundary
     * ({@see PluginAuthoringPolicy}) judges the write set of the undo — the same checks, from the same
     * files. What is NOT here is refused rather than skipped: a presented `MILPA_TOKEN` is judged by the
     * token store a plugin registers, which lives in the kernel that does not boot — so it is refused,
     * never read as «no token» (that would widen a narrow token to the terminal's wildcard). And a signed
     * sequence is not continued from its receipt: every call here signs.
     *
     * @param list<string> $argv tokens after the command name
     */
    private function recuperarSinKernel(string $comando, array $argv, string $porQue): int
    {
        $this->line('✗ The house does not boot: ' . $porQue);
        $this->line('  Recovering without booting it: only ' . $comando . ' runs here, under the same signature, scope and write-set checks.');

        $token = getenv(PresentedToken::ENV);
        if (\is_string($token) && trim($token) !== '') {
            $this->line('✗ ' . PresentedToken::ENV . ' is presented, and a token is judged by the token store the house registers when it boots — it does not boot.');
            $this->line('  Nothing ran. Unset ' . PresentedToken::ENV . ' and sign the call with --sign.');

            return 1;
        }

        $container = new \Milpa\Container\DIContainer();
        $container->registerService(\Milpa\Plugin\Contracts\AppRoot::class, new \Milpa\Plugin\Contracts\AppRoot($this->root));
        $policy = new \Milpa\AppRuntime\Agent\PluginAuthoringPolicy($this->root);
        $container->registerService(\Milpa\ToolRuntime\Contracts\CallPolicy::class, $policy);
        $container->registerService(\Milpa\Console\OperationBoundary::class, $policy);
        $operacion = null;
        foreach ($this->operacionesDeRecuperacion($container) as $op) {
            if (str_replace(['_', '.'], ':', $op->name) === $comando) {
                $operacion = $op;
            }
        }
        if ($operacion === null) {
            $this->line('✗ ' . $comando . ' cannot be offered without the kernel on this install.');

            return 1;
        }

        $renderer = \in_array('--json', $argv, true) ? new JsonCliRenderer() : new PlainTextCliRenderer();
        $base = ToolContext::cli();
        // The same scope check the ordinary door runs before the runner (`plugins:disable-unsafe` declares
        // `plugins:write`); with no token here, the local shell's default is what it judges.
        $scope = (new PolicyGate())->authorizeScopes($base, McpProjector::toolName($operacion->name), $operacion->scopes);
        if (!$scope->allowed) {
            foreach ($renderer->presentError((string) $scope->reason) as $line) {
                $this->line($line);
            }

            return 1;
        }
        // THE SAME RULE FOR AN UNSIGNED CALL AS THE ORDINARY DOOR (greenhouse decisions/0522): reading only, and what
        // asks for more is refused — never run as the terminal's wildcard. No receipt is cited here: every call signs.
        $tokens = $this->tokens($operacion, $argv);
        if (!\in_array('--sign', $tokens, true)) {
            try {
                $input = $operacion->inputSchema !== null ? (new CliRunner())->deriveInput($operacion, $tokens) : [];
            } catch (\Throwable) {
                $input = null; // the runner says what is wrong with the arguments, and runs nothing
            }
            if ($input !== null && !UnsignedTerminal::runnerDecides($operacion, $input, null)) {
                $refusal = UnsignedTerminal::refusal($operacion, $input, $policy, null);
                if ($refusal !== null) {
                    foreach ($renderer->presentError(implode("\n", $refusal)) as $line) {
                        $this->line($line);
                    }

                    return 1;
                }
                $base = UnsignedTerminal::authority($operacion);
            }
        }
        $salida = (new CliRunner(
            signer: $this->firmante,
            renderer: $renderer,
            callerAuthority: $base,
            verifier: $this->verificador,
            signerAuthority: fn (VerifiedSigner $signer): ?ToolContext => $this->autoridadDelFirmante($signer, null),
        ))->run($operacion, $tokens, $container, $this->line(...));

        if ($salida === 0) {
            $despues = (new \Milpa\AppRuntime\Support\BootProbe())->whyNot($this->root);
            $this->line($despues === null ? '✓ The house boots again.' : '✗ The house still does not boot: ' . $despues);
        }

        return $salida;
    }

    /**
     * The recovery operations, built from disk alone: the trial doors and the plugin registry's own file.
     *
     * `plugins.disable-unsafe` is milpa/plugin's, over the same `storage/plugins.json` the boot reads
     * (`ActivePlugins::wire`). Its record of a declared plugin is read from the class's metadata, so the
     * declared list is read from `config/plugins.php` — and if that very file is what broke, from nothing:
     * a stored record can still be turned off. A declared class whose file does not even compile would take
     * this process down when its metadata is read; that shape is `sandbox:undo`'s, which reads no app code.
     *
     * @return list<Operation>
     */
    private function operacionesDeRecuperacion(\Milpa\Container\DIContainer $container): array
    {
        $ops = (new \Milpa\AppRuntime\Operations\TrialOperations($container, null, $this->root, null))->operations();
        if (class_exists(\Milpa\Plugin\Operations\PluginOperations::class)) {
            $declarados = [];
            try {
                $leidos = is_file($this->root . '/config/plugins.php') ? require $this->root . '/config/plugins.php' : [];
                $declarados = \is_array($leidos) ? array_values(array_filter($leidos, 'is_string')) : [];
            } catch (\Throwable) {
            }
            $registro = new \Milpa\Plugin\Registry\FilePluginRegistry($this->root . '/storage/plugins.json');
            $container->registerService(\Milpa\Plugin\Contracts\PluginRegistryInterface::class, $registro);
            // The way back boots in a copy first too, as recovery (greenhouse decisions/0515): never refused
            // because this house does not boot, only when it would break one that does.
            $testigo = new \Milpa\AppRuntime\Support\HouseBootWitness($this->root);
            foreach ((new \Milpa\Plugin\Operations\PluginOperations($registro, null, $declarados, null, $this->root, null, $testigo))->operations() as $op) {
                if ($op->name === 'plugins.disable-unsafe') {
                    $ops[] = $op;
                }
            }
        }

        return $ops;
    }

    private function kernel(): Kernel
    {
        if ($this->booted !== null) {
            return $this->booted;
        }

        /** @var array{container: DIContainerInterface, plugins: list<class-string>} $boot */
        $boot = require $this->root . '/config/boot.php';
        /** @var array<string, mixed> $config */
        $config = require $this->root . '/config/app.php';

        // Y encima, lo que la máquina escribió por el camino gobernado (greenhouse evidence/0145).
        //
        // `config/app.php` es del humano: lleva los comentarios y es el archivo que una persona
        // abre. `.milpa/` es de la máquina — ya guarda la constitución, que un rito escribe y nadie
        // edita a mano. La configuración que cambió por una operación gobernada aterriza ahí al lado.
        $config = MachineOverlay::sobre($config, $this->root);
        // And the machine's secrets on top of that — LAST, because a credential is the most specific
        // thing anybody declared and the only one that could not have been declared anywhere else.
        //
        // Without this line `provider:declare` writes a key nothing reads: the file lands, the
        // operation answers «declared», and the turn that needs it still finds no credential. The
        // web half is the front controller's, one line after its sibling (greenhouse decisions/0267).
        $config = SecretOverlay::sobre($config, $this->root);

        $kernel = Kernel::boot([
            'root' => $this->root,
            'plugins' => $boot['plugins'],
            'config' => $config,
            'container' => $boot['container'],
            // El registro de herramientas se arma SIEMPRE, no sólo cuando alguien pide MCP: es lo
            // que permite que `bin/mcp-server.php` y esta terminal vean exactamente las mismas
            // operaciones. Un registro que sólo existe en un modo produce dos inventarios.
            'toolRegistry' => new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger()),
        ]);

        // El kernel, en su propio contenedor. Un handler que corre DESPUÉS del arranque —el agente,
        // por ejemplo— necesita preguntar qué declara esta app, y sin esto tendría que volver a
        // resolverlo por su cuenta: dos respuestas a la misma pregunta, que es como se llega a que
        // una superficie ofrezca lo que la otra no.
        $boot['container']->registerService(Kernel::class, $kernel);

        return $this->booted = $kernel;
    }

    /**
     * Una línea a la salida estándar.
     *
     * `echo` y no `fwrite(STDOUT, …)`, y la diferencia importa: `fwrite` a la constante STDOUT se
     * salta el buffer de salida de PHP, así que una prueba en proceso no puede capturarlo. La
     * alternativa era inyectar un stream, y un `create-project` cuya tesis es un piso mínimo no
     * debería necesitar una capa de abstracción de salida para imprimir un renglón.
     */
    private function line(string $texto): void
    {
        echo $texto, \PHP_EOL;
    }
}
