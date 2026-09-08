<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * THE AGENT'S TOOLS ARE EXECUTED BY WHOEVER CALLED THE TURN (greenhouse evidence/0561).
 *
 * The `agent` operation used to take only its input, so the turn never knew which door it came through and
 * wrote the terminal as the executor of every tool. Now the handler takes the invocation, keeps it for the
 * turn, and the bridge it builds names that caller.
 */
final class TheAgentsToolsAreExecutedByWhoeverCalledTheTurnTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ToolRegistry::class)) {
            self::markTestSkipped('sin milpa/tool-runtime no hay puente que armar');
        }
    }

    /** The `agent` operation's handler accepts the invocation — the runner hands it to handlers that do. */
    public function testTheAgentHandlerTakesTheInvocationThatCalledIt(): void
    {
        foreach ((new AgentOperations(new DIContainer()))->operations() as $op) {
            if ($op->name !== 'agent') {
                continue;
            }
            $handler = $op->handler;
            self::assertIsCallable($handler);
            $parameters = (new \ReflectionFunction(\Closure::fromCallable($handler)))->getParameters();
            self::assertCount(2, $parameters, 'input and the invocation');
            $type = $parameters[1]->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertSame(InvocationContext::class, $type->getName());
            self::assertTrue($type->allowsNull(), 'a terminal turn carries none');

            return;
        }
        self::fail('no `agent` operation');
    }

    /** A turn that came over HTTP with a verified actor runs its tools AS that actor; a terminal turn as the terminal. */
    public function testTheBridgeNamesTheCallerAsTheExecutor(): void
    {
        $ops = new AgentOperations(new DIContainer());
        $context = new \ReflectionProperty(AgentOperations::class, 'contextoDeLaVuelta');
        $build = new \ReflectionMethod(AgentOperations::class, 'governedExecutor');

        $context->setValue($ops, InvocationContext::web(actor: 'actor:passkey:YkS3', authorizationId: 'agent'));
        $web = $build->invoke($ops, new ToolRegistry(new NullLogger()), null, null, null);
        self::assertInstanceOf(ConsentBridge::class, $web);
        $executor = (new \ReflectionProperty(ConsentBridge::class, 'executor'))->getValue($web);
        self::assertInstanceOf(ObservedExecutor::class, $executor);
        self::assertSame('actor:passkey:YkS3', $executor->principal?->id, 'the passkey that called the turn, not the process');
        self::assertTrue($executor->principal?->verified);
        self::assertSame('web', $executor->source);

        $context->setValue($ops, null);
        $terminal = $build->invoke($ops, new ToolRegistry(new NullLogger()), null, null, null);
        $executor = (new \ReflectionProperty(ConsentBridge::class, 'executor'))->getValue($terminal);
        self::assertInstanceOf(ObservedExecutor::class, $executor);
        self::assertSame(ObservedExecutor::TERMINAL, $executor->source, 'the control: a terminal turn is still the terminal');
    }
}
