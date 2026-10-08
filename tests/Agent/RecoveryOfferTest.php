<?php

/**
 * A recovering session keeps its offer, and the live gate is what refuses the read (greenhouse 0340/0657; the
 * offer stopped moving inside a leg with decisions/0601, rule D).
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\ContractProducer;
use Milpa\AppRuntime\Agent\SessionOptionTable;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RecoveryOfferTest extends TestCase
{
    private InMemoryEventStore $events;
    private SessionStore $store;
    private SessionToolGate $gate;
    private ConsentBridge $bridge;
    private ToolRegistry $registry;
    private SessionOptionTable $table;
    private int $executions = 0;
    private bool $stallOnRead = false;

    /** @var list<array<int, array<string, mixed>>> the tools of each request of a loop */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('offer', 'Build an artifact', AutonomyMode::Auto);
        $read = new Operation(
            'inspect',
            'Read an artifact',
            static fn (): array => ['ok' => true],
            effects: EffectProfile::readOnly()
        );
        $write = new Operation(
            'materialize',
            'Write an artifact',
            static fn (): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::Compensatable,
                Authority::WriteAsUser,
                subject: Subject::Data,
                rollbackContract: 'Remove the artifact'
            )
        );
        $note = new Operation(
            'notebook',
            'Append to the session notebook',
            static fn (): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::Compensatable,
                Authority::None,
                subject: Subject::Data
            )
        );
        // An authorized producer supplies the self-log contract, outside the app operation list.
        $producer = new class ($note) implements ContractProducer {
            public function __construct(private Operation $note)
            {
            }
            public function contractFor(string $tool): ?Operation
            {
                return $tool === $this->note->name ? $this->note : null;
            }
        };
        $session = $this->store->load('offer');
        self::assertNotNull($session);
        $this->gate = new SessionToolGate($this->store, $session, [$read, $write], contractProducers: [$producer]);
        $this->table = new SessionOptionTable($this->store, 'offer');
        $this->registry = new ToolRegistry(new NullLogger());
        foreach ([$read, $write, $note] as $op) {
            $this->registry->register(
                $op->name,
                $op->description,
                ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
                function () use ($op): array {
                    if ($this->stallOnRead && $op->name === 'inspect') {
                        $this->stall();
                    }
                    ++$this->executions;
                    return ['ok' => true];
                },
                new ToolOptions(mutating: $op->mutating, scopes: ['work:write'])
            );
        }
        $this->bridge = new ConsentBridge(
            $this->registry,
            gate: $this->gate,
            recorder: $this->gate,
            table: $this->table,
            authority: new ToolContext(principal: 'fixture', scopes: ['work:write'])
        );
    }

    /** The offer does not move inside a leg, a stall included (greenhouse decisions/0601, rule D). */
    public function testAStallLeavesTheOfferAsItWasAndRecordsNothing(): void
    {
        $before = $this->bridge->getToolSummaries();
        self::assertCount(3, $before);
        $this->stall();
        $stream = $this->store->stream('offer');
        self::assertSame($before, $this->bridge->getToolSummaries(), 'a stalled session is offered what it was offered');
        self::assertSame($stream, $this->store->stream('offer'));
        self::assertSame(0, $this->executions);
        $this->bridge->callTool('materialize', []);
        self::assertSame($before, $this->bridge->getToolSummaries(), 'and so is one that progressed');
        self::assertSame(1, $this->executions);
        $this->stall();
        self::assertSame($before, $this->bridge->getToolSummaries());
    }

    /** @return iterable<string, array{string, array<string, mixed>, bool}> */
    public static function progressEvents(): iterable
    {
        yield 'failed dispatch' => ['session.tool_called', ['tool' => 'materialize', 'ok' => false, 'mutating' => true, 'result' => '{"ok":true}'], false];
        yield 'internal failure' => ['session.tool_called', ['tool' => 'materialize', 'ok' => true, 'mutating' => true, 'result' => '{"ok":false}'], false];
        yield 'mere read' => ['session.tool_called', ['tool' => 'inspect', 'ok' => true, 'mutating' => false, 'result' => '{"ok":true}'], false];
        yield 'confirmation' => ['session.tool_called', ['tool' => 'materialize', 'ok' => true, 'mutating' => true, 'awaitingConfirmation' => true, 'result' => '{"ok":true}'], false];
        yield 'evidence' => ['session.evidence_recorded', ['predicate' => 'verified', 'subject' => 'artifact'], true];
        yield 'completed todo' => ['session.todo_changed', ['id' => 'unit', 'status' => 'done'], true];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('progressEvents')]
    public function testTheGateFollowsProgressAndTheOfferDoesNot(string $type, array $payload, bool $restored): void
    {
        $this->stall();
        $this->events->append(new Event(SessionStore::PREFIX . 'offer', $type, $payload, $this->events->nextSeq()));
        self::assertContains('inspect', $this->names(), 'offered whatever the recovery says');
        self::assertSame($restored, $this->gate->refuse('inspect', []) === null, 'admitted only once the session progressed');
    }

    public function testRecoveryCannotRestoreAnOptionRemovedForAnotherReason(): void
    {
        $this->table->remove('inspect', 'out_of_scope');
        $this->stall();
        $this->bridge->callTool('materialize', []);
        self::assertNotContains('inspect', $this->names());
        self::assertTrue($this->table->wasRemoved('inspect'));
    }

    public function testDirectCallRefusesExecutionAndReportsTheHiddenRecoveryOption(): void
    {
        $this->stall();
        try {
            $this->bridge->callTool('inspect', []);
            self::fail('A withdrawn read executed');
        } catch (ToolCallRefused $error) {
            self::assertStringStartsWith('Progress recovery:', $error->getMessage());
            self::assertTrue($error->optionRemoved);
        }
        self::assertSame(0, $this->executions);
    }

    public function testAMutationIsNotOfferedToACallerWithoutItsScopeAndIsStillRefused(): void
    {
        $this->stall();
        $door = new ConsentBridge(
            $this->registry,
            gate: $this->gate,
            authority: new ToolContext(principal: 'restricted', scopes: [])
        );
        self::assertSame([], $door->getToolSummaries(), 'a recovery offers nothing this caller cannot call');
        $this->expectExceptionMessage('Missing required scope');
        try {
            $door->callTool('materialize', []);
        } finally {
            self::assertSame(0, $this->executions);
        }
    }

    public function testUnrelatedGatesAndAnAbsentGateKeepTheirCatalogue(): void
    {
        $gate = new class () implements \Milpa\ToolRuntime\Gate\ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return 'Refused';
            }
        };
        $this->stall();
        foreach ([null, $gate] as $other) {
            self::assertCount(3, (new ConsentBridge($this->registry, gate: $other))->getToolSummaries());
        }
    }

    public function testAReadIntroducedDuringRecoveryIsOfferedAndRefused(): void
    {
        $this->stall();
        $op = new Operation('late_read', 'Read newly available data', static fn (): array => [], effects: EffectProfile::readOnly());
        $this->gate->sees([$op]);
        $this->registry->register('late_read', 'Read', ['type' => 'object'], static fn (): array => []);
        self::assertContains('late_read', $this->names(), 'what the house grew is offered');
        self::assertNotNull($this->gate->refuse('late_read', []), 'and the gate is what says no more reading');
    }

    public function testLazyDiscoveryKeepsWhatItUnlockedThroughAStall(): void
    {
        $llm = $this->createMock(LlmService::class);
        $step = 0;
        $llm->expects(self::exactly(5))->method('generateResponse')->willReturnCallback(
            function (string $prompt, array $tools, array $messages) use (&$step): array {
                ++$step;
                $names = array_column($tools, 'name');
                if ($step === 1) {
                    return $this->call('describe_tool', ['name' => 'inspect']);
                }
                if ($step === 2) {
                    self::assertContains('inspect', $names);
                    $this->stall();
                    return $this->call('describe_tool', ['name' => 'materialize']);
                }
                if ($step === 3) {
                    self::assertContains('inspect', $names, 'a stall does not take back a schema the session unlocked');
                    return $this->call('describe_tool', ['name' => 'inspect']);
                }
                if ($step === 4) {
                    $last = json_decode($messages[array_key_last($messages)]['content'], true);
                    self::assertArrayNotHasKey('error', $last);
                    return $this->call('materialize', []);
                }
                self::assertContains('inspect', $names);
                self::assertSame($this->registry->getToolSummaries()[0]['inputSchema'], $tools[array_search('inspect', $names, true)]['inputSchema']);
                return ['role' => 'assistant', 'content' => 'Finished the controlled offer cycle.'];
            },
        );
        (new AgentOrchestrator($llm, $this->bridge, maxSteps: 6, lazyTools: true))->run('Exercise recovery');
        self::assertSame(1, $this->executions);
    }

    public function testAStallInsideALoopLeavesTheToolsOfEveryRequestTheSame(): void
    {
        $this->stallOnRead = true;
        $llm = $this->createMock(LlmService::class);
        $step = 0;
        $llm->expects(self::exactly(3))->method('generateResponse')->willReturnCallback(
            function (string $prompt, array $tools) use (&$step): array {
                ++$step;
                $names = array_column($tools, 'name');
                $this->sent[] = $tools;
                if ($step === 1) {
                    self::assertContains('inspect', $names);
                    return $this->call('inspect', []);
                }
                if ($step === 2) {
                    self::assertContains('inspect', $names, 'the request after the stall carries the tools the one before carried');
                    return $this->call('materialize', []);
                }
                self::assertSame([$this->sent[0], $this->sent[0], $this->sent[0]], $this->sent, 'byte for byte, before the stall, in it and after it');
                return ['role' => 'assistant', 'content' => 'Finished the controlled full cycle.'];
            },
        );
        (new AgentOrchestrator($llm, $this->bridge, maxSteps: 4))->run('Exercise recovery');
    }

    public function testTheGatesRefusalReturnsToTheModelWithoutExecutingTheRead(): void
    {
        $this->stall();
        $llm = $this->createMock(LlmService::class);
        $step = 0;
        $llm->expects(self::exactly(3))->method('generateResponse')->willReturnCallback(
            function (string $prompt, array $tools, array $messages) use (&$step): array {
                ++$step;
                if ($step === 1) {
                    self::assertContains('inspect', array_column($tools, 'name'));
                    return $this->call('inspect', []);
                }
                if ($step === 2) {
                    self::assertSame(0, $this->executions);
                    self::assertContains('inspect', array_column($tools, 'name'));
                    self::assertStringContainsString('Progress recovery', $messages[array_key_last($messages)]['content'], 'the gate said why, to the model');
                    return $this->call('materialize', []);
                }
                self::assertContains('inspect', array_column($tools, 'name'));
                return ['role' => 'assistant', 'content' => 'The fixture acted after receiving the refusal.'];
            },
        );
        (new AgentOrchestrator($llm, $this->bridge, maxSteps: 3))->run('Exercise refusal feedback');
        self::assertSame(1, $this->executions);
        $reads = array_values(array_filter(
            $this->store->stream('offer'),
            static fn ($event) => $event->type === 'session.tool_called' && $event->payload['tool'] === 'inspect'
        ));
        self::assertCount(1, $reads, 'the read reached the gate, which refused it and kept the refusal');
        self::assertFalse($reads[0]->payload['ok']);
    }

    /**
     * A read refused because the session was stalled is told «not now», not «never»: once the session progresses,
     * that same read is admitted. Since the offer stopped moving (decisions/0601), a stalled session can ask for a
     * read and the gate keeps the refusal; without this the loop guard would hold two such refusals against the
     * call for the rest of the leg, and the read that followed real progress ended it.
     */
    public function testAReadRefusedWhileStalledIsNotHeldAgainstItOnceTheSessionProgresses(): void
    {
        $read = new Operation('inspect', 'Read', static fn () => [], effects: EffectProfile::readOnly());
        $gate = new SessionToolGate($this->store, $this->store->load('offer'), [$read], vigiaDeBucle: new \Milpa\AppRuntime\Agent\SterileLoopGuard());
        $this->stall();

        self::assertStringContainsString('Progress recovery', (string) $gate->refuse('inspect', []));
        self::assertStringContainsString('Progress recovery', (string) $gate->refuse('inspect', []));
        $this->events->append(new Event(SessionStore::PREFIX . 'offer', 'session.evidence_recorded', ['predicate' => 'verified', 'subject' => 'artifact'], $this->events->nextSeq()));

        self::assertNull($gate->refuse('inspect', []), 'the session progressed: the read it was told to wait for is admitted');
        // And from here on the guard counts again: what fails on its own is its own.
        $gate->recorded('inspect', [], '{"error":"No such artifact"}', false);
        $gate->recorded('inspect', [], '{"error":"No such artifact"}', false);
        self::assertStringContainsString('No such artifact', (string) $gate->refuse('inspect', []));
    }

    /** The control: a read that failed twice for a reason of its own is still not repeated after progress. */
    public function testAReadThatFailedOnItsOwnStaysRefusedThroughARecovery(): void
    {
        $read = new Operation('inspect', 'Read', static fn () => [], effects: EffectProfile::readOnly());
        $gate = new SessionToolGate($this->store, $this->store->load('offer'), [$read], vigiaDeBucle: new \Milpa\AppRuntime\Agent\SterileLoopGuard());
        $gate->recorded('inspect', ['value' => 'gone'], '{"error":"No such artifact"}', false);
        $gate->recorded('inspect', ['value' => 'gone'], '{"error":"No such artifact"}', false);
        $this->stall();
        self::assertStringContainsString('Progress recovery', (string) $gate->refuse('inspect', []), 'another read, refused for the stall');
        $this->events->append(new Event(SessionStore::PREFIX . 'offer', 'session.evidence_recorded', ['predicate' => 'verified', 'subject' => 'artifact'], $this->events->nextSeq()));

        self::assertStringContainsString('No such artifact', (string) $gate->refuse('inspect', ['value' => 'gone']), 'its own failure is its own');
        self::assertNull($gate->refuse('inspect', []));
    }

    /**
     * While a session is stalled, the stall is what answers a read — also a read the loop guard would not repeat.
     * The guard's refusal ends the leg; the stall's goes back to the model. Before the offer stopped moving
     * (decisions/0601) a stalled session was not offered the read at all and was told so; with the read in view, the
     * guard answered first and cut a leg that had only been told to act (a real resident, evidence/1163).
     */
    public function testWhileStalledTheStallAnswersAReadTheLoopGuardWouldEnd(): void
    {
        $read = new Operation('inspect', 'Read', static fn () => [], effects: EffectProfile::readOnly());
        $gate = new SessionToolGate($this->store, $this->store->load('offer'), [$read], vigiaDeBucle: new \Milpa\AppRuntime\Agent\SterileLoopGuard());
        $gate->recorded('inspect', ['value' => 'gone'], '{"error":"No such artifact"}', false);
        $gate->recorded('inspect', ['value' => 'gone'], '{"error":"No such artifact"}', false);
        self::assertStringContainsString('No such artifact', (string) $gate->refuse('inspect', ['value' => 'gone']), 'not stalled: the guard does not repeat it');
        $this->stall();
        $door = new ConsentBridge($this->registry, gate: $gate, recorder: $gate, authority: new ToolContext(principal: 'fixture', scopes: ['work:write']));

        try {
            $door->callTool('inspect', ['value' => 'gone']);
            self::fail('A read ran while the session was stalled');
        } catch (ToolCallRefused $refusal) {
            self::assertStringStartsWith('Progress recovery', $refusal->getMessage(), 'the stall answers');
            self::assertTrue($refusal->optionRemoved, 'and its refusal goes back to the model: the leg goes on');
        }
        $this->events->append(new Event(SessionStore::PREFIX . 'offer', 'session.evidence_recorded', ['predicate' => 'verified', 'subject' => 'artifact'], $this->events->nextSeq()));

        self::assertStringContainsString('No such artifact', (string) $gate->refuse('inspect', ['value' => 'gone']), 'its own failure is still its own, with its own reason');
        self::assertSame(0, $this->executions);
    }

    /** What bounds a stalled session that keeps reading is the progress probe, not the loop guard. */
    public function testRepeatedReadsWhileStalledAllGoBackToTheModel(): void
    {
        $this->stall();
        $read = new Operation('inspect', 'Read', static fn () => [], effects: EffectProfile::readOnly());
        $gate = new SessionToolGate(
            $this->store,
            $this->store->load('offer'),
            [$read],
            vigiaDeBucle: new \Milpa\AppRuntime\Agent\SterileLoopGuard()
        );
        $door = new ConsentBridge(
            $this->registry,
            gate: $gate,
            recorder: $gate,
            authority: new ToolContext(principal: 'fixture', scopes: ['work:write'])
        );
        for ($i = 0; $i < 4; ++$i) {
            try {
                $door->callTool('inspect', []);
                self::fail('A repeated read executed while the session was stalled');
            } catch (ToolCallRefused $error) {
                self::assertStringStartsWith('Progress recovery', $error->getMessage());
                self::assertTrue($error->optionRemoved, 'told to wait, every time: none of them is a failure of the call');
            }
        }
        self::assertTrue($gate->recoveryRefusalWasHidden('inspect'));
        self::assertSame(0, $this->executions);
        $refused = array_filter($this->store->stream('offer'), static fn ($event) => $event->type === 'session.tool_called' && $event->payload['tool'] === 'inspect');
        self::assertCount(4, $refused, 'the session keeps each refusal: that record is what the progress probe counts');
    }

    public function testAnEarlierOrderRefusalDoesNotBecomeRecoverableBecauseTheSessionIsRecovering(): void
    {
        $this->stall();
        $read = new Operation('inspect', 'Read', static fn () => [], effects: EffectProfile::readOnly());
        $gate = new SessionToolGate(
            $this->store,
            $this->store->load('offer'),
            [$read],
            compuertaPrevia: new \Milpa\AppRuntime\Agent\PrerequisiteGate(['required_first'])
        );
        $door = new ConsentBridge(
            $this->registry,
            gate: $gate,
            recorder: $gate,
            authority: new ToolContext(principal: 'fixture', scopes: ['work:write'])
        );
        self::assertContains('inspect', array_column($door->getToolSummaries(), 'name'));
        try {
            $door->callTool('inspect', []);
            self::fail('The prerequisite was bypassed');
        } catch (ToolCallRefused $error) {
            self::assertFalse($error->optionRemoved);
            self::assertStringContainsString('required_first', $error->getMessage());
        }
        self::assertSame(0, $this->executions);
    }

    public function testThePreviousRecoveryCauseDoesNotClassifyAnUnjudgeableCall(): void
    {
        $this->stall();
        try {
            $this->bridge->callTool('inspect', []);
        } catch (ToolCallRefused $error) {
            self::assertTrue($error->optionRemoved);
        }
        self::assertTrue($this->gate->recoveryRefusalWasHidden('inspect'));
        try {
            $this->bridge->callTool('unknown_contract', []);
            self::fail('An unjudgeable call was accepted');
        } catch (ToolCallRefused $error) {
            self::assertFalse($error->optionRemoved);
            self::assertStringContainsString(SessionToolGate::UNJUDGEABLE, $error->getMessage());
        }
        self::assertFalse($this->gate->recoveryRefusalWasHidden('inspect'));
        self::assertSame(0, $this->executions);
    }

    public function testAHiddenReadStillRequiresItsScope(): void
    {
        $this->stall();
        $door = new ConsentBridge(
            $this->registry,
            gate: $this->gate,
            recorder: $this->gate,
            authority: new ToolContext(principal: 'restricted', scopes: [])
        );
        try {
            $door->callTool('inspect', []);
            self::fail('The required scope was bypassed');
        } catch (\Exception $error) {
            self::assertNotInstanceOf(ToolCallRefused::class, $error);
            self::assertStringContainsString('Missing required scope', $error->getMessage());
        }
        self::assertFalse($this->gate->recoveryRefusalWasHidden('inspect'));
        self::assertSame(0, $this->executions);
    }

    public function testExplicitWithdrawalKeepsItsOwnRefusalAndExecutesNothing(): void
    {
        $this->stall();
        $this->table->remove('inspect', 'caller withdrew the operation');
        try {
            $this->bridge->callTool('inspect', []);
            self::fail('Explicitly withdrawn operation executed');
        } catch (ToolCallRefused $error) {
            self::assertTrue($error->optionRemoved);
            self::assertStringContainsString('withdrawn from this session', $error->getMessage());
        }
        self::assertFalse($this->gate->recoveryRefusalWasHidden('inspect'));
        self::assertSame(0, $this->executions);
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_column($this->bridge->getToolSummaries(), 'name');
    }

    private function stall(): void
    {
        $this->events->append(new Event(SessionStore::PREFIX . 'offer', SessionToolGate::PROGRESS_STALLED, [], $this->events->nextSeq()));
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $name, array $arguments): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'id' => uniqid('call'), 'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ]]];
    }
}
