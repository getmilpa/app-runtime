<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\BroadcastingEventStore;
use Milpa\AppRuntime\Agent\FatalTermination;
use Milpa\AppRuntime\Agent\SeatFrontier;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\FirstEventInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * The line check reads the session's opening, and the leg is armed before its first full read (greenhouse
 * decisions/0517).
 *
 * Measured (evidence/1045 §3): a session of 14 × 1036 (293 MB) died 3/3 inside the line check — `outsiderOf` folded
 * the whole session to learn who opened it — before the leg armed {@see FatalTermination}, and left no
 * `session.run_terminated`. The check needs one event; the leg's first FULL read of the session is where a session
 * too big to read dies, and it must die armed.
 *
 * The spy store stands where the file would die: at the leg's first full read of the session it asks
 * {@see FatalTermination::recordIfFatal()} what a fatal right there would leave — the same call PHP's shutdown makes.
 *
 * @guards the line check never replays the session (owner, enroller's line, outsider); a leg that dies at its first
 *         full read records its termination in its session
 *
 * @refuses a termination written into a session by a caller the line refuses; an armed recorder left behind by a leg
 *          that returned early
 *
 * @subject-in milpa/app-runtime
 */
final class TheLineReadsOnlyTheOpeningTest extends TestCase
{
    private const HUMAN = 'C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';
    private const STRANGER = 'D00D0000111122223333444455556666777788889';
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';
    private const PASSKEY = 'QM1LEWEfsoWiMm';
    private const STRANGER_PASSKEY = 'ZZ9otherCredential';
    private const SESSION = 'camino-blog';
    private const FATAL = ['type' => \E_ERROR, 'message' => 'Allowed memory size of 268435456 bytes exhausted', 'file' => 'FileEventStore.php', 'line' => 107];

    /** @var list<string> */
    private array $dirs = [];

    private string|false $baseUrl = false;

    protected function setUp(): void
    {
        // A local endpoint is a credential: the leg goes past «no one to ask» to its first read of the session.
        $this->baseUrl = getenv('MILPA_AGENT_BASE_URL');
        putenv('MILPA_AGENT_BASE_URL=http://127.0.0.1:9');
    }

    protected function tearDown(): void
    {
        FatalTermination::disarm();
        putenv($this->baseUrl === false ? 'MILPA_AGENT_BASE_URL' : 'MILPA_AGENT_BASE_URL=' . $this->baseUrl);
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testALegThatDiesAtItsFirstFullReadOfTheSessionRecordsItsDeath(): void
    {
        [$c, $spy] = $this->house();

        $this->turnUntilTheFirstRead($c, $this->web(self::PASSKEY));

        self::assertSame(1, $spy->fullReads, 'the leg reached its first full read of the session');
        self::assertTrue($spy->deathRecorded, 'a fatal there is recorded — the leg was armed before it');
        $terminations = $this->terminations($spy);
        self::assertCount(1, $terminations);
        self::assertSame('failed', $terminations[0]->payload['reason']);
        self::assertSame(self::FATAL['message'], $terminations[0]->payload['fatal']['message']);
    }

    public function testTheLineCheckReadsTheOpeningAndNeverTheSession(): void
    {
        [$c, $spy] = $this->house();
        // The passkey of the line that enrolled the seat is judged through the seat frontier, the stranger is refused,
        // the terminal passes: each on the opening alone, and none of them replays the session.
        foreach ([self::PASSKEY, self::STRANGER_PASSKEY] as $credential) {
            $this->turn($c, ['prompt' => 'continue', 'session' => self::SESSION, 'denyEffects' => 'no-such-class'], $this->web($credential));
        }
        $this->turn($c, ['prompt' => 'continue', 'session' => self::SESSION, 'denyEffects' => 'no-such-class'], InvocationContext::cli());

        self::assertSame(0, $spy->fullReads, 'refused (stranger) or past the line (enroller, terminal) — on the opening alone');
        self::assertGreaterThanOrEqual(3, $spy->openings);
    }

    public function testACallerTheLineRefusesGetsNothingWrittenIntoTheSession(): void
    {
        [$c, $spy] = $this->house();
        $before = \count($spy->events());

        $r = $this->turn($c, ['prompt' => 'continue', 'session' => self::SESSION], $this->web(self::STRANGER_PASSKEY));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('you do not answer for session', (string) $r['error']);
        self::assertFalse(FatalTermination::recordIfFatal(self::FATAL), 'nothing armed: a fatal now writes nothing');
        self::assertCount($before, $spy->events(), 'not one event, and no termination');
        self::assertSame(0, $spy->fullReads);
    }

    /**
     * Armed only once the line passed: a caller it refuses must not get a termination written into the session even
     * when the process dies WHILE the line is being judged.
     */
    public function testAProcessThatDiesWhileTheLineJudgesAnOutsiderWritesNothing(): void
    {
        [$c, $spy] = $this->house();
        $before = \count($spy->events());
        $spy->dieInTheJudgment = true;

        $this->turnUntilTheFirstRead($c, $this->web(self::STRANGER_PASSKEY));

        self::assertFalse($spy->deathRecorded, 'a fatal while judging a stranger records nothing');
        self::assertCount($before, $spy->events());
    }

    public function testALegThatReturnsEarlyLeavesNothingArmed(): void
    {
        [$c, $spy] = $this->house();
        putenv('MILPA_AGENT_BASE_URL');

        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY'] as $key) {
            if (getenv($key) !== false) {
                self::markTestSkipped("{$key} is set: the leg would find someone to ask");
            }
        }
        $r = $this->turn($c, ['prompt' => 'continue', 'session' => self::SESSION], $this->web(self::PASSKEY));

        self::assertFalse($r['ok'], 'no one to ask: the leg returns after the line passed and armed it');
        self::assertFalse(FatalTermination::recordIfFatal(self::FATAL), 'the leg disarmed on its way out');
        self::assertSame([], $this->terminations($spy));
    }

    public function testTheSeatOfASessionIsReadFromItsOpening(): void
    {
        [$c, $spy, $sessions] = $this->house();
        $kernel = $c->get(Kernel::class);
        self::assertInstanceOf(Kernel::class, $kernel);

        $frontier = SeatFrontier::forRoot($kernel->root(), $sessions);

        self::assertSame(self::SEAT, $frontier->seatOf(self::SESSION));
        self::assertTrue($frontier->answersFor('passkey:' . self::PASSKEY, self::SESSION));
        self::assertFalse($frontier->answersFor('passkey:' . self::STRANGER_PASSKEY, self::SESSION));
        self::assertNull($frontier->seatOf('never-opened'));
        self::assertSame(0, $spy->fullReads);
    }

    public function testTheBridgeKeepsTheCheapReadAndAnswersFromTheStreamWithoutIt(): void
    {
        $broadcaster = new class () implements SurfaceBroadcaster {
            public function broadcast(string $topic, array $data): void
            {
            }
        };
        $spy = new OpeningSpy(new InMemoryEventStore(), self::SESSION);
        (new SessionStore($spy))->start(self::SESSION, 'goal', by: new Principal('key:' . self::SEAT, true));
        $spy->watching = true;
        $plain = new InMemoryEventStore();
        foreach ($spy->replayAll() as $events) {
            foreach ($events as $event) {
                $plain->append($event);
            }
        }
        $withoutCheapRead = new class ($plain) implements EventStoreInterface {
            public function __construct(private InMemoryEventStore $inner)
            {
            }

            public function append(Event $event): void
            {
                $this->inner->append($event);
            }

            public function replay(string $streamId): array
            {
                return $this->inner->replay($streamId);
            }

            public function nextSeq(): int
            {
                return $this->inner->nextSeq();
            }

            public function streams(): array
            {
                return $this->inner->streams();
            }

            public function replayAll(): array
            {
                return $this->inner->replayAll();
            }
        };

        $cheap = (new BroadcastingEventStore($spy, $broadcaster))->first(SessionStore::PREFIX . self::SESSION, 'session.started');
        $fromTheStream = (new BroadcastingEventStore($withoutCheapRead, $broadcaster))->first(SessionStore::PREFIX . self::SESSION, 'session.started');

        self::assertSame(0, $spy->fullReads, 'the bridge asked the wrapped store for the one event');
        self::assertEquals($cheap, $fromTheStream);
        self::assertNull((new BroadcastingEventStore($withoutCheapRead, $broadcaster))->first(SessionStore::PREFIX . self::SESSION, 'session.ended'));
    }

    // --- helpers ---

    /** @return array{0: DIContainer, 1: OpeningSpy, 2: SessionStore} */
    private function house(): array
    {
        $root = sys_get_temp_dir() . '/milpa-line-opening-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        $this->dirs[] = $root;
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $ledger->record(new IdentityEnrolled(self::SEAT, ['agent:run'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::PASSKEY, ['agent:run'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::STRANGER_PASSKEY, ['agent:run'], 'key:' . self::STRANGER));

        $c = new DIContainer();
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        $spy = new OpeningSpy(new InMemoryEventStore(), self::SESSION);
        $c->registerService(EventStoreInterface::class, $spy);
        $sessions = new SessionStore($spy);
        $c->registerService(SessionStore::class, $sessions);

        $sessions->start(self::SESSION, 'Build the blog', by: new Principal('key:' . self::SEAT, true));
        $sessions->setMode(self::SESSION, AutonomyMode::Auto);
        $spy->watching = true;

        return [$c, $spy, $sessions];
    }

    private function turnUntilTheFirstRead(DIContainer $c, InvocationContext $ctx): void
    {
        try {
            $this->turn($c, ['prompt' => 'continue', 'session' => self::SESSION], $ctx);
        } catch (LegDied) {
            // The spy stopped the leg where the file would have killed it.
        }
    }

    /** The context the HTTP projector builds for a signed-in passkey: attributed as `actor:<id>`. */
    private function web(string $credential): InvocationContext
    {
        return InvocationContext::web('actor:passkey:' . $credential, 'agent:run');
    }

    /** @return list<Event> */
    private function terminations(OpeningSpy $spy): array
    {
        return array_values(array_filter($spy->events(), static fn (Event $e): bool => $e->type === 'session.run_terminated'));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function turn(DIContainer $c, array $input, InvocationContext $ctx): array
    {
        foreach ((new AgentOperations($c))->operations() as $op) {
            if ($op->name === 'agent') {
                $handler = $op->handler;
                self::assertIsCallable($handler);
                $authority = new ToolContext(principal: (string) $ctx->actor, channel: $ctx->channel === 'cli' ? 'cli' : 'web', scopes: ['agent:run']);
                /** @var array<string, mixed> */
                return $handler($input, $ctx, $authority);
            }
        }
        self::markTestSkipped('this build offers no agent turn (milpa/ai-gateway is not installed)');
    }
}

/** Where the spy stops a leg: the fatal PHP would have raised at the first full read. */
final class LegDied extends \Error
{
}

/**
 * A store that reads its opening cheaply and counts every full read of one session. At the first full read it
 * asks what a fatal there would leave, and stops the leg like the fatal would.
 */
final class OpeningSpy implements EventStoreInterface, FirstEventInterface
{
    public bool $watching = false;

    public bool $dieInTheJudgment = false;

    public int $fullReads = 0;

    public int $openings = 0;

    public ?bool $deathRecorded = null;

    public function __construct(private readonly InMemoryEventStore $inner, private readonly string $session)
    {
    }

    public function first(string $streamId, string $type): ?Event
    {
        ++$this->openings;
        // Past the first read of the opening (does the session exist?), every read belongs to the judgment.
        if ($this->dieInTheJudgment && $this->openings > 1) {
            $this->dieInTheJudgment = false;
            $this->deathRecorded = FatalTermination::recordIfFatal([
                'type' => \E_ERROR, 'message' => 'Allowed memory size of 268435456 bytes exhausted', 'file' => 'SeatFrontier.php', 'line' => 81,
            ]);

            throw new LegDied('the process died while the line was judged');
        }

        return $this->inner->first($streamId, $type);
    }

    public function append(Event $event): void
    {
        $this->inner->append($event);
    }

    public function replay(string $streamId): array
    {
        if ($this->watching && $streamId === SessionStore::PREFIX . $this->session) {
            ++$this->fullReads;
            if ($this->deathRecorded === null) {
                $this->watching = false;
                $this->deathRecorded = FatalTermination::recordIfFatal([
                    'type' => \E_ERROR, 'message' => 'Allowed memory size of 268435456 bytes exhausted', 'file' => 'FileEventStore.php', 'line' => 107,
                ]);
                $this->watching = true;

                throw new LegDied('the session is too big to read');
            }
        }

        return $this->inner->replay($streamId);
    }

    /**
     * The watched session's events, read past the spy — what the test reads is not the leg's read.
     *
     * @return list<Event>
     */
    public function events(): array
    {
        return $this->inner->replay(SessionStore::PREFIX . $this->session);
    }

    public function nextSeq(): int
    {
        return $this->inner->nextSeq();
    }

    public function streams(): array
    {
        return $this->inner->streams();
    }

    public function replayAll(): array
    {
        return $this->inner->replayAll();
    }
}
