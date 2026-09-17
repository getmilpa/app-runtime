<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\AuthoringDiagnostic;
use Milpa\AppRuntime\Agent\FileEffectObserver;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use PHPUnit\Framework\TestCase;

/** A failed judgment earns information only with its original native subject and inputs. */
final class AuthoringDiagnosticTest extends TestCase
{
    private string $root;
    private TrialWorkspace $workspace;
    private const SUBJECT = 'src/Plugins/Demo/Services/Greet.php';
    private const TEST = 'tests/Plugins/Demo/GreetTest.php';
    private const PATHS = ['src/Plugins/Demo', 'tests/Plugins/Demo'];
    private const BODY = "<?php\ndeclare(strict_types=1);\nnamespace Wrong;\nclass Greet {}\n";

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-authoring-diagnostic-' . bin2hex(random_bytes(5));
        foreach ([self::SUBJECT => '<?php // scaffold', self::TEST => '<?php // judge'] as $path => $content) {
            mkdir($this->root . '/' . dirname($path), 0o700, true);
            file_put_contents($this->root . '/' . $path, $content);
        }
        $this->workspace = TrialWorkspace::materialize($this->root, 'w-diagnostic', $this->root . '/' . self::SUBJECT);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string, mixed> */
    private function arguments(): array
    {
        return ['plugin' => 'Demo', 'class' => 'Greet', 'content' => self::BODY];
    }

    /** @return array<string, mixed> */
    private function rejectedResult(): array
    {
        return ['ok' => false, 'error' => 'Unstructured prose is not the witness.', 'diagnostic' => [
            'schema' => 'milpa.authoring-diagnostic/v1', 'phase' => 'behavior', 'subject' => self::SUBJECT,
            'submitted_sha256' => hash('sha256', self::BODY),
            'judged_sha256' => hash('sha256', str_replace('namespace Wrong;', 'namespace App\\Plugins\\Demo\\Services;', self::BODY)),
            'restored_sha256' => hash('sha256', '<?php // scaffold'),
            'selector' => self::TEST, 'selector_sha256' => hash('sha256', '<?php // judge'),
            'stable_subject' => true, 'rolled_back' => true,
            'result' => ['exit' => 2, 'tests' => 3, 'assertions' => 0, 'failures' => 0, 'errors' => 3],
        ]];
    }

    public function testNoveltyUsesJudgedBodyAndCopiedInputsAcrossWorkspacesAndTransports(): void
    {
        $before = FileEffectObserver::trialSnapshot($this->workspace);
        $prepared = AuthoringDiagnostic::prepare('implement', $this->arguments(), $this->workspace, $before, self::PATHS);
        self::assertNotNull($prepared);
        $ids = $prepared->identities($before, $before, $this->rejectedResult(), 1);
        self::assertCount(1, $ids);
        $effect = FileEffectObserver::compare($before, $before, 'proposal', diagnostics: $ids);
        self::assertSame([], $effect->artifacts);
        self::assertSame([], $effect->evidence);
        $other = TrialWorkspace::materialize($this->root, 'w-other', $this->root . '/' . self::SUBJECT);
        $same = FileEffectObserver::trialSnapshot($other);
        $prose = $this->rejectedResult();
        $prose['error'] = 'A different message, time or workspace cannot renew progress.';
        self::assertSame($ids, AuthoringDiagnostic::prepare('implement', $this->arguments(), $other, $same, self::PATHS)?->identities($same, $same, $prose, 1));
        file_put_contents($other->copy . '/' . self::SUBJECT . '.milpa-part', self::BODY);
        $staged = FileEffectObserver::trialSnapshot($other);
        $finish = AuthoringDiagnostic::prepare('implement', ['plugin' => 'Demo', 'class' => 'Greet', 'mode' => 'finish'], $other, $staged, self::PATHS);
        self::assertSame($ids, $finish?->identities($staged, $staged, $prose, 1));
        file_put_contents($other->copy . '/criterion.txt', 'Changed actual input');
        $changed = FileEffectObserver::trialSnapshot($other);
        self::assertNotSame($ids, AuthoringDiagnostic::prepare('implement', $this->arguments(), $other, $changed, self::PATHS)?->identities($changed, $changed, $prose, 1));
        $assertion = $this->rejectedResult();
        $assertion['diagnostic']['result'] = ['exit' => 1, 'tests' => 3, 'assertions' => 5, 'failures' => 3, 'errors' => 0];
        self::assertSame($ids, $prepared->identities($before, $before, $assertion, 1), 'Changing only a result cannot mint another input.');
    }

    public function testBrokenSubjectResultAndRollbackNeverCreditInformation(): void
    {
        $before = FileEffectObserver::trialSnapshot($this->workspace);
        $prepared = AuthoringDiagnostic::prepare('implement', $this->arguments(), $this->workspace, $before, self::PATHS);
        self::assertNotNull($prepared);
        foreach (['subject', 'submitted_sha256', 'judged_sha256', 'restored_sha256', 'selector', 'selector_sha256', 'schema', 'phase', 'stable_subject', 'rolled_back'] as $field) {
            $bad = $this->rejectedResult();
            $bad['diagnostic'][$field] = 'unrelated';
            self::assertSame([], $prepared->identities($before, $before, $bad, 1), $field);
        }
        foreach (['exit' => 124, 'tests' => 0, 'assertions' => null, 'errors' => 4, 'failures' => -1] as $field => $value) {
            $bad = $this->rejectedResult();
            $bad['diagnostic']['result'][$field] = $value;
            self::assertSame([], $prepared->identities($before, $before, $bad, 1), $field);
        }
        self::assertSame([], $prepared->identities($before, $before, null, 1));
        self::assertSame([], $prepared->identities($before, $before, $this->rejectedResult(), 124));
        self::assertSame([], $prepared->identities(null, null, $this->rejectedResult(), 1));
        self::assertSame([], $prepared->identities($before, [...$before, self::SUBJECT => hash('sha256', 'moved')], $this->rejectedResult(), 1));
    }

    public function testMissingAuthorityOtherToolsAndIncompletePartsCannotPrepareAWitness(): void
    {
        $before = FileEffectObserver::trialSnapshot($this->workspace);
        self::assertNull(AuthoringDiagnostic::prepare('implement', $this->arguments(), $this->workspace, $before, null));
        self::assertNull(AuthoringDiagnostic::prepare('test', $this->arguments(), $this->workspace, $before, self::PATHS));
        foreach ([['mode' => 'start'], ['mode' => 'finish'], ['class' => '../Greet'], ['plugin' => 'Elsewhere'], ['content' => '']] as $change) {
            self::assertNull(AuthoringDiagnostic::prepare('implement', [...$this->arguments(), ...$change], $this->workspace, $before, self::PATHS));
        }
    }
}
