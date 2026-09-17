<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\{EffectObservation, SessionStore};
use Milpa\AppRuntime\Agent\{AcceptanceEvidence, CandidateState, DeliveryClosure, DeliveryExpectation, DeliveryScope, FileEffectObserver, ObservedExecutor, TrialWorkspace};
use Milpa\AppRuntime\Web\{ScreenBuild, ScreenDrafts, ScreenStore};
use Milpa\EventStore\{Event, InMemoryEventStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native-shaped receipts test composition without claiming execution or semantic UI coverage. */
final class CompositionDeliveryTest extends TestCase
{
    private string $root;
    private InMemoryEventStore $events;
    private SessionStore $store;
    private ScreenDrafts $drafts;
    private const ITEM = 'w1111111111111111';
    private const BOARD = 'w2222222222222222';
    private const TEST = 'w3333333333333333';
    private const MEMBERS = ['src/Board.php', 'src/Item.php'];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-composition-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0755, true);
        mkdir($this->root . '/tests', 0755, true);
        foreach (self::MEMBERS as $path) {
            file_put_contents($this->root . '/' . $path, '<?php // scaffold');
        }
        file_put_contents($this->root . '/tests/CompositionTest.php', '<?php // complete judge');
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'Build the complete screen');
        DeliveryExpectation::record($this->events, 's', self::target(), ObservedExecutor::unknown());
        $this->produce(self::ITEM, 'src/Item.php');
        $this->produce(self::BOARD, 'src/Board.php');
        $this->testReceipt();
        $this->drafts = new ScreenDrafts(new ScreenStore($this->root . '/var/screens.json'), $this->root . '/var/screen-drafts', static function (): void {
        }, (new ScreenBuild($this->root))->fingerprint(...));
        $draft = $this->drafts->draft('todos', 'todo-board', []);
        $this->call('screen_draft', ['name' => 'todos', 'type' => 'todo-board'], json_encode(['ok' => true, 'result' => $draft]));
        $this->call('screen_review', ['revision' => $draft['id']], json_encode(['ok' => true, 'result' => $this->drafts->review($draft['id'])]));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** A prior member's stale producer environment is not represented as a current candidate. */
    public function testWholeCompositionClosesAndBindingSurvivesNewReaders(): void
    {
        self::assertSame('recorded_input_changed', CandidateState::read($this->root, $this->rows(), self::ITEM)['reason']);
        $before = $this->rows();
        $e = $this->evidence();
        self::assertSame('current_evidence', $e['state'], json_encode($e));
        self::assertSame(self::MEMBERS, $e['scope']['members']);
        self::assertCount(2, $e['test']['members']);
        self::assertSame($before, $this->rows());
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::BOARD, ObservedExecutor::unknown());
        $bound = DeliveryScope::read($this->rows(), 's');
        self::assertCount(2, $bound['binding']['members']);
        self::assertTrue($this->closure()['verified'], json_encode($this->closure()));
        $store = new SessionStore($this->events);
        self::assertSame($bound, DeliveryScope::read($store->stream('s'), 's'));
        $before = $store->stream('s');
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::BOARD, ObservedExecutor::unknown());
        self::assertSame($before, $this->rows());
        self::assertSame('not_recorded', $this->closure()['humanApproval']);
        self::assertSame('not_observed', $this->closure()['browserBehavior']);
    }

    /** @return iterable<string,array{string}> */
    public static function physicalFailures(): iterable
    {
        foreach (['item-bytes', 'board-bytes', 'receipt', 'manifest', 'copy', 'judge', 'new-input', 'symlink', 'unsettled', 'draft', 'active', 'other-obligation', 'late-attempt', 'red-judge', 'unpromoted'] as $case) {
            yield $case => [$case];
        }
    }

    /** Previously bound delivery must not mask changed authorities or outstanding work. */
    #[DataProvider('physicalFailures')]
    public function testLaterChangesCannotKeepClosurePositive(string $case): void
    {
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::BOARD, ObservedExecutor::unknown());
        self::assertTrue($this->closure()['verified']);
        switch ($case) {
            case 'unpromoted': unlink($this->root . '/var/trials/' . self::ITEM . '/promoted.json');
                break;
            case 'item-bytes': file_put_contents($this->root . '/src/Item.php', 'changed');
                break;
            case 'board-bytes': file_put_contents($this->root . '/src/Board.php', 'changed');
                break;
            case 'receipt': file_put_contents($this->root . '/var/trials/' . self::ITEM . '/promoted.json', '{}');
                break;
            case 'manifest': file_put_contents($this->root . '/var/trials/' . self::ITEM . '/manifest.json', '{}');
                break;
            case 'copy': file_put_contents($this->root . '/var/trials/' . self::TEST . '/copy/src/Item.php', 'changed');
                break;
            case 'judge': file_put_contents($this->root . '/tests/CompositionTest.php', 'changed');
                break;
            case 'new-input': file_put_contents($this->root . '/src/New.php', 'new');
                break;
            case 'symlink': unlink($this->root . '/src/Item.php');
                symlink($this->root . '/src/Board.php', $this->root . '/src/Item.php');
                break;
            case 'unsettled': mkdir($this->root . '/var/trials/' . self::ITEM . '/copy');
                break;
            case 'draft': $rows = array_values(array_filter($this->rows(), static fn ($e) => ($e->payload['tool'] ?? null) === 'screen_review'));
                $revision = end($rows)->payload['arguments']['revision'];
                file_put_contents($this->root . '/var/screen-drafts/' . $revision . '.json', '{}');
                break;
            case 'active': (new ScreenStore($this->root . '/var/screens.json'))->declare(['name' => 'todos', 'type' => 'todo-board', 'props' => ['changed' => true]]);
                break;
            case 'other-obligation': $this->call('make', ['name' => 'Other'], '{}', mutating: true);
                break;
            case 'late-attempt': $this->call('edit', ['class' => 'Item'], '{"ok":false}', ok: false, mutating: true);
                break;
            case 'red-judge': $this->call('validate', ['target' => 'Item'], '{"ok":false,"checks":{"behavior":false}}', ok: false);
                break;
        }
        self::assertFalse($this->closure()['verified'], $case);
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidMembers(): iterable
    {
        foreach ([null, 'src/Board.php', [], ['path' => 'src/Board.php'], ['src/Board.php', 'src/Board.php'],
            ['../src/Board.php'], ['/src/Board.php'], ['src//Board.php'], ['src/./Board.php'], ['src\\Board.php'],
            ['src/Board.php '], [1], array_map(static fn ($n) => 'src/Member' . $n . '.php', range(1, 33))] as $i => $members) {
            yield (string) $i => [$members];
        }
    }

    /** Membership is explicit, bounded, unique and path-qualified. */
    #[DataProvider('invalidMembers')]
    public function testInvalidMembershipFailsBeforeDeclaration(mixed $members): void
    {
        $input = self::target();
        $input['members'] = $members;
        $before = $this->rows();
        try {
            DeliveryExpectation::parse($input);
            self::fail('Invalid members accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $this->rows());
        }
    }

    /** Caller order does not change set identity; legacy scope cannot introduce unbound members. */
    public function testMembershipNormalizationAndLegacyRefusal(): void
    {
        $input = self::target();
        $input['members'] = array_reverse($input['members']);
        self::assertSame(DeliveryExpectation::parse(self::target()), DeliveryExpectation::parse($input));
        $scope = DeliveryScope::forCandidate($this->root, $this->rows(), 's', self::BOARD)['scope'];
        $events = new InMemoryEventStore();
        $this->expectException(\InvalidArgumentException::class);
        DeliveryScope::record($events, 'legacy', $scope, ObservedExecutor::unknown());
    }

    /** Omitting a written component cannot discharge its independent work obligation. */
    public function testOmittedMemberStillPreventsClosure(): void
    {
        $this->changeExpectation(['src/Board.php']);
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::BOARD, ObservedExecutor::unknown());
        $result = $this->closure();
        self::assertFalse($result['verified']);
        self::assertContains('artifact Item has no current verification', $result['reasons']);
    }

    /** Missing members and selecting a candidate outside the declaration fail before binding. */
    public function testForeignMemberAndForeignAnchorCannotBind(): void
    {
        foreach ([['src/Board.php', 'src/Missing.php'], ['src/Item.php']] as $members) {
            $this->changeExpectation($members);
            $before = $this->rows();
            try {
                DeliveryScope::recordCandidate($this->events, 's', $this->root, self::BOARD, ObservedExecutor::unknown());
                self::fail('Foreign composition accepted');
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                self::assertSame($before, $this->rows());
            }
        }
    }

    /** @return iterable<string,array{string}> */
    public static function bindingFailures(): iterable
    {
        foreach (['missing', 'extra', 'producer', 'workspace', 'bytes', 'baseline', 'receipt', 'invalid-hash', 'member-order', 'late-producer'] as $case) {
            yield $case => [$case];
        }
    }

    /** Durable producer pins and their physical receipt hashes are independently rechecked. */
    #[DataProvider('bindingFailures')]
    public function testAlteredBindingCannotClose(string $case): void
    {
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::BOARD, ObservedExecutor::unknown());
        $this->rewrite(function (Event $e) use ($case): array {
            $p = $e->payload;
            if ($e->type !== DeliveryScope::EVENT) {
                return $p;
            }
            switch ($case) {
                case 'missing': array_pop($p['binding']['members']);
                    break;
                case 'extra': $p['binding']['members'][] = $p['binding']['members'][0];
                    break;
                case 'producer': $p['binding']['members'][1]['toolCallSeq'] = 1;
                    break;
                case 'workspace': $p['binding']['members'][1]['workspace'] = self::BOARD;
                    break;
                case 'bytes': $p['binding']['members'][1]['artifact']['sha256'] = str_repeat('0', 64);
                    break;
                case 'baseline': $p['binding']['members'][1]['baselineSha256'] = str_repeat('0', 64);
                    break;
                case 'receipt': $p['binding']['members'][1]['promotionSha256'] = str_repeat('0', 64);
                    break;
                case 'invalid-hash': $p['binding']['members'][1]['promotionSha256'] = 'wrong';
                    break;
                case 'member-order': $p['binding']['members'] = array_reverse($p['binding']['members']);
                    break;
            }
            return $p;
        });
        if ($case === 'late-producer') {
            $this->produce('w4444444444444444', 'src/Item.php');
        }
        try {
            self::assertFalse($this->closure()['verified']);
        } catch (\UnexpectedValueException) {
            self::assertTrue(true, 'Corrupt durable declaration refused');
        }
    }

    /** A different requested filter cannot reuse the complete-test observation. */
    public function testNarrowTestRequestDoesNotVerifyComposition(): void
    {
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::BOARD, ObservedExecutor::unknown());
        $scope = self::target()['test'];
        $scope['filter'] = 'BoardOnly';
        $evidence = ['ok' => true, 'session' => 's'] + AcceptanceEvidence::read($this->root, $this->rows(), self::BOARD, $scope, self::target()['screen'], $this->drafts);
        self::assertSame('partial', $evidence['test']['state']);
        $contract = ['session' => 's'] + DeliveryScope::read($this->rows(), 's')['scope'];
        self::assertFalse(DeliveryClosure::derive($this->store->load('s'), $this->store->facts('s'), $contract, $evidence)['verified']);
    }

    /** A later partial or red test cannot fall back to the earlier complete green receipt. */
    public function testLatestPartialAndRedAttemptsReplaceThePositiveReceipt(): void
    {
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::BOARD, ObservedExecutor::unknown());
        $this->testReceipt(true, 'w4444444444444444', ['path' => 'tests', 'filter' => 'BoardOnly']);
        self::assertSame('partial', $this->evidence()['test']['state']);
        self::assertFalse($this->closure()['verified']);
        $this->testReceipt(false, 'w5555555555555555');
        self::assertSame('failed', $this->evidence()['state']);
        self::assertFalse($this->closure()['verified']);
    }

    /** Change a synthetic prior declaration, never a measured application's history.
     * @param list<string> $members
     */
    private function changeExpectation(array $members): void
    {
        $this->rewrite(static function (Event $event) use ($members): array {
            $p = $event->payload;
            if ($event->type === DeliveryExpectation::EVENT) {
                $p['expected']['members'] = $members;
                $p['expected'] = DeliveryExpectation::parse($p['expected']);
                $p['sha256'] = hash('sha256', json_encode($p['expected'], JSON_THROW_ON_ERROR));
            }
            return $p;
        });
    }

    /** Replace only this synthetic fixture's authorities. */
    private function rewrite(callable $change): void
    {
        $rows = $this->rows();
        $this->events = new InMemoryEventStore();
        foreach ($rows as $event) {
            $this->events->append(new Event($event->streamId, $event->type, $change($event), $event->seq));
        }
        $this->store = new SessionStore($this->events);
    }

    /** @return array<string,mixed> */
    private static function target(): array
    {
        return ['members' => self::MEMBERS, 'test' => ['path' => 'tests', 'filter' => ''], 'screen' => ['name' => 'todos', 'type' => 'todo-board']];
    }

    /** @return list<Event> */
    private function rows(): array
    {
        return $this->store->stream('s');
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        return ['ok' => true, 'session' => 's'] + AcceptanceEvidence::read($this->root, $this->rows(), self::BOARD, self::target()['test'], self::target()['screen'], $this->drafts);
    }

    /** @return array<string,mixed> */
    private function closure(): array
    {
        $declaration = DeliveryScope::read($this->rows(), 's');
        return DeliveryClosure::derive($this->store->load('s'), $this->store->facts('s'), ['session' => 's'] + $declaration['scope'], $this->evidence());
    }

    /** Produce independently promoted single-file receipts using the real workspace copier. */
    private function produce(string $id, string $path): void
    {
        $ws = TrialWorkspace::materialize($this->root, $id, dirname(__DIR__, 2) . '/resources/trial-run.php');
        $bytes = '<?php // complete ' . $path;
        $report = [$path => ['status' => 'modified', 'sha256' => hash('sha256', $bytes)]];
        file_put_contents($this->root . '/' . $path, $bytes);
        file_put_contents($ws->baseDirectory() . '/promoted.json', json_encode($report));
        $ws->collapse();
        $args = ['class' => basename($path, '.php')];
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.trial_run_recorded', ['workspace' => $id, 'operation' => 'implement', 'exit' => 0, 'report' => $report, 'arguments_digest' => EffectObservation::argumentsDigest($args)], $this->events->nextSeq()));
        $this->call('implement', $args, json_encode(['workspace' => $id, 'ran_in_trial' => true, 'applied' => false,
            'output' => ['ok' => true, 'verified' => 'syntax', 'file' => $path], 'changed' => [$path => 'modified'],
            'to_apply' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $id]]]), mutating: true);
    }

    /** Record the complete fixture response with the native producer's explicit metadata.
     * @param array<string,mixed> $args
     */
    private function call(string $tool, array $args, string $result, bool $ok = true, bool $mutating = false, ?int $effectObservationSeq = null): void
    {
        $this->store->recordToolCall('s', $tool, $args, $result, $ok, $mutating, mb_strlen($result), false, $effectObservationSeq);
    }

    /** Native-shaped complete test evidence pins every copied input.
     * @param array<string,string>|null $args
     */
    private function testReceipt(bool $passed = true, string $id = self::TEST, ?array $args = null): void
    {
        $ws = TrialWorkspace::materialize($this->root, $id, dirname(__DIR__, 2) . '/resources/trial-run.php');
        $args ??= self::target()['test'];
        $out = ['ok' => $passed, 'ran' => true, 'tests' => 9, 'assertions' => 187, 'failures' => $passed ? 0 : 1, 'errors' => 0];
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.trial_run_recorded', ['operation' => 'test', 'workspace' => $id, 'exit' => $passed ? 0 : 1, 'report' => [], 'arguments_digest' => EffectObservation::argumentsDigest($args), 'output_digest' => hash('sha256', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n")], $this->events->nextSeq()));
        $effect = $this->events->nextSeq();
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.effect_observed', ['tool' => 'test', 'argumentsDigest' => EffectObservation::argumentsDigest($args),
            'observation' => ['producer' => 'app-runtime/file-effects/v1', 'schema' => 'milpa.agent.effect-observation/v1', 'known' => true, 'artifacts' => [], 'evidence' => FileEffectObserver::testEvidence('test', $args, FileEffectObserver::trialSnapshot($ws), $out)]], $effect));
        $result = ['workspace' => $id, 'ran_in_trial' => true, 'applied' => false, 'changed' => [], 'output' => $out];
        if (!$passed) {
            $result += ['schema' => 'milpa.trial-test-failure/v1', 'ok' => false, 'trial_exit' => 1, 'stderr' => 'Assertion failed'];
        }
        $this->call('test', $args, json_encode($result), ok: $passed, effectObservationSeq: $effect);
    }
}
