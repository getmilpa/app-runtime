<?php

/**
 * This file is part of milpa/app-runtime — the Milpa PHP framework's application runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\StackOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Subject;
use Milpa\Console\Consent;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * A FRESH APP CAN SEE THE SERVICES ITS OWN PLUGINS DECLARED, with no panel installed.
 *
 * The reader has shipped since greenhouse decisions/0201 and lived inside `milpa/admin`, which is
 * opt-in — so the declaration was written and unread on the day-one path. Rod's call was «complete the
 * saying, not the running», so this operation says and starts nothing (greenhouse decisions/0282).
 */
final class TheStackIsReadableWithoutThePanelTest extends TestCase
{
    /** The operation exists, is named `stack`, and offers no input to get wrong. */
    public function testItIsOneReadOperationNamedStack(): void
    {
        $operations = (new StackOperations(new DIContainer()))->operations();

        self::assertCount(1, $operations);
        self::assertSame('stack', $operations[0]->name);
        self::assertFalse($operations[0]->mutating, 'reading what was declared is not running it');
        self::assertSame([], $operations[0]->inputSchema['properties'] ?? null, 'no probe flag: the loopback connect is 250 ms on a refused port, and «is my stack up» is the question');
    }

    /**
     * 🚨 THE CEILING IS `SamePrincipal` AND NOT `None`, and the difference is the honest one.
     *
     * A TCP connect DOES leave the process boundary, and `Externality::None` is documented as «nothing
     * leaves». What it reaches is a service THIS app declared, on this host — systems the same
     * principal already controls. `None` would be the honest-looking lie; `ThirdParty` would be the
     * over-declaration that makes a ceiling useless.
     */
    public function testTheCeilingSaysItReachesItsOwnLoopbackAndNothingElse(): void
    {
        $effects = (new StackOperations(new DIContainer()))->operations()[0]->effects;

        self::assertNotNull($effects);
        self::assertSame(Mutation::None, $effects->mutation);
        self::assertSame(Externality::SamePrincipal, $effects->externality);
        self::assertSame(Authority::Read, $effects->authority);
        self::assertSame(Subject::None, $effects->subject);
    }

    /**
     * It demands no consent, so the framework's boot guard publishes it without a scope.
     *
     * Asked of {@see Consent}, which is the authority on that question — not re-derived from the axes
     * here, because a test that re-derives a decision another component owns will drift from it exactly
     * like the guard did (greenhouse decisions/0279).
     */
    public function testItDemandsNoConsentBecauseItSpendsNoAuthority(): void
    {
        if (!class_exists(Consent::class)) {
            self::markTestSkipped('milpa/console is not installed');
        }

        self::assertFalse(Consent::demanded((new StackOperations(new DIContainer()))->operations()[0]));
    }

    /**
     * WITH NO KERNEL IT SAYS «I CANNOT SEE», NOT «NOTHING IS DECLARED».
     *
     * The two are different answers and only one of them is true here: an empty list under
     * `kernel: false` would tell a person their plugins declare nothing, which is the same class of
     * lie as a form that reports success and changes nothing (greenhouse decisions/0280).
     */
    public function testWithNoKernelItSaysItCannotSeeRatherThanNothingIsDeclared(): void
    {
        $operation = (new StackOperations(new DIContainer()))->operations()[0];
        $report = ($operation->handler)([]);

        self::assertTrue($report['ok']);
        self::assertFalse($report['kernel'], 'no booted kernel was in the container');
        self::assertSame([], $report['services']);
    }
}
