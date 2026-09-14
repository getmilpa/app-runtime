<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\EffectObservation;
use Milpa\AppRuntime\Agent\{AcceptanceEvidence, TrialWorkspace, FileEffectObserver};
use Milpa\AppRuntime\Web\{ScreenBuild, ScreenDrafts, ScreenStore};
use Milpa\EventStore\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic receipts exercise the native collectors; fresh application evidence lives in greenhouse 0698. */
final class AcceptanceEvidenceTest extends TestCase
{
    private string $root;
    private array $rows;
    private ScreenDrafts $drafts;
    private string $candidate = 'w1234567890abcdef';
    private string $trial = 'wabcdef1234567890';
    private array $scope = ['path' => 'tests/Plugins/Owned', 'filter' => ''];
    private array $screen = ['name' => 'focus', 'type' => 'focus-counter'];
    private string $target = 'src/View.php';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-acceptance-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0755, true);
        mkdir($this->root . '/tests/Plugins/Owned', 0755, true);
        file_put_contents($this->root . '/' . $this->target, '<?php // before');
        file_put_contents($this->root . '/tests/Plugins/Owned/ViewTest.php', '<?php // judge');
        $ws = TrialWorkspace::materialize($this->root, $this->candidate, dirname(__DIR__, 2) . '/resources/trial-run.php');
        $bytes = '<?php // after';
        file_put_contents($this->root . '/' . $this->target, $bytes);
        $report = [$this->target => ['status' => 'modified', 'sha256' => hash('sha256', $bytes)]];
        file_put_contents($ws->baseDirectory() . '/promoted.json', json_encode($report));
        $ws->collapse();
        $args = ['plugin' => 'Owned', 'class' => 'View'];
        $this->rows = [['type' => 'session.trial_run_recorded', 'payload' => ['operation' => 'edit', 'workspace' => $this->candidate,
            'exit' => 0, 'report' => $report, 'arguments_digest' => EffectObservation::argumentsDigest($args)]]];
        $this->call('edit', $args, ['workspace' => $this->candidate, 'ran_in_trial' => true, 'applied' => false,
            'output' => ['ok' => true, 'verified' => 'declared judgment', 'file' => $this->target],
            'changed' => [$this->target => 'modified'], 'to_apply' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $this->candidate]]]);
        $test = TrialWorkspace::materialize($this->root, $this->trial, dirname(__DIR__, 2) . '/resources/trial-run.php');
        $out = ['ok' => true, 'ran' => true, 'tests' => 11, 'assertions' => 274, 'failures' => 0, 'errors' => 0, 'output' => 'PHPUnit'];
        $this->rows[] = ['type' => 'session.trial_run_recorded', 'payload' => ['operation' => 'test', 'workspace' => $this->trial,
            'exit' => 0, 'report' => [], 'arguments_digest' => EffectObservation::argumentsDigest($this->scope), 'output_digest' => $this->digest($out)]];
        $this->rows[] = ['type' => 'session.effect_observed', 'payload' => ['tool' => 'test', 'argumentsDigest' => EffectObservation::argumentsDigest($this->scope),
            'observation' => ['producer' => 'app-runtime/file-effects/v1', 'schema' => 'milpa.agent.effect-observation/v1', 'known' => true,
                'artifacts' => [], 'evidence' => FileEffectObserver::testEvidence('test', $this->scope, FileEffectObserver::trialSnapshot($test), $out)]]];
        $this->call('test', $this->scope, ['workspace' => $this->trial, 'ran_in_trial' => true, 'applied' => false, 'changed' => [], 'output' => $out]);
        $this->rows[4]['payload']['effectObservationSeq'] = 4;
        $this->drafts = new ScreenDrafts(new ScreenStore($this->root . '/var/screens.json'), $this->root . '/var/screen-drafts', static function (): void {
        }, (new ScreenBuild($this->root))->fingerprint(...));
        $draft = $this->drafts->draft('focus', 'focus-counter', ['goal' => 6]);
        $this->call('screen_draft', ['name' => 'focus', 'type' => 'focus-counter', 'props' => ['goal' => 6]], ['ok' => true, 'result' => $draft]);
        $this->call('screen_review', ['revision' => $draft['id']], ['ok' => true, 'result' => $this->drafts->review($draft['id'])]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        foreach (['positive' => 'current_evidence', 'failed' => 'failed', 'exception' => 'failed', 'not-run' => 'failed',
            'unknown-counts' => 'failed', 'zero-counts' => 'failed', 'empty-output' => 'failed', 'runner-error' => 'failed',
            'no-test' => 'incomplete', 'no-review' => 'incomplete', 'partial' => 'incomplete',
            'candidate-change' => 'historical_evidence', 'test-change' => 'historical_evidence', 'new-test' => 'historical_evidence',
            'new-source' => 'historical_evidence', 'baseline-change' => 'historical_evidence', 'failed-new-test' => 'historical_evidence',
            'failed-test-change' => 'historical_evidence', 'failed-new-source' => 'historical_evidence', 'failed-candidate-change' => 'historical_evidence',
            'failed-baseline-change' => 'historical_evidence', 'refused' => 'indeterminate', 'extra-stdout' => 'indeterminate',
            'truncated' => 'indeterminate', 'wrong-workspace' => 'indeterminate', 'wrong-exit' => 'indeterminate',
            'wrong-effect' => 'indeterminate', 'missing-effect' => 'indeterminate', 'mixed-stream' => 'indeterminate', 'unordered' => 'indeterminate',
            'manifest-broken' => 'indeterminate', 'copy-change' => 'indeterminate', 'revision-broken' => 'indeterminate',
            'wrong-screen' => 'indeterminate', 'wrong-definition' => 'indeterminate', 'invalid-scope' => 'indeterminate',
            'invalid-event' => 'indeterminate', 'invalid-root' => 'indeterminate', 'invalid-workspace' => 'indeterminate',
            'no-drafts-service' => 'indeterminate', 'self-read' => 'current_evidence', 'string-count' => 'indeterminate',
            'negative-count' => 'indeterminate', 'missing-count' => 'indeterminate', 'ran-type' => 'indeterminate',
            'not-run-counts' => 'indeterminate', 'verdict-contradiction' => 'indeterminate'] as $name => $state) {
            yield $name => [$name, $state];
        }
    }

    #[DataProvider('cases')]
    public function testNativeFactsDetermineEvidenceWithoutWriting(string $name, string $state): void
    {
        $failed = str_starts_with($name, 'failed') || in_array($name, ['exception', 'not-run', 'unknown-counts', 'zero-counts', 'empty-output', 'runner-error',
            'string-count', 'negative-count', 'missing-count', 'ran-type', 'not-run-counts', 'verdict-contradiction'], true);
        if ($failed) {
            $d = json_decode($this->rows[4]['payload']['result'], true);
            $out = $d['output'];
            $out['ok'] = false;
            $out['failures'] = 1;
            if ($name === 'exception') {
                $out['failures'] = 0;
                $out['errors'] = 1;
            }
            if (in_array($name, ['not-run', 'unknown-counts', 'zero-counts'], true)) {
                foreach (['tests', 'assertions', 'failures', 'errors'] as $key) {
                    $out[$key] = $name === 'zero-counts' ? 0 : null;
                }
                $out['ran'] = $name !== 'not-run';
            }
            if ($name === 'empty-output') {
                $out = null;
            }
            if ($name === 'runner-error') {
                $out = ['ok' => false, 'error' => 'runner exception'];
            }
            if ($name === 'string-count') {
                $out['tests'] = '11';
            }
            if ($name === 'negative-count') {
                $out['errors'] = -1;
            }
            if ($name === 'missing-count') {
                unset($out['tests']);
            }
            if ($name === 'ran-type') {
                $out['ran'] = 1;
            }
            if ($name === 'not-run-counts') {
                $out['ran'] = false;
            }
            if ($name === 'verdict-contradiction') {
                $out['ok'] = true;
            }
            $d = ['schema' => 'milpa.trial-test-failure/v1', 'ok' => false, 'workspace' => $this->trial, 'ran_in_trial' => true,
                'applied' => false, 'trial_exit' => 1, 'output' => $out, 'stderr' => str_repeat('long diagnostic ', 2000)];
            $this->rows[2]['payload']['exit'] = 1;
            $this->rows[2]['payload']['output_digest'] = $this->digest($out);
            $this->rows[3]['payload']['observation']['evidence'] = [];
            $this->rows[4]['payload']['ok'] = false;
            $this->replaceResult(4, $d);
        }
        $physical = str_starts_with($name, 'failed-') ? substr($name, 7) : $name;
        switch ($physical) {
            case 'candidate-change': file_put_contents($this->root . '/' . $this->target, 'changed');
                break;
            case 'test-change': file_put_contents($this->root . '/tests/Plugins/Owned/ViewTest.php', 'changed');
                break;
            case 'new-test': file_put_contents($this->root . '/tests/Plugins/Owned/NewTest.php', 'new');
                break;
            case 'new-source': file_put_contents($this->root . '/src/New.php', 'new');
                break;
            case 'baseline-change': (new ScreenStore($this->root . '/var/screens.json'))->declare(['name' => 'focus', 'type' => 'focus-counter', 'props' => ['goal' => 9]]);
                break;
            case 'manifest-broken': file_put_contents($this->root . '/var/trials/' . $this->trial . '/manifest.json', '{');
                break;
            case 'copy-change': file_put_contents($this->root . '/var/trials/' . $this->trial . '/copy/' . $this->target, 'changed');
                break;
            case 'revision-broken': $id = $this->rows[6]['payload']['arguments']['revision'];
                file_put_contents($this->root . '/var/screen-drafts/' . $id . '.json', '{}');
                break;
        }
        switch ($name) {
            case 'no-test': unset($this->rows[2], $this->rows[3], $this->rows[4]);
                break;
            case 'no-review': unset($this->rows[6]);
                break;
            case 'partial': $this->scope['filter'] = 'OneTest';
                break;
            case 'refused': unset($this->rows[2], $this->rows[3]);
                $this->rows[4]['payload']['ok'] = false;
                $this->rows[4]['payload']['result'] = 'refused';
                $this->rows[4]['payload']['resultChars'] = 7;
                break;
            case 'extra-stdout': $this->rows[2]['payload']['output_digest'] = hash('sha256', 'extra stdout');
                break;
            case 'truncated': ++$this->rows[4]['payload']['resultChars'];
                break;
            case 'wrong-workspace': $this->rows[2]['payload']['workspace'] = 'w000000000000';
                break;
            case 'wrong-exit': $this->rows[2]['payload']['exit'] = 1;
                break;
            case 'wrong-effect': $this->rows[4]['payload']['effectObservationSeq'] = 1;
                break;
            case 'missing-effect': unset($this->rows[3]);
                break;
            case 'wrong-screen': $this->screen['type'] = 'other';
                break;
            case 'wrong-definition': $this->screen['definition'] = ['type' => 'focus-counter', 'props' => ['goal' => 8]];
                break;
            case 'invalid-scope': $this->scope['path'] = [];
                break;
            case 'self-read': $this->call('acceptance_evidence', [], ['state' => 'current_evidence']);
                break;
        }
        $events = [];
        foreach ($this->rows as $i => $row) {
            $events[] = new Event($name === 'mixed-stream' && $i === 0 ? 'other' : 's1', $row['type'], $row['payload'], $i + 1, null);
        }
        if ($name === 'unordered') {
            $events = array_reverse($events);
        }
        if ($name === 'invalid-event') {
            $events[] = [];
        }
        $before = $this->tree();
        $read = fn () => AcceptanceEvidence::read(
            $name === 'invalid-root' ? $this->root . '/.' : $this->root,
            $events,
            $name === 'invalid-workspace' ? '../escape' : $this->candidate,
            $this->scope,
            $this->screen,
            $name === 'no-drafts-service' ? null : $this->drafts
        );
        $r = $read();
        self::assertSame($state, $r['state'], json_encode($r));
        self::assertSame($r, $read());
        self::assertSame($before, $this->tree());
        self::assertSame('not_evaluated', $r['authorization']);
        self::assertSame('not_recorded', $r['humanApproval']);
        if (isset($r['test']['receipt'])) {
            $raw = $this->rows[4]['payload']['result'];
            self::assertSame(['toolCallSeq' => 5, 'characters' => mb_strlen($raw), 'sha256' => hash('sha256', $raw)], $r['test']['receipt']);
            self::assertArrayNotHasKey('output', $r['test']);
            self::assertArrayNotHasKey('stderr', $r['test']);
            self::assertArrayNotHasKey('diagnostic', $r['test']);
        }
        if ($name === 'failed') {
            self::assertSame(1, $r['test']['failures']);
            self::assertLessThan(8000, mb_strlen(json_encode($r)));
        }
        if ($name === 'unknown-counts') {
            self::assertNull($r['test']['tests']);
        }
        if ($name === 'zero-counts') {
            self::assertSame(0, $r['test']['tests']);
        }
        if ($name === 'not-run') {
            self::assertSame('not_run', $r['test']['outcome']);
            self::assertFalse($r['test']['ran']);
        }
    }

    private function call(string $name, array $args, array $result): void
    {
        $raw = json_encode($result, JSON_THROW_ON_ERROR);
        $this->rows[] = ['type' => 'session.tool_called', 'payload' => ['tool' => $name, 'arguments' => $args, 'ok' => true,
            'awaitingConfirmation' => false, 'result' => $raw, 'resultChars' => mb_strlen($raw)]];
    }
    private function replaceResult(int $i, array $d): void
    {
        $this->rows[$i]['payload']['result'] = json_encode($d, JSON_THROW_ON_ERROR);
        $this->rows[$i]['payload']['resultChars'] = mb_strlen($this->rows[$i]['payload']['result']);
    }
    private function digest(?array $out): string
    {
        return hash('sha256', $out === null ? '' : json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    private function tree(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $out[$f->getPathname()] = hash_file('sha256', $f->getPathname());
            }
        }
        ksort($out);
        return $out;
    }
    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }
}
