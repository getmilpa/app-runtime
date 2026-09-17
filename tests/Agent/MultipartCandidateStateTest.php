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

/** A multipart finish changes one artifact and consumes its exact staged source. */
final class MultipartCandidateStateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-multipart-candidate-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Plugins/Owned/Services', 0755, true);
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        foreach ([
            'pending' => 'pending', 'promoted' => 'promoted',
            'start' => 'indeterminate', 'append' => 'indeterminate', 'edit' => 'indeterminate',
            'missing-cleanup' => 'indeterminate', 'wrong-sibling' => 'indeterminate',
            'extra-file' => 'indeterminate', 'changed-cleanup' => 'indeterminate',
            'missing-baseline' => 'contradicted', 'different-staged-bytes' => 'contradicted',
            'changed-staging' => 'contradicted', 'staging-still-in-copy' => 'contradicted',
            'promoted-staging-returned' => 'contradicted', 'promoted-source-changed' => 'contradicted',
            'promoted-input-changed' => 'contradicted', 'receipt-drops-cleanup' => 'contradicted',
            'promoted-staging-link' => 'indeterminate', 'partial-result' => 'indeterminate',
        ] as $name => $state) {
            yield $name => [$name, $state];
        }
    }

    #[DataProvider('cases')]
    public function testOnlyVerifiedFinishWithExactConsumedStagingIsACandidate(string $name, string $expectedState): void
    {
        $path = 'src/Plugins/Owned/Services/BoardRenderer.php';
        $staging = $path . '.milpa-part';
        $id = 'w1234567890abcdef';
        $source = "<?php // Verified complete renderer\n";
        file_put_contents($this->root . '/' . $path, "<?php // Empty scaffold\n");
        file_put_contents($this->root . '/' . $staging, $name === 'different-staged-bytes' ? 'other source' : $source);
        file_put_contents($this->root . '/input.php', '<?php // Fixed domain');
        if ($name === 'missing-baseline') {
            unlink($this->root . '/' . $staging);
        }
        $ws = TrialWorkspace::materialize($this->root, $id, dirname(__DIR__, 2) . '/resources/trial-run.php');
        file_put_contents($ws->copy . '/' . $path, $source);
        if (is_file($ws->copy . '/' . $staging)) {
            unlink($ws->copy . '/' . $staging);
        }
        $report = [$path => ['status' => 'modified', 'sha256' => hash('sha256', $source)],
            $staging => ['status' => 'deleted', 'sha256' => null]];
        $args = ['plugin' => 'Owned', 'class' => 'BoardRenderer', 'mode' => 'finish'];
        $operation = 'implement';
        switch ($name) {
            case 'start': case 'append': $args['mode'] = $name;
                break;
            case 'edit': $operation = 'edit';
                break;
            case 'missing-cleanup': unset($report[$staging]);
                break;
            case 'wrong-sibling': $report['src/Plugins/Owned/Services/Other.php.milpa-part'] = $report[$staging];
                unset($report[$staging]);
                break;
            case 'extra-file': $report['input.php'] = ['status' => 'modified', 'sha256' => hash('sha256', 'changed')];
                break;
            case 'changed-cleanup': $report[$staging] = ['status' => 'modified', 'sha256' => hash('sha256', 'other')];
                break;
            case 'changed-staging': file_put_contents($this->root . '/' . $staging, 'changed');
                break;
            case 'staging-still-in-copy': file_put_contents($ws->copy . '/' . $staging, $source);
                break;
        }
        $out = ['ok' => true, 'file' => $path, 'verified' => 'syntax and behavior'];
        if ($name === 'partial-result') {
            unset($out['verified']);
            $out['partial'] = 'staged, not verified';
        }
        $raw = json_encode(['workspace' => $id, 'ran_in_trial' => true, 'applied' => false, 'output' => $out,
            'changed' => array_map(static fn ($row) => $row['status'], $report),
            'to_apply' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $id]]], JSON_THROW_ON_ERROR);
        $events = [new Event('agent-session:multipart', 'session.trial_run_recorded', ['workspace' => $id, 'operation' => $operation,
            'exit' => 0, 'arguments_digest' => EffectObservation::argumentsDigest($args), 'report' => $report], 1, null),
            new Event('agent-session:multipart', 'session.tool_called', ['tool' => $operation, 'arguments' => $args, 'result' => $raw,
                'resultChars' => mb_strlen($raw), 'ok' => true, 'awaitingConfirmation' => false], 2, null)];
        if (str_starts_with($name, 'promoted') || $name === 'receipt-drops-cleanup') {
            file_put_contents($this->root . '/' . $path, $source);
            unlink($this->root . '/' . $staging);
            $receipt = $report;
            if ($name === 'receipt-drops-cleanup') {
                unset($receipt[$staging]);
            }
            file_put_contents($ws->baseDirectory() . '/promoted.json', json_encode($receipt, JSON_THROW_ON_ERROR));
            $ws->collapse();
            if ($name === 'promoted-staging-returned') {
                file_put_contents($this->root . '/' . $staging, $source);
            } elseif ($name === 'promoted-source-changed') {
                file_put_contents($this->root . '/' . $path, 'changed');
            } elseif ($name === 'promoted-input-changed') {
                file_put_contents($this->root . '/input.php', 'changed');
            } elseif ($name === 'promoted-staging-link') {
                symlink($this->root . '/' . $path, $this->root . '/' . $staging);
            }
        }
        $before = $this->tree();
        $actual = CandidateState::read($this->root, $events, $id);
        self::assertSame($expectedState, $actual['state'], $actual['reason']);
        self::assertSame($actual, CandidateState::read($this->root, $events, $id));
        self::assertSame($before, $this->tree(), 'Reading must not consume, restore or change files');
        self::assertSame('not_evaluated', $actual['authorization']);
        if (in_array($expectedState, ['pending', 'promoted'], true)) {
            self::assertSame(['path' => $path, 'sha256' => hash('sha256', $source)], $actual['artifact']);
            self::assertSame($expectedState === 'pending', $actual['next']['requiresRecheck'] ?? false);
        }
    }

    /** @return array<string, string> */
    private function tree(): array
    {
        clearstatcache(true);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[$file->getPathname()] = $file->isLink() ? 'link:' . readlink($file->getPathname()) : hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return $files;
    }
}
