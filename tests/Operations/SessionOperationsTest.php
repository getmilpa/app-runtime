<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * El otro lado de la pausa: ver, leer y CONTESTAR (P16.4/P16.5).
 *
 * Son átomos y no un prompt interactivo porque una sesión se pausa en un proceso y se contesta en
 * otro — al día siguiente, desde otra máquina, o desde el TUI. Un `readline()` dentro del bucle habría
 * atado la respuesta al proceso que hizo la pregunta, que es justo lo que P16.1 desató.
 */
final class SessionOperationsTest extends TestCase
{
    private InMemoryEventStore $eventos;

    protected function setUp(): void
    {
        $this->eventos = new InMemoryEventStore();
    }

    private function proveedor(): SessionOperations
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(SessionStore::class, new SessionStore($this->eventos));

        return new SessionOperations($contenedor);
    }

    private function almacen(): SessionStore
    {
        return new SessionStore($this->eventos);
    }

    /** @return array<string, mixed> */
    private function llamar(string $nombre, array $entrada = []): array
    {
        foreach ($this->proveedor()->operations() as $operacion) {
            if ($operacion->name === $nombre) {
                $handler = $operacion->handler;
                self::assertIsCallable($handler);

                /** @var array<string, mixed> $r */
                $r = $handler($entrada);

                return $r;
            }
        }

        self::fail("no existe la operación «{$nombre}»");
    }

    private function operacion(string $nombre): Operation
    {
        foreach ($this->proveedor()->operations() as $operacion) {
            if ($operacion->name === $nombre) {
                return $operacion;
            }
        }

        self::fail("no existe la operación «{$nombre}»");
    }

    /**
     * La lista dice en QUÉ ESTADO está cada una.
     *
     * Es lo que alguien busca al listar: una sesión esperando una respuesta se ve igual que una viva
     * si sólo se muestra su objetivo, y la que espera es la única sobre la que hay algo que hacer.
     */
    public function testTheListSaysWhichOnesAreWaiting(): void
    {
        $almacen = $this->almacen();
        $almacen->start('viva', 'a');
        $almacen->start('esperando', 'b');
        $almacen->ask('esperando', new PendingQuestion('perm:make', '¿autorizas?', ['sí', 'no']));
        $almacen->start('cerrada', 'c');
        $almacen->end('cerrada', 'listo');

        $r = $this->llamar('agent:sessions');

        self::assertTrue($r['ok']);
        self::assertSame(3, $r['total']);

        $porId = [];
        foreach ($r['sessions'] as $fila) {
            $porId[$fila['session']] = $fila['state'];
        }
        self::assertSame('viva', $porId['viva']);
        self::assertSame('esperando respuesta', $porId['esperando']);
        self::assertSame('terminada', $porId['cerrada']);
    }

    /** `agent:show` trae plan, pendientes y permisos — el estado, no la transcripción. */
    public function testShowBringsTheStateAndNotTheTranscript(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'migrar');
        $almacen->setPlan('s1', '1. entidad  2. rutas');
        $almacen->setTodo('s1', new Todo('t1', 'la entidad'));
        $almacen->grant('s1', 'make');
        $almacen->recordTurn('s1', 'user', 'hola');

        $r = $this->llamar('agent:show', ['session' => 's1']);

        self::assertTrue($r['ok']);
        self::assertSame('migrar', $r['goal']);
        self::assertStringContainsString('entidad', (string) $r['plan']);
        self::assertSame(['make'], $r['permissions']);
        self::assertSame(1, $r['turns'], 'cuántos turnos hay, no cuáles');
    }

    /**
     * Contestar «sí» a una pregunta de permiso OTORGA esa operación, y la sesión vuelve a ser corrible.
     */
    public function testAnsweringYesGrantsTheOperationAndResumesTheSession(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']));

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'sí']);

        self::assertTrue($r['ok']);
        self::assertSame('make', $r['granted']);

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertTrue($sesion->isRunnable());
        self::assertTrue($sesion->allows('make'));
    }

    /**
     * Contestar «no» reanuda la sesión SIN otorgar nada.
     *
     * La sesión sigue: negar un permiso no mata el trabajo, sólo cierra un camino — y el agente puede
     * proponer otro o explicar por qué no hay.
     */
    public function testAnsweringNoResumesWithoutGranting(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']));

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'no']);

        self::assertTrue($r['ok']);
        self::assertNull($r['granted']);

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertTrue($sesion->isRunnable(), 'la sesión sigue: negar cierra un camino, no el trabajo');
        self::assertFalse($sesion->allows('make'));
    }

    /**
     * SÓLO se otorga lo que la sesión preguntó.
     *
     * El permiso sale del id de la pregunta y no de lo que alguien teclee, así que un «sí» a una
     * pregunta que no era de permiso —o a una de firma— no autoriza nada. Es lo que impide que
     * `agent:answer` se convierta en una puerta para otorgar lo que nadie pidió.
     */
    public function testOnlyWhatWasAskedCanBeGranted(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('sign:plugins_remove', 'necesita firma', []));

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'sí']);

        self::assertTrue($r['ok']);
        self::assertNull($r['granted'], 'un «sí» no reemplaza una firma');
        self::assertFalse($almacen->load('s1')?->allows('plugins_remove'));
    }

    /**
     * Cualquier respuesta que no sea un sí explícito NO autoriza.
     *
     * Interpretar de más en la pieza que otorga permisos es exactamente donde no se quiere ser listo:
     * un «tal vez» tiene que caer del lado de la negativa.
     */
    public function testAnythingThatIsNotAnExplicitYesDoesNotGrant(): void
    {
        foreach (['tal vez', 'adelante pero con cuidado', 'ok', 'claro'] as $respuesta) {
            $eventos = new InMemoryEventStore();
            $this->eventos = $eventos;
            $almacen = $this->almacen();
            $almacen->start('s1', 'x');
            $almacen->ask('s1', new PendingQuestion('perm:make', '¿autorizas?', ['sí', 'no']));

            $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => $respuesta]);

            self::assertNull($r['granted'], "«{$respuesta}» no puede autorizar");
        }
    }

    /** Contestar algo que nadie preguntó se niega, en vez de dejar un turno suelto. */
    public function testAnsweringWhenNothingWasAskedIsRefused(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'sí']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no está esperando', (string) $r['error']);
    }

    /** Una sesión que no existe se dice, no se inventa. */
    public function testAnUnknownSessionIsSaid(): void
    {
        $r = $this->llamar('agent:show', ['session' => 'no-existe']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no existe', (string) $r['error']);
    }

    /**
     * La política de consentimiento de las tres, declarada.
     *
     * `answer` muta —apenda eventos, incluido un permiso— y no pide firma porque ES la compuerta:
     * exigir un consentimiento para dar un consentimiento es una escalera sin piso. Y no se ofrece por
     * HTTP, porque autorizar desde una petición web es autorizar con las credenciales del servidor.
     */
    public function testTheConsentPolicyOfTheThreeIsDeclared(): void
    {
        self::assertFalse($this->operacion('agent:sessions')->mutating);
        self::assertFalse($this->operacion('agent:show')->mutating);

        $contestar = $this->operacion('agent:answer');
        self::assertTrue($contestar->mutating);
        self::assertFalse($contestar->requiresConfirmation);

        // POR HTTP TAMBIÉN, y lo que lo hace seguro no es el canal sino las tres piezas juntas: el
        // scope exige un actor autenticado, el `InvocationContext` lo trae hasta la operación, y la
        // operación se niega si no llega. Quitar cualquiera de las tres convierte un permiso
        // auditable en uno a nombre del proceso del servidor.
        self::assertSame(['cli', 'tui', 'mcp', 'http'], $contestar->surfaces);
        self::assertSame(['agent:answer'], $contestar->scopes, 'la web exige actor, no basta con estar');
    }

    /** `agent:mode` cambia la autonomía y dice desde dónde — un cambio sin origen no se puede revisar. */
    public function testTheModeCanBeChangedAndItSaysWhereItCameFrom(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');

        $r = $this->llamar('agent:mode', ['session' => 's1', 'mode' => 'auto']);

        self::assertTrue($r['ok']);
        self::assertSame('ask', $r['from']);
        self::assertSame('auto', $r['mode']);
        self::assertSame(AutonomyMode::Auto, $almacen->load('s1')?->mode);
    }

    /**
     * Y al cambiarlo dice lo que NINGÚN modo cambia.
     *
     * Es cuando alguien podría creer lo contrario: subir a `auto` es dejar de preguntar por lo
     * reversible, no firmar en blanco. Decirlo aquí cuesta una línea; no decirlo cuesta la confusión
     * en el peor momento.
     */
    public function testChangingTheModeRestatesWhatNoModeChanges(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');

        $r = $this->llamar('agent:mode', ['session' => 's1', 'mode' => 'auto']);

        self::assertStringContainsString('firma', (string) $r['note']);
    }

    /** Un modo inventado se niega, y la negativa lista los que sí. */
    public function testAnUnknownModeIsRefusedWithTheValidOnes(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');

        $r = $this->llamar('agent:mode', ['session' => 's1', 'mode' => 'barra-libre']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('ask', (string) $r['error']);
        self::assertStringContainsString('auto', (string) $r['error']);
    }

    /**
     * Los tres rechazos de `agent:answer`, que son los que evitan escribir en el lugar equivocado.
     *
     * Sin respuesta no hay nada que apendar; sin sesión no hay dónde; y contestar algo que nadie
     * preguntó dejaría un turno suelto que el modelo leería como contexto en la siguiente vuelta —
     * una respuesta a una pregunta inexistente se vuelve una afirmación.
     */
    public function testAnsweringRefusesWhatItCannotWrite(): void
    {
        $sinRespuesta = $this->llamar('agent:answer', ['session' => 's1', 'answer' => '  ']);
        self::assertFalse($sinRespuesta['ok']);
        self::assertStringContainsString('falta `answer`', (string) $sinRespuesta['error']);

        $sinSesion = $this->llamar('agent:answer', ['session' => 'no-existe', 'answer' => 'sí']);
        self::assertFalse($sinSesion['ok']);
        self::assertStringContainsString('no existe la sesión', (string) $sinSesion['error']);
    }

    /**
     * Contestar registra QUIÉN, y por terminal ese principal va SIN verificar.
     *
     * Es la distinción que hace auditable un permiso: una terminal reporta el usuario del sistema,
     * que cualquiera con esa terminal puede ser. Guardarlo como identidad probada fabricaría una
     * cadena de custodia inexistente.
     */
    public function testAnsweringRecordsAnUnverifiedTerminalPrincipal(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿Lo autorizas?', ['sí', 'no']));

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'sí']);
        self::assertTrue($r['ok']);

        $mostrado = $this->llamar('agent:show', ['session' => 's1']);
        $decision = $mostrado['decisions'][0] ?? [];

        self::assertSame('sí', $decision['answer'] ?? null);
        self::assertIsArray($decision['by'] ?? null, 'la decisión sabe quién la firmó');
        self::assertFalse($decision['by']['verified'], 'una terminal no prueba nada');
        self::assertStringStartsWith('cli:', (string) $decision['by']['id']);
    }

    /**
     * Con un actor autenticado, el principal va VERIFICADO y con su origen adelante.
     *
     * Es la otra mitad de la distinción: detrás de un `AuthContext` autenticado hay una credencial
     * que alguien comprobó, y eso sí es una identidad. El prefijo `actor:` importa porque dos canales
     * pueden usar el mismo nombre para personas distintas.
     */
    public function testAnAuthenticatedActorIsRecordedAsVerified(): void
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(SessionStore::class, $this->almacen());
        $contenedor->registerService(
            \Milpa\Auth\AuthContext::class,
            \Milpa\Auth\AuthContext::authenticated(
                new \Milpa\Auth\Actor('member:42', \Milpa\Auth\ActorType::User),
            ),
        );

        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿?', ['sí', 'no']));

        $ops = new SessionOperations($contenedor);
        foreach ($ops->operations() as $op) {
            if ($op->name === 'agent:answer') {
                ($op->handler)(['session' => 's1', 'answer' => 'sí']);
            }
        }

        $decision = $almacen->load('s1')?->decisions[0] ?? [];
        self::assertSame('actor:member:42', $decision['by']?->id);
        self::assertTrue($decision['by']?->verified, 'hubo credencial detrás');
    }

    /**
     * Sin `session`, las tres operaciones de sesión dicen qué falta en vez de adivinar cuál.
     *
     * Elegir «la última» sería cómodo y sería el defecto: quien tenga dos sesiones abiertas vería
     * responderse una que no nombró.
     */
    public function testTheSessionOperationsRefuseToGuessWhichSession(): void
    {
        foreach (['agent:show', 'agent:answer'] as $nombre) {
            $r = $this->llamar($nombre, ['answer' => 'sí']);
            self::assertFalse($r['ok'], $nombre);
            self::assertStringContainsString('falta `session`', (string) $r['error'], $nombre);
        }
    }

    /**
     * `agent:timeline` da la MISMA respuesta a las tres superficies, y con cursor.
     *
     * Que la terminal, el navegador y el agente reciban veredictos distintos del mismo hecho es un
     * falsificador que este repositorio ya vio dispararse hoy: `ci-check` y la CI publicada
     * difirieron tres veces. La defensa es que haya un solo camino, no tres cuidadosos.
     */
    public function testTheTimelineIsTheSameAnswerForEverySurfaceAndCarriesItsCursor(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Pending));
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Done));

        $todo = $this->llamar('agent:timeline', ['session' => 's1']);

        self::assertTrue($todo['ok']);
        self::assertCount(2, $todo['events'], 'abrir no pinta; los dos movimientos sí');
        self::assertSame('pending', $todo['events'][1]['card']['from'], 'el movimiento viene leído');

        // Y con el cursor que devolvió, no llega nada nuevo: ponerse al día y recibir lo siguiente
        // son el mismo camino.
        $nada = $this->llamar('agent:timeline', ['session' => 's1', 'since' => $todo['since']]);

        self::assertSame([], $nada['events']);
        self::assertSame($todo['since'], $nada['since'], 'el cursor no retrocede');
    }

    /** Una sesión que no existe se dice, en vez de devolver una línea vacía que parece calma. */
    public function testAskingTheTimelineOfAnUnknownSessionSaysSo(): void
    {
        $r = $this->llamar('agent:timeline', ['session' => 'no-existe']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no existe la sesión', (string) $r['error']);
    }

    /**
     * Un canal que promete identidad y no la trae: se NIEGA, no degrada.
     *
     * Es el falsificador principal de esta rebanada: «HTTP autorizado, pero el evento registra
     * www-data». Escribir el proceso técnico donde debía ir la persona produce un registro que se lee
     * como auditoría y no lo es, y eso es peor que no tener la superficie.
     */
    public function testAChannelThatPromisesIdentityIsRefusedWhenItDoesNotBringOne(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿?', ['sí', 'no']));

        $sinActor = new InvocationContext(actor: null, verified: false, channel: 'web', executor: 'www-data@host');

        $r = null;
        foreach ($this->proveedor()->operations() as $op) {
            if ($op->name === 'agent:answer') {
                $r = ($op->handler)(['session' => 's1', 'answer' => 'sí'], $sinActor);
            }
        }

        self::assertFalse($r['ok'] ?? true);
        self::assertStringContainsString('actor verificado', (string) ($r['error'] ?? ''));
        self::assertNotNull($almacen->load('s1')?->question, 'y la pregunta sigue abierta');
    }

    /**
     * Con actor verificado, el evento conserva EXACTAMENTE ese principal — y el ejecutor al lado.
     *
     * Política y auditoría tienen que registrar el mismo principal: volver a derivarlo aquí sería la
     * forma de que difieran. Y el ejecutor acompaña, nunca sustituye.
     */
    public function testAVerifiedActorIsRecordedExactlyAndTheExecutorGoesBesideIt(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿?', ['sí', 'no']));

        $ctx = InvocationContext::web('actor:member:42', 'dec-7', executor: 'www-data@host');

        foreach ($this->proveedor()->operations() as $op) {
            if ($op->name === 'agent:answer') {
                ($op->handler)(['session' => 's1', 'answer' => 'sí'], $ctx);
            }
        }

        $decision = $almacen->load('s1')?->decisions[0] ?? [];

        self::assertSame('actor:member:42', $decision['by']?->id);
        self::assertTrue($decision['by']?->verified);
        self::assertSame('www-data@host', $decision['executor'], 'el proceso acompaña');
    }

    /** La terminal sigue siendo el caso honesto: sin actor, y el registro lo dice. */
    public function testTheTerminalStillWorksAndSaysItIsUnverified(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿?', ['sí', 'no']));

        foreach ($this->proveedor()->operations() as $op) {
            if ($op->name === 'agent:answer') {
                ($op->handler)(['session' => 's1', 'answer' => 'sí'], InvocationContext::cli('rod@laptop'));
            }
        }

        $decision = $almacen->load('s1')?->decisions[0] ?? [];

        self::assertStringStartsWith('cli:', (string) $decision['by']?->id);
        self::assertFalse($decision['by']?->verified);
        self::assertSame('rod@laptop', $decision['executor']);
    }

    /**
     * La linea de tiempo devuelve el cursor, y contra una sesion que no existe se niega por su nombre.
     *
     * El cursor es lo que permite que una superficie que llega tarde se ponga al dia y siga leyendo
     * desde donde se quedo; sin el, tendria que pedir el stream entero cada vez o inventarse un
     * indice propio, que es la forma de que dos lectores cuenten historias distintas.
     */
    public function testTheTimelineReturnsACursorAndRefusesAnUnknownSessionByName(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->setPlan('s1', 'plan uno');

        $r = $this->llamar('agent:timeline', ['session' => 's1']);

        self::assertTrue($r['ok'] ?? false);
        self::assertGreaterThan(0, $r['since'] ?? 0, 'el cursor dice desde donde seguir');
        self::assertNotEmpty($r['events'] ?? []);

        // Y leyendo DESDE ese cursor no vuelve a entregar lo mismo.
        $siguiente = $this->llamar('agent:timeline', ['session' => 's1', 'since' => $r['since']]);
        self::assertSame([], $siguiente['events'] ?? [null]);

        $inexistente = $this->llamar('agent:timeline', ['session' => 'no-existe']);
        self::assertFalse($inexistente['ok'] ?? true);
        self::assertStringContainsString('no-existe', (string) ($inexistente['error'] ?? ''));
    }

    /**
     * Sin `session`, ninguna de estas operaciones adivina cual.
     *
     * Elegir «la ultima» por comodidad seria contestar en una sesion que quien pregunta no nombro —y
     * `agent:answer` escribe—, asi que la falta se dice y no se rellena.
     */
    public function testWithoutASessionNothingGuessesWhichOne(): void
    {
        foreach (['agent:timeline', 'agent:mode', 'agent:answer'] as $nombre) {
            $r = $this->llamar($nombre, []);
            self::assertFalse($r['ok'] ?? true, $nombre);
            self::assertStringContainsString('session', (string) ($r['error'] ?? ''), $nombre);
        }
    }

    /** Un modo que no existe se rechaza diciendo cuales SI existen. */
    public function testAnUnknownModeIsRefusedWithTheListOfRealOnes(): void
    {
        $this->almacen()->start('s1', 'x');

        $r = $this->llamar('agent:mode', ['session' => 's1', 'mode' => 'turbo']);

        self::assertFalse($r['ok'] ?? true);
        foreach (AutonomyMode::cases() as $modo) {
            self::assertStringContainsString($modo->value, (string) ($r['error'] ?? ''));
        }
    }

    /** Cambiar el modo de una sesion que no existe se niega antes de escribir nada. */
    public function testChangingTheModeOfAnUnknownSessionRefusesBeforeWriting(): void
    {
        $r = $this->llamar('agent:mode', ['session' => 'fantasma', 'mode' => AutonomyMode::Auto->value]);

        self::assertFalse($r['ok'] ?? true);
        self::assertStringContainsString('fantasma', (string) ($r['error'] ?? ''));
    }

    /**
     * El trabajo sin explicar cuenta cuanto SIGUIO pasando con una tarjeta abierta.
     *
     * No dice que algo este mal: dice cuanto no se explico. Cero es una sesion limpia, y una sesion
     * donde nada ocurrio mientras algo quedaba abierto tambien vale cero — que es la diferencia entre
     * medir silencio y acusar abandono.
     */
    public function testUnexplainedWorkCountsWhatKeptHappeningWithACardLeftOpen(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'algo', TodoStatus::InProgress));
        // Y una ya terminada al lado: lo que cuenta es lo abierto, no cuantas tarjetas hay.
        $almacen->setTodo('s1', new Todo('t2', 'lo otro', TodoStatus::Done));
        $almacen->recordToolCall('s1', 'edit', ['path' => 'a.php'], 'ok', true, true);
        $almacen->recordToolCall('s1', 'edit', ['path' => 'b.php'], 'ok', true, true);

        $fila = null;
        foreach ($this->llamar('agent:sessions')['sessions'] ?? [] as $s) {
            if (($s['session'] ?? null) === 's1') {
                $fila = $s;
            }
        }

        self::assertNotNull($fila);
        self::assertSame(1, $fila['pending'] ?? null, 'una tarjeta sigue abierta');
        self::assertGreaterThan(0, $fila['unexplained'] ?? 0, 'y el trabajo siguio sin ella');
    }

    /**
     * Con el agente instalado pero sin dónde guardar, las operaciones existen y lo DICEN.
     *
     * Son dos ausencias distintas y el sistema no las puede confundir: «el paquete no está» hace que
     * la operación no se ofrezca (ADR-0040), y «está pero esta app no cableó un almacén» es una app
     * mal configurada, que sí tiene que aparecer y contestar qué falta. Colapsarlas escondería la
     * segunda detrás del silencio de la primera.
     */
    public function testInstalledButUnwiredIsADifferentAbsenceAndItSaysSo(): void
    {
        $proveedor = new SessionOperations(new \Milpa\Container\DIContainer());

        $nombres = array_map(static fn ($o): string => $o->name, $proveedor->operations());
        self::assertContains('agent:sessions', $nombres, 'el paquete está: la operación se ofrece');

        foreach ($proveedor->operations() as $operacion) {
            if ($operacion->name === 'agent:sessions') {
                $handler = $operacion->handler;
                self::assertIsCallable($handler);
                /** @var array<string, mixed> $r */
                $r = $handler([]);

                self::assertFalse($r['ok'] ?? true);
                self::assertStringContainsString('dónde guardar', (string) ($r['error'] ?? ''));
            }
        }
    }

    /**
     * DESCARTAR CIERRA LA SESIÓN Y DEJA EL MOTIVO — el contrato «control» de P19.3.
     *
     * Un hijo que pausó preguntando y a quien nadie contesta se quedaba abierto para siempre. Lo que
     * cierra la fuga no es sólo terminarla: es que el stream diga POR QUÉ, porque un final sin causa
     * se lee idéntico a un trabajo terminado.
     */
    public function testDiscardingASessionEndsItAndLeavesTheReasonBehind(): void
    {
        $this->almacen()->start('j', 'la tarea');

        $r = $this->llamar('agent:discard', ['session' => 'j', 'because' => 'la pregunta ya no aplica']);

        self::assertTrue($r['ok']);
        self::assertSame('la pregunta ya no aplica', $this->almacen()->load('j')?->endedBecause, 'el motivo queda en el stream');
    }

    /** Sin motivo NO se descarta: un final sin causa es un final que nadie puede leer mañana. */
    public function testDiscardingWithoutAReasonIsRefused(): void
    {
        $this->almacen()->start('j', 'la tarea');

        $r = $this->llamar('agent:discard', ['session' => 'j', 'because' => '   ']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('because', (string) $r['error']);
        self::assertNull($this->almacen()->load('j')?->endedBecause, 'la sesión sigue viva');
    }

    /** Y una sesión que no existe se dice, en vez de cerrar un id inventado en silencio. */
    public function testDiscardingAMissingSessionSaysSo(): void
    {
        $r = $this->llamar('agent:discard', ['session' => 'no-existe', 'because' => 'x']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no existe la sesión', (string) $r['error']);
    }

    /**
     * EL TABLERO ES EL FOLD DEL STREAM, no un almacén — la propiedad de P19.5 que no se puede perder.
     *
     * En el momento en que esto guardara su propia copia de en qué columna va una tarjeta habría dos
     * sitios que contestan «¿en qué va esto?», y divergirían. La única pregunta sería cuándo.
     */
    public function testTheBoardIsTheFoldOfTheStreamAndNotAStore(): void
    {
        $almacen = $this->almacen();
        $almacen->start('j', 'la tarea');
        $almacen->setTodo('j', new \Milpa\Agent\Todo('t1', 'crear el plugin'));
        $almacen->setTodo('j', new \Milpa\Agent\Todo('t2', 'registrarlo'));
        $almacen->setTodo('j', new \Milpa\Agent\Todo('t1', 'crear el plugin', \Milpa\Agent\TodoStatus::Done));

        $r = $this->llamar('agent:board', ['session' => 'j']);

        self::assertTrue($r['ok']);
        self::assertSame(['t1'], array_column($r['columns']['done'], 'id'), 'la que se cerró está cerrada');
        self::assertSame(['t2'], array_column($r['columns']['pending'], 'id'));
        self::assertSame([], $r['columns']['in_progress']);

        // Y DOS LECTURAS SEGUIDAS DAN LO MISMO: no hay nada que se mueva por preguntar.
        self::assertSame($r, $this->llamar('agent:board', ['session' => 'j']));
    }

    /**
     * Las columnas son el ENUM, no una lista escrita en el tablero.
     *
     * Un estado nuevo aparece como columna sin tocar el archivo, y —más importante— no puede existir
     * un estado que el tablero no sepa pintar.
     */
    public function testTheColumnsAreTheEnumAndNotAHandWrittenList(): void
    {
        $this->almacen()->start('j', 'x');

        $r = $this->llamar('agent:board', ['session' => 'j']);

        $esperadas = array_map(static fn (\Milpa\Agent\TodoStatus $e): string => $e->value, \Milpa\Agent\TodoStatus::cases());
        self::assertSame($esperadas, array_keys($r['columns']));
        foreach ($r['columns'] as $columna) {
            self::assertSame([], $columna, 'una sesión sin pendientes tiene columnas vacías, no ausentes');
        }
    }

    /**
     * LA PREGUNTA ABIERTA VA EN EL TABLERO: es trabajo detenido esperando a un humano, y un tablero
     * que no la muestra deja al agente parado sin que nadie lo vea.
     */
    public function testAPausedSessionShowsItsQuestionOnTheBoard(): void
    {
        $almacen = $this->almacen();
        $almacen->start('j', 'x');
        $almacen->ask('j', new \Milpa\Agent\PendingQuestion(id: 'perm:make', question: '¿autorizas make?', options: ['sí', 'no']));

        self::assertSame('¿autorizas make?', $this->llamar('agent:board', ['session' => 'j'])['pending_question'] ?? null);
    }

    /** Y el tablero NO aprueba: mover una tarjeta y consentir un efecto no son el mismo sistema. */
    public function testTheBoardDoesNotDecideAnything(): void
    {
        foreach ((new \Milpa\AppRuntime\Operations\SessionOperations(new \Milpa\Container\DIContainer()))->operations() as $op) {
            if ($op->name === 'agent:board') {
                self::assertFalse($op->mutating, 'el tablero lee; consentir tiene su propia forma (Q-P19-B)');

                return;
            }
        }
        self::fail('no existe la operación agent:board');
    }

    /** Un tablero de una sesión que no existe se dice, en vez de pintar columnas vacías con cara de reales. */
    public function testTheBoardOfAMissingSessionSaysSo(): void
    {
        $r = $this->llamar('agent:board', ['session' => 'no-existe']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no existe la sesión', (string) $r['error']);
    }

    /** Y sin `session` se dice qué falta. */
    public function testTheBoardWithoutASessionSaysWhatIsMissing(): void
    {
        $r = $this->llamar('agent:board', []);

        self::assertFalse($r['ok']);
    }

    /** Descartar sin sesión también. */
    public function testDiscardWithoutASessionSaysWhatIsMissing(): void
    {
        self::assertFalse($this->llamar('agent:discard', ['because' => 'x'])['ok']);
    }
}
