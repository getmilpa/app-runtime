<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\Command\InvocationContext;
use PHPUnit\Framework\TestCase;

/**
 * WHO MATERIALISES AN EFFECT IS WHOEVER CALLED THE TURN — one derivation for every door.
 *
 * Measured in the browser ceremony (greenhouse evidence/0561): a turn that arrived over HTTP with a passkey
 * session ran `sequence:run` and the ledger wrote `executed_by {principal: cli:rod@…, source:
 * terminal-environment, verified: false}` beside an `authorized_by` that named the passkey. The agent's
 * door wrote the terminal regardless of the door the turn came through; the sequence door already read the
 * invocation. This is that reading, shared.
 */
final class ObservedExecutorFromContextTest extends TestCase
{
    /** A verified actor over the web IS the executor, on the channel that verified it. */
    public function testAWebActorIsTheExecutorVerifiedAsTheDoorVerifiedIt(): void
    {
        $executor = ObservedExecutor::fromContext(InvocationContext::web(actor: 'actor:passkey:YkS3', authorizationId: 'agent'));

        self::assertSame('actor:passkey:YkS3', $executor->principal?->id);
        self::assertTrue($executor->principal?->verified);
        self::assertSame('web', $executor->source);
    }

    /** THE CONTROL: a terminal — or no context at all — is still the operator at the keyboard, unverified. */
    public function testATerminalOrNoContextIsTheOperatorAtTheKeyboard(): void
    {
        foreach ([InvocationContext::cli(), null] as $context) {
            $executor = ObservedExecutor::fromContext($context);

            self::assertSame(ObservedExecutor::TERMINAL, $executor->source);
            self::assertNotNull($executor->principal);
            self::assertFalse($executor->principal->verified, 'the terminal never verifies anybody');
        }
    }

    /** An anonymous invocation over a channel that is not a terminal is UNKNOWN, and says so. */
    public function testAnAnonymousWebInvocationIsUnknownNotTheOperator(): void
    {
        $executor = ObservedExecutor::fromContext(new InvocationContext(actor: null, channel: 'web'));

        self::assertNull($executor->principal, 'nobody is invented for an anonymous call');
        self::assertSame(ObservedExecutor::UNKNOWN, $executor->source);
    }
}
