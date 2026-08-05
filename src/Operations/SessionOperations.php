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

namespace Milpa\AppRuntime\Operations;

use Milpa\AppRuntime\Support\ContratoInstalado;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Auth\AuthContext;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Command\CommandProvider;
use Milpa\Command\InvocationContext;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * El otro lado de la pausa: ver las sesiones, leer una, y CONTESTARLE (P16.4/P16.5).
 *
 * ── POR QUÉ SON ÁTOMOS Y NO UN PROMPT INTERACTIVO ───────────────────────────────────────────────
 *
 * Porque una sesión se pausa en un proceso y se contesta en otro — al día siguiente, desde otra
 * máquina, o desde el TUI en vez de la terminal. Un `readline()` dentro del bucle habría atado la
 * respuesta al proceso que hizo la pregunta, que es justo lo que P16.1 acaba de desatar. Como átomos,
 * las cuatro superficies pueden contestar y ninguna tiene que saber cómo funcionan las otras.
 *
 * ── `answer` MUTA, Y NO PIDE FIRMA ──────────────────────────────────────────────────────────────
 *
 * Apenda eventos —incluido un permiso, cuando la respuesta es «sí»— así que decir que no muta sería
 * mentir. No pide firma porque ES la compuerta: exigir un consentimiento para dar un consentimiento
 * es una escalera sin piso. Lo que la mantiene honesta es que sólo puede otorgar lo que la sesión ya
 * había preguntado — no acepta un permiso para algo que nadie pidió.
 */
final class SessionOperations implements CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * @return list<Operation>
     */
    /**
     * Las operaciones de sesión que este grupo aporta al registro.
     *
     * Se devuelven en vez de registrarse solas: quien arma el registro decide qué grupos entran y
     * con qué autoridad, y un grupo que se auto-registrara le quitaría esa decisión.
     */
    public function operations(): array
    {
        // LO QUE NO SE PUEDE HACER NO SE OFRECE.
        //
        // Este framework es tiny por default y crece por opt-in, así que `milpa/agent` puede no estar
        // instalado. Anunciar una operación que sólo sabe contestar «esta app no tiene…» es peor que
        // no anunciarla: quien lee `coa list` —persona o agente— la cuenta como disponible, la llama,
        // y aprende que el listado miente. `coa capabilities` es donde se ve lo que FALTA, con el
        // `composer require` que lo enciende.
        if (!Capabilities::installed('agent')) {
            return [];
        }

        return [
            new Operation(
                name: 'agent:sessions',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'The agent sessions of this app, and where each one stands',
                handler: fn (array $input): array => $this->listar(),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
            ),
            new Operation(
                name: 'agent:show',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'Everything known about one session: goal, plan, todos, permissions and how it ended',
                handler: fn (array $input): array => $this->mostrar($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'Identificador de la sesión'],
                    ],
                    'required' => ['session'],
                ],
                mutating: false,
            ),
            new Operation(
                name: 'agent:mode',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // Append-only stream: a decision is a fact, and facts are not withdrawn.
                    Reversibility::Irreversible,
                    // It raises how far the agent may act WITHOUT ASKING — it spends the human's
                    // authority over decisions that have not happened yet.
                    Authority::WriteAsUser,
                ),
                description: 'Change how far a session may go without asking',
                handler: fn (array $input): array => $this->cambiarModo($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'Identificador de la sesión'],
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['ask', 'acknowledge', 'auto'],
                            'description' => 'ask pregunta antes de mutar · acknowledge avisa y sigue · auto sigue sola',
                        ],
                    ],
                    'required' => ['session', 'mode'],
                ],
                // Muta la sesión, y sube o baja cuánta autonomía tiene un proceso automático. No pide
                // firma porque lo que hay del otro lado tampoco la evita: subir a `auto` no
                // pre-aprueba nada que exija firma, así que esto no puede usarse para rodear esa
                // compuerta — sólo para dejar de preguntar por lo reversible.
                mutating: true,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'agent:timeline',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'What happened in a session, translated for a surface to paint: cards, plan and closing',
                handler: fn (array $input): array => $this->linea($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'Identificador de la sesión'],
                        'since' => ['type' => 'integer', 'description' => 'La última secuencia que ya viste; 0 trae todo'],
                    ],
                    'required' => ['session'],
                ],
                mutating: false,
                // POR HTTP TAMBIÉN, a diferencia de las otras. Leer lo que ya pasó no autoriza nada y
                // es justo lo que un navegador necesita para pintar el trabajo en vivo. Las que sí
                // deciden —contestar, cambiar el modo— siguen fuera de la web.
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'agent:answer',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::Irreversible,
                    Authority::WriteAsUser,
                ),
                // NO DICE «and resume it», y antes sí. Contestar apenda la respuesta y devuelve el hint
                // para retomar; el bucle NO se vuelve a correr. La descripción prometía lo que la
                // operación no hace, y en el TUI eso se sentía como un agente que se quedó callado.
                description: 'Answer the question that paused a session; it does not resume the loop',
                // EL CONTEXTO VIAJA POR EL MISMO CAMINO QUE LA INVOCACIÓN, no por el contenedor.
                // Un handler que lo lee del ambiente puede olvidarse de leerlo y seguir funcionando,
                // y el contenedor puede conservar el actor de la petición anterior.
                handler: fn (array $input, ?InvocationContext $ctx = null): array => $this->contestar($input, $ctx),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'Identificador de la sesión'],
                        'answer' => ['type' => 'string', 'description' => 'Tu respuesta — «sí» autoriza la operación en esta sesión'],
                    ],
                    'required' => ['session', 'answer'],
                ],
                mutating: true,
                // POR HTTP TAMBIÉN, ahora que la identidad llega entera. El comentario anterior decía
                // que contestar desde la web era «autorizar con las credenciales del servidor», y eso
                // describía un sistema sin `milpa/auth` cableado — que dejó de existir hace rato.
                //
                // Lo que hace que esto sea seguro no es el canal: es que el scope exija un actor
                // autenticado, que el `InvocationContext` lo traiga hasta aquí, y que la operación se
                // NIEGUE si no llega. Sin las tres, exponerla escribiría un permiso a nombre del
                // proceso del servidor.
                scopes: ['agent:answer'],
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'agent:board',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'The work of one session as four columns, derived from its stream',
                handler: fn (array $input): array => $this->tablero($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'Identificador de la sesión'],
                    ],
                    'required' => ['session'],
                ],
                mutating: false,
                // POR HTTP TAMBIÉN, como `agent:timeline` y por lo mismo: leer lo que ya pasó no
                // autoriza nada, y es justo lo que un navegador necesita para pintar el trabajo en
                // vivo. Lo que decide —contestar, aprobar, cambiar el modo— sigue fuera de la web.
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'agent:discard',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::Irreversible,
                    Authority::WriteAsUser,
                ),
                description: 'Discard a session: it stops waiting and nothing can resume it',
                handler: fn (array $input, ?InvocationContext $ctx = null): array => $this->descartar($input, $ctx),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'Identificador de la sesión'],
                        'because' => ['type' => 'string', 'description' => 'Por qué se descarta — queda en el stream'],
                    ],
                    'required' => ['session', 'because'],
                ],
                mutating: true,
                // MISMO PISO QUE CONTESTAR: descartar decide sobre una sesión, así que exige actor
                // verificado en todo canal que prometa identidad.
                scopes: ['agent:answer'],
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
        ];
    }

    /**
     * El tablero de una sesión: cuatro columnas, TODAS derivadas del stream (P19.5).
     *
     * ── LA PROPIEDAD QUE NO SE PUEDE PERDER ─────────────────────────────────────────────────────
     *
     * **El tablero no tiene estado.** Lo que se ve es el fold del stream, releído en cada llamada. En
     * el momento en que esto guardara su propia copia de en qué columna va una tarjeta habría dos
     * sitios que contestan «¿en qué va esto?», y divergirían — la única pregunta sería cuándo. Es la
     * misma regla que el tablero de `milpa/devtools` ya tenía escrita: *nadie mueve nada, las columnas
     * salen de los resultados, así que el tablero no puede mentir.*
     *
     * Por eso esto es una PROYECCIÓN y no un almacén, y por eso se pudo escribir antes que la página:
     * una página bonita sobre un fold equivocado enseña una mentira convincente.
     *
     * ── LAS COLUMNAS SON EL ENUM, NO UNA LISTA DE AQUÍ ──────────────────────────────────────────
     *
     * Salen de {@see TodoStatus}, así que un estado nuevo aparece como columna sin tocar este archivo
     * — y, más importante, no puede existir un estado que el tablero no sepa pintar. Una lista escrita
     * a mano aquí sería el segundo lugar que decide cuántas columnas hay.
     *
     * ── LO QUE NO HACE ──────────────────────────────────────────────────────────────────────────
     *
     * No aprueba nada. Aprobar es consentir un efecto y tiene su propia forma —`PolicyDecision`, con
     * el `Principal` de quien consiente— y [Q-P19-B](settlement-q-p19b.md) midió que mover una tarjeta
     * y consentir un efecto **no son el mismo sistema**. Un tablero que hiciera las dos con el mismo
     * gesto perdería la distinción que vuelve auditable esto: mover una tarjeta no puede consentir un
     * `plugins.disable`.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, session?: string, columns?: array<string, list<array<string, mixed>>>, pending_question?: string, error?: string}
     */
    private function tablero(array $input): array
    {
        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        $sesion = $almacen->load($id);
        if ($sesion === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        $columnas = [];
        foreach (TodoStatus::cases() as $estado) {
            $columnas[$estado->value] = [];
        }

        foreach ($sesion->todos as $todo) {
            $columnas[$todo->status->value][] = [
                'id' => $todo->id,
                'text' => $todo->text,
                // LA VERSIÓN VIAJA. Es lo que deja a quien pinta saber si una tarjeta se movió o si
                // sólo se volvió a leer, sin comparar textos.
                'version' => $todo->version,
                'origin' => $todo->origin?->value,
                // CUÁNTAS MUTACIONES PASARON DESPUÉS de tocar esta tarjeta. `0` es una tarjeta al día;
                // un número alto es el sistema diciendo «cambiaron siete cosas y esto no se movió ni
                // se cerró». No afirma que esté mal: afirma que no se explicó.
                'unexplained' => max(0, $sesion->mutations - $todo->mutationsAt),
            ];
        }

        $salida = ['ok' => true, 'session' => $id, 'columns' => $columnas];

        // LA PREGUNTA ABIERTA VA EN EL TABLERO, porque es trabajo detenido esperando a un humano — y
        // un tablero que no la muestra deja al agente parado sin que nadie lo vea.
        if ($sesion->question !== null) {
            $salida['pending_question'] = $sesion->question->question;
        }

        return $salida;
    }

    /**
     * DESCARTAR ES DEL HUMANO, y por eso esta operación no entra al catálogo del agente.
     *
     * Cierra el contrato «control» de P19.3: *«detener, retomar, descartar — y quién puede hacerlo. Un
     * hijo que no se puede detener no es un sub-agente, es una fuga»*. Retomar ya existía
     * (`agent_resume`) y detener el árbol entero también (la interrupción del humano viaja hacia
     * arriba). Lo que faltaba era **descartar**: un hijo que pausó preguntando y a quien nadie contesta
     * se quedaba abierto para siempre, esperando a alguien que ya no va a llegar.
     *
     * ── POR QUÉ NO SE LO DAMOS AL PADRE ─────────────────────────────────────────────────────────
     *
     * Es tentador y está mal. Un hijo pausa pidiendo permiso para algo; si el padre pudiera
     * descartarlo, la pregunta que el humano tenía que ver **desaparecería sin que nadie la viera**, y
     * el registro diría que la sesión se cerró en vez de que un permiso quedó sin pedir. No otorga
     * nada, pero hace invisible lo que existía para ser visto — que es la misma clase de lavado que
     * `agent:answer` y `agent:mode` evitan quedándose fuera del catálogo (Q-P19-M).
     *
     * El control del padre sobre su hijo ya existe y es suficiente: **no retomarlo**.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, session?: string, error?: string, hint?: string}
     */
    private function descartar(array $input, ?InvocationContext $ctx = null): array
    {
        if ($ctx !== null && $ctx->channel !== 'cli' && !$ctx->isAttributable()) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'descartar por «%s» exige un actor verificado, y esta invocación no lo trae',
                    $ctx->channel,
                ),
                'hint' => 'autentica la petición: cerrar una sesión sin principal no es auditable',
            ];
        }

        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        // EL MOTIVO ES OBLIGATORIO. Una sesión que se cierra sin decir por qué deja a quien lea el
        // stream mañana con un final y ninguna causa — y el caso que esto atiende es justamente una
        // pregunta que nadie contestó: sin el motivo, se ve idéntico a un trabajo terminado.
        $porque = \is_string($input['because'] ?? null) ? trim($input['because']) : '';
        if ($porque === '') {
            return ['ok' => false, 'error' => 'falta `because`: por qué se descarta, y queda en el stream'];
        }

        $sesion = $almacen->load($id);
        if ($sesion === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        $almacen->end($id, $porque);

        return ['ok' => true, 'session' => $id];
    }

    /**
     * Lo que pasó en una sesión, traducido — la misma respuesta para las tres superficies.
     *
     * No arma nada: le pide al almacén el `timeline()`, que a su vez usa el proyector. Que la terminal,
     * el navegador y el agente reciban veredictos distintos del mismo hecho es un falsificador que
     * este repositorio ya vio dispararse hoy —`ci-check` y la CI publicada difirieron tres veces— y la
     * defensa es que haya un solo camino, no tres cuidadosos.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, session?: string, since?: int, events?: list<array<string, mixed>>, error?: string, hint?: string}
     */
    private function linea(array $input): array
    {
        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        if ($almacen->load($id) === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        $desde = \is_int($input['since'] ?? null) && $input['since'] > 0 ? $input['since'] : 0;
        $hechos = $almacen->timeline($id, $desde);

        // LA ÚLTIMA SECUENCIA VA EN LA RESPUESTA para que quien pregunte de nuevo sepa desde dónde,
        // sin tener que mirar dentro de la lista ni llevar la cuenta por su lado. Un cliente que
        // calcula su propio cursor es un cliente que puede calcularlo mal y perderse hechos en
        // silencio.
        $ultima = $desde;
        foreach ($hechos as $hecho) {
            $ultima = max($ultima, \is_int($hecho['at'] ?? null) ? $hecho['at'] : $ultima);
        }

        return ['ok' => true, 'session' => $id, 'since' => $ultima, 'events' => $hechos];
    }

    /**
     * Cuántas mutaciones ocurrieron sin que nadie tocara una tarjeta que seguía abierta.
     *
     * Es el invariante de [Q-P19-C] reducido a un número por sesión: no dice que algo esté mal, dice
     * cuánto **no se explicó**. Cero es una sesión limpia — o porque cerró todo, o porque no pasó nada
     * mientras algo quedaba abierto, que también está bien.
     */
    private function trabajoSinExplicar(Session $sesion): int
    {
        $peor = 0;
        foreach ($sesion->todos as $todo) {
            if ($todo->status === TodoStatus::Done) {
                continue;
            }
            $peor = max($peor, $sesion->mutations - $todo->mutationsAt);
        }

        return max(0, $peor);
    }

    /**
     * Quién está contestando, con su origen y su nivel de confianza.
     *
     * ── LAS DOS FUENTES NO VALEN LO MISMO, Y POR ESO NO SE MEZCLAN ──────────────────────────────
     *
     * Si hay un {@see AuthContext} autenticado, hay una credencial detrás y el principal va marcado
     * **verificado**. Si no —el caso normal por terminal— se toma el usuario del sistema operativo y
     * va marcado **sin verificar**, porque cualquiera con esa terminal puede ser ese usuario.
     *
     * Guardar el segundo como si fuera el primero fabricaría una cadena de custodia inexistente:
     * «lo autorizó rod» cuando lo que se sabe es «lo autorizó quien tenía la máquina de rod».
     *
     * Ver Q-P19-B: comparando con `milpa/workflow`
     * salió que este lado no guardaba principal ninguno, y que aquél además prohíbe que el que pide
     * sea el que aprueba.
     */
    private function quienContesta(?InvocationContext $ctx = null): Principal
    {
        // EL CONTEXTO MANDA cuando trae un actor verificable: viene de la política que ya autorizó,
        // así que es la identidad que la auditoría tiene que conservar. Volver a derivarla aquí sería
        // que política y auditoría registren principals distintos.
        if ($ctx !== null && $ctx->isAttributable()) {
            return new Principal((string) $ctx->actor, verified: true);
        }

        $contexto = $this->container->has(AuthContext::class) ? $this->container->get(AuthContext::class) : null;
        if ($contexto instanceof AuthContext && $contexto->actor !== null) {
            return new Principal('actor:' . $contexto->actor->id, verified: true);
        }

        return Principal::fromTerminal($this->usuarioDelSistema(), gethostname() ?: null);
    }

    /** El proceso que está corriendo esto, para acompañar al actor y nunca para reemplazarlo. */
    private function procesoLocal(): string
    {
        return ($this->usuarioDelSistema() ?? 'desconocido') . '@' . (gethostname() ?: 'desconocido');
    }

    /** El usuario del sistema operativo, si el entorno lo dice. */
    private function usuarioDelSistema(): ?string
    {
        $usuario = getenv('USER');
        if (!\is_string($usuario) || $usuario === '') {
            $usuario = getenv('USERNAME');
        }

        return \is_string($usuario) && $usuario !== '' ? $usuario : null;
    }

    /**
     * @return array{ok: bool, total?: int, sessions?: list<array<string, mixed>>, error?: string}
     */
    private function listar(): array
    {
        $almacen = $this->sessions();
        if ($almacen === null) {
            return ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        $filas = [];
        foreach ($almacen->ids() as $id) {
            $sesion = $almacen->load($id);
            if ($sesion === null) {
                continue;
            }

            $filas[] = [
                'session' => $id,
                'goal' => $sesion->goal,
                'mode' => $sesion->mode->value,
                'turns' => \count($sesion->turns),
                // Qué le pasa AHORA, que es lo que alguien busca al listar: una sesión esperando una
                // respuesta se ve igual que una viva si sólo se muestra su objetivo.
                'state' => $sesion->endedBecause !== null
                    ? 'terminada'
                    : ($sesion->question !== null ? 'esperando respuesta' : 'viva'),
                'pending' => \count($sesion->pendingTodos()),
                // TRABAJO SIN EXPLICAR. El invariante existía en el stream y no lo leía nadie — que es
                // el patrón que este repositorio lleva un mes cazando. Aquí es donde alguien mira para
                // saber qué sesión necesita atención, así que aquí tiene que estar.
                'unexplained' => $this->trabajoSinExplicar($sesion),
            ];
        }

        return ['ok' => true, 'total' => \count($filas), 'sessions' => $filas];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function mostrar(array $input): array
    {
        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        $sesion = $almacen->load($id);
        if ($sesion === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        return [
            'ok' => true,
            'session' => $sesion->id,
            'goal' => $sesion->goal,
            'mode' => $sesion->mode->value,
            'plan' => $sesion->plan,
            'todos' => array_map(static fn ($t): array => $t->toArray(), $sesion->todos),
            'permissions' => $sesion->permissions,
            'turns' => \count($sesion->turns),
            'compactedThrough' => $sesion->compactedThrough,
            'question' => $sesion->question?->toArray(),
            // LO QUE SE DECIDIÓ, y quién. Sin esta línea el principal se guardaba y no lo veía nadie
            // —el patrón que este repositorio lleva un mes cazando: una capacidad a la que le falta
            // la línea que la enchufa—. `agent:show` es donde alguien va a preguntar «¿quién
            // autorizó esto?», así que es donde tiene que estar la respuesta.
            // QUÉ QUEDÓ ABIERTO Y CUÁNTO CAMBIÓ MIENTRAS TANTO. No se lee del evento de cierre sino
            // que se deriva del estado, que es la misma cuenta: `mutations` de la sesión menos las que
            // llevaba la tarjeta cuando alguien la tocó por última vez. Dos lugares que guarden lo
            // mismo divergen; uno que lo derive, no.
            'openWork' => array_values(array_map(
                static fn (Todo $t): array => [
                    'id' => $t->id,
                    'text' => $t->text,
                    'status' => $t->status->value,
                    // A través del contrato: `Todo::$origin` nació el 2026-08-01 y el `?->` protege
                    // el desreferenciado, NO la lectura — con un vendor anterior emite el aviso que
                    // destruye la pantalla del TUI antes de devolver null.
                    'origin' => ContratoInstalado::valorDeEnum($t, 'origin'),
                    'mutationsSince' => max(0, $sesion->mutations - $t->mutationsAt),
                ],
                array_filter($sesion->todos, static fn (Todo $t): bool => $t->status !== TodoStatus::Done),
            )),
            'decisions' => array_map(
                static fn (array $d): array => [
                    'question' => $d['question'],
                    'answer' => $d['answer'],
                    // `by` va con su `verified` pegado y NO se aplana a una cadena: un id sin su
                    // nivel de confianza se lee como una identidad probada, y la mitad de las veces
                    // no lo es.
                    'by' => ($d['by'] ?? null) instanceof Principal ? $d['by']->toArray() : null,
                    // Presente sólo cuando nadie decidió: la ventana se cerró sola.
                    'expired' => $d['expired'] ?? null,
                ],
                // `decisions` puede no existir en un vendor anterior, y `array_map` sobre null es
                // TypeError, no aviso: la operación entera se cae en vez de mostrar lo que sí sabe.
                ContratoInstalado::arreglo($sesion, 'decisions'),
            ),
            'endedBecause' => $sesion->endedBecause,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function contestar(array $input, ?InvocationContext $ctx = null): array
    {
        // ATRIBUCIÓN EXIGIDA, Y SIN DEGRADAR. En un canal que promete identidad —web, MCP— contestar
        // sin actor verificable escribiría un permiso a nombre del proceso técnico, y ese registro se
        // lee como auditoría sin serlo. La respuesta correcta es negarse.
        //
        // La terminal es el caso honesto y sigue permitida: ahí no hay actor y el registro lo dice —
        // `cli:usuario@máquina`, sin verificar. Lo que no puede pasar es que un canal CON identidad
        // la pierda al escribirla.
        if ($ctx !== null && $ctx->channel !== 'cli' && !$ctx->isAttributable()) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'contestar por «%s» exige un actor verificado, y esta invocación no lo trae',
                    $ctx->channel,
                ),
                'hint' => 'autentica la petición: un permiso sin principal no es auditable',
            ];
        }

        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        $respuesta = \is_string($input['answer'] ?? null) ? trim($input['answer']) : '';
        if ($respuesta === '') {
            return ['ok' => false, 'error' => 'falta `answer`: qué le contestas'];
        }

        $sesion = $almacen->load($id);
        if ($sesion === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        if ($sesion->question === null) {
            // Contestar algo que nadie preguntó no es inocuo: si sólo se apendara, quedaría un turno
            // suelto que el modelo leería como contexto en la siguiente vuelta.
            return [
                'ok' => false,
                'error' => "la sesión «{$id}» no está esperando ninguna respuesta",
                'hint' => 'córrela con `coa agent "…" --session=' . $id . '`',
            ];
        }

        $pregunta = $sesion->question;
        $almacen->answer(
            $id,
            $pregunta->id,
            $respuesta,
            $this->quienContesta($ctx),
            $ctx instanceof InvocationContext && $ctx->executor !== null ? $ctx->executor : $this->procesoLocal(),
        );

        // Un «sí» a una pregunta de PERMISO otorga esa operación para el resto de la sesión. El
        // permiso se deriva del id de la pregunta y no de lo que alguien teclee: así no se puede
        // autorizar algo que la sesión nunca pidió.
        $otorgado = null;
        if (str_starts_with($pregunta->id, 'perm:') && $this->esAfirmativa($respuesta)) {
            $otorgado = substr($pregunta->id, 5);
            $almacen->grant($id, $otorgado);
        }

        return [
            'ok' => true,
            'session' => $id,
            'answered' => $pregunta->id,
            'granted' => $otorgado,
            'hint' => 'retoma con `coa agent "sigue" --session=' . $id . '`',
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function cambiarModo(array $input): array
    {
        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        $modo = AutonomyMode::tryFrom(\is_string($input['mode'] ?? null) ? $input['mode'] : '');
        if ($modo === null) {
            return [
                'ok' => false,
                'error' => 'modo desconocido — válidos: '
                    . implode(', ', array_map(static fn (AutonomyMode $m): string => $m->value, AutonomyMode::cases())),
            ];
        }

        $sesion = $almacen->load($id);
        if ($sesion === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        $antes = $sesion->mode;
        $almacen->setMode($id, $modo);

        return [
            'ok' => true,
            'session' => $id,
            'from' => $antes->value,
            'mode' => $modo->value,
            // Lo que NINGÚN modo cambia se dice al cambiar de modo, que es cuando alguien podría creer
            // lo contrario. Subir a `auto` es dejar de preguntar por lo reversible, no firmar en blanco.
            'note' => 'lo que exige firma se sigue deteniendo en cualquier modo',
        ];
    }

    /**
     * Si una respuesta autoriza.
     *
     * Lista corta y explícita: cualquier cosa que no esté aquí NO autoriza. Un «tal vez» o un
     * «adelante pero con cuidado» tienen que caer del lado de la negativa, porque interpretar de más
     * en la pieza que otorga permisos es exactamente donde no se quiere ser listo.
     */
    private function esAfirmativa(string $respuesta): bool
    {
        return \in_array(mb_strtolower($respuesta), ['sí', 'si', 'yes', 'y', 's'], true);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: SessionStore|null, 1: string, 2: array<string, mixed>|null} el almacén es
     *                                                                              `null` sólo cuando
     *                                                                              hay error
     */
    private function target(array $input): array
    {
        $almacen = $this->sessions();
        if ($almacen === null) {
            return [null, '', ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones']];
        }

        $id = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        if ($id === '') {
            return [null, '', ['ok' => false, 'error' => 'falta `session`: cuál']];
        }

        return [$almacen, $id, null];
    }

    /**
     * El mismo almacén que usa {@see AgentOperations}, por la misma vía.
     *
     * Se resuelve del contenedor y no se construye aquí: dos lugares que decidan dónde viven las
     * sesiones son dos lugares donde pueden dejar de coincidir, y el día que dejaran de hacerlo
     * `agent:answer` contestaría en una sesión que `agent` no está leyendo.
     */
    private function sessions(): ?SessionStore
    {
        if (!class_exists(SessionStore::class)) {
            return null;
        }

        return (new AgentOperations($this->container))->sessionStore();
    }
}
