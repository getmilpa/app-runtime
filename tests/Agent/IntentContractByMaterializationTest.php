<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent
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
 * D-05 (greenhouse decisions/0187): the intent contract asks about the target unless the op both is
 * non-grave AND CREATES its named target.
 *
 * Two questions were conflated, and only one is a human's: «you did not name this» vs. «you did not
 * authorise the necessary component». WHICH class realises the plan (a materialising op) is the
 * model's interpretive domain; WHICH existing plugin to disable, or note to purge (a selecting op),
 * is target selection that still returns to the human — Q-P19-K, the measured defect that created
 * this contract. Run in auto mode, which exempts permission, so what remains is the intent contract
 * alone. Fail-closed: the flag defaults false, so an op must DECLARE it creates its target to relax.
 */
final class IntentContractByMaterializationTest extends TestCase
{
    /** THE FIX: a materialising, non-grave op does not stop to ask which class realises the plan. */
    public function testAMaterializingMidTierOpDoesNotAskAboutItsTarget(): void
    {
        $gate = $this->gate($this->buildOp('TareaService', createsTarget: true, tier: 'mid'), 'Build the Tareas app.');

        self::assertNull(
            $gate->refuse('implement', ['class' => 'TareaService']),
            'a class the plan implies is the model interpretive domain — no target question',
        );
    }

    /** Q-P19-K PRESERVED: a SELECTING op still names its target, even reversible and non-grave. */
    public function testASelectingMidTierOpStillAsksAboutItsTarget(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'Turn off the old plugin.', AutonomyMode::Auto);
        $gate = new SessionToolGate($store, $store->load('s1'), [$this->buildOp('MailPlugin', createsTarget: false, tier: 'mid')], petition: 'Turn off the old plugin.');

        self::assertNotNull($gate->refuse('toggle', ['class' => 'MailPlugin']));
        self::assertSame('target_not_named', $store->load('s1')?->question?->reason, 'selecting an existing target still returns it to the human');
    }

    /** THE GRAVITY OVERRIDE: a materialising op that is GRAVE (destructive) still names its target. */
    public function testAGraveMaterializingOpStillAsksAboutItsTarget(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'Redo the accounts.', AutonomyMode::Auto);
        $gate = new SessionToolGate($store, $store->load('s1'), [$this->buildOp('Ledger', createsTarget: true, tier: 'grave')], petition: 'Redo the accounts.');

        self::assertNotNull($gate->refuse('rebuild', ['class' => 'Ledger']));
        self::assertSame('target_not_named', $store->load('s1')?->question?->reason, 'the NEVER tier keeps the intent question regardless of the flag');
    }

    private function gate(Operation $op, string $petition): SessionToolGate
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', $petition, AutonomyMode::Auto);

        return new SessionToolGate($store, $store->load('s1'), [$op], petition: $petition);
    }

    private function buildOp(string $target, bool $createsTarget, string $tier): Operation
    {
        // 'mid' → EXACT_SCOPE (reversible, as-user); 'grave' → NEVER (Irreversible).
        $reversibility = $tier === 'grave' ? Reversibility::Irreversible : Reversibility::Compensatable;

        return new Operation(
            $tier === 'grave' ? 'rebuild' : ($createsTarget ? 'implement' : 'toggle'),
            'A build/toggle op',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
            namedTarget: 'class',
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                $reversibility,
                Authority::WriteAsUser,
                subject: Subject::Executable,
            ),
            createsNamedTarget: $createsTarget,
        );
    }
}
