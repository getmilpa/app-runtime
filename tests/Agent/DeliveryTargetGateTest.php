<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\{AutonomyMode, PendingQuestion, ProgressReceipt, SessionStore};
use Milpa\AiGateway\{AgentOrchestrator, LlmService};
use Milpa\AppRuntime\Agent\{ConsentBridge, DeliveryExpectation, ObservedExecutor, SessionOptionTable, SessionToolGate, SterileLoopGuard};
use Milpa\AppRuntime\Web\{ScreenDraftOperations, ScreenDrafts, ScreenStore};
use Milpa\Command\Operation;
use Milpa\Command\Effect\{Authority, EffectProfile, Externality, Mutation, Reversibility, Subject};
use Milpa\Console\McpProjector;
use Milpa\Container\DIContainer;
use Milpa\EventStore\{Event, InMemoryEventStore};
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The caller's durable screen name resolves selection, never consent or activation (0428/0748). */
final class DeliveryTargetGateTest extends TestCase
{
    private string $root;
    private ScreenDrafts $drafts;
    private string $baseline;

    /** Real screen storage; rendering is outside this gate's contract. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-delivery-target-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $active = new ScreenStore($this->root . '/screens.json');
        $active->declare(['name' => 'focus-delivery', 'type' => 'counter', 'props' => ['goal' => 3]]);
        $this->baseline = file_get_contents($this->root . '/screens.json');
        $this->drafts = new ScreenDrafts($active, $this->root . '/drafts', static function (): void {
        }, static fn (): string => 'test-build');
    }

    /** Every case must preserve active state, including refusal paths. */
    protected function tearDown(): void
    {
        self::assertSame($this->baseline, file_get_contents($this->root . '/screens.json'));
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** The invocation owns the expectation before any execution. */
    private function ledger(AutonomyMode $mode = AutonomyMode::Auto, string $goal = 'Prepare the repaired screen for review.'): InMemoryEventStore
    {
        $events = new InMemoryEventStore();
        (new SessionStore($events))->start('s', $goal, $mode);
        DeliveryExpectation::record($events, 's', [
            'test' => ['path' => 'tests/Owned', 'filter' => ''],
            'screen' => ['name' => 'focus-delivery', 'type' => 'counter'],
        ], ObservedExecutor::unknown());
        return $events;
    }

    /** Rehydrate using a fresh store, as a continuation does. */
    private function gate(InMemoryEventStore $events, ?array $operations = null, string $petition = 'Continue.', ?SterileLoopGuard $guard = null): SessionToolGate
    {
        $sessions = new SessionStore($events);
        return new SessionToolGate($sessions, $sessions->load('s'), $operations ?? (new ScreenDraftOperations($this->drafts))->operations(), petition: $petition, vigiaDeBucle: $guard);
    }

    /** The expected target reaches the actual handler and yields a reviewable immutable revision. */
    public function testExpectedTargetCanCreateDraftWithoutChangingActiveScreen(): void
    {
        $events = $this->ledger();
        $args = ['name' => 'focus-delivery', 'type' => 'counter', 'props' => ['goal' => 6]];
        self::assertNull($this->gate($events)->refuse('screen_draft', $args));
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll((new ScreenDraftOperations($this->drafts))->operations(), $registry, new DIContainer());
        $result = $registry->call('screen_draft', $args, new ToolContext(principal: 'lab-test', channel: 'lab', scopes: ['milpa:component:screen-review:draft']));
        self::assertTrue($result->success, json_encode($result->toArray()));
        $revision = $result->data['result'];
        self::assertSame('/live/review?revision=' . $revision['id'], $revision['reviewAt']);
        self::assertSame('focus-delivery', $this->drafts->load($revision['id'])['name']);
        self::assertCount(1, $this->drafts->catalogue()['drafts']);
        self::assertNull((new SessionStore($events))->load('s')->question);
    }

    /** Naming a target has no effect on the session's permission policy. */
    public function testAskModeStillRequiresPermission(): void
    {
        $events = $this->ledger(AutonomyMode::Ask);
        self::assertNotNull($this->gate($events)->refuse('screen_draft', ['name' => 'focus-delivery']));
        self::assertSame('permission', (new SessionStore($events))->load('s')->question->reason);
        self::assertCount(0, $this->drafts->catalogue()['drafts']);
    }

    /** Naming is not a registry scope, even in Auto mode. */
    public function testMissingDraftScopeStillRefuses(): void
    {
        $events = $this->ledger();
        $args = ['name' => 'focus-delivery', 'type' => 'counter', 'props' => []];
        self::assertNull($this->gate($events)->refuse('screen_draft', $args));
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll((new ScreenDraftOperations($this->drafts))->operations(), $registry, new DIContainer());
        $result = $registry->call('screen_draft', $args, new ToolContext(principal: 'lab-test', channel: 'lab', scopes: []));
        self::assertFalse($result->success);
        self::assertSame('FORBIDDEN', $result->meta['code']);
        self::assertCount(0, $this->drafts->catalogue()['drafts']);
    }

    /** Target comparison is exact; text, another stream and another operation cannot name it. */
    public static function unselected(): iterable
    {
        foreach (['absent', 'other_session', 'model_prose', 'promote', 'rollback', 'other_operation', 'grave', 'other_argument'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('unselected')]
    public function testUnselectedTargetsStillPause(string $case): void
    {
        $events = $this->ledger();
        $rows = (new SessionStore($events))->stream('s');
        if (in_array($case, ['absent', 'other_session', 'model_prose'], true)) {
            $events = new InMemoryEventStore();
            $events->append($rows[0]);
            if ($case === 'other_session') {
                $events->append(new Event(SessionStore::PREFIX . 'other', DeliveryExpectation::EVENT, $rows[1]->payload, 2));
            } elseif ($case === 'model_prose') {
                $events->append(new Event(SessionStore::PREFIX . 's', 'session.model_answered', ['answer' => 'Use focus-delivery'], 2));
            }
        }
        $name = 'focus-delivery';
        $tool = in_array($case, ['promote', 'rollback'], true) ? 'screen_' . $case : 'screen_draft';
        $args = in_array($case, ['promote', 'rollback'], true) ? ['revision' => $name] : ['name' => $name];
        $operations = null;
        if (in_array($case, ['other_operation', 'grave', 'other_argument'], true)) {
            $operations = [new Operation(
                $case === 'other_operation' ? 'plugin:disable' : 'screen:draft',
                'A different effect or target contract.',
                static fn (): array => [],
                mutating: true,
                namedTarget: $case === 'other_argument' ? 'destination' : 'name',
                effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, $case === 'grave' ? Authority::Privileged : Authority::WriteAsUser, subject: Subject::Data),
            )];
            $tool = $case === 'other_operation' ? 'plugin_disable' : 'screen_draft';
            $args = $case === 'other_argument' ? ['destination' => $name] : $args;
        }
        self::assertNotNull($this->gate($events, $operations)->refuse($tool, $args));
        self::assertSame('target_not_named', (new SessionStore($events))->load('s')->question->reason);
        self::assertCount(0, $this->drafts->catalogue()['drafts']);
    }

    /** @return iterable<string, array{string}> */
    public static function mismatchedNames(): iterable
    {
        foreach (['resident-todo-board', 'FOCUS-DELIVERY', 'focus', 'focus-delivery-other'] as $name) {
            yield $name => [$name];
        }
    }

    /** A wrong argument is recorded as failure, leaves recovery open and never writes or asks. */
    #[DataProvider('mismatchedNames')]
    public function testKnownMismatchCanBeCorrectedWithoutRewritingArguments(string $name): void
    {
        $events = $this->ledger();
        $events->append(new Event(SessionStore::PREFIX . 's', SessionToolGate::PROGRESS_STALLED, [], $events->nextSeq()));
        $gate = $this->gate($events);
        $bridge = $this->bridge($events, $gate);
        $before = $bridge->getToolSummaries();
        $args = ['name' => $name, 'type' => 'counter', 'props' => ['goal' => 6]];
        try {
            $bridge->callTool('screen_draft', $args);
            self::fail('The wrong target executed.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('delivery_target_mismatch', $error->getMessage());
            self::assertStringContainsString('focus-delivery', $error->getMessage());
            self::assertStringContainsString($name, $error->getMessage());
        }
        self::assertCount(0, $this->drafts->catalogue()['drafts']);
        self::assertNull((new SessionStore($events))->load('s')->question);
        self::assertSame($before, $bridge->getToolSummaries());
        $rows = (new SessionStore($events))->stream('s');
        $calls = array_values(array_filter($rows, static fn (Event $e): bool => $e->type === 'session.tool_called'));
        self::assertCount(1, $calls);
        self::assertSame($args, $calls[0]->payload['arguments']);
        self::assertFalse($calls[0]->payload['ok']);
        $receipt = ProgressReceipt::of($rows, 0, end($rows)->seq);
        self::assertSame(ProgressReceipt::STALLED, $receipt->progress);
        self::assertSame(0, $receipt->newArtifacts);
        self::assertSame(0, $receipt->newEvidence);
        self::assertSame([], (new SessionOptionTable(new SessionStore($events), 's'))->removed());

        // A fresh reader gets the failed call and the unchanged expectation. The caller must
        // supply the corrected name; the gate never edits it on their behalf.
        $result = $this->bridge($events, $this->gate($events))->callTool('screen_draft', array_replace($args, ['name' => 'focus-delivery']));
        self::assertSame('focus-delivery', $this->drafts->load($result['result']['id'])['name']);
        self::assertCount(1, $this->drafts->catalogue()['drafts']);
    }

    /** Same native gateway loop receives the error, then a separately proposed correction. */
    public function testModelLoopReceivesCorrectionAndRetainsTheTool(): void
    {
        $events = $this->ledger();
        $bridge = $this->bridge($events, $this->gate($events));
        $llm = $this->createMock(LlmService::class);
        $step = 0;
        $llm->expects(self::exactly(3))->method('generateResponse')->willReturnCallback(
            function (string $prompt, array $tools, array $messages) use (&$step): array {
                ++$step;
                self::assertContains('screen_draft', array_column($tools, 'name'));
                if ($step === 2) {
                    self::assertCount(0, $this->drafts->catalogue()['drafts']);
                    $last = $messages[array_key_last($messages)];
                    self::assertSame('tool', $last['role']);
                    self::assertSame('screen_draft', $last['name']);
                    self::assertStringContainsString('delivery_target_mismatch', $last['content']);
                    self::assertStringContainsString('focus-delivery', $last['content']);
                }
                if ($step === 3) {
                    return ['role' => 'assistant', 'content' => 'Controlled draft created.'];
                }
                return ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                    'id' => 'draft-' . $step, 'type' => 'function',
                    'function' => ['name' => 'screen_draft', 'arguments' => json_encode([
                        'name' => $step === 1 ? 'resident-todo-board' : 'focus-delivery',
                        'type' => 'counter', 'props' => ['goal' => 6],
                    ])],
                ]]];
            }
        );
        (new AgentOrchestrator($llm, $bridge, maxSteps: 3))->run('Continue.');
        self::assertCount(1, $this->drafts->catalogue()['drafts']);
        self::assertNull((new SessionStore($events))->load('s')->question);
        self::assertSame([], (new SessionOptionTable(new SessionStore($events), 's'))->removed());
    }

    /** Correcting a name neither grants consent nor forgives missing scopes. */
    public function testCorrectionStillRequiresPermissionAndScope(): void
    {
        $events = $this->ledger(AutonomyMode::Ask);
        $args = ['name' => 'resident-todo-board', 'type' => 'counter', 'props' => []];
        $bridge = $this->bridge($events, $this->gate($events));
        try {
            $bridge->callTool('screen_draft', $args);
            self::fail('Wrong target ran.');
        } catch (\InvalidArgumentException) {
            self::assertNull((new SessionStore($events))->load('s')->question);
        }
        try {
            $bridge->callTool('screen_draft', array_replace($args, ['name' => 'focus-delivery']));
            self::fail('Consent was waived.');
        } catch (ToolCallRefused $error) {
            self::assertFalse($error->optionRemoved);
            self::assertSame('permission', (new SessionStore($events))->load('s')->question->reason);
        }
        foreach (['resident-todo-board', 'focus-delivery'] as $name) {
            $scoped = $this->ledger();
            try {
                $this->bridge($scoped, $this->gate($scoped), [])->callTool('screen_draft', array_replace($args, ['name' => $name]));
                self::fail('Missing scope was waived.');
            } catch (\Exception $error) {
                self::assertStringContainsString('Missing required scope', $error->getMessage());
                self::assertStringNotContainsString('delivery_target_mismatch', $error->getMessage());
                self::assertNull((new SessionStore($scoped))->load('s')->question);
            }
        }
        self::assertCount(0, $this->drafts->catalogue()['drafts']);
    }

    /** Existing human intent still follows normal policy, even for a different named target. */
    public function testExplicitHumanTargetIsNotReclassifiedAsAnArgumentMistake(): void
    {
        $args = ['name' => 'other-screen', 'type' => 'counter', 'props' => []];
        foreach (['petition', 'goal', 'confirmed'] as $source) {
            $events = $this->ledger(AutonomyMode::Ask, $source === 'goal' ? 'Prepare other-screen.' : 'Prepare the screen.');
            if ($source === 'confirmed') {
                $store = new SessionStore($events);
                $store->ask('s', new PendingQuestion(
                    'target',
                    'Confirm other-screen?',
                    ['yes', 'no'],
                    why: json_encode(['operation' => 'screen:draft', 'arguments' => $args]),
                    reason: 'target_not_named'
                ));
                $store->answer('s', 'target', 'yes');
            }
            $result = $this->gate($events, petition: $source === 'petition' ? 'Prepare other-screen.' : 'Continue.')->refuse('screen_draft', $args);
            if ($source === 'confirmed') {
                self::assertNull($result);
            } else {
                self::assertNotNull($result);
                self::assertSame('permission', (new SessionStore($events))->load('s')->question->reason);
            }
        }
    }

    /** Error receipts still feed the existing identical-failure guard. */
    public function testRepeatedMismatchDoesNotResetFailureHistory(): void
    {
        $events = $this->ledger();
        $guard = new SterileLoopGuard();
        $gate = $this->gate($events, guard: $guard);
        $args = ['name' => 'other-screen', 'type' => 'counter', 'props' => []];
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $gate->refuse('screen_draft', $args);
                self::fail('Expected argument error.');
            } catch (\InvalidArgumentException) {
                self::assertNull((new SessionStore($events))->load('s')->question);
            }
        }
        self::assertStringContainsString('dos veces', $gate->refuse('screen_draft', $args));
        self::assertNull($gate->refuse('screen_draft', array_replace($args, ['name' => 'focus-delivery'])));
    }

    /** The production bridge keeps the native scope check before session argument validation.
     * @param list<string> $scopes
     */
    private function bridge(InMemoryEventStore $events, SessionToolGate $gate, array $scopes = ['milpa:component:screen-review:draft']): ConsentBridge
    {
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll((new ScreenDraftOperations($this->drafts))->operations(), $registry, new DIContainer());
        return new ConsentBridge(
            $registry,
            gate: $gate,
            recorder: $gate,
            table: new SessionOptionTable(new SessionStore($events), 's'),
            authority: new ToolContext(principal: 'lab-test', channel: 'lab', scopes: $scopes)
        );
    }

    /** A corrupt declared obligation cannot turn into an omitted one or an ordinary confirmation. */
    public static function invalidDeclarations(): iterable
    {
        foreach (['hash', 'schema', 'session', 'source', 'channel', 'duplicate', 'late', 'malformed'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidDeclarations')]
    public function testInvalidDeclarationsFailClosed(string $case): void
    {
        $rows = (new SessionStore($this->ledger()))->stream('s');
        $events = new InMemoryEventStore();
        $events->append($rows[0]);
        $p = $rows[1]->payload;
        match ($case) {
            'hash' => $p['sha256'] = 'wrong',
            'schema' => $p['schema'] = 'wrong',
            'session' => $p['session'] = 'other',
            'source' => $p['provenance']['source'] = 'model',
            'channel' => $p['provenance']['channel'] = null,
            'malformed' => $p['expected'] = [],
            default => null,
        };
        if ($case === 'late') {
            $events->append(new Event(SessionStore::PREFIX . 's', 'session.tool_called', [], 2));
        }
        $events->append(new Event(SessionStore::PREFIX . 's', DeliveryExpectation::EVENT, $p, $events->nextSeq()));
        if ($case === 'duplicate') {
            $events->append(new Event(SessionStore::PREFIX . 's', DeliveryExpectation::EVENT, $p, $events->nextSeq()));
        }
        $before = (new SessionStore($events))->stream('s');
        self::assertStringContainsString(SessionToolGate::UNJUDGEABLE, $this->gate($events)->refuse('screen_draft', ['name' => 'focus-delivery']));
        self::assertSame($before, (new SessionStore($events))->stream('s'));
        self::assertCount(0, $this->drafts->catalogue()['drafts']);
    }
}
