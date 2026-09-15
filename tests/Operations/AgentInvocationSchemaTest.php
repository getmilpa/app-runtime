<?php

/**
 * This file is part of Milpa App Runtime.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The installed session capability must survive operation discovery before Kernel registration. */
final class AgentInvocationSchemaTest extends TestCase
{
    /** Describing an operation cannot depend on resolving its invocation's session store. */
    public function testDiscoveryDoesNotResolveTheSessionStore(): void
    {
        $container = new DIContainer();
        $operations = new class ($container) extends AgentOperations {
            protected function sessions(): ?SessionStore
            {
                throw new \LogicException('Discovery tried to resolve the session store.');
            }
        };
        $before = self::agent($operations)->inputSchema;
        $container->registerService(EventStoreInterface::class, new InMemoryEventStore());
        $after = self::agent(new AgentOperations($container))->inputSchema;

        self::assertSame($before, $after);
        foreach (['delivery', 'expectation', 'deliveryCandidate', 'deny', 'denyEffects', 'grant'] as $field) {
            self::assertSame('string', $before['properties'][$field]['type'] ?? null);
        }
    }

    /** Explicit persistent inputs cannot fall through to a stateless provider call. */
    #[DataProvider('persistentInputs')]
    public function testMissingStoreRefusesExplicitPersistentInputs(string $field, string $value): void
    {
        $container = self::container();
        $result = (self::agent(new AgentOperations($container))->handler)([
            'prompt' => 'A transport probe', 'session' => 's', $field => $value,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('Delivery, withdrawals and launch grants require a session store.', $result['error']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function persistentInputs(): iterable
    {
        yield 'delivery' => ['delivery', json_encode([
            'workspace' => 'w071800000000', 'artifactPath' => 'src/Owned.php',
            'test' => ['path' => 'tests', 'filter' => 'OwnedTest'],
            'screen' => ['name' => 'owned', 'type' => 'owned'],
        ], JSON_THROW_ON_ERROR)];
        yield 'expectation' => ['expectation', '{"test":{"path":"tests","filter":""},"screen":{"name":"focus","type":"counter"}}'];
        yield 'deliveryCandidate' => ['deliveryCandidate', 'w123456789abc'];
        yield 'deny' => ['deny', 'probe_read'];
        yield 'denyEffects' => ['denyEffects', 'mutating'];
        yield 'grant' => ['grant', 'probe:write'];
    }

    /** A misspelled effect class is rejected before even a new session is appended. */
    public function testInvalidEffectsDoNotStartASession(): void
    {
        $container = self::container();
        $events = new InMemoryEventStore();
        $container->registerService(EventStoreInterface::class, $events);
        $result = (self::agent(new AgentOperations($container))->handler)([
            'prompt' => 'A transport probe', 'session' => 's', 'denyEffects' => 'mutates',
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('unknown effect class', $result['error']);
        self::assertSame([], (new SessionStore($events))->stream('s'));
    }

    /** A loopback endpoint makes accidental provider access fail without contacting a service. */
    private static function container(): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config([
            'agent' => ['baseUrl' => 'http://127.0.0.1:1', 'model' => 'fixture'],
        ]));

        return $container;
    }

    /** Select the actual public operation, including its declared schema and invocation handler. */
    private static function agent(AgentOperations $operations): Operation
    {
        foreach ($operations->operations() as $operation) {
            if ($operation->name === 'agent') {
                return $operation;
            }
        }

        throw new \LogicException('The installed agent operation is missing.');
    }
}
