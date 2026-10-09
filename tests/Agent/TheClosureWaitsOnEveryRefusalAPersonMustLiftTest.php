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
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\BuiltVerb;
use Milpa\AppRuntime\Agent\GrantedCall;
use Milpa\AppRuntime\Agent\HouseExecutedWork;
use Milpa\AppRuntime\Agent\MissingAdmission;
use Milpa\AppRuntime\Agent\OwnVerbRehearsal;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\Command\Operation;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * THE CLOSURE OF WORK WAITS ON EVERY REFUSAL A PERSON MUST LIFT, AND A GRANT LIFTS THE CALL IT WAS GIVEN FOR
 * (greenhouse decisions/0599, amended — decided by Rod on 2026-10-09).
 *
 * Two findings in what was published, read by t-0104 and confirmed by t-0092 on streams written as the house
 * records one (evidence/1179), built here:
 *
 * 1. A SUSPENDED ADMISSION NEVER HELD THE CLOSURE. A call of a built verb that writes, refused because the seat's
 *    admission is kept and suspended while the capability is back in works (decisions/0590, rule 10), waits on a
 *    person exactly as one nobody ever admitted: a person admits it again. The closure recognised the refusal by two
 *    phrases of the house's sentence and the suspended one says neither — so a session of work could close with a
 *    call of a verb that writes refused and waiting.
 *
 * 2. A GRANT LIFTED ONLY THE REFUSAL IT NAMED. A model retries a refused call, and a leg may meet it again; a
 *    person grants once, citing one of them — the frontier shows the latest. The others, the same call, kept
 *    holding the closure for ever: nobody can cite them any more.
 *
 * @guards a suspended refusal is a reason until a person admits again or the house answers it in a rehearsal; a
 *         grant lifts every refusal of THE SAME CALL — the same tool with the same arguments — that was pending
 *         when it was given
 *
 * @refuses a grant that lifts a refusal of another call, of another session — which is another seat's — or one
 *          recorded after it; a refusal the domain itself made read as one that waits
 */
final class TheClosureWaitsOnEveryRefusalAPersonMustLiftTest extends TestCase
{
    private const OWNER = 'key:AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';

    /** Nobody is admitted to anything: every verb is one of a built capability, and the closure is asked. */
    private static function unadmitted(): \Closure
    {
        return static fn (string $operation, ?string $principal): ?bool => false;
    }

    /**
     * The house's own sentence for each way an admission is missing — never a copy of it: the closure reads what the
     * gate recorded, and a sentence that moves must not leave the closure reading a stale one.
     */
    private static function refusal(string $why): string
    {
        $verb = new BuiltVerb('Prestamos', new Operation(name: 'herramientas.prestar', description: 'Lend a tool.', handler: static fn (array $input): array => ['ok' => true]));

        return (new MissingAdmission($verb, 'herramientas:write', $why, $why === MissingAdmission::IN_WORKS))->sentence();
    }

    public function testACallRefusedBecauseItsAdmissionIsSuspendedHoldsTheClosure(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('w', 'Lend the drill.', AutonomyMode::Auto);
        $suspended = self::refusal(MissingAdmission::IN_WORKS);
        self::assertStringContainsString('is kept and suspended', $suspended, 'the control: this is the sentence of a suspended admission');
        self::assertStringNotContainsString('no person has admitted', $suspended, 'and it says neither of the phrases the closure knew');
        $seq = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], $suspended, false, mutating: true);

        $work = HouseExecutedWork::of($sessions->stream('w'), self::unadmitted());

        self::assertFalse($work['derived']);
        self::assertSame(
            ["a call of «herramientas_prestar» was refused because its admission is suspended while its capability is in works, and nobody has admitted it again (seq {$seq})"],
            $work['reasons'],
        );
    }

    /** What already waited says what it said, byte for byte. */
    public function testEveryRefusalForAnAdmissionAPersonCanGiveHoldsItAndSaysWhich(): void
    {
        foreach ([MissingAdmission::NEVER, MissingAdmission::ADDED, MissingAdmission::CHANGED] as $why) {
            $sessions = new SessionStore(new InMemoryEventStore());
            $sessions->start('w', 'Lend the drill.', AutonomyMode::Auto);
            $seq = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], self::refusal($why), false, mutating: true);

            self::assertSame(
                ["a call of «herramientas_prestar» was refused for lack of an admission, and nobody has admitted it (seq {$seq})"],
                HouseExecutedWork::of($sessions->stream('w'), self::unadmitted())['reasons'],
                $why,
            );
        }
    }

    /**
     * NOT DECIDED, AND PINNED AS IT IS: a call refused because a person WITHDREW the admission (decisions/0590, rule
     * 12) does not hold the closure today. Rod decided the suspended one; this one nobody asked about. It is here so
     * that changing it is a deliberate edit, and so that «every sentence of a missing admission holds» cannot arrive
     * by accident.
     */
    public function testACallRefusedBecauseAPersonWithdrewItsAdmissionIsNotOneTheClosureWaitsOnToday(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('w', 'Lend the drill.', AutonomyMode::Auto);
        $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], self::refusal(MissingAdmission::WITHDRAWN), false, mutating: true);

        self::assertSame([], HouseExecutedWork::of($sessions->stream('w'), self::unadmitted())['reasons']);
    }

    public function testASuspendedRefusalIsLiftedByAPersonAdmittingAgainOrByTheHousesRehearsalAndByNothingElse(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('w', 'Lend the drill.', AutonomyMode::Auto);
        $first = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], self::refusal(MissingAdmission::IN_WORKS), false, mutating: true);
        $second = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 2], self::refusal(MissingAdmission::IN_WORKS), false, mutating: true);
        self::assertCount(2, HouseExecutedWork::of($sessions->stream('w'), self::unadmitted())['reasons']);

        GrantedCall::granted($events, 'w', self::at($sessions, $first), 'herramientas:write', self::OWNER, ['capability' => 'Prestamos']);
        $verb = new BuiltVerb('Prestamos', new Operation(name: 'herramientas.prestar', description: 'Lend a tool.', handler: static fn (array $input): array => ['ok' => true]));
        OwnVerbRehearsal::record($events, 'w', $verb, $second, ['output' => ['ok' => true], 'exit' => 0, 'bounds' => TrialWorkspace::BOUNDS]);

        $work = HouseExecutedWork::of($sessions->stream('w'), self::unadmitted());
        self::assertSame([], $work['reasons']);
        self::assertSame(['calls' => 1, 'of_verbs_that_change_state' => 1, 'applied' => false], $work['rehearsed']);
    }

    /** A refusal the DOMAIN made says what it likes — «suspended», «no person has admitted» — and waits on nobody. */
    public function testARefusalOfTheDomainIsNotOneThatWaitsWhateverItsWords(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('w', 'Lend the drill.', AutonomyMode::Auto);
        $said = (string) json_encode(['ok' => false, 'ran_in_house' => true, 'error' => 'the loan is kept and suspended: no person has admitted it']);
        $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], $said, false, mutating: true);
        // And a verb that answered in its own structure without the house's receipt in it: still not the gate's sentence.
        $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 2], (string) json_encode(['ok' => false, 'error' => 'that loan is kept and suspended']), false, mutating: true);

        self::assertStringNotContainsString('admission', implode(' ', HouseExecutedWork::of($sessions->stream('w'), self::unadmitted())['reasons']));
    }

    public function testAGrantLiftsEveryPendingRefusalOfTheSameCallNotOnlyTheOneItNames(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('w', 'Lend the drill.', AutonomyMode::Auto);
        $first = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1, 'a' => 'Ana'], self::refusal(MissingAdmission::NEVER), false, mutating: true);
        $sessions->recordToolCall('w', 'herramientas_disponibles', [], '[]', true, mutating: false);
        // The same call again — its arguments in another order are the same arguments.
        $retry = $sessions->recordToolCall('w', 'herramientas_prestar', ['a' => 'Ana', 'id' => 1], self::refusal(MissingAdmission::NEVER), false, mutating: true);
        $latest = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1, 'a' => 'Ana'], self::refusal(MissingAdmission::IN_WORKS), false, mutating: true);
        self::assertCount(3, HouseExecutedWork::of($sessions->stream('w'), self::unadmitted())['reasons'], 'the control: three refusals of one call, all waiting');

        // A person grants once, citing the one the frontier shows: the latest.
        GrantedCall::granted($events, 'w', self::at($sessions, $latest), 'herramientas:write', self::OWNER, ['capability' => 'Prestamos']);

        self::assertSame([], HouseExecutedWork::of($sessions->stream('w'), self::unadmitted())['reasons'], "seq {$first} and seq {$retry} were the same call: nobody can cite them any more");
    }

    public function testAGrantDoesNotLiftARefusalOfAnotherCall(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('w', 'Lend the drill and the saw.', AutonomyMode::Auto);
        $drill = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], self::refusal(MissingAdmission::NEVER), false, mutating: true);
        $saw = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 2], self::refusal(MissingAdmission::NEVER), false, mutating: true);
        $back = $sessions->recordToolCall('w', 'herramientas_devolver', ['id' => 1], self::refusal(MissingAdmission::NEVER), false, mutating: true);

        GrantedCall::granted($events, 'w', self::at($sessions, $drill), 'herramientas:write', self::OWNER, ['capability' => 'Prestamos']);

        self::assertSame([
            "a call of «herramientas_prestar» was refused for lack of an admission, and nobody has admitted it (seq {$saw})",
            "a call of «herramientas_devolver» was refused for lack of an admission, and nobody has admitted it (seq {$back})",
        ], HouseExecutedWork::of($sessions->stream('w'), self::unadmitted())['reasons'], 'the same verb with other arguments, and another verb with the same ones, are other calls');
    }

    public function testAGrantDoesNotLiftTheSameCallRefusedAfterIt(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('w', 'Lend the drill.', AutonomyMode::Auto);
        $before = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], self::refusal(MissingAdmission::NEVER), false, mutating: true);
        GrantedCall::granted($events, 'w', self::at($sessions, $before), 'herramientas:write', self::OWNER, ['capability' => 'Prestamos']);
        // The capability went back in works, and the admission that grant gave is suspended: a new refusal, of now.
        $after = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], self::refusal(MissingAdmission::IN_WORKS), false, mutating: true);

        self::assertSame(
            ["a call of «herramientas_prestar» was refused because its admission is suspended while its capability is in works, and nobody has admitted it again (seq {$after})"],
            HouseExecutedWork::of($sessions->stream('w'), self::unadmitted())['reasons'],
            'what was pending when the grant was given is lifted; what was refused later waits on a person again',
        );
    }

    /** A session is one seat's. A grant is a fact of the session it was given in, and lifts nothing in another's. */
    public function testAGrantInOneSeatsSessionLiftsNothingInAnothers(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('ana', 'Lend the drill.', AutonomyMode::Auto);
        $sessions->start('beto', 'Lend the drill.', AutonomyMode::Auto);
        $hers = $sessions->recordToolCall('ana', 'herramientas_prestar', ['id' => 1], self::refusal(MissingAdmission::NEVER), false, mutating: true);
        $his = $sessions->recordToolCall('beto', 'herramientas_prestar', ['id' => 1], self::refusal(MissingAdmission::NEVER), false, mutating: true);

        GrantedCall::granted($events, 'ana', self::at($sessions, $hers, 'ana'), 'herramientas:write', self::OWNER, ['capability' => 'Prestamos']);

        self::assertSame([], HouseExecutedWork::of($sessions->stream('ana'), self::unadmitted())['reasons']);
        self::assertSame(
            ["a call of «herramientas_prestar» was refused for lack of an admission, and nobody has admitted it (seq {$his})"],
            HouseExecutedWork::of($sessions->stream('beto'), self::unadmitted())['reasons'],
            'the same call, by another seat, in its own session: her grant is not his',
        );
    }

    /** A grant that names a seq and says no call — a fact written before grants carried one — lifts what it names. */
    public function testAGrantThatSaysNoCallLiftsOnlyTheRefusalItNames(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('w', 'Lend the drill.', AutonomyMode::Auto);
        $first = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], self::refusal(MissingAdmission::NEVER), false, mutating: true);
        $second = $sessions->recordToolCall('w', 'herramientas_prestar', ['id' => 1], self::refusal(MissingAdmission::NEVER), false, mutating: true);
        $events->append(new Event(SessionStore::PREFIX . 'w', GrantedCall::GRANTED, ['seq' => $second], $events->nextSeq()));

        self::assertSame(
            ["a call of «herramientas_prestar» was refused for lack of an admission, and nobody has admitted it (seq {$first})"],
            HouseExecutedWork::of($sessions->stream('w'), self::unadmitted())['reasons'],
            'a fact that does not say which call cannot be read as saying it',
        );
    }

    /** The recorded call at a seq of a session's stream. */
    private static function at(SessionStore $sessions, int $seq, string $session = 'w'): Event
    {
        foreach ($sessions->stream($session) as $event) {
            if ($event->seq === $seq) {
                return $event;
            }
        }

        self::fail("no event at seq {$seq}");
    }
}
