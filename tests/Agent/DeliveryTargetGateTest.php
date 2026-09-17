<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\{AutonomyMode, SessionStore};
use Milpa\AppRuntime\Agent\{DeliveryExpectation, ObservedExecutor, SessionToolGate};
use Milpa\AppRuntime\Web\{ScreenDraftOperations, ScreenDrafts, ScreenStore};
use Milpa\Command\Operation;
use Milpa\Command\Effect\{Authority, EffectProfile, Externality, Mutation, Reversibility, Subject};
use Milpa\Console\McpProjector;
use Milpa\Container\DIContainer;
use Milpa\EventStore\{Event, InMemoryEventStore};
use Milpa\ToolRuntime\Contracts\ToolContext;
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
    private function ledger(AutonomyMode $mode = AutonomyMode::Auto): InMemoryEventStore
    {
        $events = new InMemoryEventStore();
        (new SessionStore($events))->start('s', 'Prepare the repaired screen for review.', $mode);
        DeliveryExpectation::record($events, 's', [
            'test' => ['path' => 'tests/Owned', 'filter' => ''],
            'screen' => ['name' => 'focus-delivery', 'type' => 'counter'],
        ], ObservedExecutor::unknown());
        return $events;
    }

    /** Rehydrate using a fresh store, as a continuation does. */
    private function gate(InMemoryEventStore $events, ?array $operations = null): SessionToolGate
    {
        $sessions = new SessionStore($events);
        return new SessionToolGate($sessions, $sessions->load('s'), $operations ?? (new ScreenDraftOperations($this->drafts))->operations(), petition: 'Continue.');
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
        foreach (['foreign', 'case', 'substring', 'absent', 'other_session', 'model_prose', 'promote', 'rollback', 'other_operation', 'grave', 'other_argument'] as $case) {
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
        $name = match ($case) {
            'foreign' => 'other-screen', 'case' => 'FOCUS-DELIVERY', 'substring' => 'focus', default => 'focus-delivery'
        };
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
