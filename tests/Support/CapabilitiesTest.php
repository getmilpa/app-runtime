<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Operations\CapabilityOperations;
use Milpa\AppRuntime\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * El contrato por el que un paquete se anuncia solo.
 *
 * Lo que se prueba aquí es el LECTOR del contrato, contra vendors falsos escritos en disco: es la
 * única forma de ejercitar «no está instalado» sin desinstalar nada. Que el contrato esté bien
 * DECLARADO en los seis paquetes reales lo prueba el gate `verify-capability-contract.php`, que mira
 * los `composer.json` de verdad — dos preguntas distintas, dos instrumentos.
 */
final class CapabilitiesTest extends TestCase
{
    private string $vendor;

    /** @var list<string> extra vendors created per test — each one its own tree */
    private array $extras = [];

    protected function setUp(): void
    {
        $this->vendor = sys_get_temp_dir() . '/milpa-cap-' . bin2hex(random_bytes(6));
        mkdir($this->vendor . '/composer', 0o775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->vendor . '/composer/installed.json');
        @rmdir($this->vendor . '/composer');
        @rmdir($this->vendor);
        foreach ($this->extras as $extra) {
            exec('rm -rf ' . escapeshellarg($extra));
        }
        $this->extras = [];
    }

    /**
     * A vendor SEPARATE from the main one — the tests where the before and the after of an
     * install must be different trees, because in real life they are.
     *
     * @param list<array<string, mixed>> $paquetes
     */
    private function otroVendorCon(array $paquetes): string
    {
        $dir = sys_get_temp_dir() . '/milpa-cap-otro-' . bin2hex(random_bytes(6));
        mkdir($dir . '/composer', 0o775, true);
        file_put_contents(
            $dir . '/composer/installed.json',
            json_encode(['packages' => $paquetes], \JSON_THROW_ON_ERROR),
        );
        $this->extras[] = $dir;

        return $dir;
    }

    /** @param array<int, mixed> $paquetes — mixed on purpose: some tests feed deliberate garbage */
    private function vendorCon(array $paquetes): string
    {
        file_put_contents(
            $this->vendor . '/composer/installed.json',
            json_encode(['packages' => $paquetes], \JSON_THROW_ON_ERROR),
        );

        return $this->vendor;
    }

    /** @return array<string, mixed> */
    private function paquete(string $nombre, string $id, string $puerto, string $briefing = ''): array
    {
        return [
            'name' => $nombre,
            'extra' => ['milpa' => ['capability' => [
                'id' => $id,
                'title' => 'lo que sea',
                'unlocks' => ['algo'],
                'provides' => [$puerto],
                'briefing' => $briefing,
            ]]],
        ];
    }

    /** Un paquete instalado que declara su capacidad se ve, con lo que él dice de sí mismo. */
    public function testAnInstalledPackageAnnouncesItself(): void
    {
        $v = $this->vendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        self::assertTrue(Capabilities::installed('agent', $v));
        self::assertArrayHasKey('milpa/agent', Capabilities::declaredBy($v));
    }

    /** Y uno que no está, no. */
    public function testAPackageThatIsNotThereIsNotInstalled(): void
    {
        $v = $this->vendorCon([]);

        self::assertFalse(Capabilities::installed('agent', $v));
        self::assertSame([], Capabilities::declaredBy($v));
    }

    /**
     * Un paquete SIN el bloque `extra.milpa` se ignora, sin romper nada.
     *
     * La mayoría de lo que hay en `vendor/` es de terceros y nunca va a declarar nada. Un lector que
     * se cayera ante eso haría el contrato inservible en la única app que importa: una real.
     */
    public function testPackagesWithoutTheContractAreSimplyIgnored(): void
    {
        $v = $this->vendorCon([
            ['name' => 'symfony/console'],       // de terceros: nunca va a declarar nada
            ['sin' => 'nombre'],                 // una entrada sin `name`
            'ni siquiera un objeto',             // y una que no es ni un mapa
            ['name' => 'milpa/mudo', 'extra' => ['milpa' => ['capability' => ['sin' => 'id']]]],
            $this->paquete('milpa/agent', 'agent', 'agent.sessions'),
        ]);

        self::assertSame(['milpa/agent'], array_keys(Capabilities::declaredBy($v)));
    }

    /**
     * DOS paquetes en el mismo puerto se ven los dos. **No es un error aquí.**
     *
     * Es la pregunta de Rod hecha prueba: si mañana `milpa/agent-postgres` guarda las sesiones en
     * otro lado, el sistema tiene que poder NOMBRAR a los dos. Quién gana lo decide la app al
     * registrar el servicio — ADR-0037 dice por qué este archivo no es el lugar para decidirlo.
     */
    public function testTwoPackagesOnTheSamePortAreBothVisible(): void
    {
        $v = $this->vendorCon([
            $this->paquete('milpa/agent', 'agent', 'agent.sessions'),
            $this->paquete('milpa/agent-postgres', 'agent-postgres', 'agent.sessions'),
        ]);

        self::assertSame(
            ['milpa/agent', 'milpa/agent-postgres'],
            Capabilities::ports($v)['agent.sessions'] ?? [],
        );
    }

    /** El contexto que el agente recibe sale de lo instalado, no de una lista del framework. */
    public function testTheAgentBriefingComesFromWhatIsInstalled(): void
    {
        $v = $this->vendorCon([
            $this->paquete('milpa/agent', 'agent', 'agent.sessions', 'Esta app guarda sesiones.'),
            $this->paquete('milpa/data', 'persistence', 'data.repository', 'Esta app persiste.'),
            $this->paquete('milpa/mudo', 'mudo', 'x'),
        ]);

        self::assertSame(['Esta app guarda sesiones.', 'Esta app persiste.'], Capabilities::briefing($v));
    }

    /**
     * Sin `installed.json` no se adivina.
     *
     * Un catálogo inventado enseñaría un camino que nadie recorrió, y el agente lo seguiría.
     */
    public function testWithoutAnInstalledJsonNothingIsInvented(): void
    {
        self::assertSame([], Capabilities::declaredBy($this->vendor));
        self::assertFalse(Capabilities::installed('agent', $this->vendor));
    }

    /** El estado separa lo puesto de lo que falta, y lo que falta trae su comando armado. */
    public function testStateSeparatesWhatIsInstalledFromWhatIsMissing(): void
    {
        $v = $this->vendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        $estado = Capabilities::state($v);

        self::assertSame(['milpa/agent'], array_column($estado['installed'], 'package'));
        $faltan = array_column($estado['available'], 'package');
        self::assertContains('milpa/auth', $faltan);
        self::assertNotContains('milpa/agent', $faltan);
        foreach ($estado['available'] as $fila) {
            self::assertSame('composer require ' . $fila['package'], $fila['command']);
        }
    }

    /** @return callable */
    private function enable(): callable
    {
        foreach ((new CapabilityOperations())->operations() as $o) {
            if ($o->name === 'capabilities:enable') {
                $h = $o->handler;
                self::assertIsCallable($h);

                return $h;
            }
        }
        self::fail('capabilities:enable no se ofrece');
    }

    /**
     * `dry_run` ensena el comando EXACTO y no corre nada.
     *
     * Es lo que hace que el consentimiento se de sobre algo legible en vez de sobre un nombre: quien
     * autoriza ve la misma linea que se va a ejecutar.
     */
    public function testDryRunShowsTheExactCommandAndRunsNothing(): void
    {
        /** @var array<string, mixed> $r */
        $r = ($this->enable())(['capability' => 'milpa/agent', 'dry_run' => true]);

        // En este monorepo agent ESTA puesto, asi que la respuesta correcta es «ya esta» y no un
        // comando: pedir dos veces no es un error, y decir que fallo mandaria a buscar otra via.
        self::assertTrue($r['ok'] ?? false);
        self::assertStringContainsString('already installed', (string) ($r['hint'] ?? ''));

        // Y sobre un vendor donde SI falta, ensena el comando exacto y no corre nada.
        $corrio = false;
        $seco = Capabilities::install(
            'milpa/agent',
            $this->vendorCon([]),
            static function (string $c) use (&$corrio): array {
                $corrio = true;
                return [0, []];
            },
            dryRun: true,
        );

        self::assertTrue($seco['ok']);
        self::assertSame('composer require milpa/agent', $seco['command']);
        self::assertTrue($seco['dry_run']);
        self::assertFalse($corrio, 'dry-run no corre nada');
    }

    /** Sin nombre, no adivina cual. */
    public function testWithoutANameItDoesNotGuess(): void
    {
        /** @var array<string, mixed> $r */
        $r = ($this->enable())([]);

        self::assertFalse($r['ok'] ?? true);
        self::assertStringContainsString('capability', (string) ($r['error'] ?? ''));
    }

    /** The operation wrapper travels whole: dry_run reaches install through the declared handler. */
    public function testTheEnableHandlerPassesDryRunThrough(): void
    {
        /** @var array<string, mixed> $r */
        $r = ($this->enable())(['capability' => 'milpa/definitely-not-a-package', 'dry_run' => true]);

        self::assertFalse($r['ok'] ?? true);
        self::assertIsArray($r['available'] ?? null, 'the refusal travels with the valid answers');
    }

    /**
     * Un nombre que no existe se rechaza CON las respuestas validas.
     *
     * Decir solo «desconocida» obliga a correr una segunda operacion para saber que se debio decir —
     * un paso mas para quien ya se equivoco una vez.
     */
    public function testAnUnknownNameIsRefusedWithTheValidAnswers(): void
    {
        /** @var array<string, mixed> $r */
        $r = ($this->enable())(['capability' => 'milpa/teleport']);

        self::assertFalse($r['ok'] ?? true);
        self::assertStringContainsString('milpa/teleport', (string) ($r['error'] ?? ''));
        self::assertIsArray($r['available'] ?? null);
    }

    /** La pista aparece cuando falta algo, y calla cuando no. */
    public function testTheHintOnlyShowsUpWhenSomethingIsMissing(): void
    {
        self::assertNull(Capabilities::hintFor([]));
        self::assertNotNull(Capabilities::hintFor([['package' => 'milpa/agent']]));
    }

    /**
     * With a derived index, `available` answers from the REGISTRY — packages the offline floor
     * never heard of appear, their unlocks arrive filled, and the answer names its authority and
     * its date. Rank: installed.json > derived index > offline floor.
     */
    public function testWithAnIndexAvailableAnswersFromTheRegistryAndNamesItsSource(): void
    {
        $index = [
            'derived_at' => '2026-08-06T09:00:00+00:00',
            'capabilities' => [
                'acme/teleport' => [
                    'id' => 'teleport', 'title' => 'Teleportation', 'unlocks' => ['coa teleport'],
                    'provides' => ['transport.instant'], 'briefing' => '', 'version' => 'v2.0.0',
                ],
            ],
            'undeclared' => [],
        ];

        $estado = Capabilities::state($this->vendorCon([]), $index);

        $fila = null;
        foreach ($estado['available'] as $f) {
            if ($f['package'] === 'acme/teleport') {
                $fila = $f;
            }
        }
        self::assertNotNull($fila, 'un tercero del registro no aparece — el piso sigue mandando');
        self::assertSame(['coa teleport'], $fila['unlocks']);
        self::assertSame('v2.0.0', $fila['version']);
        self::assertStringContainsString('2026-08-06', (string) $estado['source']);
    }

    /** Without an index the floor answers — and SAYS it is the floor. */
    public function testWithoutAnIndexTheFloorAnswersAndSaysSo(): void
    {
        $estado = Capabilities::state($this->vendorCon([]));

        self::assertStringContainsString('offline floor', (string) $estado['source']);
    }

    /**
     * The promise the registry made travels into the install verification: what ARRIVED is
     * compared with what was PROMISED, and any difference is RECORDED — its own declaration about
     * itself is a claim, not a classification (GOV-11). The record is the deliverable; deciding
     * what to do about a mismatch has no evidence yet and stays deferred.
     */
    public function testADeliveryThatDiffersFromThePromiseIsRecorded(): void
    {
        $index = [
            'derived_at' => '2026-08-06T09:00:00+00:00',
            'capabilities' => [
                'milpa/agent' => [
                    'id' => 'agent', 'title' => 'Sessions', 'unlocks' => ['coa chat', 'agent:answer'],
                    'provides' => ['agent.sessions'], 'briefing' => '', 'version' => 'v0.6.0',
                ],
            ],
            'undeclared' => [],
        ];
        // The delivered vendor declares FEWER unlocks than the registry promised.
        $deliveredVendor = $this->otroVendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        $r = Capabilities::install('milpa/agent', $this->vendorCon([]), static fn (string $c): array => [0, ['ok']], index: $index, vendorAfter: $deliveredVendor);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('v0.6.0', $r['promised_version']);
        self::assertIsArray($r['promise_mismatch'] ?? null, 'la diferencia con la promesa no quedó registrada');
    }

    /**
     * `capabilities:refresh` exists with its effects said: it reaches a third-party registry, it
     * writes a deletable cache, and it never changes what the app can DO — only what it knows
     * exists. And it is not offered over http: a web request that makes this server fan out to a
     * registry is a surface nobody asked for.
     */
    public function testRefreshDeclaresItsEffectsAndStaysOffTheWeb(): void
    {
        $refresh = null;
        foreach ((new CapabilityOperations())->operations() as $o) {
            if ($o->name === 'capabilities:refresh') {
                $refresh = $o;
            }
        }

        self::assertNotNull($refresh, 'capabilities:refresh no se ofrece');
        self::assertTrue($refresh->mutating, 'escribe el artefacto y lo dice');
        self::assertNotContains('http', $refresh->surfaces ?? []);
    }

    /** A delivery that matches its promise carries no mismatch — the field only exists when true. */
    public function testADeliveryThatMatchesItsPromiseCarriesNoMismatch(): void
    {
        $cap = ['id' => 'agent', 'title' => 'Sessions', 'unlocks' => ['algo'], 'provides' => ['agent.sessions'], 'briefing' => '', 'version' => 'v0.6.0'];
        $index = ['derived_at' => '2026-08-06T09:00:00+00:00', 'capabilities' => ['milpa/agent' => $cap], 'undeclared' => []];
        $deliveredVendor = $this->otroVendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        $r = Capabilities::install('milpa/agent', $this->vendorCon([]), static fn (string $c): array => [0, ['ok']], index: $index, vendorAfter: $deliveredVendor);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertArrayNotHasKey('promise_mismatch', $r);
    }

    /**
     * La operación existe, no muta, y se ofrece en TODAS las superficies.
     *
     * Si el agente no la pudiera llamar, el sistema le estaría enseñando el camino sólo a quien ya
     * sabía dónde mirar — que es lo que esta operación existe para no hacer.
     */
    public function testTheOperationIsOfferedOnEverySurfaceAndChangesNothing(): void
    {
        $operaciones = (new CapabilityOperations())->operations();
        $porNombre = [];
        foreach ($operaciones as $o) {
            $porNombre[$o->name] = $o;
        }

        self::assertArrayHasKey('capabilities', $porNombre);
        self::assertFalse($porNombre['capabilities']->mutating);
        foreach (['cli', 'tui', 'mcp', 'http'] as $superficie) {
            self::assertContains($superficie, $porNombre['capabilities']->surfaces ?? []);
        }

        // Y la que INSTALA muta, y no se ofrece por HTTP: instalar un paquete corre codigo de la red
        // sobre la maquina, y un scope no sostiene eso — una superficie alcanzable desde cualquier
        // lado convierte un token filtrado en codigo arbitrario en el host.
        self::assertTrue($porNombre['capabilities:enable']->mutating);
        self::assertNotContains('http', $porNombre['capabilities:enable']->surfaces ?? []);
        self::assertContains('cli', $porNombre['capabilities:enable']->surfaces ?? []);
    }

    /**
     * Y la operación ARMA la respuesta: estado, puertos, y la pista sólo si falta algo.
     *
     * Se prueba contra un vendor falso porque en esta app no falta nada, y una rama que sólo corre
     * cuando falta algo no se ejercitaría nunca aquí.
     */
    public function testTheOperationBuildsTheAnswerWithTheHintWhenSomethingIsMissing(): void
    {
        $r = Capabilities::answer($this->vendorCon([]));

        self::assertTrue($r['ok']);
        self::assertSame([], $r['installed']);
        self::assertNotEmpty($r['available']);
        self::assertArrayHasKey('hint', $r, 'si falta algo, se dice cómo conseguirlo');

        $conTodo = Capabilities::answer($this->vendorCon([
            $this->paquete('milpa/agent', 'agent', 'agent.sessions'),
            $this->paquete('milpa/ai-gateway', 'agent-runs', 'agent.model'),
            $this->paquete('milpa/auth', 'identity', 'auth.verifier'),
            $this->paquete('milpa/data', 'persistence', 'data.repository'),
            $this->paquete('milpa/devtools', 'devtools', 'dev.doctor'),
            $this->paquete('milpa/mcp-server', 'mcp', 'surface.mcp'),
        ]));

        self::assertArrayNotHasKey('hint', $conTodo, 'y si no falta nada, no se pide trabajo');
    }

    /** Y contesta con el estado real de esta app, donde los seis opt-in están puestos. */
    public function testItAnswersWithTheRealStateOfThisApp(): void
    {
        $handler = (new CapabilityOperations())->operations()[0]->handler;
        self::assertIsCallable($handler);

        /** @var array<string, mixed> $r */
        $r = $handler([]);

        self::assertTrue($r['ok'] ?? false);
        self::assertNotEmpty($r['installed'] ?? []);
        self::assertArrayHasKey('agent.sessions', $r['ports'] ?? []);
    }

    /**
     * Instalar de verdad: el comando corre y la respuesta dice que se desbloqueo.
     *
     * El runner inyectado es la unica forma honesta de probar esto sin instalar nada — y sin el, la
     * mitad que importa de `capabilities:enable` no la ejercitaria ninguna prueba.
     */
    public function testInstallingRunsTheCommandAndSaysWhatItUnlocked(): void
    {
        $v = $this->vendorCon([]);   // nada puesto: milpa/agent esta disponible
        $corrio = null;

        $r = Capabilities::install('milpa/agent', $v, static function (string $cmd) use (&$corrio): array {
            $corrio = $cmd;

            return [0, ['ok']];
        });

        self::assertSame('composer require milpa/agent', $corrio, 'corrió el comando exacto');
        self::assertSame('composer require milpa/agent', $r['command']);
        // Y NO dice `ok` — porque el vendor de la prueba no cambió, así que la capacidad no apareció.
        // Ver el caso de abajo: el código de salida de composer es una afirmación de composer sobre
        // sí mismo, no la prueba de que la capacidad esté.
        self::assertFalse($r['ok'], 'el comando salió bien y la capacidad no llegó');
    }

    /**
     * UN CERO DE COMPOSER NO ES LA PRUEBA DE QUE LA CAPACIDAD LLEGÓ.
     *
     * El código de salida es una afirmación del subproceso sobre sí mismo; que la capacidad exista se
     * comprueba leyendo el disco después. Sin esta distinción, `capabilities:enable` devolvería un
     * éxito con `unlocked: []` —un campo siempre vacío, la clase de defecto que este repositorio lleva
     * una semana cazando— y quien preguntara «¿ya puedo?» recibiría un sí que nadie verificó.
     */
    public function testACleanExitCodeIsNotProofTheCapabilityArrived(): void
    {
        $r = Capabilities::install(
            'milpa/agent',
            $this->vendorCon([]),           // el vendor NO cambia: nada se instaló de verdad
            static fn (string $c): array => [0, ['Nothing to install or update']],
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no apareció', $r['error']);
        self::assertStringContainsString('milpa/agent', $r['error']);
        self::assertSame('composer require milpa/agent', $r['command'], 'con el comando que corrió, para poder repetirlo a mano');
        self::assertArrayNotHasKey('unlocked', $r, 'y sin una lista vacía que parezca un resultado');
    }

    /**
     * Cuando SÍ aparece, lo dice con lo que desbloqueó — leído del disco DESPUÉS, no del comando.
     *
     * El runner es quien «instala»: escribe el `installed.json` como lo dejaría composer. Es la misma
     * costura que el resto de esta clase usa —lo que una prueba no puede arreglar, lo inyecta— y aquí
     * hace exactamente lo que hace falta: separar «el comando corrió» de «la capacidad llegó».
     */
    public function testWhenTheCapabilityDoesArriveItSaysWhatItUnlocked(): void
    {
        $vendor = $this->vendorCon([]);   // nada puesto al empezar
        $prueba = $this;

        $r = Capabilities::install(
            'milpa/agent',
            $vendor,
            static function (string $c) use ($prueba): array {
                // Composer aterrizó el paquete: el vendor ahora lo declara.
                $prueba->instalarEnElVendor('milpa/agent', 'agent', ['coa chat', 'agent:sessions']);

                return [0, ['ok']];
            },
        );

        self::assertTrue($r['ok']);
        self::assertSame(['coa chat', 'agent:sessions'], $r['unlocked'], 'lo que desbloqueó, del disco');
    }

    /**
     * Deja el vendor de la prueba declarando un paquete — lo que composer habría hecho.
     *
     * @param list<string> $unlocks
     */
    public function instalarEnElVendor(string $paquete, string $id, array $unlocks): void
    {
        $this->vendorCon([
            ['name' => $paquete, 'extra' => ['milpa' => ['capability' => [
                'id' => $id,
                'title' => 'lo que sea',
                'unlocks' => $unlocks,
            ]]]],
        ]);
    }

    /**
     * Y lo que desbloqueo se lee DESPUES de instalar, no antes.
     *
     * La primera version leia la lista del catalogo de faltantes — que esta vacia a proposito, porque
     * un paquete ausente no puede declarar nada. El campo contestaba `[]` SIEMPRE: la forma exacta del
     * defecto que este repositorio lleva un mes cazando, algo declarado que nunca aterriza. Aqui el
     * runner de mentira instala de verdad en el vendor de mentira, que es la unica manera de que esta
     * prueba pueda fallar.
     */
    public function testWhatItUnlockedIsReadAfterInstallingAndNotBefore(): void
    {
        $v = $this->vendorCon([]);

        $r = Capabilities::install('milpa/agent', $v, function (string $cmd): array {
            // El paquete "llega" al vendor, que es lo que hace composer.
            $this->vendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

            return [0, []];
        });

        self::assertTrue($r['ok']);
        self::assertNotSame([], $r['unlocked'], 'un campo que siempre contesta vacio no informa de nada');
        self::assertSame(['algo'], $r['unlocked']);
    }

    /**
     * Y si composer se niega, se devuelve LO QUE DIJO.
     *
     * Un resumen convertiria un problema con arreglo —un conflicto de version, sin red— en «no
     * funciono», que es donde se acaban las opciones de quien lo lee.
     */
    public function testWhenComposerRefusesItsOwnWordsComeBack(): void
    {
        $r = Capabilities::install('milpa/agent', $this->vendorCon([]), static fn (string $cmd): array => [
            1,
            ['Your requirements could not be resolved', 'Problem 1'],
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('could not be resolved', (string) $r['error']);
    }

    /** Pedir algo ya puesto no es un error: es «ya esta». */
    public function testAskingForSomethingAlreadyInstalledIsNotAnError(): void
    {
        $v = $this->vendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        $r = Capabilities::install('milpa/agent', $v, static fn (string $c): array => [0, []]);

        self::assertTrue($r['ok']);
        self::assertStringContainsString('already installed', (string) $r['hint']);
    }

    /** Y por su id tambien, no solo por el nombre del paquete. */
    public function testItAlsoAnswersByCapabilityId(): void
    {
        $v = $this->vendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        self::assertTrue(Capabilities::install('agent', $v, static fn (string $c): array => [0, []])['ok']);
    }

    /** Sin nombre no adivina, y un nombre desconocido vuelve con las respuestas validas. */
    public function testItRefusesAnEmptyOrUnknownNameUsefully(): void
    {
        $v = $this->vendorCon([]);

        self::assertFalse(Capabilities::install('  ', $v)['ok']);

        $r = Capabilities::install('milpa/teleport', $v);
        self::assertFalse($r['ok']);
        self::assertContains('milpa/agent', $r['available']);
    }
}
