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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\ResidentSeat;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The question says what a yes does: the house runs the call (greenhouse decisions/0600, the fourth decision).
 *
 * Since the house takes up the call a person said yes to, «the agent will run it» stopped being what happens. So the
 * question says it, to the person who answers and to the session that reads its own question: «If you say yes, the
 * house runs it.» It says so only where it is true — in a session a seat runs, the one whose next leg the house
 * opens with the call. A session a person drives with their own key is not taken up, and its question stays as it
 * was; so does what asks for a signature, which is not answered but signed.
 *
 * @guards the two questions the house asks about a call — the target the request does not name, and the permission
 *         the mode asks for — saying what the yes does, in a seat's session
 *
 * @refuses saying it in a session no seat runs; in a house the gate cannot read; of a key that is not a seat
 *
 * @subject-in milpa/app-runtime
 */
final class TheQuestionSaysTheHouseRunsWhatAYesNamesTest extends TestCase
{
    private const HUMAN = 'BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666';

    private const SEAT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';

    private const SAYS = ' If you say yes, the house runs it.';

    private string $root;

    private SessionStore $sessions;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-yes-says-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/identity', 0o777, true);
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))
            ->record(new IdentityEnrolled(self::SEAT, [...ResidentSeat::SCOPES], 'key:' . self::HUMAN));
        $this->sessions = new SessionStore(new InMemoryEventStore());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheQuestionAboutATargetSaysItInASeatsSession(): void
    {
        $this->sessions->start('bv', 'Tidy the house.', AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, true));

        $this->gate('bv')->refuse('things_remove', ['name' => 'Old']);

        self::assertSame('The request does not name «Old». Confirm things:remove on «Old»?' . self::SAYS, $this->asked('bv'));
    }

    public function testTheQuestionOfPermissionSaysItInASeatsSession(): void
    {
        $this->sessions->start('bv', 'Remove the thing named Old.', AutonomyMode::Ask, by: new Principal('key:' . self::SEAT, true));

        $this->gate('bv')->refuse('things_remove', ['name' => 'Old']);

        self::assertSame('The agent wants to run «things:remove». Do you allow it in this session?' . self::SAYS, $this->asked('bv'));
    }

    public function testASessionNoSeatRunsIsAskedAsBefore(): void
    {
        $this->sessions->start('own', 'Tidy the house.', AutonomyMode::Auto, by: new Principal('key:' . self::HUMAN, true));
        $this->sessions->start('nobody', 'Tidy the house.', AutonomyMode::Auto);
        $this->sessions->start('ask', 'Remove the thing named Old.', AutonomyMode::Ask, by: new Principal('key:' . self::HUMAN, true));

        $this->gate('own')->refuse('things_remove', ['name' => 'Old']);
        $this->gate('nobody')->refuse('things_remove', ['name' => 'Old']);
        $this->gate('ask')->refuse('things_remove', ['name' => 'Old']);

        self::assertSame('The request does not name «Old». Confirm things:remove on «Old»?', $this->asked('own'), 'a person\'s own session: the house takes nothing up there');
        self::assertSame('The request does not name «Old». Confirm things:remove on «Old»?', $this->asked('nobody'));
        self::assertSame('The agent wants to run «things:remove». Do you allow it in this session?', $this->asked('ask'));
    }

    public function testAnUnprovenClaimToBeTheSeatIsNotASeatsSession(): void
    {
        $this->sessions->start('bv', 'Tidy the house.', AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, false));

        $this->gate('bv')->refuse('things_remove', ['name' => 'Old']);

        self::assertSame('The request does not name «Old». Confirm things:remove on «Old»?', $this->asked('bv'));
    }

    public function testAGateWithNoHouseToReadAsksAsBefore(): void
    {
        $this->sessions->start('bv', 'Tidy the house.', AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, true));

        $this->gate('bv', house: false)->refuse('things_remove', ['name' => 'Old']);

        self::assertSame('The request does not name «Old». Confirm things:remove on «Old»?', $this->asked('bv'));
    }

    private function gate(string $session, bool $house = true): SessionToolGate
    {
        $loaded = $this->sessions->load($session);
        self::assertNotNull($loaded);
        $remove = new Operation(
            'things:remove',
            'Removes a thing.',
            static fn (): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            mutating: true,
            namedTarget: 'name',
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser),
        );

        return new SessionToolGate($this->sessions, $loaded, [$remove], petition: 'continue', houseRoot: $house ? $this->root : null);
    }

    private function asked(string $session): ?string
    {
        return $this->sessions->load($session)?->question?->question;
    }
}
