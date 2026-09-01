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

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\Agent\ProgressReceipt;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\ProgressProbe;
use Milpa\AiGateway\RunInterrupted;
use Milpa\Agent\Principal;
use Milpa\AppRuntime\Agent\AffirmativeAnswer;
use Milpa\AppRuntime\Support\ContratoInstalado;
use Milpa\AppRuntime\Support\Foundation;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\Router;
use Milpa\AppRuntime\Agent\ArchitectureSummaryProjector;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\DebtSignal;
use Milpa\AppRuntime\Agent\LaunchGrants;
use Milpa\AppRuntime\Agent\SessionIdentity;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityConfig;
use Milpa\AppRuntime\Policy\PolicyConfig;
use Milpa\ToolRuntime\Identity\GnupgSignatureVerifier;
use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\Attributes\PluginMetadata;
use Milpa\Plugin\Runtime\MetadataGraphResolver;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\AiGateway\LlmService;
use Milpa\AppRuntime\Agent\AgentTable;
use Milpa\AppRuntime\Agent\EffectClasses;
use Milpa\AppRuntime\Agent\ExecutionRecorder;
use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\AppRuntime\Agent\IntakeObserver;
use Milpa\AppRuntime\Agent\IntentAdmissibility;
use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\OptionTable;
use Milpa\AiGateway\SecondOpinionGate;
use Milpa\AppRuntime\Agent\RecordOnlyOptionTable;
use Milpa\AppRuntime\Agent\SessionOptionTable;
use Milpa\AppRuntime\Agent\SessionPlanBoard;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\StepWatcher;
use Milpa\AppRuntime\Agent\SterileLoopGuard;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\AppRuntime\Agent\TransitionGate;
use Milpa\AppRuntime\Agent\TreeBudget;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Compactor;
use Milpa\Agent\Session;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\StderrLogger;
use Milpa\Command\CommandProvider;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\DeclaredCondition;
use Milpa\Command\Consent\ConsentGrant;
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
use Milpa\AppRuntime\Agent\ContractProducer;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Support\Operations;
use Milpa\Interfaces\Tooling\ToolProviderInterface;
use Milpa\EventStore\EventStoreInterface;
use Milpa\AppRuntime\Agent\BroadcastingEventStore;
use Milpa\AppRuntime\Agent\MercureBroadcaster;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\EventStore\FileEventStore;
use Milpa\Runtime\Kernel;
use Milpa\AppRuntime\Agent\Skill\Skill;
use Milpa\AppRuntime\Agent\Skill\SkillRegistry;
use Milpa\AppRuntime\Agent\Role\AgentRole;
use Milpa\AppRuntime\Agent\Role\RoleRegistry;
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
    /** El techo de pasos cuando nadie lo dice. Vivía como un `12` suelto en dos lugares. */
    private const PASOS_POR_DEFECTO = 12;

    /**
     * The event store THIS invocation's session store was composed over, or `null` when the store
     * arrived already built and its log is not reachable ({@see self::sessions()} names the one
     * branch). It exists so the closure verdict can append its own event to the SAME stream the
     * session writes — a second store would be a second truth about what happened.
     */
    private ?EventStoreInterface $sessionEvents = null;

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
                description: 'The catalogue an agent would receive from this app, with each tool\'s inputSchema and declared effects',
                handler: fn (array $input): array => $this->catalogueFor($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'reads' => [
                            'type' => 'boolean',
                            'description' => 'Show the read-only catalogue — what the agent gets when the request was classified as reads',
                        ],
                        // SIN ESTO EL COMANDO MENTÍA POR SEIS (greenhouse evidence/0187). Enseñaba
                        // 22 herramientas mientras un agente en sesión recibe 28, y las que faltaban
                        // eran las de sesión: planear, anotar pendientes y delegar. `--session` ya se
                        // pasaba y se IGNORABA EN SILENCIO, así que quien la usaba creía que servía.
                        'session' => [
                            'type' => 'string',
                            'description' => 'Show the catalogue an agent IN THIS SESSION receives — six session tools only exist inside one',
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
            new Operation(
                name: 'operation:contract',
                description: 'One operation\'s declared contract, uniform: inputs, effects, preconditions, postconditions, artifacts and what proves a run — read from the declaration, never invented',
                handler: fn (array $input): array => $this->contractFor($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'The operation, in any surface spelling — config:set, config_set and config.set name the same act and resolve to it',
                        ],
                    ],
                    'required' => ['name'],
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'name' => ['type' => 'string', 'description' => 'The operation\'s declared spelling, not necessarily the one asked with'],
                        'description' => ['type' => 'string'],
                        'inputs' => ['type' => ['object', 'null'], 'description' => 'The declared inputSchema; null when the operation declares no typed inputs'],
                        'effects' => ['type' => 'object', 'description' => 'The effect ceiling as declared — unclassified is every axis at its maximum, and fully_classified inside says which of the two it is'],
                        'authority' => ['type' => 'string', 'description' => 'The authority axis of that same ceiling, alone, because gates ask for it by name'],
                        'mutating' => ['type' => 'boolean'],
                        'requiresConfirmation' => ['type' => 'boolean'],
                        'namedTarget' => ['type' => ['string', 'null']],
                        'surfaces' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                        'preconditions' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Each {name, description}, enforced by the handler — empty when the operation declares none'],
                        'postconditions' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Each {name, description}, proven by the producing package\'s verifier'],
                        'artifacts' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'observableEvidence' => ['type' => ['string', 'null']],
                        'error' => ['type' => 'string'],
                    ],
                    'required' => ['ok'],
                ],
                // Reads one declaration out of the assembled catalogue: it changes nothing, reaches
                // nobody, and spends no authority.
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'house:context',
                description: 'The house explained structurally in one call: app identity, plugins as booted, storage, routes, capabilities, operations, session tools and the layout conventions — each section read from its one authority',
                handler: fn (array $input): array => $this->houseContext(),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [],
                    // An empty `required` is information, its absence is not (evidence/0094): this
                    // aggregate demands nothing to be asked.
                    'required' => [],
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'app' => ['type' => 'object', 'description' => 'name (config `app.name`), root (the kernel\'s), and foundation — Foundation\'s own answer'],
                        'plugins' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Each {class, name, provides?} in the exact order the kernel holds them'],
                        'storage' => ['type' => 'object', 'description' => 'The storage block\'s shape: driver and where — never credentials'],
                        'routes' => ['type' => 'object', 'description' => 'count and paths of the route table the kernel\'s router holds'],
                        'capabilities' => ['type' => 'object', 'description' => 'The capability registry\'s own answer: installed, available, ports'],
                        'operations' => ['type' => 'object', 'description' => 'count and names of the assembled catalogue — Operations::all'],
                        'sessionTools' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The session notebook\'s names; empty when this app stores no sessions'],
                        'conventions' => ['type' => 'object', 'description' => 'The layout the generators write to: plugins, entities, controllers, config'],
                        'error' => ['type' => 'string'],
                    ],
                    'required' => ['ok'],
                ],
                // Reads the kernel, the config bag and the registries this app already assembled:
                // it changes nothing, reaches nobody, and spends no authority.
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                surfaces: ['cli', 'tui', 'mcp'],
                // THE DECLARED CONTRACT (greenhouse decisions/0183): no preconditions — an aggregate
                // that demanded something to be asked would be re-creating the orientation cost it
                // exists to pay off.
                observableEvidence: 'the answer itself: every key present, each section equal to its one authority — plugins to what the kernel booted, operations to the assembled catalogue, capabilities to the capability registry',
            ),
            new Operation(
                name: 'session:owner',
                description: 'Who the house recognizes as this session\'s verified owner right now — re-verified live, or the system user when none',
                handler: fn (array $input): array => $this->ownerOf($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => [
                            'type' => 'string',
                            'description' => 'The session to read the recognized owner of',
                            'x-milpa-source' => ['tool' => 'agent:sessions', 'path' => 'sessions', 'key' => 'session'],
                        ],
                    ],
                    'required' => ['session'],
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'session' => ['type' => 'string'],
                        'verified' => ['type' => 'boolean', 'description' => 'True only when a signature was re-verified live and its signer is recognized'],
                        'owner' => ['type' => ['string', 'null'], 'description' => 'The recognized principal as key:<fingerprint>, or null for the system user'],
                        'scopes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What the recognized owner may do'],
                        'note' => ['type' => 'string', 'description' => 'Why there is no verified owner, when there is not'],
                        'error' => ['type' => 'string'],
                    ],
                    'required' => ['ok'],
                ],
                // A projection may not proclaim what only the house can verify: it RE-VERIFIES the
                // stored assertion live and reports the fact, so a surface can show identity without
                // inventing it (greenhouse decisions/0117). It reads, reaches nobody, changes nothing.
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'reads only: there is nothing to roll back',
                ),
            ),
            new Operation(
                name: 'skill:load',
                description: 'Load a skill by name and follow its instructions for this task. Call this when a listed skill matches what you are about to do — its guidance is the real next step, not optional.',
                handler: fn (array $input): array => $this->loadSkill((string) ($input['name'] ?? '')),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'the skill name, e.g. governed-discovery'],
                    ],
                    'required' => ['name'],
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'name' => ['type' => 'string'],
                        'body' => ['type' => 'string', 'description' => 'the full skill instructions to follow'],
                        'error' => ['type' => 'string'],
                    ],
                    'required' => ['ok'],
                ],
                // Reads a local skill file: changes nothing, reaches nobody.
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'reads only: there is nothing to roll back',
                ),
            ),
            new Operation(
                name: 'skill:list',
                description: 'The skills this app carries — each one\'s name, description, and who may invoke it (the agent, the human, or both). A skill guides judgment; it is not a tool that runs.',
                handler: fn (array $input): array => $this->listSkills(),
                inputSchema: ['type' => 'object', 'properties' => []],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'skills' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'modelInvocable' => ['type' => 'boolean', 'description' => 'The agent may reach for it on its own'],
                                    'userInvocable' => ['type' => 'boolean', 'description' => 'The human may invoke it directly'],
                                    'bodyChars' => ['type' => 'integer', 'description' => 'Size of the full instructions loaded on demand'],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['ok'],
                ],
                // Reads the app's skills directory: changes nothing, reaches nobody.
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'reads only: there is nothing to roll back',
                ),
            ),
            new Operation(
                name: 'agent:role:list',
                description: 'The specialized agent roles this app declares — each with the skills it preloads and the tools it is denied. A role names authority that already governs; the skills only suggest.',
                handler: fn (array $input): array => $this->listRoles(),
                inputSchema: ['type' => 'object', 'properties' => []],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'roles' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'origin' => ['type' => 'string'],
                                    'produces' => ['type' => ['string', 'null']],
                                    'deny' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'first' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'skills' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['ok'],
                ],
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'reads only: there is nothing to roll back',
                ),
            ),
            new Operation(
                name: 'agent:role:declare',
                description: 'Compose a specialist agent: write .milpa/agents/<name>.md from a name, a brief (prompt), the skills it preloads, the tools it is denied, and what it produces. A role names authority that already governs — a role without a brief is a muzzle, not a specialist, and is refused.',
                handler: fn (array $input): array => $this->declareRole($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'lowercase letters, numbers, hyphens'],
                        'prompt' => ['type' => 'string', 'description' => 'the brief — who this specialist is and how it works'],
                        'skills' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'skills it preloads (they suggest)'],
                        'deny' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'tools withdrawn from its catalogue (this governs)'],
                        'produces' => ['type' => 'string', 'description' => 'the artifact kind its answer is checked against, if any'],
                    ],
                    'required' => ['name', 'prompt'],
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => ['ok' => ['type' => 'boolean'], 'name' => ['type' => 'string'], 'path' => ['type' => 'string'], 'error' => ['type' => 'string']],
                    'required' => ['ok'],
                ],
                mutating: true,
                // Writes one role file the operator owns: a local persistent change, no third party,
                // undone by editing or deleting the file.
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Compensatable,
                    authority: Authority::WriteAsUser,
                    subject: Subject::None,
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
                            // LA DESCRIPCION SE ARMA DE LA LISTA, no se copia junto a ella. Estas
                            // cuatro vivían escritas aquí Y en el `match` que resuelve, y dos copias
                            // discrepan el día que alguien cambia una (greenhouse evidence/0141).
                            'denyEffects' => ['type' => 'string', 'description' => EffectClasses::describe()],
                            // THE DENY'S MIRROR (greenhouse evidence/0442): what the operator
                            // withdraws travels as `deny`; what the operator CONSENTS TO at launch
                            // travels here, seeded into the session as the same fact a mid-session
                            // yes produces. Signature-class operations are refused at seeding.
                            'grant' => ['type' => 'string', 'description' => 'Comma-separated launch grants, each `op` or `op:key=value[;key2=value2]` — seeded into the session as operator consent. Requires --session; a signature-class operation is refused'],
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
    /**
     * El catálogo que un agente recibe — y de CUÁL mundo, dicho en voz alta.
     *
     * Se anunciaba como «el catálogo que un agente recibiría» y enseñaba 22 herramientas mientras un
     * agente en sesión recibe 28, medido del cable con un proxy y no del código (greenhouse
     * `evidence/0186`). Las seis que faltaban son las de sesión —`plan`, `todo` y las de delegación—,
     * que sólo existen dentro de una. Y `--session` se ignoraba en silencio.
     *
     * **Un depurador que enseña un subconjunto callándoselo es peor que uno que falla**: deja
     * depurando el mundo equivocado con confianza. Y es el único instrumento que esta casa ofrece
     * para ver lo que ve el agente.
     *
     * Sin sesión NO se inventan las seis —una app sin sesión de verdad no las ofrece, y decir que sí
     * sería el mismo defecto en espejo— pero se NOMBRAN aparte, para que quien abra el comando por
     * costumbre sepa qué le falta.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function catalogueFor(array $input): array
    {
        $readOnly = ($input['reads'] ?? false) === true;
        $sessionId = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        $store = $this->sessionStore();

        // UNA SESIÓN QUE NO EXISTE SE DICE. Devolver el catálogo pelón sería contestar otra pregunta
        // y dejar creer que se contestó la que se hizo.
        if ($sessionId !== '' && ($store === null || $store->load($sessionId) === null)) {
            return [
                'ok' => false,
                'session' => $sessionId,
                'error' => $store === null
                    ? 'esta app no guarda sesiones, así que no hay ninguna que mostrar'
                    : "no existe la sesión «{$sessionId}»",
                'hint' => 'córrela con `coa agent "…" --session=' . $sessionId . '`',
            ];
        }

        $deSesion = $store === null ? [] : $this->herramientasDeLaSesion($store, $sessionId);

        // LO QUE UNA SESIÓN AGREGARÍA se calcula ANTES de mirar el kernel, porque no depende de él:
        // depende de que esta app guarde sesiones. Calcularlo después dejaba a una app sin kernel
        // callándose las seis, que es el mismo silencio que este cambio vino a quitar.
        $masConSesion = [];
        if ($sessionId === '') {
            // EN LA ORTOGRAFÍA DEL AGENTE, que es de quien es esta vista. Los nombres crudos
            // mezclaban las dos —`agent:roles` junto a `agent_message`— y quien leyera esta lista
            // para pedir una herramienta habría pedido una que no existe con ese nombre.
            //
            // La convención SE LLAMA, no se copia (evidence/0141): reimplementarla aquí con un
            // `str_replace` es exactamente el defecto que esa acta cerró.
            $nombres = array_values(array_filter(array_map(
                static fn (object $op): string => class_exists(McpProjector::class)
                    ? McpProjector::toolName((string) ($op->name ?? ''))
                    : (string) ($op->name ?? ''),
                $deSesion,
            )));
            sort($nombres);
            $masConSesion = [
                'withSession' => [
                    'more' => \count($nombres),
                    'tools' => $nombres,
                    'why' => 'sólo existen dentro de una sesión: son cierres atados a ella',
                ],
                'hint' => $nombres === []
                    ? 'esta app no guarda sesiones, así que este catálogo es el completo'
                    : 'pasa --session=<id> para ver el catálogo que recibe un agente en sesión',
            ];
        }

        $declarations = [];
        $registry = $this->toolsOfThisApp($sessionId === '' ? [] : $deSesion, $readOnly, declarations: $declarations);
        if ($registry === null) {
            return [
                'ok' => false,
                'reads' => $readOnly,
                'session' => $sessionId === '' ? null : $sessionId,
                'total' => 0,
                'tools' => [],
                'error' => 'this app has no kernel, so nobody has assembled a catalogue to show yet',
                ...$masConSesion,
            ];
        }

        $tools = $this->catalogueTools($registry, $declarations);
        usort($tools, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return [
            'ok' => true,
            'reads' => $readOnly,
            'session' => $sessionId === '' ? null : $sessionId,
            'total' => \count($tools),
            'tools' => $tools,
            ...$masConSesion,
        ];
    }

    /**
     * One operation's declared contract, in the uniform shape — EVERY key always present.
     *
     * «What should happen?» before executing and «did it?» after are questions this house answers
     * from DECLARATIONS: the effect ceiling, the conditions the handlers enforce, the artifacts a
     * run leaves behind, the evidence that proves it ran. This reads them off the operation and
     * never derives, summarises or invents — an operation that declares nothing gets empty lists
     * and nulls, which is itself the honest answer.
     *
     * The operation is resolved by IDENTITY, never spelling ({@see OperationId}): `config:set`,
     * `config_set` and `config.set` are one act however a surface writes it. And it resolves across
     * the app's FULL declared catalogue — {@see Operations::all()} — so a capability-loaded
     * operation (devtools' `make`, once the capability is enabled) answers exactly like a native
     * one. An unknown name is a VERDICT, not an exception: `ok:false`, naming what was asked —
     * fail closed, in words (H-GATE-1).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function contractFor(array $input): array
    {
        $name = \is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '') {
            return ['ok' => false, 'error' => 'missing `name`: which operation'];
        }

        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [
                'ok' => false,
                'name' => $name,
                'error' => 'this app has no kernel, so nobody has assembled a catalogue to read a contract from yet',
            ];
        }

        $wanted = new OperationId($name);
        foreach (Operations::all($kernel, $kernel->root()) as $operation) {
            if (!$wanted->is($operation->name)) {
                continue;
            }

            // The ceiling, never null: an operation that declared nothing answers with every axis
            // at its maximum and `fully_classified: false` saying so (GOV-05) — strict from
            // ignorance is an answer; a permissive default would be an invention.
            $effects = $operation->effectCeiling();

            return [
                'ok' => true,
                'name' => $operation->name,
                'description' => $operation->description,
                'inputs' => $operation->inputSchema,
                'effects' => $effects->toArray(),
                'authority' => $effects->authority->value,
                'mutating' => $operation->mutating,
                'requiresConfirmation' => $operation->requiresConfirmation,
                'namedTarget' => $operation->namedTarget,
                'surfaces' => $operation->surfaces,
                'preconditions' => array_map(static fn (DeclaredCondition $c): array => $c->toArray(), $operation->preconditions),
                'postconditions' => array_map(static fn (DeclaredCondition $c): array => $c->toArray(), $operation->postconditions),
                'artifacts' => $operation->artifacts,
                'observableEvidence' => $operation->observableEvidence,
            ];
        }

        return [
            'ok' => false,
            'name' => $name,
            'error' => "unknown operation «{$name}» — no operation in this app's catalogue has that identity",
        ];
    }

    /**
     * The house explained structurally, in ONE call — every key always present.
     *
     * Live sessions were measured spending their first five to eight model calls ORIENTING:
     * `source_read` on plugin files, `contract_search` probes, reading an example plugin —
     * re-deriving every run what the house already knows about itself (greenhouse decisions/0183,
     * primitive #1). This is that aggregate, answered once.
     *
     * Each section is read from its ONE authoritative source, never a second index and never a
     * filesystem guess:
     *
     * - `app` — the config bag's `app.name`, the kernel's root, and {@see Foundation::answer()}
     *   verbatim, because FoundationOperations already owns that question;
     * - `plugins` — {@see Kernel::plugins()} as the kernel holds them, each named by its own
     *   `#[PluginMetadata]` (the same reading {@see self::pluginMetadata()} makes);
     * - `storage` — the `storage` block of the config bag, the block
     *   {@see \Milpa\Data\RepositoryFactory} reads: the driver and where it points, NEVER
     *   `storage.user` / `storage.password`, and a DSN stripped of any credential pair;
     * - `routes` — the table {@see Kernel::router()} actually holds. The router publishes no
     *   enumeration, so the table is read reflectively off the router itself rather than
     *   re-asking the plugins: a second derivation could drift from what the kernel serves;
     * - `capabilities` — {@see Capabilities::answer()}, the exact answer the `capabilities`
     *   operation gives (CapabilityOperations' authority);
     * - `operations` — the names in {@see Operations::all()}, the catalogue's own registry;
     * - `sessionTools` — the session notebook's declared names ({@see SessionBookkeeping}),
     *   empty when this app stores no sessions, because offering them then would be the lie
     *   `herramientasDeLaSesion()` documents;
     * - `conventions` — the layout the devtools generators REALLY write to, cited from their
     *   code, not invented: `PluginGenerator` writes `{appDir}/Plugins/<Name>/<Name>.php`,
     *   `EntityGenerator` `{appDir}/Plugins/<Name>/Entities/`, `ControllerGenerator`
     *   `{appDir}/Plugins/<Name>/Controllers/` (appDir is `src` under the runtime flavor), and
     *   the config seam is `config/`.
     *
     * No kernel is a fail-closed verdict in words, `operation:contract`'s own idiom (H-GATE-1).
     *
     * @return array<string, mixed>
     */
    public function houseContext(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [
                'ok' => false,
                'error' => 'this app has no kernel, so there is no booted house to explain yet',
            ];
        }

        $root = $kernel->root();
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $config = $config instanceof Config ? $config : null;

        $plugins = [];
        $booted = $kernel->bootedPluginNames();
        foreach ($kernel->plugins() as $plugin) {
            $attributes = (new \ReflectionClass($plugin))->getAttributes(PluginMetadata::class);
            $meta = $attributes === [] ? null : $attributes[0]->newInstance();

            // AS THE KERNEL BOOTS THEM: `plugins()` also carries the vetoed ones, and a plugin
            // whose `boot()` never ran contributes no tools, no routes and no commands — a row
            // for it would describe a house that does not exist ({@see self::pluginMetadata()}
            // reads the same distinction for the architecture summary). A configured plugin
            // without metadata cannot have booted at all: the kernel refuses it before boot.
            if ($meta === null || !\in_array($meta->name, $booted, true)) {
                continue;
            }

            $row = [
                'class' => $plugin::class,
                'name' => $meta->name,
            ];
            $provides = [];
            foreach ($meta->provides as $entry) {
                $id = \is_string($entry) ? $entry : ($entry['id'] ?? null);
                if (\is_string($id) && $id !== '') {
                    $provides[] = $id;
                }
            }
            if ($provides !== []) {
                $row['provides'] = $provides;
            }
            $plugins[] = $row;
        }

        $declaredStorage = $config?->get('storage');
        $declaredStorage = \is_array($declaredStorage) ? $declaredStorage : [];
        $driver = \is_string($declaredStorage['driver'] ?? null) ? $declaredStorage['driver'] : null;
        $where = \is_string($declaredStorage['path'] ?? null) ? $declaredStorage['path'] : null;
        if ($where === null && \is_string($declaredStorage['dsn'] ?? null)) {
            // The DSN is where a mysql backend points — minus anything that could be a credential.
            $where = preg_replace('/(user|password)=[^;]*/i', '$1=…', $declaredStorage['dsn']);
        }

        $table = (new \ReflectionProperty(Router::class, 'routes'))->getValue($kernel->router());
        $paths = [];
        foreach (\is_array($table) ? $table : [] as $route) {
            if ($route instanceof Route) {
                $paths[] = $route->path;
            }
        }

        $names = array_map(
            static fn (Operation $operation): string => $operation->name,
            Operations::all($kernel, $root),
        );

        // A synthetic id is enough to ENUMERATE, `herramientasDeLaSesion()`'s own reasoning:
        // the notebook's names do not depend on which session it is.
        $store = $this->sessions();
        $sessionTools = $store === null ? [] : array_map(
            static fn (Operation $operation): string => $operation->name,
            (new SessionBookkeeping($store, '·enumerando·', $this->sessionEvents))->operations(),
        );

        $appName = $config?->get('app.name');

        return [
            'ok' => true,
            'app' => [
                'name' => \is_string($appName) && $appName !== '' ? $appName : basename($root),
                'root' => $root,
                'foundation' => Foundation::answer($root),
            ],
            'plugins' => $plugins,
            'storage' => ['driver' => $driver, 'where' => $where],
            'routes' => ['count' => \count($paths), 'paths' => $paths],
            'capabilities' => Capabilities::answer(),
            'operations' => ['count' => \count($names), 'names' => $names],
            'sessionTools' => $sessionTools,
            'conventions' => [
                'plugins' => 'src/Plugins/<Name>/<Name>.php',
                'entities' => 'src/Plugins/<Name>/Entities',
                'controllers' => 'src/Plugins/<Name>/Controllers',
                'config' => 'config/',
            ],
        ];
    }

    /**
     * Add only the effect declarations carried by the channel that produced these tools.
     *
     * The registry's flat definition cannot distinguish an omitted boolean from `false`: both are
     * stored as `false`. Operations do preserve the declaration that was projected, so their tools
     * can honestly publish both boolean values and the profile they carry. A tool registered through
     * another path has no such provenance here. For that tool, `true` is still evidence because it
     * cannot be the registry default; `false` is not, and the missing declaration is named in
     * `cannotSay` rather than turned into a reassuring answer nobody gave.
     *
     * @param list<Operation> $declarations the exact operations selected by {@see toolsOfThisApp()}
     *
     * @return list<array<string, mixed>>
     */
    private function catalogueTools(ToolRegistry $registry, array $declarations): array
    {
        /** @var array<string, Operation|null> $byTool */
        $byTool = [];
        foreach ($declarations as $operation) {
            $name = McpProjector::toolName($operation->name);
            // Two operation names can normalize to one MCP name. That collision carries no honest
            // answer about which declaration produced the registered tool, so keep it unknown.
            $byTool[$name] = \array_key_exists($name, $byTool) ? null : $operation;
        }

        $tools = [];
        foreach ($registry->getToolSummaries() as $tool) {
            $name = (string) $tool['name'];
            $definition = $registry->getDefinition($name);
            $operation = $byTool[$name] ?? null;
            $cannotSay = [];

            if ($operation instanceof Operation && $definition !== null) {
                $tool['mutating'] = $definition->mutating;
                $tool['requiresConfirmation'] = $definition->requiresConfirmation;
            } else {
                if ($definition?->mutating === true) {
                    $tool['mutating'] = true;
                } else {
                    $cannotSay[] = 'mutating';
                }

                if ($definition?->requiresConfirmation === true) {
                    $tool['requiresConfirmation'] = true;
                } else {
                    $cannotSay[] = 'requiresConfirmation';
                }
            }

            if ($operation?->effects !== null) {
                $tool['effects'] = $operation->effects->toArray();
            } else {
                $cannotSay[] = 'effects';
            }

            if ($cannotSay !== []) {
                $tool['cannotSay'] = $cannotSay;
            }
            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * Las herramientas que SÓLO existen dentro de una sesión, ensambladas en UN solo lugar.
     *
     * `plan`, `todo` y las cuatro de delegación son cierres atados a una sesión concreta: el id se
     * captura, no se le pide al modelo. Vivían armadas únicamente dentro de `run()`, así que el
     * catálogo —que corre sin sesión— nunca las veía y reportaba seis de menos sin decirlo.
     *
     * El corredor del hijo NO se pasa aquí: quien sólo quiere el catálogo necesita la FORMA de esas
     * operaciones, no su ejecución. Se le da un corredor que revienta si alguien lo llama, porque un
     * corredor de mentiras que devuelve algo plausible sería peor que uno que falta.
     *
     * @return list<object>
     */
    private function herramientasDeLaSesion(SessionStore $store, string $sessionId, ?\Closure $corredor = null): array
    {
        // Un id sintético alcanza para ENUMERAR: los nombres y esquemas no dependen de qué sesión
        // sea. Lo que no se hace nunca es meterlas al catálogo como si la app las ofreciera sin una.
        $id = $sessionId !== '' ? $sessionId : '·enumerando·';

        $ops = (new SessionBookkeeping($store, $id, $this->sessionEvents))->operations();

        if (!class_exists(SubAgentSpawner::class)) {
            return $ops;
        }

        $spawner = new SubAgentSpawner(
            $store,
            $id,
            $corredor ?? static function (): array {
                throw new \LogicException('este spawner se armó para enumerar el catálogo, no para correr un hijo');
            },
            $this->presupuestoDelArbol(self::PASOS_POR_DEFECTO),
            prologue: fn (): ?string => $this->foundationArrow()?->teaching(),
            roles: $this->rolesRegistry(),
            skills: $this->skillsRegistryForSpawn(),
        );

        return [...$ops, $spawner->operation(), $spawner->resumeOperation(), $spawner->messageOperation(), $spawner->rolesOperation()];
    }

    /**
     * Corre el bucle y devuelve lo que el agente contestó.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, answer?: string, steps?: int, tools?: int, error?: string, hint?: string, paused?: bool, exhausted?: bool, stalled?: bool, receipt?: array<string, mixed>, houseDebt?: bool, interrupted?: bool, closure?: array{verified: bool, reasons: list<string>}}
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

        $pasos = \is_int($input['steps'] ?? null) && $input['steps'] > 0 ? $input['steps'] : self::PASOS_POR_DEFECTO;

        // ── LAUNCH GRANTS (greenhouse evidence/0442) ────────────────────────────────────────────
        //
        // Parsed BEFORE anything mutates, and refused whole on the first malformed entry: accepting
        // the good half in silence would leave whoever typed it believing the whole brief landed —
        // the same doctrine `denyEffects` already enforces on an invented class.
        $grantsAsked = LaunchGrants::parse($input['grant'] ?? null);
        if (\is_string($grantsAsked)) {
            return [
                'ok' => false,
                'error' => $grantsAsked,
                'hint' => 'write grant entries as `op` or `op:key=value[;key2=value2]`, comma-separated',
            ];
        }

        // LA SESIÓN (P16.1). Sin `session`, esto sigue siendo lo que era: una pregunta con una
        // respuesta. Con ella, la conversación sobrevive al proceso — que es la diferencia entre un
        // agente al que se le pregunta algo y uno con el que se trabaja un rato.
        $store = $this->sessions();
        $sessionId = \is_string($input['session'] ?? null) ? trim($input['session']) : '';

        // SIN SESIÓN NO HAY CONTABILIDAD, Y SIN CONTABILIDAD EL PRIMER TURNO NO PUEDE PLANEAR.
        //
        // `plan` y `todo` se registran atadas a una sesión del almacén, así que una corrida sin
        // `--session` no las llevaba: 28 herramientas contra 34 — medido en el cable, greenhouse
        // evidence/0172. Y el prompt le pedía justamente ahí «escribe un plan ANTES de empezar»,
        // en el único turno donde `plan` no existía. El segundo turno ya la tenía, cuando el plan
        // que importaba ya no se escribió.
        //
        // Se acuña una. Rod, 2026-08-13: toda app debe traer `plan` y `todo`. El costo es que cada
        // corrida deja sesión en el almacén — y eso es lo que la vuelve inspeccionable con
        // `agent:sessions`, que existe para eso.
        if ($sessionId === '' && $store !== null) {
            $sessionId = 'run-' . date('md-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
        }
        $historial = [];
        /** @var list<array{role: string, content: string, class: string}>|null $declaredWindow */
        $declaredWindow = null;

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
                $declaredWindow = $store->load($sessionId)?->classifiedWindow();
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
                $declaredWindow = $sesion?->classifiedWindow();
            }

            // ── SEED THE LAUNCH GRANTS, before the gate reads the session ───────────────────
            //
            // It must land HERE: the decisions snapshot and the gate below fold the session as it
            // stands, so consent seeded after them would exist in the stream and govern nothing
            // until the next turn. Judged against the live catalogue and refused whole when an
            // entry cannot be judged or demands a signature — never narrowed in silence.
            if ($grantsAsked !== []) {
                $kernelDeGrants = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
                if (!$kernelDeGrants instanceof Kernel) {
                    return ['ok' => false, 'error' => 'a grant needs the app catalogue to be judged against, and this app exposes none'];
                }
                $sembrado = (new LaunchGrants())->seed(
                    $store,
                    $sessionId,
                    $grantsAsked,
                    Operations::all($kernelDeGrants, $kernelDeGrants->root()),
                    // WHO CONFERS IT: the operator at the terminal, observed now and written once —
                    // the same reading `governedExecutor()` makes for who materialises effects.
                    Principal::fromTerminal(getenv('USER') ?: null, gethostname() ?: null),
                );
                if (isset($sembrado['error'])) {
                    return ['ok' => false, 'error' => (string) $sembrado['error']];
                }
            }

            // Both representations come from the same immutable Session. Capture them before the
            // current prompt becomes a turn: the gateway adds that prompt separately, so claiming it
            // as part of this declaration would say Session::window() composed something it did not.
            $store->recordTurn($sessionId, 'user', $prompt);
        }

        // A GRANT WITH NO SESSION WOULD BE A MASTER KEY. The consent lives in the session — it is
        // what scopes it — so without one there is nowhere to record it and nothing to bind it to.
        // Same refusal shape as `deny`: silently ignoring it would be worse than lacking it.
        if ($grantsAsked !== [] && ($sessionId === '' || $store === null)) {
            return [
                'ok' => false,
                'error' => 'a grant cannot be seeded without a session: the consent lives in the session',
                'hint' => 'add --session=<id> and run it again',
            ];
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
        /** @var list<array<string, mixed>> $decisionesDeSesion cada sí, con la operación y los argumentos que el humano vio */
        $decisionesDeSesion = [];
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if ($sessionId !== '' && $store !== null && $kernel instanceof Kernel) {
            $viva = $store->load($sessionId);
            if ($viva !== null) {
                // THE APP'S DECLARED POLICY, so the gate can read the composed ceiling of a call —
                // its owner's authority and its certified descents (greenhouse decisions/0058). Null
                // when the app declares none, and then the gate decides by the flag as it always did.
                $decisionesDeSesion = ContratoInstalado::arreglo($viva, 'decisions');
                // ONE BUILDER, so a collaborator cannot be forgotten in one gate and remembered in
                // another (greenhouse decisions/0058, 0059). It was: the pin's own method caught a
                // gate wired without policy or identity, so a session owned by a real signature never
                // saw its authority descend (evidence/0259). A unit test that builds the gate by hand
                // cannot catch a wiring the constructor makes optional — so the wiring lives in ONE
                // place, `nuevaCompuerta`, and both the main session and the sub-agent pass through
                // it. What varies — the session, the human's petition, the ordering obligation —
                // travels as an argument; everything else is the same producers for everyone.
                $compuerta = $this->nuevaCompuerta($store, $kernel, $viva, $prompt, $runFirst);
                // ATADAS a esta sesión: el id se captura, no se le pide al modelo. Uno que el modelo
                // pudiera nombrar es uno que puede errar, y escribirle el plan a otra sesión no es una
                // equivocación recuperable — quien la lea mañana verá un plan que su agente no escribió.
                $contabilidad = (new SessionBookkeeping($store, $sessionId, $this->sessionEvents))->operations();

                // LA DELEGACIÓN (Q-P19-P). El hijo es una sesión con `parentId` corriendo por los
                // MISMOS rieles: mismo orquestador, misma compuerta —que ya pide el techo del linaje
                // en cada llamada—, mismo almacén. El catálogo del hijo sale de su propia
                // contabilidad SIN spawn: profundidad 1 por construcción, y el presupuesto del árbol
                // (§5.4) queda diferido con esa misma decisión a la vista.
                $presupuestoDelArbol = $this->presupuestoDelArbol($pasos);
                $spawner = new SubAgentSpawner(
                    $store,
                    $sessionId,
                    function (
                        string $encargo,
                        string $hijoId,
                        array $historialHijo,
                        array $primeroHijo = [],
                        array $declaredWindowHijo = [],
                    ) use ($store, $kernel, $pasos, $proveedor, $llave, $modelo, $presupuestoDelArbol): array {
                        $hijo = $store->load($hijoId);
                        if ($hijo === null) {
                            return ['answer' => 'la sesión hija no se pudo abrir', 'steps' => 0];
                        }

                        // The SAME builder as the main session (greenhouse decisions/0059): the
                        // child's intent contract compares against ITS petition — what the parent
                        // asked is, to the child, what the human is to the parent — and its ordering
                        // obligation governs only the turn that spawns it. Everything else, the arrow
                        // and the composed-ceiling producers included, is what the main gate gets,
                        // because delegation is not a tunnel.
                        $compuertaHijo = $this->nuevaCompuerta($store, $kernel, $hijo, $encargo, $primeroHijo);
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
                                ...(new SessionBookkeeping($store, $hijoId, $this->sessionEvents))->operations(),
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
                        // The observer is created inside ask(), so its session and declaration must
                        // follow the nested call and then return to the parent. Leaving parent state
                        // here would append the child's intake to the wrong stream; reloading later
                        // would classify a different window from the one the spawner supplied.
                        $parentIntakeSession = $this->intakeSession;
                        $parentDeclaredWindow = $this->declaredWindow;
                        $this->intakeSession = $hijoId;
                        $this->declaredWindow = $declaredWindowHijo;

                        try {
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
                        } finally {
                            $this->intakeSession = $parentIntakeSession;
                            $this->declaredWindow = $parentDeclaredWindow;
                        }

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
                    roles: $this->rolesRegistry(),
                    skills: $this->skillsRegistryForSpawn(),
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
        // UNA CLASE QUE NADIE DEFINIO SE RECHAZA POR NOMBRE, y con la lista de las reales.
        //
        // Medido sobre ganado: `--denyEffects=mutates` retiraba cero, fallaba cero y decía cero, así
        // que quien lo tecleó pedía retirar una clase entera y creía que había pasado — contenía
        // menos de lo que creía, sin una palabra (greenhouse evidence/0197). Una bandera que acepta
        // lo que no entiende es ley sin mecanismo.
        //
        // Se rechaza ANTES de retirar: aceptar la mitad buena y callar la inventada sería peor, con
        // el operador viendo ALGUN retiro y concluyendo que la instrucción entera aterrizó.
        $clasesInventadas = EffectClasses::unknownIn($input['denyEffects'] ?? null);
        if ($clasesInventadas !== []) {
            return [
                'ok' => false,
                'error' => EffectClasses::refusal($clasesInventadas),
                'hint' => 'name the tools with --deny instead, or use one of the classes above',
            ];
        }

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

        // WHEN TRIALS ARE ON, the registry the model calls through runs a confined mutation in a
        // disposable copy instead of on the host — the SAME router the gate used to compose the call,
        // so the plan the gate judged is the plan the executor runs (greenhouse decisions/0069).
        $trialRouter = $this->trialRouter($kernel);
        if ($trialRouter !== null) {
            $registry = new TrialAwareRegistry(
                $registry,
                $trialRouter,
                Operations::all($kernel, $kernel->root()),
                $store,
                $sessionId !== '' ? $sessionId : null,
            );
        }

        $vistos = 0;
        // EL VIGÍA MIRA EL TECLADO ENTRE PASOS, si la app registró uno. Sin él esto corre igual que
        // antes: una app sin terminal no tiene a quién preguntarle si quiere parar.
        $vigia = $this->container->has(StepWatcher::class) ? $this->container->get(StepWatcher::class) : null;
        $vigia = $vigia instanceof StepWatcher ? $vigia : null;

        try {
            // Lo que ESTA sesión ya consintió, puesto donde `ask()` lo lee sin cambiar su firma:
            // `ask()` es protected y el esqueleto lo sobrescribe, así que crecerle parámetros lo rompe.
            $this->decisionesDeLaSesion = $decisionesDeSesion;
            // THE CATALOGUE THE INTENT CLAIMS ARE JUDGED BY (greenhouse decisions/0184): a confirmed
            // intent derives a grant only under its operation's DECLARED effect ceiling, and the
            // ceiling is a declaration of the catalogue. Captured here with the decisions, for the
            // same reason they are: `ask()` is protected and overridden, so nothing new may travel
            // in its signature. Empty when there is no kernel — and with no catalogue there is no
            // ceiling, so no intent claim mints anything: fail closed.
            $this->catalogueForIntentClaims = $kernel instanceof Kernel
                ? Operations::all($kernel, $kernel->root())
                : [];
            $this->sesionDeLosPermisos = $sessionId !== '' ? $sessionId : null;
            $this->intakeSession = $sessionId !== '' ? $sessionId : null;
            $this->declaredWindow = $declaredWindow;

            $respuesta = $this->ask(
                $prompt,
                $pasos,
                $registry,
                $proveedor,
                $llave,
                $modelo,
                function () use (&$vistos, $vigia): void {
                    ++$vistos;
                    $vigia?->paso($vistos);
                },
                $historial,
                $compuerta,
                $table,
                $grabadora,
                $this->tableroDePlan($sessionId, $store),
            );
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

        // A STALLED LEG IS AN HONEST END, NOT AN ERROR (greenhouse decisions/0185). Named exactly
        // like `exhausted` above and for the same reason: a surface must recognize the state, never
        // infer it from a string. The receipt the probe derived rides the sentinel's second line;
        // it is decoded here once so the surface reads numbers instead of parsing an answer.
        // Guarded by `defined()` because this file coexists with whatever ai-gateway its owner has
        // installed (the planBoard trap): against an older one the constant simply does not exist
        // and no answer can carry it.
        if (\defined(AgentOrchestrator::class . '::PROGRESS_STALLED')
            && str_starts_with($respuesta, AgentOrchestrator::PROGRESS_STALLED)
        ) {
            $resultado['stalled'] = true;
            $decoded = json_decode(trim(substr($respuesta, \strlen(AgentOrchestrator::PROGRESS_STALLED))), true);
            if (\is_array($decoded) && \is_array($decoded['receipt'] ?? null)) {
                $resultado['receipt'] = $decoded['receipt'];
            }
            $resultado['answer'] = 'The leg ended without semantic progress: the house put the '
                . 'forced choice in front of the model and it took none of the options.';
            $resultado['hint'] = 'read the receipt, then re-run with a narrower brief — or take the blocked decision yourself';
        }

        // A DECLARED FRAMEWORK GAP BECOMES A DEBT SIGNAL (greenhouse decisions/0185): the model
        // faced the forced choice and named the blocker as the house's own plumbing. The signal
        // carries a DIGEST — the first line, bounded by the emitter — never the raw prose: the
        // full declaration is already in the stream as the assistant turn recorded above. The
        // answer still surfaces verbatim; recording an observation must not rewrite what was said.
        if ($sessionId !== ''
            && \defined(AgentOrchestrator::class . '::HOUSE_DEBT_MARKER')
            && str_starts_with(trim($respuesta), AgentOrchestrator::HOUSE_DEBT_MARKER)
        ) {
            $declaration = trim(substr(trim($respuesta), \strlen(AgentOrchestrator::HOUSE_DEBT_MARKER)));
            $firstLine = trim(strtok($declaration, "\n") ?: '');
            (new DebtSignal($this->sessionEvents, $sessionId))->emit(DebtSignal::FRAMEWORK_GAP, [
                'summary' => $firstLine,
            ]);
            $resultado['houseDebt'] = true;
        }

        // ── THE CLOSURE VERDICT (greenhouse evidence/0442) ──────────────────────────────────────
        //
        // Only the NATURAL end carries it: paused, interrupted and exhausted returns already say
        // what they are. Derived from RECORDED facts alone — no re-scan, no re-run, no model call —
        // and appended to the session's own stream exactly once per final answer, so a surface can
        // project it. It blocks the assertion, never the write: the answer still returns, but the
        // envelope cannot claim a completion the ledger does not back.
        // `$pausada` is only ever loaded with a session and its store in hand, so its presence is
        // the whole guard: re-checking the store here would be a condition that can never fire.
        if ($pausada !== null && !isset($resultado['paused']) && !isset($resultado['exhausted']) && !isset($resultado['stalled'])) {
            $closure = ClosureVerdict::derive($pausada, $store->facts($sessionId));
            $resultado['closure'] = $closure;
            if ($this->sessionEvents !== null) {
                ClosureVerdict::record($this->sessionEvents, $sessionId, $closure);
            }
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
     * @param list<array{role: string, content: string}> $history  lo que ya se dijo en esta sesión —
     *                                                             vacío cuando no hay sesión, que es
     *                                                             como corría antes de P16.1
     * @param list<string>                               $permisos operaciones que ESTA sesión ya consintió
     */
    /**
     * LOS PERMISOS NO VIAJAN EN LA FIRMA DE `ask()`, y eso es compatibilidad, no estilo.
     *
     * `ask()` es `protected` y el esqueleto lo SOBRESCRIBE: agregarle parámetros rompió su override
     * con un fatal en cada corrida — «Declaration of … ::ask() must be compatible». Un punto de
     * extensión que viaja en `composer create-project` no puede crecer parámetros sin romper a quien
     * ya lo extendió, y esa lección la pagó v0.28.0.
     */
    /**
     * Los sí de esta sesión, como hechos con sus argumentos exactos.
     *
     * **Sólo cuentan las decisiones que guardaron el hecho estructurado.** Una sesión vieja trae el
     * `why` como el JSON pelón de los argumentos, sin decir de qué operación son, y de ahí no se
     * puede reconstruir a qué dijo que sí el humano sin leer el TEXTO de la pregunta. Esa sesión
     * vuelve a preguntar, y eso es lo correcto: fallar hacia arriba es la única falla que esta
     * familia se puede permitir en este eje (greenhouse decisions/0029).
     *
     * @return list<ConsentGrant>
     */
    private function grantsDeLaSesion(): array
    {
        if ($this->decisionesDeLaSesion === []) {
            return [];
        }

        $ahora = new \DateTimeImmutable();
        $grants = [];

        foreach ($this->decisionesDeLaSesion as $decision) {
            $reason = $decision['reason'] ?? null;
            if ($reason !== 'permission' && $reason !== 'target_not_named') {
                continue;
            }
            if (! AffirmativeAnswer::is((string) ($decision['answer'] ?? ''))) {
                continue;
            }
            $hecho = json_decode(\is_string($decision['why'] ?? null) ? $decision['why'] : '', true);
            if (! \is_array($hecho) || ! \is_string($hecho['operation'] ?? null)) {
                continue;
            }

            // A CONFIRMED INTENT IS A CLAIM, NOT A PERMISSION (greenhouse decisions/0184). It never
            // minted an event, so it is DERIVED per run like everything else here — but only for the
            // tiers the policy rules admissible, judged from the operation's declared ceiling. The
            // PolicyGate layer then honours the same semantics with its exact `covers()`.
            if ($reason === 'target_not_named') {
                $grant = $this->grantFromIntentClaim($decision, $hecho, $ahora);
                if ($grant !== null) {
                    $grants[] = $grant;
                }

                continue;
            }

            // QUIÉN LO AUTORIZÓ SE LEE DEL REGISTRO, NO DEL ENTORNO.
            //
            // Esto se armaba con `getenv('USER')` y `gethostname()`, o sea con la identidad de quien
            // estuviera corriendo AHORA. Como el consentimiento no se guarda sino que se re-deriva
            // cada vez, el mismo sí grabado volvía a nombre de otra persona según quién retomara la
            // sesión: medido en ganado —rod contestó, impostor retomó, la operación corrió— y el
            // registro sólo nombraba a rod (greenhouse evidence/0209).
            //
            // Leer el `by` grabado NO lo asciende: llega `verified:false` y se queda `verified:false`.
            // Lo único que cambia es que la autoridad deja de pertenecerle al lector.
            //
            // Y donde no hay `by` —streams escritos antes de que la respuesta lo cargara— queda
            // `null`. Un registro con un hueco es peor de ver y más verdadero que uno rellenado con
            // quien pasaba por ahí, que es exactamente el defecto que esto viene a quitar.
            $concedio = ($decision['by'] ?? null) instanceof Principal ? $decision['by'] : null;

            $grants[] = new ConsentGrant(
                operation: new OperationId($hecho['operation']),
                principal: $concedio?->id,
                session: $this->sesionDeLosPermisos,
                grantedAt: $ahora,
                // Cómo se ganó, para que ningún consumidor tenga que volver a ganarlo. Un sí
                // sembrado al lanzar conserva su procedencia: el auditor distingue el grant de
                // arranque del sí contestado a media sesión sin releer el stream.
                provenance: ($decision['executor'] ?? null) === LaunchGrants::EXECUTOR
                    ? LaunchGrants::EXECUTOR
                    : 'session.question_answered',
                arguments: \is_array($hecho['arguments'] ?? null) ? $hecho['arguments'] : [],
            );
        }

        return $grants;
    }

    /**
     * The ConsentGrant a confirmed intent claim derives, or `null` when the claim buys none.
     *
     * «La intención describe qué quiere el humano. La policy decide qué autoridad compra haberlo
     * dicho.» (Rod, greenhouse decisions/0184). The tier is judged at judgment time from the
     * operation's DECLARED ceiling — an operation the captured catalogue does not declare, or one
     * that never declared its effects, fails closed. A claim that names no arguments also derives
     * nothing: an argument-less ConsentGrant covers every call of its operation, and a claim may
     * never buy a blanket.
     *
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $hecho
     */
    private function grantFromIntentClaim(array $decision, array $hecho, \DateTimeImmutable $ahora): ?ConsentGrant
    {
        $argumentos = \is_array($hecho['arguments'] ?? null) ? $hecho['arguments'] : null;
        if ($argumentos === null || $argumentos === []) {
            return null;
        }

        $operation = (string) $hecho['operation'];
        if (IntentAdmissibility::tier($this->declaredCeilingOf($operation)) === IntentAdmissibility::NEVER) {
            return null;
        }

        // WHO CONFIRMED IT, read from the record — never from the environment (evidence/0209).
        $concedio = ($decision['by'] ?? null) instanceof Principal ? $decision['by'] : null;

        return new ConsentGrant(
            operation: new OperationId($operation),
            principal: $concedio?->id,
            session: $this->sesionDeLosPermisos,
            grantedAt: $ahora,
            provenance: 'intent-confirmed',
            arguments: $argumentos,
        );
    }

    /**
     * The declared EffectProfile of an operation in the captured catalogue — identity, not spelling.
     *
     * `null` both when the catalogue does not declare the operation and when the operation declared
     * no profile: the two are the same answer to the caller, «no ceiling to judge by», and the
     * admissibility table treats that as NEVER.
     */
    private function declaredCeilingOf(string $operation): ?EffectProfile
    {
        $id = new OperationId($operation);
        foreach ($this->catalogueForIntentClaims as $operacion) {
            if ($id->is($operacion->name)) {
                return $operacion->effects;
            }
        }

        return null;
    }

    /** @var list<array<string, mixed>> lo que ESTA sesión ya decidió, con su hecho adentro */
    private array $decisionesDeLaSesion = [];

    /**
     * The app's declared catalogue, captured with the decisions so an intent claim can be judged by
     * its operation's declared ceiling (greenhouse decisions/0184). Empty means «no ceiling», which
     * derives nothing.
     *
     * @var list<Operation>
     */
    private array $catalogueForIntentClaims = [];

    private ?string $sesionDeLosPermisos = null;

    private ?string $intakeSession = null;

    /** @var list<array{role: string, content: string, class: string}>|null */
    private ?array $declaredWindow = null;

    // THE ONE trial router for this invocation (greenhouse decisions/0069): built once, shared by the
    // gate and the trial-aware registry so «this call is confined» has a single source. `false` means
    // «not resolved yet», `null` means «resolved to none» — the leaf is off, or there is no sandbox.
    private TrialRouter|null|false $trialRouterMemo = false;

    /**
     * Una vuelta del agente contra el modelo, con sus herramientas y su compuerta.
     *
     * @param array<int, array<string, mixed>> $history lo que ya se dijo en esta sesión
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
        $argumentos = [
            $llave,
            $modelo,
            $proveedor,
            new NullLogger(),
            'baseUrl' => $this->baseUrl(),
            'extraHeaders' => $this->extraHeaders(),
            // LA ENTRADA DEL AGENTE, GRABADA DONDE SE SERIALIZA.
            //
            // Sin este cable el stream sigue guardando sólo lo que el agente HIZO, y `agent:observe`
            // reporta con toda razón que nadie observó lo que le DIERON — para siempre. Es el paso
            // que convierte dos extremos probados en una cadena.
            //
            // Sólo cuando hay sesión: una corrida sin sesión no tiene dónde apendar, y grabar en
            // ningún lado con tal de grabar sería peor que no grabar.
            'channelObserver' => $this->observadorDeEntrada(),
        ];

        // EL PULSO SÓLO SI EL LlmService LO ADMITE — degradar, no romper.
        //
        // `onStreamChunk` nació en una versión posterior de `milpa/ai-gateway`; pasarlo como
        // argumento con nombre a un constructor que no lo declara es un fatal, aunque el valor sea
        // null. Y ai-gateway es una capacidad OPCIONAL: esta app no puede fijar su versión. Así que
        // se le pregunta al constructor si lo acepta —una app con el ai-gateway viejo corre igual,
        // sin streaming; con el nuevo, el spinner late por chunk (greenhouse evidence/0307)—.
        if ($this->llmServiceAdmiteStreaming()) {
            // EL PULSO DEL MODELO, HONESTO. Con una superficie viva, late por cada trozo REAL que el
            // modelo escribe, para que su spinner avance por hecho —no por reloj— mientras el modelo
            // tiene la palabra. Sin superficie —un `coa agent` de script— `progresoDelModelo()`
            // devuelve null y el camino queda sin streaming, byte por byte como antes.
            $argumentos['onStreamChunk'] = $this->progresoDelModelo();
        }

        $modeloRemoto = new LlmService(...$argumentos);
        $cliente = $this->governedExecutor($registry, $gate, $recorder, $mesa);

        // EL PUENTE SE QUEDA CON EL CONTEXTO, y no se arma aquí.
        //
        // Antes esta capa ponía `consent.grants` una vez por corrida. Pero `PolicyGate` compara el
        // grant contra `consent.arguments` —los argumentos de LA LLAMADA que está juzgando— y esos
        // cambian en cada paso, así que un contexto puesto una sola vez nunca podía traerlos: iban
        // vacíos, y un grant sin argumentos cubría cualquier llamada cuyo nombre coincidiera.
        //
        // No era una regla con un hueco. Era una regla a la que nunca se le dio nada que comparar.
        // `ConsentBridge` lo pone por llamada, que es la única capa que sabe cuál es (decisions/0031).

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
        // The lazy toolbox is opt-in (agent.lazyTools). It serves tool schemas on demand instead of
        // inlining all of them — worth it for a small-context model, at one describe round-trip per tool.
        $configTB = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $lazyTools = ($configTB instanceof Config ? $configTB->get('agent.lazyTools') : null) === true;

        // THE PROGRESS PROBE (greenhouse decisions/0185), built from the SAME captured state the
        // grants and the intent catalogue already ride — `ask()` is protected and overridden, so
        // nothing new may travel in its signature. Non-null ONLY when the installed ai-gateway
        // declares the seam, so the branch below can pass the extra constructor argument safely.
        $sonda = $this->progressProbe();

        // Three shapes, not one array: the named `lazyTools` argument only appears when the toolbox is
        // on, because passing an argument an OLDER ai-gateway does not declare throws «Unknown named
        // parameter» on every turn (the planBoard trap). When it is off, the old positional shape rides.
        // A fourth shape carries the probe: its non-null already proves the installed ai-gateway is
        // >=0.16, so every earlier parameter is declared too and the full positional list is safe.
        if ($sonda !== null) {
            $orquestador = new AgentOrchestrator($modeloRemoto, $cliente, $pasos, new NullLogger(), null, $tablero, $lazyTools, $sonda);
        } elseif ($lazyTools) {
            $orquestador = new AgentOrchestrator($modeloRemoto, $cliente, $pasos, new NullLogger(), null, $tablero, true);
        } elseif ($tablero !== null) {
            $orquestador = new AgentOrchestrator($modeloRemoto, $cliente, $pasos, new NullLogger(), null, $tablero);
        } else {
            $orquestador = new AgentOrchestrator($modeloRemoto, $cliente, $pasos, new NullLogger());
        }

        return $orquestador->run(
            $prompt,
            // LO QUE VIAJA DE VERDAD, no lo que el catálogo cree: el prompt se arma con los nombres
            // que este registro va a mandar, para que no ordene lo que no dio.
            $this->systemPrompt(array_map(
                static fn (\Milpa\ToolRuntime\ToolDefinition $d): string => $d->name,
                $registry->getToolDefinitions(),
            )),
            $history,
            $onStep,
        );
    }

    /**
     * The semantic-progress probe over THIS session's live stream, or `null` when it cannot exist:
     * no session (nothing to measure), no captured event store (nowhere to read or record), or an
     * installed ai-gateway/agent pair too old to declare the seam. Every guard fails toward the
     * byte-identical default path — a run without the probe is exactly the run 0.100.0 shipped.
     */
    private function progressProbe(): ?SessionProgressProbe
    {
        if ($this->sesionDeLosPermisos === null || $this->sessionEvents === null) {
            return null;
        }
        if (!interface_exists(ProgressProbe::class) || !class_exists(ProgressReceipt::class)) {
            return null;
        }

        return new SessionProgressProbe($this->sessionEvents, $this->sesionDeLosPermisos);
    }

    /**
     * Builds the ConsentBridge that gates and records every tool call `ask()` originates.
     *
     * This is the one construction, named: a caller that needs the same governed door — the
     * gate, the session's grants, the recorder, the option table, and who is executing — builds it
     * this way rather than reproducing the wiring inline (greenhouse recipe:apply, task 1).
     */
    private function governedExecutor(
        ToolRegistry $registry,
        ?ToolCallGate $gate,
        ?ToolCallRecorder $recorder,
        ?OptionTable $mesa,
    ): ConsentBridge {
        return new ConsentBridge(
            $registry,
            $this->grantsDeLaSesion(),
            $gate,
            $recorder ?? ($gate instanceof ToolCallRecorder ? $gate : null),
            $mesa,
            executions: $gate instanceof ExecutionRecorder ? $gate : null,
            // WHO IS RUNNING, OBSERVED HERE AND WRITTEN ONCE.
            //
            // The expression looks like the one in `grantsDeLaSesion()`, and the difference is
            // everything. There the environment is read to REBUILD an authority somebody else already
            // granted, which makes an old fact change author depending on who reads it. Here it is
            // read to DECLARE who is materialising the effect now, and it is written down once. Same
            // reading, different moment, different destination (greenhouse evidence/0209,
            // decisions/0037).
            executor: new ObservedExecutor(
                Principal::fromTerminal(getenv('USER') ?: null, gethostname() ?: null),
                ObservedExecutor::TERMINAL,
            ),
            // THE SAME SEAM THE GATE CARRIES (greenhouse decisions/0183): the bridge observes the
            // consent frontier, so its signals land in the session whose grants it holds. Without
            // a session there is no stream to observe into, and the seam stays silent by
            // construction — never by accident.
            debtSignals: $this->sesionDeLosPermisos === null
                ? null
                : new DebtSignal($this->sessionEvents, $this->sesionDeLosPermisos),
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
        // Presupuesto de tokens de la ventana sin resumir. El default supone un modelo de ~32k y deja
        // espacio para el catálogo + el system prompt + la respuesta; un modelo de ventana grande lo
        // sube por config. Sin esto la compactación sólo cuenta turnos y la ventana revienta a media
        // jornada (greenhouse evidence/0436).
        $maxTokens = 16000;
        if (\is_array($ajustes)) {
            $maximo = \is_int($ajustes['maxTurns'] ?? null) ? $ajustes['maxTurns'] : $maximo;
            // `keepLast` ES EL NOMBRE DEL CONTRATO, y este puente leía `keepRecent`.
            //
            // `keepRecent` es el parámetro del `Compactor` —legítimo en su librería— y este renglón lo
            // usaba para leer la CONFIGURACIÓN, cuyo nombre `AgentKeys` declara como `keepLast` y
            // `coa config` le imprime a quien configura la app. Así que la llave documentada no hacía
            // nada y `keepRecent` se quedaba en 12.
            //
            // No era cosmético: `shouldCompact()` exige `maxTurns > keepRecent`, de modo que bajar
            // `maxTurns` por debajo de 12 APAGABA LA COMPACTACIÓN EN SILENCIO. Medido sobre ganado
            // (greenhouse evidence/0218): con la llave documentada, cero compactaciones en catorce
            // turnos; con la que se leía, cuatro. Nada falla y nada avisa — la ventana crece hasta que
            // el proveedor la rechaza, que es la muerte a media jornada que este código evita.
            //
            // Gana el nombre público (greenhouse decisions/0038): cambiarlo para acomodar la
            // implementación sería dejar que el defecto legisle. Y NO se aceptan las dos: dos
            // ortografías para una decisión terminan siendo dos contratos (evidence/0141).
            $recientes = \is_int($ajustes['keepLast'] ?? null) ? $ajustes['keepLast'] : $recientes;
            $maxTokens = \is_int($ajustes['maxTokens'] ?? null) ? $ajustes['maxTokens'] : $maxTokens;
        }

        // The WHOLE-WINDOW budget: the model's declared context, resolved with the same precedence
        // as the endpoint itself — config first, environment fallback — by the one resolver that
        // owns that precedence ({@see AgentEndpoint::contextTokens()}). Absent everywhere it is
        // `null`, and `null` is yesterday's construction byte-for-byte. Measured need: greenhouse
        // evidence/0443 — a 32,768-token model re-entered at 35.6k because only the turn tail had
        // a budget and nothing bounded the summary's system side.
        $contexto = AgentEndpoint::contextTokens($config instanceof Config ? $config : null);

        return new Compactor(
            maxTurns: $maximo,
            keepRecent: $recientes,
            maxTokens: $maxTokens,
            windowBudget: $contexto,
        );
    }

    /**
     * El almacén de sesiones de esta app, para quien lo necesite desde fuera.
     *
     * Existe para que {@see SessionOperations} lea y escriba EXACTAMENTE donde esta operación lo hace:
     * dos lugares que decidan dónde viven las sesiones son dos lugares donde pueden dejar de
     * coincidir, y el día que lo hicieran `agent:answer` contestaría en una sesión que `agent` no
     * está leyendo.
     */
    /**
     * Who the house recognizes as this session's verified owner, re-verified live at the moment asked.
     *
     * The read half of identity (greenhouse decisions/0117): enrollment and ownership WRITE facts;
     * this READS the admission, so a surface can project «you are key:… with these scopes» or «system
     * user» without ever proclaiming a grade the house did not produce. The verdict is produced HERE,
     * each call, by re-verifying the stored assertion — never read from a stored flag (evidence/0254).
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, session?: string, verified?: bool, owner?: string|null, scopes?: list<string>, note?: string, error?: string}
     */
    private function ownerOf(array $input): array
    {
        $session = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        if ($session === '') {
            return ['ok' => false, 'error' => 'which session? `session` is required — agent:sessions lists them'];
        }

        $store = $this->sessionStore();
        $sesion = $store?->load($session);
        if ($sesion === null) {
            return ['ok' => false, 'error' => 'the session «' . $session . '» does not exist here'];
        }

        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        $assertion = $sesion->ownershipAssertion();
        [, $identity] = $kernel instanceof Kernel ? $this->policyAndIdentity($kernel->root()) : [null, null];

        if ($assertion === null || $identity === null) {
            return [
                'ok' => true, 'session' => $session, 'verified' => false, 'owner' => null, 'scopes' => [],
                'note' => 'the system user — no verified owner is recognized for this session',
            ];
        }

        $facts = $identity->admit($assertion, $session);
        if ($facts === null) {
            return [
                'ok' => true, 'session' => $session, 'verified' => false, 'owner' => null, 'scopes' => [],
                'note' => 'an ownership assertion exists but no principal is admitted — unrecognized, revoked, or the binding failed live',
            ];
        }

        return [
            'ok' => true, 'session' => $session,
            'verified' => $facts->verified, 'owner' => $facts->principal, 'scopes' => $facts->scopes,
        ];
    }

    /**
     * The app's declared PolicyProvider and the identity admission built on it — greenhouse
     * decisions/0058. Null provider when the app declares no `config/policy.php`, and then the gate
     * decides by the flag as it always did.
     *
     * @return array{0: ?\Milpa\AppRuntime\Policy\PolicyProvider, 1: ?SessionIdentity}
     */
    private function policyAndIdentity(string $root): array
    {
        $provider = PolicyConfig::load($root);
        // Admission exists when there is ANY basis for recognition: a declared PolicyProvider, or a
        // declared out-of-band root that enrollment consumes (greenhouse decisions/0117, evidence/0375).
        // Neither means the app opts out of identity, and the gate decides by the flag as it always did.
        $rooted = IdentityConfig::load($root);
        $enrollments = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        // Admission exists when there is ANY basis for recognition: a declared PolicyProvider, a declared
        // out-of-band root, OR standing enrollments — a key bootstrapped or enrolled into the store must be
        // admissible even with an empty config root and no policy (greenhouse decisions/0117, evidence/0384).
        $identity = ($provider === null && $rooted->isEmpty() && $enrollments->isEmpty()) ? null : new SessionIdentity(
            new GnupgSignatureVerifier(),
            $provider,
            $enrollments,
        );

        return [$provider, $identity];
    }

    /**
     * The trial router for this invocation, or `null` when trials are off or no sandbox is available.
     *
     * ON BY DEFAULT (greenhouse decisions/0072): a confinable mutation rehearses in a disposable copy
     * unless the app declares `agent.trialWorkspace` FALSE — the escape hatch. Absent means on; only an
     * explicit `false` turns it off. The default buys no authority: it changes WHERE the first attempt
     * happens, never what may run — the effects contract still fixes eligibility and `SessionPolicy`
     * still judges every call. And it fails closed: without an unprivileged namespace
     * ({@see TrialRunner::available()}) there is no trial, and the mutation asks for consent against its
     * declared ceiling as before — the default never invents a permissive path. Memoised so the gate
     * and the registry share ONE instance: the gate plans the call during composition, the executor
     * reuses that plan.
     */
    private function trialRouter(Kernel $kernel): ?TrialRouter
    {
        if ($this->trialRouterMemo !== false) {
            return $this->trialRouterMemo;
        }

        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declared = $config instanceof Config ? $config->get('agent.trialWorkspace') : null;
        // THE FLIP (decisions/0072): `!== false`, not `=== true`. Absent (nobody touched it) or true is
        // on; only an explicit `false` is the escape hatch — a good default is not a constitutional
        // obligation.
        if ($declared === false) {
            return $this->trialRouterMemo = null;
        }

        $runner = new TrialRunner();
        if (! $runner->available()) {
            return $this->trialRouterMemo = null;
        }

        return $this->trialRouterMemo = new TrialRouter($kernel->root(), $runner, \dirname(__DIR__, 2) . '/resources/trial-run.php');
    }

    /**
     * The ONE place a session's gate is built (greenhouse decisions/0059), so every collaborator —
     * the composed-ceiling producers most of all — reaches every gate. Both the main session and a
     * sub-agent pass through here; only the session, the human's petition, and the ordering
     * obligation vary, and they travel as arguments. A gate built any other way could omit a
     * producer and pass a green suite, which is exactly the wiring the pin's method caught
     * (evidence/0259).
     *
     * @param list<string> $runFirst the standing ordering obligation for THIS turn, or [] for none
     */
    private function nuevaCompuerta(
        SessionStore $store,
        Kernel $kernel,
        Session $session,
        string $petition,
        array $runFirst,
    ): SessionToolGate {
        [$policyProvider, $identity] = $this->policyAndIdentity($kernel->root());

        return new SessionToolGate(
            $store,
            $session,
            Operations::all($kernel, $kernel->root()),
            permissionWindow: $this->permissionWindow(),
            petition: $petition,
            vigiaDeBucle: $this->sterileLoopGuard(),
            compuertaPrevia: $runFirst === [] ? null : new PrerequisiteGate($runFirst),
            arrow: $this->foundationArrow(),
            policyProvider: $policyProvider,
            identity: $identity,
            trialRouter: $this->trialRouter($kernel),
            // THE INTERNAL PRODUCERS, so the gate judges the notebook and delegation by their declared
            // contracts instead of allowing them by name (greenhouse decisions/0078). Built from THIS
            // session's id, so a child spawned through this same builder governs its own internal tools.
            contractProducers: $this->contractProducers($store, $session->id),
            // THE DEBT-SIGNAL SEAM (greenhouse decisions/0183 primitive #5): the observation
            // channel writes through the SAME captured event store the closure verdict uses, so a
            // signal lands in the very stream it observes. With no reachable store the seam
            // degrades to silence — an observation must never break the observed run.
            debtSignals: new DebtSignal($this->sessionEvents, $session->id),
        );
    }

    /**
     * The authorized producers whose tools reach a gate through the registry's `$extra` and never
     * through `Operations::all()` — the session's own notebook and delegation.
     *
     * The gate consults them to RESOLVE a tool's contract and judge it (greenhouse decisions/0078):
     * `agent_message`/`agent:roles` read as allowed, `agent_spawn`/`agent_resume` finally enforce the
     * `requiresConfirmation` they always declared, and `plan`/`todo` pass as the session's own
     * self-legibility. Built here, in the one gate builder, so the main session and a sub-agent get the
     * same seam. The runner throws by construction: a gate RESOLVES contracts, it never runs a child.
     *
     * @return list<ContractProducer>
     */
    private function contractProducers(SessionStore $store, string $sessionId): array
    {
        $productores = [new SessionBookkeeping($store, $sessionId, $this->sessionEvents)];

        if (class_exists(SubAgentSpawner::class)) {
            $productores[] = new SubAgentSpawner(
                $store,
                $sessionId,
                static fn (): array => throw new \LogicException('a gate resolves contracts; it does not run a child'),
            );
        }

        return $productores;
    }

    /** The session store this app writes to, or null when it has nowhere to keep sessions. */
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

    /**
     * @param list<string> $herramientas los nombres que de verdad viajan en esta corrida
     */
    private function instruccionDePlan(array $herramientas): bool
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;

        if (($config instanceof Config ? $config->get('agent.planInstruction') : null) === false) {
            return false;
        }

        // NO LE ORDENES LO QUE NO LE DISTE.
        //
        // Estos tres renglones le mandan escribir un plan con `plan` y un pendiente con `todo`, y en
        // un app donde esas herramientas no se registran NO VIAJAN — medido en el cable, 28
        // herramientas y ninguna de las dos (greenhouse evidence/0172). El agente quedaba con una
        // orden imposible, y la medición lo describía como desobediente: cero planes en veinte
        // llamadas, cuando la causa era que no podía.
        //
        // Es MILPA-G002 del lado del agente: una instrucción no se cumple por estar escrita, y aquí
        // ni siquiera había con qué. Se calla, que es lo correcto y no lo cómodo — inventar las
        // herramientas para toda app es decisión de producto y no de este arreglo.
        return \in_array('plan', $herramientas, true) && \in_array('todo', $herramientas, true);
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

    /**
     * El observador de la entrada, cuando hay sesión donde apendarla.
     *
     * Devuelve `null` sin sesión, y eso NO es una degradación silenciosa: `agent:observe` distingue
     * «nadie grabó la entrada» de «no se le ofreció nada», así que una corrida sin observar se lee
     * como lo que es en vez de parecer un agente al que no le dieron herramientas.
     */
    protected function observadorDeEntrada(): ?IntakeObserver
    {
        $sesion = $this->intakeSession;
        if ($sesion === null) {
            return null;
        }

        $almacen = $this->sessions();

        return $almacen === null ? null : new IntakeObserver($almacen, $sesion, $this->declaredWindow);
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

        // EL ALMACÉN DE EVENTOS SE CAPTURA AL COMPONER, y en cada rama. Es lo que permite que el
        // veredicto de cierre apende su hecho al MISMO stream que la sesión escribe. La única rama
        // que no puede capturarlo es la del `SessionStore` ya construido sin `EventStoreInterface`
        // registrado: ahí el log no es alcanzable y el veredicto viaja sólo en el sobre — dicho
        // aquí, no descubierto depurando.
        $this->sessionEvents = null;

        if ($superficie !== null && $this->container->has(EventStoreInterface::class)) {
            $eventos = $this->container->get(EventStoreInterface::class);
            if ($eventos instanceof EventStoreInterface) {
                $puenteado = new BroadcastingEventStore($eventos, $superficie);
                $this->sessionEvents = $puenteado;

                return new SessionStore($puenteado);
            }
        }

        if ($this->container->has(SessionStore::class)) {
            $declarado = $this->container->get(SessionStore::class);
            if ($declarado instanceof SessionStore) {
                if ($this->container->has(EventStoreInterface::class)) {
                    $eventos = $this->container->get(EventStoreInterface::class);
                    if ($eventos instanceof EventStoreInterface) {
                        $this->sessionEvents = $eventos;
                    }
                }

                return $declarado;
            }
        }

        if ($this->container->has(EventStoreInterface::class)) {
            $eventos = $this->container->get(EventStoreInterface::class);
            if ($eventos instanceof EventStoreInterface) {
                $this->sessionEvents = $eventos;

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

        $archivo = $this->conPuente(new FileEventStore($directorio . '/agent-sessions.jsonl'));
        $this->sessionEvents = $archivo;

        return new SessionStore($archivo);
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

    /**
     * EL PULSO DEL MODELO — el cable que el LlmService late por cada trozo REAL que llega.
     *
     * Con una superficie viva (la TUI registrada como `SurfaceBroadcaster`), devuelve un closure que
     * le empuja un hecho `activity` por chunk: la pantalla avanza un cuadro del spinner por evento,
     * no por reloj (greenhouse evidence/0307, promesa `tui-says-what-it-is-doing`). *Si el modelo se
     * cuelga y no llega ningún trozo, no late — que es exactamente lo honesto.* Sin superficie —un
     * `coa agent` de script, sin humano mirando— devuelve null y el LlmService no streamea.
     *
     * Se throttlea a ~12 fps: a ~65 tokens/s un repintado por token es trabajo de más sin cuadro
     * nuevo que se note. El primer trozo SIEMPRE late (saca el estado del «preguntando…» inicial).
     *
     * @return (\Closure(string): void)|null
     */
    /**
     * ¿El `LlmService` instalado admite el pulso por chunk? `onStreamChunk` llegó en una versión
     * posterior de `milpa/ai-gateway` —una capacidad opcional cuya versión esta app no fija—, así
     * que se lee el constructor real: si declara el parámetro, se cablea el streaming; si no, la app
     * corre sin él en vez de reventar (born-green, degradar no romper).
     */
    private function llmServiceAdmiteStreaming(): bool
    {
        $constructor = (new \ReflectionClass(LlmService::class))->getConstructor();
        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parametro) {
            if ($parametro->getName() === 'onStreamChunk') {
                return true;
            }
        }

        return false;
    }

    private function progresoDelModelo(): ?\Closure
    {
        $superficie = $this->broadcaster();
        if ($superficie === null) {
            return null;
        }

        $ultimo = 0.0;

        return static function (string $pieza) use ($superficie, &$ultimo): void {
            $ahora = microtime(true);
            if ($ahora - $ultimo < 0.08) {
                return;
            }
            $ultimo = $ahora;
            $superficie->broadcast('progress', [
                'kind' => 'activity',
                'activity' => ['state' => 'thinking'],
            ]);
        };
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
    /**
     * @param list<string> $herramientas los nombres que de verdad viajan en esta corrida
     */
    /**
     * Load one skill's body for the agent to follow. Blocks skills the author barred from the model
     * (`disable-model-invocation: true`): the tool the agent calls must honour that flag, or the bar
     * is decorative.
     *
     * @return array{ok: bool, name?: string, body?: string, error?: string}
     */
    /**
     * Project every skill this app carries, for a surface to show. Reads only — the invocation flags
     * come from the backend, never computed by a client that must not decide them.
     *
     * @return array{ok: bool, skills: list<array{name: string, description: string, modelInvocable: bool, userInvocable: bool, bodyChars: int}>}
     */
    /**
     * Project the specialist roles this app declares (`.milpa/agents/*.md`), each with the skills it
     * preloads. Reads only. A role NAMES authority that already governs (deny/first/produces); the
     * skills it lists only suggest — the runtime injects them, it does not govern by them.
     *
     * @return array{ok: bool, roles: list<array<string, mixed>>}
     */
    /**
     * Compose a specialist role from the UI: write `.milpa/agents/<name>.md`. A role without a brief
     * is refused — restrictions with no prose are a muzzle wearing a name, not a specialist (the same
     * invariant {@see AgentRole} enforces at construction).
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, name?: string, path?: string, error?: string}
     */
    private function declareRole(array $input): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return ['ok' => false, 'error' => 'no kernel: roles are written under the app root'];
        }

        $name = strtolower(trim((string) ($input['name'] ?? '')));
        if ($name === '' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name) !== 1) {
            return ['ok' => false, 'error' => 'name must be lowercase letters, numbers and single hyphens'];
        }
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            return ['ok' => false, 'error' => 'a role needs a brief: restrictions without a prompt are a muzzle, not a specialist'];
        }

        $list = static function (mixed $v): array {
            $items = \is_array($v) ? $v : (\is_string($v) ? explode(',', $v) : []);

            return array_values(array_filter(array_map(static fn ($x): string => trim((string) $x), $items), static fn (string $x): bool => $x !== ''));
        };
        $skills = $list($input['skills'] ?? null);
        $deny = $list($input['deny'] ?? null);
        $produces = trim((string) ($input['produces'] ?? ''));

        $front = "---\nname: {$name}\n";
        if ($produces !== '') {
            $front .= "produces: {$produces}\n";
        }
        if ($skills !== []) {
            $front .= 'skills: ' . implode(', ', $skills) . "\n";
        }
        if ($deny !== []) {
            $front .= 'deny: ' . implode(', ', $deny) . "\n";
        }
        $front .= "---\n";

        $dir = $kernel->root() . '/.milpa/agents';
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => "could not create {$dir}"];
        }
        $path = "{$dir}/{$name}.md";
        if (@file_put_contents($path, $front . $prompt . "\n") === false) {
            return ['ok' => false, 'error' => "could not write {$path}"];
        }

        return ['ok' => true, 'name' => $name, 'path' => ".milpa/agents/{$name}.md"];
    }

    /** A RoleRegistry loaded with this app's roles (`.milpa/agents/*.md`) — the spawner delegates by these. */
    private function rolesRegistry(): RoleRegistry
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        $registry = new RoleRegistry();
        if ($kernel instanceof Kernel) {
            $registry->loadFrom($kernel->root() . '/.milpa/agents');
        }

        return $registry;
    }

    /** A SkillRegistry rooted at this app, so a spawned role can be born with its skills' bodies. */
    private function skillsRegistryForSpawn(): SkillRegistry
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;

        return new SkillRegistry($kernel instanceof Kernel ? $kernel->root() : '');
    }

    /** @return array{ok: bool, roles: list<array<string, mixed>>} */
    private function listRoles(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return ['ok' => false, 'roles' => []];
        }

        $registry = new RoleRegistry();
        $registry->loadFrom($kernel->root() . '/.milpa/agents');

        return ['ok' => true, 'roles' => array_map(static fn (AgentRole $r): array => $r->toArray(), $registry->all())];
    }

    /** @return array{ok: bool, skills: list<array<string, mixed>>} */
    private function listSkills(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return ['ok' => false, 'skills' => []];
        }

        $skills = array_map(static fn (Skill $s): array => [
            'name' => $s->name,
            'description' => $s->description,
            'modelInvocable' => $s->modelInvocable,
            'userInvocable' => $s->userInvocable,
            'bodyChars' => \strlen($s->body),
        ], (new SkillRegistry($kernel->root()))->all());

        return ['ok' => true, 'skills' => $skills];
    }

    /** @return array{ok: bool, name?: string, body?: string, error?: string} */
    private function loadSkill(string $name): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return ['ok' => false, 'error' => 'no kernel: skills are read from the app root'];
        }

        $skill = (new SkillRegistry($kernel->root()))->get($name);
        if ($skill === null) {
            return ['ok' => false, 'error' => "unknown skill: {$name}"];
        }
        if (!$skill->modelInvocable) {
            return ['ok' => false, 'error' => "skill '{$name}' is user-invocable only; ask the human to run it"];
        }

        // Wrap the body the way the reference harnesses do: name it, and hand the agent the skill's
        // base directory so it can reach any bundled scripts/ or references/ this skill ships.
        $resources = $skill->directory !== ''
            ? "<skill_resources>Base directory for this skill: {$skill->directory}</skill_resources>\n"
            : '';
        $wrapped = "<skill_content name=\"{$skill->name}\">\n{$resources}{$skill->body}\n</skill_content>";

        return ['ok' => true, 'name' => $skill->name, 'body' => $wrapped];
    }

    /** @param list<string> $herramientas */
    protected function systemPrompt(array $herramientas = []): string
    {
        $partes = [
            'You are the agent of this Milpa app. Use the tools to answer; do not invent results. If '
            . 'a tool answers with `guidance`, that guidance is the real next step: repeat it instead of '
            . 'improvising one.',

            // Lo que un agente necesita para no inventar un plugin donde había una línea de config.
            "How this app is built:\n"
            . "- Everything it can do is a declared operation; the tools you see ARE those operations.\n"
            . "- Plugins are declared in `config/plugins.php`. Scaffolding one does NOT enable it: you must add its class to that list.\n"
            . "- Persistence comes from `config/app.php`, the `storage` block: `driver` is `file`, `sqlite`, `mysql` or `memory`\n"
            . "  (with its `path` or its `dsn`). What `make entity` and `make crud` write already reads that block through\n"
            . "  `Milpa\\Data\\RepositoryFactory`, so switching backend is that one line and nothing more. You do NOT need a\n"
            . "  persistence plugin, and none exists.\n"
            . "- Doctrine belongs to the legacy convention, not this one. The entities `make` writes implement\n"
            . '  `Milpa\\Data\\EntityInterface`: no ORM attributes, no mapping.',

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
        if ($this->instruccionDePlan($herramientas)) {
            $partes[] = "When the work takes more than two or three steps:\n"
                . "- Write a plan with `plan` BEFORE you start, and add a todo with `todo` for each part.\n"
                . "- Mark a `todo` `done` AS SOON AS you finish each one, not at the end.\n"
                . '- If a session already carries a plan and todos, follow those instead of writing new ones: they are yours, from before.';
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
            $partes[] = "What this app has installed:\n- " . implode("\n- ", $puesto);
        }

        // Skills — non-deterministic guidance the agent reaches for by judgment, not tools it runs.
        // Only the model-invocable ones are advertised: a skill barred from the model
        // (`disable-model-invocation`) is withheld here so the agent never reaches for it.
        $kernelSkills = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if ($kernelSkills instanceof Kernel) {
            $skills = (new SkillRegistry($kernelSkills->root()))->modelInvocable();
            if ($skills !== []) {
                $lineas = array_map(static fn (Skill $s): string => "- {$s->name}: {$s->description}", $skills);
                $partes[] = "<system-reminder> A skill is a reusable set of task-specific instructions. "
                    . "The following skills are available in this session:\n<available_skills>\n"
                    . implode("\n", $lineas)
                    . "\n</available_skills>\n"
                    . "When a skill matches the task, call `skill:load` with its name, read its instructions, "
                    . "and follow them before you act. </system-reminder>";
            }
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
        // La precedencia vive en UN lugar (greenhouse evidence/0166): estaba escrita aquí y otra vez
        // en el banner del chat, y las dos copias no coincidían.
        return AgentEndpoint::baseUrl(
            $this->container->has(Config::class) && ($c = $this->container->get(Config::class)) instanceof Config ? $c : null,
        );
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
                    AgentEndpoint::model($config instanceof Config ? $config : null) ?? 'qwen3-coder:30b',
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
            // RETIRAR DE LA MESA QUE EXISTE, no del catálogo entero.
            //
            // Medido sobre ganado: esto declaraba TRECE retiros mientras OCHO herramientas dejaban de
            // viajar, porque iteraba todas las operaciones y apendaba un retiro por cada una que
            // casara — incluidas las que nunca estuvieron en la mesa del agente. Quien leyera la
            // observación entendía que el agente había perdido trece capacidades (greenhouse
            // evidence/0198).
            //
            // La regla ya existía y estaba partida en dos; aquí no se llamaba ninguna de las dos. No
            // era un problema de orden: era la misma app decidiendo dos veces qué está en la mesa.
            if (!AgentTable::offers($op)) {
                continue;
            }

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
     * @param list<Operation>      $extra        operations that exist only for this run — today, the
     *                                           ones tying the plan and the pending items to the session
     *                                           in flight
     * @param list<Operation>|null $declarations out: the exact operations selected for projection;
     *                                           null when the caller does not inspect provenance
     *
     * @param-out list<Operation> $declarations
     */
    private function toolsOfThisApp(
        array $extra = [],
        bool $soloLectura = false,
        bool $registroPropio = false,
        ?array &$declarations = null,
    ): ?ToolRegistry {
        $declarations = [];
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
        // LA MISMA REGLA, LLAMADA. La lista vivía aquí y quien resolvía el retiro no se enteraba,
        // que es como una app termina decidiendo dos veces qué está en su mesa (greenhouse
        // evidence/0198). El proyector sigue aplicando el opt-in de superficie más abajo; esto sólo
        // deja de ser la segunda copia de la mitad que faltaba.
        $todas = array_values(array_filter($todas, static fn ($op): bool => AgentTable::offers($op)));
        $declarations = $todas;

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
