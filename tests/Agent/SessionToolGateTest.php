<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\ContractProducer;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionObservation;
use Milpa\Agent\SessionStore;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * La compuerta que detiene al agente antes de que cambie algo (P16.4/P16.5).
 *
 * Lo que se mide aquí no es la política —eso vive probado en `milpa/agent`— sino que negar APENDE:
 * la sesión queda esperando y la pregunta sobrevive al proceso. Una negativa que sólo existiera en el
 * texto de la respuesta se perdería en cuanto cerraras la terminal, y entonces «el agente pidió
 * permiso» sería una frase sin nada detrás.
 */
final class SessionToolGateTest extends TestCase
{
    /** @return list<Operation> */
    private function operaciones(): array
    {
        return [
            new Operation('plugins_list', 'Lista', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []]),
            new Operation('make', 'Andamia', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true),
            new Operation('plugins_remove', 'Quita', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true, requiresConfirmation: true),
        ];
    }

    private function compuerta(
        SessionStore $almacen,
        string $id,
        AutonomyMode $modo = AutonomyMode::Ask,
        ?\DateInterval $ventana = null,
    ): SessionToolGate {
        $almacen->start($id, 'x', $modo);
        $sesion = $almacen->load($id);
        self::assertNotNull($sesion);

        return new SessionToolGate($almacen, $sesion, $this->operaciones(), permissionWindow: $ventana);
    }

    /**
     * THE LOG KEEPS WHAT THE TOOL ANSWERED, and says how long that was.
     *
     * This test used to assert the opposite half — that a big result arrived shortened — and it was
     * right until the cap moved to the window, which is the consumer whose space is actually scarce.
     * A surface builds its data view from this value, and a half JSON is a text rather than a table:
     * measured on cattle, `capabilities` answered 2004 characters, the log kept 600, and the human
     * saw no table at all.
     *
     * What survives from that slice is the declaration itself. It now equals the stored length, and
     * that is the point: it is the mechanism that would confess any future cap, kept because it
     * stopped having something to confess rather than removed for it.
     */
    public function testABigResultReachesTheLogWholeWithItsLength(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $compuerta = $this->compuerta($almacen, 's1');

        $compuerta->recorded('plugins_list', [], str_repeat('x', 2026), true);

        $r = SessionObservation::of($eventos, 's1')->answers['returned']['value'][0];

        self::assertSame(2026, $r['resultChars'], 'lo que la herramienta contestó');
        self::assertSame(2026, $r['chars'], 'y lo que el log guardó');
        self::assertFalse($r['truncated'], 'el log no corta; el que recorta es la ventana');
    }

    /**
     * A CALL THAT ONLY ASKED IS RECORDED AS HAVING ASKED.
     *
     * The gate holds the tool's answer, so it is where the distinction can be made — and it asks the
     * protocol's own predicate rather than reading `requires_confirmation` a second time here. Two
     * readers of one rule disagree the day either changes.
     */
    public function testACallThatOnlyAskedForConfirmationIsRecordedAsAsking(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $compuerta = $this->compuerta($almacen, 's1');

        $compuerta->recorded('make', ['what' => 'entity'], (string) json_encode([
            'requires_confirmation' => true,
            'confirm_token' => 'fc6c5582',
        ]), true);
        $compuerta->recorded('make', ['what' => 'entity', 'confirm_token' => 'fc6c5582'], (string) json_encode([
            'ok' => true,
        ]), true);

        $ll = SessionObservation::of($eventos, 's1')->answers['called']['value'];

        self::assertTrue($ll[0]['awaitingConfirmation'], 'la primera sólo pidió');
        self::assertFalse($ll[1]['awaitingConfirmation'], 'la segunda hizo');
    }

    /** Y la cuenta que gobierna el consentimiento da UNA, no dos. */
    public function testCountingCompletedMutationsGivesOneForOneWrite(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $compuerta = $this->compuerta($almacen, 's1');

        $compuerta->recorded('make', [], '{"requires_confirmation":true,"confirm_token":"x"}', true);
        $compuerta->recorded('make', ['confirm_token' => 'x'], '{"ok":true}', true);

        $consumadas = array_filter(
            SessionObservation::of($eventos, 's1')->answers['called']['value'],
            static fn (array $l): bool => $l['mutating'] === true && $l['awaitingConfirmation'] !== true,
        );

        self::assertCount(1, $consumadas);
    }

    /** Y lo chico llega igual: el cambio es de dónde vive el tope, no de cuánto se guarda. */
    public function testAResultThatFitIsNotReportedAsCut(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $compuerta = $this->compuerta($almacen, 's1');

        $compuerta->recorded('plugins_list', [], 'ok: dos plugins', true);

        $r = SessionObservation::of($eventos, 's1')->answers['returned']['value'][0];

        self::assertFalse($r['truncated']);
        self::assertSame(15, $r['resultChars']);
    }

    /** Leer pasa, y no deja nada apendado: preguntar por una consulta gastaría la atención en lo que no importa. */
    public function testReadingPassesAndLeavesNoQuestionBehind(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1');

        self::assertNull($compuerta->refuse('plugins_list', []));
        self::assertNull($almacen->load('s1')?->question);
    }

    /**
     * Lo que muta se niega Y SE APENDA: la sesión queda esperando.
     *
     * Las dos mitades importan. Sin la negativa, la herramienta corre; sin el apendado, nadie puede
     * contestar desde otro proceso y la pausa muere con la terminal.
     */
    public function testAMutationIsRefusedAndTheSessionIsLeftWaiting(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1');

        $motivo = $compuerta->refuse('make', ['what' => 'plugin', 'plugin' => 'Cobranza']);

        self::assertNotNull($motivo);
        self::assertStringContainsString('make', $motivo);
        self::assertStringContainsString('Cobranza', $motivo, 'la negativa dice sobre QUÉ');
        // CÓMO SE CONTESTA YA NO VIENE AQUÍ, y es deliberado: esa línea viajaba dentro del texto de
        // la pausa, así que una instrucción de shell aparecía TAMBIÉN dentro del TUI, mandando a la
        // gente fuera de la pantalla donde le estaban preguntando. La pregunta y sus opciones son del
        // dominio; cómo se contesta lo pone cada superficie —la CLI en su `hint`, el TUI en su widget.
        self::assertStringNotContainsString('agent:answer', $motivo, 'cómo contestar es de la superficie');
        self::assertNotNull($almacen->load('s1')?->question, 'pero la pregunta sí quedó abierta');
        self::assertSame(['sí', 'no'], $almacen->load('s1')?->question?->options, 'con sus opciones, que es lo que la superficie necesita');

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertFalse($sesion->isRunnable(), 'la sesión quedó esperando');
        self::assertSame('perm:make', $sesion->question?->id);
    }

    /** Con el permiso ya otorgado, pasa sin preguntar de nuevo. */
    public function testAGrantedOperationPassesWithoutAskingAgain(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'x');
        $almacen->grant('s1', 'make');
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate($almacen, $sesion, $this->operaciones());

        self::assertNull($compuerta->refuse('make', []));
        self::assertNull($almacen->load('s1')?->question, 'no volvió a preguntar');
    }

    /** En `auto`, lo que muta y no exige firma sigue de largo. */
    public function testInAutoModeAMutationDoesNotStop(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1', AutonomyMode::Auto);

        self::assertNull($compuerta->refuse('make', []));
    }

    /**
     * LA FIRMA SE DETIENE INCLUSO EN `auto`, y la pregunta no ofrece un «sí».
     *
     * Es la línea entre autonomía y cheque en blanco, medida donde se aplica y no sólo donde se
     * decide: la política ya lo dice, y esta prueba verifica que la compuerta la honra.
     */
    public function testASignatureStopsEvenInAutoAndOffersNoWayToApproveFromHere(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1', AutonomyMode::Auto);

        $motivo = $compuerta->refuse('plugins_remove', ['name' => 'X']);

        self::assertNotNull($motivo);
        self::assertStringContainsString('--sign', $motivo);
        self::assertStringNotContainsString('agent:answer', $motivo, 'no hay respuesta que autorice esto');

        $sesion = $almacen->load('s1');
        self::assertSame('sign:plugins_remove', $sesion?->question?->id);
    }

    /**
     * A tool this app CANNOT JUDGE is refused as UNJUDGEABLE — the gate fails closed (H-GATE-1).
     *
     * This test asserted the OPPOSITE until greenhouse decisions/0078: that an external-registry tool
     * was «left to its own gate», allowed here on the theory that a downstream scope gate would judge
     * it. That delegated authority did not exist in the governed path — `ConsentBridge::callTool`
     * builds the context with `ToolContext::cli()` = `scopes: ['*']`, so nothing downstream really
     * judges it — and an unjudgeable call ran with NO judge (masked at evidence/0314 only by the
     * registry's accidental «Tool not found»). Registering an executable tool is not enough to acquire
     * authority: it needs a judgeable `Operation`. So the tool is now refused, distinguishably.
     */
    public function testAToolThisAppCannotJudgeIsRefusedAsUnjudgeable(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1');

        $motivo = $compuerta->refuse('herramienta_de_otro_registro', []);

        self::assertNotNull($motivo, 'no Operation can state its effect, so the gate refuses');
        self::assertStringContainsString(
            SessionToolGate::UNJUDGEABLE,
            $motivo,
            'and it refuses as the UNJUDGEABLE state, recognizably',
        );
    }

    /**
     * THE DIRECT FALSIFIER: a tool the executor's registry could run, but that this app does not
     * declare as an `Operation`, is refused AT THE GATE — never allowed, never executed.
     *
     * It isolates the gate from the registry's «Tool not found»: `externally_registered_tool` is the
     * shape of a tool some other registry contributed and would happily execute — the gate is the only
     * thing that stops it. `AutonomyMode::Auto` removes even the permission question from the picture,
     * so what blocks the call is JUDGEABILITY and nothing else. It is a hard block, not a pause: there
     * is nothing a human decides about a call no producer can characterise, and a pause could be
     * answered «yes» and then run unjudged — the very hole this closes.
     */
    public function testAToolTheRegistryCouldRunButThisAppCannotJudgeIsRefusedAtTheGate(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1', AutonomyMode::Auto);

        $motivo = $compuerta->refuse('externally_registered_tool', ['x' => 1]);

        self::assertNotNull($motivo, 'the gate itself refuses; it never returns Allow');
        self::assertStringContainsString(SessionToolGate::UNJUDGEABLE, $motivo);
        self::assertNull(
            $almacen->load('s1')?->question,
            'it does not pause — a call nobody can judge is a hard block, not a question',
        );
    }

    /**
     * THE CONTRACT PATH SURVIVES: a tool that did NOT ship with this app, but whose name resolves to a
     * known `Operation`, is judged NORMALLY — extensibility is not the casualty of failing closed.
     *
     * The criterion is a contract, not provenance: bring an `Operation` whose `McpProjector::toolName`
     * matches and the gate judges it by its declared effect — a read passes, a mutation asks — and the
     * refusal, when there is one, is an ordinary permission pause, never the UNJUDGEABLE state.
     */
    public function testAnExternalToolThatResolvesToAKnownOperationIsJudgedNormally(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'x', AutonomyMode::Ask);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        // Two operations a third party contributed to this app's catalogue — the gate judges by these.
        $compuerta = new SessionToolGate($almacen, $sesion, [
            new Operation('vendor_probe', 'A read a third party added', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []]),
            new Operation('vendor_write', 'A mutation a third party added', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true),
        ]);

        self::assertNull($compuerta->refuse('vendor_probe', []), 'a resolvable read is judged and allowed');

        $mutacion = $compuerta->refuse('vendor_write', []);
        self::assertNotNull($mutacion, 'a resolvable mutation is judged and asks, per its effect');
        self::assertStringNotContainsString(
            SessionToolGate::UNJUDGEABLE,
            $mutacion,
            'and it is judged, not UNJUDGEABLE — the contract made it legible',
        );
    }

    /**
     * AUDIT CAN TELL THE TWO REFUSALS APART. «I know this is forbidden» and «I cannot judge this» both
     * block, but they are not the same fact: an ordinary permission pause never carries the UNJUDGEABLE
     * marker, and the unjudgeable refusal always does.
     */
    public function testTheUnjudgeableReasonIsDistinguishableFromAnOrdinaryRefusal(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1', AutonomyMode::Ask);

        $ordinaria = $compuerta->refuse('make', ['what' => 'plugin', 'plugin' => 'Cobranza']);
        $sinJuez = $compuerta->refuse('externally_registered_tool', []);

        self::assertNotNull($ordinaria);
        self::assertNotNull($sinJuez);
        self::assertNotSame($ordinaria, $sinJuez, 'two facts, two reasons');
        self::assertStringNotContainsString(
            SessionToolGate::UNJUDGEABLE,
            $ordinaria,
            'a forbidden/ask refusal is not the unjudgeable one',
        );
        self::assertStringContainsString(
            SessionToolGate::UNJUDGEABLE,
            $sinJuez,
            'and the unjudgeable one is recognizably marked',
        );
    }

    /**
     * Con ventana declarada, la pregunta nace con plazo; sin ella, espera para siempre.
     *
     * Es la línea que ARMA la caducidad. Sin ella el mecanismo existe, está probado, y no lo usa
     * nadie — el patrón que este repositorio lleva un mes cazando: una capacidad a la que le falta la
     * línea que la enchufa.
     */
    public function testTheHostsWindowReachesTheQuestionAsADeadline(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $this->compuerta($almacen, 's1', ventana: new \DateInterval('PT4H'))->refuse('make', []);

        $pregunta = $almacen->load('s1')?->question;

        self::assertNotNull($pregunta?->expiresAt, 'la ventana del host tiene que llegar hasta aquí');
        self::assertFalse($pregunta->hasExpired(new \DateTimeImmutable('+3 hours')));
        self::assertTrue($pregunta->hasExpired(new \DateTimeImmutable('+5 hours')));
    }

    /** Sin ventana, la pregunta no lleva plazo — que es lo que hacía antes de que esto existiera. */
    public function testWithoutAWindowTheQuestionCarriesNoDeadline(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $this->compuerta($almacen, 's1')->refuse('make', []);

        self::assertNull($almacen->load('s1')?->question?->expiresAt);
    }

    // ── EL CONTRATO DE INTENCIÓN (ADR-0044) ─────────────────────────────────────────────────────

    /** @return list<Operation> */
    private function operacionesConContrato(): array
    {
        return [
            new Operation(
                'plugins.disable',
                'Apaga',
                static fn (array $i): array => ['ok' => true],
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: true,
                namedTarget: 'name',
            ),
        ];
    }

    private function compuertaConPeticion(SessionStore $almacen, string $id, string $peticion, AutonomyMode $modo = AutonomyMode::Auto): SessionToolGate
    {
        $almacen->start($id, $peticion, $modo);
        $sesion = $almacen->load($id);
        self::assertNotNull($sesion);

        return new SessionToolGate($almacen, $sesion, $this->operacionesConContrato(), petition: $peticion);
    }

    /**
     * Un objetivo que la petición no nombra NO se ejecuta: se pregunta, con todo adentro.
     *
     * Es la autoridad que Q-P19-K midió como inexistente — question_asked salió 0 de 160 mientras
     * tres corridas mataban un plugin que nadie nombró. La pregunta lleva la operación y los
     * argumentos porque quien conteste «sí» tiene que saber exactamente qué autoriza.
     */
    public function testATargetThePetitionDoesNotNameBecomesAFormalQuestion(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuertaConPeticion($almacen, 's1', 'Quita el plugin viejo.');

        self::assertNotNull($compuerta->refuse('plugins_disable', ['name' => 'HelloPlugin']));

        $pregunta = $almacen->load('s1')?->question;
        self::assertNotNull($pregunta, 'la duda tiene que PAUSAR, no narrar');
        self::assertSame('target_not_named', $pregunta->reason, 'el motivo viaja como código, no como prosa');
        self::assertStringContainsString('HelloPlugin', $pregunta->question);
        self::assertNotNull($pregunta->why);
        self::assertStringContainsString('plugins.disable', $pregunta->why, 'la operación va adentro');
        self::assertStringContainsString('HelloPlugin', $pregunta->why, 'y los argumentos también');
    }

    /** El objetivo nombrado pasa — sin distinguir mayúsculas, porque el humano no teclea camelCase. */
    public function testANamedTargetPassesWithoutAQuestion(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuertaConPeticion($almacen, 's1', 'Deshabilita el plugin helloplugin, por favor.');

        self::assertNull($compuerta->refuse('plugins_disable', ['name' => 'HelloPlugin']));
        self::assertNull($almacen->load('s1')?->question);
    }

    /**
     * `auto` NO exime el contrato: exime pedir permiso, no entender qué se pidió.
     *
     * Es la cláusula 3 de ADR-0044, y es la diferencia entera con la política de permisos: en las
     * 160 corridas de K el modo auto dejó pasar toda mutación, y por eso la verificación de
     * intención vive ANTES de la política y no adentro.
     */
    public function testAutoModeDoesNotWaiveTheIntentContract(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuertaConPeticion($almacen, 's1', 'Limpia lo que no se usa.', AutonomyMode::Auto);

        self::assertNotNull($compuerta->refuse('plugins_disable', ['name' => 'OperationsHttp']));
        self::assertSame('target_not_named', $almacen->load('s1')?->question?->reason);
    }

    /** Una operación sin contrato se comporta como siempre — declarar es opt-in por operación. */
    public function testAnOperationWithoutAContractIsUntouched(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'Limpia lo que no se usa.', AutonomyMode::Auto);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        // `make` muta y NO declara namedTarget: en auto pasa, como pasaba.
        $compuerta = new SessionToolGate($almacen, $sesion, $this->operaciones(), petition: 'Limpia lo que no se usa.');

        self::assertNull($compuerta->refuse('make', ['name' => 'Cosa']));
    }

    /** Sin petición contra qué comparar, el contrato no opina — sesiones viejas siguen corriendo. */
    public function testWithoutAPetitionTheContractStaysQuiet(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'x', AutonomyMode::Auto);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate($almacen, $sesion, $this->operacionesConContrato());

        self::assertNull($compuerta->refuse('plugins_disable', ['name' => 'HelloPlugin']));
    }

    /**
     * EL CICLO CIERRA: la confirmación del humano ES el nombramiento.
     *
     * Pregunta → respuesta «sí» → la re-propuesta pasa. Sin esto, la misma llamada volvería a pausar
     * —la petición sigue sin nombrar al objetivo— y una pregunta que contestarla no destraba nada es
     * teatro con acta. La confirmación se lee del hecho (reason + why heredados a la decisión), nunca
     * del texto de la pregunta.
     */
    public function testAYesFromTheHumanNamesTheTargetAndTheRetryPasses(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuertaConPeticion($almacen, 's1', 'Quita el plugin viejo.');

        // Primera propuesta: pausa.
        self::assertNotNull($compuerta->refuse('plugins_disable', ['name' => 'HelloPlugin']));
        $pregunta = $almacen->load('s1')?->question;
        self::assertNotNull($pregunta);

        // El humano confirma.
        $almacen->answer('s1', $pregunta->id, 'sí');

        // La re-propuesta, sobre la sesión YA CONFIRMADA, pasa el contrato.
        $confirmada = $almacen->load('s1');
        self::assertNotNull($confirmada);
        $compuerta2 = new SessionToolGate($almacen, $confirmada, $this->operacionesConContrato(), petition: 'Quita el plugin viejo.');

        self::assertNull($compuerta2->refuse('plugins_disable', ['name' => 'HelloPlugin']));
    }

    /**
     * Un «no» NO nombra nada: la re-propuesta del mismo objetivo vuelve a pausar.
     *
     * Si el humano dijo que no y el actor insiste, lo que corresponde es volver a preguntar — no
     * dejar pasar por cansancio ni negar para siempre por una respuesta que era sobre ESA propuesta.
     */
    public function testANoDoesNotNameAnythingAndTheRetryPausesAgain(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuertaConPeticion($almacen, 's1', 'Quita el plugin viejo.');

        self::assertNotNull($compuerta->refuse('plugins_disable', ['name' => 'HelloPlugin']));
        $pregunta = $almacen->load('s1')?->question;
        self::assertNotNull($pregunta);

        $almacen->answer('s1', $pregunta->id, 'no');

        $despues = $almacen->load('s1');
        self::assertNotNull($despues);
        $compuerta2 = new SessionToolGate($almacen, $despues, $this->operacionesConContrato(), petition: 'Quita el plugin viejo.');

        self::assertNotNull($compuerta2->refuse('plugins_disable', ['name' => 'HelloPlugin']));
    }

    /** La confirmación es POR OBJETIVO: el «sí» a HelloPlugin no nombra a OperationsHttp. */
    public function testAConfirmationNamesOneTargetNotAllOfThem(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuertaConPeticion($almacen, 's1', 'Quita el plugin viejo.');

        self::assertNotNull($compuerta->refuse('plugins_disable', ['name' => 'HelloPlugin']));
        $pregunta = $almacen->load('s1')?->question;
        self::assertNotNull($pregunta);
        $almacen->answer('s1', $pregunta->id, 'sí');

        $despues = $almacen->load('s1');
        self::assertNotNull($despues);
        $compuerta2 = new SessionToolGate($almacen, $despues, $this->operacionesConContrato(), petition: 'Quita el plugin viejo.');

        self::assertNotNull(
            $compuerta2->refuse('plugins_disable', ['name' => 'OperationsHttp']),
            'el sí fue sobre HelloPlugin — otro objetivo es otra pregunta',
        );
    }

    /**
     * EL TECHO DEL LINAJE GANA SOBRE EL MODO DECLARADO DEL HIJO (Q-P19-P, invariante 2 de la spec).
     *
     * Un hijo en `auto` bajo un padre en `ask` pausa ante su primera mutación. Sin esto, spawnearle
     * un hijo `auto` a una sesión supervisada sería la escalada de privilegio con un paso extra —
     * y el juez que la impide existía desde antes; lo que se prueba aquí es que el camino real lo
     * consulta.
     */
    public function testAChildInAutoUnderAnAskParentPausesBeforeMutating(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'la tarea grande', AutonomyMode::Ask);
        $almacen->start('hijo', 'la sub-tarea', AutonomyMode::Auto, parentId: 'padre');
        $sesion = $almacen->load('hijo');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate($almacen, $sesion, $this->operaciones());

        self::assertNotNull($compuerta->refuse('make', []), 'el techo del linaje gana');
        self::assertFalse($almacen->load('hijo')?->isRunnable() ?? true, 'el hijo quedó esperando');
    }

    /** El control: bajo un padre en `auto`, el hijo `auto` sigue de largo — el techo no estorba. */
    public function testAChildInAutoUnderAnAutoParentRunsWithoutPausing(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'la tarea grande', AutonomyMode::Auto);
        $almacen->start('hijo', 'la sub-tarea', AutonomyMode::Auto, parentId: 'padre');
        $sesion = $almacen->load('hijo');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate($almacen, $sesion, $this->operaciones());

        self::assertNull($compuerta->refuse('make', []));
    }

    /**
     * EL TECHO SE REPROYECTA, NO SE FOTOGRAFÍA (doctrina de Q-P20-B, falsificador 3 de Q-P19-P).
     *
     * Si el padre baja a `ask` a media corrida del hijo, la SIGUIENTE herramienta del hijo ya lo
     * siente — con la misma compuerta, sin reconstruir nada. Un techo cacheado en construcción se
     * quedaría viejo exactamente cuando el humano acaba de decidir supervisar.
     */
    public function testTheCeilingIsReprojectedNotPhotographed(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'la tarea grande', AutonomyMode::Auto);
        $almacen->start('hijo', 'la sub-tarea', AutonomyMode::Auto, parentId: 'padre');
        $sesion = $almacen->load('hijo');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate($almacen, $sesion, $this->operaciones());
        self::assertNull($compuerta->refuse('make', []), 'con el padre en auto, pasa');

        $almacen->setMode('padre', AutonomyMode::Ask);

        self::assertNotNull(
            $compuerta->refuse('make', []),
            'el padre bajó a ask y la misma compuerta lo siente en la siguiente llamada',
        );
    }

    /**
     * LA OBLIGACIÓN DE ORDEN CIERRA LA MESA, y lo obligado sigue siendo llamable.
     *
     * El orden dentro de `refuse()` es lo que se fija aquí: la contabilidad se exime ANTES que esta
     * compuerta, porque lo obligado casi siempre ES contabilidad —«planea antes de empezar»— y
     * gatearla volvería la obligación imposible de cumplir. Una mesa que no puede abrirse nunca es
     * peor que una abierta.
     */
    public function testAnOrderObligationHoldsBackTheRestAndNotItself(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('j', 'x', AutonomyMode::Auto);
        $sesion = $almacen->load('j');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate(
            $almacen,
            $sesion,
            $this->operaciones(),
            compuertaPrevia: new \Milpa\AppRuntime\Agent\PrerequisiteGate(['plan']),
            // El productor de la bitácora: `plan` se resuelve por su contrato y pasa como bitácora
            // propia. Sin él, `plan` no sería una operación ni un contrato de productor, y la compuerta
            // —que ya falla cerrado— lo negaría, cerrando la mesa que la obligación venía a abrir.
            contractProducers: [new \Milpa\AppRuntime\Agent\SessionBookkeeping($almacen, 'j')],
        );

        self::assertIsString($compuerta->refuse('make', []), 'trabajar no procede sin plan');
        self::assertNull($compuerta->refuse('plan', []), 'y planear sí, o no se podría cumplir');
    }

    /**
     * Y la compuerta APRENDE de la contabilidad, aunque la contabilidad no se apunte como llamada.
     *
     * `recorded()` corta temprano para no cobrarle a la ventana dos veces el mismo hecho. Si la
     * compuerta se enterara después de ese corte, no vería nunca que se cumplió: la mesa quedaría
     * cerrada para siempre por el mismo `plan` que venía a abrirla.
     */
    public function testTheGateLearnsFromBookkeepingEvenThoughItIsNotCountedAsACall(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('j', 'x', AutonomyMode::Auto);
        $sesion = $almacen->load('j');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate(
            $almacen,
            $sesion,
            $this->operaciones(),
            compuertaPrevia: new \Milpa\AppRuntime\Agent\PrerequisiteGate(['plan']),
        );

        $compuerta->recorded('plan', ['plan' => 'primero esto'], '{"ok":true}', true);

        self::assertNull($compuerta->refuse('make', []), 'con el plan escrito, la mesa abre');
    }
    /**
     * A resume is not a new petition (greenhouse decisions/0009): the intent contract compares
     * the named target against the STANDING ask — this run's prompt OR the session's goal. The
     * three non-converters of the rental series all died on «the petition does not name X»
     * while the petition was literally «sigue» and the goal named X in lowercase.
     */
    public function testTheIntentContractReadsTheStandingGoalOnResumes(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'construye un plugin para la agencia de viajes', AutonomyMode::Auto);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate(
            $almacen,
            $sesion,
            [new Operation('foundation_found', 'Funda', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true, namedTarget: 'domain')],
            petition: 'sigue',
        );

        self::assertNull($compuerta->refuse('foundation_found', ['domain' => 'Agencia de Viajes']));
        self::assertNull($almacen->load('s1')?->question);
    }

    /** A target named in NEITHER the prompt nor the goal still pauses — ADR-0044 lives. */
    public function testATargetNamedNowhereStillPauses(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'construye un plugin para la agencia de viajes', AutonomyMode::Auto);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate(
            $almacen,
            $sesion,
            [new Operation('foundation_found', 'Funda', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true, namedTarget: 'domain')],
            petition: 'sigue',
        );

        self::assertNotNull($compuerta->refuse('foundation_found', ['domain' => 'Panadería Central']));
        self::assertNotNull($almacen->load('s1')?->question);
    }

    // ── JUDGING BY CONTRACT, NOT BY NAME (greenhouse decisions/0078, expanded) ───────────────────

    /**
     * THE GOLD FALSIFIER: the gate's verdict follows the CONTRACT a producer declares, not the tool's
     * name. Three cells, one tool name, produced by a test-only producer that is in NO allowlist and
     * backs NO app Operation.
     *
     *   1. the producer declares `requiresConfirmation: true` → the gate STOPS it (asks/enforces)
     *   2. ONLY the contract changes to a read              → the SAME tool is now ALLOWED
     *   3. the identical tool with NO producer               → nobody states its effect → UNJUDGEABLE
     *
     * If the gate recognised names, cell 2 could not flip cell 1's verdict, and cell 3 could not
     * differ from a cell that shares its name. It is the contract that moves the veredicto.
     */
    public function testTheGateJudgesAProducerContractByItsContractNotItsName(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        // Auto removes the mode's permission question from the picture, so only the contract decides.
        $almacen->start('s1', 'x', AutonomyMode::Auto);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $productorDe = static fn (Operation $op): ContractProducer => new class ($op) implements ContractProducer {
            public function __construct(private readonly Operation $op)
            {
            }

            public function contractFor(string $tool): ?Operation
            {
                return McpProjector::toolName($this->op->name) === $tool ? $this->op : null;
            }
        };
        $schema = ['type' => 'object', 'properties' => []];

        // CELL 1 — a contract that demands confirmation. The gate enforces it: a non-null refusal, and
        // NOT the unjudgeable state (it is judged — it asks).
        $exigeConfirmacion = new Operation(
            'gold_probe',
            'A delegation-like effect a producer declares',
            static fn (array $i): array => ['ok' => true],
            inputSchema: $schema,
            mutating: true,
            requiresConfirmation: true,
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Irreversible, Authority::WriteAsUser, subject: Subject::Executable),
        );
        $c1 = (new SessionToolGate($almacen, $sesion, [], contractProducers: [$productorDe($exigeConfirmacion)]))->refuse('gold_probe', []);
        self::assertNotNull($c1, 'the declared confirmation is enforced: the gate stops it');
        self::assertStringNotContainsString(SessionToolGate::UNJUDGEABLE, $c1, 'it is JUDGED, not unjudgeable');

        // CELL 2 — SAME tool name, ONLY the contract changed to a read → allowed.
        $soloLee = new Operation(
            'gold_probe',
            'The same tool, now a read',
            static fn (array $i): array => ['ok' => true],
            inputSchema: $schema,
        );
        $c2 = (new SessionToolGate($almacen, $sesion, [], contractProducers: [$productorDe($soloLee)]))->refuse('gold_probe', []);
        self::assertNull($c2, 'same name, a read contract now: allowed — the verdict follows the CONTRACT');

        // CELL 3 — the identical tool with NO producer → nobody can state its effect → UNJUDGEABLE.
        $c3 = (new SessionToolGate($almacen, $sesion, [], contractProducers: []))->refuse('gold_probe', []);
        self::assertNotNull($c3);
        self::assertStringContainsString(SessionToolGate::UNJUDGEABLE, $c3, 'no producer, no Operation: the gate cannot judge it');
    }

    /**
     * THE INTERNAL-TOOLS BATTERY: the real producers, judged by their real contracts.
     *
     * These tools reach the gate through the registry's `$extra`, never `Operations::all()`. Under the
     * old null→ALLOW they ran without ever asking the consent their contract declares — a dead policy.
     * Now the gate resolves each from its authorized producer and judges it: delegation enforces its
     * `requiresConfirmation`, the read-only channels pass, and the session's own notebook passes as
     * self-legibility (by its benign profile, not by its name). A tool no producer claims still fails
     * closed. Asserted in `Auto`, so a confirmation stopping is the contract talking, not the mode.
     */
    public function testTheInternalToolsAreJudgedByTheirProducersContracts(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'x', AutonomyMode::Auto);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $productores = [new SessionBookkeeping($almacen, 's1')];
        if (class_exists(SubAgentSpawner::class)) {
            $productores[] = new SubAgentSpawner($almacen, 's1', static fn (): array => throw new \LogicException('the gate resolves contracts, it does not run children'));
        }
        $compuerta = new SessionToolGate($almacen, $sesion, [], contractProducers: $productores);

        if (class_exists(SubAgentSpawner::class)) {
            // Delegation carries `requiresConfirmation: true` → enforced now, where null→ALLOW let it run.
            self::assertNotNull($compuerta->refuse('agent_spawn', ['brief' => 'x', 'done_when' => 'y']), 'spawn: its declared confirmation is finally enforced');
            self::assertNotNull($compuerta->refuse('agent_resume', ['sub_session' => 'k']), 'resume: same');

            // Read-only channels resolve to reads → allowed.
            self::assertNull($compuerta->refuse('agent_message', ['to' => 'a', 'message' => 'b']), 'message is read-only: allowed');
            self::assertNull($compuerta->refuse('agent_roles', []), 'roles is read-only: allowed');
        }

        // The session's own notebook: self-legibility, allowed by its benign contract — not by name.
        self::assertNull($compuerta->refuse('plan', ['plan' => 'do the thing']), 'plan is self-log: allowed');
        self::assertNull($compuerta->refuse('todo', ['text' => 'a thing']), 'todo is self-log: allowed');

        // A tool no producer claims and no Operation backs → UNJUDGEABLE (the falsifier still holds).
        $sinDuenio = $compuerta->refuse('some_unowned_tool', []);
        self::assertNotNull($sinDuenio);
        self::assertStringContainsString(SessionToolGate::UNJUDGEABLE, $sinDuenio);
    }
}
