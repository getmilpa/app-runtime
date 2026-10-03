<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\BroadcastingEventStore;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The house's closure verdict reaches the surface that is watching the session (greenhouse decisions/0563).
 *
 * `session.closure_derived` is the house's own fact, written outside `Milpa\Agent\SessionEvent` on purpose. The
 * bridge asked milpa/agent's projector to translate it, the projector answered «a type I do not know», and
 * the verdict was stored and never pushed: measured in evidence/1095, a window open on the session received
 * 1,209 pushes during the run and showed «Verified by the house» only after a reload.
 */
final class TheHouseVerdictReachesTheSurfaceTest extends TestCase
{
    public function testAVerdictRecordedThroughTheBridgeIsPushedToTheSessionsTopic(): void
    {
        $spy = new SurfaceSpy();
        $events = new BroadcastingEventStore(new InMemoryEventStore(), $spy);

        ClosureVerdict::record($events, 's1', [
            'verified' => true,
            'reasons' => [],
            'scope' => 'recorded_work_and_house_observation',
            'derivedFrom' => ['subject' => '/blog', 'seq' => 290],
        ]);

        self::assertSame(['milpa/sessions/s1'], $spy->topics);
        self::assertSame([[
            'session' => 's1',
            'kind' => 'closure',
            'at' => 1,
            'closure' => ['verified' => true, 'reasons' => [], 'scope' => 'recorded_work_and_house_observation'],
        ]], $spy->payloads);
    }

    public function testAVerdictThatDidNotVerifyCarriesItsReasons(): void
    {
        $spy = new SurfaceSpy();
        $events = new BroadcastingEventStore(new InMemoryEventStore(), $spy);

        ClosureVerdict::record($events, 's1', [
            'verified' => false,
            'reasons' => ['todo «t1» is still open', ''],
            'scope' => 'recorded_work',
        ]);

        self::assertFalse($spy->payloads[0]['closure']['verified']);
        self::assertSame(['todo «t1» is still open'], $spy->payloads[0]['closure']['reasons']);
    }

    /** Only `true` verifies: a payload that says anything else is pushed as not verified, never as a badge. */
    public function testOnlyALiteralTrueIsPushedAsVerified(): void
    {
        $spy = new SurfaceSpy();
        $events = new BroadcastingEventStore(new InMemoryEventStore(), $spy);

        $events->append(new Event(SessionStore::PREFIX . 's1', ClosureVerdict::EVENT, ['verified' => 'yes', 'reasons' => 'none', 'scope' => 7], 1));

        self::assertSame(['verified' => false, 'reasons' => [], 'scope' => ''], $spy->payloads[0]['closure']);
    }

    /** The verdict is stored first and stored whole: what the surface gets is a view, the stream keeps the fact. */
    public function testTheStreamKeepsTheWholeFact(): void
    {
        $inner = new InMemoryEventStore();
        $events = new BroadcastingEventStore($inner, new SurfaceSpy());
        $closure = ['verified' => true, 'reasons' => [], 'scope' => 'house_observation', 'derivedFrom' => ['subject' => '/blog']];

        ClosureVerdict::record($events, 's1', $closure);

        $stored = $inner->replay(SessionStore::PREFIX . 's1');
        self::assertCount(1, $stored);
        self::assertSame($closure, $stored[0]->payload);
    }

    /** Control: a type neither milpa/agent nor the house paints is still not pushed — the bridge guesses nothing. */
    public function testAnUnknownSessionFactIsStillNotPushed(): void
    {
        $spy = new SurfaceSpy();
        $events = new BroadcastingEventStore(new InMemoryEventStore(), $spy);

        $events->append(new Event(SessionStore::PREFIX . 's1', 'session.something_new', ['verified' => true], 1));
        $events->append(new Event(SessionStore::PREFIX . 's1', 'session.debt_signaled', ['kind' => 'framework_gap'], 2));

        self::assertSame([], $spy->payloads);
    }

    /** Control: a verdict in a stream that is not a session's has no session topic to go to. */
    public function testAVerdictOutsideASessionStreamIsNotPushed(): void
    {
        $spy = new SurfaceSpy();
        $events = new BroadcastingEventStore(new InMemoryEventStore(), $spy);

        $events->append(new Event('governance-abc', ClosureVerdict::EVENT, ['verified' => true], 1));

        self::assertSame([], $spy->topics);
    }
}

/** Keeps what was pushed, in order. */
final class SurfaceSpy implements SurfaceBroadcaster
{
    /** @var list<string> */
    public array $topics = [];

    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function broadcast(string $topic, array $payload): void
    {
        $this->topics[] = $topic;
        $this->payloads[] = $payload;
    }
}
