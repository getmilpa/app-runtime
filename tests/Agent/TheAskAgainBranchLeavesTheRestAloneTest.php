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
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Principal;
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE ASK-AGAIN BRANCH DOES NOT TOUCH (greenhouse decisions/0226, «lo que no cambia»).
 *
 * The branch re-asks only when a consent-demanding operation is admitted BY A RECORDED PERMISSION and the
 * recorded yes was for other arguments. Each guard that keeps it out of everything else has its falsifier
 * here: an operation that does not demand consent, an Allow that the mode bought rather than a permission,
 * and a yes recorded for ANOTHER operation. Dropping any of the three guards turns one of these into a
 * second question the acta says must not be asked.
 */
final class TheAskAgainBranchLeavesTheRestAloneTest extends TestCase
{
    private const SID = 's1';

    /** An operation that does not demand consent (Data + WriteAsUser, below rule S2) never enters the branch. */
    #[Test]
    public function an_operation_that_does_not_demand_consent_is_not_asked_again_for_other_arguments(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start(self::SID, 'x', AutonomyMode::Ask);
        $this->recordAYesFor($store, 'lab:note', ['what' => 'a'], grant: true);

        $gate = $this->gate($store);

        self::assertNull($gate->refuse('lab_note', ['what' => 'b']), 'a yes by name admits every call of an operation that demands no consent');
        self::assertNull($store->load(self::SID)?->question, 'no second question was opened');
    }

    /** An Allow the MODE bought (auto, nothing recorded by name) is not a recorded permission: the branch stays out. */
    #[Test]
    public function an_allow_bought_by_auto_mode_is_not_re_asked_for_other_arguments(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start(self::SID, 'x', AutonomyMode::Auto);
        // The ledger holds a yes for {what:a} — but no permission by name admits this call; auto does.
        $this->recordAYesFor($store, 'lab:burn', ['what' => 'a'], grant: false);

        $gate = $this->gate($store);

        self::assertNull($gate->refuse('lab_burn', ['what' => 'b']), 'auto allowed it; the other door judges the call, this one does not ask');
        self::assertNull($store->load(self::SID)?->question, 'no second question was opened');
    }

    /** A yes recorded for ANOTHER operation is not «a yes for other arguments» of this one. */
    #[Test]
    public function a_yes_for_another_operation_leaves_a_bare_permission_as_it_was(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start(self::SID, 'x', AutonomyMode::Ask);
        // `lab:burn` is allowed by NAME with no fact behind it (a session older than the structured fact)…
        $store->grant(self::SID, 'lab:burn');
        // …and the only recorded fact names a different operation.
        $this->recordAYesFor($store, 'lab:other', ['what' => 'a'], grant: true);

        $gate = $this->gate($store);

        self::assertNull($gate->refuse('lab_burn', ['what' => 'b']), 'a bare permission passes as it always did; the other operation\'s yes is not this one\'s');
        self::assertNull($store->load(self::SID)?->question, 'no second question was opened');
    }

    /**
     * As the gate writes it and `agent:answer` answers it: the question with the fact inside, the yes, the grant by name.
     *
     * @param array<string, mixed> $arguments
     */
    private function recordAYesFor(SessionStore $store, string $operation, array $arguments, bool $grant): void
    {
        $store->ask(self::SID, new PendingQuestion(
            'perm:' . $operation,
            'El agente quiere correr «' . $operation . '». ¿Lo autorizas en esta sesión?',
            ['sí', 'no'],
            json_encode(['operation' => $operation, 'arguments' => $arguments], \JSON_THROW_ON_ERROR),
            null,
            'permission',
        ));
        $store->answer(self::SID, 'perm:' . $operation, 'sí', new Principal('actor:passkey:test', true), 'rod@desktop');
        if ($grant) {
            $store->grant(self::SID, $operation);
        }
    }

    private function gate(SessionStore $store): SessionToolGate
    {
        $session = $store->load(self::SID);
        self::assertNotNull($session);
        $handler = static fn (array $i): array => ['ok' => true];
        $schema = ['type' => 'object', 'properties' => ['what' => ['type' => 'string']], 'required' => []];
        $s2 = new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::Privileged, subject: Subject::Executable);

        return new SessionToolGate($store, $session, [
            new Operation('lab:burn', 'demands consent (S2)', $handler, inputSchema: $schema, mutating: true, effects: $s2),
            new Operation('lab:other', 'demands consent (S2)', $handler, inputSchema: $schema, mutating: true, effects: $s2),
            new Operation('lab:note', 'does not demand consent', $handler, inputSchema: $schema, mutating: true, effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Data)),
        ]);
    }
}
