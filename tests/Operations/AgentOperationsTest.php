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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The standing obligation, at the operation seam: declared, LIFTED, read back, or renewed.
 *
 * This is where the sixth arm's side finding lived — «the docblock promises that an empty `--first`
 * lifts it, and the code does not do it». The empty list fell through to the renewal, which
 * re-armed with `todo` what the caller had just tried to unset. With the renewal's default staying
 * ON (Rod, 2026-08-06, sexto-brazo.tsv), the lift is the per-session exit — a promise that broken
 * would leave no way out but abandoning the session.
 */
final class AgentOperationsTest extends TestCase
{
    private SessionStore $store;
    private AgentOperations $operations;

    protected function setUp(): void
    {
        $this->store = new SessionStore(new InMemoryEventStore());
        $contenedor = new DIContainer();
        $contenedor->registerService(SessionStore::class, $this->store);
        $this->operations = new AgentOperations($contenedor);
    }

    /** Declaring writes the obligation to the SESSION — it outlives the invocation that typed it. */
    public function testDeclaringWritesTheObligationToTheSession(): void
    {
        $this->store->start('s', 'work', AutonomyMode::Auto);

        $told = $this->operations->standingObligation('plan,todo', 's', $this->store);

        self::assertSame(['plan', 'todo'], $told);
        self::assertSame(['plan', 'todo'], $this->store->load('s')?->runFirst);
        self::assertTrue($this->store->load('s')?->obligationDeclared);
    }

    /**
     * AN EMPTY `--first` IS THE LIFT, and the renewal stops with it.
     *
     * Both halves in one test on purpose: a lift that returned `[]` for this turn but left the
     * discipline armed would hand the next turn a renewed `todo` — unset by the caller, re-set by
     * the session. That is the exact defect the sixth arm found.
     */
    public function testAnEmptyFirstLiftsTheObligationAndTheRenewalStops(): void
    {
        $this->store->start('s', 'work', AutonomyMode::Auto);
        $this->store->requireFirst('s', ['plan']);
        $this->store->setPlan('s', 'a plan');
        $this->store->setTodo('s', new Todo('t1', 'pending work', TodoStatus::Pending));

        $told = $this->operations->standingObligation('', 's', $this->store);

        self::assertSame([], $told, 'the lift governs this very turn');
        self::assertFalse($this->store->load('s')?->obligationDeclared, 'the lift is a fact, not a local variable');
        self::assertSame(
            [],
            $this->operations->standingObligation(null, 's', $this->store),
            'and the next turn renews NOTHING — the discipline ended',
        );
    }

    /**
     * THE STERILE-LOOP GUARD IS ON BY DEFAULT — decided 2026-08-06 with churn-signal.tsv in hand:
     * at its home tolerance it would have refused 81 of the sick run's 89 calls and ZERO of the
     * healthy runs'. Opting out stays possible and explicit — a session that runs unguarded is a
     * session somebody declared that way, no longer the silent state every app woke up in.
     */
    public function testTheSterileLoopGuardIsOnByDefaultAndOptOutIsExplicit(): void
    {
        self::assertNotNull($this->operations->sterileLoopGuard(), 'no config at all: the guard is on');

        $contenedor = new DIContainer();
        $contenedor->registerService(\Milpa\Runtime\Config::class, new \Milpa\Runtime\Config([
            'agent' => ['sterileLoopGuard' => false],
        ]));
        self::assertNull(
            (new AgentOperations($contenedor))->sterileLoopGuard(),
            'false is the explicit opt-out',
        );

        $contenedor = new DIContainer();
        $contenedor->registerService(\Milpa\Runtime\Config::class, new \Milpa\Runtime\Config([
            'agent' => ['sterileLoopGuard' => 5],
        ]));
        self::assertNotNull(
            (new AgentOperations($contenedor))->sterileLoopGuard(),
            'an integer keeps it on with that tolerance',
        );
    }

    /**
     * The renewal ORIENTS instead of curating — decided twice, same day, second time with the
     * rematch's clean numbers (sexto-brazo-revancha.tsv): the board-pointed shape terminated 2/9
     * against the innocuous read's 6/9, two of three plugins in every run. A turn opened by
     * bookkeeping becomes a bookkeeping turn; a turn opened by reading the session's own state
     * keeps its momentum. And it still fires only when there is work to resume.
     */
    public function testTheRenewalOrientsAndOnlyWhenThereIsWorkToResume(): void
    {
        $this->store->start('s', 'work', AutonomyMode::Auto);
        $this->store->requireFirst('s', ['plan']);
        $this->store->setPlan('s', 'a plan');
        $this->store->setTodo('s', new Todo('t1', 'pending work', TodoStatus::Pending));

        self::assertSame(
            ['agent_show'],
            $this->operations->standingObligation(null, 's', $this->store),
            'declared + plan + pending: the system renews with orientation, not curation',
        );

        $this->store->setTodo('s', new Todo('t1', 'pending work', TodoStatus::Done, version: 2));
        self::assertSame(
            [],
            $this->operations->standingObligation(null, 's', $this->store),
            'all items done: pushing now would be nagging',
        );
    }

    /** The board-pointed shape is one config key away, and disabling renewal is declared, never silent. */
    public function testTheRenewalToolIsAKnobAndDisablingItIsExplicit(): void
    {
        $armar = function (mixed $renewalTool): AgentOperations {
            $contenedor = new DIContainer();
            $contenedor->registerService(SessionStore::class, $this->store);
            $contenedor->registerService(\Milpa\Runtime\Config::class, new \Milpa\Runtime\Config([
                'agent' => ['renewalTool' => $renewalTool],
            ]));

            return new AgentOperations($contenedor);
        };

        $this->store->start('s', 'work', AutonomyMode::Auto);
        $this->store->requireFirst('s', ['plan']);
        $this->store->setPlan('s', 'a plan');
        $this->store->setTodo('s', new Todo('t1', 'pending work', TodoStatus::Pending));

        self::assertSame(
            ['todo'],
            $armar('todo')->standingObligation(null, 's', $this->store),
            'the curated-board shape stays available by name',
        );
        self::assertSame(
            [],
            $armar(false)->standingObligation(null, 's', $this->store),
            'false is the declared opt-out — the 0/9 arm, never a silent state',
        );
    }

    // ── EL CATÁLOGO DICE QUÉ MUNDO ESTÁ ENSEÑANDO (greenhouse evidence/0187) ────────────────────
    //
    // Se anunciaba como «el catálogo que un agente recibiría» y enseñaba 22 mientras viajaban 28.
    // Las seis que faltaban eran las de sesión —planear, anotar, delegar—, y `--session` se ignoraba
    // en silencio, así que quien la pasaba creía que la había usado.

    /** Un esquema lo leen los agentes también: lo que no se declara, no se puede pedir. */
    public function testTheCatalogueDeclaresThatItTakesASession(): void
    {
        $esquema = null;
        foreach ($this->operations->operations() as $op) {
            if ($op->name === 'agent:catalogue') {
                $esquema = $op->inputSchema;
            }
        }

        self::assertNotNull($esquema, 'agent:catalogue existe');
        self::assertArrayHasKey('session', $esquema['properties'] ?? [], 'y declara que toma una sesión');
    }

    /** Una sesión que no existe se DICE. Devolver el catálogo pelón es contestar otra pregunta. */
    public function testAnUnknownSessionIsSaidRatherThanIgnored(): void
    {
        $r = $this->operations->catalogueFor(['session' => 'no-existe']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no-existe', (string) ($r['error'] ?? ''));
    }

    /** Sin sesión, dice CUÁNTAS más habría con una — o sigue enseñando un mundo sin decirlo. */
    public function testWithoutASessionItSaysWhatASessionWouldAdd(): void
    {
        $r = $this->operations->catalogueFor([]);

        self::assertNull($r['session'], 'ninguna sesión, y se dice');
        self::assertArrayHasKey('withSession', $r, 'y se dice qué agregaría una');
        self::assertNotSame([], $r['withSession']['tools'] ?? [], 'nombrándolas, no contándolas nada más');
    }

    /**
     * EL PULSO DEL MODELO (greenhouse evidence/0307): sin superficie viva no hay a quién empujar,
     * así que no se streamea; con una superficie, cada trozo real late un hecho `activity` para que
     * el spinner avance por evento, y se throttlea para no repintar por token.
     */
    public function testTheModelPulseFiresActivityOnlyWithALiveSurfaceAndIsThrottled(): void
    {
        $sinSuperficie = new AgentOperations(new DIContainer());
        $metodo = new \ReflectionMethod(AgentOperations::class, 'progresoDelModelo');
        $metodo->setAccessible(true);
        self::assertNull($metodo->invoke($sinSuperficie), 'sin SurfaceBroadcaster, no hay pulso (ni streaming)');

        $superficie = new class () implements \Milpa\AppRuntime\Agent\SurfaceBroadcaster {
            /** @var list<array{topic: string, payload: array<string, mixed>}> */
            public array $empujes = [];

            public function broadcast(string $topic, array $payload): void
            {
                $this->empujes[] = ['topic' => $topic, 'payload' => $payload];
            }
        };
        $contenedor = new DIContainer();
        $contenedor->registerService(\Milpa\AppRuntime\Agent\SurfaceBroadcaster::class, $superficie);
        $pulso = $metodo->invoke(new AgentOperations($contenedor));

        self::assertInstanceOf(\Closure::class, $pulso);

        $pulso('primer trozo');
        $pulso('segundo trozo, inmediato');   // dentro de la ventana de throttle: no debe empujar

        self::assertCount(1, $superficie->empujes, 'el primer trozo late; el inmediato se throttlea');
        self::assertSame('activity', $superficie->empujes[0]['payload']['kind'] ?? null);
        self::assertSame('thinking', $superficie->empujes[0]['payload']['activity']['state'] ?? null);
    }


    /**
     * LA GUARDA POR REFLEXIÓN (greenhouse evidence/0309): `onStreamChunk` es de una versión
     * posterior de milpa/ai-gateway —capacidad opcional cuya versión esta app no fija—, así que la
     * app pregunta al constructor real si lo admite. Con el ai-gateway viejo debe correr sin
     * streaming (degradar), con el nuevo cablearlo — nunca reventar por pasar un arg que no existe.
     * La aserción es versión-agnóstica: concuerda con lo que el constructor REALMENTE declara.
     */
    public function testStreamingIsWiredOnlyWhenTheInstalledLlmServiceDeclaresIt(): void
    {
        $metodo = new \ReflectionMethod(AgentOperations::class, 'llmServiceAdmiteStreaming');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke(new AgentOperations(new DIContainer()));

        $declarado = false;
        foreach ((new \ReflectionMethod(\Milpa\AiGateway\LlmService::class, '__construct'))->getParameters() as $parametro) {
            if ($parametro->getName() === 'onStreamChunk') {
                $declarado = true;
                break;
            }
        }

        self::assertSame($declarado, $resultado, 'la guarda debe seguir lo que el LlmService instalado realmente acepta');
    }

}
