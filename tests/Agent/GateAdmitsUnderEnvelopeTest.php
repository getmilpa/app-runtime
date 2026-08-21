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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The gate judges a call against the grant's ENVELOPE — through the policy, the single judge.
 *
 * The gate already composes every call's effective profile (greenhouse decisions/0058). What this
 * pins is that it HANDS that composition to `SessionPolicy::decide()`, so an enveloped grant
 * (decisions/0067) can admit a call that fits and refuse one that rose — and that the question it
 * writes when it pauses carries the declared ceiling (`base`) and the composed profile, so the human
 * tightens from a fact they can see.
 *
 * The discriminating pair: an envelope EQUAL to the ceiling must ADMIT the call (composed == ceiling
 * <= envelope). If the gate failed to pass the composition, `allows(op, null)` would refuse it — so
 * admission here proves the wiring, not just the absence of a pause.
 */
final class GateAdmitsUnderEnvelopeTest extends TestCase
{
    private InMemoryEventStore $eventos;

    protected function setUp(): void
    {
        $this->eventos = new InMemoryEventStore();
    }

    private function techo(): EffectProfile
    {
        return new EffectProfile(
            Mutation::Persistent,
            Externality::SamePrincipal,
            Reversibility::ManualRecovery,
            Authority::WriteAsUser,
            subject: Subject::Configuration,
        );
    }

    private function operacion(): Operation
    {
        return new Operation(
            'probe',
            'A mutating probe with a declared ceiling',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
            effects: $this->techo(),
        );
    }

    /** @param array<string, mixed>|null $sobre null = plain sí; array = enveloped grant */
    private function compuerta(?array $sobre, bool $otorgar = true): SessionToolGate
    {
        $almacen = new SessionStore($this->eventos);
        $almacen->start('s1', 'x', AutonomyMode::Ask);
        if ($otorgar) {
            $almacen->grant('s1', 'probe', $sobre);
        }
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        return new SessionToolGate($almacen, $sesion, [$this->operacion()]);
    }

    public function testAPlainGrantStillAdmitsTheCall(): void
    {
        self::assertNull($this->compuerta(null)->refuse('probe', []), 'null envelope: unbounded, as today');
    }

    /** The wiring proof: an envelope equal to the ceiling admits ONLY if the gate passed the composition. */
    public function testAnEnvelopeEqualToTheCeilingAdmitsTheCall(): void
    {
        self::assertNull(
            $this->compuerta($this->techo()->toArray())->refuse('probe', []),
            'composed == ceiling <= envelope: admitted — which requires the gate to hand the policy the composition',
        );
    }

    /** The drift negative: a call that composes ABOVE a tightened envelope pauses, where a plain sí would not. */
    public function testACallAboveATightenedEnvelopePauses(): void
    {
        $apretado = $this->techo()->meet(EffectProfile::fromPartial(['reversibility' => 'compensatable']));

        $motivo = $this->compuerta($apretado->toArray())->refuse('probe', []);

        self::assertNotNull($motivo, 'no descent lowered reversibility, so the call composes at manual_recovery > compensatable: not covered, it asks');
    }

    /** When it pauses, the question carries the declared ceiling and the composed profile, structured. */
    public function testThePauseRecordsBaseAndComposedInTheWhy(): void
    {
        $almacen = new SessionStore($this->eventos);
        $almacen->start('s1', 'x', AutonomyMode::Ask);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        $gate = new SessionToolGate($almacen, $sesion, [$this->operacion()]);

        self::assertNotNull($gate->refuse('probe', ['k' => 'v']));

        $pregunta = $almacen->load('s1')?->question;
        self::assertNotNull($pregunta);
        $why = json_decode((string) $pregunta->why, true);
        self::assertIsArray($why);
        self::assertSame('probe', $why['operation'] ?? null);
        self::assertSame(['k' => 'v'], $why['arguments'] ?? null);
        self::assertSame('manual_recovery', $why['base']['reversibility'] ?? null, 'the declared ceiling, so contestar can meet against it');
        self::assertSame('manual_recovery', $why['composed']['reversibility'] ?? null, 'what THIS call composed to, so the human tightens from a shown fact');
    }
}
