<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\EffectObservation;
use Milpa\Agent\ProgressReceipt;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ScreenDraftObservation;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Web\ScreenDraftOperations;
use Milpa\AppRuntime\Web\ScreenDrafts;
use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Native persisted proposals, their result boundary, and the existing progress consumer. */
final class ScreenDraftObservationTest extends TestCase
{
    private string $root;
    private ScreenStore $active;
    private ScreenDrafts $drafts;
    private string $build = 'build-a';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-draft-observation-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->active = new ScreenStore($this->root . '/screens.json');
        $this->drafts = new ScreenDrafts($this->active, $this->root . '/drafts', static function (): void {
        }, fn () => $this->build);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testNewRevisionsOfKnownValuesPreserveStorageWithoutNewCredit(): void
    {
        $args = $this->arguments();
        $first = $this->save($args);
        self::assertTrue($first->known);
        self::assertCount(1, $first->artifacts);
        self::assertSame([], $first->evidence);
        self::assertSame([], $first->diagnostics);
        self::assertSame([], $this->save($args)->artifacts);
        $args['props'] = ['name' => 'tasks', 'locale' => 'en', 'density' => 'comfortable'];
        self::assertSame([], $this->save($args)->artifacts, 'Map order and explicit default name are not new values.');
        $args['props']['name'] = null;
        self::assertSame([], $this->save($args)->artifacts, 'Native name normalization also applies to null.');
        $records = $this->drafts->catalogue()['drafts'];
        self::assertCount(4, $records);
        self::assertCount(4, array_unique(array_column($records, 'id')));
        foreach ($records as $record) {
            self::assertSame($record, $this->drafts->load($record['id']));
        }
        self::assertNull($this->active->screen('tasks'));
    }

    public function testPreexistingProposalsAndReturnToEarlierValuesAreNotNovel(): void
    {
        $args = $this->arguments();
        $this->drafts->draft($args['name'], $args['type'], $args['props']);
        self::assertSame([], $this->save($args)->artifacts, 'A new session does not erase the native store.');
        $args['props']['density'] = 'compact';
        self::assertCount(1, $this->save($args)->artifacts);
        $args['props']['density'] = 'comfortable';
        self::assertSame([], $this->save($args)->artifacts);
    }

    public function testPropsListOrderTypeNameBuildAndBaselineDescribeDifferentProposals(): void
    {
        $args = $this->arguments();
        $identities = $this->save($args)->artifacts;
        $args['props']['items'] = ['a', 'b'];
        array_push($identities, ...$this->save($args)->artifacts);
        $args['props']['items'] = ['b', 'a'];
        array_push($identities, ...$this->save($args)->artifacts);
        $args['type'] = 'other-board';
        array_push($identities, ...$this->save($args)->artifacts);
        $args['name'] = 'other-tasks';
        array_push($identities, ...$this->save($args)->artifacts);
        $this->build = 'build-b';
        array_push($identities, ...$this->save($args)->artifacts);
        $this->active->declare(['name' => 'other-tasks', 'type' => 'board', 'props' => []]);
        array_push($identities, ...$this->save($args)->artifacts);
        self::assertCount(7, array_unique($identities));
        self::assertSame([], $this->save($args)->artifacts);
    }

    #[DataProvider('tamperedResults')]
    public function testSuccessfulClaimsMustMatchThePersistedRecordAndArguments(string $tamper): void
    {
        $args = $this->arguments();
        $observation = ScreenDraftObservation::prepare(fn () => $this->drafts, $args);
        $result = $this->execute($args);
        $data = $result->data;
        switch ($tamper) {
            case 'id': $data['result']['id'] = str_repeat('0', 64);
                break;
            case 'missing-id': unset($data['result']['id']);
                break;
            case 'build': $data['result']['build'] = 'invented';
                break;
            case 'before': $data['result']['before'] = ['type' => 'forged'];
                break;
            case 'nonce': $data['result']['nonce'] = 'invented';
                break;
            case 'missing-field': unset($data['result']['createdAt']);
                break;
            case 'result': $data['result'] = 'I saved a new screen';
                break;
            case 'arguments':
                $args['props']['density'] = 'compact';
                $data = $this->execute($args)->data;
                break;
            case 'name':
                $args['name'] = 'elsewhere';
                $data = $this->execute($args)->data;
                break;
        }
        $effect = $observation->observe(ToolResult::success($data));
        self::assertFalse($effect->known);
        self::assertSame([], $effect->artifacts);
    }

    public static function tamperedResults(): iterable
    {
        foreach (['id', 'missing-id', 'build', 'before', 'nonce', 'missing-field', 'result', 'arguments', 'name'] as $case) {
            yield $case => [$case];
        }
    }

    public function testLinksAndMapOrderDoNotChangeTheNativeResult(): void
    {
        $args = $this->arguments();
        $observer = ScreenDraftObservation::prepare(fn () => $this->drafts, $args);
        $data = $this->execute($args)->data;
        self::assertArrayHasKey('reviewAt', $data['result']);
        $data['result'] = array_reverse($data['result'], true);
        $data['result']['definition']['props'] = array_reverse($data['result']['definition']['props'], true);
        self::assertCount(1, $observer->observe(ToolResult::success($data))->artifacts);
    }

    #[DataProvider('unverifiableArguments')]
    public function testUnverifiableArgumentsCannotClaimASavedProposal(array $arguments): void
    {
        $observer = ScreenDraftObservation::prepare(fn () => $this->drafts, $arguments);
        self::assertFalse($observer->observe($this->execute($this->arguments()))->known);
    }

    public static function unverifiableArguments(): iterable
    {
        yield 'missing' => [[]];
        yield 'type' => [['name' => 'tasks', 'type' => null, 'props' => []]];
        yield 'props' => [['name' => 'tasks', 'type' => 'board', 'props' => 'not-an-object']];
        yield 'invalid-encoding' => [['name' => 'tasks', 'type' => 'board', 'props' => ['value' => "\xFF"]]];
    }

    #[DataProvider('nonExecutedResults')]
    public function testFailureConfirmationAndProseCannotClaimAWrittenArtifact(ToolResult $result): void
    {
        $observer = ScreenDraftObservation::prepare(fn () => $this->drafts, $this->arguments());
        $effect = $observer->observe($result);
        self::assertTrue($effect->known);
        self::assertSame([], $effect->artifacts);
        $this->execute($this->arguments());
        self::assertFalse($observer->observe($result)->known, 'A changed store with no bound result is unknown.');
    }

    public static function nonExecutedResults(): iterable
    {
        yield 'error' => [ToolResult::error('refused')];
        yield 'nested-error' => [ToolResult::success(['ok' => false, 'error' => 'invalid_name'])];
        yield 'prose' => [ToolResult::success('I saved it')];
        yield 'confirmation' => [ToolResult::confirmation('approve', ['ok' => true], 'draft', 'screen', 'tasks')];
    }

    public function testInvalidNativeNameIsObservedWithoutChangingTheFailure(): void
    {
        $args = $this->arguments();
        $args['name'] = '../outside';
        $observer = ScreenDraftObservation::prepare(fn () => $this->drafts, $args);
        $result = $this->execute($args);
        self::assertSame(['ok' => false, 'error' => 'invalid_name'], $result->data);
        self::assertTrue($observer->observe($result)->known);
        self::assertSame([], $observer->observe($result)->artifacts);
        self::assertSame([], $this->drafts->catalogue()['drafts']);
    }

    public function testMissingOrFailingResolversAreUnknownAndDoNotBreakExecution(): void
    {
        foreach ([null, static fn () => null, static fn () => throw new \RuntimeException('unavailable')] as $resolve) {
            $observer = ScreenDraftObservation::prepare($resolve, $this->arguments());
            $result = $this->execute($this->arguments());
            self::assertTrue($result->data['ok']);
            self::assertFalse($observer->observe($result)->known);
        }
        self::assertCount(3, $this->drafts->catalogue()['drafts']);
    }

    public function testCorruptStoreBeforeOrAfterExecutionRemainsUnknown(): void
    {
        $args = $this->arguments();
        $before = ScreenDraftObservation::prepare(fn () => $this->drafts, $args);
        $result = $this->execute($args);
        $path = $this->root . '/drafts/' . $result->data['result']['id'] . '.json';
        $original = file_get_contents($path);
        file_put_contents($path, '{broken');
        self::assertFalse($before->observe($result)->known);
        $after = ScreenDraftObservation::prepare(fn () => $this->drafts, $args);
        file_put_contents($path, $original);
        self::assertFalse($after->observe($result)->known);
    }

    #[DataProvider('malformedRecords')]
    public function testNativeHashAloneDoesNotMakeAMalformedRecordObservable(array $change): void
    {
        $result = $this->execute($this->arguments());
        $record = $this->drafts->load($result->data['result']['id']);
        unset($record['id']);
        $record = array_replace($record, $change);
        file_put_contents($this->root . '/drafts/' . ScreenDrafts::hash($record) . '.json', json_encode($record));
        $observer = ScreenDraftObservation::prepare(fn () => $this->drafts, $this->arguments());
        self::assertFalse($observer->observe($this->execute($this->arguments()))->known);
    }

    public static function malformedRecords(): iterable
    {
        yield 'kind' => [['kind' => 'other']];
        yield 'baseline' => [['before' => 'not-a-screen']];
        yield 'build' => [['build' => null]];
        yield 'definition' => [['definition' => null]];
        yield 'name' => [['name' => null]];
        yield 'forged-id' => [['id' => str_repeat('0', 64)]];
    }

    public function testRegistryEmitsLinkedEffectsAndProgressDoesNotCountRevisions(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s-draft', 'Build the screen', AutonomyMode::Ask);
        $registry = $this->registry($sessions);
        $args = $this->arguments();
        $checkpoint = 0;
        foreach ([1, 0, 1, 0] as $index => $expected) {
            $args['props']['density'] = $index === 2 ? 'compact' : 'comfortable';
            $result = $registry->call('screen_draft', $args, $this->context());
            self::assertTrue($result->success);
            $stream = $events->replay('agent-session:s-draft');
            $witness = $stream[array_key_last($stream)];
            self::assertSame('session.effect_observed', $witness->type);
            self::assertSame(EffectObservation::argumentsDigest($args), $witness->payload['argumentsDigest']);
            $end = $sessions->recordToolCall('s-draft', 'screen_draft', $args, $result->toJson(), true, true, effectObservationSeq: $witness->seq);
            $receipt = ProgressReceipt::of($events->replay('agent-session:s-draft'), $checkpoint, $end);
            self::assertSame($expected, $receipt->newArtifacts);
            self::assertSame(0, $receipt->newEvidence);
            self::assertSame(0, $receipt->newDiagnostics);
            $checkpoint = $end;
        }
        self::assertSame(2, ProgressReceipt::of($events->replay('agent-session:s-draft'), 0, $checkpoint)->newArtifacts);
        self::assertCount(4, $this->drafts->catalogue()['drafts']);
        self::assertNull($this->active->screen('tasks'));
        // A historical mutating success without an observation still uses its documented proxy.
        $end = $sessions->recordToolCall('s-draft', 'screen_draft', $args, '{"ok":true}', true, true);
        self::assertSame(1, ProgressReceipt::of($events->replay('agent-session:s-draft'), $checkpoint, $end)->newArtifacts);
    }

    public function testScopeDenialDoesNotSaveOrEarnCreditAndTheObserverDoesNotWidenAuthority(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s-draft', 'Build the screen', AutonomyMode::Ask);
        $result = $this->registry($sessions)->call('screen_draft', $this->arguments(), new ToolContext(principal: 'resident', channel: 'test'));
        self::assertFalse($result->success);
        self::assertStringContainsString('Missing required scope', (string) $result->error);
        self::assertSame([], $this->drafts->catalogue()['drafts']);
        foreach ($events->replay('agent-session:s-draft') as $event) {
            if ($event->type === 'session.effect_observed') {
                self::assertSame([], $event->payload['observation']['artifacts']);
            }
        }
    }

    public function testCallsWithoutASessionStillReturnTheNativeResult(): void
    {
        $result = $this->registry(null)->call('screen_draft', $this->arguments(), $this->context());
        self::assertTrue($result->success);
        self::assertTrue($result->data['ok']);
        self::assertSame($result->data['result']['id'], $this->drafts->catalogue()['drafts'][0]['id']);
    }

    private function registry(?SessionStore $sessions): TrialAwareRegistry
    {
        $operations = (new ScreenDraftOperations($this->drafts))->operations();
        $operation = $operations[0];
        $inner = new ToolRegistry(new NullLogger());
        $inner->register('screen_draft', $operation->description, $operation->inputSchema, $operation->handler, new ToolOptions(scopes: $operation->scopes, mutating: true));
        return new TrialAwareRegistry($inner, new TrialRouter($this->root, new TrialRunner(bwrap: '/unavailable'), '/unused'), $operations, $sessions, $sessions === null ? null : 's-draft', fn () => $this->drafts);
    }

    private function context(): ToolContext
    {
        return new ToolContext(principal: 'resident', channel: 'test', scopes: ['milpa:component:screen-review:draft']);
    }

    private function arguments(): array
    {
        return ['name' => 'tasks', 'type' => 'board', 'props' => ['density' => 'comfortable', 'locale' => 'en']];
    }

    private function execute(array $args): ToolResult
    {
        return ToolResult::success(((new ScreenDraftOperations($this->drafts))->operations()[0]->handler)($args));
    }

    private function save(array $args): EffectObservation
    {
        $observer = ScreenDraftObservation::prepare(fn () => $this->drafts, $args);
        return $observer->observe($this->execute($args));
    }
}
