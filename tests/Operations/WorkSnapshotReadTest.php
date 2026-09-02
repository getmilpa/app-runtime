<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Mutation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * D-06 (greenhouse decisions/0187): work:snapshot projects where the WORK stands from the session's
 * own stream, so the model stops re-reading files to know its position. The derivation is proven in
 * milpa/agent's WorkSnapshotTest; this pins the operation's wiring — read-only contract, the missing
 * -session refusal, and that a real stream flows through to the projected fields.
 */
final class WorkSnapshotReadTest extends TestCase
{
    private InMemoryEventStore $events;

    public function testItReadsAndReachesNobodyAndChangesNothing(): void
    {
        $op = $this->operation();
        self::assertSame(Mutation::None, $op->effects->mutation);
        self::assertSame(Authority::Read, $op->effects->authority);
        self::assertFalse($op->requiresConfirmation);
    }

    public function testAMissingSessionSaysSo(): void
    {
        $c = $this->container();
        new SessionStore($this->events); // store exists but the session was never started

        $r = $this->call($c, ['session' => 'nope']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('does not exist', (string) $r['error']);
    }

    public function testWithoutASessionNothingIsRead(): void
    {
        $r = $this->call($this->container(), []);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('required', (string) $r['error']);
    }

    public function testARealStreamFlowsThroughToTheProjectedFields(): void
    {
        $c = $this->container();
        $store = new SessionStore($this->events);
        $store->start('s1', 'build the greeter');
        $store->setPlan('s1', 'ship the GreeterService');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService'],
            '{"ok":true,"file":"x","verified":"syntax"}',
            true,
            true,
        );

        $r = $this->call($c, ['session' => 's1']);

        self::assertTrue($r['ok']);
        self::assertSame('s1', $r['session']);
        self::assertSame('ship the GreeterService', $r['objective']);
        self::assertContains('GreeterService', $r['materialized']);
        self::assertContains('GreeterService', $r['verified']);
    }

    // --- helpers ---

    private function container(): DIContainer
    {
        $this->events = new InMemoryEventStore();
        $c = new DIContainer();
        $c->registerService(SessionStore::class, new SessionStore($this->events));

        return $c;
    }

    private function operation(): \Milpa\Command\Operation
    {
        return $this->find($this->container());
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $c, array $input): array
    {
        /** @var array<string, mixed> */
        return ($this->find($c)->handler)($input);
    }

    private function find(DIContainer $c): \Milpa\Command\Operation
    {
        foreach ((new AgentOperations($c))->operations() as $op) {
            if ($op->name === 'work:snapshot') {
                return $op;
            }
        }
        self::fail('work:snapshot is not offered');
    }
}
