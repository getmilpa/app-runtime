<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\IntakeObserver;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * `agent:observe` — the developer's surface, and the position test that judges it.
 *
 * The role is transversal: what defines it is needing both the human's view and the agent's at once.
 * So the operation ships a single fact and both projections read it — because if a human has to open
 * logs, source and traces to reconstruct what the agent received, the position is not served either.
 */
final class ObserveOperationTest extends TestCase
{
    private function operations(InMemoryEventStore $eventos): SessionOperations
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(EventStoreInterface::class, $eventos);

        return new SessionOperations($contenedor);
    }

    private function conUnaCorridaObservada(InMemoryEventStore $eventos): void
    {
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'listar los plugins');
        (new IntakeObserver($almacen, 's1'))->observe('https://llama.local/v1/chat/completions', [
            'model' => 'qwen3-coder:30b',
            'system' => 'eres un agente de milpa',
            'messages' => [['role' => 'user', 'content' => 'listar los plugins']],
            'tools' => [['name' => 'plugins_list'], ['name' => 'config_set']],
        ]);
        $almacen->recordToolCall('s1', 'plugins_list', [], 'ok: dos plugins', true, false);
    }

    private function correr(SessionOperations $ops, array $input): array
    {
        foreach ($ops->operations() as $operacion) {
            if ($operacion->name === 'agent:observe') {
                return ($operacion->handler)($input);
            }
        }

        self::fail('la operación agent:observe no está en el catálogo');
    }

    public function testTheAgentGetsTheSameSevenAnswersAsAFact(): void
    {
        $eventos = new InMemoryEventStore();
        $this->conUnaCorridaObservada($eventos);

        $r = $this->correr($this->operations($eventos), ['session' => 's1']);

        self::assertTrue($r['ok']);
        self::assertSame(
            ['tools_offered', 'context_received', 'omitted', 'called', 'returned', 'gate', 'between_turns'],
            array_keys($r['result']['answers']),
        );
        self::assertSame(['plugins_list', 'config_set'], $r['result']['answers']['tools_offered']['value']);
    }

    /**
     * IT SAYS WHERE IT LOOKED, and that is not decoration.
     *
     * This test used to assert the opposite — that `omitted` was reported as unanswerable — and it
     * was right until the slice that gave the question an answer: the withdrawal was already a fact
     * of the stream and the view simply was not reading it. What survives is the property underneath,
     * which never depended on the question being open: an answer carries its own scope, because a
     * partial view that does not declare itself partial leaves its reader debugging the wrong world
     * with confidence.
     */
    public function testEveryAnswerCarriesItsOwnScope(): void
    {
        $eventos = new InMemoryEventStore();
        $this->conUnaCorridaObservada($eventos);

        $r = $this->correr($this->operations($eventos), ['session' => 's1']);

        self::assertTrue($r['result']['answers']['omitted']['answered']);
        self::assertSame(
            'session.option_removed',
            $r['result']['answers']['omitted']['value']['readFrom'],
            'the answer names the fact it was read from, so its limits travel with it',
        );
    }

    /**
     * A run nobody observed must not look like an agent that was offered nothing. This is the
     * distinction the surface exists to protect, checked at the surface and not only underneath it.
     */
    public function testAnUnobservedRunSaysSoInsteadOfShowingZeroTools(): void
    {
        $eventos = new InMemoryEventStore();
        (new SessionStore($eventos))->start('s2', 'x');

        $r = $this->correr($this->operations($eventos), ['session' => 's2']);

        self::assertFalse($r['result']['answers']['tools_offered']['answered']);
        self::assertContains('tools_offered', $r['result']['cannotSay']);
    }

    public function testAnUnknownSessionIsRefusedRatherThanShownEmpty(): void
    {
        $r = $this->correr($this->operations(new InMemoryEventStore()), ['session' => 'no-existe']);

        self::assertFalse($r['ok']);
    }

    /** Reading what already happened authorizes nothing, so it is offered wherever it is useful. */
    public function testItIsAReadAndOfferedOnEverySurface(): void
    {
        foreach ((new SessionOperations(new DIContainer()))->operations() as $o) {
            if ($o->name === 'agent:observe') {
                self::assertFalse($o->mutating);
                self::assertContains('mcp', $o->surfaces);
                self::assertContains('cli', $o->surfaces);

                return;
            }
        }

        self::fail('la operación agent:observe no está en el catálogo');
    }
}
