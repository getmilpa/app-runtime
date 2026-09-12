<?php

/**
 * Recovery offers follow the live gate without granting authority (greenhouse 0340/0657).
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

    public function testOfferWithdrawsReadsAndRestoresThemWithoutRecordingOrExecuting(): void
    {
        $before = $this->bridge->getToolSummaries();
        self::assertCount(3, $before);
        $this->stall();
        $stream = $this->store->stream('offer');
        self::assertSame(['materialize', 'notebook'], $this->names());
        self::assertSame($stream, $this->store->stream('offer'));
        self::assertSame(0, $this->executions);
        $this->bridge->callTool('materialize', []);
        self::assertSame($before, $this->bridge->getToolSummaries(), 'Complete schemas return after real progress');
        self::assertSame(1, $this->executions);
        $this->stall();
        self::assertSame(['materialize', 'notebook'], $this->names());
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
    public function testOfferUsesTheSameSemanticProgressAsAdmission(string $type, array $payload, bool $restored): void
    {
        $this->stall();
        $this->events->append(new Event(SessionStore::PREFIX . 'offer', $type, $payload, $this->events->nextSeq()));
        self::assertSame($restored, in_array('inspect', $this->names(), true));
        self::assertSame($restored, $this->gate->refuse('inspect', []) === null);
    }

    public function testRecoveryCannotRestoreAnOptionRemovedForAnotherReason(): void
    {
        $this->table->remove('inspect', 'out_of_scope');
        $this->stall();
        $this->bridge->callTool('materialize', []);
        self::assertNotContains('inspect', $this->names());
        self::assertTrue($this->table->wasRemoved('inspect'));
    }

    public function testDirectCallStillRefusesAndKeepsItsTerminalClassification(): void
    {
        $this->stall();
        try {
            $this->bridge->callTool('inspect', []);
            self::fail('A withdrawn read executed');
        } catch (ToolCallRefused $error) {
            self::assertStringStartsWith('Progress recovery:', $error->getMessage());
            self::assertFalse($error->optionRemoved);
        }
        self::assertSame(0, $this->executions);
    }

    public function testOfferedMutationStillRequiresItsScope(): void
    {
        $this->stall();
        $door = new ConsentBridge(
            $this->registry,
            gate: $this->gate,
            authority: new ToolContext(principal: 'restricted', scopes: [])
        );
        self::assertContains('materialize', array_column($door->getToolSummaries(), 'name'));
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

    public function testAReadIntroducedDuringRecoveryIsAlsoWithdrawn(): void
    {
        $this->stall();
        $op = new Operation('late_read', 'Read newly available data', static fn (): array => [], effects: EffectProfile::readOnly());
        $this->gate->sees([$op]);
        $this->registry->register('late_read', 'Read', ['type' => 'object'], static fn (): array => []);
        self::assertNotContains('late_read', $this->names());
    }

    public function testLazyDiscoveryDoesNotLeakAWithdrawnSchemaAndRestoresTheUnlockedTool(): void
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
                    self::assertNotContains('inspect', $names);
                    self::assertStringNotContainsString('inspect: ', $tools[array_search('describe_tool', $names, true)]['description']);
                    return $this->call('describe_tool', ['name' => 'inspect']);
                }
                if ($step === 4) {
                    $last = json_decode($messages[array_key_last($messages)]['content'], true);
                    self::assertArrayHasKey('error', $last);
                    self::assertArrayNotHasKey('inputSchema', $last);
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

    public function testFullLoopReprojectsBeforeTheNextModelCall(): void
    {
        $this->stallOnRead = true;
        $llm = $this->createMock(LlmService::class);
        $step = 0;
        $llm->expects(self::exactly(3))->method('generateResponse')->willReturnCallback(
            function (string $prompt, array $tools) use (&$step): array {
                ++$step;
                $names = array_column($tools, 'name');
                if ($step === 1) {
                    self::assertContains('inspect', $names);
                    return $this->call('inspect', []);
                }
                if ($step === 2) {
                    self::assertNotContains('inspect', $names);
                    return $this->call('materialize', []);
                }
                self::assertContains('inspect', $names);
                return ['role' => 'assistant', 'content' => 'Finished the controlled full cycle.'];
            },
        );
        (new AgentOrchestrator($llm, $this->bridge, maxSteps: 4))->run('Exercise recovery');
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
