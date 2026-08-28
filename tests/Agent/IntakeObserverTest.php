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

use Milpa\Agent\SessionObservation;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\IntakeObserver;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The wire between the channel and the stream.
 *
 * Everything either side of this is already pinned: the gateway hands over what it serialized, the
 * store records what it is handed. What was missing is that anybody connect them — and until one
 * does, a developer surface truthfully reports that nobody observed the intake, forever.
 */
final class IntakeObserverTest extends TestCase
{
    public function testWhatTravelledBecomesAnswerableFromTheSurface(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'listar plugins');

        (new IntakeObserver($almacen, 's1'))->observe('https://llama.local/v1/chat/completions', [
            'model' => 'qwen3-coder:30b',
            'messages' => [['role' => 'system', 'content' => 'eres un agente'], ['role' => 'user', 'content' => 'hola']],
            'tools' => [['name' => 'plugins_list'], ['name' => 'config_set']],
        ]);

        $o = SessionObservation::of($eventos, 's1');

        self::assertTrue($o->answers['tools_offered']['answered']);
        self::assertSame(['plugins_list', 'config_set'], $o->answers['tools_offered']['value']);
        self::assertSame('qwen3-coder:30b', $o->answers['context_received']['value']['model']);
    }

    public function testTheCostOfACallBecomesItsOwnFactBesideTheIntake(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'listar plugins');

        // The same observer that records the request is the one the gateway reports the return to:
        // one wire, both halves. The gateway already normalized the usage across providers.
        (new IntakeObserver($almacen, 's1'))->observeReturn('https://llama.local/v1/chat/completions', [
            'model' => 'qwen3-coder:30b',
            'usage' => ['prompt_tokens' => 17, 'completion_tokens' => 16, 'total_tokens' => 33],
        ]);

        $payload = null;
        foreach ($eventos->replay(SessionStore::PREFIX . 's1') as $event) {
            if ($event->type === 'session.model_returned') {
                $payload = $event->payload;
            }
        }

        self::assertNotNull($payload, 'the return reached the stream as its own event');
        self::assertSame('qwen3-coder:30b', $payload['model']);
        self::assertSame(33, $payload['usage']['total_tokens']);
    }

    public function testTheDeclaredWindowReachesTheStreamWithoutEnteringTheProviderPayload(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'continue the work');
        $store->setPlan('s1', '1. Inspect 2. Change 3. Verify');
        $session = $store->load('s1') ?? self::fail('the session must exist');
        $declaredWindow = $session->classifiedWindow();
        $providerPayload = [
            'model' => 'qwen3-coder:30b',
            'messages' => [
                ['role' => 'system', 'content' => 'you are an agent'],
                ...$session->window(),
                ['role' => 'user', 'content' => 'continue'],
            ],
        ];

        foreach ($providerPayload['messages'] as $message) {
            self::assertArrayNotHasKey('class', $message);
        }

        (new IntakeObserver($store, 's1', $declaredWindow))->observe(
            'https://llama.local/v1/chat/completions',
            $providerPayload,
        );

        $recorded = null;
        foreach ($events->replay(SessionStore::PREFIX . 's1') as $event) {
            if ($event->type === 'session.model_called') {
                $recorded = $event->payload;
            }
        }

        self::assertNotNull($recorded);
        self::assertSame($declaredWindow, $recorded['window']);
        foreach ($recorded['messages'] as $message) {
            self::assertArrayNotHasKey('class', $message);
        }
    }

    /**
     * OBSERVING MAY NOT BREAK WHAT IT OBSERVES.
     *
     * A recorder that throws would turn a working agent into a broken one the moment somebody asked
     * to watch it — and the failure would look like the agent's, not the instrument's. So a bad write
     * loses the observation and nothing else.
     */
    public function testAFailedRecordingNeverReachesTheAgent(): void
    {
        $discoLleno = new class () implements \Milpa\EventStore\EventStoreInterface {
            public function append(\Milpa\EventStore\Event $event): void
            {
                throw new \RuntimeException('el disco se llenó');
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
        };

        (new IntakeObserver(new SessionStore($discoLleno), 's1'))->observe('https://x/y', ['model' => 'm', 'messages' => []]);

        self::assertTrue(true, 'llegar aquí es la prueba: la excepción no salió');
    }
}
