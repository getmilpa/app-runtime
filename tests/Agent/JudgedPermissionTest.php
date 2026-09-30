<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\JudgedPermission;
use Milpa\Command\Operation;
use Milpa\Console\Events\ConsoleEvents;
use Milpa\Console\Events\OperationExecutingEvent;
use Milpa\Eventing\EventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The HTTP policy's verdict is one run's: marked by that run, taken once, for that operation object only. */
final class JudgedPermissionTest extends TestCase
{
    private static function operation(?string $permission): Operation
    {
        return new Operation('sync_push', '', static fn (): null => null, mutating: true, permission: $permission);
    }

    private static function starts(EventDispatcher $events, Operation $operation, string $surface): void
    {
        $events->dispatch(ConsoleEvents::EXECUTING, ['event' => new OperationExecutingEvent($operation, [], $surface)]);
    }

    public function testOnlyTheHttpRunOfAPermissionedOperationIsAVerdict(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $mark = JudgedPermission::listen($events);
        $push = self::operation('attendance:write');

        self::starts($events, $push, 'cli');
        self::assertFalse($mark->take($push), 'another surface judged nothing');

        $bare = self::operation(null);
        self::starts($events, $bare, 'http');
        self::assertFalse($mark->take($bare), 'an operation without a permission has no permission verdict');

        self::starts($events, $push, 'http');
        self::assertTrue($mark->take($push));
    }

    public function testTheVerdictIsTakenOnceAndOnlyByTheOperationItJudged(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $mark = JudgedPermission::listen($events);
        $push = self::operation('attendance:write');

        self::starts($events, $push, 'http');
        self::assertFalse($mark->take(self::operation('attendance:write')), 'the same name is not the same run');
        self::assertFalse($mark->take($push), 'a question asked by another operation still spends the verdict');

        self::starts($events, $push, 'http');
        self::assertTrue($mark->take($push));
        self::assertFalse($mark->take($push), 'a call the handler makes finds no verdict');
    }

    public function testARunThatEndedLeavesNoVerdictBehind(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $mark = JudgedPermission::listen($events);
        $push = self::operation('attendance:write');

        self::starts($events, $push, 'http');
        $events->dispatch(ConsoleEvents::EXECUTED, []);

        self::assertFalse($mark->take($push));
    }
}
