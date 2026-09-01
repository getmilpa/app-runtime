<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\IntentAdmissibility;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use PHPUnit\Framework\TestCase;

/**
 * The tier table of decisions/0184, pinned axis by axis — the policy's ruling on what authority a
 * confirmed intent buys, judged from the EffectProfile alone.
 *
 * Intent answers «is this what you wanted?»; consent answers «do you authorize this effect?». The
 * table below is what separates the profiles where the first answer is admissible evidence for the
 * second from the profiles where it never is — and every `Unknown` sits with the worst of its axis,
 * because not knowing is never a reason to ask less (GOV-05).
 */
final class IntentAdmissibilityTest extends TestCase
{
    /** A mid-tier profile: persistent, as-user, nothing leaves — decisions/0184's second row. */
    private function midTier(): EffectProfile
    {
        return new EffectProfile(
            Mutation::Persistent,
            Externality::None,
            Reversibility::Compensatable,
            Authority::WriteAsUser,
            subject: Subject::Data,
        );
    }

    // ── THE TIER TABLE ──────────────────────────────────────────────────────────────────────────

    public function testAnUnclassifiedOperationIsNeverAdmissible(): void
    {
        self::assertSame(IntentAdmissibility::NEVER, IntentAdmissibility::tier(null));
        self::assertSame(
            IntentAdmissibility::NEVER,
            IntentAdmissibility::tier(EffectProfile::unclassified()),
            'every axis unknown carries the ceiling of every axis: never admissible',
        );
    }

    public function testAReadWithNoEgressIsSufficient(): void
    {
        $read = new EffectProfile(
            Mutation::None,
            Externality::None,
            Reversibility::Compensatable,
            Authority::Read,
            subject: Subject::None,
        );

        self::assertSame(IntentAdmissibility::SUFFICIENT, IntentAdmissibility::tier($read));
    }

    public function testAnEphemeralEffectWithNoEgressIsSufficient(): void
    {
        $ephemeral = new EffectProfile(
            Mutation::Ephemeral,
            Externality::None,
            Reversibility::Compensatable,
            Authority::WriteAsUser,
            subject: Subject::Data,
        );

        self::assertSame(IntentAdmissibility::SUFFICIENT, IntentAdmissibility::tier($ephemeral));
    }

    public function testPersistentWriteAsUserWithNoEgressIsExactScope(): void
    {
        self::assertSame(IntentAdmissibility::EXACT_SCOPE, IntentAdmissibility::tier($this->midTier()));
    }

    public function testPrivilegedAuthorityIsNeverAdmissibleEvenWithNoEgress(): void
    {
        $privileged = new EffectProfile(
            Mutation::Persistent,
            Externality::None,
            Reversibility::Compensatable,
            Authority::Privileged,
            subject: Subject::Executable,
        );

        self::assertSame(
            IntentAdmissibility::NEVER,
            IntentAdmissibility::tier($privileged),
            'the plugins.register shape of evidence/0444: for this tier, intent does not equal consent',
        );
    }

    public function testAnyEgressPastThePerimeterIsNeverAdmissible(): void
    {
        foreach ([Externality::SamePrincipal, Externality::ThirdParty, Externality::Public, Externality::Unknown] as $egress) {
            $profile = new EffectProfile(
                Mutation::Persistent,
                $egress,
                Reversibility::Compensatable,
                Authority::WriteAsUser,
                subject: Subject::Data,
            );

            self::assertSame(
                IntentAdmissibility::NEVER,
                IntentAdmissibility::tier($profile),
                "egress «{$egress->value}» is not «no egress»",
            );
        }
    }

    public function testADestructiveClassChangeIsNeverAdmissible(): void
    {
        $destructive = new EffectProfile(
            Mutation::Persistent,
            Externality::None,
            Reversibility::Irreversible,
            Authority::WriteAsUser,
            subject: Subject::Data,
        );

        self::assertSame(IntentAdmissibility::NEVER, IntentAdmissibility::tier($destructive));
    }

    public function testUnknownAxesFailClosed(): void
    {
        $unknownAuthority = new EffectProfile(
            Mutation::Persistent,
            Externality::None,
            Reversibility::Compensatable,
            Authority::Unknown,
            subject: Subject::Data,
        );
        $unknownMutation = new EffectProfile(
            Mutation::Unknown,
            Externality::None,
            Reversibility::Compensatable,
            Authority::WriteAsUser,
            subject: Subject::Data,
        );
        $unknownReversibility = new EffectProfile(
            Mutation::Persistent,
            Externality::None,
            Reversibility::Unknown,
            Authority::WriteAsUser,
            subject: Subject::Data,
        );

        self::assertSame(IntentAdmissibility::NEVER, IntentAdmissibility::tier($unknownAuthority));
        self::assertSame(IntentAdmissibility::NEVER, IntentAdmissibility::tier($unknownMutation));
        self::assertSame(
            IntentAdmissibility::NEVER,
            IntentAdmissibility::tier($unknownReversibility),
            'an unknown reversibility carries the ceiling of its axis — destructive-class',
        );
    }

    // ── EXACTNESS: the argument arrays must be EQUAL — the value IS the scope ───────────────────

    public function testEqualArgumentsAdmitOrderInsensitively(): void
    {
        self::assertTrue(IntentAdmissibility::admits(
            ['plugin' => 'Tareas', 'fields' => ['b' => 2, 'a' => 1]],
            ['fields' => ['a' => 1, 'b' => 2], 'plugin' => 'Tareas'],
            $this->midTier(),
        ), 'key order is how a caller happened to build the array, not a different act');
    }

    public function testADifferentValueDoesNotAdmit(): void
    {
        self::assertFalse(IntentAdmissibility::admits(
            ['key' => 'debug', 'value' => true],
            ['key' => 'debug', 'value' => false],
            $this->midTier(),
        ), 'config-set(debug=true) confirmed is not config-set(debug=false) authorized');
    }

    public function testAnArgumentTheConfirmationDidNotNameDoesNotAdmit(): void
    {
        self::assertFalse(IntentAdmissibility::admits(
            ['plugin' => 'Tareas'],
            ['plugin' => 'Tareas', 'force' => true],
            $this->midTier(),
        ), 'the call carries an argument nobody was shown');
    }

    public function testAMissingArgumentDoesNotAdmit(): void
    {
        self::assertFalse(IntentAdmissibility::admits(
            ['plugin' => 'Tareas', 'fields' => 'titulo'],
            ['plugin' => 'Tareas'],
            $this->midTier(),
        ));
    }

    public function testExactArgumentsNeverAdmitOnTheHighTier(): void
    {
        $privileged = new EffectProfile(
            Mutation::Persistent,
            Externality::None,
            Reversibility::Compensatable,
            Authority::Privileged,
            subject: Subject::Executable,
        );

        self::assertFalse(
            IntentAdmissibility::admits(['plugin' => 'TareasPlugin'], ['plugin' => 'TareasPlugin'], $privileged),
            'exactness cannot buy what the tier refuses to sell',
        );
    }
}
