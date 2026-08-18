<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Retomar a un sub-agente contestado — y las cuatro puertas que lo protegen.
 *
 * Retomar no es re-delegar: el hijo sigue con SU historial. Y quién puede retomarlo a quién es una
 * cuestión de autoridad, no de comodidad — por eso cada negativa dice cuál es el caso.
 */
final class SubAgentResumeTest extends TestCase
{
    private SessionStore $almacen;

    protected function setUp(): void
    {
        $this->almacen = new SessionStore(new InMemoryEventStore());
        $this->almacen->start('padre', 'la tarea grande', AutonomyMode::Auto);
    }

    private function spawner(?\Closure $correr = null): SubAgentSpawner
    {
        return new SubAgentSpawner(
            $this->almacen,
            'padre',
            $correr ?? static fn (): array => ['answer' => 'listo', 'steps' => 2],
        );
    }

    /** @return array<string, mixed> */
    private function retomar(string $id): array
    {
        $op = $this->spawner()->resumeOperation();
        self::assertSame('agent_resume', $op->name);
        /** @var array<string, mixed> $r */
        $r = ($op->handler)(['sub_session' => $id]);

        return $r;
    }

    /** Las dos herramientas se declaran, y el hijo no recibe ninguna: profundidad 1 por construcción. */
    public function testBothToolsAreDeclaredAndTheChildGetsNeither(): void
    {
        $spawner = $this->spawner();

        self::assertSame('agent_spawn', $spawner->operation()->name);
        self::assertSame('agent_resume', $spawner->resumeOperation()->name);
        self::assertContains('brief', $spawner->operation()->inputSchema['required'] ?? []);
        self::assertContains('sub_session', $spawner->resumeOperation()->inputSchema['required'] ?? []);
    }

    /** Sin id no se retoma nada: no se adivina a cuál de los hijos se refería. */
    public function testWithoutAnIdItSaysWhatIsMissing(): void
    {
        $r = $this->retomar('  ');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('sub_session', (string) $r['error']);
    }

    /**
     * UNA SESIÓN AJENA NO SE RETOMA, aunque exista.
     *
     * La filiación se comprueba contra el `parentId` del stream, no contra el nombre: un id que se
     * parezca al de un hijo no lo vuelve hijo, y retomarlo sería operar el trabajo de otro árbol.
     */
    public function testASessionThatIsNotMyChildIsRefused(): void
    {
        $this->almacen->start('ajena', 'de nadie', AutonomyMode::Auto);

        $r = $this->retomar('ajena');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no es un sub-agente de esta sesión', (string) $r['error']);
    }

    /** Y una que no existe, igual — sin abrir una sesión vacía con ese nombre. */
    public function testAMissingSessionIsRefused(): void
    {
        self::assertFalse($this->retomar('padre.sub-noexiste')['ok']);
    }

    /**
     * UN HIJO QUE SIGUE ESPERANDO NO SE RETOMA: primero se le contesta.
     *
     * Retomarlo con su pregunta abierta lo devolvería al mismo punto, y la negativa trae la pregunta
     * adentro para que quien lea sepa qué hay que contestar.
     */
    public function testAChildStillWaitingIsNotResumedButItsQuestionIsSaid(): void
    {
        $this->almacen->start('padre.sub-a1', 'la sub-tarea', AutonomyMode::Auto, parentId: 'padre');
        $this->almacen->ask('padre.sub-a1', new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']));

        $r = $this->retomar('padre.sub-a1');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('¿autorizas make?', (string) $r['error']);
    }

    /** Un hijo que ya terminó tampoco: su trabajo se cerró, y volver a correrlo sería otra cosa. */
    public function testAFinishedChildIsNotResumed(): void
    {
        $this->almacen->start('padre.sub-b2', 'la sub-tarea', AutonomyMode::Auto, parentId: 'padre');
        $this->almacen->end('padre.sub-b2', 'entregó su reporte');

        $r = $this->retomar('padre.sub-b2');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('ya terminó', (string) $r['error']);
        self::assertSame('padre.sub-b2', $r['sub_session']);
    }

    /**
     * Y el que fue contestado SÍ se retoma, con SU historial.
     *
     * Retomar no es re-delegar: si llegara con el historial vacío sería un hijo nuevo con el mismo id,
     * y lo que ya hizo se perdería sin que nadie lo dijera.
     */
    public function testAnAnsweredChildIsResumedWithItsOwnWindow(): void
    {
        $this->almacen->start('padre.sub-c3', 'la sub-tarea', AutonomyMode::Auto, parentId: 'padre');
        $this->almacen->recordTurn('padre.sub-c3', 'user', 'el encargo');
        $this->almacen->ask('padre.sub-c3', new PendingQuestion('perm:make', '¿autorizas?', ['sí', 'no']));
        $this->almacen->answer('padre.sub-c3', 'perm:make', 'sí', new \Milpa\Agent\Principal('cli:prueba'), 'prueba');

        $historial = null;
        $declaredWindow = null;
        $expectedDeclaration = $this->almacen->load('padre.sub-c3')?->classifiedWindow();
        $op = $this->spawner(static function (
            string $e,
            string $h,
            array $hist,
            array $first,
            array $declaration,
        ) use (&$historial, &$declaredWindow): array {
            $historial = $hist;
            $declaredWindow = $declaration;

            return ['answer' => 'seguí y terminé', 'steps' => 3];
        })->resumeOperation();

        /** @var array<string, mixed> $r */
        $r = ($op->handler)(['sub_session' => 'padre.sub-c3']);

        self::assertTrue($r['ok']);
        self::assertSame('seguí y terminé', $r['report']);
        self::assertIsArray($historial);
        self::assertNotSame([], $historial, 'retomar no es re-delegar: llega con su ventana');
        self::assertSame($expectedDeclaration, $declaredWindow, 'the same composer declares the resumed window');
    }
}
