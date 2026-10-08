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

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\GrantedCall;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\ResidentSeat;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * After a person grants the scope a recorded call was refused for, the house re-issues THAT call at the start of the
 * seat's next leg, instead of asking the model to retype it (greenhouse decisions/0577, evidence/1114).
 *
 * Measured on the BV-4 run with the real resident (evidence/1109 §3, call 6): the house told the model «Your call #44
 * … that same call can run now», and the model spent 14,843 tokens and 32 s — 816 of them generated — to
 * «reconstruct what call #44 was»: eight arguments the house held byte for byte.
 *
 * What is resumed is the recorded call and nothing else. It re-enters the same door as the seat, from scratch.
 *
 * @guards the call resumed is the one recorded at the seq the grant was given for, with the arguments recorded
 *         there; only once; only as the first move of the first leg after the grant; only while the grant is fresh;
 *         only a producer the trial confines; only when the seat itself runs the leg; a grant in words is no grant
 *
 * @refuses resuming a call the person did not see; resuming a promotion; resuming under another principal; resuming
 *          twice; resuming after anything else happened
 *
 * @subject-in milpa/app-runtime
 */
final class AGrantResumesTheCallItWasGivenForTest extends TestCase
{
    private const HUMAN = 'BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666';
    private const SEAT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';
    private const STREAM = 'agent-session:bv';
    private const MAKE = ['what' => 'page', 'plugin' => 'Blog', 'name' => 'blog', 'fields' => 'title:string, body:text, published:bool'];
    private const REFUSED = "Missing required permission 'plugins.Blog:write' for plugin 'Blog'.";

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testTheCallTheGrantWasGivenForIsResumedWithItsRecordedArguments(): void
    {
        $call = GrantedCall::toResume(self::stream(), self::at(60));

        self::assertSame(['seq' => 4, 'tool' => 'make', 'arguments' => self::MAKE, 'granted' => 5], $call);
    }

    public function testTheMoveIsThatCallAndNoWords(): void
    {
        $move = GrantedCall::move(['seq' => 4, 'tool' => 'make', 'arguments' => self::MAKE, 'granted' => 5]);

        self::assertSame('assistant', $move['role']);
        self::assertSame('', $move['content']);
        self::assertCount(1, $move['tool_calls']);
        self::assertSame('resumed-4', $move['tool_calls'][0]['id']);
        self::assertSame('make', $move['tool_calls'][0]['function']['name']);
        self::assertSame(self::MAKE, json_decode($move['tool_calls'][0]['function']['arguments'], true));
        self::assertSame('{}', GrantedCall::move(['seq' => 9, 'tool' => 'test', 'arguments' => [], 'granted' => 10])['tool_calls'][0]['function']['arguments']);
    }

    /** @param list<Event> $stream */
    #[DataProvider('notResumed')]
    public function testNothingElseIsResumed(array $stream, int $secondsAfterTheGrant = 60): void
    {
        self::assertNull(GrantedCall::toResume($stream, self::at($secondsAfterTheGrant)));
    }

    /** @return iterable<string, array{0: list<Event>, 1?: int}> */
    public static function notResumed(): iterable
    {
        $notice = self::event(6, 'session.turn', ['role' => 'user', 'content' => '[house] key:' . self::HUMAN . ' granted this seat the scope «plugins.Blog:write». Your call #4 (make plugin=Blog) was refused for lacking it; that same call can run now. Nothing else changed.']);
        $granted = self::granted();

        yield 'a grant in words, with no recorded fact' => [[...self::upToTheRefusal(), $notice, self::event(7, 'session.turn', ['role' => 'user', 'content' => 'continue'])]];
        yield 'no grant at all' => [self::upToTheRefusal()];
        yield 'a grant given for another call' => [[...self::upToTheRefusal(), self::granted(['seq' => 2])]];
        yield 'a grant whose call had other arguments' => [[...self::upToTheRefusal(), self::granted(['arguments_sha256' => ConsentBridge::digest(['what' => 'page', 'plugin' => 'Shop'])])]];
        yield 'a grant whose call was another tool' => [[...self::upToTheRefusal(), self::granted(['tool' => 'implement'])]];
        yield 'a grant recorded before the refusal' => [[self::event(1, 'session.started', []), self::granted([], 2), self::refusal(4)]];
        yield 'another call was made since' => [[...self::upToTheRefusal(), $granted, self::event(6, 'session.tool_called', ['tool' => 'source_read', 'arguments' => ['path' => 'a'], 'result' => '{}', 'ok' => true])]];
        yield 'the same call was refused again since' => [[...self::upToTheRefusal(), $granted, self::refusal(6)]];
        yield 'the model was already asked since' => [[...self::upToTheRefusal(), $granted, self::event(6, 'session.model_called', [])]];
        yield 'it was already resumed' => [[...self::upToTheRefusal(), $granted, self::event(6, GrantedCall::RESUMED, ['seq' => 4, 'granted' => 5])]];
        yield 'the grant is no longer fresh' => [self::stream(), GrantedCall::FRESH_SECONDS + 1];
        yield 'the grant is dated after now' => [self::stream(), -5];
        yield 'the grant carries no date' => [[...self::upToTheRefusal(), new Event(self::STREAM, GrantedCall::GRANTED, self::granted()->payload, 5, null)]];
        yield 'the call that was refused is a promotion' => [[self::event(1, 'session.started', []), self::refusal(4, 'sandbox_promote', ['workspace' => 'w1']), self::granted(['tool' => 'sandbox_promote', 'arguments_sha256' => ConsentBridge::digest(['workspace' => 'w1'])])]];
        yield 'the last call ran' => [[self::event(1, 'session.started', []), self::event(4, 'session.tool_called', ['tool' => 'make', 'arguments' => self::MAKE, 'result' => '{}', 'ok' => true]), $granted]];
        yield 'the refused call records no arguments' => [[self::event(1, 'session.started', []), self::event(4, 'session.tool_called', ['tool' => 'make', 'result' => self::REFUSED, 'ok' => false]), $granted]];
        yield 'an empty session' => [[]];
    }

    public function testTheGrantIsFreshUpToItsLastSecond(): void
    {
        self::assertNotNull(GrantedCall::toResume(self::stream(), self::at(GrantedCall::FRESH_SECONDS)));
        self::assertNotNull(GrantedCall::toResume(self::stream(), self::at(0)));
    }

    public function testEveryProducerTheTrialConfinesCanBeResumedAndNothingElse(): void
    {
        foreach (['make', 'implement', 'edit', 'test'] as $producer) {
            $stream = [self::event(1, 'session.started', []), self::refusal(4, $producer), self::granted(['tool' => $producer])];
            self::assertSame($producer, GrantedCall::toResume($stream, self::at(1))['tool'] ?? null);
        }
        foreach (['sandbox_promote', 'sandbox_undo', 'sandbox_discard', 'plugins_register', 'config_set'] as $other) {
            $stream = [self::event(1, 'session.started', []), self::refusal(4, $other), self::granted(['tool' => $other])];
            self::assertNull(GrantedCall::toResume($stream, self::at(1)), $other . ' lands or decides: it is the model\'s to ask for again');
        }
    }

    public function testTheGrantRecordsTheCallItWasGivenFor(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Build the blog');
        $seq = $sessions->recordToolCall('bv', 'make', self::MAKE, self::REFUSED, false, true);
        $refused = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->seq === $seq))[0];

        GrantedCall::granted($events, 'bv', $refused, 'plugins.Blog:write', 'key:' . self::HUMAN);

        $facts = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === GrantedCall::GRANTED));
        self::assertCount(1, $facts);
        self::assertSame(
            ['seq' => $seq, 'tool' => 'make', 'permission' => 'plugins.Blog:write', 'arguments_sha256' => ConsentBridge::digest(self::MAKE), 'authorized_by' => 'key:' . self::HUMAN],
            $facts[0]->payload,
        );
        self::assertSame($seq, GrantedCall::toResume($sessions->stream('bv'), new \DateTimeImmutable())['seq'] ?? null, 'and that is the call the next leg resumes');
    }

    /**
     * Through the real door of a leg: the house hands the orchestrator the opening move, records that it did, and
     * does it once. The orchestrator of this fixture only remembers what it was handed.
     */
    public function testTheSeatsNextLegOpensWithThatCallAndTheHouseRecordsThatItPlayedIt(): void
    {
        [$operations, $sessions, $loop] = $this->house();

        self::leg($operations, self::seat());

        self::assertSame(GrantedCall::move(['seq' => 4, 'tool' => 'make', 'arguments' => self::MAKE, 'granted' => 0]), $loop->opened);
        $resumed = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === GrantedCall::RESUMED));
        self::assertCount(1, $resumed);
        self::assertSame(4, $resumed[0]->payload['seq']);
        self::assertSame('make', $resumed[0]->payload['tool']);
        self::assertSame(ConsentBridge::digest(self::MAKE), $resumed[0]->payload['arguments_sha256']);
        self::assertSame('key:' . self::SEAT, $resumed[0]->payload['as']);

        $loop->opened = null;
        self::leg($operations, self::seat());
        self::assertNull($loop->opened, 'a second leg resumes nothing: it was played');
    }

    public function testALegSomebodyElseRunsResumesNothing(): void
    {
        [$operations, $sessions, $loop] = $this->house();

        self::leg($operations, new InvocationContext('key:' . self::HUMAN, true));
        self::assertNull($loop->opened, 'the call was the seat\'s: nobody else\'s leg plays it');

        self::leg($operations, new InvocationContext('key:' . self::SEAT, false));
        self::assertNull($loop->opened, 'an unproven claim to be the seat is not the seat');
        self::assertSame([], array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === GrantedCall::RESUMED)));
    }

    public function testAHouseWithoutTrialsResumesNothing(): void
    {
        [$operations, , $loop] = $this->house(trials: false);

        self::leg($operations, self::seat());

        self::assertNull($loop->opened, 'outside a trial the call would land: the model asks for it');
    }

    public function testAnOrchestratorThatCannotOpenIsNotHandedTheMove(): void
    {
        $call = ['seq' => 4, 'tool' => 'make', 'arguments' => self::MAKE, 'granted' => 5];
        $old = new \stdClass();
        $new = new class () {
            /** @var array<string, mixed>|null */
            public ?array $opened = null;

            public function setOpeningMove(?array $assistantMessage): self
            {
                $this->opened = $assistantMessage;

                return $this;
            }
        };

        self::assertFalse(GrantedCall::open($old, $call), 'a gateway from before the opening move: the model re-issues the call, as before');
        self::assertSame([], get_object_vars($old));
        self::assertTrue(GrantedCall::open($new, $call));
        self::assertSame(GrantedCall::move($call), $new->opened);
    }

    /**
     * A fixture house: the seat enrolled, its session with the refused call and the grant recorded for it.
     *
     * @return array{0: ResumeFixtureOperations, 1: SessionStore, 2: OpeningRecorder}
     */
    private function house(bool $trials = true): array
    {
        $root = sys_get_temp_dir() . '/milpa-grant-resumes-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        $this->roots[] = $root;
        (new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))
            ->record(new IdentityEnrolled(self::SEAT, [...ResidentSeat::SCOPES, 'plugins.Blog:write'], 'key:' . self::HUMAN));
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Build the blog this house was founded for: a plugin named Blog that serves GET /blog.', \Milpa\Agent\AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, true));
        $sessions->recordTurn('bv', 'user', 'Build the blog');
        $sessions->recordTurn('bv', 'assistant', 'Scaffolding.');
        $seq = $sessions->recordToolCall('bv', 'make', self::MAKE, self::REFUSED, false, true);
        self::assertSame(4, $seq);
        $refused = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->seq === $seq))[0];
        GrantedCall::granted($events, 'bv', $refused, 'plugins.Blog:write', 'key:' . self::HUMAN);

        $container = new DIContainer();
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(Kernel::class, Kernel::boot(['root' => $root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]));
        $operations = new ResumeFixtureOperations($container);
        // Whether this house confines its producers in trials, said outright: the answer must not depend on what
        // the machine running the test has installed.
        (new \ReflectionProperty(AgentOperations::class, 'trialRouterMemo'))->setValue(
            $operations,
            $trials ? new TrialRouter($root, new TrialRunner(), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php') : null,
        );
        $loop = new OpeningRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class));
        $operations->loop = $loop;

        return [$operations, $sessions, $loop];
    }

    private static function seat(): InvocationContext
    {
        return new InvocationContext('key:' . self::SEAT, true);
    }

    /** @return array<string, mixed> */
    private static function leg(AgentOperations $operations, InvocationContext $by): array
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    return ($operation->handler)(['prompt' => 'continue', 'session' => 'bv'], $by);
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('the agent operation was not offered');
    }

    /** @return list<Event> the session up to the refused `make`, at seq 4 */
    private static function upToTheRefusal(): array
    {
        return [
            self::event(1, 'session.started', ['goal' => 'Build the blog']),
            self::event(2, 'session.tool_called', ['tool' => 'house_context', 'arguments' => [], 'result' => '{}', 'ok' => true]),
            self::event(3, 'session.model_called', []),
            self::refusal(4),
        ];
    }

    /** @return list<Event> the refusal, the grant recorded for it, and the person's `continue` */
    private static function stream(): array
    {
        return [...self::upToTheRefusal(), self::granted(), self::event(6, 'session.turn', ['role' => 'user', 'content' => 'continue'])];
    }

    /** @param array<string, mixed> $arguments */
    private static function refusal(int $seq, string $tool = 'make', array $arguments = self::MAKE): Event
    {
        return self::event($seq, 'session.tool_called', ['tool' => $tool, 'arguments' => $arguments, 'result' => self::REFUSED, 'ok' => false, 'mutating' => true]);
    }

    /** @param array<string, mixed> $changes */
    private static function granted(array $changes = [], int $seq = 5): Event
    {
        return self::event($seq, GrantedCall::GRANTED, $changes + [
            'seq' => 4, 'tool' => 'make', 'permission' => 'plugins.Blog:write',
            'arguments_sha256' => ConsentBridge::digest(self::MAKE), 'authorized_by' => 'key:' . self::HUMAN,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private static function event(int $seq, string $type, array $payload): Event
    {
        return new Event(self::STREAM, $type, $payload, $seq, new \DateTimeImmutable('2026-10-06T12:00:00Z'));
    }

    /** `$seconds` after every event of the fixture stream was recorded. */
    private static function at(int $seconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('2026-10-06T12:00:00Z'))->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }
}

/** A leg whose orchestrator is the test's. */
final class ResumeFixtureOperations extends AgentOperations
{
    public ?AgentOrchestrator $loop = null;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->loop !== null);

        return $this->loop;
    }
}

/** An orchestrator that can open with a move, and only remembers which. */
final class OpeningRecorder extends AgentOrchestrator
{
    /** @var array<string, mixed>|null */
    public ?array $opened = null;

    public function setOpeningMove(?array $assistantMessage): self
    {
        $this->opened = $assistantMessage;

        return $this;
    }

    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        return 'Continued.';
    }
}
