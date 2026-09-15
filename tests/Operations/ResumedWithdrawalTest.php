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
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** A new invocation must bind durable operator withdrawal to both offer and execution. */
final class ResumedWithdrawalTest extends TestCase
{
    /** Recomposition must neither reopen explicit withdrawals nor enable automatic withdrawal. */
    #[DataProvider('modes')]
    public function testRecomposedTablePreservesOperatorAndAutomaticSemantics(bool|string $mode, string $reason, bool $blocked): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['agent' => ['removeRefusedOptions' => $mode]]));
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Retain the operator decision');
        $store->removeOption('s', 'mark', $reason);
        $table = (new \ReflectionMethod(AgentOperations::class, 'invocationOptionTable'))->invoke(new AgentOperations($container), $store, 's', []);
        $handled = 0;
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('mark', 'Mark', ['type' => 'object'], static function () use (&$handled): string {
            ++$handled;
            return 'marked';
        });
        $bridge = new ConsentBridge($registry, table: $table);
        self::assertSame($blocked, $bridge->getToolSummaries() === []);
        try {
            self::assertSame('marked', $bridge->callTool('mark', []));
            self::assertFalse($blocked, 'A retired operation executed');
        } catch (ToolCallRefused $e) {
            self::assertTrue($blocked);
            self::assertTrue($e->optionRemoved);
        }
        self::assertSame($blocked ? 0 : 1, $handled);
        self::assertCount(2, $store->stream('s'), 'Reading the table must not append another withdrawal');
    }

    /** @return iterable<string, array{bool|string, string, bool}> */
    public static function modes(): iterable
    {
        foreach ([false, true, 'record-only'] as $mode) {
            yield json_encode($mode) . '-operator' => [$mode, 'denied-by-operator', true];
            yield json_encode($mode) . '-automatic' => [$mode, 'refused', $mode === true];
        }
    }
}
