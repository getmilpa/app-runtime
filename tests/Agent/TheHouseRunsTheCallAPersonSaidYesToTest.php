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
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\AnsweredCall;
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
 * What a person decided about a call, the house runs: it does not ask the session to make it again
 * (greenhouse decisions/0600).
 *
 * A person decides about ONE call at three doors. After a frontier grant the house already resumes the call it was
 * given for, if a trial confines it (decisions/0577). After the admission of a built verb it told the session «make
 * that same call again»; and after a yes to a question it had asked about a call, nothing took the call up. The
 * record of the lab's houses: of 375 yeses about a call, the model typed that same call again, argument for
 * argument, 275 times — an inference spent copying what the house held — and 23 times it typed something the yes
 * did not cover.
 *
 * Decided: the narrower what the person decided, the more the house takes up. A YES names a call — the question
 * carries its operation and its arguments — so the session's next leg opens with that call, whatever it is, whether
 * it lands or not. A grant or an admission names a scope, so it resumes its call only if that call does not land by
 * itself: a producer a trial confines, or a built verb, which runs confined to its state. In every case the exact
 * call, once, as the first move, while the act is fresh, in a leg the session's seat runs — and through the same
 * door, judged from scratch.
 *
 * @guards the call a yes was given for opening the seat's next leg, with the arguments the question carried, named
 *         as the house names its tools, whether it lands or not; the house recording that it played it, and after
 *         which answer; the admission of a built verb resuming the call it was given for
 *
 * @refuses a «no»; a question that carries no call; what asks for a signature; an answer nobody proved; an answer
 *          older than the hour, dated after now or with no date; an answer that is no longer the last thing — a
 *          model call, a tool call, a question or a resume since; a leg somebody other than the seat runs; a grant
 *          that admits nothing, over a call that lands
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseRunsTheCallAPersonSaidYesToTest extends TestCase
{
    private const HUMAN = 'BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666';

    private const SEAT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';

    private const STREAM = 'agent-session:bv';

    private const SET = ['key' => 'site.title', 'value' => 'A new title'];

    private const COUNT = ['of' => 'things'];

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testTheCallAYesWasGivenForIsTheOneToTakeUp(): void
    {
        $call = AnsweredCall::toResume(self::stream(), self::at(60));

        self::assertSame(['question' => 'perm:config:set', 'asked' => 4, 'answered' => 5, 'tool' => 'config_set', 'arguments' => self::SET], $call);
    }

    public function testItIsTakenUpWhetherItLandsOrNot(): void
    {
        foreach (['config:set' => 'config_set', 'sandbox:promote' => 'sandbox_promote', 'plugins.register' => 'plugins_register', 'edit' => 'edit'] as $operation => $tool) {
            $call = AnsweredCall::toResume([...self::upToTheModel(), self::asked($operation, self::SET), self::answered('perm:' . $operation)], self::at(60));

            self::assertSame($tool, $call['tool'] ?? null, "«{$operation}» is the call the person said yes to");
            self::assertSame(self::SET, $call['arguments']);
        }
    }

    public function testAYesToAQuestionOfIntentIsTakenUpToo(): void
    {
        $question = self::event(4, 'session.question_asked', ['id' => 'intent-0123456789ab', 'question' => 'The request does not name «X». Confirm edit on «X»?',
            'options' => ['yes', 'no'], 'why' => json_encode(['operation' => 'edit', 'arguments' => ['plugin' => 'Shop', 'class' => 'X']]), 'reason' => 'target_not_named']);

        $call = AnsweredCall::toResume([...self::upToTheModel(), $question, self::answered('intent-0123456789ab')], self::at(60));

        self::assertSame('edit', $call['tool'] ?? null);
        self::assertSame(['plugin' => 'Shop', 'class' => 'X'], $call['arguments']);
    }

    public function testAnOlderClientsSiIsAYes(): void
    {
        self::assertNotNull(AnsweredCall::toResume([...self::upToTheModel(), self::asked(), self::answered(answer: 'sí')], self::at(60)));
    }

    public function testTheMoveIsThatCallAndNoWords(): void
    {
        $move = AnsweredCall::move(['question' => 'perm:config:set', 'asked' => 4, 'answered' => 5, 'tool' => 'config_set', 'arguments' => self::SET]);

        self::assertSame('assistant', $move['role']);
        self::assertSame('', $move['content']);
        self::assertCount(1, $move['tool_calls']);
        self::assertSame('config_set', $move['tool_calls'][0]['function']['name']);
        self::assertSame(self::SET, json_decode($move['tool_calls'][0]['function']['arguments'], true));
        self::assertSame('{}', AnsweredCall::move(['question' => 'q', 'asked' => 1, 'answered' => 2, 'tool' => 'probe', 'arguments' => []])['tool_calls'][0]['function']['arguments']);
    }

    public function testAnOrchestratorThatCannotOpenIsNotHandedTheMove(): void
    {
        $call = ['question' => 'perm:config:set', 'asked' => 4, 'answered' => 5, 'tool' => 'config_set', 'arguments' => self::SET];
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

        self::assertFalse(AnsweredCall::open($old, $call), 'a gateway from before the opening move: the model re-issues the call, as before');
        self::assertSame([], get_object_vars($old));
        self::assertTrue(AnsweredCall::open($new, $call));
        self::assertSame(AnsweredCall::move($call), $new->opened);
        self::assertSame('answered-5', $new->opened['tool_calls'][0]['id'], 'the call says after which answer it was played');
    }

    /** @param list<Event> $stream */
    #[DataProvider('notTakenUp')]
    public function testNothingElseIsTakenUp(array $stream, int $secondsAfterTheAnswer = 60): void
    {
        self::assertNull(AnsweredCall::toResume($stream, self::at($secondsAfterTheAnswer)));
    }

    /** @return iterable<string, array{0: list<Event>, 1?: int}> */
    public static function notTakenUp(): iterable
    {
        $asked = [...self::upToTheModel(), self::asked()];
        $answered = [...$asked, self::answered()];

        yield 'a no' => [[...$asked, self::answered(answer: 'no')]];
        yield 'an answer that is neither' => [[...$asked, self::answered(answer: 'later')]];
        yield 'a question nobody answered' => [$asked];
        yield 'a question that carries no call' => [[...self::upToTheModel(), self::event(4, 'session.question_asked', ['id' => 'ask-1', 'question' => 'Which colour?', 'options' => ['yes', 'no'], 'why' => 'the page needs one', 'reason' => 'other']), self::answered('ask-1')]];
        yield 'a question with no why at all' => [[...self::upToTheModel(), self::event(4, 'session.question_asked', ['id' => 'ask-1', 'question' => 'Go on?', 'options' => ['yes', 'no']]), self::answered('ask-1')]];
        yield 'a why that names an operation and no arguments' => [[...self::upToTheModel(), self::event(4, 'session.question_asked', ['id' => 'perm:config:set', 'why' => json_encode(['operation' => 'config:set']), 'reason' => 'permission']), self::answered()]];
        yield 'a why whose operation is not a name' => [[...self::upToTheModel(), self::event(4, 'session.question_asked', ['id' => 'perm:config:set', 'why' => json_encode(['operation' => ['config:set'], 'arguments' => self::SET]), 'reason' => 'permission']), self::answered()]];
        yield 'what asks for a signature' => [[...self::upToTheModel(), self::event(4, 'session.question_asked', ['id' => 'sign:config:set', 'question' => '«config:set» demands a signature that names this call.', 'options' => [], 'why' => json_encode(['operation' => 'config:set', 'arguments' => self::SET]), 'reason' => 'signature']), self::answered('sign:config:set')]];
        yield 'what asks for a signature, whatever its id' => [[...self::upToTheModel(), self::event(4, 'session.question_asked', ['id' => 'perm:config:set', 'why' => json_encode(['operation' => 'config:set', 'arguments' => self::SET]), 'reason' => 'signature']), self::answered()]];
        yield 'a why whose operation is empty' => [[...self::upToTheModel(), self::event(4, 'session.question_asked', ['id' => 'perm:config:set', 'why' => json_encode(['operation' => '', 'arguments' => self::SET]), 'reason' => 'permission']), self::answered()]];
        yield 'a signature question by its id alone' => [[...self::upToTheModel(), self::event(4, 'session.question_asked', ['id' => 'sign:config:set', 'why' => json_encode(['operation' => 'config:set', 'arguments' => self::SET])]), self::answered('sign:config:set')]];
        yield 'the answer to another question' => [[...$asked, self::answered('perm:plugins.enable')]];
        yield 'an answer that names no question' => [[...self::upToTheModel(), self::event(4, 'session.question_asked', ['why' => json_encode(['operation' => 'config:set', 'arguments' => self::SET]), 'reason' => 'permission']), self::event(5, 'session.question_answered', ['answer' => 'yes', 'by' => ['id' => 'key:' . self::HUMAN, 'verified' => true]])]];
        yield 'an answer that only claims to be proven' => [[...$asked, self::event(5, 'session.question_answered', ['id' => 'perm:config:set', 'answer' => 'yes', 'by' => ['id' => 'key:' . self::HUMAN, 'verified' => 'true']])]];
        yield 'an answer nobody proved' => [[...$asked, self::answered(verified: false)]];
        yield 'an answer with no principal' => [[...$asked, self::event(5, 'session.question_answered', ['id' => 'perm:config:set', 'answer' => 'yes', 'by' => null])]];
        yield 'the model was already asked since' => [[...$answered, self::event(6, 'session.model_called', [])]];
        yield 'another call was made since' => [[...$answered, self::event(6, 'session.tool_called', ['tool' => 'source_read', 'arguments' => ['path' => 'a'], 'result' => '{}', 'ok' => true])]];
        yield 'another question was asked since' => [[...$answered, self::event(6, 'session.question_asked', ['id' => 'ask-2', 'question' => 'And this?'])]];
        yield 'it was already taken up' => [[...$answered, self::event(6, GrantedCall::RESUMED, ['question' => 'perm:config:set', 'answered' => 5])]];
        yield 'the same question, asked again after the answer' => [[...$answered, self::asked(seq: 6)]];
        yield 'the answer is older than the hour' => [self::stream(), AnsweredCall::FRESH_SECONDS + 1];
        yield 'the answer is dated after now' => [self::stream(), -5];
        yield 'the answer carries no date' => [[...$asked, new Event(self::STREAM, 'session.question_answered', self::answered()->payload, 5, null)]];
        yield 'an empty session' => [[]];
    }

    public function testOnlyTheLastAnswerCounts(): void
    {
        $first = ['key' => 'site.title', 'value' => 'The first title'];
        $earlier = [...self::upToTheModel(), self::asked(arguments: $first), self::answered(),
            self::event(6, 'session.model_called', []), self::event(7, 'session.tool_called', ['tool' => 'config_set', 'arguments' => $first, 'result' => '{}', 'ok' => true])];
        $later = [self::event(8, 'session.question_asked', self::asked()->payload), self::event(9, 'session.question_answered', self::answered()->payload)];

        self::assertNull(AnsweredCall::toResume($earlier, self::at(60)), 'the first yes was acted on: the session moved on');
        $call = AnsweredCall::toResume([...$earlier, ...$later], self::at(60));
        self::assertSame(9, $call['answered'] ?? null, 'the yes that is now the last thing is the one taken up');
        self::assertSame(8, $call['asked'], 'with the question it answers, not an older one of the same name');
        self::assertSame(self::SET, $call['arguments'], 'and the arguments THAT question carried');
    }

    public function testAnAnswerIsFreshUpToItsLastSecond(): void
    {
        self::assertNotNull(AnsweredCall::toResume(self::stream(), self::at(AnsweredCall::FRESH_SECONDS)));
        self::assertSame(3600, AnsweredCall::FRESH_SECONDS, 'one hour from the answer, as a grant from its grant');
    }

    public function testWhatTheHouseDoesBetweenTheAnswerAndTheLegIsNotTheSessionMovingOn(): void
    {
        $stream = [
            ...self::stream(),
            self::event(7, 'session.sequence_authorized', ['operation' => 'agent:answer']),
            self::event(8, 'session.compacted', ['summary' => 'Session goal: …']),
            self::event(9, 'session.turn', ['role' => 'user', 'content' => 'continue']),
            self::event(10, 'session.window_composed', ['tokens' => 100]),
        ];

        self::assertSame('config_set', AnsweredCall::toResume($stream, self::at(60))['tool'] ?? null);
    }

    public function testTheSeatsNextLegOpensWithThatCallAndTheHouseRecordsThatItPlayedIt(): void
    {
        [$operations, $sessions, $loop] = $this->house();

        self::leg($operations, self::seat());

        self::assertNotNull($loop->opened, 'the leg opens with the call the person said yes to');
        self::assertSame('config_set', $loop->opened['tool_calls'][0]['function']['name']);
        self::assertSame(self::SET, json_decode($loop->opened['tool_calls'][0]['function']['arguments'], true));
        $resumed = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === GrantedCall::RESUMED));
        self::assertCount(1, $resumed);
        self::assertSame('perm:config:set', $resumed[0]->payload['question']);
        self::assertSame('config_set', $resumed[0]->payload['tool']);
        self::assertSame(ConsentBridge::digest(self::SET), $resumed[0]->payload['arguments_sha256']);
        self::assertSame('key:' . self::SEAT, $resumed[0]->payload['as']);
        self::assertIsInt($resumed[0]->payload['asked']);
        self::assertGreaterThan($resumed[0]->payload['asked'], $resumed[0]->payload['answered'], 'after which answer, to which question');

        $loop->opened = null;
        self::leg($operations, self::seat());
        self::assertNull($loop->opened, 'a second leg takes nothing up: it was played');
    }

    public function testAHouseWithoutTrialsStillRunsWhatAPersonSaidYesTo(): void
    {
        [$operations, , $loop] = $this->house(trials: false);

        self::leg($operations, self::seat());

        self::assertNotNull($loop->opened, 'the yes named this call, landing or not: no trial is asked for');
    }

    public function testALegSomebodyElseRunsTakesNothingUp(): void
    {
        [$operations, $sessions, $loop] = $this->house();

        self::leg($operations, new InvocationContext('key:' . self::HUMAN, true));
        self::assertNull($loop->opened, 'the call was the seat\'s: the leg of whoever answered plays nothing');

        self::leg($operations, new InvocationContext('key:' . self::SEAT, false));
        self::assertNull($loop->opened, 'an unproven claim to be the seat is not the seat');
        self::assertSame([], array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === GrantedCall::RESUMED)));
    }

    public function testANoOpensNothing(): void
    {
        [$operations, , $loop] = $this->house(answer: 'no');

        self::leg($operations, self::seat());

        self::assertNull($loop->opened);
    }

    public function testTheSeatsNextLegOpensWithTheCallAnAdmissionWasGivenFor(): void
    {
        [$operations, $sessions, $loop] = $this->house(acts: ['admission']);

        self::leg($operations, self::seat());

        self::assertSame('things_count', $loop->opened['tool_calls'][0]['function']['name'] ?? null);
        self::assertSame(self::COUNT, json_decode($loop->opened['tool_calls'][0]['function']['arguments'], true));
        $resumed = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === GrantedCall::RESUMED));
        self::assertCount(1, $resumed);
        self::assertSame('things_count', $resumed[0]->payload['tool']);
        self::assertArrayHasKey('granted', $resumed[0]->payload, 'the fact of a grant, as decisions/0577 left it');
    }

    public function testAnAdmissionInAHouseWithoutTrialsResumesNothing(): void
    {
        [$operations, , $loop] = $this->house(trials: false, acts: ['admission']);

        self::leg($operations, self::seat());

        self::assertNull($loop->opened, 'a scope resumes only what does not land by itself: with no confinement the model asks for it');
    }

    public function testWhenAnAdmissionAndAYesBothStandTheLaterActIsTheOneTakenUp(): void
    {
        [$operations, $sessions, $loop] = $this->house(acts: ['admission', 'answer']);
        self::leg($operations, self::seat());
        self::assertSame('config_set', $loop->opened['tool_calls'][0]['function']['name'] ?? null, 'the yes came last');
        self::assertCount(1, array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === GrantedCall::RESUMED), 'one move, one fact');

        $loop->opened = null;
        self::leg($operations, self::seat());
        self::assertNull($loop->opened, 'and the admission is no longer the last thing: its call goes back to the model');

        [$operations, $sessions, $loop] = $this->house(acts: ['answer', 'admission']);
        self::leg($operations, self::seat());
        self::assertSame('things_count', $loop->opened['tool_calls'][0]['function']['name'] ?? null, 'the admission came last');
        self::assertCount(1, array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === GrantedCall::RESUMED));

        $loop->opened = null;
        self::leg($operations, self::seat());
        self::assertNull($loop->opened);
    }

    public function testInAHouseWithoutTrialsAYesStandsAloneBesideAnAdmission(): void
    {
        [$operations, , $loop] = $this->house(trials: false, acts: ['answer', 'admission']);

        self::leg($operations, self::seat());

        self::assertSame('config_set', $loop->opened['tool_calls'][0]['function']['name'] ?? null, 'the admission resumes nothing there, so the yes is the one act the house can play');
    }

    public function testTheAdmissionOfABuiltVerbResumesTheCallItWasGivenFor(): void
    {
        $stream = [...self::upToTheModel(), self::refusedVerb(4), self::admitted()];

        $call = GrantedCall::toResume($stream, self::at(60));

        self::assertSame(['seq' => 4, 'tool' => 'things_count', 'arguments' => self::COUNT, 'granted' => 5], $call);
    }

    public function testAGrantThatAdmitsNothingStillResumesOnlyWhatATrialConfines(): void
    {
        $grant = self::admitted(['capability' => null, 'contract' => null]);

        self::assertNull(GrantedCall::toResume([...self::upToTheModel(), self::refusedVerb(4), $grant], self::at(60)), 'the control: a scope granted over a call that lands by itself');
        self::assertNull(GrantedCall::toResume([...self::upToTheModel(), self::refusedVerb(4), self::admitted(['capability' => ''])], self::at(60)), 'an admission that names no capability admits nothing');
    }

    public function testAnAdmissionKeepsEveryOtherConditionOfAGrant(): void
    {
        $base = [...self::upToTheModel(), self::refusedVerb(4)];

        self::assertNull(GrantedCall::toResume([...$base, self::admitted(['seq' => 2])], self::at(60)), 'given for another call');
        self::assertNull(GrantedCall::toResume([...$base, self::admitted(['arguments_sha256' => ConsentBridge::digest(['of' => 'others'])])], self::at(60)), 'other arguments');
        self::assertNull(GrantedCall::toResume([...$base, self::admitted(), self::event(6, 'session.model_called', [])], self::at(60)), 'the model was already asked');
        self::assertNull(GrantedCall::toResume([...$base, self::admitted()], self::at(GrantedCall::FRESH_SECONDS + 1)), 'no longer fresh');
    }

    /**
     * A fixture house: the seat enrolled, its session in `ask`, the question the house asked about a call and a
     * person's answer to it.
     *
     * @param list<'answer'|'admission'> $acts what a person did in the session, in order
     *
     * @return array{0: AnsweredFixtureOperations, 1: SessionStore, 2: AnsweredOpeningRecorder}
     */
    private function house(bool $trials = true, string $answer = 'yes', array $acts = ['answer']): array
    {
        $root = sys_get_temp_dir() . '/milpa-yes-runs-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        $this->roots[] = $root;
        (new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))
            ->record(new IdentityEnrolled(self::SEAT, [...ResidentSeat::SCOPES], 'key:' . self::HUMAN));
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Give the site a title.', AutonomyMode::Ask, by: new Principal('key:' . self::SEAT, true));
        $sessions->recordTurn('bv', 'user', 'Give the site a title.');
        $refused = null;
        if (\in_array('admission', $acts, true)) {
            // The seat's call of a built verb was refused: nobody had admitted it.
            $seq = $sessions->recordToolCall('bv', 'things_count', self::COUNT, 'No person admitted «things:read» of the capability «Things» for this seat.', false, true);
            $refused = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->seq === $seq))[0];
        }
        foreach ($acts as $act) {
            if ($act === 'admission') {
                \assert($refused !== null);
                GrantedCall::granted($events, 'bv', $refused, 'things:read', 'key:' . self::HUMAN, ['capability' => 'Things', 'contract' => 'sha256:' . str_repeat('c', 64)]);

                continue;
            }
            $sessions->ask('bv', new PendingQuestion(
                id: 'perm:config:set',
                question: 'The agent wants to run «config:set». Do you allow it in this session?',
                options: ['yes', 'no'],
                why: (string) json_encode(['operation' => 'config:set', 'arguments' => self::SET]),
                reason: 'permission',
            ));
            $sessions->answer('bv', 'perm:config:set', $answer, new Principal('key:' . self::HUMAN, true), 'fixture');
        }

        $container = new DIContainer();
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(Kernel::class, Kernel::boot(['root' => $root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]));
        $operations = new AnsweredFixtureOperations($container);
        (new \ReflectionProperty(AgentOperations::class, 'trialRouterMemo'))->setValue(
            $operations,
            $trials ? new TrialRouter($root, new TrialRunner(), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php') : null,
        );
        $loop = new AnsweredOpeningRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class));
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

    /** @return list<Event> a session up to the model call whose tool call the house asked a person about */
    private static function upToTheModel(): array
    {
        return [
            self::event(1, 'session.started', ['goal' => 'Give the site a title.']),
            self::event(2, 'session.tool_called', ['tool' => 'house_context', 'arguments' => [], 'result' => '{}', 'ok' => true]),
            self::event(3, 'session.model_called', []),
        ];
    }

    /** @return list<Event> the question the house asked about `config:set`, a person's yes, and their `continue` */
    private static function stream(): array
    {
        return [...self::upToTheModel(), self::asked(), self::answered(), self::event(6, 'session.turn', ['role' => 'user', 'content' => 'continue'])];
    }

    /** @param array<string, mixed> $arguments */
    private static function asked(string $operation = 'config:set', array $arguments = self::SET, int $seq = 4): Event
    {
        return self::event($seq, 'session.question_asked', [
            'id' => 'perm:' . $operation,
            'question' => "The agent wants to run «{$operation}». Do you allow it in this session?",
            'options' => ['yes', 'no'],
            'why' => json_encode(['operation' => $operation, 'arguments' => $arguments, 'base' => ['mutation' => 'persistent']]),
            'reason' => 'permission',
        ]);
    }

    private static function answered(string $question = 'perm:config:set', string $answer = 'yes', bool $verified = true): Event
    {
        return self::event(5, 'session.question_answered', ['id' => $question, 'answer' => $answer, 'by' => ['id' => 'key:' . self::HUMAN, 'verified' => $verified], 'executor' => 'fixture']);
    }

    /** A call of a verb built in the house, refused because no person had admitted it for the seat. */
    private static function refusedVerb(int $seq): Event
    {
        return self::event($seq, 'session.tool_called', ['tool' => 'things_count', 'arguments' => self::COUNT, 'result' => 'No person admitted «things:read» of the capability «Things» for this seat.', 'ok' => false, 'mutating' => true]);
    }

    /** @param array<string, mixed> $changes */
    private static function admitted(array $changes = []): Event
    {
        return self::event(5, GrantedCall::GRANTED, array_filter($changes + [
            'seq' => 4, 'tool' => 'things_count', 'permission' => 'things:read',
            'arguments_sha256' => ConsentBridge::digest(self::COUNT), 'authorized_by' => 'key:' . self::HUMAN,
            'capability' => 'Things', 'contract' => 'sha256:' . str_repeat('c', 64),
        ], static fn (mixed $value): bool => $value !== null));
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
final class AnsweredFixtureOperations extends AgentOperations
{
    public ?AgentOrchestrator $loop = null;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->loop !== null);

        return $this->loop;
    }
}

/** An orchestrator that can open with a move, and only remembers which. */
final class AnsweredOpeningRecorder extends AgentOrchestrator
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
