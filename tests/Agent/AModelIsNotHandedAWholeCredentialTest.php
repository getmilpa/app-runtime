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

use Milpa\AppRuntime\Agent\WhoDecided;
use PHPUnit\Framework\TestCase;

/**
 * WHAT A MODEL READS DOES NOT CARRY A PERSON'S WHOLE CREDENTIAL (greenhouse decisions/0609, decided apart from the
 * frontier; measured in evidence/1175 §6).
 *
 * When a person grants or admits, the house tells the seat's session so with a turn of its own — «[house] passkey:…
 * granted this seat the scope…». That turn is part of the conversation: it carried the WHOLE identifier of her
 * passkey, and it travelled to the model in 4 of the 6 calls of a real session. The model needs to know that a person
 * decided, and to tell two deciders apart; it does not need the credential. The ledger keeps the whole attribution,
 * in the fact of the grant.
 *
 * @guards the kind and the first eight characters, marked as cut; never more than half of an identifier, however short
 *
 * @refuses a whole passkey id or a whole key fingerprint in a turn of the house; a principal of an unknown kind said
 *          as it came
 *
 * @subject-in milpa/app-runtime
 */
final class AModelIsNotHandedAWholeCredentialTest extends TestCase
{
    public function testAPasskeyIsNamedByItsKindAndItsFirstEightCharacters(): void
    {
        self::assertSame('passkey:QM1LEWEf…', WhoDecided::said('passkey:QM1LEWEfsoWiMmAbCdEf0123456789-_'));
    }

    public function testAKeyIsNamedTheSameWay(): void
    {
        self::assertSame('key:BBBB2222…', WhoDecided::said('key:BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666'));
    }

    public function testAShortIdentifierIsNeverSaidWhole(): void
    {
        self::assertSame('passkey:QM1LEWEf…', WhoDecided::said('passkey:QM1LEWEfsoWiMmAb'), 'sixteen characters: eight, half of it');
        self::assertSame('passkey:QM1LEWE…', WhoDecided::said('passkey:QM1LEWEfsoWiMm'), 'fourteen: seven — never more than half');
        self::assertSame('passkey:QM1L…', WhoDecided::said('passkey:QM1LEWEfs'), 'nine: four');
        self::assertSame('passkey:QM1L…', WhoDecided::said('passkey:QM1LEWEf'), 'eight: four');
        self::assertSame('passkey:Q…', WhoDecided::said('passkey:QM'));
        self::assertSame('passkey:…', WhoDecided::said('passkey:Q'), 'one character is the whole of it: none is said');
        self::assertSame('passkey:…', WhoDecided::said('passkey:'));
    }

    public function testAPrincipalOfAnotherKindIsNotSaidAsItCame(): void
    {
        foreach (['actor:service:ci-runner-7f3a', 'cli:rod@host', 'someone', ''] as $principal) {
            self::assertSame('a person', WhoDecided::said($principal), $principal);
        }
    }

    public function testWhatIsSaidNeverHoldsTheWholeIdentifier(): void
    {
        foreach (['passkey:QM1LEWEfsoWiMm', 'key:BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666', 'passkey:ab', 'key:0123456789'] as $principal) {
            $identifier = substr($principal, (int) strpos($principal, ':') + 1);
            self::assertStringNotContainsString($identifier, WhoDecided::said($principal), $principal);
        }
    }
}
