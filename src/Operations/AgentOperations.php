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

use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\RunInterrupted;
use Milpa\AppRuntime\Agent\ArchitectureSummaryProjector;
use Milpa\Attributes\PluginMetadata;
use Milpa\Plugin\Runtime\MetadataGraphResolver;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\OptionTable;
use Milpa\AiGateway\SecondOpinionGate;
use Milpa\AppRuntime\Agent\RecordOnlyOptionTable;
use Milpa\AppRuntime\Agent\SessionOptionTable;
use Milpa\AppRuntime\Agent\SessionPlanBoard;
use Milpa\AppRuntime\Agent\StepWatcher;
use Milpa\AppRuntime\Agent\SterileLoopGuard;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\AppRuntime\Agent\TransitionGate;
use Milpa\AppRuntime\Agent\TreeBudget;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Compactor;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\StderrLogger;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\AppRuntime\Agent\PrerequisiteGate;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Support\Operations;
use Milpa\Interfaces\Tooling\ToolProviderInterface;
use Milpa\EventStore\EventStoreInterface;
use Milpa\AppRuntime\Agent\BroadcastingEventStore;
use Milpa\AppRuntime\Agent\MercureBroadcaster;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\EventStore\FileEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;

/**
 * El agente de esta app: le pides algo en tu idioma y lo hace con las MISMAS operaciones que ya
 * tienes.
 *
 * `milpa/ai-gateway` traía el bucle —alterna modelo ↔ herramientas— desde antes, y venía instalado
 * con el framework sin que nadie lo llamara: estaba en el árbol y no existía para quien creaba una
 * app. Esto es la línea que faltaba.
 *
 * ── LAS HERRAMIENTAS SON LOS ÁTOMOS, NO OTRA COSA ───────────────────────────────────────────────
 *
 * El agente ve exactamente lo que ve un cliente MCP, porque se arma igual: las operaciones de esta
 * app proyectadas con {@see McpProjector}. No hay un segundo catálogo de «herramientas del agente»
 * que se pueda desincronizar del primero — sería el mismo defecto que tener dos gestores de plugins.
 *
 * ── SIN LLAVE NO FINGE ──────────────────────────────────────────────────────────────────────────
 *
 * Sin API key configurada contesta qué falta y cómo ponerla. No hay modo demo ni respuesta simulada:
 * un agente que contesta algo plausible sin haber llamado a nadie es peor que uno que no arranca.
 */
class AgentOperations implements CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * @return list<Operation>
     */
    /**
     * Las operaciones del agente que este grupo aporta al registro.
     *
     * Se devuelven en vez de registrarse solas: quien arma el registro decide qué grupos entran y
     * con qué autoridad, y un grupo que se auto-registrara le quitaría esa decisión.
     */
    public function operations(): array
    {
        // LO QUE NO SE PUEDE HACER NO SE OFRECE.
        //
        // This framework ships tiny and grows by opt-in, so `milpa/agent` may well be absent.
        // Advertising an operation whose only answer is «this app does not have…» is worse than
        // leaving it out: whoever reads `coa list` —person or agent— counts it as available, calls
        // it, and learns that the listing lies. `coa capabilities` is where what is MISSING shows,
        // together with the `composer require` that turns it on.
        // SÓLO `agent-runs`, y la diferencia importa: correr y RECORDAR son dos capacidades.
        //
        // The first version demanded both, and with that an app holding a model but lacking
        // `milpa/agent` had `coa agent` at all — where `run()` already handles the null store and
        // answers just the same, only without surviving the process. Building the Q-P20-A laboratory
        // exposed it: the arm meant to ask the agent to install sessions could never start the agent.
        //
        // Es el mismo error que este contrato existe para no cometer: dos capacidades fundidas en una
        // guarda contestan por la que falta, no por la que hace falta.
        // INSPECTING THE CATALOGUE IS NOT RUNNING THE AGENT — and the guard below is precisely the
        // shape this docblock warns about, so it must not swallow this one too. An app with no model
        // still HAS a catalogue, and what an agent would receive is exactly what an app without a
        // model most needs to be able to show: nobody can audit a schema by reading a diff, which is
        // how evidence/0091 had to accept B1, B2 and B3.
        //
        // It reads, it reaches nobody, and it changes nothing — so it costs an app that cannot run
        // the agent nothing to be able to answer what the agent would have been handed.
        $inspection = [
            new Operation(
                name: 'agent:catalogue',
                description: 'The catalogue an agent would receive from this app, with the inputSchema of every tool',
                handler: fn (array $input): array => $this->catalogue($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'reads' => [
                            'type' => 'boolean',
                            'description' => 'Show the read-only catalogue — what the agent gets when the request was classified as reads',
                        ],
                    ],
                    // AN EMPTY `required` IS INFORMATION, ITS ABSENCE IS NOT (evidence/0094). Leaving
                    // the key out does not say «everything is optional», it says «not known», and an
                    // agent reading the schema cannot tell those apart. The handler demands nothing
                    // — `reads` defaults to false — so this declares exactly that, and declaring it
                    // is what the reader could not derive.
                    'required' => [],
                ],
                // THE OPERATION THAT PUBLISHES EVERYONE ELSE'S CEILING NEVER DECLARED ITS OWN.
                //
                // greenhouse evidence/0144 counted it: of the operations a newborn app offers, this
                // was the only one leaving its profile unset — and GOV-05 makes what nobody
                // classified carry the maximum of every dimension. Once rule S2 derives consent from
                // the ceiling, that silence turns into an app interrupting a human to LIST A
                // CATALOGUE.
                //
                // It reads. It writes nothing, it leaves the machine to reach no one, and it changes
                // nothing that could need undoing.
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'reads only: there is nothing to roll back',
                ),
            ),
        ];

        if (!Capabilities::installed('agent-runs')) {
            return $inspection;
        }

        return [
            ...$inspection,
            new Operation(
                name: 'agent',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    // THE ONE THAT DOES NOT LOOK LIKE IT: this sends the petition, and whatever
                    // context it carries, to a model provider. It is the widest reach in the whole
                    // catalogue and nothing here is called «send».
                    Externality::ThirdParty,
                    // A prompt cannot be un-sent, and this house's event streams are never rewritten.
                    Reversibility::Irreversible,
                    Authority::WriteAsUser,
                    subject: Subject::Data,
                ),
                description: 'Ask the agent to do something using the operations of this app',
                handler: fn (array $input): array => $this->run($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'What you want it to do, in your own words'],
                        'steps' => ['type' => 'integer', 'description' => 'Ceiling on model↔tool steps; 12 when unsaid'],
                        'session' => ['type' => 'string', 'description' => 'Continúa esta sesión — sin ella, cada pregunta empieza de cero'],
                        'mode' => ['type' => 'string', 'enum' => ['ask', 'acknowledge', 'auto'], 'description' => 'Autonomía: ask pregunta antes de mutar, auto sigue sola. Ninguno se salta una firma'],
                        // B2 (evidence/0091): both say «Requires --session» and both were offered
                        // whether or not this app can hold one. Same doctrine as above — what
                        // cannot be done is not offered, and a schema is read by agents too.
                        ...($this->sessionStore() !== null ? [
                            'deny' => ['type' => 'string', 'description' => 'Comma-separated tools withdrawn from its catalogue. Requires --session'],
                            'denyEffects' => ['type' => 'string', 'description' => 'Withdraw every operation in these effect classes: mutating|external|irreversible|authority. Unknown effects count as denied. Requires --session'],
                        ] : []),
                        'first' => ['type' => 'string', 'description' => 'Comma-separated tools that must run before anything else proceeds — an ordering obligation, executed rather than asked'],
                    ],
                    'required' => ['prompt'],
                ],
                // MUTA, y lo dice: el agente puede llamar operaciones que cambian cosas. Lo que NO
                // hace es pedir firma por sí mismo — la pide cada herramienta que la exige, cuando la
                // exige, y esa es la compuerta que nombra la llamada concreta. Una firma aquí
                // consentiría «lo que el agente decida», que es justo lo que no se puede consentir.
                mutating: true,
                // Fuera de la terminal: un agente que corre por HTTP con las credenciales del
                // servidor es otra decisión, y esta plantilla no la toma por nadie.
                surfaces: ['cli'],
            ),
        ];
    }

    /**
     * What an agent would receive from this app — the SAME registry, never a re-derivation.
     *
     * ── WHY THIS CALLS `toolsOfThisApp()` AND NOTHING ELSE ───────────────────────────────────────
     *
     * The catalogue is not the operation list. The operations that adjudicate the agent's own
     * session are withdrawn from it, the mutating ones are filtered when the request reads, and the
     * session notebook arrives through a separate argument rather than the registry. An artifact
     * that walked the registered operations and assembled its own answer would print a plausible
     * imitation, and an adversary auditing it would be auditing the imitation.
     *
     * So this asks the one function that builds what the agent is handed, and publishes the summary
     * that function's registry already produces — `inputSchema` included.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, reads: bool, total: int, tools: list<array<string, mixed>>, error?: string}
     */
    private function catalogue(array $input): array
    {
        $readOnly = ($input['reads'] ?? false) === true;

        $registry = $this->toolsOfThisApp([], $readOnly);
        if ($registry === null) {
            return [
                'ok' => false,
                'reads' => $readOnly,
                'total' => 0,
                'tools' => [],
                'error' => 'this app has no kernel, so nobody has assembled a catalogue to show yet',
            ];
        }

        $tools = $registry->getToolSummaries();
        usort($tools, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return [
            'ok' => true,
            'reads' => $readOnly,
            'total' => \count($tools),
            'tools' => $tools,
        ];
    }

    /**
     * Corre el bucle y devuelve lo que el agente contestó.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, answer?: string, steps?: int, tools?: int, error?: string, hint?: string, paused?: bool, exhausted?: bool, interrupted?: bool}
     */
    private function run(array $input): array
    {
        $prompt = \is_string($input['prompt'] ?? null) ? trim($input['prompt']) : '';
        if ($prompt === '') {
            return ['ok' => false, 'error' => 'falta `prompt`: qué quieres que haga'];
        }

        if (!class_exists(AgentOrchestrator::class) || !class_exists(ToolRegistry::class)) {
            return [
                'ok' => false,
                'error' => 'esta app no tiene la superficie de agente instalada',
                'hint' => 'composer require milpa/ai-gateway milpa/tool-runtime',
            ];
        }

        $credencial = $this->credential();
        if ($credencial === null) {
            return [
                'ok' => false,
                'error' => 'no hay API key configurada, así que no hay a quién preguntarle',
                'hint' => 'exporta ANTHROPIC_API_KEY (o OPENAI_API_KEY) y vuelve a correrlo',
            ];
        }

        [$proveedor, $llave, $modelo] = $credencial;

        $pasos = \is_int($input['steps'] ?? null) && $input['steps'] > 0 ? $input['steps'] : 12;

        // LA SESIÓN (P16.1). Sin `session`, esto sigue siendo lo que era: una pregunta con una
        // respuesta. Con ella, la conversación sobrevive al proceso — que es la diferencia entre un
        // agente al que se le pregunta algo y uno con el que se trabaja un rato.
        $store = $this->sessions();
        $sessionId = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        $historial = [];

        if ($sessionId !== '' && $store !== null) {
            // CADUCAR ANTES DE MIRAR. Una pregunta vencida deja la sesión sin poder correr para
            // siempre —`isRunnable()` es falso mientras haya pregunta— así que sin esta línea la
            // sesión olvidada no queda pausada: queda muerta y nadie lo declara ([Q-P19-B]).
            //
            // Va aquí y no en un cron porque el momento en que importa es éste: cuando alguien
            // vuelve a la sesión. Un barrido nocturno mataría sesiones que nadie estaba mirando, y
            // dejaría vivas las que sí — al revés de lo que hace falta.
            $store->expireIfDue($sessionId, new \DateTimeImmutable());

            $sesion = $store->load($sessionId);
            // `ask` cuando no se dice: una sesión que empieza pidiendo permiso enseña qué va a hacer
            // antes de hacerlo, y quien la mira sube el modo cuando ya vio de qué se trata. El default
            // contrario —empezar en `auto`— pediría confianza antes de haberla ganado.
            $modo = AutonomyMode::tryFrom(\is_string($input['mode'] ?? null) ? $input['mode'] : '');

            if ($sesion === null) {
                // Se abre con el primer prompt como objetivo: continuar una sesión que no existe es
                // empezarla, y negarse obligaría a dos comandos para lo que es una intención.
                $store->start($sessionId, $prompt, $modo ?? AutonomyMode::Ask);
            } elseif (!$sesion->isRunnable()) {
                // Una sesión con una pregunta abierta o ya terminada NO se sigue por accidente: se
                // contesta o se abre otra. Seguirla sería contestar por el humano que no contestó.
                return [
                    'ok' => false,
                    'error' => $sesion->question !== null
                        ? "la sesión «{$sessionId}» está esperando una respuesta: {$sesion->question->question}"
                        : "la sesión «{$sessionId}» ya terminó: {$sesion->endedBecause}",
                    'hint' => $sesion->question !== null
                        ? 'contéstala y vuelve a correrlo'
                        : 'usa otro --session para empezar una nueva',
                ];
            } else {
                // `window()` y no `turns`: si ya hubo compactación, esto es el resumen más lo reciente.
                // El stream conserva todo; lo que se acorta es lo que se le manda al modelo.
                $historial = $sesion->window();

                // Un `--mode` sobre una sesión viva la cambia, y queda apendado. Es explícito: alguien
                // lo tecleó. Lo que NO se hace es cambiarlo en silencio cuando no se dijo nada — el
                // modo es de la sesión, no del comando, y heredar el default de cada invocación
                // devolvería a `ask` una sesión que alguien puso en `auto` a propósito.
                if ($modo !== null && $modo !== $sesion->mode) {
                    $store->setMode($sessionId, $modo);
                }

                // COMPACTAR ANTES DE PREGUNTAR (P16.2), no después. Después sería descubrir que la
                // ventana no cabía cuando el proveedor ya la rechazó — y una sesión larga se muere
                // exactamente ahí, a la mitad, con trabajo hecho que conviene no repetir.
                $compactado = $this->compactor()->compactIfNeeded($store, $sesion);
                if ($compactado !== null) {
                    // Se relee: la ventana cambió, y mandar la vieja habría hecho el trabajo de
                    // compactar sin cobrar el beneficio.
                    $sesion = $store->load($sessionId);
                }

                $historial = $sesion?->window() ?? $historial;
            }

            $store->recordTurn($sessionId, 'user', $prompt);
        }

        // LA COMPUERTA (P16.4/P16.5) y LAS HERRAMIENTAS DE LA SESIÓN (P16.3). Las dos sólo con sesión:
        // pedir permiso sin sesión sería pedirlo sin dónde apuntarlo, y ofrecer un `plan` sin dónde
        // guardarlo sería peor — el modelo lo llamaría, lo vería contestar «ok», y seguiría creyendo
        // que dejó un plan.
        // `--first=plan,todo` — the tools that must run before anything else proceeds.
        $runFirst = $this->standingObligation($input['first'] ?? null, $sessionId, $store);

        // AND IT IS TOLD, at the FRONT of the brief — both halves, or neither works.
        //
        // Measured here before the experiment that needed it: with the gate alone, the agent takes
        // ONE step, meets «`plugins_list` does not proceed yet: `plan` runs first», and stops. One
        // step, zero tools, and never a plan. A fact without its sentence is not an obligation but a
        // wall the agent lacks any reason to climb.
        //
        // `SubAgentSpawner` already carried this in writing —«the fact changes the world; the
        // sentence tells it why»— and it goes FIRST rather than appended for a measured reason: hung
        // at the end it pushed the brief's last stage away from the tail, and 4 of 8 runs lost the
        // fifth stage. Position is a mechanism (Q-P20-E), not formatting.
        if ($runFirst !== []) {
            $prompt = 'Before anything else, run: ' . implode(', ', $runFirst)
                . ". Until then none of your other calls proceed.\n\n" . $prompt;
        }

        $compuerta = null;
        $contabilidad = [];
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if ($sessionId !== '' && $store !== null && $kernel instanceof Kernel) {
            $viva = $store->load($sessionId);
            if ($viva !== null) {
                $compuerta = new SessionToolGate(
                    $store,
                    $viva,
                    Operations::all($kernel, $kernel->root()),
                    permissionWindow: $this->permissionWindow(),
                    // La petición de ESTA corrida, no el goal de la sesión: el contrato de intención
                    // (ADR-0044) compara los argumentos contra lo que el humano acaba de pedir.
                    petition: $prompt,
                    vigiaDeBucle: $this->sterileLoopGuard(),
                    // ── AN ORDERING OBLIGATION FOR WHOEVER RUNS THE AGENT ────────────────────
                    //
                    // Same asymmetry `deny` had: the mechanism existed and only a delegating parent
                    // could reach it. `PrerequisiteGate` is measured — Q-P20-I: `must` delivers the
                    // sentence 8/8 and is obeyed 0/8; closing the table until the required call runs
                    // is what makes it a fact instead of a request.
                    //
                    // The reason it matters here and not only in delegation: `plan` and `todo` ask
                    // in prose today —«do it BEFORE starting anything long»— and 32 measured runs
                    // touched neither, not once. A board built on that source would paint stale
                    // cards as current state, which is worse than painting nothing.
                    compuertaPrevia: $runFirst === [] ? null : new PrerequisiteGate($runFirst),
                    // The first arrow (greenhouse evidence/0009), if this host declares it:
                    // founding precedes building, adjudicated from durable state, never from
                    // executions.
                    arrow: $this->foundationArrow(),
                );
                // ATADAS a esta sesión: el id se captura, no se le pide al modelo. Uno que el modelo
                // pudiera nombrar es uno que puede errar, y escribirle el plan a otra sesión no es una
                // equivocación recuperable — quien la lea mañana verá un plan que su agente no escribió.
                $contabilidad = (new SessionBookkeeping($store, $sessionId))->operations();

                // LA DELEGACIÓN (Q-P19-P). El hijo es una sesión con `parentId` corriendo por los
                // MISMOS rieles: mismo orquestador, misma compuerta —que ya pide el techo del linaje
                // en cada llamada—, mismo almacén. El catálogo del hijo sale de su propia
                // contabilidad SIN spawn: profundidad 1 por construcción, y el presupuesto del árbol
                // (§5.4) queda diferido con esa misma decisión a la vista.
                $presupuestoDelArbol = $this->presupuestoDelArbol($pasos);
                $spawner = new SubAgentSpawner(
                    $store,
                    $sessionId,
                    function (string $encargo, string $hijoId, array $historialHijo, array $primeroHijo = []) use ($store, $kernel, $pasos, $proveedor, $llave, $modelo, $presupuestoDelArbol): array {
                        $hijo = $store->load($hijoId);
                        if ($hijo === null) {
                            return ['answer' => 'la sesión hija no se pudo abrir', 'steps' => 0];
                        }

                        $compuertaHijo = new SessionToolGate(
                            $store,
                            $hijo,
                            Operations::all($kernel, $kernel->root()),
                            permissionWindow: $this->permissionWindow(),
                            // El contrato de intención del hijo compara contra SU encargo: lo que el
                            // padre le pidió es, para el hijo, lo que el humano es para el padre.
                            petition: $encargo,
                            // El hijo tiene el SUYO, nuevo: el presupuesto que gasta repitiendo es
                            // el suyo, y los fallos del padre no son los de él.
                            vigiaDeBucle: $this->sterileLoopGuard(),
                            // LA OBLIGACIÓN DE ORDEN, ejecutada (Q-P20-I). Va por el mismo canal que
                            // corre al hijo y NO por el stream, así que sólo gobierna la vuelta que
                            // spawnea: un hijo retomado llega con la mesa abierta. Es residuo
                            // declarado, no descuido — persistirlo pide un evento nuevo en
                            // `milpa/agent`, y la pregunta que esta rebanada contesta se mide en
                            // spawn.
                            compuertaPrevia: $primeroHijo === [] ? null : new PrerequisiteGate($primeroHijo),
                            // THE ARROW GOVERNS THE CHILD TOO, from the same disk: delegation is
                            // not a tunnel — a sub-agent building in an unfounded app would be the
                            // same unearned transition under another name in the stream.
                            arrow: $this->foundationArrow(),
                        );
                        // THE CHILD GETS THE CHANNEL AND NOTHING ELSE FROM THE SPAWNER.
                        //
                        // A `SubAgentSpawner` is built with ITS id as the subject and only
                        // `messageOperation()` is taken: it can talk to whoever delegated to it, and
                        // still has no `agent_spawn` — depth 1 comes from the list, not from a check.
                        //
                        // Without this a child could only say something when it FINISHED. A scout
                        // that found the answer on step two had to run to the end for anyone to hear.
                        $canalDelHijo = new SubAgentSpawner(
                            $store,
                            $hijoId,
                            // The child does not delegate, so this closure is never called. It is
                            // explicit rather than `null` because the signature requires it, and an
                            // exception here would tell the truth if anyone ever handed it `spawn`.
                            static fn (): array => throw new \LogicException('a sub-agent does not delegate: depth 1 by construction'),
                        );

                        $registroHijo = $this->toolsOfThisApp(
                            [
                                ...(new SessionBookkeeping($store, $hijoId))->operations(),
                                $canalDelHijo->messageOperation(),
                            ],
                            registroPropio: true,
                        );
                        if ($registroHijo === null) {
                            return ['answer' => 'esta app no expuso ninguna operación como herramienta', 'steps' => 0];
                        }

                        $vistosHijo = 0;

                        // EL HISTORIAL LO DECIDE EL SPAWNER, no este cableado: vacío al spawnear
                        // (§5.1, el contexto fresco es la razón de ser) y la ventana del hijo al
                        // retomar (Q-P19-Q, retomar no es re-spawnear).
                        // EL TECHO DEL HIJO SALE DEL FONDO: el suyo, o lo que quede si es menos. La
                        // negativa por fondo agotado ya la dio el spawner antes de llegar aquí, así
                        // que esto sólo recorta al último hijo que todavía alcanza a trabajar.
                        $respuestaHijo = $this->ask(
                            $encargo,
                            $presupuestoDelArbol?->techoParaElSiguiente($pasos) ?? $pasos,
                            $registroHijo,
                            $proveedor,
                            $llave,
                            $modelo,
                            function () use (&$vistosHijo): void {
                                ++$vistosHijo;
                            },
                            $historialHijo,
                            $compuertaHijo,
                            // LA MESA DEL HIJO, que antes iba en `null` y por eso una opción retirada
                            // no salía de su catálogo. Es lo que vuelve ejecutable el `deny` de
                            // `agent_spawn`: sin ella, prohibirle una herramienta sería otra frase
                            // más — y Q-P20-G midió cuánto valen las frases (0/8).
                            new SessionOptionTable($store, $hijoId),
                            $compuertaHijo,
                            $this->tableroDePlan($hijoId, $store),
                        );

                        return ['answer' => $respuestaHijo, 'steps' => $vistosHijo];
                    },
                    $presupuestoDelArbol,
                    // THE CHILD IS BORN KNOWING (decisions/0007): the unearned transition's
                    // teaching, derived at spawn time from the same judge as the refusal and the
                    // frontier, prepended to every errand where the goal is COMPOSED — the first
                    // wiring of this arm went through runChild and never reached the recorded
                    // goal, which is what the child's window derives from. Caught by rental v5:
                    // zero taught briefs in three runs (the arm was inert).
                    prologue: fn (): ?string => $this->foundationArrow()?->teaching(),
                );
                $contabilidad[] = $spawner->operation();
                $contabilidad[] = $spawner->resumeOperation();

                // THE CHANNEL, in BOTH catalogues — which is why it is not in the set the child is
                // denied along with `agent_spawn`.
                //
                // A child without `agent_message` can only speak when it finishes: a scout that found
                // the answer on step two would have to run to the end for anyone to hear about it.
                // Depth is still 1 — the child never receives `spawn` — and talking is not delegating.
                //
                // Who may talk to whom is decided by the operation, reading filiation from the
                // stream, not by this list: here it is only offered.
                $contabilidad[] = $spawner->messageOperation();

                // AND THE CATALOGUE OF SPECIALISTS. It is read-only and it is what turns «delegate to
                // the reviewer» from a guess into a lookup: an unknown name becomes a correction
                // rather than a dead end.
                $contabilidad[] = $spawner->rolesOperation();
            }
        }

        // ── EL SEGUNDO JUICIO, SI ESTA APP LO PIDE ──────────────────────────────────────────────
        //
        // `agent.secondOpinion` en `config/app.php` enlista las herramientas que ameritan un segundo
        // lector. Sin esa lista no se envuelve nada y el comportamiento es el de hoy: esto se apila,
        // no reemplaza. Q-P19-D está pre-registrada para medir si sirve, y puede refutarse.
        //
        // Va DESPUÉS de construir la compuerta de sesión y envolviéndola, nunca en su lugar: el piso
        // sintáctico decide primero y su `no` no se apela. Un verificador que pudiera revertirlo sería
        // una vía de escape con forma de mejora.
        // LA MESA DE ESTA SESIÓN. Es la misma autoridad para las dos puntas: quien retira una opción y
        // quien dice cuáles quedan. Separarlas daría dos verdades sobre lo mismo, y este repositorio ya
        // pagó ese precio cuatro veces con los comparadores de capacidad (Q-P17).
        //
        // Sin sesión no hay mesa: retirar una opción sin dónde apuntarlo sería un cambio que no
        // sobrevive al proceso, y el agente volvería a encontrarla enfrente al paso siguiente.
        // ── `deny` FOR WHOEVER RUNS THE AGENT, NOT ONLY FOR WHOEVER DELEGATES ───────────────────
        //
        // The mechanism was already complete — `SubAgentSpawner` withdraws options from the child's
        // table — but only a delegating parent could reach it. Somebody wanting to run a contained
        // reviewer by hand had no way to: all that was left was asking in prose, which is precisely
        // what this house measured does not govern (Q-P20-G: the obligation arrives 8/8 and is obeyed
        // 0/8; withdrawing the option redirects 16/16). Containment was a property of delegation
        // rather than of the system.
        //
        // Same withdrawal, same table, same record in the stream. What changes is who may ask for it.
        $denied = [];
        $asked = $input['deny'] ?? null;
        // The CLI hands `--deny=a,b,c` over as a string; a programmatic call hands over the list.
        foreach (\is_string($asked) ? explode(',', $asked) : (\is_array($asked) ? $asked : []) as $tool) {
            if (\is_string($tool) && trim($tool) !== '') {
                $denied[] = trim($tool);
            }
        }

        // ── AND THE SAME WITHDRAWAL BY EFFECT CLASS, WHICH IS THE ONE THAT DOES NOT LEAK ────────
        //
        // Q-P20-P measured what a list of names is worth. With five tools withdrawn by name, three of
        // three runs reached for `plugins.lock` — an operation that mutates and was not on the list.
        // Not once: 3/3. Take the named door away and the model goes for the one next to it.
        //
        //     Containment by name is worth exactly what the list is worth — and the list is written
        //     by somebody who has to remember everything.
        //
        // «Withdraw what mutates» is not something anyone forgets. It resolves against the LIVE
        // catalogue rather than against a list somebody maintains, so an operation added tomorrow is
        // covered the day it exists.
        //
        // UNKNOWN COUNTS AS DENIED, and that is the invariant that closes the leak rather than
        // narrowing it: an operation that never declared its effects is treated as carrying the
        // ceiling of the dimension. The opposite reading — unknown means harmless — turns every
        // undeclared operation into the next `plugins.lock`.
        //
        // The session bookkeeping is exempt, and not by exception: it arrives through `$extra`, never
        // through the catalogue this resolves against. `plan` and `todo` declare `mutating: true` and
        // it is true — they append — but their effect is confined to this session's log. Taking the
        // notebook away from a contained agent does not make it safer, it makes it illegible.
        $undeclared = 0;
        $catalogue = 0;
        foreach ($this->operationsMatchingEffects($input['denyEffects'] ?? null, $undeclared, $catalogue) as $tool) {
            $denied[] = $tool;
        }
        $denied = array_values(array_unique($denied));

        // AND IF THAT LEAVES NOTHING, SAY SO INSTEAD OF HANDING BACK A MUTE AGENT.
        //
        // Measured 2026-08-05, and it is the failure mode this flag ships with: on a catalogue where
        // nothing declared its effects every operation ceilings at `Unknown`, and `Unknown` is on the
        // deny side by construction — so «withdraw what mutates» withdraws EVERYTHING, reads
        // included. On a fixture running published packages that was 25 of 25.
        //
        // The withdrawal is NOT softened: unknown never reduces controls, and an `Unknown` waved
        // through is the next `plugins.lock`. What changes is that the cause gets named. An agent
        // that comes back empty because nobody classified the catalogue looks exactly like a broken
        // agent, and whoever typed the flag would go looking anywhere but at the real reason.
        if ($denied !== [] && $catalogue > 0 && \count($denied) >= $catalogue) {
            return [
                'ok' => false,
                'error' => "containing by effect class withdrew the whole catalogue ({$catalogue} operations)"
                    . ($undeclared > 0 ? ", {$undeclared} of them because they never declared their effects" : ''),
                'hint' => 'classify the catalogue, or name the tools with --deny instead',
            ];
        }

        $tableMode = $this->retiraOpciones();

        // AN EXPLICIT `deny` IS NOT ENABLED BY AN APP-LEVEL SETTING.
        //
        // `agent.removeRefusedOptions` governs whether the SYSTEM withdraws an option the gate has
        // refused — an automatism an app may not want, and which ships off. Whoever types `--deny`
        // has already decided, and making that decision depend on an unrelated flag would render it
        // silently inert: exactly the case this house names as worse than not having the mechanism.
        //
        // `record-only` may not degrade it either: it is a laboratory value that records the
        // withdrawal and keeps offering the tool. Applied to an explicit prohibition it would be a
        // lie with a receipt.
        $table = ($sessionId !== '' && $store !== null && ($tableMode !== false || $denied !== []))
            ? new SessionOptionTable($store, $sessionId)
            : null;
        if ($table !== null && $tableMode === 'record-only' && $denied === []) {
            $table = new RecordOnlyOptionTable($table);
        }

        if ($denied !== []) {
            if ($table === null || $store === null) {
                // REFUSE RATHER THAN PRETEND. Without a session there is nowhere to record the
                // withdrawal, so the prohibition would not survive the first step — and a `deny`
                // silently ignored is worse than none: whoever typed it walks away believing they
                // are contained.
                return [
                    'ok' => false,
                    'error' => 'a tool cannot be withdrawn without a session: the table lives in the session',
                    'hint' => 'add --session=<id> and run it again',
                ];
            }

            foreach ($denied as $tool) {
                $store->removeOption($sessionId, $tool, 'denied-by-operator', 'whoever ran this agent excluded it from the brief');
            }
            // AND IT IS TOLD, same as the child: the fact changes the world and the sentence tells it
            // why. A tool that vanishes without explanation leaves the agent spending steps hunting
            // for what is no longer there.
            $prompt .= "\n\nThese tools are not in your catalogue for this brief: "
                . implode(', ', $denied) . '. Do not look for them.';
        }

        // QUIÉN REGISTRA SE CAPTURA ANTES DE ENVOLVER, y esto es un arreglo, no un refinamiento.
        //
        // `McpClientService` deducía la grabadora del gate final (`$gate instanceof ToolCallRecorder`).
        // `SessionToolGate` implementa los dos papeles; `SecondOpinionGate` sólo juzga. Así que en
        // cuanto una app declaraba `agent.secondOpinion`, envolver la compuerta **apagaba el registro
        // de herramientas**: la sesión seguía apendando preguntas y turnos, y ni una sola
        // `session.tool_called`.
        //
        // Medido aquí el 2026-08-02 antes de correr nada: una corrida con el verificador puesto dio 8
        // pasos, trajo datos reales de tres herramientas de observación, y dejó CERO llamadas en el
        // stream. Como el stream es la evidencia con que se distingue «observó» de «contestó sin
        // mirar», ese cero se habría leído como el hallazgo — y es del instrumento.
        //
        // Registrar es papel del piso. Envolverlo para juzgar no se lo quita.
        $grabadora = $compuerta instanceof ToolCallRecorder ? $compuerta : null;

        $segundas = $this->segundaOpinion();
        if ($compuerta !== null && $segundas !== [] && class_exists(SecondOpinionGate::class)) {
            $juez = $this->llm();
            if ($juez !== null) {
                // CON UN LOGGER DE VERDAD. El gate promete que un juez caído «no calla», y sin esto
                // su warning iba a un NullLogger — o sea que callaba, y una corrida donde el juez no
                // pudo opinar se veía idéntica a una donde aprobó. En una terminal, stderr es donde
                // se ven las advertencias; en el laboratorio, es el `.err` de cada corrida.
                $compuerta = new SecondOpinionGate(
                    $compuerta,
                    $juez,
                    $prompt,
                    $segundas,
                    $this->alternativasObservables(),
                    logger: new StderrLogger(),
                    mesa: $table,
                );
            }
        }

        $veredictoCatalogo = $this->clasificarPeticion($prompt);
        $registry = $this->toolsOfThisApp($contabilidad, $veredictoCatalogo === 'reads');
        if ($registry === null) {
            return ['ok' => false, 'error' => 'esta app no expuso ninguna operación como herramienta'];
        }

        $vistos = 0;
        // EL VIGÍA MIRA EL TECLADO ENTRE PASOS, si la app registró uno. Sin él esto corre igual que
        // antes: una app sin terminal no tiene a quién preguntarle si quiere parar.
        $vigia = $this->container->has(StepWatcher::class) ? $this->container->get(StepWatcher::class) : null;
        $vigia = $vigia instanceof StepWatcher ? $vigia : null;

        try {
            $respuesta = $this->ask($prompt, $pasos, $registry, $proveedor, $llave, $modelo, function () use (&$vistos, $vigia): void {
                ++$vistos;
                $vigia?->paso($vistos);
            }, $historial, $compuerta, $table, $grabadora, $this->tableroDePlan($sessionId, $store));
        } catch (RunInterrupted $e) {
            // INTERRUMPIR NO ES FALLAR. El trabajo hecho hasta aquí ya está en el stream —cada llamada
            // se apenda al ocurrir— así que la sesión sigue viva y retomable. Decirlo como error
            // sugeriría que hay algo que arreglar, y lo que hay es una decisión del humano.
            if ($sessionId !== '' && $store !== null) {
                $store->recordTurn($sessionId, 'assistant', 'La vuelta se interrumpió.');
            }

            return [
                'ok' => true,
                'answer' => 'La vuelta se interrumpió.',
                'interrupted' => true,
                'steps' => $vistos,
                'tools' => \count($registry->getToolDefinitions()),
                // B1 (evidence/0091): the WRITE above requires a store and the report did not, so
                // an app holding a session id without `milpa/agent` reported a session nobody
                // wrote. A result that names what it did not do teaches its reader a false fact.
                'session' => ($sessionId !== '' && $store !== null) ? $sessionId : null,
                'hint' => 'dile qué cambió y pídele que siga',
            ];
        } catch (\Throwable $e) {
            // El motivo se devuelve tal cual: viene del proveedor —una llave inválida, un modelo que
            // no existe, la red— y quien lo lee necesita esa frase, no una reformulación.
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($sessionId !== '' && $store !== null) {
            $store->recordTurn($sessionId, 'assistant', $respuesta);
        }

        $resultado = [
            'ok' => true,
            'answer' => $respuesta,
            'steps' => $vistos,
            'tools' => \count($registry->getToolDefinitions()),
        ];

        if ($sessionId !== '') {
            $resultado['session'] = $sessionId;
        }

        // QUE LA VUELTA HAYA TERMINADO PAUSADA SE DICE, no se infiere.
        //
        // El texto de la pausa ES la pregunta, así que una superficie que además pinta el widget de la
        // pregunta la mostraba DOS VECES — una con la voz del agente y otra como pregunta. Y una
        // superficie no debería tener que adivinar por el contenido de una cadena en qué estado quedó
        // la sesión.
        //
        // El `hint` es de la CLI: dice cómo contestar donde no hay dónde teclear la respuesta. El TUI
        // lo ignora porque ahí sí lo hay. Antes esa línea viajaba dentro del texto y salía en las dos.
        $pausada = $sessionId !== '' && $store !== null ? $store->load($sessionId) : null;
        if ($pausada?->question !== null) {
            $resultado['paused'] = true;
            $resultado['hint'] = 'contesta con: coa agent:answer --session=' . $sessionId
                . ' --answer=<' . implode('|', $pausada->question->options ?: ['tu respuesta']) . '>';
        }

        // AGOTAR EL TECHO NO ES CONTESTAR. Se nombra para que la superficie no lo pinte como respuesta.
        if ($respuesta === AgentOrchestrator::STEPS_EXHAUSTED) {
            $resultado['exhausted'] = true;
            $resultado['answer'] = 'La vuelta se quedó sin pasos antes de terminar.';
            $resultado['hint'] = 'pídele que siga, o dale más pasos con `--steps`';
        }

        // Que se haya compactado se DICE. Es lo único de esta operación que cambia en silencio lo que
        // el modelo ve, y una sesión que empieza a contestar distinto sin que nadie sepa por qué es
        // la clase de cosa que se depura durante una hora.
        if (isset($compactado)) {
            $resultado['compacted'] = true;
        }

        // Y EL VEREDICTO DEL CLASIFICADOR TAMBIÉN — por la misma razón exacta que `compacted`: es la
        // otra cosa que cambia en silencio lo que el modelo ve. Sin este campo, un catálogo completo
        // no distingue «el clasificador leyó CHANGES» de «el clasificador no contestó», y esa
        // confusión ya estuvo a punto de fabricar el resultado de una medición (Q-P19-J).
        if ($veredictoCatalogo !== 'off') {
            $resultado['classifier'] = $veredictoCatalogo;
        }

        return $resultado;
    }

    /**
     * Una vuelta del bucle: pregunta al modelo, deja que llame herramientas, devuelve la respuesta.
     *
     * Es un método aparte —y protegido— porque es la ÚNICA parte que sale a la red. Todo lo demás de
     * esta operación —qué falta, qué herramientas hay, qué forma tiene el resultado, qué pasa si el
     * proveedor truena— se puede probar sin llave y sin red sustituyendo esto. La alternativa era
     * dejar la mitad del archivo sin medir y enterarse en producción.
     *
     * @param callable():void                            $onStep
     * @param list<array{role: string, content: string}> $history lo que ya se dijo en esta sesión —
     *                                                            vacío cuando no hay sesión, que es
     *                                                            como corría antes de P16.1
     */
    protected function ask(
        string $prompt,
        int $pasos,
        ToolRegistry $registry,
        string $proveedor,
        string $llave,
        string $modelo,
        callable $onStep,
        array $history = [],
        ?ToolCallGate $gate = null,
        ?OptionTable $mesa = null,
        ?ToolCallRecorder $recorder = null,
        ?PlanBoard $tablero = null,
    ): string {
        $modeloRemoto = new LlmService(
            $llave,
            $modelo,
            $proveedor,
            new NullLogger(),
            baseUrl: $this->baseUrl(),
            extraHeaders: $this->extraHeaders(),
        );
        $cliente = new McpClientService($registry, $gate, $recorder ?? ($gate instanceof ToolCallRecorder ? $gate : null), $mesa);

        // EL PARÁMETRO SÓLO SE PASA CUANDO HAY TABLERO, y no es estilo: es defensa.
        //
        // `planBoard` llegó en `milpa/ai-gateway` 0.8. Este archivo viaja con `composer
        // create-project` y **convive con el vendor que su dueño tenga**, no con el que su manifiesto
        // pide; contra un 0.7 instalado, `planBoard: null` truena con «Unknown named parameter» en
        // CADA vuelta del agente — no cuando hay plan, siempre.
        //
        // El `conflict: milpa/ai-gateway <0.8` declara la exigencia; no la garantiza, porque nadie
        // obliga a correr `composer update`. Ya pasó una vez con `Operation::$namedTarget`, y el modo
        // de falla es el peor: un desajuste de versiones que se ve como un sistema roto.
        $orquestador = $tablero === null
            ? new AgentOrchestrator($modeloRemoto, $cliente, $pasos, new NullLogger())
            : new AgentOrchestrator($modeloRemoto, $cliente, $pasos, new NullLogger(), null, $tablero);

        return $orquestador->run(
            $prompt,
            $this->systemPrompt(),
            $history,
            $onStep,
        );
    }

    /**
     * Dónde viven las sesiones de esta app.
     *
     * El almacén se pide al contenedor primero: una app que ya guarda eventos guarda las sesiones de
     * su agente en el MISMO log, y ahí es donde tiene que estar — un segundo almacén sería una segunda
     * verdad sobre lo que pasó. Si no hay ninguno registrado, se cae a un JSONL bajo `var/`, porque
     * una app recién creada tiene que poder tener memoria sin configurar nada.
     *
     * Devuelve `null` sólo si el paquete no está instalado: sin sesiones, `agent` sigue contestando
     * exactamente como antes. Perder la memoria es peor que no tenerla, pero no poder preguntar nada
     * es peor que las dos.
     */
    /**
     * Cuándo se compacta esta app, y con qué resumidor (P16.2).
     *
     * Los umbrales salen de `config/app.php` (`agent.compaction`) porque dependen del modelo: una
     * ventana de 8k y una de 200k no se compactan igual, y cablear un número aquí obligaría a la mitad
     * de las apps a editarlo. Los defaults —40 turnos sin resumir, 12 conservados— están pensados para
     * un modelo mediano; lo importante es que `keepRecent` sea holgado, porque el resumen contesta
     * «qué ha pasado» y sólo los turnos íntegros contestan «en qué íbamos».
     *
     * Es `protected` para poder fijarlo desde una prueba sin generar cuarenta turnos de verdad.
     */
    protected function compactor(): Compactor
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $ajustes = $config instanceof Config ? $config->get('agent.compaction') : null;

        $maximo = 40;
        $recientes = 12;
        if (\is_array($ajustes)) {
            $maximo = \is_int($ajustes['maxTurns'] ?? null) ? $ajustes['maxTurns'] : $maximo;
            $recientes = \is_int($ajustes['keepRecent'] ?? null) ? $ajustes['keepRecent'] : $recientes;
        }

        return new Compactor($maximo, $recientes);
    }

    /**
     * El almacén de sesiones de esta app, para quien lo necesite desde fuera.
     *
     * Existe para que {@see SessionOperations} lea y escriba EXACTAMENTE donde esta operación lo hace:
     * dos lugares que decidan dónde viven las sesiones son dos lugares donde pueden dejar de
     * coincidir, y el día que lo hicieran `agent:answer` contestaría en una sesión que `agent` no
     * está leyendo.
     */
    public function sessionStore(): ?SessionStore
    {
        return $this->sessions();
    }

    /**
     * The standing obligation this invocation runs under — declared, LIFTED, read back, or renewed.
     *
     * ── THE OBLIGATION OUTLIVES THE TURN ─────────────────────────────────────────────────────────
     *
     * `--first` used to govern only the invocation that carried it. A session paused for a
     * confirmation and resumed came back with the obligation gone — and Q-P17-L measured what it
     * buys: 21 plans and 14 cards moved with it, zero of both without, and zero work finished
     * without. Something worth that much cannot depend on whoever types the resume.
     *
     * So it is written to the session and read back from it. Passing `--first` again REPLACES it
     * —the standing obligation is the last one somebody with authority declared— and passing an
     * empty one LIFTS it, because the same authority that set it has to be able to unset it.
     *
     * ── THE SYSTEM RENEWS IT, so a long session does not depend on somebody retyping ─────────────
     *
     * Q-P17-L, four runs, one variable moved at a time:
     *
     *     plan obliged + renewed every turn   6/9 work · but a new plan each turn
     *     an innocuous obligation, renewed    1/9 work · turns without direction
     *     plan obliged once                   0/9 · the agent calls itself done
     *     nothing                             0/9
     *
     * Renewal buys TURNS; the plan buys what to do with them. So the system renews — but never with
     * `plan`, which is what produced six copies of the same card. And only when there is something
     * to resume: a session lacking a plan has nothing to renew, and one whose items are all done
     * is not being pushed — it is being nagged.
     *
     * ── WHAT IT RENEWS WITH — MEASURED TWICE, DECIDED TWICE, SAME DAY ────────────────────────────
     *
     * The sixth arm (sexto-brazo.tsv, 2026-08-06) compared renewing with the board tool against an
     * innocuous read, and its rematch (sexto-brazo-revancha.tsv, same day, sterile-loop guard on
     * BOTH arms, faithful template, equalised turns) gave the clean answer the first round could
     * not: pointing the renewal at the board buys a LIVE board — 80 card events against 22, the
     * MANTUVO moves — and COSTS termination: 2/9 against 6/9, with the innocuous arm delivering
     * 2 of 3 plugins in every single run. The mechanism was visible in the streams: a turn opened
     * by bookkeeping becomes a bookkeeping turn (~1 real call per resume); a turn opened by a cheap
     * read of the session's own state keeps its momentum. Position is a mechanism (Q-P20-E).
     *
     * Rod's second call, with the clean numbers (2026-08-06): the system renews with `agent_show`
     * — orientation, not curation. The board-pointed shape stays one config key away
     * (`agent.renewalTool`), and `false` disables system renewal entirely: that is the Q-P17-L 0/9
     * arm, so it must be something an operator declared, never a silent state.
     *
     * @param mixed        $requested the raw `first` input: `null` when absent; a string or array
     *                                when present — and PRESENT-BUT-EMPTY is the lift
     * @param SessionStore $store     where the obligation lives; `null` (or no session) leaves
     *                                nothing standing to write or read
     *
     * @return list<string> the tools that must run before anything else this turn
     */
    public function standingObligation(mixed $requested, string $sessionId, ?SessionStore $store): array
    {
        $declared = [];
        foreach (\is_string($requested) ? explode(',', $requested) : (\is_array($requested) ? $requested : []) as $tool) {
            if (\is_string($tool) && trim($tool) !== '') {
                $declared[] = trim($tool);
            }
        }

        if ($sessionId === '' || $store === null) {
            return $declared;
        }

        if ($declared !== []) {
            $store->requireFirst($sessionId, $declared);

            return $declared;
        }

        if ($requested !== null) {
            // AN EMPTY `--first` IS THE LIFT — appended as a fact, so the discipline ends for the
            // SESSION, not just for this turn. The docblock promised this and the code did not do
            // it (the sixth arm's side finding): the empty list fell through to the renewal below,
            // which re-armed what the caller had just tried to unset.
            $store->requireFirst($sessionId, []);

            return [];
        }

        $liveSession = $store->load($sessionId);
        $standing = $liveSession->runFirst ?? [];

        if ($standing === [] && $liveSession?->obligationDeclared === true) {
            $pending = array_filter(
                $liveSession->todos,
                static fn (Todo $t): bool => $t->status !== TodoStatus::Done,
            );
            if ($liveSession->plan !== null && $liveSession->plan !== '' && $pending !== []) {
                return $this->renewalTool();
            }
        }

        return $standing;
    }

    /**
     * What the system re-arms the standing obligation with — see {@see self::standingObligation()}
     * for the two measurements behind the default. `agent.renewalTool` names another single tool
     * (the board shape is one key away); `false` disables system renewal — the Q-P17-L 0/9 arm,
     * allowed only as something an operator declared.
     *
     * @return list<string> one tool, or nothing when an operator disabled renewal
     */
    private function renewalTool(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declared = $config instanceof Config ? $config->get('agent.renewalTool') : null;

        if ($declared === false) {
            return [];
        }
        if (\is_string($declared) && trim($declared) !== '') {
            return [trim($declared)];
        }

        return ['agent_show'];
    }

    /**
     * Cuánto tiempo tiene un humano para contestar antes de que la sesión se declare muerta.
     *
     * Se lee de `agent.permissionWindow` como una cadena de {@see \DateInterval} —`PT4H`, `P1D`— y
     * **sin default**: si el host no la declara, las preguntas esperan para siempre, que es lo que
     * hacían antes de que esto existiera.
     *
     * No poner un default es deliberado. Cuánto tiempo es razonable depende de quién opera el agente
     * —una jornada, un turno, un fin de semana— y un número inventado aquí mataría sesiones de gente
     * que nunca lo eligió. Lo que este código garantiza es que la ventana se PUEDA poner y que
     * vencerla sea un hecho declarado ({@see SessionStore::expireIfDue()}), no que exista una.
     */
    /**
     * The first arrow, if this host declares it (greenhouse evidence/0009).
     *
     * Read from `agent.transitions.foundation` as a list of tool names to hold closed until this
     * app adjudicates `founded` — and with NO default: an absent key is the open table, which is
     * the behaviour of every session before this existed. The knob belongs to the host because
     * WHICH tools founding precedes is a product decision, not a constant of this file. The gate
     * adjudicates durable state on every check: executing the rite opens nothing; producing the
     * state the rite was meant to demonstrate does.
     */
    private function foundationArrow(): ?TransitionGate
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declared = $config instanceof Config ? $config->get('agent.transitions.foundation') : null;
        if (!\is_array($declared)) {
            return null;
        }

        $held = array_values(array_filter(
            $declared,
            static fn ($t): bool => \is_string($t) && trim($t) !== '',
        ));

        return $held === [] ? null : TransitionGate::untilFounded($held);
    }

    private function permissionWindow(): ?\DateInterval
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declarada = $config instanceof Config ? $config->get('agent.permissionWindow') : null;
        if (!\is_string($declarada) || $declarada === '') {
            return null;
        }

        try {
            return new \DateInterval($declarada);
        } catch (\Throwable) {
            // Una ventana ilegible NO se convierte en una inventada: se ignora y las preguntas siguen
            // sin plazo. Adivinar aquí sería matar sesiones por un typo en un archivo de config.
            return null;
        }
    }

    /**
     * El plan de esta sesión, para que el bucle se lo vuelva a poner enfrente al agente en cada paso.
     *
     * `agent.reprojectPlan` en `config/app.php`. Detrás de una perilla por la misma razón que
     * `agent.removeRefusedOptions`: es la intervención que
     * [Q-P20-B](../../../../docs/library/pregunta-q-p20b.md) mide, y un experimento sin el brazo que
     * NO la tiene no se puede comparar contra nada.
     *
     * Apagada por default mientras la pregunta esté abierta: lo que se despacha es lo ya medido, no lo
     * que se está midiendo.
     *
     * `protected` por lo mismo que {@see ask()}: para que una prueba pueda mirar la decisión sin montar
     * un kernel entero. El control positivo de esta perilla es lo único que hace legible la tanda.
     *
     * Sin sesión devuelve `null` y no un tablero vacío — no hay plan que reproyectar cuando no hay
     * dónde guardarlo, y un encabezado sin tarjetas ocuparía contexto para decir nada.
     */
    protected function tableroDePlan(string $sessionId, ?SessionStore $store): ?PlanBoard
    {
        if ($sessionId === '' || $store === null) {
            return null;
        }

        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        if (($config instanceof Config ? $config->get('agent.reprojectPlan') : null) !== true) {
            return null;
        }

        // SIN LA INTERFAZ NO SE INSTANCIA EL ADAPTADOR. `SessionPlanBoard implements PlanBoard`, así
        // que contra un `milpa/ai-gateway` anterior a 0.8 cargarlo sería un fatal por interfaz
        // ausente. Misma razón que la guarda de arriba: el manifiesto pide, el vendor instalado manda.
        if (!interface_exists(PlanBoard::class)) {
            return null;
        }

        return new SessionPlanBoard($store, $sessionId);
    }

    /**
     * Si el prompt le pide al agente que escriba y mueva su plan.
     *
     * `agent.planInstruction`, y **encendida por default** — al revés que las otras perillas, porque
     * ésta no enciende una intervención nueva: apaga la que **ya se despacha**. El brazo A de Q-P20-B
     * es el único que la pone en `false`, para medir el piso sin palabras.
     *
     * Que exista esta perilla es lo que destapó la enmienda 4 de esa pregunta: el «control» del diseño
     * anterior ya traía la instrucción puesta, así que habría sido el mismo brazo dos veces.
     */
    /**
     * El fondo de pasos que el árbol comparte en esta vuelta (§5.4), o `null` para correr sin techo.
     *
     * El default es TRES VECES el techo del padre y no una constante suelta: el número que importa es
     * cuánto puede gastar delegando comparado con lo que gasta él, y esa proporción se lee. Un `12`
     * escrito aquí no diría nada el día que alguien corra con techo de 40.
     */
    private function presupuestoDelArbol(int $pasos): ?TreeBudget
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $valor = $config instanceof Config ? $config->get('agent.treeBudget') : null;

        if (\is_int($valor)) {
            // Un cero o un negativo declarado NO es «sin techo»: es «no delegues», y se respeta. Caer
            // a ilimitado ante un número raro convertiría un error de config en un gasto abierto.
            return new TreeBudget(max(0, $valor));
        }

        return $valor === false ? null : new TreeBudget($pasos * 3);
    }

    /**
     * Whether this app refuses to repeat a call that already failed twice identically (Q-P19-R) —
     * ON by default since 2026-08-06.
     *
     * It shipped opt-in while its question was open; the question closed with the churn-signal
     * measurement (churn-signal.tsv): at its home tolerance the guard would have refused 81 of the
     * sick run's 89 calls and ZERO of the healthy runs' — no false positives on anything recorded.
     * Its design is what makes the default safe: it never cuts a call that worked, changed
     * arguments start the count over, and one success clears the slate. Rod's call with those
     * numbers: on by default, opt-out with `agent.sterileLoopGuard: false`; an integer sets the
     * tolerance.
     */
    public function sterileLoopGuard(): ?SterileLoopGuard
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declared = $config instanceof Config ? $config->get('agent.sterileLoopGuard') : null;

        if ($declared === false || $declared === 0) {
            return null;
        }
        if (\is_int($declared) && $declared > 0) {
            return new SterileLoopGuard($declared);
        }

        return new SterileLoopGuard();
    }

    private function instruccionDePlan(): bool
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;

        return ($config instanceof Config ? $config->get('agent.planInstruction') : null) !== false;
    }

    /**
     * Si una negativa del segundo juicio además RETIRA la opción de la mesa.
     *
     * `agent.removeRefusedOptions` en `config/app.php`. Va detrás de una perilla por la misma razón que
     * `agent.conditionalCatalog`: es la intervención que Q-P19-H mide, y un experimento necesita poder
     * correr el brazo que NO la tiene. Sin la perilla, negar-y-quitar sería la única conducta posible y
     * el brazo de control dejaría de existir — no se puede medir contra nada.
     *
     * Apagada por default, y eso es deliberado: mientras la pregunta esté abierta, el comportamiento
     * que se despacha es el ya medido, no el que se está midiendo.
     */
    private function retiraOpciones(): bool|string
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $valor = $config instanceof Config ? $config->get('agent.removeRefusedOptions') : null;

        // `record-only` es un valor de LABORATORIO y se llama así para que nadie lo confunda con una
        // política: apenda que la opción se fue y la sigue ofreciendo, que es lo peor de las dos. Existe
        // porque el cierre de Q-P19-H no pudo atribuir su propio resultado — el brazo que corrió cambió
        // dos cosas a la vez, y éste separa «la vuelta siguió» de «la mesa cambió».
        return $valor === 'record-only' ? 'record-only' : $valor === true;
    }

    /**
     * Las herramientas que esta app quiere que un segundo lector revise.
     *
     * Lista y no booleano: preguntarle al modelo por cada lectura duplicaría las peticiones para
     * confirmar lo obvio, y ese costo se paga en cada corrida. Cuáles ameritan revisión es una
     * decisión de la app, no de este archivo.
     *
     * @return list<string>
     */
    private function segundaOpinion(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declarado = $config instanceof Config ? $config->get('agent.secondOpinion') : null;

        return \is_array($declarado)
            ? array_values(array_filter($declarado, static fn ($x): bool => \is_string($x)))
            : [];
    }

    /**
     * La herramienta que OBSERVA lo que cada una cambia.
     *
     * `agent.observableAlternatives` en `config/app.php`. La declara la app y no la adivina el modelo:
     * Q-P19-D midió que una negativa sin esto apaga al agente —0 de 32 corridas volvieron a llamar una
     * herramienta— y pedirle al verificador que la invente sería mover la adivinación de lugar.
     *
     * @return array<string, string>
     */
    private function alternativasObservables(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $mapa = $config instanceof Config ? $config->get('agent.observableAlternatives') : null;

        if (!\is_array($mapa)) {
            return [];
        }

        $limpio = [];
        foreach ($mapa as $muta => $observa) {
            if (\is_string($muta) && \is_string($observa)) {
                $limpio[$muta] = $observa;
            }
        }

        return $limpio;
    }

    /**
     * ¿Esta petición se contesta mirando, o pide cambiar algo?
     *
     * Un solo juicio, al empezar, sobre la petición — no uno por llamada sobre cada intento. Es más
     * barato y llega antes: en vez de negar N veces, no ofrece.
     *
     * Sin `agent.conditionalCatalog` no se pregunta nada y el catálogo es el de siempre. Y si el
     * juicio no se puede emitir, **también** se ofrece todo: recortar por no haber podido preguntar
     * sería censurar a ciegas, y una app sin red se quedaría sin agente.
     */
    /**
     * Qué dijo el clasificador de la petición — y CADA desenlace con su nombre, no un booleano.
     *
     * La primera forma devolvía `bool`, y con eso CUATRO situaciones distintas colapsaban en el mismo
     * `false` → catálogo completo: el veredicto CHANGES real, una excepción de red, una respuesta sin
     * veredicto, y una respuesta con las dos palabras. Ninguna dejaba huella — el catch era mudo.
     *
     * Lo encontró la revisión adversaria del pre-registro de Q-P19-J, donde la divergencia de lecturas
     * ES el mensurando: un hipo del endpoint habría fabricado un «CHANGES» indistinguible del real, y
     * la tanda habría concluido «la ambigüedad se sortea» sobre un fallo de infraestructura. Es la
     * sexta vez que este programa está a punto de medir su propio instrumento.
     *
     * Los valores: `off` (la app no pidió catálogo condicionado) · `no-judge` (sin credencial) ·
     * `reads` · `changes` · `no-verdict` (contestó sin la palabra) · `unreachable` (excepción). Sólo
     * `reads` recorta el catálogo; todos los demás lo dejan completo, que es el comportamiento de
     * siempre — lo que cambia es que ahora cada uno se llama por su nombre.
     */
    private function clasificarPeticion(string $prompt): string
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        if (($config instanceof Config ? $config->get('agent.conditionalCatalog') : null) !== true) {
            return 'off';
        }

        $juez = $this->llm();
        if ($juez === null) {
            return 'no-judge';
        }

        // LA PREGUNTA SE HACE POR LA INTENCIÓN, no por la mecánica. Su primera forma —«¿llevar esto a
        // cabo requiere cambiar algo?»— contestó CHANGES ante «¿qué deja de funcionar si deshabilito
        // X?», porque la petición NOMBRA una acción aunque sea hipotética. Preguntando qué quiere la
        // persona —saber algo, o que el sistema quede distinto— acierta en los dos casos.
        $pregunta = <<<TXT
            Read this request and decide ONE thing.

            «{$prompt}»

            Does the person want the state of the system to be DIFFERENT after this, or do they want to
            KNOW something?

            - They want to know something  → answer READS
            - They want the system changed → answer CHANGES

            A hypothetical («what would happen if…») is a request to KNOW. Your whole answer is one word.
            TXT;

        try {
            $r = $juez->generateResponse($pregunta, maxTokens: 20);
        } catch (\Throwable $e) {
            // El fallo SE DICE — un clasificador caído que calla se ve idéntico a uno que contestó
            // CHANGES, y esa confusión ya estuvo a punto de arruinar una medición.
            (new StderrLogger())->warning('el clasificador no pudo opinar; el catálogo va completo', [
                'error' => $e->getMessage(),
            ]);

            return 'unreachable';
        }

        $texto = \is_string($r['content'] ?? null) ? $r['content'] : '';

        // Se busca la palabra EXPLÍCITA. Un párrafo sin veredicto no recorta nada: tratar un silencio
        // como «sólo lee» quitaría herramientas por una opinión que nadie emitió. Y una respuesta con
        // LAS DOS palabras tampoco es un veredicto — es el modelo dudando en voz alta.
        $dijoReads = preg_match('/\bREADS\b/i', $texto) === 1;
        $dijoChanges = preg_match('/\bCHANGES\b/i', $texto) === 1;

        return match (true) {
            $dijoReads && !$dijoChanges => 'reads',
            $dijoChanges && !$dijoReads => 'changes',
            default => 'no-verdict',
        };
    }

    /** El mismo modelo que corre al agente, para el que lo juzga. */
    // `protected` por lo mismo que `ask()`: es una costura que sale a la red, y una prueba tiene que
    // poder sustituirla sin red y sin llave. Antes era `private` y el clasificador quedó sin prueba
    // unitaria — sus controles vivían sólo en el laboratorio.
    protected function llm(): ?LlmService
    {
        $credencial = $this->credential();
        if ($credencial === null || !class_exists(LlmService::class)) {
            return null;
        }

        [$proveedor, $llave, $modelo] = $credencial;

        return new LlmService(
            $llave,
            $modelo,
            $proveedor,
            new NullLogger(),
            baseUrl: $this->baseUrl(),
            extraHeaders: $this->extraHeaders(),
        );
    }

    protected function sessions(): ?SessionStore
    {
        if (!class_exists(SessionStore::class)) {
            return null;
        }

        // CUANDO HAY SUPERFICIE, EL ALMACÉN LO CONSTRUIMOS NOSOTROS.
        //
        // `DIContainer::has()` contesta que sí tanto a lo que alguien declaró como a lo que el
        // contenedor puede FABRICAR, y `SessionStore` es fabricable en cuanto haya un
        // `EventStoreInterface` registrado. Preguntando primero por él, el contenedor devolvería una
        // sesión armada por su cuenta —sin puente—, el agente seguiría escribiendo y el tablero se
        // quedaría quieto sin que nada fallara. Lo encontró la prueba de cableado, no el diseño.
        $superficie = $this->broadcaster();

        if ($superficie !== null && $this->container->has(EventStoreInterface::class)) {
            $eventos = $this->container->get(EventStoreInterface::class);
            if ($eventos instanceof EventStoreInterface) {
                return new SessionStore(new BroadcastingEventStore($eventos, $superficie));
            }
        }

        if ($this->container->has(SessionStore::class)) {
            $declarado = $this->container->get(SessionStore::class);
            if ($declarado instanceof SessionStore) {
                return $declarado;
            }
        }

        if ($this->container->has(EventStoreInterface::class)) {
            $eventos = $this->container->get(EventStoreInterface::class);
            if ($eventos instanceof EventStoreInterface) {
                return new SessionStore($eventos);
            }
        }

        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return null;
        }

        $directorio = $kernel->root() . '/var';
        if (!is_dir($directorio) && !mkdir($directorio, 0o775, true) && !is_dir($directorio)) {
            return null;
        }

        return new SessionStore($this->conPuente(new FileEventStore($directorio . '/agent-sessions.jsonl')));
    }

    /**
     * El mismo almacén, empujando a la superficie — si hay alguien a quien empujarle.
     *
     * ── POR QUÉ AQUÍ Y NO EN UN «BOOT» APARTE ───────────────────────────────────────────────────
     *
     * Porque éste es el ÚNICO lugar donde se decide qué almacén usa una sesión. Un puente montado en
     * otra parte tendría que adivinar cuál de los tres caminos de arriba se tomó, y el día que no
     * coincidiera dejaría de empujar sin que nada fallara: el agente seguiría escribiendo, el tablero
     * se quedaría quieto, y nadie tendría por qué sospechar. Es exactamente la forma del defecto que
     * este repositorio lleva un mes cazando —lo declarado que nunca aterriza—, y aterrizarlo aquí es
     * lo que impide que se repita.
     *
     * ── DOS FORMAS DE PRENDERLO, Y NINGUNA OBLIGA A INSTALAR NADA ───────────────────────────────
     *
     * La app puede registrar su propio {@see SurfaceBroadcaster} —cualquier transporte— o, si ya
     * tiene un hub Mercure en el contenedor, se envuelve solo. Sin ninguno de los dos, esto devuelve
     * el almacén intacto: una app sin tablero no paga nada, ni siquiera una dependencia.
     */
    private function conPuente(EventStoreInterface $eventos): EventStoreInterface
    {
        $superficie = $this->broadcaster();

        return $superficie === null ? $eventos : new BroadcastingEventStore($eventos, $superficie);
    }

    /** A quién se le empuja, si hay alguien. */
    private function broadcaster(): ?SurfaceBroadcaster
    {
        if (!class_exists(BroadcastingEventStore::class)) {
            return null;
        }

        if ($this->container->has(SurfaceBroadcaster::class)) {
            $declarado = $this->container->get(SurfaceBroadcaster::class);
            if ($declarado instanceof SurfaceBroadcaster) {
                return $declarado;
            }
        }

        // Sin puerto declarado, sirve el hub que ya esté configurado. Se pregunta por el NOMBRE y no
        // por la clase para no arrastrar `milpa/mercure` como dependencia de este proyecto.
        $hub = $this->container->has('Milpa\\Mercure\\MercureService')
            ? $this->container->get('Milpa\\Mercure\\MercureService')
            : null;

        return \is_object($hub) && method_exists($hub, 'publish') ? new MercureBroadcaster($hub) : null;
    }

    /**
     * Lo que el agente sabe de esta app antes de que nadie le pregunte nada.
     *
     * ── POR QUÉ NO ES UNA LÍNEA ─────────────────────────────────────────────────────────────────
     *
     * Era una: «usa las herramientas y no inventes resultados». Suena suficiente y no lo es, porque
     * las herramientas dicen QUÉ se puede hacer y no CÓMO está armada la app. Medido: a «quiero
     * guardar en sqlite en vez de json, dime qué cambio» el agente contestó que creara un
     * `SQLitePersistencePlugin` con `make plugin`. Inventó un plugin que no existe para resolver algo
     * que es una línea de `config/app.php` — y no estaba desobedeciendo, estaba llenando un hueco.
     * Un agente sin contexto no se calla: adivina.
     *
     * ── LAS DOS COSTURAS ────────────────────────────────────────────────────────────────────────
     *
     * `getPromptSections()` es la de siempre: vive en `ToolProviderInterface`, los stubs que este
     * framework genera ya traen su marcador `// {coa:tool-prompts}`, `PluginsManager` sabe juntarlas
     * — y NADIE las leía. Un plugin que contribuye herramientas también contribuye lo que hay que
     * saber para usarlas, y ese texto se estaba tirando. Es el quinto valor producido y descartado
     * que aparece esta semana, después de `merge`, `guidance`, el sabor del verify y el inventario.
     *
     * `agent.instructions` en `config/app.php` es la otra: lo que esta app en particular quiere que
     * su agente sepa y que no le toca a ningún plugin decir.
     *
     * Es `protected` por la misma razón que {@see ask()}: para poder mirarlo desde una prueba sin
     * salir a la red. Un prompt de sistema que nadie puede leer se convierte en el lugar donde se
     * acumulan afirmaciones que ya no son ciertas.
     */
    protected function systemPrompt(): string
    {
        $partes = [
            'Eres el agente de esta app Milpa. Usa las herramientas para responder; no inventes '
            . 'resultados. Si una herramienta contesta con `guidance`, esa guía es el siguiente paso '
            . 'real: repítela en vez de improvisar uno.',

            // Lo que un agente necesita para no inventar un plugin donde había una línea de config.
            "Cómo está armada esta app:\n"
            . "- Cada cosa que sabe hacer es una operación declarada; las herramientas que ves SON esas operaciones.\n"
            . "- Los plugins se declaran en `config/plugins.php`. Andamiar uno NO lo enciende: hay que agregar su clase a esa lista.\n"
            . "- La persistencia sale de `config/app.php`, bloque `storage`: `driver` es `file`, `sqlite`, `mysql` o `memory`\n"
            . "  (con su `path` o su `dsn`). Lo que `make entity` y `make crud` escriben ya lee ese bloque a través de\n"
            . "  `Milpa\\Data\\RepositoryFactory`, así que cambiar de backend es esa línea y nada más. NO hace falta un plugin\n"
            . "  de persistencia, y no existe uno.\n"
            . "- Doctrine es de la convención legacy, no de ésta. Las entidades que `make` escribe implementan\n"
            . '  `Milpa\\Data\\EntityInterface`: sin atributos de ORM, sin mapeo.',

        ];

        // LA INSTRUCCIÓN DEL PLAN, QUE AHORA SE PUEDE APAGAR.
        //
        // Sin esto, las herramientas existen y no se usan. Un modelo que puede anotar su plan y no sabe
        // que le conviene, no lo anota — y el plan es lo único que sobrevive a una compactación para
        // decirle qué sigue.
        //
        // Va detrás de `agent.planInstruction` porque el brazo A de Q-P20-B mide el piso sin ella. Y
        // hay una ironía que vale la pena dejar escrita donde vive: el tercer renglón le pide seguir un
        // plan que **el bucle nunca le enseña** — `AgentOrchestrator` no conocía `Todo` hasta que
        // apareció {@see \Milpa\AiGateway\PlanBoard}. Ésa es la pregunta entera en una frase.
        if ($this->instruccionDePlan()) {
            $partes[] = "Cuando el trabajo lleve más de dos o tres pasos:\n"
                . "- Escribe un plan con `plan` ANTES de empezar, y agrega un pendiente con `todo` por cada parte.\n"
                . "- Marca `todo` con status `done` EN CUANTO termines cada una, no al final.\n"
                . '- Si una sesión ya trae plan y pendientes, sigue ésos en vez de escribir otros: son tuyos, de antes.';
        }

        // LO QUE ESTA APP TRAE PUESTO, dicho por los paquetes mismos.
        //
        // Es la mitad del contrato de capacidades que no son operaciones: un paquete no sólo agrega
        // herramientas, agrega CONTEXTO. Sin esto, el agente tendría que deducir de la lista de
        // herramientas qué clase de app opera —si persiste, si sabe quién llama, si puede pausarse— y
        // deducir es una decisión más, que es lo que llevamos cuatro tandas midiendo que cuesta.
        //
        // Y va aquí y no en una constante porque una app tiny y una completa son apps distintas: un
        // prompt que hablara de tokens en una app sin identidad estaría describiendo otra.
        $puesto = Capabilities::briefing();
        if ($puesto !== []) {
            $partes[] = "Lo que esta app trae puesto:\n- " . implode("\n- ", $puesto);
        }

        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;

        // El resumen de arquitectura, APAGADO por default (`agent.architectureSummary`).
        //
        // Apagado no por timidez: su efecto está SIN MEDIR — Q-P17-E pregunta cuánta arquitectura
        // derivada hay que entregar sin que la pidan, y encenderlo por default antes de contestar
        // sería el verde sin medición que este repositorio lleva semanas negándose a escribir. La
        // bandera existe para que el experimento tenga sus dos condiciones sobre el MISMO binario;
        // si la medición lo sostiene, el default cambia y la bandera deja de hacer falta.
        // `true` da el resumen entero; `'pointer'` da SÓLO la línea que nombra la herramienta, en la
        // MISMA ranura del prompt. Lo segundo existe para medir de dónde viene el efecto que Q-P17-G
        // observó: si el puntero solo basta, el resumen son 68 tokens que no compran nada. Misma
        // ranura y mismo texto a propósito — en otra posición, una diferencia podría deberse a dónde
        // está y no a qué dice.
        $modo = $config instanceof Config ? $config->get('agent.architectureSummary') : null;
        if ($modo === true || $modo === 'pointer' || $modo === 'state') {
            $proyector = new ArchitectureSummaryProjector();
            $resumen = match ($modo) {
                'pointer' => $proyector->pointerOnly(),
                'state' => $proyector->stateOnly($this->architectureReport(), $this->providedCapabilities()),
                default => $proyector->section($this->architectureReport(), $this->providedCapabilities()),
            };
            if ($resumen !== '') {
                $partes[] = $resumen;
            }
        }

        $propias = $config instanceof Config ? $config->get('agent.instructions') : null;
        if (\is_string($propias) && trim($propias) !== '') {
            $partes[] = trim($propias);
        }

        // El filtro vive AQUÍ y no sólo en quien recolecta: las partes se unen con una línea en
        // blanco, así que una sección vacía se vuelve un hueco donde el modelo espera contenido.
        // `PluginsManager` intercala cadenas vacías como separador entre plugins, y cualquier host que
        // arme su propia lista puede hacer lo mismo — el ensamblador tiene que aguantarlo.
        foreach ($this->promptSectionsOfPlugins() as $seccion) {
            if (trim($seccion) !== '') {
                $partes[] = trim($seccion);
            }
        }

        return implode("\n\n", $partes);
    }

    /**
     * Las capacidades que los plugins arrancados DECLARAN proveer.
     *
     * Aparte del reporte porque el reporte no las tiene: la resolución la mueven los requisitos, así
     * que una capacidad que nadie pide no aparece ni en `resolved` ni en `missing`. Sin esta lista, el
     * punto ciego que Q-P17-C midió —provista por dos, consumida por nadie— seguiría invisible
     * también aquí.
     *
     * @return list<string>
     */
    protected function providedCapabilities(): array
    {
        $ids = [];
        foreach ($this->pluginMetadata() as $meta) {
            foreach ($meta->provides as $entrada) {
                $id = \is_string($entrada) ? $entrada : ($entrada['id'] ?? null);
                if (\is_string($id) && $id !== '' && !\in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * El `#[PluginMetadata]` de cada plugin ARRANCADO.
     *
     * @return list<PluginMetadata>
     */
    protected function pluginMetadata(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $metas = [];
        foreach ($kernel->plugins() as $plugin) {
            $atributos = (new \ReflectionClass($plugin))->getAttributes(PluginMetadata::class);
            if ($atributos !== []) {
                $metas[] = $atributos[0]->newInstance();
            }
        }

        return $metas;
    }

    /**
     * El grafo de esta app como reporte, o `null` si no se puede saber.
     *
     * Se diagnostica desde los plugins ARRANCADOS —igual que {@see promptSectionsOfPlugins()}— y no
     * desde `config/plugins.php`: lo que importa es lo que botó, porque un plugin declarado y vetado
     * no aporta capacidades y su línea hablaría de un grafo que no existe.
     *
     * Nunca lanza. Un prompt es lo último que puede tumbar una sesión: si el grafo no se puede
     * diagnosticar, el agente trabaja sin resumen igual que antes de que esto existiera, y sigue
     * teniendo `plugins_architecture` a una llamada. Devolver `null` es decir «no sé», y el proyector
     * ya sabe que eso se calla en vez de rellenar.
     */
    protected function architectureReport(): ?ResolutionReport
    {
        try {
            $registros = [];
            foreach ($this->pluginMetadata() as $meta) {
                $registros[] = [
                    'name' => $meta->name,
                    'version' => $meta->version,
                    'type' => $meta->type,
                    'provides' => array_values($meta->provides),
                    'requires' => array_values($meta->requires),
                    'suggests' => array_values($meta->suggests),
                ];
            }

            return $registros === [] ? null : (new MetadataGraphResolver())->diagnose($registros);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lo que cada plugin arrancado quiere que el agente sepa sobre sus herramientas.
     *
     * Se camina el kernel y no `PluginsManager` porque lo que importa es lo que BOOTEÓ: un plugin
     * declarado y vetado no tiene herramientas en el registro, así que su sección hablaría de algo que
     * el agente no puede llamar.
     *
     * @return list<string>
     */
    protected function promptSectionsOfPlugins(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $secciones = [];
        foreach ($kernel->plugins() as $plugin) {
            if (!$plugin instanceof ToolProviderInterface) {
                continue;
            }

            foreach ($plugin->getPromptSections() as $seccion) {
                // Las vacías se tiran: `PluginsManager` intercala cadenas vacías como separador entre
                // plugins, y un separador que llegue al prompt como sección deja un hueco donde el
                // modelo espera contenido.
                if (trim($seccion) !== '') {
                    $secciones[] = trim($seccion);
                }
            }
        }

        return $secciones;
    }

    /**
     * A dónde va el agente: el proveedor público, o el endpoint que esta app declare.
     *
     * `MILPA_AGENT_BASE_URL` apunta a cualquier cosa compatible con la API de OpenAI — un Ollama en
     * la LAN, un vLLM, un proxy. Existe por dos razones que se parecen poco: probar el bucle sin
     * gastarle tokens a un proveedor público, y correrlo con datos que no pueden salir de la casa.
     */
    private function baseUrl(): ?string
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declarado = $config instanceof Config ? $config->get('agent.baseUrl') : null;
        if (\is_string($declarado) && $declarado !== '') {
            return $declarado;
        }

        $entorno = getenv('MILPA_AGENT_BASE_URL');

        return \is_string($entorno) && $entorno !== '' ? $entorno : null;
    }

    /**
     * Encabezados extra para el endpoint — auth básica, si la hay.
     *
     * `MILPA_AGENT_BASIC_AUTH` en la forma `usuario:contraseña`. Un endpoint local detrás de auth
     * básica no acepta el Bearer del proveedor, y sin esto la única salida era no usarlo.
     *
     * @return array<string,string>
     */
    private function extraHeaders(): array
    {
        $basica = getenv('MILPA_AGENT_BASIC_AUTH');
        if (!\is_string($basica) || !str_contains($basica, ':')) {
            return [];
        }

        return ['Authorization' => 'Basic ' . base64_encode($basica)];
    }

    /**
     * La llave, el proveedor y el modelo — de las variables de entorno, o `null` si no hay ninguna.
     *
     * Anthropic primero porque es el default de la casa. El modelo se puede fijar en `config/app.php`
     * (`agent.model`) y si no se usa el de cada proveedor.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function credential(): ?array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $modeloDeclarado = $config instanceof Config ? $config->get('agent.model') : null;

        // Un endpoint propio manda: quien apuntó su agente a un modelo local no quiere que una
        // ANTHROPIC_API_KEY olvidada en el entorno lo mande a otro lado —y a cobrar.
        if ($this->baseUrl() !== null) {
            $llaveLocal = getenv('MILPA_AGENT_API_KEY');

            return [
                'openai',
                \is_string($llaveLocal) && $llaveLocal !== '' ? $llaveLocal : 'local',
                \is_string($modeloDeclarado) ? $modeloDeclarado : (getenv('MILPA_AGENT_MODEL') ?: 'qwen3-coder:30b'),
            ];
        }

        $anthropic = getenv('ANTHROPIC_API_KEY');
        if (\is_string($anthropic) && $anthropic !== '') {
            return ['anthropic', $anthropic, \is_string($modeloDeclarado) ? $modeloDeclarado : 'claude-sonnet-4-5'];
        }

        $openai = getenv('OPENAI_API_KEY');
        if (\is_string($openai) && $openai !== '') {
            return ['openai', $openai, \is_string($modeloDeclarado) ? $modeloDeclarado : 'gpt-4o'];
        }

        return null;
    }

    /**
     * Las operaciones de esta app, proyectadas como herramientas — las MISMAS que ve un cliente MCP.
     *
     * Se proyecta contra un registry nuevo y no contra el del kernel: el del kernel puede traer
     * herramientas que un plugin registró con `#[Tool]`, y las queremos también, pero armar el
     * catálogo aquí es lo que garantiza que el agente vea lo mismo que `bin/mcp-server.php` expone.
     */
    /**
     * The tool names of every live operation whose declared effects fall in one of the given classes.
     *
     * Resolved from the booted catalogue, never from a maintained list: what this app can do today is
     * a fact of the app, and reading it anywhere else is how a second inventory starts.
     *
     * @param mixed $classes    comma-separated string or list: mutating|external|irreversible|authority
     * @param int   $undeclared out: how many matched only because nobody classified them
     * @param int   $catalogue  out: how many live operations were considered at all
     *
     * @return list<string>
     */
    private function operationsMatchingEffects(mixed $classes, int &$undeclared = 0, int &$catalogue = 0): array
    {
        $wanted = [];
        foreach (\is_string($classes) ? explode(',', $classes) : (\is_array($classes) ? $classes : []) as $c) {
            if (\is_string($c) && trim($c) !== '') {
                $wanted[] = strtolower(trim($c));
            }
        }
        if ($wanted === []) {
            return [];
        }

        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $names = [];
        foreach (Operations::all($kernel, $kernel->root()) as $op) {
            ++$catalogue;
            $e = $op->effectCeiling();
            $sinClasificar = !$e->isFullyClassified();

            // Unknown is on the deny side of every one of these, deliberately. See the call site.
            $matches = false;
            foreach ($wanted as $class) {
                $matches = $matches || match ($class) {
                    // `mutating` reads the declared bool TOO, and not only the profile: eight
                    // consumers across four packages already set it, and an operation that says it
                    // mutates is denied whether or not anybody got around to classifying it.
                    'mutating' => $op->mutating || $e->mutation !== Mutation::None,
                    'external' => $e->externality !== Externality::None,
                    'irreversible' => $e->reversibility !== Reversibility::Guaranteed
                        && $e->reversibility !== Reversibility::Compensatable,
                    'authority' => $e->authority !== Authority::None && $e->authority !== Authority::Read,
                    default => false,
                };
            }

            if ($matches) {
                // THE NAME COMES FROM THE PROJECTOR, NEVER FROM A COPY OF ITS RULE (greenhouse
                // evidence/0141).
                //
                // `str_replace([':', '.'], '_', ...)` lived here — a second implementation of the tool
                // naming convention. It disagreed with {@see McpProjector::toolName()} on three inputs
                // nobody forbids: a space, a non-ASCII character, and a name past 64 characters, where
                // the projector truncates and the copy did not.
                //
                // And this is the WITHDRAWAL list: whatever leaves here is taken away from the agent's
                // catalogue. A name that does not match withdraws nothing, so the divergence would not
                // have opened the gate — it would have left the tool this filter exists to remove
                // sitting in the catalogue, with no signal at all. Measured inert across all 34
                // operations of a current app, which means it did not bite by luck, not by design.
                $names[] = McpProjector::toolName($op->name);
                if ($sinClasificar && !$op->mutating) {
                    ++$undeclared;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<Operation> $extra operations that exist only for this run — today, the ones tying
     *                               the plan and the pending items to the session in flight
     */
    private function toolsOfThisApp(array $extra = [], bool $soloLectura = false, bool $registroPropio = false): ?ToolRegistry
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return null;
        }

        // UN REGISTRO PROPIO PARA QUIEN TIENE OTRA SESIÓN — y no es una optimización, es corregir que
        // el hijo escribía en el cuaderno del padre.
        //
        // El registro del kernel es UNO para el proceso, y unas líneas más abajo se descarta lo que ya
        // esté registrado por NOMBRE. `plan` y `todo` no son herramientas cualesquiera: son cierres
        // atados a una sesión concreta. El padre registraba las suyas primero, el `plan` del hijo se
        // descartaba por duplicado, y el hijo terminaba llamando al del padre: su plan y sus
        // pendientes aterrizaban en el stream del padre.
        //
        // Se descubrió el 2026-08-04 midiendo Q-P20-I, y su daño no fue el estado: fue la MEDICIÓN.
        // «El hijo no planeó 0/8» era el `plan_set` archivado en el otro stream — el cierre de
        // Q-P20-G se construyó sobre eso y quedó corregido. `SessionBookkeeping($store, $hijoId)`
        // era un lector muerto: se construía con el id correcto y nadie lo llamaba.
        $registry = $registroPropio ? new ToolRegistry(new NullLogger()) : $kernel->toolRegistry();
        if (!$registry instanceof ToolRegistry) {
            $registry = new ToolRegistry(new NullLogger());
        }

        // TODO lo que esta app declara, no sólo lo que los plugins arrancaron: `kernel->commands()`
        // trae las operaciones de los plugins booteados y deja fuera las de `config/operations.php`.
        // Con eso, el agente veía OCHO herramientas mientras `coa` ofrecía doce — `make` y `validate`
        // entre las que faltaban, que son justo las que sirven para construir algo. Dos inventarios
        // de la misma app es exactamente lo que `Operations` existe para evitar, y aquí no se estaba
        // usando.
        $todas = [...Operations::all($kernel, $kernel->root()), ...$extra];

        // ── EL CATÁLOGO CONDICIONADO POR LA TAREA ───────────────────────────────────────────────
        //
        // Si `$soloLectura` es verdadero —lo decidió UN juicio, al empezar, sobre la petición— las
        // herramientas que mutan no entran. No se niegan después: no se ofrecen.
        //
        // Es la cuarta intervención de esta serie y la primera que cambia el sistema en vez de
        // hablarle al agente. Las tres anteriores están medidas y fallaron: explicarle (4 tandas sin
        // moverse), juzgarlo (Q-P19-D: cero destructivo apagando al agente) y sugerirle (Q-P19-E:
        // idéntico). El brazo sin freno enseña por qué se prueba esto: 36 llamadas a `plugins_disable`
        // en 32 corridas. **Una herramienta ofrecida es una herramienta que se va a llamar.**
        //
        // Q-P19-F está pre-registrada para medirlo y puede refutarse: si tampoco observa, la serie
        // termina apuntando al modelo y no al diseño.
        //
        // LA CONTABILIDAD NO SE FILTRA. `plan` y `todo` declaran `mutating: true` —y es verdad,
        // apendan— pero su efecto es exclusivamente sobre la bitácora de esta sesión: no tocan un
        // archivo, una base ni un plugin. Es la misma doctrina con que `SessionToolGate` las exime de
        // la compuerta: quitarle el cuaderno a un agente en modo lectura no lo vuelve más seguro, lo
        // vuelve ilegible. Y era además una variable oculta: dos mesas que difieren en lo destructivo
        // Y en la contabilidad no permiten atribuir una diferencia de conducta a lo primero — lo
        // encontró la revisión adversaria de Q-P19-J.
        if ($soloLectura) {
            $sinMutantes = array_values(array_filter(
                Operations::all($kernel, $kernel->root()),
                static fn ($op): bool => !$op->mutating,
            ));
            $todas = [...$sinMutantes, ...$extra];
        }

        // ── THE ACTIONABLE FRONTIER (greenhouse decisions/0006) — behind its OWN knob ───────────
        //
        // What a declared transition holds is not OFFERED while the transition is unearned — the
        // same judge that refuses decides what is on the table, so law and presentation cannot
        // disagree. MEASURED AND REFUTED AS A DEFAULT (rental v4, evidence/0012): removing the
        // held tools while leaving the rest of the catalogue silenced the teaching the refusal
        // carried — 0/3 founded versus the arrow's 2/3, and the tenants went SHOPPING for the
        // missing tools (capabilities read 9/4/7 times) instead of founding. Removal without
        // teaching loses to refusal with teaching. So the law (refusal) is what
        // `agent.transitions.foundation` enables; this presentation experiment only runs where a
        // lab declares `agent.transitions.frontier` — until the fuller frontier earns its acta.
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $flecha = $this->foundationArrow();
        if ($flecha !== null && $config instanceof Config && $config->get('agent.transitions.frontier') === true) {
            $todas = array_values(array_filter(
                $todas,
                static fn ($op): bool => $flecha->offers(McpProjector::toolName($op->name)),
            ));
        }

        // LAS OPERACIONES QUE ADJUDICAN SESIONES NO SON HERRAMIENTAS DEL ADJUDICADO.
        //
        // `agent:answer` contesta la pregunta que pausó una sesión; `agent:mode` cambia cuánta
        // autonomía tiene. Las dos existen para que un HUMANO gobierne al agente — y estaban en el
        // catálogo del propio agente, con el mensaje de pausa entregándole la sintaxis exacta. Un
        // agente que puede contestarse «sí» a sí mismo o subirse solo a `auto` no está gobernado:
        // está narrando gobernanza. Lo encontró la revisión adversaria de Q-P19-M, y habría vuelto
        // teatro TODO el contrato de intención de ADR-0044.
        //
        // Se filtra aquí —después de TODAS las ramas que arman la lista, un solo sitio— y no por
        // `surfaces`, porque un cliente MCP con un humano detrás contesta legítimamente por esa
        // superficie: la regla no es «answer no sale por MCP», es «answer no es una herramienta de
        // la sesión que espera la respuesta».
        // `agent:discard` entra a esta lista el día que nace, y no después: cierra una sesión, y un
        // padre que pudiera cerrar a su hijo pausado haría desaparecer la pregunta que el humano tenía
        // que ver. No otorga nada — vuelve invisible lo que existía para ser visto.
        $adjudican = ['agent:answer', 'agent:mode', 'agent:discard'];
        $todas = array_values(array_filter(
            $todas,
            static fn ($op): bool => !\in_array($op->name, $adjudican, true),
        ));

        // Sólo lo que TODAVÍA no está. Proyectar dos veces sobre el mismo registro lanza
        // `ToolAlreadyRegisteredException`, y eso convertía la segunda llamada al agente —en el mismo
        // proceso— en una excepción. Lo encontró una prueba que llama dos veces; en producción lo
        // habría encontrado quien le preguntara dos cosas seguidas.
        $faltantes = array_values(array_filter(
            $todas,
            static fn ($op): bool => $registry->getDefinition(McpProjector::toolName($op->name)) === null,
        ));

        if ($faltantes !== []) {
            (new McpProjector())->projectAll($faltantes, $registry, $kernel->container());
        }

        return $registry;
    }
}
