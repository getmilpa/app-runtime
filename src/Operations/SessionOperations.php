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
use Milpa\Agent\SessionProjector;
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
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;

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
                // EXPONER UNA LECTURA NO CONCEDE EL DERECHO A LEERLA (greenhouse decisions/0082): los hechos
                // de una sesión son del actor que la corre. Sin scopes la política HTTP no se consulta y
                // `config/http.php` era una lista de cosas públicas (evidence/0318). `hasAnyScope`: el token
                // de respuesta que el README reparte sigue leyendo; un token de sólo lectura existe por fin.
                scopes: ['agent:read', 'agent:answer'],
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'The agent sessions of this app, and where each one stands',
                handler: fn (array $input): array => $this->listar(),
                inputSchema: ['type' => 'object', 'properties' => []],
                // WHAT COMES BACK — because three other operations demand a `session` and nothing in
                // the catalogue said where one comes from (evidence/0095). The chain already ran:
                // this returns rows keyed `session` and `agent:show`, `agent:board` and
                // `agent:timeline` ask for exactly that key. It was true and unpublished, so an
                // agent could plan one call and never two.
                //
                // Declared against the command's real output, never against what the shape looks
                // like it should be: an outputSchema that lies is worse than none, since a reader
                // plans against it. `enum` values are the AutonomyMode cases and the three arms of
                // the state match below — read, not guessed.
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean', 'description' => 'False when this app has nowhere to store sessions'],
                        'total' => ['type' => 'integer', 'description' => 'How many sessions were read'],
                        'error' => ['type' => 'string', 'description' => 'Why the listing could not be produced; absent when ok'],
                        'sessions' => [
                            'type' => 'array',
                            'description' => 'One row per session, newest first as the store yields them',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'session' => ['type' => 'string', 'description' => 'The session identifier — what agent:show, agent:board and agent:timeline ask for'],
                                    'goal' => ['type' => 'string', 'description' => 'What the session was opened to achieve'],
                                    'mode' => ['type' => 'string', 'enum' => ['ask', 'acknowledge', 'auto'], 'description' => 'How much autonomy it was granted'],
                                    'turns' => ['type' => 'integer', 'description' => 'How many turns it has taken'],
                                    'state' => ['type' => 'string', 'enum' => ['viva', 'esperando respuesta', 'terminada'], 'description' => 'Where it stands right now'],
                                    'pending' => ['type' => 'integer', 'description' => 'Todos still open'],
                                    'unexplained' => ['type' => 'integer', 'description' => 'Work done that no card explains'],
                                ],
                                'required' => ['session', 'goal', 'mode', 'turns', 'state', 'pending', 'unexplained'],
                            ],
                        ],
                    ],
                    // Only `ok` survives both arms: the failure arm returns `error` and no listing.
                    'required' => ['ok'],
                ],
                mutating: false,
            ),
            new Operation(
                name: 'agent:show',
                // EXPONER UNA LECTURA NO CONCEDE EL DERECHO A LEERLA (greenhouse decisions/0082): los hechos
                // de una sesión son del actor que la corre. Sin scopes la política HTTP no se consulta y
                // `config/http.php` era una lista de cosas públicas (evidence/0318). `hasAnyScope`: el token
                // de respuesta que el README reparte sigue leyendo; un token de sólo lectura existe por fin.
                scopes: ['agent:read', 'agent:answer'],
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'Everything known about one session: goal, plan, todos, permissions and how it ended',
                handler: fn (array $input): array => $this->mostrar($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => [
                            'type' => 'string',
                            'description' => 'The session identifier',
                            // WHERE THIS VALUE COMES FROM, said rather than guessed (evidence/0098). Matching by name
                            // fails exactly where it decides work — four of five chains in evidence/0097 — so the
                            // edge is declared ON the parameter that needs it, and a verifier checks it against
                            // the catalogue. An annotation nobody verifies is prose with better syntax.
                            'x-milpa-source' => ['tool' => 'agent:sessions', 'path' => 'sessions', 'key' => 'session'],
                        ],
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
                    subject: Subject::Configuration,
                ),
                description: 'Change how far a session may go without asking',
                handler: fn (array $input): array => $this->cambiarModo($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => [
                            'type' => 'string',
                            'description' => 'The session identifier',
                            // WHERE THIS VALUE COMES FROM, said rather than guessed (evidence/0098). Matching by name
                            // fails exactly where it decides work — four of five chains in evidence/0097 — so the
                            // edge is declared ON the parameter that needs it, and a verifier checks it against
                            // the catalogue. An annotation nobody verifies is prose with better syntax.
                            'x-milpa-source' => ['tool' => 'agent:sessions', 'path' => 'sessions', 'key' => 'session'],
                        ],
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
                // EXPONER UNA LECTURA NO CONCEDE EL DERECHO A LEERLA (greenhouse decisions/0082): los hechos
                // de una sesión son del actor que la corre. Sin scopes la política HTTP no se consulta y
                // `config/http.php` era una lista de cosas públicas (evidence/0318). `hasAnyScope`: el token
                // de respuesta que el README reparte sigue leyendo; un token de sólo lectura existe por fin.
                scopes: ['agent:read', 'agent:answer'],
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'What happened in a session, translated for a surface to paint: cards, plan and closing',
                handler: fn (array $input): array => $this->linea($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => [
                            'type' => 'string',
                            'description' => 'The session identifier',
                            // WHERE THIS VALUE COMES FROM, said rather than guessed (evidence/0098). Matching by name
                            // fails exactly where it decides work — four of five chains in evidence/0097 — so the
                            // edge is declared ON the parameter that needs it, and a verifier checks it against
                            // the catalogue. An annotation nobody verifies is prose with better syntax.
                            'x-milpa-source' => ['tool' => 'agent:sessions', 'path' => 'sessions', 'key' => 'session'],
                        ],
                        'since' => ['type' => 'integer', 'description' => 'The last sequence you already saw; 0 brings everything'],
                    ],
                    'required' => ['session'],
                ],
                mutating: false,
                // POR HTTP TAMBIÉN, a diferencia de las otras. Leer lo que ya pasó no autoriza nada y
                // es justo lo que un navegador necesita para pintar el trabajo en vivo. Las que sí
                // deciden —contestar, cambiar el modo— siguen fuera de la web.
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            // ── LA SUPERFICIE DEL PUESTO DE DESARROLLADOR ───────────────────────────────────────
            //
            // No hay tres usuarios: hay dos —el humano y el agente— y un PUESTO TRANSVERSAL que a
            // veces ocupa uno y a veces el otro. Lo que lo define no es quién lo llena sino que
            // necesita LAS DOS VISTAS A LA VEZ: lo que el sistema mostró y lo que de verdad ocurrió.
            //
            // Por eso esto no es una tercera verdad. Es UNA fuente y dos proyecciones: la de máquina
            // sale de aquí como hecho, la humana la pinta la consola con el mismo hecho. Dos
            // implementaciones podrían discrepar; una fuente no.
            //
            // Y por eso DICE lo que no puede decir. Ocho veces en una jornada un instrumento enseñó
            // un subconjunto callándoselo —un catálogo de 22 de 28, un cursor que no dice cuántos
            // eventos deja fuera—, y cada vez costó una vuelta entera de diagnóstico. Una vista
            // parcial que no declara ser parcial es más peligrosa que una vista pequeña.
            new Operation(
                name: 'agent:observe',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'What the agent was given and what it did with it, from the same source — including what this view cannot say',
                handler: fn (array $input): array => $this->observar($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => [
                            'type' => 'string',
                            'description' => 'The session identifier',
                            'x-milpa-source' => ['tool' => 'agent:sessions', 'path' => 'sessions', 'key' => 'session'],
                        ],
                    ],
                    'required' => ['session'],
                ],
                mutating: false,
                // EN TODAS LAS SUPERFICIES, incluida la web: leer lo que ya pasó no autoriza nada, y
                // el puesto lo ocupa un agente tan seguido como un humano. Dejarla fuera de `mcp`
                // sería documentar que el puesto existe y no atenderlo — el siguiente agente
                // tendría que volver a fabricarse un proxy.
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'agent:answer',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::Irreversible,
                    Authority::WriteAsUser,
                    subject: Subject::Data,
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
                        'session' => [
                            'type' => 'string',
                            'description' => 'The session identifier',
                            // WHERE THIS VALUE COMES FROM, said rather than guessed (evidence/0098). Matching by name
                            // fails exactly where it decides work — four of five chains in evidence/0097 — so the
                            // edge is declared ON the parameter that needs it, and a verifier checks it against
                            // the catalogue. An annotation nobody verifies is prose with better syntax.
                            'x-milpa-source' => ['tool' => 'agent:sessions', 'path' => 'sessions', 'key' => 'session'],
                        ],
                        'answer' => ['type' => 'string', 'description' => 'Your answer — «sí» authorises the operation for this session'],
                        'counter' => ['type' => 'string', 'description' => 'A COUNTER instead of answer: your constraint (e.g. «use 200, not 250»). It grants nothing — it re-queues the session so the agent re-proposes the call with your constraint, and that call re-faces the gate. Mutually exclusive with `answer`.'],
                        // THE STRUCTURAL COUNTER (greenhouse decisions/0067): tighten the EFFECT ENVELOPE
                        // the proposed call may run under — the five axes, nothing else. A key that is
                        // not an axis is a change of target, and that is `counter`. `guaranteed` is not
                        // offered: it needs a producer-backed rollback contract, never a click.
                        'envelope' => [
                            'type' => 'object',
                            'description' => 'Tighten instead of answer: the effect envelope the proposed call is allowed to run under, as the axes you want LOWERED (e.g. {"reversibility":"compensatable"}). Granted at the gate as meet(declared ceiling, yours) — it can only lower, never widen — and the SAME call runs if it fits. Mutually exclusive with `answer` and `counter`.',
                            'properties' => [
                                'mutation' => ['type' => 'string', 'enum' => ['none', 'ephemeral', 'persistent']],
                                'externality' => ['type' => 'string', 'enum' => ['none', 'same_principal', 'third_party', 'public']],
                                'reversibility' => ['type' => 'string', 'enum' => ['compensatable', 'manual_recovery', 'irreversible']],
                                'authority' => ['type' => 'string', 'enum' => ['none', 'read', 'write_as_user', 'privileged']],
                                'subject' => ['type' => 'string', 'enum' => ['none', 'data', 'configuration', 'executable']],
                            ],
                            'additionalProperties' => false,
                            'minProperties' => 1,
                        ],
                    ],
                    'required' => ['session'],
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
                // EXPONER UNA LECTURA NO CONCEDE EL DERECHO A LEERLA (greenhouse decisions/0082): los hechos
                // de una sesión son del actor que la corre. Sin scopes la política HTTP no se consulta y
                // `config/http.php` era una lista de cosas públicas (evidence/0318). `hasAnyScope`: el token
                // de respuesta que el README reparte sigue leyendo; un token de sólo lectura existe por fin.
                scopes: ['agent:read', 'agent:answer'],
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'The work of one session as four columns, derived from its stream',
                handler: fn (array $input): array => $this->tablero($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => [
                            'type' => 'string',
                            'description' => 'The session identifier',
                            // WHERE THIS VALUE COMES FROM, said rather than guessed (evidence/0098). Matching by name
                            // fails exactly where it decides work — four of five chains in evidence/0097 — so the
                            // edge is declared ON the parameter that needs it, and a verifier checks it against
                            // the catalogue. An annotation nobody verifies is prose with better syntax.
                            'x-milpa-source' => ['tool' => 'agent:sessions', 'path' => 'sessions', 'key' => 'session'],
                        ],
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
                    subject: Subject::Data,
                ),
                description: 'Discard a session: it stops waiting and nothing can resume it',
                handler: fn (array $input, ?InvocationContext $ctx = null): array => $this->descartar($input, $ctx),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => [
                            'type' => 'string',
                            'description' => 'The session identifier',
                            // WHERE THIS VALUE COMES FROM, said rather than guessed (evidence/0098). Matching by name
                            // fails exactly where it decides work — four of five chains in evidence/0097 — so the
                            // edge is declared ON the parameter that needs it, and a verifier checks it against
                            // the catalogue. An annotation nobody verifies is prose with better syntax.
                            'x-milpa-source' => ['tool' => 'agent:sessions', 'path' => 'sessions', 'key' => 'session'],
                        ],
                        'because' => ['type' => 'string', 'description' => 'Why it is discarded — it stays in the stream'],
                    ],
                    'required' => ['session', 'because'],
                ],
                mutating: true,
                // MISMO PISO QUE CONTESTAR: descartar decide sobre una sesión, así que exige actor
                // verificado en todo canal que prometa identidad.
                scopes: ['agent:answer'],
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'session:own',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // Append-only stream: ownership is a fact once asserted, and facts are not
                    // withdrawn — revocation is its own governed act, undecided on purpose
                    // (greenhouse decisions/0056).
                    Reversibility::Irreversible,
                    // It binds the HUMAN'S identity to the session: everything the authority judge
                    // later grants those facts is spent on this signature's account.
                    Authority::WriteAsUser,
                    subject: Subject::Configuration,
                ),
                description: 'Own this session: store the signed assertion that names its owner — verifiably',
                handler: fn (array $input): array => $this->adueniar($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => [
                            'type' => 'string',
                            'description' => 'The session identifier this signature will be bound to',
                            'x-milpa-source' => ['tool' => 'agent:sessions', 'path' => 'sessions', 'key' => 'session'],
                        ],
                    ],
                    'required' => ['session'],
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean', 'description' => 'False when nothing was stored — the error says why'],
                        'session' => ['type' => 'string', 'description' => 'The session that now carries the assertion'],
                        'owner' => ['type' => 'string', 'description' => 'The verified signer, as key:<fingerprint> — never the terminal user'],
                        'note' => ['type' => 'string', 'description' => 'What was stored, and what every consumer must still do'],
                        'error' => ['type' => 'string', 'description' => 'Why owning did not happen; absent when ok'],
                    ],
                    'required' => ['ok'],
                ],
                mutating: true,
                // THE SIGNATURE IS THE ACT, not a formality around it: the signed payload IS the
                // assertion this operation stores. Without --sign there is nothing to store.
                requiresConfirmation: true,
            ),
        ];
    }

    /**
     * One session's board: four columns, ALL of them derived from the stream (P19.5).
     *
     * ── LA PROPIEDAD QUE NO SE PUEDE PERDER ─────────────────────────────────────────────────────
     *
     * **The board holds no state.** What you see is the fold of the stream, re-read on every call. In
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
            return $error ?? ['ok' => false, 'error' => 'this app has nowhere to store sessions'];
        }

        $session = $almacen->load($id);
        if ($session === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        $columnas = [];
        foreach (TodoStatus::cases() as $estado) {
            $columnas[$estado->value] = [];
        }

        // ONLY THE PLAN GENERATION IN FORCE.
        //
        // Re-planning is what completes long work —Q-P17-L: 6/9 against 0/9— and it is also what
        // stacked generations: measured on a real session, TWENTY pending cards for SIX tasks, six
        // copies of the same one. Showing all of them as today's state is the lie that refreshes
        // itself, and it beats an empty board only in looking alive.
        //
        // Filtered HERE, with nothing retired from the stream: the copies happened and they stay.
        // The board holds no state — what you see is the fold, and the fold picks its generation.
        //
        // A session whose plan carries no version filters nothing: there are no generations to tell
        // apart, and hiding cards there would invent a criterion nobody declared.
        $generation = $session->planVersion;

        // THE MOST RECENT CARD FOR EACH TEXT, in arrival order — and no generations involved.
        //
        // The generation approach could not work here and the measurement said why: nine `plan_set`
        // events in one real session, ALL of them version 1. The agent does not write a NEW plan when
        // it re-plans — it writes the SAME plan again, so there are no generations to compare and the
        // duplicates come from re-adding cards under an unchanged plan.
        //
        // Three refinements were built on a discriminator that never varied. What actually separates
        // a restatement from a distinct task is the text, and the risk of merging on it was measured
        // rather than assumed: of sixteen groups sharing a text, ZERO had more than one card ever
        // worked. The agent never treated them as two.
        $newest = [];
        foreach ($session->todos as $t) {
            $newest[self::normalised($t->text)] = $t->id;
        }

        // Which ones were superseded, read from the fold itself: each card names the one it replaced.
        $replaced = [];
        foreach ($session->todos as $t) {
            if ($t->replaces !== null) {
                $replaced[$t->replaces] = true;
            }
        }
        foreach ($session->todos as $todo) {
            if ($generation > 0 && $todo->planVersion > 0 && $todo->planVersion !== $generation) {
                continue;
            }
            // AND WHAT SOMEBODY DECLARED REPLACED STOPS BEING SHOWN.
            //
            // Comparing plan generations could not do this: the stamp records when a card was last
            // touched, so one moved after a re-plan migrates to the new generation and survives the
            // filter. Verified rather than assumed — seven generations removed seven cards in one
            // run and none in the other.
            //
            // Whether two cards speak about the same task is not something the system can derive
            // without guessing what the agent meant. So it gets declared, and the declaration is
            // EXECUTED here rather than trusted as prose: `must` governs 0/8 in this house.
            if (isset($replaced[$todo->id])) {
                continue;
            }
            // AND A CARD RESTATED BY A LATER PLAN GENERATION STEPS ASIDE FOR IT.
            //
            // Re-planning REFORMULATES: of 68 card births measured on real sessions, 51 repeated a
            // text already there — and not approximately, the SAME text once normalised. That is why
            // this is not the system guessing what the agent meant: it is reading what the agent
            // wrote twice.
            //
            // Compared on the BIRTH generation, never on the last touch: a card moved after a
            // re-plan migrates forward under the touch stamp and stops being comparable with the one
            // it duplicates. That is exactly why this rule shipped inert the first time.
            //
            // Only ACROSS generations, never within one. A plan that legitimately lists «verify»
            // twice in the same generation is naming two moments, and collapsing those would be the
            // guess this rule refuses to make.
            //
            // And it hides rather than closes: the older card stays open and untouched in the
            // stream. Closing it would need to know it is done, which nobody here knows — measured,
            // 17% to 33% of a generation's tasks never reappear in the next one, and treating that
            // silence as completion would throw away up to a third of the work.
            if (($newest[self::normalised($todo->text)] ?? $todo->id) !== $todo->id) {
                continue;
            }

            $fila = [
                'id' => $todo->id,
                'text' => $todo->text,
                // LA VERSIÓN VIAJA. Es lo que deja a quien pinta saber si una tarjeta se movió o si
                // sólo se volvió a leer, sin comparar textos.
                'version' => $todo->version,
                'origin' => $todo->origin?->value,
                // CUÁNTAS MUTACIONES PASARON DESPUÉS de tocar esta tarjeta. `0` es una tarjeta al día;
                // un número alto es el sistema diciendo «cambiaron siete cosas y esto no se movió ni
                // se cerró». No afirma que esté mal: afirma que no se explicó.
                'unexplained' => max(0, $session->mutations - $todo->mutationsAt),
            ];

            // AN OPEN QUESTION HOLDS THE WORK IN FLIGHT (Rod, 2026-08-06): while the session waits
            // for an answer, its in-progress cards are not advancing — presenting them under
            // `in_progress` would be the board claiming movement that is not happening.
            //
            // DERIVED here, never written to the stream: emitting `todo_changed` from this gate
            // would be the system fabricating the agent's bookkeeping, with a return trip owed at
            // answer time. The stream already holds both facts — a card in progress, a question
            // open — and the fold composes them. `held_by` says WHY the card sits here, so a
            // surface can tell the derived hold from a block the agent declared.
            $destino = $todo->status->value;
            if ($session->question !== null && $todo->status === TodoStatus::InProgress) {
                $destino = TodoStatus::Blocked->value;
                $fila['held_by'] = 'question';
            }

            $columnas[$destino][] = $fila;
        }

        // THE WORK ITSELF, not only what the agent narrated (greenhouse evidence/0284–0286). The board
        // folds the stream into ONE card per assistant turn — the unit of work, read from stream order,
        // with zero todo calls — so it shows what the agent actually did, not only what it remembered
        // to write down. A turn is `in progress` only while it is the current one of a live session
        // (blocked if that turn waits on a question); a session that ended, and every earlier turn, is
        // `done`. Reads and mutations are told apart by the fact `mutated`, never by asking the agent.
        $turnos = array_values(array_filter(
            (new SessionProjector())->boardCards($almacen->stream($id)),
            static fn (array $c): bool => ($c['card']['origin'] ?? '') === 'turn',
        ));
        $viva = $session->endedBecause === null;
        foreach ($turnos as $c) {
            $card = $c['card'];
            // The card's own status, derived by boardCards from stream order (greenhouse evidence/0287):
            // `doing` is the open cycle the agent is still in, `done` is a cycle the response closed.
            // A session that ended holds nothing open, so even a `doing` card lands in done.
            $enCurso = ($card['to'] ?? 'done') === 'doing' && $viva;
            if ($enCurso) {
                $destino = $session->question !== null ? TodoStatus::Blocked->value : TodoStatus::InProgress->value;
            } else {
                $destino = TodoStatus::Done->value;
            }
            $columnas[$destino][] = [
                'id' => \is_string($card['id'] ?? null) ? $card['id'] : '',
                'text' => \is_string($card['text'] ?? null) ? $card['text'] : '',
                'version' => 1,
                'origin' => 'turn',
                'mutated' => ($card['mutated'] ?? false) === true,
                'unexplained' => 0,
            ];
        }

        $salida = ['ok' => true, 'session' => $id, 'columns' => $columnas];

        // LA PREGUNTA ABIERTA VA EN EL TABLERO, porque es trabajo detenido esperando a un humano — y
        // un tablero que no la muestra deja al agente parado sin que nadie lo vea.
        if ($session->question !== null) {
            $salida['pending_question'] = $session->question->question;
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
            return $error ?? ['ok' => false, 'error' => 'this app has nowhere to store sessions'];
        }

        // EL MOTIVO ES OBLIGATORIO. Una sesión que se cierra sin decir por qué deja a quien lea el
        // stream mañana con un final y ninguna causa — y el caso que esto atiende es justamente una
        // pregunta que nadie contestó: sin el motivo, se ve idéntico a un trabajo terminado.
        $porque = \is_string($input['because'] ?? null) ? trim($input['because']) : '';
        if ($porque === '') {
            return ['ok' => false, 'error' => 'falta `because`: por qué se descarta, y queda en el stream'];
        }

        $session = $almacen->load($id);
        if ($session === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        $almacen->end($id, $porque);

        return ['ok' => true, 'session' => $id];
    }

    /**
     * La proyección de máquina de la observación: los mismos hechos, sin una palabra más.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function observar(array $input): array
    {
        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'this app has nowhere to store sessions'];
        }

        $observacion = $almacen->observation($id);

        // UNA SESIÓN QUE NO EXISTE NO SE ENSEÑA VACÍA. Ceros por todos lados y «no existe» mandan a
        // lugares opuestos —a revisar la app, o a revisar el identificador— y son indistinguibles si
        // los dos se pintan igual.
        if (!$observacion->exists) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        return ['ok' => true, 'result' => $observacion->toArray()];
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
            return $error ?? ['ok' => false, 'error' => 'this app has nowhere to store sessions'];
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
    private function trabajoSinExplicar(Session $session): int
    {
        $peor = 0;
        foreach ($session->todos as $todo) {
            if ($todo->status === TodoStatus::Done) {
                continue;
            }
            $peor = max($peor, $session->mutations - $todo->mutationsAt);
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
     * Ver [Q-P19-B](../../../docs/library/settlement-q-p19b.md): comparando con `milpa/workflow`
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
            return ['ok' => false, 'error' => 'this app has nowhere to store sessions'];
        }

        $filas = [];

        // UNA sola lectura del log, no una por sesión. `load()` en un bucle sobre `ids()` reproducía
        // el log entero por cada sesión —O(sesiones × eventos)—, y con ~2000 sesiones eso colgaba
        // `/sessions` por minutos. `loadAll()` lee el log una vez y reduce cada stream (greenhouse:
        // «el /sessions colgado»).
        foreach ($almacen->loadAll() as $id => $session) {
            $filas[] = [
                'session' => $id,
                'goal' => $session->goal,
                'mode' => $session->mode->value,
                'turns' => \count($session->turns),
                // Qué le pasa AHORA, que es lo que alguien busca al listar: una sesión esperando una
                // respuesta se ve igual que una viva si sólo se muestra su objetivo.
                'state' => $session->endedBecause !== null
                    ? 'terminada'
                    : ($session->question !== null ? 'esperando respuesta' : 'viva'),
                'pending' => \count($session->pendingTodos()),
                // TRABAJO SIN EXPLICAR. El invariante existía en el stream y no lo leía nadie — que es
                // el patrón que este repositorio lleva un mes cazando. Aquí es donde alguien mira para
                // saber qué sesión necesita atención, así que aquí tiene que estar.
                'unexplained' => $this->trabajoSinExplicar($session),
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
            return $error ?? ['ok' => false, 'error' => 'this app has nowhere to store sessions'];
        }

        $session = $almacen->load($id);
        if ($session === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        return [
            'ok' => true,
            'session' => $session->id,
            'goal' => $session->goal,
            'mode' => $session->mode->value,
            'plan' => $session->plan,
            'todos' => array_map(static fn ($t): array => $t->toArray(), $session->todos),
            'permissions' => $session->permissions,
            'turns' => \count($session->turns),
            'compactedThrough' => $session->compactedThrough,
            'question' => $session->question?->toArray(),
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
                    'mutationsSince' => max(0, $session->mutations - $t->mutationsAt),
                ],
                array_filter($session->todos, static fn (Todo $t): bool => $t->status !== TodoStatus::Done),
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
                ContratoInstalado::arreglo($session, 'decisions'),
            ),
            'endedBecause' => $session->endedBecause,
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
            return $error ?? ['ok' => false, 'error' => 'this app has nowhere to store sessions'];
        }

        $respuesta = \is_string($input['answer'] ?? null) ? trim($input['answer']) : '';
        $contra = \is_string($input['counter'] ?? null) ? trim($input['counter']) : '';
        $sobre = \is_array($input['envelope'] ?? null) ? $input['envelope'] : null;
        $dados = ($respuesta !== '' ? 1 : 0) + ($contra !== '' ? 1 : 0) + ($sobre !== null ? 1 : 0);
        if ($dados === 0) {
            return ['ok' => false, 'error' => 'falta `answer`, `counter` o `envelope`: qué contestas, qué contraofertas, o qué aprietas'];
        }
        if ($dados > 1) {
            return ['ok' => false, 'error' => '`answer`, `counter` y `envelope` son excluyentes: o autorizas/niegas, o contraofertas el valor, o aprietas el sobre'];
        }

        $session = $almacen->load($id);
        if ($session === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        if ($session->question === null) {
            // Contestar algo que nadie preguntó no es inocuo: si sólo se apendara, quedaría un turno
            // suelto que el modelo leería como contexto en la siguiente vuelta.
            return [
                'ok' => false,
                'error' => "la sesión «{$id}» no está esperando ninguna respuesta",
                'hint' => 'córrela con `coa agent "…" --session=' . $id . '`',
            ];
        }

        $pregunta = $session->question;

        // APRETAR EL SOBRE: la contraoferta ESTRUCTURAL (greenhouse decisions/0067). No cambia la
        // llamada —eso sería un `counter`—; cambia cuánto se le permite ser, y se adjudica AQUÍ, sin
        // volver al agente, porque el sistema puede demostrar que el sobre es un meet seguro del techo.
        if ($sobre !== null) {
            return $this->apretar($almacen, $id, $pregunta, $sobre, $ctx);
        }

        // CONTRAOFERTAR ES RE-PROPONER, NO OTORGAR (decisions/0064). Esta rama no puede alcanzar
        // `grant()`: resuelve la pregunta —la sesión vuelve corrible—, siembra la restricción del
        // humano como un turno que el agente leerá para RE-PROPONER, y devuelve `granted: null`. El
        // valor nunca es un permiso; la llamada re-propuesta re-pasa la compuerta desde cero, así que
        // un `200` y un `2_000_000` reciben el MISMO escrutinio fresco. Es lo que hace que una
        // contraoferta no pueda subir autoridad: no tiene poder de otorgar.
        if ($contra !== '') {
            $almacen->answer(
                $id,
                $pregunta->id,
                '⟲ contraoferta: ' . $contra,
                $this->quienContesta($ctx),
                $ctx instanceof InvocationContext && $ctx->executor !== null ? $ctx->executor : $this->procesoLocal(),
            );
            $almacen->recordTurn(
                $id,
                'user',
                'Contraoferta a «' . $pregunta->question . '»: ' . $contra
                    . '. Vuelve a proponer la operación con eso; seguirá requiriendo mi permiso.',
            );

            return [
                'ok' => true,
                'session' => $id,
                'answered' => $pregunta->id,
                'countered' => $contra,
                'granted' => null,
                'hint' => 'retoma con `coa agent "sigue" --session=' . $id . '`',
            ];
        }

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
        } elseif ($pregunta->id === 'perm:sandbox:promote') {
            // DISCARD-ON-REJECT (greenhouse decisions/0071, Precondition B): a «no» to a promotion is
            // terminal — the trial is dead — so it is discarded, making «no» as clean as «sí» (which
            // collapses the trial on promote). The workspace travels in the question's `why`, beside
            // the arguments the human was shown (SessionToolGate::conElHechoAdentro).
            $this->descartarEnsayoDe($pregunta);
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
     * Adjudica una contraoferta ESTRUCTURAL en la compuerta: otorga la operación bajo `meet(B, P_h)`.
     *
     * ── LO QUE ESTO DEMUESTRA, MECÁNICAMENTE (greenhouse decisions/0067) ────────────────────────
     *
     * `B` es el techo DECLARADO de la operación, escrito en el `why` de la pausa por la compuerta
     * —un hecho del sistema en el stream—, nunca el payload del humano. `P_h` son las hachas que el
     * humano nombró (`fromPartial`: las demás quedan en `Unknown`, el tope, así el meet las deja en B).
     * `E = meet(B, P_h)` sólo puede bajar; y ANTES de apendar se re-verifica `E ≤ B` sobre el valor
     * concreto —el tripwire—: si alguna vez no se cumple, nada se otorga. Por transitividad, toda
     * llamada que luego quepa en `E` cabía en `B`: un sobre jamás ensancha un «sí» pelón.
     *
     * La llamada que corre sigue siendo la que el agente propuso; el sobre sólo la filtra. Un sobre
     * que no baja ninguna hacha es un «sí», y se dice así en vez de fingir un apriete.
     *
     * @param array<string, mixed> $sobre las hachas que el humano aprieta, p.ej. ['reversibility' => 'compensatable']
     *
     * @return array<string, mixed>
     */
    private function apretar(SessionStore $almacen, string $id, \Milpa\Agent\PendingQuestion $pregunta, array $sobre, ?InvocationContext $ctx): array
    {
        if (!str_starts_with($pregunta->id, 'perm:')) {
            return ['ok' => false, 'error' => 'un `envelope` sólo aprieta una pregunta de PERMISO; ésta es «' . $pregunta->id . '»'];
        }

        /** @var array<string, mixed> $hecho */
        $hecho = json_decode((string) $pregunta->why, true) ?: [];
        $operacion = \is_string($hecho['operation'] ?? null) ? $hecho['operation'] : '';
        $argumentos = \is_array($hecho['arguments'] ?? null) ? $hecho['arguments'] : [];
        if ($operacion === '' || !\is_array($hecho['base'] ?? null)) {
            return [
                'ok' => false,
                'error' => 'esta pausa no registró el techo declarado de la operación (`base`), así que no hay contra qué hacer el meet',
                'hint' => 'es una pausa de una compuerta anterior a los sobres: contesta `answer` o `counter`',
            ];
        }

        try {
            $base = EffectProfile::fromArray($hecho['base']);
            $pedido = EffectProfile::fromPartial($sobre);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $sobreEfectivo = $base->meet($pedido);

        // EL TRIPWIRE. `meet` está probado como invariante, y aun así se re-verifica sobre el valor
        // concreto antes de apendar: la demostración no descansa en la suite, sino en esta línea.
        // Si alguien cambia `meet` por `join`, esto lanza y nada se otorga.
        if (!$sobreEfectivo->isNoWiderThan($base)) {
            throw new \LogicException('el sobre resultó más ancho que el techo declarado; nada se otorga');
        }

        $apretadas = [];
        foreach (['mutation', 'externality', 'reversibility', 'authority', 'subject'] as $hacha) {
            if ($sobreEfectivo->{$hacha}->weight() < $base->{$hacha}->weight()) {
                $apretadas[] = $hacha;
            }
        }
        if ($apretadas === []) {
            return [
                'ok' => false,
                'error' => 'esto es un «sí»: el sobre no baja ninguna hacha respecto del techo declarado — contesta `answer: sí`',
            ];
        }

        $quien = $this->quienContesta($ctx);
        $almacen->answer(
            $id,
            $pregunta->id,
            'sí',
            $quien,
            $ctx instanceof InvocationContext && $ctx->executor !== null ? $ctx->executor : $this->procesoLocal(),
        );
        $almacen->grant($id, $operacion, $sobreEfectivo->toArray(), [
            'base' => $base->toArray(),
            'requested' => $sobre,
            'question' => $pregunta->id,
            'arguments_digest' => \Milpa\AppRuntime\Agent\ConsentBridge::digest($argumentos),
            'by' => $quien->toArray(),
        ]);

        return [
            'ok' => true,
            'session' => $id,
            'answered' => $pregunta->id,
            'granted' => $operacion,
            'envelope' => $sobreEfectivo->toArray(),
            'base' => $base->toArray(),
            'tightened' => $apretadas,
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
            return $error ?? ['ok' => false, 'error' => 'this app has nowhere to store sessions'];
        }

        $modo = AutonomyMode::tryFrom(\is_string($input['mode'] ?? null) ? $input['mode'] : '');
        if ($modo === null) {
            return [
                'ok' => false,
                'error' => 'modo desconocido — válidos: '
                    . implode(', ', array_map(static fn (AutonomyMode $m): string => $m->value, AutonomyMode::cases())),
            ];
        }

        $session = $almacen->load($id);
        if ($session === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        $antes = $session->mode;
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
    /**
     * Discard the trial named in a rejected promotion's question, or nothing if there is none.
     *
     * The workspace id rides in the question's `why` (`{operation, arguments: {workspace}, ...}`),
     * and the root comes from the kernel. Both absent leaves it a no-op — a reject that can find no
     * trial simply grants and cleans up nothing, never throws.
     */
    private function descartarEnsayoDe(\Milpa\Agent\PendingQuestion $pregunta): void
    {
        $hecho = json_decode((string) $pregunta->why, true);
        $ws = \is_array($hecho) && \is_array($hecho['arguments'] ?? null)
            ? ($hecho['arguments']['workspace'] ?? null)
            : null;
        if (! \is_string($ws) || $ws === '') {
            return;
        }
        $kernel = $this->container->has(\Milpa\Runtime\Kernel::class)
            ? $this->container->get(\Milpa\Runtime\Kernel::class)
            : null;
        if (! $kernel instanceof \Milpa\Runtime\Kernel) {
            return;
        }
        \Milpa\AppRuntime\Agent\TrialWorkspace::open($kernel->root(), $ws)?->discard();
    }

    private function esAfirmativa(string $respuesta): bool
    {
        return \Milpa\AppRuntime\Agent\AffirmativeAnswer::is($respuesta);
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
            return [null, '', ['ok' => false, 'error' => 'this app has nowhere to store sessions']];
        }

        $id = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        if ($id === '') {
            return [null, '', ['ok' => false, 'error' => 'falta `session`: cuál']];
        }

        return [$almacen, $id, null];
    }

    /**
     * The comparable form of a card's text: same words, no accidents of punctuation or case.
     *
     * Deliberately blunt. A cleverer normaliser would start matching cards that merely resemble each
     * other, and the evidence only covers literal repeats.
     */
    private static function normalised(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9 ]/u', '', mb_strtolower($text)) ?? '');
    }

    /**
     * El mismo almacén que usa {@see AgentOperations}, por la misma vía.
     *
     * Se resuelve del contenedor y no se construye aquí: dos lugares que decidan dónde viven las
     * sesiones son dos lugares donde pueden dejar de coincidir, y el día que dejaran de hacerlo
     * `agent:answer` contestaría en una sesión que `agent` no está leyendo.
     */
    /**
     * Own a session: persist the signed authorization as its ownership assertion.
     *
     * The GrantedAuthorization arrives through the container because the verdict used to die at the
     * banner — CliRunner printed «authorized by …» and dropped the verified signer (greenhouse
     * decisions/0056). What is stored is the EXACT signed bytes, because every consumer re-verifies
     * them live (evidence/0254: a stored grade was forged; a re-checked signature was not).
     *
     * The binding check looks redundant — the runner just signed THIS call — and stays on purpose:
     * this handler also runs from surfaces that are not the CLI runner, and a defence that depends
     * on who called it is not a defence.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function adueniar(array $input): array
    {
        $session = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        if ($session === '') {
            return ['ok' => false, 'error' => 'which session? `session` is required — agent:sessions lists them'];
        }

        $concedida = $this->container->has(GrantedAuthorization::class)
            ? $this->container->get(GrantedAuthorization::class)
            : null;
        if (! $concedida instanceof GrantedAuthorization) {
            return [
                'ok' => false,
                'error' => 'owning a session requires the signature that names its owner; re-run with --sign — '
                    . 'the signed payload IS the assertion this operation stores',
            ];
        }

        if (
            $concedida->authorization->operation !== 'session:own'
            || ($concedida->authorization->arguments['session'] ?? null) !== $session
        ) {
            return [
                'ok' => false,
                'error' => 'the granted signature does not cover owning THIS session — nothing was stored',
            ];
        }

        $sesiones = $this->sessions();
        if ($sesiones === null) {
            return ['ok' => false, 'error' => 'this app has nowhere to store sessions'];
        }

        $sesiones->assertOwnership($session, [
            'payload' => $concedida->payload,
            'signature' => $concedida->signature,
            'fingerprint' => $concedida->signer->fingerprint,
            'uid' => $concedida->signer->uid,
        ]);

        return [
            'ok' => true,
            'session' => $session,
            'owner' => 'key:' . $concedida->signer->fingerprint,
            'note' => 'the signed assertion is stored; every consumer re-verifies it live before trusting it',
        ];
    }

    private function sessions(): ?SessionStore
    {
        if (!class_exists(SessionStore::class)) {
            return null;
        }

        return (new AgentOperations($this->container))->sessionStore();
    }
}
