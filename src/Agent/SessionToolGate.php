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

use Milpa\ToolRuntime\ToolResult;
use Milpa\AppRuntime\Support\ContratoInstalado;
use Milpa\Agent\PolicyDecision;
use Milpa\Agent\Principal;
use Milpa\Agent\Session;
use Milpa\Agent\SessionPolicy;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\AppRuntime\Policy\PolicyProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\AxisReduction;
use Milpa\Command\Effect\CallSubject;
use Milpa\Command\Effect\ContextFacts;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\ProfileComposition;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;

/**
 * Une la política de la sesión con el bucle del agente (P16.4/P16.5).
 *
 * ── POR QUÉ ESTE ADAPTADOR VIVE EN LA APP Y NO EN UN PAQUETE ────────────────────────────────────
 *
 * Porque es lo único que conoce a los dos lados. `milpa/agent` decide —y no sabe qué es un modelo ni
 * una herramienta— y `milpa/ai-gateway` corre el bucle —y no sabe qué es una sesión ni un permiso—.
 * Ponerlo en cualquiera de los dos los ataría, y lo que hoy son dos paquetes que se pueden usar por
 * separado pasarían a ser uno con dos nombres. La app es donde las decisiones concretas se toman.
 *
 * ── NEGAR ES APENDAR ────────────────────────────────────────────────────────────────────────────
 *
 * Cuando la política dice que hay que preguntar, esto NO devuelve nomás una negativa: apenda la
 * pregunta en la sesión. Esa es la diferencia entre un agente que se detiene y uno que se detiene y
 * te espera — la sesión queda no-corrible hasta que alguien conteste, y la pregunta sobrevive al
 * proceso igual que todo lo demás. Una negativa que sólo existiera en el texto de la respuesta se
 * perdería en cuanto cerraras la terminal.
 */
final class SessionToolGate implements ToolCallGate, ToolCallRecorder, ExecutionRecorder
{
    /**
     * SUMMARY: The marker every UNJUDGEABLE refusal carries, so audit can tell «I cannot judge this»
     * apart from «I know this is forbidden» — both block the call, but they are NOT the same fact
     * (greenhouse decisions/0078, H-GATE-1).
     *
     * It is a stable string and not a new enum ON PURPOSE. {@see ToolCallGate::refuse()} is a RELEASED
     * interface (`milpa/ai-gateway`) whose contract is `?string`; widening it to carry a typed reason
     * would break every published implementer. The refusal reason is already the channel a refusal
     * travels on — down to {@see \Milpa\AiGateway\ToolCallRefusedException} — so the minimal honest
     * signal is a recognizable prefix inside that `?string`, not a second shape. A recorder or an
     * auditor recognises the state with `str_contains($reason, self::UNJUDGEABLE)`.
     */
    public const UNJUDGEABLE = 'UNJUDGEABLE';

    /**
     * @param list<Operation>        $operations        las de esta app — de ahí salen `mutating` y
     *                                                  `requiresConfirmation`, que son declaraciones de la
     *                                                  operación y no algo que esta compuerta pueda opinar
     * @param list<ContractProducer> $contractProducers los productores internos cuyos contratos la
     *                                                  compuerta resuelve cuando la app no declara la operación
     */
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly Session $session,
        private readonly array $operations,
        private readonly SessionPolicy $policy = new SessionPolicy(),
        // Cuánto tiempo tiene un humano para contestar antes de que la sesión se declare muerta.
        // `null` es sin plazo, que es lo que había; la decide el host en `agent.permissionWindow`
        // porque es una política de producto y no una constante de este archivo.
        private readonly ?\DateInterval $permissionWindow = null,
        // LA PETICIÓN, tal cual la escribió el humano. Es contra esto que se verifica el contrato de
        // intención (ADR-0044): sin la petición no hay contra qué comparar un objetivo, y una cadena
        // vacía —lo que había— simplemente desactiva la verificación, que es el comportamiento de
        // toda sesión anterior a que esto existiera.
        private readonly string $petition = '',
        // EL VIGÍA DEL BUCLE ESTÉRIL (Q-P19-R), o `null` para correr como antes. Va aquí y no en su
        // propia compuerta porque esta clase ya es las dos mitades que hacen falta: juzga la llamada
        // ANTES (`refuse`) y ve su resultado DESPUÉS (`recorded`). Una compuerta aparte tendría que
        // reconstruir la segunda mitad, y serían dos verdades sobre lo mismo.
        private readonly ?SterileLoopGuard $vigiaDeBucle = null,
        // WHAT PRODUCES THE AXES THE COMPOSED CEILING NEEDS (greenhouse decisions/0058). Both are
        // optional and default to «this app declares none»: with neither, composition still applies
        // certified mutation descents (a rehearsal), and authority simply never descends — the gate
        // behaves exactly as it did before it learned to read the composed ceiling.
        private readonly ?PolicyProvider $policyProvider = null,
        private readonly ?SessionIdentity $identity = null,
        // LA COMPUERTA DE ORDEN (Q-P20-I), o `null` para correr como antes. Va aquí por lo mismo que
        // el vigía: esta clase ya es las dos mitades —juzga antes, ve el resultado después— y una
        // compuerta aparte tendría que reconstruir la segunda.
        private readonly ?PrerequisiteGate $compuertaPrevia = null,
        // THE ARROW (greenhouse evidence/0009), or `null` to run as before. Unlike the ordering
        // gate it never learns from `recorded()`: it adjudicates durable STATE on every call —
        // executing the rite does not open it; producing the state the rite was meant to
        // demonstrate does.
        private readonly ?TransitionGate $arrow = null,
        // THE ONE source that decides whether THIS call goes to trial (greenhouse decisions/0069). With
        // it, a confinable mutation composes down to Ephemeral and runs without a pause when it fits
        // the trial ceiling; without it, or without a sandbox, the gate behaves exactly as before.
        private readonly ?TrialRouter $trialRouter = null,
        // THE AUTHORIZED PRODUCERS of tools that reach the gate through the registry's `$extra` and
        // never through `Operations::all()` — the session's notebook and delegation (greenhouse
        // decisions/0078). The gate consults them to RESOLVE a tool's contract when the app declares
        // no Operation for it, and then judges THAT contract. Empty by default: with no producers the
        // gate resolves only app Operations, exactly as before — and a tool neither an Operation nor a
        // producer claims is the one genuinely unjudgeable thing, which fails closed.
        private readonly array $contractProducers = [],
        // THE DEBT-SIGNAL SEAM (greenhouse decisions/0183 primitive #5), or `null` to observe
        // nothing. An OBSERVATION channel only: it carries no authority, changes no decision this
        // gate makes, and with no seam every path behaves byte-identically — which is the A/B
        // falsifier its own suite pins.
        private readonly ?DebtSignal $debtSignals = null,
    ) {
    }

    /**
     * El motivo por el que esta llamada no procede, o `null` si procede.
     *
     * @param array<string, mixed> $arguments
     */
    public function refuse(string $tool, array $arguments): ?string
    {
        // LA EXENCIÓN POR NOMBRE SE RETIRÓ AQUÍ, y lo que la sustituye es la regla.
        //
        // Decía —con razón— que pedir permiso para anotar un plan es pedir permiso para ser legible.
        // Pero lo decía por LISTA DE NOMBRES, y una compuerta que se abre así deja de ser una regla y
        // se vuelve un directorio: el siguiente que necesite lo mismo pide que lo agreguen, y mientras
        // dos mecanismos sostienen el mismo caso nadie sabe cuál lo está sosteniendo.
        //
        // Desde que `SessionBookkeeping` declara su `EffectProfile` (greenhouse evidence/0189), la
        // política decide por el TECHO y las deja pasar sola. Verificado por ejecución antes de
        // quitarla, con el control que separa las dos explicaciones posibles:
        //
        //   clasificadas   + sin exención → el pendiente aterriza
        //   SIN clasificar + sin exención → no aterriza      ← la exención SÍ era lo que las pasaba
        //
        // Sin ese segundo caso esto habría sido quitar algo que ya sobraba y llamarlo arreglo.

        // LA OBLIGACIÓN DE ORDEN VA ANTES QUE TODO LO DEMÁS, y por eso queda debajo de la
        // contabilidad y encima de la operación: lo obligado suele SER contabilidad —«planea antes de
        // empezar»— así que la línea de arriba tiene que dejarlo pasar siempre, o la obligación se
        // volvería imposible de cumplir y la mesa no abriría nunca.
        $falta = $this->compuertaPrevia?->motivoParaEsperar($tool);
        if ($falta !== null) {
            return $falta;
        }

        // THE ARROW COMES AFTER THE OBLIGATION AND BEFORE POLICY: it is a fact with a teaching,
        // not a question — there is nothing a human must decide about a transition durable state
        // has not earned yet. The refusal says what is missing, and with which name.
        $closed = $this->arrow?->reasonToWait($tool);
        if ($closed !== null) {
            return $closed;
        }

        // RESOLVE THE OPERATIONAL CONTRACT of this call: an app Operation, or — for a tool the app
        // does not declare but an authorized internal producer does — that producer's declared
        // contract (greenhouse decisions/0078). {@see self::operationFor()} walks that ladder.
        $operacion = $this->operationFor($tool);
        if ($operacion === null) {
            // FAIL CLOSED (H-GATE-1). No Operation and no producer states this call's effect, so it is
            // genuinely UNJUDGEABLE. The old code returned `null` here — ALLOW — on the theory that «not
            // my operation; the scope gate will judge it». That justification is FALSE in the governed
            // path: `ConsentBridge::callTool` builds the context with `ToolContext::cli()` = `scopes:
            // ['*']`, so no scope gate really judges it, and an unjudgeable call would run with NO judge
            // (masked at evidence/0314 only by the registry's accidental «Tool not found»). The gate is
            // the judge, and the judge cannot abstain.
            //
            // The criterion is JUDGEABILITY, never scopes. «I cannot judge this» is a DIFFERENT fact
            // from «I know this is forbidden» — {@see self::UNJUDGEABLE} marks it so audit can tell them
            // apart without widening the released `?string` contract. No pause: a call nobody can
            // characterise offers a human nothing to decide, and a pause answered «yes» would run it
            // unjudged — the hole this closes.
            return self::UNJUDGEABLE . ": «{$tool}» resolves to no Operation of this app and no producer"
                . ' states its effect. Registering an executable tool does not grant authority in the'
                . ' governed path; a judgeable contract does.';
        }

        // THE SESSION'S OWN NOTEBOOK IS NOT WORLD-GATED — S2 by profile (greenhouse decisions/0028,
        // evidence/0189). A resolved contract whose declared effect touches only the session's own log
        // — no externality, no authority, subject Data — and demands no confirmation is self-legibility,
        // not a world mutation: gating it would ask permission to be legible, the one thing the
        // authority declared non-negotiable. This reads the CONTRACT (the `EffectProfile`), never a
        // name — it is what `SessionBookkeeping`'s profile always meant, finally wired now that the gate
        // resolves it. Only such a contract passes; delegation (`WriteAsUser`, `Executable`,
        // `requiresConfirmation`) is not self-log, so it is judged by the policy below.
        if ($this->esBitacoraPropia($operacion)) {
            return null;
        }

        // EL CONTRATO DE INTENCIÓN VA ANTES DE LA POLÍTICA, y ningún modo lo exime (ADR-0044).
        //
        // `auto` exime pedir PERMISO; no exime entender qué se pidió — igual que la firma. Por eso
        // esta verificación no pasa por `SessionPolicy`: la política juzga una intención concreta, y
        // lo que aquí se decide es si ya existe una. El orden también importa para `ask`: preguntar
        // «¿autorizas X sobre Y?» presupone que Y es el objetivo correcto, que es justo lo que está
        // en duda.
        //
        // La verificación es MECÁNICA a propósito — ¿el valor aparece en la petición, sin distinguir
        // mayúsculas? — sin ningún modelo en el circuito: el piso es la autoridad no-persuadible y
        // así se queda. Sus falsos positivos («apaga el plugin de hola» no nombra HelloPlugin)
        // producen una pregunta contestable, no un bloqueo, y su tasa es lo que Q-P19-M mide.
        $duda = $this->intentUnderdetermined($operacion, $arguments);
        if ($duda !== null) {
            return $this->pause($duda);
        }

        // NO REPETIR LO QUE YA FALLÓ DOS VECES IGUAL (Q-P19-R). Va después del contrato de intención
        // y antes de la política: no es una cuestión de autoridad —nadie está pidiendo permiso para
        // nada— sino de no gastar el presupuesto en una llamada cuyo resultado ya se conoce.
        //
        // NO PAUSA LA SESIÓN. Devuelve el motivo sin apendar pregunta: aquí no hay nada que un humano
        // deba decidir, y detener la vuelta por esto sería cobrarle al humano un descuido del modelo.
        // El bucle del agente sigue —`optionRemoved` en la excepción que arma quien nos llama— y el
        // modelo recibe el hecho con el error adentro, que es con lo que puede corregir.
        $bucle = $this->vigiaDeBucle?->motivoParaNoRepetir($tool, $arguments);
        if ($bucle !== null) {
            return $bucle;
        }

        // THE GATE DECIDES BY THE COMPOSED CEILING OF THIS CALL, not by the declared flag
        // (greenhouse decisions/0058). A rehearsal whose certified descent drops mutation to None is
        // not a mutation, so the session does not pause for it — while the same operation without the
        // rehearsal still does. Composition can only LOWER a ceiling, never raise it, so this can
        // only ever ask LESS, and only when a producer authorised the descent — never by a flag.
        // THE COMPOSITION IS COMPUTED ONCE AND HANDED TO THE POLICY (greenhouse decisions/0067): the
        // policy is the single judge, so it receives the composed profile and compares it against a
        // grant's envelope itself — this gate never compares. `null` when the operation does not
        // mutate: there is nothing to compose and nothing a read needs admitting under.
        $composicion = $this->componer($operacion, $arguments);

        // PROGRESS RECOVERY PRESCRIBES, IT DOES NOT ONLY DIAGNOSE (greenhouse decisions/0187, D-03).
        //
        // The ProgressReceipt (0452) detected a stall and worded the forced choice, but the
        // orchestrator's enforcement let ANY tool call through as «acting» — including one more read.
        // A stall is exactly «I have read enough and produced nothing»; more exploration is the one
        // move that cannot help. So while a stall stands unanswered by an action, this refuses a
        // NON-mutating call — the read/inspect/speculate family — and names the moves that remain:
        // materialize, verify, close, ask a human, or declare house debt. It is a non-pausing
        // refusal (the teaching, like the arrow and the obligation above), never a question: nothing
        // here is a human's to decide. A mutating call is the recovery itself and passes untouched;
        // making one clears the stall for the next read. Bookkeeping (the plan, the todos) already
        // passed as self-log above, so narrowing the plan under recovery is never blocked.
        if (
            ($composicion === null || $composicion->effective->mutation === Mutation::None)
            && $this->enRecuperacion()
        ) {
            return 'Progress recovery: the last window produced no evidence, no artifact and closed no '
                . 'todo, so more reading is not on the table. Do one of these now: materialize an '
                . 'artifact, run a verification, close a todo with its evidence, ask the human a '
                . 'decision, or declare framework debt. A mutating action clears this.';
        }

        $decision = $this->policy->decide(
            $this->session,
            $operacion->name,
            $composicion !== null && $composicion->effective->mutation !== Mutation::None,
            $operacion->requiresConfirmation,
            // El techo se pide AQUÍ, por llamada, y no se guarda en el constructor: si el padre baja
            // a `ask` a media corrida del hijo, la siguiente herramienta ya lo siente. Un techo
            // cacheado se queda viejo exactamente cuando el humano acaba de decidir supervisar —
            // la clase de defecto que Q-P20-B midió (la foto contra el estado vigente).
            $this->sessions->ceilingFor($this->session->id),
            // For a mutation, the composed (possibly trial-lowered) ceiling. For a READ there is
            // nothing to compose, but a read can still carry externality — the egress axis — so the
            // declared ceiling is handed over instead of null, and the policy judges the crossing.
            composed: $composicion !== null ? $composicion->effective : $operacion->effectCeiling(),
            composition: $composicion,
        );

        return match ($decision) {
            PolicyDecision::Allow => null,
            // EL «why» SE GUARDA ESTRUCTURADO, igual que el de la pregunta de intención (:254).
            // `SessionPolicy` lo escribe como el JSON pelón de los argumentos, y así el operativo
            // que después quiera saber QUÉ autorizó el humano tendría que sacar la operación del
            // TEXTO de la pregunta. Un consentimiento que sólo se puede reconstruir leyendo prosa no
            // es un hecho: es una redacción (greenhouse decisions/0031).
            // AND SINCE decisions/0184 THE ARM ASKS ONLY IF NO CONFIRMED INTENT CLAIM ALREADY
            // ANSWERS IT — the admissibility ruling lives in {@see self::askUnlessAConfirmedIntentAdmits()}.
            PolicyDecision::AskPermission => $this->askUnlessAConfirmedIntentAdmits($operacion, $arguments, $composicion),
            PolicyDecision::RequireSignature => $this->pause(
                $this->policy->signatureQuestion($operacion->name, $arguments),
            ),
        };
    }

    /**
     * La pregunta que el contrato de intención exige hacer, o `null` si la intención alcanza.
     *
     * Tres salidas en `null`, y las tres son deliberadas: la operación no declara contrato (casi
     * todas), no hay petición contra qué comparar (sesiones viejas), o el argumento declarado no
     * viene en la llamada — eso lo rechaza la validación de schema con su propio error, y duplicar
     * ese juicio aquí sería el segundo comparador que esta casa ya pagó cuatro veces (Q-P17).
     *
     * La pregunta lleva la operación y los argumentos ADENTRO — es la primera aplicación de la
     * restricción que Q-P19-K dejó: toda aprobación necesita tanta evidencia como una negativa, y
     * quien conteste «sí» tiene que poder saber exactamente qué está autorizando.
     *
     * @param array<string, mixed> $arguments
     */
    private function intentUnderdetermined(Operation $operacion, array $arguments): ?\Milpa\Agent\PendingQuestion
    {
        // SE PREGUNTA SI EL CONTRATO EXISTE, no se asume. `namedTarget` nació en milpa/command 0.5 y
        // este `src/` viaja con `composer create-project`: puede convivir con un vendor que su dueño
        // no actualizó —lock viejo, update parcial que no resuelve— y ahí la propiedad no está.
        //
        // Encontrado en la máquina de Rod, y el daño fue desproporcionado: el warning de PHP se
        // escribe sobre la pantalla del TUI y la destruye, así que un desajuste de versiones se veía
        // como un stack trace encima de la conversación. Leer defensivamente cuesta una línea; el
        // pin declara la exigencia y esto la sobrevive cuando el pin no se cumplió todavía.
        $campo = $this->contratoDeclaradoPor($operacion);
        if ($campo === null || $this->petition === '') {
            return null;
        }

        $valor = $arguments[$campo] ?? null;
        if (!\is_string($valor) || trim($valor) === '') {
            return null;
        }

        // D-05 (greenhouse decisions/0187): the intent contract asks about the TARGET unless BOTH
        // (a) the call is non-grave AND (b) the operation CREATES its named target.
        //
        // Two questions were being conflated, and only one is a human's: «you did not name this» vs.
        // «you did not authorise the necessary component». WHICH class realises the plan —
        // `implement TareaService` materialising the component the objective implies — is the MODEL's
        // interpretive domain (product→human, interpretation→model, mechanics→house), so a
        // materialising op opts out. But WHICH existing plugin to disable, or note to delete, is
        // target SELECTION, not interpretation — Q-P19-K, the measured defect that CREATED this
        // contract («apaga el plugin viejo» → runs disabled different plugins) — so a SELECTING op
        // still names its target even when reversible. The `createsNamedTarget` flag draws that line;
        // it fails closed (default false → keep asking), so only an op that declares it materialises
        // is relaxed.
        //
        // Gravity gates it too: the admissibility table (decisions/0184) marks privileged authority,
        // egress and destructive/unknown reversibility NEVER, and those keep asking regardless of the
        // flag. Judged by the DECLARED ceiling — a descent only lowers, so this never asks LESS for a
        // grave op.
        if (
            IntentAdmissibility::tier($operacion->effectCeiling()) !== IntentAdmissibility::NEVER
            && $this->createsItsNamedTarget($operacion)
        ) {
            return null;
        }

        // A RESUME IS NOT A NEW PETITION (greenhouse decisions/0009). The prompt of a resumed
        // run is literally «sigue», which names nothing by construction — and the series' three
        // non-converters all died on «the petition does not name X» while the human's standing
        // errand named X in lowercase. The target is compared against the STANDING ask: this
        // run's prompt AND the session's goal. Still mechanical, still no model in the circuit;
        // a target named in neither keeps pausing — ADR-0044 lives.
        $standing = $this->petition . "\n" . (string) $this->session->goal;
        if (mb_stripos($standing, trim($valor)) !== false) {
            return null;
        }

        // ── EL CICLO SE CIERRA: Pregunta → Nueva intención ──────────────────────────────────────
        //
        // Si el humano YA confirmó esta operación sobre este objetivo —contestó «sí» a la pregunta
        // que este mismo contrato produjo— el objetivo está nombrado: por el humano, en el stream,
        // con principal. Sin esto, la re-propuesta tras la respuesta volvería a pausar la misma
        // llamada (la petición sigue sin nombrar al objetivo), y una pregunta que contestarla no
        // destraba nada es teatro con acta.
        //
        // Se lee del hecho, no de la prosa: la decisión hereda `reason` y `why` de la pregunta, así
        // que «¿ya se confirmó plugins.disable sobre HelloPlugin?» se contesta comparando código y
        // JSON — nunca el texto de la pregunta, que se redacta y cambia.
        foreach (ContratoInstalado::arreglo($this->session, 'decisions') as $decision) {
            if (($decision['reason'] ?? null) !== 'target_not_named') {
                continue;
            }
            if (! AffirmativeAnswer::is((string) $decision['answer'])) {
                continue;
            }
            $why = json_decode(\is_string($decision['why'] ?? null) ? $decision['why'] : '', true);
            if (!\is_array($why) || ($why['operation'] ?? null) !== $operacion->name) {
                continue;
            }
            $confirmado = \is_array($why['arguments'] ?? null) ? ($why['arguments'][$campo] ?? null) : null;
            if ($confirmado === trim($valor)) {
                return null;
            }
        }

        return new \Milpa\Agent\PendingQuestion(
            id: 'intent-' . substr(sha1($operacion->name . '|' . $valor), 0, 12),
            question: "La petición no nombra a «{$valor}». ¿Confirmas {$operacion->name} sobre «{$valor}»?",
            options: ['sí', 'no'],
            why: json_encode(
                ['operation' => $operacion->name, 'arguments' => $arguments],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ) ?: null,
            expiresAt: $this->vence()?->format(\DateTimeInterface::ATOM),
            reason: 'target_not_named',
        );
    }

    /**
     * El argumento que esta operación exige nombrado, leído SIN asumir que el contrato existe.
     *
     * Recibe `object` y no `Operation` a propósito: el análisis estático ve el paquete instalado
     * AQUÍ, donde `namedTarget` siempre existe, y con el tipo estrecho concluiría —con razón, desde
     * su vista— que la comprobación sobra. En runtime no sobra: este `src/` viaja con
     * `composer create-project` y convive con el vendor que su dueño tenga.
     */
    /**
     * Whether a detected stall stands unanswered by an action (greenhouse decisions/0187, D-03).
     *
     * Recovery opens when {@see SessionProgressProbe} appends a `progress_stalled` fact and closes
     * the moment a real mutating call lands after it — acting IS the way out. A call that merely
     * asked for confirmation (`awaitingConfirmation`) did not act and does not clear it. Read from
     * the session's own stream by the fact's literal type (the DebtSignal doctrine: the reader reads
     * the fact, never the emitter's class), and silent on a store that cannot answer — a recovery it
     * cannot prove is one it does not impose.
     */
    private function enRecuperacion(): bool
    {
        try {
            $stream = $this->sessions->stream($this->session->id);
        } catch (\Throwable) {
            return false;
        }

        $stalled = 0;
        $acted = 0;
        foreach ($stream as $event) {
            if ($event->type === SessionProgressProbe::EVENT) {
                $stalled = max($stalled, $event->seq);
            } elseif (
                $event->type === 'session.tool_called'
                && ($event->payload['mutating'] ?? false) === true
                && ($event->payload['awaitingConfirmation'] ?? null) !== true
            ) {
                $acted = max($acted, $event->seq);
            }
        }

        return $stalled > 0 && $acted < $stalled;
    }

    private function contratoDeclaradoPor(object $operacion): ?string
    {
        return ContratoInstalado::cadena($operacion, 'namedTarget');
    }

    /**
     * Whether the operation declares it CREATES its named target (greenhouse decisions/0187, D-05).
     *
     * Read defensively for the same reason {@see contratoDeclaradoPor()} is: this `src/` travels with
     * `composer create-project` and can run against a `milpa/command` vendor that predates the flag.
     * Absent property → `false`, which is the fail-closed answer this gate wants anyway: keep asking.
     */
    private function createsItsNamedTarget(object $operacion): bool
    {
        return property_exists($operacion, 'createsNamedTarget') && $operacion->createsNamedTarget === true;
    }

    /** Cuándo vence la pregunta que se está por hacer, o `null` si el host no puso plazo. */
    private function vence(): ?\DateTimeImmutable
    {
        return $this->permissionWindow === null
            ? null
            : (new \DateTimeImmutable())->add($this->permissionWindow);
    }

    /**
     * The perm: pause of the AskPermission arm — unless a confirmed intent claim already answers it.
     *
     * «La intención describe qué quiere el humano. La policy decide qué autoridad compra haberlo
     * dicho.» — the policy decides what authority saying it buys (Rod, greenhouse decisions/0184).
     * Measured at evidence/0444: the operator answered the INTENT question and the consent gate
     * still refused the very same call — two human ceremonies buying the same right. The confirmed
     * intent is a CLAIM, already structured in the session's decisions, and {@see IntentAdmissibility}
     * is the policy's ruling on whether that claim is admissible EVIDENCE for the consent this arm
     * was about to ask for — judged against the COMPOSED profile of this call when there is one, the
     * declared ceiling otherwise: the same profile the policy just judged, so the evidence is
     * weighed against the question that was actually asked.
     *
     * NOT A SECOND JUDGE: this runs only after {@see SessionPolicy::decide()} routed the call to
     * AskPermission. It can only ever answer that question with the human's own recorded yes — it
     * never widens, never decides Allow for anything the policy did not route here, and the
     * RequireSignature arm never consults it. The claim appends nothing to the session: no
     * PermissionGranted is minted; admissibility is re-judged where consent is judged, at judgment
     * time, from recorded facts — exactly like the grants the authority layer re-derives.
     *
     * AUDIT RESIDUE, PAID (the DebtSignal arc, greenhouse decisions/0183): an admitted skip now
     * leaves its mark as a `session.debt_signaled` OBSERVATION — zero authority, zero behavior —
     * never as the permission event this ruling does not license. And the deliberate double
     * ceremony of a NEVER-tier exact claim is counted the same way, right beside the perm:
     * question it doubles (the plugins.register case of evidence/0444).
     *
     * @param array<string, mixed> $arguments
     */
    private function askUnlessAConfirmedIntentAdmits(
        Operation $operacion,
        array $arguments,
        ?ProfileComposition $composicion,
    ): ?string {
        $techo = $composicion !== null ? $composicion->effective : $operacion->effectCeiling();
        if ($this->aConfirmedIntentAdmits($operacion, $arguments, $techo)) {
            // THE NAMED RESIDUE OF evidence/0445, PAID: the zero-authority skip becomes visible
            // as a SIGNAL, not as authority — the ConsentBridge digest travels, never the raw
            // arguments. Emitted right here, at the site that holds the proof: this frame just
            // resolved the admitting claim.
            $this->debtSignals?->emit(DebtSignal::ADMITTED_INTENT_SKIP, [
                'operation' => $operacion->name,
                'tier' => IntentAdmissibility::tier($techo),
                'argumentsDigest' => ConsentBridge::digest($arguments),
            ]);

            return null;
        }

        $pausa = $this->pause(
            $this->conElHechoAdentro(
                $this->policy->permissionQuestion($operacion->name, $arguments, $this->vence()),
                $operacion->name,
                $arguments,
                // THE DECLARED CEILING AND WHAT THIS CALL COMPOSED TO, as facts the system writes
                // at the pause (decisions/0067): `base` is what a structural counter is meet-ed
                // against — never taken from the human's payload — and `composed` is what the
                // human tightens from, shown rather than remembered.
                $operacion->effectCeiling()->toArray(),
                $composicion?->effective->toArray(),
                // WHAT WOULD ENTER, shown at the pause. Only a promotion carries it: the human
                // authorises a trial's consequences seeing the diff, not a workspace id
                // (greenhouse decisions/0069, 0068). Any other pause gets null and is unchanged.
                $this->cambiosDeUnaPromocion($operacion->name, $arguments),
            ),
        );

        // THE DOUBLE CEREMONY, COUNTED WHERE IT HAPPENS (the plugins.register case of
        // evidence/0444): the human confirmed EXACTLY this call and the tier still rules NEVER, so
        // the perm: question above is deliberate policy — and deliberate policy is still friction
        // the Measuring Stick deserves to see. Observed AFTER the ask so the signal sits adjacent
        // to the question it counts; it prevents nothing and answers nothing.
        if (IntentAdmissibility::tier($techo) === IntentAdmissibility::NEVER
            && $this->anExactConfirmedClaimNames($operacion, $arguments)
        ) {
            $this->debtSignals?->emit(DebtSignal::HIGH_TIER_DOUBLE_CEREMONY, [
                'operation' => $operacion->name,
                'tier' => IntentAdmissibility::NEVER,
            ]);
        }

        return $pausa;
    }

    /**
     * Whether this session carries a confirmed intent claim admissible for THIS exact call.
     *
     * The same fact-read the intent-contract cycle performs in {@see self::intentUnderdetermined()}:
     * the decision inherits `reason` and `why` from the question, so the claim is compared as code
     * and JSON — never the question's prose. A claim answered «no» never admits; a claim for another
     * operation never admits; a claim from another session cannot even appear, because these
     * decisions are THIS session's stream. Whether an exact claim is ENOUGH for this profile is
     * {@see IntentAdmissibility}'s table — and its unjudgeable answer is «ask».
     *
     * @param array<string, mixed> $arguments
     */
    private function aConfirmedIntentAdmits(Operation $operacion, array $arguments, EffectProfile $techo): bool
    {
        foreach ($this->confirmedClaimArguments($operacion) as $confirmados) {
            if (IntentAdmissibility::admits($confirmados, $arguments, $techo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the human confirmed EXACTLY this call — the strict start alone, no tier ruling.
     *
     * What the `high_tier_double_ceremony` signal observes (greenhouse decisions/0183): the
     * exactness is {@see IntentAdmissibility::exact()} itself, so the observation can never
     * disagree with the judgment about what «exact» means.
     *
     * @param array<string, mixed> $arguments
     */
    private function anExactConfirmedClaimNames(Operation $operacion, array $arguments): bool
    {
        foreach ($this->confirmedClaimArguments($operacion) as $confirmados) {
            if (IntentAdmissibility::exact($confirmados, $arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every argument set this session holds a confirmed intent claim for, on THIS operation.
     *
     * The ONE fact-read behind both consumers above: the decision inherits `reason` and `why` from
     * the question, so the claim is read as code and JSON — never the question's prose. A claim
     * answered «no» never appears; a claim for another operation never appears; a claim from
     * another session cannot even appear, because these decisions are THIS session's stream.
     *
     * @return list<array<string, mixed>>
     */
    private function confirmedClaimArguments(Operation $operacion): array
    {
        $claims = [];
        foreach (ContratoInstalado::arreglo($this->session, 'decisions') as $decision) {
            if (($decision['reason'] ?? null) !== 'target_not_named') {
                continue;
            }
            if (! AffirmativeAnswer::is((string) ($decision['answer'] ?? ''))) {
                continue;
            }
            $why = json_decode(\is_string($decision['why'] ?? null) ? $decision['why'] : '', true);
            if (!\is_array($why) || ($why['operation'] ?? null) !== $operacion->name) {
                continue;
            }
            if (!\is_array($why['arguments'] ?? null)) {
                continue;
            }
            $claims[] = $why['arguments'];
        }

        return $claims;
    }

    /**
     * Apunta en la sesión que esta herramienta corrió y qué contestó.
     *
     * La compuerta ve la intención y esto ve el desenlace; hacen falta las dos. Sin el desenlace,
     * retomar una sesión sería retomarla sabiendo qué se iba a intentar y no si funcionó — y el agente
     * repetiría el trabajo que su yo anterior ya hizo, o el que ya falló.
     *
     * El resultado se recorta: un `make` que devuelve tres rutas absolutas no aporta más al retomar
     * que su primera línea, y sí ocupa la ventana que la compactación acaba de liberar.
     *
     * @param array<string, mixed> $arguments
     */
    public function recorded(string $tool, array $arguments, string $result, bool $ok): void
    {
        // La contabilidad NO se apunta como llamada. Su efecto ya está en el stream con su propio
        // evento —`plan_set`, `todo_changed`— y el reductor lo devuelve como estado, que es como llega
        // a la ventana. Registrarla además como turno diría lo mismo dos veces y le cobraría a la
        // ventana el doble justo en las sesiones largas, donde el espacio es lo que escasea.
        // LA COMPUERTA DE ORDEN SE ENTERA PRIMERO, antes del corte de abajo. Lo obligado casi siempre
        // es contabilidad, y si aprendiera después del `return` no vería nunca que se cumplió: la mesa
        // quedaría cerrada para siempre por el mismo hecho que venía a abrirla.
        $this->compuertaPrevia?->anota($tool, $ok);

        if ($this->esContabilidad($tool)) {
            return;
        }

        // El vigía ve TODO lo que se ejecutó, incluidas las llamadas de operaciones que esta app no
        // declara: un bucle estéril sobre una herramienta externa gasta el mismo presupuesto.
        $this->vigiaDeBucle?->anota($tool, $arguments, $result, $ok);

        // SI LA LLAMADA MUTABA, lo sabe esta compuerta: tiene la operación delante. El stream no lo
        // guardaba, así que no distinguía mirar de mover — y sin esa distinción no se puede verificar
        // nada sobre las mutaciones, porque como mutaciones son invisibles.
        $operacion = $this->operationFor($tool);

        // LA COMPUERTA GRABA `trial_promoted`, porque el agente promueve con un workspace y SIN id de
        // sesión —no conoce la suya (greenhouse decisions/0069 §7)—. Este es el único observador de la
        // ejecución que sí sabe la sesión; lee las rutas promovidas del resultado y apenda el hecho de
        // frontera. Se guarda con la AUSENCIA del argumento `session`: quien pasó su propia sesión ya
        // lo grabó en el handler, y grabarlo aquí lo contaría dos veces.
        if ($ok
            && $operacion instanceof Operation
            && $operacion->name === 'sandbox:promote'
            && ! isset($arguments['session'])
            && \is_string($arguments['workspace'] ?? null)
            && $arguments['workspace'] !== ''
        ) {
            $decodificado = json_decode($result, true);
            $rutas = \is_array($decodificado) && \is_array($decodificado['promoted'] ?? null)
                ? array_values($decodificado['promoted'])
                : null;
            if ($rutas !== null && ($decodificado['ok'] ?? null) !== false) {
                $this->sessions->recordTrialPromotion($this->session->id, [
                    'workspace' => $arguments['workspace'],
                    'paths' => $rutas,
                    'diff_digest' => hash('sha256', (string) json_encode($rutas, \JSON_UNESCAPED_SLASHES)),
                    'by' => 'agent',
                ]);
            }
        }

        // EL LOG GUARDA LO QUE LA HERRAMIENTA CONTESTÓ. El recorte vive en `Session::window()`,
        // que es el único consumidor con escasez de espacio.
        //
        // Aquí se cortaba antes, y así un solo `mb_substr` le servía a dos consumidores con
        // necesidades opuestas: la ventana lo quiere corto porque el contexto es lo que se acaba en
        // una sesión larga, y una superficie lo quiere entero para ARMAR la vista del dato. La
        // ventana obtenía lo suyo y la superficie pagaba la cuenta — medido sobre ganado:
        // `capabilities` contestó 2004 caracteres, el log guardó 600, el valor dejó de parsear y el
        // humano no vio tabla ninguna, en la misma sesión donde una llamada más chica sí la tuvo
        // (greenhouse evidence/0203).
        //
        // `resultChars` se sigue mandando: hoy iguala el largo guardado, y ese es el punto. Es el
        // mecanismo que declararía cualquier tope futuro —uno de disco, que es otra escasez y otro
        // tope— y no se quita porque haya dejado de tener algo que confesar (evidence/0202).
        $this->sessions->recordToolCall(
            $this->session->id,
            $tool,
            $arguments,
            $result,
            $ok,
            $operacion instanceof Operation && $operacion->mutating,
            mb_strlen($result),
            // PEDIR NO ES HABER HECHO, y esta compuerta tiene la respuesta delante.
            //
            // Una petición de confirmación y una escritura consumada vuelven las dos con éxito, así
            // que sobre una operación que muta se graban idénticas: `ok` y `mutating`, y las dos
            // cosas son ciertas en ambas. Quien cuente mutaciones desde el stream contaba DOS donde
            // hubo UNA — la cuenta que gobierna el consentimiento (greenhouse evidence/0200).
            //
            // SE LE PREGUNTA AL PROTOCOLO, no se relee aquí. `requires_confirmation` es suyo, y un
            // segundo lector discrepa con el primero el día que cualquiera de los dos cambie
            // (evidence/0141). El predicado además distingue una petición de un PLAN, que lleva la
            // misma llave anidada para decir qué requeriría confirmarse sin estar pidiéndolo.
            ToolResult::asksForConfirmation($result),
        );
    }

    /**
     * Writes down that an operation was materialised — a different question from the one above.
     *
     * {@see self::recorded()} is told about a CALL after it returned, and everything it receives
     * describes the call: what it was, what it got back, whether it failed. This one is told about a
     * FACT, and carries the two identities that fact needs to be auditable a year from now.
     *
     * This class only writes them; it neither observes the executor nor decides the authority. Both
     * arrive already established from the frame that was present when the effect happened, because a
     * gate that filled in an identity here would be filling it in AFTER the act — which is the defect
     * this event exists to remove (greenhouse decisions/0037).
     */
    public function executed(
        string $operation,
        ?Principal $executedBy,
        string $executorSource,
        ?array $authorizedBy,
        string $argumentsDigest,
    ): void {
        $this->sessions->recordExecution(
            $this->session->id,
            $operation,
            $executedBy,
            $executorSource,
            $authorizedBy,
            $argumentsDigest,
        );
    }

    /** Apenda la pregunta —la sesión queda esperando— y devuelve lo que se le dice a quien preguntó. */
    /** Lo que un humano necesita leer de un hecho que trae más de lo que le importa. */
    private static function conQué(?string $why): string
    {
        $hecho = json_decode((string) $why, true);
        if (! \is_array($hecho) || ! \array_key_exists('arguments', $hecho)) {
            return (string) $why;
        }

        return json_encode($hecho['arguments'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            ?: (string) $why;
    }

    /**
     * La misma pregunta, con la operación y los argumentos guardados como dato — y, desde los sobres
     * (decisions/0067), el techo declarado y lo que esta llamada compuso.
     *
     * @param array<string, mixed>       $arguments
     * @param array<string, mixed>|null  $base      el techo DECLARADO de la operación (`EffectProfile::toArray()`)
     * @param array<string, mixed>|null  $composed  lo que ESTA llamada compuso, para apretar desde un hecho visto
     * @param array<string, string>|null $cambios   el diff que una promoción aplicaría (path => added|modified|deleted)
     */
    private function conElHechoAdentro(
        \Milpa\Agent\PendingQuestion $pregunta,
        string $operacion,
        array $arguments,
        ?array $base = null,
        ?array $composed = null,
        ?array $cambios = null,
    ): \Milpa\Agent\PendingQuestion {
        $hecho = ['operation' => $operacion, 'arguments' => $arguments];
        if ($base !== null) {
            $hecho['base'] = $base;
        }
        if ($composed !== null) {
            $hecho['composed'] = $composed;
        }
        // The diff rides ALONGSIDE the arguments, never inside them: the grant reads `arguments`
        // (decisions/0031), so `cambios` is display for the human and cannot pollute what is granted.
        if ($cambios !== null && $cambios !== []) {
            $hecho['cambios'] = $cambios;
        }

        return new \Milpa\Agent\PendingQuestion(
            id: $pregunta->id,
            question: $pregunta->question,
            options: $pregunta->options,
            why: json_encode($hecho, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: $pregunta->why,
            expiresAt: $pregunta->expiresAt,
            reason: $pregunta->reason,
        );
    }

    private function pause(\Milpa\Agent\PendingQuestion $pregunta): string
    {
        $this->sessions->ask($this->session->id, $pregunta);

        $linea = $pregunta->question;
        if ($pregunta->why !== null) {
            // LA PROYECCIÓN LEE EL ÁTOMO, no lo escupe. `why` guarda el hecho —operación y
            // argumentos— para que nadie tenga que reconstruirlo del texto; lo que el humano
            // necesita ver es CON QUÉ, no el sobre en el que viaja. Volcar el JSON entero convertía
            // una pregunta legible en un dump, que es la proyección comiéndose al átomo.
            $linea .= "\n  con: " . self::conQué($pregunta->why);
        }
        // AQUÍ NO SE DICE CÓMO CONTESTAR, y antes sí: la línea `contesta con: coa agent:answer …`
        // viajaba dentro del texto de la pausa, así que aparecía TAMBIÉN dentro del TUI — una
        // instrucción de shell en una superficie donde se contesta con Enter, mandando a la gente
        // fuera de la pantalla donde ya le estaban preguntando.
        //
        // La pregunta y sus opciones son del dominio; **cómo se contesta es de cada superficie**. La
        // CLI lo pone en su `hint`, que es el campo que existe para eso; el TUI pinta su widget.
        return $linea;
    }

    /** Si esta herramienta es la contabilidad de la propia sesión — plan y pendientes. */
    private function esContabilidad(string $tool): bool
    {
        foreach (SessionBookkeeping::names() as $nombre) {
            if (McpProjector::toolName($nombre) === $tool) {
                return true;
            }
        }

        return false;
    }

    /**
     * La operación detrás de un nombre de herramienta.
     *
     * La traducción la hace {@see McpProjector::toolName()} y no una regla escrita aquí: si un día
     * cambia cómo se nombran las herramientas, esta compuerta dejaría de encontrar sus operaciones y
     * las dejaría pasar TODAS en silencio. Preguntarle al proyector es lo que impide que una
     * convención duplicada se desincronice sin ruido.
     */
    /**
     * Does THIS call really change the world, once its ceiling is composed (greenhouse decisions/0058)?
     *
     * It STARTS from the declared flag and only ever LOWERS it: a declared read stays a read
     * (composition can only descend, never raise, so a read never becomes a mutation — and an
     * operation with no EffectProfile carries the conservative maximum, which must not turn its
     * honest `mutating: false` into a pause). A declared mutation becomes a read only when a certified
     * rehearsal descends it to None. The certificate produces the observed axes; the app's policy and
     * the session's OWNER produce authority, but a mutation descent needs only a valid signed
     * certificate, so this works even before an owner exists.
     *
     * @param array<string, mixed> $arguments
     */
    private function componer(Operation $operacion, array $arguments): ?ProfileComposition
    {
        if (! $operacion->mutating) {
            return null;
        }

        // THE FOURTH PRODUCER (greenhouse decisions/0080): for a promotion, the workspace that owns the
        // diff attests what the change is made of, and composition may lower `subject` to it — so a
        // grant a human tightened to `subject ≤ configuration` can finally admit a configuration-only
        // promotion while a code diff still pauses. Null for everything that is not a promotion, and
        // for a promotion the workspace cannot vouch for: then the declared ceiling holds.
        $subject = new CallSubject(
            $operacion->name,
            $operacion->handlerDigest(),
            $this->policyProvider?->authorityPolicy(),
            $this->hechosDelDuenio(),
            $this->trialRouter?->planFor($operacion, $arguments)?->confinement,
            subjectAttestation: $this->trialRouter?->attestationFor($operacion, $arguments),
        );
        $composicion = $operacion->effectCeiling()->composeForCall($arguments, $subject);

        // WHEN THE COMPOSITION LOWERED THE CEILING, THE RECEIPT BECOMES A FACT (greenhouse
        // decisions/0059). Only when a descent actually reduced something: a call that changed no
        // axis is not a line worth keeping. The receipt is what the human audits later — why the
        // agent did not have to ask — read from the stream, cited, not reconstructed.
        if ($composicion->reductions !== []) {
            $this->sessions->recordCeilingComposition($this->session->id, [
                'operation' => $operacion->name,
                'reductions' => array_map(static fn (AxisReduction $r): array => [
                    'axis' => $r->axis,
                    'from' => $r->from,
                    'to' => $r->to,
                    'producer' => $r->producer,
                    'provenance' => $r->provenance,
                ], $composicion->reductions),
            ]);
        }

        return $composicion;
    }

    /**
     * The verified facts of the session's owner, admitted live — or null when it has no owner.
     *
     * greenhouse decisions/0056: what the session stores is the signed assertion, and the grade is
     * produced by re-verifying it here, each time. Without an owner there are no facts, and authority
     * does not descend — the gate behaves exactly as it did before this existed.
     */
    /**
     * The diff a promotion would apply, or `null` when this pause is not a promotion.
     *
     * Read live from the trial named in the arguments (greenhouse decisions/0069): the human sees
     * what enters at the moment of authorising it, and the house — not the model — puts it there.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, string>|null
     */
    private function cambiosDeUnaPromocion(string $operation, array $arguments): ?array
    {
        if ($operation !== 'sandbox:promote' || $this->trialRouter === null) {
            return null;
        }
        $workspace = $arguments['workspace'] ?? null;
        if (! \is_string($workspace) || $workspace === '') {
            return null;
        }

        $cambios = $this->trialRouter->diffForWorkspace($workspace);

        return $cambios === [] ? null : $cambios;
    }

    private function hechosDelDuenio(): ?ContextFacts
    {
        $asercion = $this->session->ownershipAssertion();
        if ($asercion === null || $this->identity === null) {
            return null;
        }

        return $this->identity->admit($asercion, $this->session->id);
    }

    /**
     * The operational contract of a tool: an app Operation, else a producer-declared one.
     *
     * The name stays `operationFor` while its meaning widened (greenhouse decisions/0078): it no
     * longer «finds an app Operation», it RESOLVES THE CONTRACT this call is judged by. Step 1 is the
     * app's own catalogue. Step 2 asks each authorized producer — the notebook, delegation — for the
     * contract IT declares, so a tool that never reaches `Operations::all()` is still judged by what
     * its producer states rather than allowed by its name. Neither step matching is the one genuinely
     * unjudgeable case, and the caller fails closed on it.
     */
    private function operationFor(string $tool): ?Operation
    {
        foreach ($this->operations as $operacion) {
            if (McpProjector::toolName($operacion->name) === $tool) {
                return $operacion;
            }
        }

        foreach ($this->contractProducers as $productor) {
            $contrato = $productor->contractFor($tool);
            if ($contrato !== null) {
                return $contrato;
            }
        }

        return null;
    }

    /**
     * Does this contract touch only the session's own log — a self-legibility effect, not a world one?
     *
     * True when the declared profile has no externality, no authority, and subject Data, and the
     * operation asks for no confirmation: an append to the session's own bookkeeping (greenhouse
     * evidence/0189). It is read from the CONTRACT and not from a tool name, so any producer whose
     * profile is a benign self-log inherits the treatment and delegation — which carries `WriteAsUser`
     * and `requiresConfirmation` — never does. Read defensively, like the rest of this gate: this
     * `src/` travels with `composer create-project` and can meet a vendor whose `EffectProfile` predates
     * an axis; an unreadable one is simply «not a benign self-log», which is the safe answer.
     */
    private function esBitacoraPropia(Operation $operacion): bool
    {
        if ($operacion->requiresConfirmation) {
            return false;
        }

        $perfil = $operacion->effectCeiling();

        return $perfil->externality === Externality::None
            && $perfil->authority === Authority::None
            && $perfil->subject === Subject::Data;
    }
}
