<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\RunInterrupted;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * La delegación como composición (Q-P19-P): el hijo es una sesión con padre, y el padre recibe un
 * reporte — jamás el transcript.
 *
 * El almacén es REAL y el juez del linaje ya quedó probado en `SessionToolGateTest` con los
 * componentes verdaderos; aquí el corredor del hijo es un guion, porque lo que se mide es la
 * frontera del reporte (§5.2) y que ningún desenlace del hijo desaparezca en silencio
 * (ADR-0029/0033). Un guion que suplantara al juez certificaría al guion — y esa clase de evidencia
 * ya fue refutada seis veces en R-0011..R-0016.
 */
final class SubAgentSpawnerTest extends TestCase
{
    /** El hijo nace bajo su padre, y lo que cruza de vuelta es el reporte — con el id para ir a ver más. */
    public function testTheChildIsBornUnderItsParentAndTheParentGetsAReportNotATranscript(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'la tarea grande');

        $spawner = new SubAgentSpawner($almacen, 'padre', fn (string $encargo, string $hijoId): array => ['answer' => 'hecho: 3 plugins auditados', 'steps' => 4]);

        $reporte = $spawner->spawn(['brief' => 'audita los plugins instalados', 'done_when' => 'el reporte lista cada plugin con su estado']);

        self::assertTrue($reporte['ok']);
        self::assertSame('hecho: 3 plugins auditados', $reporte['report']);
        self::assertSame(4, $reporte['steps']);
        self::assertIsString($reporte['sub_session']);
        self::assertStringStartsWith('padre.sub-', $reporte['sub_session']);

        $hijo = $almacen->load($reporte['sub_session']);
        self::assertNotNull($hijo, 'la sesión hija existe y es consultable por id');
        self::assertSame('padre', $hijo->parentId, 'desciende del padre: el techo del linaje le aplica');

        // LA FRONTERA DEL REPORTE: ninguna llave del resultado carga historial. El transcript del
        // hijo vive en SU stream, no en lo que el padre recibe.
        self::assertSame(['ok', 'report', 'sub_session', 'steps'], array_keys($reporte));
    }

    /** El papel envuelve el encargo que corre; el brief queda como objetivo de la sesión hija. */
    public function testAnUnknownRoleIsRefusedInsteadOfBecomingProse(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $visto = '';
        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $encargo, string $hijoId) use (&$visto): array {
            $visto = $encargo;

            return ['answer' => 'ok', 'steps' => 1];
        });

        // UN ROL YA NO ES PROSA LIBRE, y esto es ruptura declarada.
        //
        // Antes, `role` era una frase que se pegaba al encargo: «tu papel: revisor de seguridad».
        // Eso entregaba una sugerencia y nada más — el catálogo del hijo seguía teniendo todas las
        // herramientas de mutación, y `must` gobierna 0/8. Un revisor así revisa hasta que decide
        // que una edición chica ayudaría.
        //
        // Ahora `role` se busca en el registro, y lo desconocido se rechaza CON LA LISTA de los que
        // existen, antes de abrir la sesión del hijo. Un nombre libre que antes «funcionaba» ahora
        // falla, y falla diciendo por qué — que es mejor que gobernar de mentiras.
        $rechazado = $spawner->spawn(['brief' => 'revisa la entidad Cliente', 'role' => 'revisor de seguridad']);

        self::assertFalse($rechazado['ok']);
        self::assertStringContainsString('unknown role', (string) $rechazado['error']);
        self::assertSame('', $visto, 'y no se abrió ninguna sesión hija');
    }

    /**
     * LA PAUSA DEL HIJO LLEGA AL PADRE CON NOMBRE (falsificador 5 de Q-P19-P).
     *
     * Un hijo que quedó esperando permiso no puede verse como un hijo que terminó: el reporte lo
     * dice y trae la pregunta, para que el padre —o el humano detrás— sepa exactamente qué falta.
     */
    public function testAPausedChildReachesTheParentByName(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $encargo, string $hijoId) use ($almacen): array {
            $almacen->ask($hijoId, new PendingQuestion(
                id: 'perm:make',
                question: 'El agente quiere correr «make», que cambia algo. ¿Lo autorizas en esta sesión?',
                options: ['sí', 'no'],
                why: '{"what":"plugin"}',
            ));

            return ['answer' => 'Me detuve a pedir permiso.', 'steps' => 2];
        });

        $reporte = $spawner->spawn(['brief' => 'anda y hazlo', 'done_when' => 'la mutación quedó aplicada']);

        self::assertTrue($reporte['ok']);
        self::assertTrue($reporte['paused'] ?? false, 'la pausa se dice, no se infiere');
        self::assertStringContainsString('¿Lo autorizas', $reporte['question'] ?? '', 'y trae la pregunta');
    }

    /** Un hijo que truena produce un reporte explícito con su motivo — nunca una desaparición. */
    public function testAChildThatCrashesProducesAnExplicitReport(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $spawner = new SubAgentSpawner($almacen, 'padre', function (): array {
            throw new \RuntimeException('el proveedor rechazó la llave');
        });

        $reporte = $spawner->spawn(['brief' => 'algo', 'done_when' => 'ya']);

        self::assertFalse($reporte['ok']);
        self::assertStringContainsString('el proveedor rechazó la llave', $reporte['error']);
        self::assertIsString($reporte['sub_session'], 'la bitácora del intento queda consultable');
    }

    /** La interrupción del humano NO se convierte en reporte: para el árbol completo. */
    public function testTheHumansInterruptionStopsTheWholeTree(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $spawner = new SubAgentSpawner($almacen, 'padre', function (): array {
            throw RunInterrupted::porElHumano(3);
        });

        $this->expectException(RunInterrupted::class);

        $spawner->spawn(['brief' => 'algo', 'done_when' => 'ya']);
    }

    /** Agotarse se dice: un techo alcanzado entregado como reporte completo fabricaría el «terminé». */
    public function testExhaustionIsNamed(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $spawner = new SubAgentSpawner($almacen, 'padre', fn (): array => ['answer' => AgentOrchestrator::STEPS_EXHAUSTED, 'steps' => 12]);

        $reporte = $spawner->spawn(['brief' => 'algo', 'done_when' => 'ya']);

        self::assertTrue($reporte['exhausted'] ?? false);
        self::assertStringNotContainsString('Error', $reporte['report'], 'la voz es honesta, no críptica');
    }

    /**
     * RETOMAR NO ES RE-SPAWNEAR (Q-P19-Q, falsificador 2): el hijo retomado recibe SU ventana.
     *
     * La decisión contestada ya es un turno de su stream; un corredor que la recibiera vacía
     * perdería el trabajo y la respuesta del humano — «retomar» con contexto fresco es re-spawnear
     * con otro nombre.
     */
    public function testResumingHandsTheChildItsOwnWindowNotFreshContext(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $historiales = [];
        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $encargo, string $hijoId, array $historial) use (&$historiales): array {
            $historiales[] = $historial;

            return ['answer' => 'avancé y me detuve', 'steps' => 2];
        });

        $nacimiento = $spawner->spawn(['brief' => 'audita el plugin Hola', 'done_when' => 'el reporte nombra sus hallazgos']);
        self::assertSame([], $historiales[0], 'el spawn SÍ arranca fresco (§5.1)');

        $hijoId = $nacimiento['sub_session'];
        $reporte = $spawner->resume(['sub_session' => $hijoId]);

        self::assertTrue($reporte['ok']);
        self::assertNotSame([], $historiales[1], 'el resume entrega la ventana del hijo');
        $contenido = implode(' · ', array_map(static fn (array $t): string => $t['content'], $historiales[1]));
        self::assertStringContainsString('audita el plugin Hola', $contenido, 'con su encargo original adentro');
    }

    /** El linaje se valida con lo capturado, no con lo dicho: un ajeno no se retoma (falsificador 1). */
    public function testResumingAForeignSessionIsRefused(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');
        $almacen->start('otro', 'y');
        $almacen->start('otro.sub-abc123', 'z', parentId: 'otro');

        $spawner = new SubAgentSpawner($almacen, 'padre', fn (): array => ['answer' => 'no debería correr', 'steps' => 0]);

        foreach (['otro.sub-abc123', 'otro', 'no-existe'] as $ajena) {
            $reporte = $spawner->resume(['sub_session' => $ajena]);
            self::assertFalse($reporte['ok'], "«{$ajena}» no es hija de «padre» y no se retoma");
        }
    }

    /** Un hijo con pregunta abierta no se retoma: la negativa dice qué falta (falsificador 4). */
    public function testResumingAnUnansweredChildSaysWhatIsMissing(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $encargo, string $hijoId, array $historial) use ($almacen): array {
            $almacen->ask($hijoId, new PendingQuestion(id: 'perm:make', question: '¿autorizas make?', options: ['sí', 'no'], why: 'x'));

            return ['answer' => 'me detuve', 'steps' => 1];
        });

        $hijoId = $spawner->spawn(['brief' => 'algo', 'done_when' => 'ya'])['sub_session'];

        $reporte = $spawner->resume(['sub_session' => $hijoId]);

        self::assertFalse($reporte['ok']);
        self::assertStringContainsString('esperando una respuesta', $reporte['error']);
        self::assertStringContainsString('¿autorizas make?', $reporte['error'], 'y dice CUÁL respuesta falta');
    }

    /** Contestada la pregunta, el resume procede — y una nueva pausa vuelve a llegar con nombre. */
    public function testAnAnsweredChildResumesAndANewPauseIsNamed(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $vuelta = 0;
        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $encargo, string $hijoId, array $historial) use ($almacen, &$vuelta): array {
            ++$vuelta;
            if ($vuelta === 1) {
                $almacen->ask($hijoId, new PendingQuestion(id: 'perm:make', question: '¿autorizas make?', options: ['sí', 'no'], why: 'x'));

                return ['answer' => 'me detuve', 'steps' => 1];
            }

            $almacen->ask($hijoId, new PendingQuestion(id: 'perm:plugins_remove', question: '¿y quitar el viejo?', options: ['sí', 'no'], why: 'y'));

            return ['answer' => 'avancé y me detuve otra vez', 'steps' => 3];
        });

        $hijoId = $spawner->spawn(['brief' => 'algo', 'done_when' => 'ya'])['sub_session'];
        $almacen->answer($hijoId, 'perm:make', 'sí');

        $reporte = $spawner->resume(['sub_session' => $hijoId]);

        self::assertTrue($reporte['ok']);
        self::assertTrue($reporte['paused'] ?? false, 'la nueva pausa se dice');
        self::assertStringContainsString('¿y quitar el viejo?', $reporte['question'] ?? '');
    }

    /**
     * DELEGAR SIN CRITERIO DE TERMINADO PROCEDE — y se midió que así debe ser (Q-P19-S).
     *
     * Nació obligatorio. Con el criterio exigido, el agotamiento subió de 4/8 a 8/8 y el
     * cumplimiento cayó de 8/8 a 5/8: los padres escribían criterios razonables e INALCANZABLES con
     * las herramientas del hijo, y un criterio que nunca se cumple convierte una tarea acotada en
     * una búsqueda sin fondo. Exigir una promesa que el sistema no puede verificar no es una
     * salvaguarda.
     */
    public function testDelegatingWithoutADoneCriterionProceeds(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $visto = '';
        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $encargo, string $hijoId, array $historial) use (&$visto): array {
            $visto = $encargo;

            return ['answer' => 'hecho', 'steps' => 2];
        });

        $reporte = $spawner->spawn(['brief' => 'audita los plugins']);

        self::assertTrue($reporte['ok']);
        self::assertStringNotContainsString('Terminas cuando', $visto, 'sin criterio no se inventa una regla de paro');
    }

    /**
     * LAS OBLIGACIONES LLEGAN AUNQUE EL PADRE NO LAS ESCRIBA (Q-P20-E).
     *
     * Medido: una instrucción dentro de la enumeración del encargo llega 8/8; la misma colgando
     * después, 1/8. El padre reproduce la lista y tira la cola. Mientras el gobierno del hijo viaje
     * dentro del brief, llegar depende de cómo redactó quien delega — y eso es disciplina, no
     * garantía: el día que alguien escriba prosa suelta, la obligación se pierde y nadie lo nota.
     *
     * `must` las lleva aparte, las arma el spawner y el modelo no las redacta.
     */
    public function testTheObligationsArriveEvenIfTheParentDoesNotWriteThem(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $visto = '';
        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $encargo, string $hijoId, array $historial) use (&$visto): array {
            $visto = $encargo;

            return ['answer' => 'ok', 'steps' => 1];
        });

        $spawner->spawn([
            'brief' => 'crea el plugin Hola',
            'must' => ['escribe un plan antes de empezar', 'no uses plugins_lock'],
        ]);

        self::assertStringContainsString('crea el plugin Hola', $visto);
        // NUMERADAS, que es la forma que el modelo conserva — la misma que la medición encontró.
        self::assertStringContainsString('1. escribe un plan antes de empezar', $visto);
        self::assertStringContainsString('2. no uses plugins_lock', $visto);
        self::assertStringContainsString('Obligaciones', $visto, 'y dichas como lo que son');
    }

    /**
     * UNA PROHIBICIÓN SE EJECUTA, NO SE PIDE (Q-P20-G → Q-P20-H).
     *
     * Q-P20-G midió que una obligación en el encargo llega 8/8 y gobierna 0/8. Y Q-P19-F midió lo
     * contrario para el entorno: una opción retirada de la mesa redirige 16/16. `deny` traduce la
     * prohibición de prosa a hecho — la herramienta sale del catálogo del hijo, y el motivo queda
     * apendado en SU stream con código, no con prosa.
     */
    public function testADenialWithdrawsTheToolFromTheChildsTable(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');
        $spawner = new SubAgentSpawner($almacen, 'padre', fn (): array => ['answer' => 'ok', 'steps' => 1]);

        $r = $spawner->spawn(['brief' => 'crea el plugin Hola', 'deny' => ['plugins_lock', 'plugins_remove']]);

        self::assertTrue($r['ok']);
        $hijo = $almacen->load($r['sub_session']);
        self::assertNotNull($hijo);
        self::assertSame(['plugins_lock', 'plugins_remove'], $hijo->removedOptions ?? [], 'salieron de su mesa');
    }

    /** Y el encargo lo DICE además: retirarla sin decirlo dejaría al hijo buscando lo que ya no está. */
    public function testTheChildIsToldWhatWasWithdrawn(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $visto = '';
        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $e, string $h, array $x) use (&$visto): array {
            $visto = $e;

            return ['answer' => 'ok', 'steps' => 1];
        });

        $spawner->spawn(['brief' => 'crea el plugin Hola', 'deny' => ['plugins_lock']]);

        self::assertStringContainsString('plugins_lock', $visto);
        self::assertStringContainsString('en tu catálogo', $visto);
    }

    /** Sin obligaciones no se inventa una sección vacía: el encargo queda como estaba. */
    public function testNoObligationsMeansNoSection(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $visto = '';
        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $e, string $h, array $x) use (&$visto): array {
            $visto = $e;

            return ['answer' => 'ok', 'steps' => 1];
        });

        $spawner->spawn(['brief' => 'crea el plugin Hola']);

        self::assertStringNotContainsString('Obligaciones', $visto);
    }

    /** El criterio viaja al hijo como su regla de paro, aparte del trabajo. */
    public function testTheDoneCriterionTravelsToTheChildAsItsStoppingRule(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $visto = '';
        $spawner = new SubAgentSpawner($almacen, 'padre', function (string $encargo, string $hijoId, array $historial) use (&$visto): array {
            $visto = $encargo;

            return ['answer' => 'ok', 'steps' => 1];
        });

        $spawner->spawn([
            'brief' => 'crea el plugin Hola',
            'done_when' => 'el plugin aparece en plugins_list',
        ]);

        self::assertStringContainsString('crea el plugin Hola', $visto);
        self::assertStringContainsString('Terminas cuando', $visto, 'el criterio se nombra como regla de paro');
        self::assertStringContainsString('el plugin aparece en plugins_list', $visto);
        self::assertStringContainsString('no sigas', $visto, 'y dice explícitamente que ahí se para');
    }

    /** Sin encargo no hay hijo: el sub-agente no ve esta conversación, así que el brief lo es todo. */
    public function testAnEmptyBriefIsRefused(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $spawner = new SubAgentSpawner($almacen, 'padre', fn (): array => ['answer' => 'no debería correr', 'steps' => 0]);

        $reporte = $spawner->spawn(['brief' => '   ']);

        self::assertFalse($reporte['ok']);
        self::assertSame([], $almacen->ids() === [] ? [] : array_filter($almacen->ids(), static fn (string $id): bool => str_starts_with($id, 'padre.sub-')), 'y no nació ninguna sesión hija');
    }

    /**
     * EL FONDO DEL ÁRBOL SE GASTA ENTRE HERMANOS, y la negativa llega antes de abrir la sesión.
     *
     * La profundidad ya era 1 por construcción; la anchura no estaba acotada por nada. Con fondo de
     * 10 y dos hijos que gastan 4 cada uno, el tercero no procede — y no deja stream huérfano que
     * alguien tenga que explicar mañana.
     */
    public function testTheTreesFundIsSpentBetweenSiblingsAndTheThirdOneIsRefused(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'la tarea grande');

        $fondo = new \Milpa\AppRuntime\Agent\TreeBudget(10);
        $spawner = new SubAgentSpawner(
            $almacen,
            'padre',
            fn (): array => ['answer' => 'listo', 'steps' => 4],
            $fondo,
        );

        self::assertTrue($spawner->spawn(['brief' => 'uno'])['ok']);
        self::assertTrue($spawner->spawn(['brief' => 'dos'])['ok']);

        $sesionesAntes = \count($almacen->ids());
        $tercero = $spawner->spawn(['brief' => 'tres']);

        self::assertFalse($tercero['ok'] ?? true, 'el tercero no procede');
        self::assertStringContainsString('2 paso', (string) $tercero['error'], 'y dice cuánto queda');
        self::assertArrayNotHasKey('sub_session', $tercero, 'sin sesión abierta y negada');
        self::assertCount($sesionesAntes, $almacen->ids(), 'no dejó stream huérfano');
    }

    /** Sin fondo declarado corre como siempre: esto no cambia el comportamiento de quien no lo pidió. */
    public function testWithoutAFundNothingChanges(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x');

        $spawner = new SubAgentSpawner($almacen, 'padre', fn (): array => ['answer' => 'listo', 'steps' => 99]);

        for ($i = 0; $i < 4; ++$i) {
            self::assertTrue($spawner->spawn(['brief' => "encargo {$i}"])['ok']);
        }
    }
    /**
     * The child is born knowing (greenhouse decisions/0007): the prologue — the unearned
     * transition's teaching — lands FIRST in the child's recorded goal, so it reaches the
     * stream, the window, and every resume. A spawner without a prologue changes nothing.
     */
    public function testThePrologueLandsFirstInTheChildsGoal(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x', \Milpa\Agent\AutonomyMode::Auto);
        $spawner = new SubAgentSpawner(
            $almacen,
            'padre',
            static fn (string $encargo, string $hijoId): array => ['answer' => 'ok', 'steps' => 1],
            prologue: static fn (): ?string => 'This app is not founded yet — founding comes before building.',
        );

        $reporte = $spawner->spawn(['brief' => 'crea el plugin Agencia']);

        self::assertTrue($reporte['ok']);
        $hijo = $almacen->load($reporte['sub_session']);
        self::assertNotNull($hijo);
        self::assertStringStartsWith('This app is not founded yet', (string) $hijo->goal);
        self::assertStringContainsString('crea el plugin Agencia', (string) $hijo->goal);
    }
    /**
     * The hollow-scout guard (greenhouse decisions/0008): a conforming artifact from a session
     * that called NO tools is not a report — it is silence with formatting. One teaching retry;
     * a repeat travels to the parent MARKED, never silently trusted. Causality, not quality:
     * the guard counts recorded calls, never words.
     */
    public function testAHollowScoutGetsOneTeachingRetryAndARepeatTravelsMarked(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x', \Milpa\Agent\AutonomyMode::Auto);
        $vueltas = 0;
        $spawner = new SubAgentSpawner(
            $almacen,
            'padre',
            static function (string $encargo, string $hijoId) use (&$vueltas): array {
                ++$vueltas;

                return ['answer' => '{"findings": [], "searched": []}', 'steps' => 1];
            },
        );

        $reporte = $spawner->spawn(['brief' => 'verifica el directorio del plugin', 'produces' => 'findings']);

        self::assertSame(2, $vueltas);
        self::assertTrue($reporte['ok']);
        self::assertTrue($reporte['hollow'] ?? false);
        self::assertStringContainsString('called no tools', (string) $reporte['report']);
    }

    /** A child that LOOKED — recorded tool calls — is untouched by the guard. */
    public function testAScoutThatLookedIsUntouched(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x', \Milpa\Agent\AutonomyMode::Auto);
        $vueltas = 0;
        $spawner = new SubAgentSpawner(
            $almacen,
            'padre',
            static function (string $encargo, string $hijoId) use (&$vueltas, $almacen): array {
                ++$vueltas;
                $almacen->recordToolCall($hijoId, 'plugins_list', [], '{"ok":true}', true, false);

                return ['answer' => '{"findings": [{"claim": "no existe src/Plugins/Agencia", "where": "src/Plugins"}], "searched": ["src/Plugins"]}', 'steps' => 2];
            },
        );

        $reporte = $spawner->spawn(['brief' => 'verifica el directorio del plugin', 'produces' => 'findings']);

        self::assertSame(1, $vueltas);
        self::assertTrue($reporte['ok']);
        self::assertFalse($reporte['hollow'] ?? false);
        self::assertStringNotContainsString('called no tools', (string) $reporte['report']);
    }

    /** No artifact contract, no guard: an errand that is not a report may legitimately not look. */
    public function testAnErrandWithoutAnArtifactContractIsNotGuarded(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'x', \Milpa\Agent\AutonomyMode::Auto);
        $vueltas = 0;
        $spawner = new SubAgentSpawner(
            $almacen,
            'padre',
            static function (string $encargo, string $hijoId) use (&$vueltas): array {
                ++$vueltas;

                return ['answer' => 'hecho', 'steps' => 1];
            },
        );

        $reporte = $spawner->spawn(['brief' => 'resume el objetivo del encargo en una línea']);

        self::assertSame(1, $vueltas);
        self::assertTrue($reporte['ok']);
        self::assertArrayNotHasKey('hollow', $reporte);
    }
}
