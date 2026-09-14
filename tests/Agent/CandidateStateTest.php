<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\EffectObservation;
use Milpa\AppRuntime\Agent\CandidateState;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\EventStore\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Filesystem/event controls; these synthetic receipts do not claim application execution. */
final class CandidateStateTest extends TestCase
{
    private string $world;

    protected function setUp(): void
    {
        $this->world = sys_get_temp_dir() . '/milpa-candidate-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::remove($this->world);
    }

    /** @return iterable<string, array{string, array{string, string}}> */
    public static function cases(): iterable
    {
        $cases = self::expectations();
        foreach ($cases as $name => $expected) {
            yield $name => [$name, $expected];
        }
    }

    /** @return array<string, array{string, string}> */
    private static function expectations(): array
    {
        return [
            'pending' => ['pending', 'candidate_and_baseline_match'],
            'promoted' => ['promoted', 'receipt_and_host_match'],
            'candidate-changed' => ['contradicted', 'candidate_bytes_changed'],
            'host-changed' => ['contradicted', 'recorded_input_changed'],
            'dependency-changed' => ['contradicted', 'recorded_input_changed'],
            'new-input' => ['contradicted', 'copied_inputs_changed'],
            'receipt-changed' => ['contradicted', 'promotion_receipt_mismatch'],
            'receipt-broken' => ['indeterminate', 'unreadable_json'],
            'copy-missing' => ['indeterminate', 'candidate_and_promotion_missing'],
            'manifest-missing' => ['indeterminate', 'unreadable_file'],
            'manifest-broken' => ['indeterminate', 'unreadable_json'],
            'manifest-empty' => ['indeterminate', 'baseline_unreadable'],
            'manifest-escape' => ['indeterminate', 'unsafe_path'],
            'target-symlink' => ['indeterminate', 'unsafe_path'],
            'receipt-symlink' => ['indeterminate', 'unsafe_path'],
            'unreadable' => ['indeterminate', 'unreadable_file'],
            'trial-missing' => ['indeterminate', 'trial_receipt_missing'],
            'call-missing' => ['indeterminate', 'producer_result_missing'],
            'arguments-changed' => ['contradicted', 'arguments_mismatch'],
            'verification-missing' => ['indeterminate', 'verification_not_declared'],
            'trial-red' => ['contradicted', 'trial_failed'],
            'transport-failed' => ['indeterminate', 'verification_not_declared'],
            'next-changed' => ['contradicted', 'continuation_mismatch'],
            'hash-changed' => ['contradicted', 'candidate_bytes_changed'],
            'multiple-files' => ['indeterminate', 'single_file_candidate_not_proven'],
            'promoted-host-changed' => ['contradicted', 'promoted_bytes_changed'],
            'promoted-dependency-changed' => ['contradicted', 'recorded_input_changed'],
            'promoted-copy-present' => ['indeterminate', 'promotion_not_settled'],
            'invalid-workspace' => ['indeterminate', 'invalid_location'],
            'mixed-stream' => ['indeterminate', 'invalid_stream'],
            'unordered-stream' => ['indeterminate', 'invalid_stream'],
            'truncated-result' => ['indeterminate', 'producer_result_incomplete'],
            'invalid-result-type' => ['indeterminate', 'producer_result_missing'],
            'invalid-hash-type' => ['indeterminate', 'single_file_candidate_not_proven'],
            'self-read' => ['pending', 'candidate_and_baseline_match'],
        ];
    }

    /** @param array{string, string} $expected */
    #[DataProvider('cases')]
    public function testCurrentBytesAndReceiptsDetermineTheState(string $name, array $expected): void
    {
        $root = dirname(__DIR__, 2);
        $id = 'w1234567890abcdef';
        $target = 'src/Plugins/Owned/Services/FocusCounterView.php';
        $original = "<?php // Before\n";
        $p = ['known' => "<?php // After\n"];
        $args = ['plugin' => 'Owned', 'class' => 'FocusCounterView'];
        $run = ['type' => 'session.trial_run_recorded', 'seq' => 1, 'payload' => ['workspace' => $id, 'operation' => 'edit',
            'exit' => 0, 'arguments_digest' => EffectObservation::argumentsDigest($args),
            'report' => [$target => ['status' => 'modified', 'sha256' => hash('sha256', $p['known'])]]]];
        $result = json_encode(['workspace' => $id, 'ran_in_trial' => true, 'applied' => false,
            'output' => ['ok' => true, 'verified' => 'syntax only', 'file' => $target],
            'changed' => [$target => 'modified'], 'to_apply' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $id]]], JSON_THROW_ON_ERROR);
        $originalRows = [$run, ['type' => 'session.tool_called', 'seq' => 2, 'payload' => ['tool' => 'edit', 'arguments' => $args,
            'result' => $result, 'resultChars' => mb_strlen($result), 'ok' => true, 'awaitingConfirmation' => false]]];
        $world = $this->world;
        mkdir($world . '/src/Plugins/Owned/Services', 0755, true);
        mkdir($world . '/tests/Plugins/Owned', 0755, true);
        file_put_contents($world . '/' . $target, $original);
        file_put_contents($world . '/tests/Plugins/Owned/FocusCounterViewTest.php', "<?php // Fixed input to the reader fixture.\n");
        $ws = TrialWorkspace::materialize($world, $id, $root . '/resources/trial-run.php');
        file_put_contents($ws->copy . '/' . $target, $p['known']);
        $rows = $originalRows;
        $report = $run['payload']['report'];
        $manifestFile = $ws->baseDirectory() . '/manifest.json';
        $receiptFile = $ws->baseDirectory() . '/promoted.json';
        if (str_starts_with($name, 'promoted') || str_starts_with($name, 'receipt-')) {
            file_put_contents($world . '/' . $target, $p['known']);
            file_put_contents($receiptFile, json_encode($report, JSON_THROW_ON_ERROR));
            if ($name !== 'promoted-copy-present') {
                $ws->collapse();
            }
        }
        switch ($name) {
            case 'candidate-changed': file_put_contents($ws->copy . '/' . $target, "\n// Moved\n", FILE_APPEND);
                break;
            case 'host-changed': case 'promoted-host-changed': file_put_contents($world . '/' . $target, "\n// Moved\n", FILE_APPEND);
                break;
            case 'dependency-changed': case 'promoted-dependency-changed': file_put_contents($world . '/tests/Plugins/Owned/FocusCounterViewTest.php', "\n// Moved\n", FILE_APPEND);
                break;
            case 'new-input': file_put_contents($world . '/new.php', '<?php');
                break;
            case 'receipt-changed': $report[$target]['sha256'] = str_repeat('0', 64);
                file_put_contents($receiptFile, json_encode($report));
                break;
            case 'receipt-broken': file_put_contents($receiptFile, '{');
                break;
            case 'copy-missing': $ws->collapse();
                break;
            case 'manifest-missing': unlink($manifestFile);
                break;
            case 'manifest-broken': file_put_contents($manifestFile, '{');
                break;
            case 'manifest-empty': file_put_contents($manifestFile, '{}');
                break;
            case 'manifest-escape': $m = $ws->manifest();
                $m['../sentinel'] = str_repeat('0', 64);
                file_put_contents($manifestFile, json_encode($m));
                break;
            case 'target-symlink': unlink($ws->copy . '/' . $target);
                symlink($world . '/' . $target, $ws->copy . '/' . $target);
                break;
            case 'receipt-symlink': unlink($receiptFile);
                symlink($manifestFile, $receiptFile);
                break;
            case 'unreadable': chmod($ws->copy . '/' . $target, 0000);
                break;
        }
        foreach ($rows as $i => &$row) {
            if ($row['type'] === 'session.trial_run_recorded') {
                if ($name === 'trial-missing') {
                    unset($rows[$i]);
                    continue;
                }
                if ($name === 'trial-red') {
                    $row['payload']['exit'] = 1;
                }
                if ($name === 'hash-changed') {
                    $row['payload']['report'][$target]['sha256'] = str_repeat('0', 64);
                }
                if ($name === 'invalid-hash-type') {
                    $row['payload']['report'][$target]['sha256'] = [];
                }
                if ($name === 'multiple-files') {
                    $row['payload']['report']['another.php'] = ['status' => 'added', 'sha256' => str_repeat('0', 64)];
                }
            }
            if ($row['type'] === 'session.tool_called' && $row['payload']['tool'] === 'edit') {
                if ($name === 'call-missing') {
                    unset($rows[$i]);
                    continue;
                }
                if ($name === 'arguments-changed') {
                    $row['payload']['arguments']['plugin'] = 'Other';
                }
                if ($name === 'transport-failed') {
                    $row['payload']['ok'] = false;
                }
                if ($name === 'verification-missing' || $name === 'next-changed') {
                    $data = json_decode($row['payload']['result'], true);
                    if ($name === 'verification-missing') {
                        unset($data['output']['verified']);
                    } else {
                        $data['to_apply']['arguments']['workspace'] = 'wrong';
                    }
                    $row['payload']['result'] = json_encode($data);
                    $row['payload']['resultChars'] = mb_strlen($row['payload']['result']);
                }
                if ($name === 'truncated-result') {
                    $row['payload']['resultChars'] += 1;
                }
                if ($name === 'invalid-result-type') {
                    $row['payload']['result'] = [];
                }
            }
        }
        unset($row);
        $events = array_map(static fn ($r) => new Event('agent-session:candidate-positive', $r['type'], $r['payload'], $r['seq'], null), array_values($rows));
        if ($name === 'self-read') {
            $events[] = new Event('agent-session:candidate-positive', 'session.tool_called', ['tool' => 'candidate_state', 'result' => json_encode(['workspace' => $id, 'state' => 'pending'])], end($events)->seq + 1, null);
        }
        if ($name === 'mixed-stream') {
            $e = $events[0];
            $events[0] = new Event('other', $e->type, $e->payload, $e->seq, null);
        }
        if ($name === 'unordered-stream') {
            $events = array_reverse($events);
        }
        $before = $this->tree($world);
        $result = CandidateState::read($world, $events, $name === 'invalid-workspace' ? '../outside' : $id);
        $repeat = CandidateState::read($world, $events, $name === 'invalid-workspace' ? '../outside' : $id);
        $unchanged = $before === $this->tree($world);
        self::assertSame($expected, [$result['state'], $result['reason']]);
        self::assertSame($result, $repeat);
        self::assertTrue($unchanged);
        self::assertSame('not_evaluated', $result['authorization']);
        self::assertSame($expected[0] === 'pending', ($result['next']['requiresRecheck'] ?? false));
        if ($result['verification'] !== null) {
            self::assertSame(['scope' => 'producer_declaration', 'detail' => 'syntax only'], $result['verification']);
        }
    }
    private function tree(string $root): array
    {
        clearstatcache(true);
        $result = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $name = substr($file->getPathname(), strlen($root) + 1);
            $result[$name] = [$file->getPerms(), $file->isLink() ? readlink($file->getPathname()) : ($file->isFile() && $file->isReadable() ? hash_file('sha256', $file->getPathname()) : null)];
        }
        ksort($result);
        return $result;
    }

    /** Remove only the disposable fixture created by this test. */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $file) {
            self::remove($file->getPathname());
        }
        rmdir($path);
    }
}
