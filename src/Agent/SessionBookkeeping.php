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

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\Evidence;
use Milpa\Agent\EvidenceKind;
use Milpa\Agent\SessionFacts;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
/**
 * Las herramientas con las que el agente escribe su propio plan y mueve sus pendientes (P16.3).
 *
 * ── ATADAS A LA SESIÓN, NO PARAMETRIZADAS POR ELLA ──────────────────────────────────────────────
 *
 * El identificador de la sesión se CAPTURA al construirlas; no es un argumento que el modelo pase.
 * Si lo fuera, un modelo confundido podría escribir el plan de otra sesión —o el de una que no es
 * suya— y eso no es una equivocación recuperable: quien lea esa sesión mañana verá un plan que su
 * agente nunca escribió. Un identificador que el modelo no puede nombrar es uno que no puede errar.
 *
 * Por lo mismo sólo existen CUANDO hay sesión: sin ella no habría dónde apendar, y una herramienta
 * que acepta y no guarda es peor que una ausente — el modelo la llama, la ve contestar «ok» y sigue
 * adelante creyendo que dejó un plan.
 *
 * ── POR QUÉ NO PASAN POR LA COMPUERTA DE PERMISOS ───────────────────────────────────────────────
 *
 * Declaran `mutating: true` porque apendan, y mentir sobre eso sería lo peor que puede hacer una
 * declaración. Pero {@see SessionToolGate} las deja pasar sin preguntar, a propósito: pedir permiso
 * para anotar un plan es pedir permiso para ser legible. El efecto de estas herramientas es
 * exclusivamente sobre la bitácora de la propia sesión —no tocan un archivo, una base ni un plugin—
 * y una compuerta que se pide también para eso se aprueba sin leer, que es como se pierde la que sí
 * importaba.
 */
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;

final readonly class SessionBookkeeping implements ContractProducer
{
    public function __construct(
        private SessionStore $sessions,
        private string $sessionId,
        // THE SESSION'S OWN STREAM, for JUDGING claims (greenhouse decisions/0183). Optional because
        // not every construction site can reach it: without it the notebook still declares and
        // gates identically, and `work:claim-verified` FAILS CLOSED — it refuses to judge what it
        // cannot read, instead of taking the model's word for the work.
        private ?EventStoreInterface $events = null,
        // Which calls last, by their own declaration ({@see LastingCalls}, greenhouse decisions/0523): the claim
        // door reads the house's closure through the same function, with the same reading, as the final answer.
        private ?\Closure $lasting = null,
    ) {
    }

    /**
     * Los nombres que {@see SessionToolGate} no gatea.
     *
     * Vive aquí, junto a las operaciones, para que agregar una obligue a verla en esta lista. En la
     * compuerta se leería como una lista de excepciones sin dueño.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return ['plan', 'todo', 'work:claim-verified'];
    }

    /**
     * SUMMARY: The contract this notebook declares for `$tool` — the `plan`/`todo` {@see Operation}
     * with its benign self-log `EffectProfile` — or `null` for anything it does not own.
     *
     * This is how {@see SessionToolGate} reaches the profile these operations declare (evidence/0189)
     * without them being in `Operations::all()`: the gate resolves the contract from HERE, the
     * authorized producer, and judges THAT. Their profile is self-legibility (no externality, no
     * authority, subject Data), so the gate lets them pass — by the contract, not by their name.
     */
    public function contractFor(string $tool): ?Operation
    {
        foreach ($this->operations() as $operacion) {
            if (McpProjector::toolName($operacion->name) === $tool) {
                return $operacion;
            }
        }

        return null;
    }

    /**
     * El cuaderno de ESTA sesión como operaciones: `plan` y `todo`, atadas a su id.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'plan',
                scopes: ['agent:run'],
                description: 'Escribe o reemplaza el plan de trabajo de esta sesión. Hazlo ANTES de empezar algo largo',
                handler: fn (array $input): array => $this->escribirPlan($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'plan' => ['type' => 'string', 'description' => 'El plan, en pasos'],
                    ],
                    'required' => ['plan'],
                ],
                mutating: true,
                // ── EL CUADERNO DICE LO QUE ES, y por eso la compuerta lo deja pasar ────────────
                //
                // Esto llevaba tiempo escrito arriba, en prosa: «pedir permiso para anotar un plan es
                // pedir permiso para ser legible». Nunca fue DATO, y S2 —que exige consentimiento a
                // todo lo que nadie clasificó, porque premiar el silencio volvería opcional cualquier
                // techo (greenhouse decisions/0028)— las alcanzaba. El agente NO PODÍA PLANEAR, que
                // es lo único que la autoridad declaró no negociable.
                //
                // Se clasifica en vez de eximir. `SessionToolGate` ya las exime POR NOMBRE, y una
                // compuerta que se abre por lista de nombres deja de ser una regla y se vuelve un
                // directorio: el siguiente que necesite lo mismo pedirá que lo agreguen. Declarado el
                // perfil, S2 deja de alcanzarlas POR LA REGLA, y cualquier bitácora futura hereda el
                // trato sin que nadie toque nada.
                //
                // `Compensatable` y no `Guaranteed`: un pendiente se mueve o se reemplaza, pero un
                // apéndice no se des-apenda. `Guaranteed` es el único nivel que compra MENOS
                // escrutinio y habría sido la mentira cómoda (greenhouse evidence/0189).
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Compensatable,
                    authority: Authority::None,
                    subject: Subject::Data,
                ),
                // Fuera de HTTP: escriben en la sesión que está corriendo, y no hay una corriendo
                // detrás de una petición web.
                surfaces: ['mcp'],
            ),
            new Operation(
                name: 'todo',
                scopes: ['agent:run'],
                // NO INVITATION TO ASSERT (greenhouse decisions/0183): this tool used to say «mark
                // done as soon as you finish» — the invitation to claim without evidence, printed in
                // the model's contract. Finishing is now a CLAIM, and it has its own door.
                description: 'Add a pending item or move one that already exists. Closing as done does not live here: claim it via work:claim-verified with the evidence that backs it',
                handler: fn (array $input): array => $this->pendiente($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'What needs doing'],
                        'id' => ['type' => 'string', 'description' => 'El de uno que ya existe, para moverlo'],
                        'replaces' => ['type' => 'string', 'description' => 'The id of the item this one supersedes, when you reword one that already existed'],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['pending', 'in_progress', 'blocked'],
                            'description' => 'Where it stands; `pending` when unsaid. There is no `done` here: a finished item is claimed via work:claim-verified',
                        ],
                    ],
                    'required' => [],
                ],
                mutating: true,
                // ── EL CUADERNO DICE LO QUE ES, y por eso la compuerta lo deja pasar ────────────
                //
                // Esto llevaba tiempo escrito arriba, en prosa: «pedir permiso para anotar un plan es
                // pedir permiso para ser legible». Nunca fue DATO, y S2 —que exige consentimiento a
                // todo lo que nadie clasificó, porque premiar el silencio volvería opcional cualquier
                // techo (greenhouse decisions/0028)— las alcanzaba. El agente NO PODÍA PLANEAR, que
                // es lo único que la autoridad declaró no negociable.
                //
                // Se clasifica en vez de eximir. `SessionToolGate` ya las exime POR NOMBRE, y una
                // compuerta que se abre por lista de nombres deja de ser una regla y se vuelve un
                // directorio: el siguiente que necesite lo mismo pedirá que lo agreguen. Declarado el
                // perfil, S2 deja de alcanzarlas POR LA REGLA, y cualquier bitácora futura hereda el
                // trato sin que nadie toque nada.
                //
                // `Compensatable` y no `Guaranteed`: un pendiente se mueve o se reemplaza, pero un
                // apéndice no se des-apenda. `Guaranteed` es el único nivel que compra MENOS
                // escrutinio y habría sido la mentira cómoda (greenhouse evidence/0189).
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Compensatable,
                    authority: Authority::None,
                    subject: Subject::Data,
                ),
                surfaces: ['mcp'],
            ),
            new Operation(
                name: 'work:claim-verified',
                scopes: ['agent:run'],
                // THE CONTRACT SAYS WHICH REFERENCE THE SESSION HOLDS (greenhouse evidence/1116). The judge compares
                // the reference letter by letter against identifiers of the session's own record, and only its
                // refusal used to say which: the first round of claims was written as prose and refused, every
                // time it was measured. Said here it costs no call. It is said by rule, not as a list of what the
                // session holds now: a contract that changed as the session worked would change the catalogue
                // under the model at every step.
                description: 'Claim a todo as verified done: name the todo, the kind of evidence and the reference that backs it. The reference is one identifier this session\'s record already holds, copied exactly — the session judges the claim against its RECORDED facts, and a claim nothing covers is refused and the todo stays open',
                handler: fn (array $input): array => $this->claimVerified($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'todo' => ['type' => 'string', 'description' => 'The id of the todo this claim closes'],
                        'kind' => [
                            'type' => 'string',
                            'enum' => array_keys(self::HELD),
                            'description' => 'What kind of recorded evidence backs the claim',
                        ],
                        'reference' => ['type' => 'string', 'description' => self::whichReference()],
                    ],
                    'required' => ['todo', 'kind', 'reference'],
                ],
                mutating: true,
                // THE SAME SELF-LOG PROFILE AS plan/todo (greenhouse evidence/0189, decisions/0183):
                // the claim appends to this session's own stream and the judging READS it — nothing
                // leaves the app, nothing asks for privilege. Planning and claiming must never ask
                // permission; the gate that matters guards the world, not the notebook. What keeps
                // this honest is not a gate but the judge inside: an uncovered claim is refused.
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Compensatable,
                    authority: Authority::None,
                    subject: Subject::Data,
                ),
                surfaces: ['mcp'],
            ),
        ];
    }

    /**
     * WHICH REFERENCE THE SESSION HOLDS, KIND BY KIND — the rule {@see coveringFact()} judges by, with one example of
     * each that it accepts as written (a test claims with every example, so the two cannot drift apart).
     */
    private const HELD = [
        'test-passed' => 'the filter or path a `test` run of this session declared, when its last run is green, e.g. `BlogTest`',
        'operation-ok' => 'the name of a tool this session called and that answered ok, as it was called, e.g. `plugins_register`',
        'artifact-created' => 'one path a `sandbox_promote` of this session carried into the house, e.g. `src/Plugins/Blog/Blog.php`',
        'screen-served' => 'the route the house observed served and that still is, as the house wrote it — or the name of the screen `screen_observe` answered for —, e.g. `/blog`',
    ];

    /** What the contract says of `reference`: one identifier, and which one for each kind. */
    private static function whichReference(): string
    {
        $byKind = [];
        foreach (self::HELD as $kind => $held) {
            $byKind[] = $kind . ': ' . $held;
        }

        return 'One identifier, exactly as this session\'s record holds it and nothing else — not a sentence, no explanation '
            . 'after it. Which one, by kind — ' . implode('; ', $byKind) . '.';
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function escribirPlan(array $input): array
    {
        $plan = \is_string($input['plan'] ?? null) ? trim($input['plan']) : '';
        if ($plan === '') {
            return ['ok' => false, 'error' => 'falta `plan`: qué vas a hacer'];
        }

        $this->sessions->setPlan($this->sessionId, $plan);

        return ['ok' => true, 'session' => $this->sessionId, 'plan' => $plan];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function pendiente(array $input): array
    {
        $sesion = $this->sessions->load($this->sessionId);
        if ($sesion === null) {
            return ['ok' => false, 'error' => 'esta sesión ya no existe'];
        }

        // THE CORRECTING ERROR, never silence (greenhouse decisions/0183): a model that still asks
        // for `done` here gets told where done now lives, and nothing moves. Checked on the RAW
        // input — the enum no longer offers it, but an insistent caller is answered, not ignored.
        if (($input['status'] ?? null) === 'done') {
            return [
                'ok' => false,
                'error' => '`done` is not this tool\'s to write (greenhouse decisions/0183): claim it '
                    . 'via work:claim-verified with the todo id, the kind of evidence that backs it '
                    . '(test-passed | operation-ok | artifact-created | screen-served) and the '
                    . 'reference to re-check',
            ];
        }

        $id = \is_string($input['id'] ?? null) ? trim($input['id']) : '';
        $texto = \is_string($input['text'] ?? null) ? trim($input['text']) : '';
        $estado = TodoStatus::tryFrom(\is_string($input['status'] ?? null) ? $input['status'] : '');

        if ($id !== '') {
            // Mover uno que existe. El TEXTO se conserva si no se manda otro: un `todo` que sólo venía
            // a marcar `done` no puede borrar la descripción de lo que se hizo, que es lo que después
            // aparece en el resumen al compactar.
            foreach ($sesion->todos as $todo) {
                if ($todo->id === $id) {
                    $actualizado = new Todo($id, $texto !== '' ? $texto : $todo->text, $estado ?? $todo->status);
                    $this->sessions->setTodo($this->sessionId, $actualizado);

                    return ['ok' => true, 'todo' => $actualizado->toArray()];
                }
            }

            return ['ok' => false, 'error' => "no existe el pendiente «{$id}» en esta sesión"];
        }

        if ($texto === '') {
            return ['ok' => false, 'error' => 'falta `text` para uno nuevo, o `id` para mover uno que ya existe'];
        }

        // El id lo pone la app y no el modelo: uno inventado puede chocar con otro y sobrescribir un
        // pendiente ajeno, y ese choque se ve como un pendiente que cambió de texto solo.
        // WHICH ONE THIS SUPERSEDES, and only when that card really exists.
        //
        // VALIDATED rather than believed: a made-up id closing somebody else's card would be the
        // same collision the generated ids above already avoid. And when it does not exist it is
        // said out loud — swallowing it would leave the agent believing it replaced something.
        $supersedes = \is_string($input['replaces'] ?? null) ? trim($input['replaces']) : '';
        $found = false;
        foreach ($sesion->todos as $t) {
            if ($t->id === $supersedes) {
                $found = true;

                break;
            }
        }
        if ($supersedes !== '' && !$found) {
            return ['ok' => false, 'error' => "there is no item «{$supersedes}» for this one to supersede"];
        }

        $nuevo = new Todo(
            't' . (\count($sesion->todos) + 1),
            $texto,
            $estado ?? TodoStatus::Pending,
            replaces: $supersedes !== '' ? $supersedes : null,
        );
        $this->sessions->setTodo($this->sessionId, $nuevo);

        return ['ok' => true, 'todo' => $nuevo->toArray()];
    }

    /**
     * SUMMARY: Judge a claim of finished work against the session's RECORDED facts, and complete
     * the todo only when a covering fact exists (greenhouse decisions/0183).
     *
     * Deterministic by construction: nothing re-runs, nothing touches the filesystem — the judge
     * reads only what the stream already holds, through milpa/agent's own projections
     * ({@see SessionFacts}). No covering fact refuses and the todo does not move; a recorded RED
     * verdict for the reference refuses naming the red; a covering green fact goes through
     * {@see SessionStore::completeTodo()} — the store's only door to `done` — and the answer says
     * WHAT evidence closed it.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function claimVerified(array $input): array
    {
        $sesion = $this->sessions->load($this->sessionId);
        if ($sesion === null) {
            return ['ok' => false, 'error' => 'esta sesión ya no existe'];
        }

        $todoId = \is_string($input['todo'] ?? null) ? trim($input['todo']) : '';
        $kindRaw = \is_string($input['kind'] ?? null) ? trim($input['kind']) : '';
        $reference = \is_string($input['reference'] ?? null) ? trim($input['reference']) : '';
        $kind = match ($kindRaw) {
            'test-passed' => EvidenceKind::TestPassed,
            'operation-ok' => EvidenceKind::OperationOk,
            'artifact-created' => EvidenceKind::ArtifactCreated,
            'screen-served' => EvidenceKind::ScreenServed,
            default => null,
        };

        if ($todoId === '' || $kind === null || $reference === '') {
            return [
                'ok' => false,
                'error' => '`todo`, `kind` and `reference` are all required: the todo id, the kind '
                    . 'of evidence (test-passed | operation-ok | artifact-created | screen-served) '
                    . 'and the reference that backs the claim',
            ];
        }

        $tarjeta = null;
        foreach ($sesion->todos as $t) {
            if ($t->id === $todoId) {
                $tarjeta = $t;

                break;
            }
        }
        if ($tarjeta === null) {
            // THE REFUSAL TEACHES (greenhouse decisions/0482): 27 claims named a todo that does not exist
            // («screen», «turn:61»); the open ones are named so the next claim is exact.
            $open = array_map(
                static fn ($t): string => $t->id . ' «' . $t->text . '»',
                array_values(array_filter($sesion->todos, static fn ($t): bool => $t->status !== TodoStatus::Done)),
            );

            // A session that never opened a todo needs none once the HOUSE derived its closure (greenhouse
            // decisions/0487, evidence/1022): the advice below would send it to open a todo only to close it,
            // and in the epilogue that ritual spent the budget the final answer needed.
            //
            // ONE RULE ON EVERY PATH (greenhouse decisions/0523, deferred by 0517): the door asks the leg's own
            // closure — {@see LegClosure}, what the final answer records and the epilogue opens on — not a second
            // reading of it. Between steps a declared delivery's verdict is not known yet (null), and the door
            // then says nothing of the house.
            if ($sesion->todos === [] && $this->events !== null) {
                $closure = LegClosure::betweenSteps($sesion, $this->stream(), $this->lasting);
                $subject = $closure['derivedFrom']['observation']['subject'] ?? null;
                if (($closure['verified'] ?? false) === true && \is_string($subject)) {
                    return ['ok' => false, 'error' => "there is nothing to claim: this session has no todos, and the house "
                        . "already closed it on its own observation of «{$subject}» served in the house after your last "
                        . 'change. Do not open a todo to close it — give your final answer'];
                }
            }

            // With none open, the refusal names the fix, not only the fault (evidence/1017): 7 claims on fresh cattle
            // were made by sessions that never opened a todo, and each was refused without saying what to do.
            return ['ok' => false, 'error' => "there is no todo «{$todoId}» in this session to claim"
                . ($open === []
                    ? '; this session has no open todo — a claim closes one: add it first with the todo tool (text: what you did), then claim its id with the evidence that backs it'
                    : '; its open todos are: ' . implode(', ', $open))];
        }
        if ($tarjeta->status === TodoStatus::Done) {
            return ['ok' => false, 'error' => "«{$todoId}» is already done: there is nothing left to claim"];
        }

        if ($this->events === null) {
            return [
                'ok' => false,
                'error' => 'this claim cannot be judged: no session stream is reachable from here, '
                    . 'and a claim nobody can check is refused rather than believed (greenhouse '
                    . 'decisions/0183)',
            ];
        }

        // THE STREAM IS READ ONCE PER CLAIM (greenhouse decisions/0509 §6). Measured (evidence/1036): a claim with
        // a prose reference walked the candidates of its refusal, and every candidate read the whole event file
        // again — the verification, the green test, the promotions, and a second SessionFacts — until a 21 MB
        // ledger took four legs past PHP's 128 MB, each dying with no termination event. The judge below asks
        // this one list everything it asks.
        $stream = $this->stream();
        $facts = SessionFacts::fromEvents($this->sessionId, $stream);

        // A recorded RED verdict for the reference refuses the claim outright, whatever the kind:
        // claiming done over a red is exactly the assertion this door exists to stop.
        $verdict = $facts->lastVerificationOf($reference);
        if (($verdict['ok'] ?? false) === true && ($verdict['verification']['verified'] ?? true) === false) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'the claim is refused: the last recorded verification verdict for «%s» is RED '
                    . '(operation %s, seq %d). Fix it and re-verify before claiming',
                    $reference,
                    \is_string($verdict['verification']['operation'] ?? null) ? $verdict['verification']['operation'] : '?',
                    \is_int($verdict['verification']['seq'] ?? null) ? $verdict['verification']['seq'] : 0,
                ),
            ];
        }

        $staleRefusal = null;
        $covering = $this->coveringFact($facts, $stream, $kind, $reference, $verdict, $staleRefusal);
        if ($covering === null) {
            if ($staleRefusal !== null) {
                return ['ok' => false, 'error' => $staleRefusal];
            }

            // THE REFUSAL TEACHES THE REFERENCE (greenhouse decisions/0482). Measured: 63% of claims refused,
            // most with a reference written as prose (a sentence about the screen instead of its name). The
            // judge stays exact; what it would accept is named, so the model copies instead of guessing.
            $candidates = $this->candidates($kind, $facts, $stream);

            return [
                'ok' => false,
                'error' => sprintf(
                    'no recorded fact covers the claim: looked for %s and found none. %s',
                    $this->lookedFor($kind, $reference),
                    $candidates === []
                        ? 'Nothing in this session is of that kind yet: run the work through the governed tools first — the stream is what gets judged, not the word'
                        : 'This session holds, exactly as a reference: ' . implode(', ', array_map(static fn (string $c): string => '«' . $c . '»', $candidates)) . ' — claim with one of them',
                ),
            ];
        }

        $this->sessions->completeTodo(
            $this->sessionId,
            $todoId,
            new Evidence('e' . (\count($sesion->evidence) + 1), $kind, $reference),
        );

        $despues = $this->sessions->load($this->sessionId);
        $cerrado = null;
        foreach ($despues->todos ?? [] as $t) {
            if ($t->id === $todoId) {
                $cerrado = $t;

                break;
            }
        }

        return [
            'ok' => true,
            'session' => $this->sessionId,
            'todo' => $cerrado?->toArray(),
            'evidence' => [
                'kind' => $kindRaw,
                'reference' => $reference,
                // WHAT closed it, so the answer teaches instead of merely confirming.
                'coveredBy' => $covering,
            ],
        ];
    }

    /**
     * SUMMARY: The recorded fact that covers a claim of `$kind` for `$reference`, or `null` when
     * nothing in the stream does — the fail-closed half of the judge.
     *
     * When a covering fact EXISTS but is refused for a reason more specific than «nothing covers it»
     * — a served receipt that went stale — the reason is written to `$refusal`, so the caller can
     * teach with it instead of the generic not-found message.
     *
     * @param list<Event>          $stream  the session's own stream, read once by the caller
     * @param array<string, mixed> $verdict the already-fetched {@see SessionFacts::lastVerificationOf()} answer
     * @param string|null          $refusal out: a specific refusal reason when one applies
     *
     * @return array<string, mixed>|null
     */
    private function coveringFact(SessionFacts $facts, array $stream, EvidenceKind $kind, string $reference, array $verdict, ?string &$refusal = null): ?array
    {
        if ($kind === EvidenceKind::TestPassed) {
            // A producer-declared verification verdict is the strongest fact the stream holds.
            if (($verdict['ok'] ?? false) === true && ($verdict['verification']['verified'] ?? false) === true) {
                return [
                    'fact' => 'verification',
                    'operation' => $verdict['verification']['operation'] ?? '?',
                    'seq' => $verdict['verification']['seq'] ?? 0,
                ];
            }

            // Or the last recorded `test` run DECLARING the reference as its filter or path — when that last run
            // is GREEN. A red run after a green one is the judge's last word, and a todo is not done against a
            // red judge (greenhouse decisions/0509 §4): the claim used to take the last green, whatever followed.
            $last = LastTestRun::of($stream, $reference);
            if ($last !== null && ! $last['green']) {
                $refusal = sprintf(
                    'the claim is refused: the last test run declaring «%s» is RED (seq %d). Fix it and run it green before claiming',
                    $reference,
                    $last['seq'],
                );

                return null;
            }
            if ($last !== null) {
                return ['fact' => 'call', 'operation' => 'test', 'seq' => $last['seq']];
            }

            return null;
        }

        if ($kind === EvidenceKind::OperationOk) {
            $answer = $facts->operationResult($reference);
            $call = \is_array($answer['call'] ?? null) ? $answer['call'] : [];
            if (($answer['ok'] ?? false) === true && ($call['succeeded'] ?? false) === true) {
                return ['fact' => 'call', 'operation' => $reference, 'seq' => $call['seq'] ?? 0];
            }

            // A governed execution receipt proves an operation ran even when no tool call names it.
            $operational = $facts->operationalFacts(\PHP_INT_MAX);
            foreach (\is_array($operational['executions'] ?? null) ? $operational['executions'] : [] as $execution) {
                if (($execution['operation'] ?? null) === $reference) {
                    return ['fact' => 'execution', 'operation' => $reference, 'seq' => $execution['seq'] ?? 0];
                }
            }

            return null;
        }

        if ($kind === EvidenceKind::ScreenServed) {
            // THE JUDGE READS THE PREDICATE, NOT THE PRODUCER (greenhouse decisions/0187). A served
            // screen is real, verifiable evidence — a reader opens it at its address — but it is none
            // of the three producer-shaped facts above. The judge asks the stream what some call
            // DEMONSTRATED: a receipt declaring the predicate «served» for this subject. It matches on
            // predicate and subject and NEVER on the tool that emitted them, so screen:declare and any
            // future operation that serves a screen and declares the same predicate cover a claim the
            // same way — no branch here names an operation.
            $served = $facts->evidenceByPredicate('served', $reference);
            if (($served['ok'] ?? false) === true) {
                // FRESHNESS: a receipt covers only while it is still valid (greenhouse decisions/0187).
                // A screen served then FORGOTTEN — or a served screen whose later re-declare failed —
                // is no longer served, yet its old served receipt would still close a claim. The judge
                // covers ONLY a FRESH receipt; a stale one is refused and NAMES what went stale, so the
                // model can re-establish it. The verdict is read from the stream, never a receipt field.
                if (($served['evidence']['fresh'] ?? false) !== true) {
                    $refusal = sprintf(
                        'the claim is refused: the served evidence for «%s» went stale — a later fact '
                        . 'at seq %d (%s) invalidated it. Re-establish the screen and claim again',
                        $reference,
                        \is_int($served['evidence']['invalidatedAtSeq'] ?? null) ? $served['evidence']['invalidatedAtSeq'] : 0,
                        \is_string($served['evidence']['invalidatedBy'] ?? null) ? $served['evidence']['invalidatedBy'] : '?',
                    );

                    return null;
                }

                return [
                    'fact' => 'served',
                    'predicate' => 'served',
                    'subject' => $reference,
                    'operation' => $served['evidence']['operation'] ?? '?',
                    'seq' => $served['evidence']['seq'] ?? 0,
                    'fresh' => true,
                ];
            }

            // WHAT THE HOUSE OBSERVED COVERS TOO (greenhouse decisions/0580). A promotion and `route:observe` leave
            // the house's own `served` receipt of a route under `observed[]`, and the judge did not look there:
            // with `/blog` served twice on record it answered «nothing of that kind yet» (evidence/1109 §6.4).
            // It asks the reading the house closes by ({@see ObservedInTheHouse}), so the contract of
            // decisions/0576 is not derived a second time — a page that does not list does not close a todo —
            // and what that reading says of the page («unjudged», decisions/0579) is carried, never left out.
            $observed = ObservedInTheHouse::served($stream, $facts, $reference, $this->lasting);
            if ($observed !== null && !$observed['covers']) {
                $refusal = "the claim is refused: {$observed['reason']}. When it holds, have the house look again with "
                    . 'route:observe {"path":' . json_encode($reference, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . '} and claim again';
            }

            return $observed === null || !$observed['covers'] ? null : [
                'fact' => 'observed',
                'predicate' => 'served',
                'subject' => $reference,
                'environment' => 'house',
                'seq' => $observed['seq'],
                'fresh' => true,
                ...array_intersect_key($observed, ['content' => true, 'surface' => true]),
            ];
        }

        // artifact-created: the derived work state must have reached materialisation — a mutating
        // call's own ok:true naming the artifact. `attempted` is precisely what does NOT count.
        $state = $facts->workStateFor($reference);
        $reached = \is_string($state['workState']['state'] ?? null) ? $state['workState']['state'] : '';
        if (($state['ok'] ?? false) === true && \in_array($reached, ['materialized', 'superseded', 'verified'], true)) {
            return ['fact' => 'work-state', 'state' => $reached, 'artifact' => $reference];
        }

        // A PROMOTION MATERIALISES ITS PATHS (greenhouse decisions/0482, 0463): the promotion that carried a
        // path into the house is the fact that put it there — only the paths it names, never any other.
        foreach ($this->promotedPaths($stream) as $path => $seq) {
            if ($path === $reference) {
                return ['fact' => 'promotion', 'artifact' => $reference, 'seq' => $seq];
            }
        }

        return null;
    }

    /**
     * What this session's stream holds for a kind, spelled exactly as the judge accepts it as a reference
     * (greenhouse decisions/0482) — the teaching half of a refusal.
     *
     * @param list<Event> $stream the session's own stream, read once by the caller
     *
     * @return list<string>
     */
    private function candidates(EvidenceKind $kind, SessionFacts $facts, array $stream): array
    {
        $found = [];
        foreach ($stream as $event) {
            if ($event->type !== 'session.tool_called' || ($event->payload['ok'] ?? null) !== true) {
                continue;
            }
            $tool = \is_string($event->payload['tool'] ?? null) ? $event->payload['tool'] : '';
            $result = json_decode(\is_string($event->payload['result'] ?? null) ? $event->payload['result'] : '', true);
            $result = \is_array($result) ? $result : [];
            $evidence = $result['evidence'] ?? ($result['output']['evidence'] ?? null);
            $arguments = \is_array($event->payload['arguments'] ?? null) ? $event->payload['arguments'] : [];
            match ($kind) {
                EvidenceKind::ScreenServed => \is_array($evidence) && ($evidence['predicate'] ?? null) === 'served'
                    && ($evidence['invalidates'] ?? false) !== true && \is_string($evidence['subject'] ?? null)
                    ? $found[$evidence['subject']] = true : null,
                EvidenceKind::OperationOk => ($result['ok'] ?? true) !== false && $tool !== '' ? $found[$tool] = true : null,
                EvidenceKind::TestPassed => $tool === 'test' && \is_string($arguments['filter'] ?? $arguments['path'] ?? null)
                    ? $found[(string) ($arguments['filter'] ?? $arguments['path'])] = true : null,
                EvidenceKind::ArtifactCreated => null,
            };
        }
        if ($kind === EvidenceKind::ArtifactCreated) {
            foreach (array_keys($this->promotedPaths($stream)) as $path) {
                $found[$path] = true;
            }
        }
        if ($kind === EvidenceKind::ScreenServed) {
            foreach (array_keys(ObservedInTheHouse::last($stream)) as $route) {
                $found[$route] = true;
            }
        }
        // Only what the judge would accept today: a served subject that went stale is not offered.
        $accepted = [];
        foreach (array_keys($found) as $candidate) {
            $candidate = (string) $candidate;
            $verdict = $facts->lastVerificationOf($candidate);
            $refusal = null;
            if ($this->coveringFact($facts, $stream, $kind, $candidate, $verdict, $refusal) !== null) {
                $accepted[] = $candidate;
            }
        }

        return \array_slice($accepted, 0, 12);
    }

    /**
     * The paths each successful `sandbox:promote` of this session carried into the house, with its seq.
     *
     * @param list<Event> $stream the session's own stream, read once by the caller
     *
     * @return array<string, int>
     */
    private function promotedPaths(array $stream): array
    {
        $paths = [];
        foreach ($stream as $event) {
            if ($event->type !== 'session.tool_called' || ($event->payload['tool'] ?? null) !== 'sandbox_promote') {
                continue;
            }
            $result = json_decode(\is_string($event->payload['result'] ?? null) ? $event->payload['result'] : '', true);
            if (\is_array($result) && ($result['ok'] ?? false) === true && \is_array($result['promoted'] ?? null)) {
                foreach ($result['promoted'] as $path) {
                    if (\is_string($path)) {
                        $paths[$path] = $event->seq;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * SUMMARY: The session's own stream, read from the event store — once per claim, by the claim.
     *
     * @return list<Event>
     */
    private function stream(): array
    {
        return $this->events === null ? [] : $this->events->replay(SessionStore::PREFIX . $this->sessionId);
    }

    /** SUMMARY: Name exactly what the judge looked for, so a refusal corrects instead of stonewalling. */
    private function lookedFor(EvidenceKind $kind, string $reference): string
    {
        return match ($kind) {
            EvidenceKind::TestPassed => sprintf(
                'a recorded verification verdict for «%s», or a `test` call with ok:true DECLARING it in its target arguments (filter/path)',
                $reference,
            ),
            EvidenceKind::OperationOk => sprintf(
                'a recorded call or execution receipt with ok:true for operation «%s»',
                $reference,
            ),
            EvidenceKind::ArtifactCreated => sprintf(
                'a recorded artifact-producing result (make/implement) materialising «%s»',
                $reference,
            ),
            // A receipt earned in a trial never covers the house (decisions/0463); the verb that earns
            // one here is named, so the agent observes instead of re-declaring (decisions/0466).
            EvidenceKind::ScreenServed => sprintf(
                'a recorded call whose result declares the served predicate for «%s» (a served screen), or the '
                . 'house\'s own observation of that route served. %s',
                $reference,
                // THE HINT IS A CALL THE HOUSE RUNS, OR NONE (greenhouse decisions/0580): with a sentence for a
                // reference it used to print the sentence inside the call.
                match (true) {
                    preg_match('#^/[\w\-./]*$#D', $reference) === 1 => 'Observe it in the house with route:observe {"path":"' . $reference . '"}',
                    preg_match('/^[\w][\w\-.]*$/D', $reference) === 1 => 'If it was promoted from a trial, observe it in the house with screen:observe {"name":"' . $reference . '"}',
                    default => 'The reference is the screen\'s name or the route, alone — not a sentence about it',
                },
            ),
        };
    }
}
