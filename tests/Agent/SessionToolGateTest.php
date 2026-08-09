<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\Command\Operation;
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
     * Una herramienta que no viene de una operación de esta app se deja pasar.
     *
     * Esta política no sabe si muta, y negar lo que no se entiende volvería inútil cualquier registro
     * externo. El registro de herramientas tiene su propia compuerta de scopes, que sigue puesta.
     */
    public function testAToolThisAppDidNotDeclareIsLeftToItsOwnGate(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1');

        self::assertNull($compuerta->refuse('herramienta_de_otro_registro', []));
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
}
