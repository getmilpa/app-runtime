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

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\StandingDecision;
use Milpa\Command\Operation;
use Milpa\Command\Standing\StandingCall;
use Milpa\Command\Standing\StandingJudge;
use PHPUnit\Framework\TestCase;

/**
 * The runtime admits no `milpa/command` older than the one that carries the judgment of a standing grant
 * (greenhouse decisions/0615, evidence/1194).
 *
 * The house's judge is written against the contract: what an operation says it acts on (`Operation::$actsOn`), the
 * grant, the call as facts, and `StandingJudge` — all born in milpa/command 0.30.0. The manifest still admitted 0.29,
 * and with 0.29.2 installed static analysis of this package reports 153 errors, every one of them a class, a property
 * or a method of that contract that is not there (the CI of getmilpa/app-runtime#797 before this floor).
 *
 * A test of this package runs beside the command composer installed, which is the newest: it cannot see an old one.
 * So this pins the floor where its reason is written. It does not measure what a house does with an older command.
 *
 * @guards the floor declared for milpa/command is at or above the release that carries the standing judgment
 *
 * @refuses a floor lowered below it while the house's judge is still written against that contract
 *
 * @subject-in milpa/app-runtime
 */
final class TheRuntimeAdmitsNoCommandOlderThanTheOneThatJudgesTest extends TestCase
{
    /** The milpa/command release in which `Operation::$actsOn` and the `Standing` contract were born. */
    private const string BORN_IN = '0.30';

    public function testTheHousesJudgeIsWrittenAgainstTheContractThatRaisesTheFloor(): void
    {
        $call = (new \ReflectionProperty(StandingDecision::class, 'call'))->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $call);
        self::assertSame(StandingCall::class, $call->getName());
        self::assertTrue(method_exists(StandingJudge::class, 'among'));
        self::assertTrue(property_exists(Operation::class, 'actsOn'));
    }

    public function testTheDeclaredFloorCarriesThatContract(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $declared = (string) ($manifest['require']['milpa/command'] ?? '');

        self::assertSame(
            1,
            preg_match('/^>=(\d+\.\d+(?:\.\d+)?) <1\.0$/', $declared, $floor),
            "milpa/command is declared «{$declared}»: a floor and the family's ceiling were expected",
        );
        self::assertTrue(
            version_compare($floor[1], self::BORN_IN, '>='),
            "milpa/command is admitted from {$floor[1]}, and the house's judge is written against a contract born in " . self::BORN_IN,
        );
    }
}
