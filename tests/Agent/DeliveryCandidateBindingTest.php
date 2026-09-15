<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\{EffectObservation, SessionStore};
use Milpa\AppRuntime\Agent\{DeliveryExpectation, DeliveryScope, ObservedExecutor, TrialWorkspace};
use Milpa\EventStore\{Event, InMemoryEventStore};
use PHPUnit\Framework\TestCase;

/** Synthetic native-shaped receipts exercise binding; application execution is measured on cattle. */
final class DeliveryCandidateBindingTest extends TestCase
{
    private string $root;
    private InMemoryEventStore $events;
    private const WORKSPACE = 'w1234567890abcdef';
    private const FILE = 'src/View.php';

    /** Prepare only this test's disposable native filesystem and ledger authorities. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-delivery-binding-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0755, true);
        $this->events = new InMemoryEventStore();
        (new SessionStore($this->events))->start('s', 'Build');
        DeliveryExpectation::record($this->events, 's', DeliveryExpectationTest::target(), ObservedExecutor::unknown());
        $this->candidate(self::WORKSPACE, self::FILE);
    }

    /** Remove only the fixture owned by this test. */
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** The same expectation and exact producer survive a new reader and repeated binding. */
    public function testNativeBindingIsImmutableAndIdempotent(): void
    {
        $scope = DeliveryScope::forCandidate($this->root, $this->rows(), 's', self::WORKSPACE);
        self::assertSame(DeliveryExpectation::parse(DeliveryExpectationTest::target())['test'], $scope['scope']['test']);
        self::assertSame(self::FILE, $scope['scope']['artifactPath']);
        self::assertSame(hash('sha256', "<?php // After\n"), $scope['binding']['candidate']['artifact']['sha256']);
        self::assertNull(DeliveryScope::read($this->rows(), 's'));
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::WORKSPACE, ObservedExecutor::unknown());
        $before = $this->rows();
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::WORKSPACE, ObservedExecutor::unknown());
        self::assertSame($before, $this->rows());
        $read = DeliveryScope::read((new SessionStore($this->events))->stream('s'), 's');
        self::assertSame($scope['scope'], $read['scope']);
        self::assertSame($scope['binding'], $read['binding']);
        $this->candidate('w0000000000000000', 'src/Other.php');
        $before = $this->rows();
        try {
            DeliveryScope::recordCandidate($this->events, 's', $this->root, 'w0000000000000000', ObservedExecutor::unknown());
            self::fail('Rebound candidate');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $this->rows());
        }
    }

    /** A caller cannot use legacy delivery to bypass a prior expectation. */
    public function testLegacyDeclarationCannotBypassExpectation(): void
    {
        $scope = DeliveryScope::forCandidate($this->root, $this->rows(), 's', self::WORKSPACE)['scope'];
        $before = $this->rows();
        try {
            DeliveryScope::record($this->events, 's', $scope, ObservedExecutor::unknown());
            self::fail('Expectation bypassed');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $this->rows());
        }
    }

    /** Physical state and native producer identity are read rather than supplied by a caller. */
    public function testMissingForeignOrChangedCandidateIsRefused(): void
    {
        foreach (['missing', 'foreign', 'changed', 'no-expectation', 'pending'] as $case) {
            $rows = $this->rows();
            $workspace = self::WORKSPACE;
            $session = 's';
            if ($case === 'missing') {
                $workspace = 'w9999999999999999';
            }
            if ($case === 'foreign') {
                $session = 'other';
            }
            if ($case === 'changed') {
                file_put_contents($this->root . '/' . self::FILE, 'Changed');
            }
            if ($case === 'no-expectation') {
                $rows = array_values(array_filter($rows, static fn ($e) => $e->type !== DeliveryExpectation::EVENT));
            }
            if ($case === 'pending') {
                unlink($this->root . '/var/trials/' . self::WORKSPACE . '/promoted.json');
            }
            try {
                DeliveryScope::forCandidate($this->root, $rows, $session, $workspace);
                self::fail($case);
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                self::assertTrue(true);
            } finally {
                file_put_contents($this->root . '/' . self::FILE, "<?php // After\n");
            }
        }
    }

    /** A valid content hash on a changed envelope cannot erase the expectation or swap its producer. */
    public function testBoundLedgerCorruptionFailsClosed(): void
    {
        DeliveryScope::recordCandidate($this->events, 's', $this->root, self::WORKSPACE, ObservedExecutor::unknown());
        foreach (['unbound', 'criterion', 'criterion-seq', 'bytes', 'producer', 'narrow', 'missing-expectation', 'later-producer'] as $case) {
            $rows = $this->rows();
            $e = array_pop($rows);
            $p = $e->payload;
            if ($case === 'unbound') {
                unset($p['binding']);
            }
            if ($case === 'criterion') {
                $p['binding']['expectationSha256'] = str_repeat('0', 64);
            }
            if ($case === 'criterion-seq') {
                $p['binding']['expectationSeq'] = 1;
            }
            if ($case === 'bytes') {
                $p['binding']['candidate']['artifact']['sha256'] = str_repeat('0', 64);
            }
            if ($case === 'producer') {
                $p['binding']['candidate']['toolCallSeq'] = 1;
            }
            if ($case === 'narrow') {
                $p['scope']['test']['filter'] = 'OnlyOne';
                $p['sha256'] = hash('sha256', json_encode($p['scope'], JSON_THROW_ON_ERROR));
            }
            if ($case === 'missing-expectation') {
                $rows = array_values(array_filter($rows, static fn ($event) => $event->type !== DeliveryExpectation::EVENT));
            }
            $rows[] = new Event($e->streamId, $e->type, $p, $e->seq);
            if ($case === 'later-producer') {
                $run = $rows[2];
                $rows[] = new Event($run->streamId, $run->type, $run->payload, $e->seq + 1);
            }
            try {
                DeliveryScope::read($rows, 's');
                self::fail($case);
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    /** Current test ledger in its native ordering. */
    private function rows(): array
    {
        return (new SessionStore($this->events))->stream('s');
    }

    /** Use the real workspace and a synthetic edit receipt, without claiming a tool invocation. */
    private function candidate(string $id, string $path): void
    {
        file_put_contents($this->root . '/' . $path, "<?php // Before\n");
        $ws = TrialWorkspace::materialize($this->root, $id, dirname(__DIR__, 2) . '/resources/trial-run.php');
        $report = [$path => ['status' => 'modified', 'sha256' => hash('sha256', "<?php // After\n")]];
        file_put_contents($this->root . '/' . $path, "<?php // After\n");
        file_put_contents($ws->baseDirectory() . '/promoted.json', json_encode($report, JSON_THROW_ON_ERROR));
        $ws->collapse();
        $args = ['path' => $path];
        $this->events->append(new Event(
            SessionStore::PREFIX . 's',
            'session.trial_run_recorded',
            ['workspace' => $id, 'operation' => 'edit', 'exit' => 0,
                'arguments_digest' => EffectObservation::argumentsDigest($args), 'report' => $report],
            $this->events->nextSeq()
        ));
        $result = json_encode(['workspace' => $id, 'ran_in_trial' => true, 'applied' => false,
            'output' => ['ok' => true, 'verified' => 'syntax only', 'file' => $path],
            'changed' => [$path => 'modified'], 'to_apply' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $id]]], JSON_THROW_ON_ERROR);
        $this->events->append(new Event(
            SessionStore::PREFIX . 's',
            'session.tool_called',
            ['tool' => 'edit', 'arguments' => $args, 'result' => $result, 'resultChars' => mb_strlen($result),
                'ok' => true, 'awaitingConfirmation' => false],
            $this->events->nextSeq()
        ));
    }
}
