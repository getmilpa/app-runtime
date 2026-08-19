<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\BroadcastingEventStore;
use Milpa\AppRuntime\Agent\MercureBroadcaster;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionProjector;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * El puente entre el stream de sesión y la superficie que lo mira.
 *
 * La prueba que importa es la primera: **la secuencia que sale en vivo tiene que ser idéntica a la
 * que sale al ponerse al día**. Es el criterio 1 de `docs/library/spec-kanban-agente.md`, y existe
 * porque dos traducciones del mismo stream divergirían justo en el evento que nadie probó.
 */
final class BroadcastingEventStoreTest extends TestCase
{
    /**
     * En vivo y poniéndose al día dicen EXACTAMENTE lo mismo.
     *
     * Se escribe una sesión completa a través del puente, se guarda lo que se empujó, y se compara
     * contra `projectAll()` sobre el mismo stream — que es lo que devuelve `agent:timeline`.
     */
    public function testTheLiveSequenceIsIdenticalToTheCatchUpOne(): void
    {
        $eventos = new InMemoryEventStore();
        $espia = new BroadcasterEspia();
        $almacen = new SessionStore(new BroadcastingEventStore($eventos, $espia));

        $almacen->start('s1', 'hacer algo');
        $almacen->setPlan('s1', 'primero A, luego B');
        $almacen->setTodo('s1', new Todo('t1', 'A', TodoStatus::Pending));
        $almacen->recordTurn('s1', 'assistant', 'voy');
        $almacen->recordToolCall('s1', 'edit', [], 'ok', true, true);
        $almacen->setTodo('s1', new Todo('t1', 'A', TodoStatus::Done));
        $almacen->setPlan('s1', 'mejor B primero');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿sigo?', ['sí', 'no']));
        $almacen->end('s1', 'listo');

        $alDia = (new SessionProjector())->projectAll($eventos->replay(SessionStore::PREFIX . 's1'));

        self::assertSame($alDia, $espia->payloads, 'las dos traducciones tienen que ser la misma');
        self::assertNotEmpty($alDia, 'y con algo adentro: comparar dos listas vacías no prueba nada');
    }

    /**
     * Control positivo del propio criterio: si el puente tradujera distinto, la prueba de arriba
     * FALLARÍA.
     *
     * Sin esto, la igualdad podría venir de que ninguno de los dos caminos produce nada. Aquí se
     * sabotea a propósito —un broadcaster que le mete un campo de más— y se verifica que la
     * comparación lo caza. Un cero de un instrumento que nunca se probó contra un caso positivo no es
     * un hallazgo, es silencio.
     */
    public function testTheComparisonWouldCatchATranslationThatDrifts(): void
    {
        $eventos = new InMemoryEventStore();
        $entrometido = new BroadcasterEntrometido();
        $almacen = new SessionStore(new BroadcastingEventStore($eventos, $entrometido));

        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'A', TodoStatus::Pending));

        $alDia = (new SessionProjector())->projectAll($eventos->replay(SessionStore::PREFIX . 's1'));

        self::assertNotSame($alDia, $entrometido->payloads, 'la comparación tiene que poder fallar');
    }

    /** El tópico nombra la sesión: una superficie se suscribe a la suya y no a las demás. */
    public function testTheTopicNamesTheSession(): void
    {
        $espia = new BroadcasterEspia();
        $almacen = new SessionStore(new BroadcastingEventStore(new InMemoryEventStore(), $espia));

        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'A', TodoStatus::Pending));

        self::assertSame(['milpa/sessions/s1'], array_values(array_unique($espia->topics)));
    }

    /**
     * Lo que no es una sesión no se empuja a un tópico de sesiones.
     *
     * El mismo almacén puede estar guardando gobernanza o cualquier otra cosa, y darle audiencia de
     * tablero a un hecho que no la pidió es inventarle un lector.
     */
    public function testAStreamThatIsNotASessionIsNotBroadcast(): void
    {
        $espia = new BroadcasterEspia();
        $almacen = new BroadcastingEventStore(new InMemoryEventStore(), $espia);

        $almacen->append(new Event('governance-abc', 'decision.accepted', ['x' => 1], 1));

        self::assertSame([], $espia->topics);
    }

    /**
     * Un hecho que no cambia lo que se ve no se empuja.
     *
     * El `null` del proyector es una afirmación —«esto no se pinta»—, así que un permiso concedido no
     * tiene por qué despertar a ninguna superficie.
     */
    public function testAFactThatChangesNothingVisibleIsNotPushed(): void
    {
        $espia = new BroadcasterEspia();
        $almacen = new SessionStore(new BroadcastingEventStore(new InMemoryEventStore(), $espia));

        $almacen->start('s1', 'x');
        $almacen->grant('s1', 'plugins.disable');

        self::assertSame([], $espia->payloads, 'abrir y permitir no pintan nada');
    }

    /**
     * Pero la ACTIVIDAD sí se empuja, y en el momento en que ocurre.
     *
     * Es lo que hace que una pantalla pueda decir en qué está mientras espera: los turnos y las
     * llamadas a herramienta no mueven una tarjeta, pero son exactamente el hecho que distingue
     * trabajo de cuelgue. Se proyectan una vez y cada superficie filtra.
     */
    public function testActivityIsPushedAsItHappens(): void
    {
        $espia = new BroadcasterEspia();
        $almacen = new SessionStore(new BroadcastingEventStore(new InMemoryEventStore(), $espia));

        $almacen->start('s1', 'x');
        $almacen->recordTurn('s1', 'user', 'hola');
        $almacen->recordToolCall('s1', 'plugins_list', [], 'ok');

        $estados = array_map(
            static fn (array $p): mixed => $p['activity']['state'] ?? null,
            array_values(array_filter($espia->payloads, static fn (array $p): bool => $p['kind'] === 'activity')),
        );

        self::assertSame(['thinking', 'tool'], $estados);
    }

    /**
     * Si el transporte falla, el hecho IGUAL queda guardado — y el fallo queda dicho.
     *
     * El stream es la verdad y la superficie es una vista: que nadie esté mirando no puede impedir
     * escribir. Pero callarlo haría que un hub caído se viera igual que una sesión tranquila.
     */
    public function testWhenTheTransportFailsTheFactIsStillStoredAndTheFailureIsSaid(): void
    {
        $eventos = new InMemoryEventStore();
        $bitacora = new BitacoraEspia();
        $almacen = new SessionStore(new BroadcastingEventStore(
            $eventos,
            new BroadcasterCaido(),
            new SessionProjector(),
            $bitacora,
        ));

        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'A', TodoStatus::Pending));

        self::assertNotNull($almacen->load('s1'), 'la sesión existe');
        self::assertCount(1, $almacen->load('s1')?->todos ?? [], 'y la tarjeta quedó guardada');
        self::assertNotEmpty($bitacora->lineas, 'y el hub caído no pasó en silencio');
    }

    /**
     * Si guardar falla, no se empuja nada.
     *
     * Ver moverse una tarjeta por un hecho que el sistema no llegó a recordar es peor que no verla:
     * es una pantalla afirmando algo que no ocurrió.
     */
    public function testIfStoringFailsNothingIsBroadcast(): void
    {
        $espia = new BroadcasterEspia();
        $puente = new BroadcastingEventStore(new AlmacenQueSeNiega(), $espia);

        try {
            $puente->append(new Event(SessionStore::PREFIX . 's1', 'session.started', [], 1));
            self::fail('el almacén tenía que negarse');
        } catch (\RuntimeException) {
            self::assertSame([], $espia->topics);
        }
    }

    /** El puente delega lo que no es su asunto, sin reinterpretarlo. */
    public function testItDelegatesEverythingElseUntouched(): void
    {
        $adentro = new InMemoryEventStore();
        $puente = new BroadcastingEventStore($adentro, new BroadcasterEspia());

        $puente->append(new Event(SessionStore::PREFIX . 's1', 'session.started', ['goal' => 'x'], 1));

        self::assertSame($adentro->streams(), $puente->streams());
        self::assertSame($adentro->nextSeq(), $puente->nextSeq());
        self::assertEquals(
            $adentro->replay(SessionStore::PREFIX . 's1'),
            $puente->replay(SessionStore::PREFIX . 's1'),
        );
    }

    /** Un hub que no sabe publicar se rechaza al construir, no la primera vez que alguien mira. */
    public function testAHubThatCannotPublishIsRefusedUpFront(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MercureBroadcaster(new \stdClass());
    }

    /** Y uno que sí sabe recibe el payload TAL CUAL. */
    public function testAHubThatCanPublishGetsThePayloadUntouched(): void
    {
        $hub = new HubDePrueba();
        (new MercureBroadcaster($hub))->broadcast('milpa/sessions/s1', ['kind' => 'card']);

        self::assertSame([['milpa/sessions/s1', ['kind' => 'card']]], $hub->publicados);
    }
}

/** @internal guarda lo que se empujó, para poder compararlo */
final class BroadcasterEspia implements SurfaceBroadcaster
{
    /** @var list<string> */
    public array $topics = [];

    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function broadcast(string $topic, array $payload): void
    {
        $this->topics[] = $topic;
        $this->payloads[] = $payload;
    }
}

/** @internal el sabotaje del control positivo: traduce de más */
final class BroadcasterEntrometido implements SurfaceBroadcaster
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function broadcast(string $topic, array $payload): void
    {
        $this->payloads[] = [...$payload, 'adorno' => 'que nadie pidió'];
    }
}

/** @internal el hub caído */
final class BroadcasterCaido implements SurfaceBroadcaster
{
    public function broadcast(string $topic, array $payload): void
    {
        throw new \RuntimeException('el hub no contesta');
    }
}

/** @internal */
final class AlmacenQueSeNiega implements \Milpa\EventStore\EventStoreInterface
{
    public function append(\Milpa\EventStore\Event $event): void
    {
        throw new \RuntimeException('disco lleno');
    }

    public function replay(string $streamId): array
    {
        return [];
    }

    public function nextSeq(): int
    {
        return 1;
    }

    public function streams(): array
    {
        return [];
    }

    public function replayAll(): array
    {
        return [];
    }
}

/** @internal */
final class HubDePrueba
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $publicados = [];

    /** @param array<string, mixed> $data */
    public function publish(string $topic, array $data): void
    {
        $this->publicados[] = [$topic, $data];
    }
}

/** @internal */
final class BitacoraEspia extends AbstractLogger
{
    /** @var list<string> */
    public array $lineas = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lineas[] = (string) $message;
    }
}
