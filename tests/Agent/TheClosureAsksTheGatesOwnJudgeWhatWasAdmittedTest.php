<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\HouseExecutedWork;
use PHPUnit\Framework\TestCase;

/**
 * WHAT WAS ADMITTED IS READ FROM THE JUDGE THE GATE ASKS (greenhouse decisions/0599, question 4; decisions/0590).
 *
 * The closure of a session of work counts an act only when a person's admission covers it. It does not keep a second
 * opinion of what that is: on a house with a real ledger and a capability built in it, the reading the closure is
 * handed says exactly what the gate would.
 */
final class TheClosureAsksTheGatesOwnJudgeWhatWasAdmittedTest extends TestCase
{
    use BuiltHouse;

    public function testItSaysWhatAPersonAdmittedForEachSeat(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $admissions = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel));
        $group = $admissions->group('Prestamos', 'herramientas:write');
        self::assertNotNull($group);
        self::assertTrue($this->ledger($root)->admit(self::SEAT, 'Prestamos', 'herramientas:write', $group['verbs'], 'key:' . self::HUMAN));

        $admitted = HouseExecutedWork::admittedBy(CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel)));

        self::assertTrue($admitted('herramientas.prestar', 'key:' . self::SEAT), 'a verb under the scope a person admitted for this seat');
        self::assertTrue($admitted('herramientas_prestar', 'key:' . self::SEAT), 'in whatever spelling a surface gives it');
        self::assertFalse($admitted('herramientas.listar', 'key:' . self::SEAT), 'reading is another scope, and nobody admitted it');
        self::assertFalse($admitted('herramientas.prestar', 'key:' . self::OTHER_SEAT), 'an admission is of ONE seat');
        self::assertFalse($admitted('herramientas.prestar', 'key:' . self::HUMAN), 'the person who admits is no seat: nothing a person approved bounds that work');
        self::assertFalse($admitted('herramientas.prestar', 'somebody@a-terminal'));
        self::assertFalse($admitted('herramientas.prestar', null), 'asked with no principal it only says it IS a built verb');
        self::assertNull($admitted('sandbox.promote', 'key:' . self::SEAT), 'an operation of the house is no verb of a built capability');
    }
}
