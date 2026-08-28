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
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The READ half of identity (decisions/0117): session:owner reports who the house recognizes, produced
 * live, so a surface can project identity without proclaiming a grade the house did not produce. The
 * verified path needs real gpg and is measured on cattle; here the honest «system user» paths are pinned.
 */
final class SessionOwnerReadTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            @unlink($d . '/config/identity.php');
            @rmdir($d . '/config');
            @rmdir($d);
        }
    }

    public function testItReadsAndReachesNobodyAndChangesNothing(): void
    {
        $op = $this->operation();
        self::assertSame(Mutation::None, $op->effects->mutation);
        self::assertSame(Authority::Read, $op->effects->authority);
        self::assertFalse($op->requiresConfirmation);
    }

    public function testAnUnownedSessionIsTheSystemUser(): void
    {
        [$c] = $this->container();
        $store = new SessionStore($this->events);
        $store->start('s1', 'a goal');

        $r = $this->call($c, ['session' => 's1']);

        self::assertTrue($r['ok']);
        self::assertFalse($r['verified']);
        self::assertNull($r['owner']);
        self::assertSame([], $r['scopes']);
        self::assertStringContainsString('system user', (string) $r['note']);
    }

    public function testAMissingSessionSaysSo(): void
    {
        [$c] = $this->container();
        new SessionStore($this->events); // store exists but the session was never started

        $r = $this->call($c, ['session' => 'nope']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('does not exist', (string) $r['error']);
    }

    public function testWithoutASessionNothingIsRead(): void
    {
        [$c] = $this->container();
        $r = $this->call($c, []);
        self::assertFalse($r['ok']);
    }

    // --- helpers ---

    private InMemoryEventStore $events;

    /** @return array{0: DIContainer, 1: string} */
    private function container(): array
    {
        $this->events = new InMemoryEventStore();
        $root = sys_get_temp_dir() . '/milpa-owner-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o777, true);
        $this->dirs[] = $root;

        $c = new DIContainer();
        $c->registerService(SessionStore::class, new SessionStore($this->events));
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setAccessible(true);
            $p->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);

        return [$c, $root];
    }

    private function operation(): \Milpa\Command\Operation
    {
        [$c] = $this->container();
        foreach ((new AgentOperations($c))->operations() as $op) {
            if ($op->name === 'session:owner') {
                return $op;
            }
        }
        self::fail('session:owner is not offered');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $c, array $input): array
    {
        foreach ((new AgentOperations($c))->operations() as $op) {
            if ($op->name === 'session:owner') {
                /** @var array<string, mixed> */
                return ($op->handler)($input);
            }
        }
        self::fail('session:owner is not offered');
    }
}
