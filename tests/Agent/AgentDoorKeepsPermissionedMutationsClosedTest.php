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
 * A model's call to a mutation typed by `permission` stays refused at the agent's door (greenhouse decisions/0544 §2).
 *
 * MCP and a finite terminal caller now judge an operation's permission with the host's resolver (decisions/0545), and
 * the house's boundary admits what that judge admits. The agent's tools are projected the same way, so without this
 * door a model whose principal holds the permission would run the mutation — a path Rod keeps closed until its own
 * slice. The door refuses it by the contract (a mutation typed by permission), whatever the session granted; a read
 * typed by permission, and a mutation typed by scopes, are judged as before.
 */
final class AgentDoorKeepsPermissionedMutationsClosedTest extends TestCase
{
    private static function rows(): EffectProfile
    {
        return new EffectProfile(Mutation::Persistent, Externality::SamePrincipal, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data);
    }

    /** @param list<Operation> $operations */
    private static function door(array $operations, string $granted): SessionToolGate
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Auto);
        $store->grant('s1', $granted);
        $session = $store->load('s1');
        self::assertNotNull($session);

        return new SessionToolGate($store, $session, $operations);
    }

    public function testAModelsCallToAPermissionedMutationIsRefusedEvenWhenGranted(): void
    {
        $push = new Operation('sync_push', '', static fn (): null => null, mutating: true, permission: 'attendance:write', effects: self::rows());

        self::assertSame(
            "«sync_push» is a mutation typed by the permission «attendance:write». A model's call to it stays refused at the "
            . "agent's door until its own slice (greenhouse decisions/0544); MCP and the terminal judge it. Nothing ran.",
            self::door([$push], 'sync_push')->refuse('sync_push', []),
        );
    }

    public function testAPermissionedReadAndAScopedMutationAreJudgedAsBefore(): void
    {
        $read = new Operation('grades_read', '', static fn (): null => null, permission: 'school.grades:read', effects: EffectProfile::readOnly());
        $write = new Operation('notes_write', '', static fn (): null => null, mutating: true, scopes: ['notes:write'], effects: self::rows());

        self::assertNull(self::door([$read, $write], 'grades_read')->refuse('grades_read', []));
        self::assertNull(self::door([$read, $write], 'notes_write')->refuse('notes_write', []));
    }
}
