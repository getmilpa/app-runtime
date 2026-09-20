<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\AuthoringDiagnostic;
use Milpa\AppRuntime\Agent\FileEffectObserver;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\DevTools\Operations\ImplementationBody;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Operations\StaticAnalysisFindings;
use Milpa\DevTools\Support\RootResolver;
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

    /** @return array<string, mixed> */
    private function staticResult(string $body = self::BODY): array
    {
        $result = $this->rejectedResult();
        $receipt = &$result['diagnostic'];
        $receipt['phase'] = 'static-analysis';
        unset($receipt['selector'], $receipt['selector_sha256']);
        $receipt['submitted_sha256'] = hash('sha256', $body);
        $receipt['judged_sha256'] = hash('sha256', ImplementationBody::normalize($body, self::SUBJECT));
        $findings = [['message' => 'Unknown contract.', 'identifier' => 'class.notFound', 'line' => 7]];
        $receipt['result'] = ['exit' => 1, 'errors' => 1, 'findings' => $findings, 'fingerprint' => StaticAnalysisFindings::fingerprint($findings)];
        return $result;
    }

    public function testStaticNoveltyUsesFindingsWhileBodyHashStillBindsAttribution(): void
    {
        $before = FileEffectObserver::trialSnapshot($this->workspace);
        $prepared = AuthoringDiagnostic::prepare('implement', $this->arguments(), $this->workspace, $before, self::PATHS);
        self::assertNotNull($prepared);
        $result = $this->staticResult();
        $ids = $prepared->identities($before, $before, $result, 1);
        self::assertCount(1, $ids);
        $effect = FileEffectObserver::compare($before, $before, 'proposal', diagnostics: $ids);
        self::assertSame([], $effect->artifacts);
        self::assertSame([], $effect->evidence);

        $body = self::BODY . '// Only a comment.';
        $comment = AuthoringDiagnostic::prepare('implement', [...$this->arguments(), 'content' => $body], $this->workspace, $before, self::PATHS);
        self::assertNotNull($comment);
        self::assertSame([], $comment->identities($before, $before, $result, 1), 'The old body receipt cannot attribute the new proposal.');
        $commentResult = $this->staticResult($body);
        $commentResult['diagnostic']['result']['findings'][0]['line'] = 90;
        self::assertSame($ids, $comment->identities($before, $before, $commentResult, 1), 'A newly attributed body with the same findings is not new information.');
        $changed = $result;
        $changed['diagnostic']['result']['findings'][0]['message'] = 'A different contract.';
        self::assertSame([], $prepared->identities($before, $before, $changed, 1), 'The advertised fingerprint must be recomputed.');
        $changed['diagnostic']['result']['fingerprint'] = StaticAnalysisFindings::fingerprint($changed['diagnostic']['result']['findings']);
        $changedIds = $prepared->identities($before, $before, $changed, 1);
        self::assertCount(1, $changedIds);
        self::assertNotSame($ids, $changedIds);

        $other = TrialWorkspace::materialize($this->root, 'w-static-other', $this->root . '/' . self::SUBJECT);
        file_put_contents($other->copy . '/' . self::SUBJECT . '.milpa-part', self::BODY);
        $staged = FileEffectObserver::trialSnapshot($other);
        $finish = AuthoringDiagnostic::prepare('implement', ['plugin' => 'Demo', 'class' => 'Greet', 'mode' => 'finish'], $other, $staged, self::PATHS);
        self::assertSame($ids, $finish?->identities($staged, $staged, $result, 1));
        file_put_contents($other->copy . '/criterion.txt', 'A different observed input');
        $newState = FileEffectObserver::trialSnapshot($other);
        $otherPrepared = AuthoringDiagnostic::prepare('implement', $this->arguments(), $other, $newState, self::PATHS);
        $otherIds = $otherPrepared?->identities($newState, $newState, $result, 1);
        self::assertCount(1, $otherIds);
        self::assertNotSame($ids, $otherIds);
    }

    public function testStaticReceiptsRequireCompleteResultBindingRollbackAndAuthority(): void
    {
        $before = FileEffectObserver::trialSnapshot($this->workspace);
        $prepared = AuthoringDiagnostic::prepare('implement', $this->arguments(), $this->workspace, $before, self::PATHS);
        self::assertNotNull($prepared);
        foreach (['subject', 'submitted_sha256', 'judged_sha256', 'restored_sha256', 'schema', 'phase', 'stable_subject', 'rolled_back'] as $field) {
            $bad = $this->staticResult();
            $bad['diagnostic'][$field] = 'unrelated';
            self::assertSame([], $prepared->identities($before, $before, $bad, 1), $field);
        }
        foreach (['exit' => 124, 'errors' => '1', 'findings' => [], 'fingerprint' => 'invented'] as $field => $value) {
            $bad = $this->staticResult();
            $bad['diagnostic']['result'][$field] = $value;
            self::assertSame([], $prepared->identities($before, $before, $bad, 1), $field);
        }
        $bad = $this->staticResult();
        $bad['diagnostic']['result']['errors'] = 0;
        $bad['diagnostic']['result']['findings'] = [];
        self::assertSame([], $prepared->identities($before, $before, $bad, 1));
        self::assertSame([], $prepared->identities($before, $before, ['ok' => false, 'error' => 'Older producer static refusal'], 1));
        self::assertSame([], $prepared->identities($before, $before, $this->staticResult(), 77));
        self::assertSame([], $prepared->identities($before, [...$before, self::SUBJECT => hash('sha256', 'changed')], $this->staticResult(), 1));
        self::assertNull(AuthoringDiagnostic::prepare('implement', $this->arguments(), $this->workspace, $before, ['tests/Plugins/Demo']));
    }

    public function testStaticJudgmentNeedsNoBehavioralSelectorButBehaviorStillDoes(): void
    {
        unlink($this->workspace->copy . '/' . self::TEST);
        $before = FileEffectObserver::trialSnapshot($this->workspace);
        $prepared = AuthoringDiagnostic::prepare('implement', $this->arguments(), $this->workspace, $before, self::PATHS);
        self::assertNotNull($prepared);
        self::assertCount(1, $prepared->identities($before, $before, $this->staticResult(), 1));
        self::assertSame([], $prepared->identities($before, $before, $this->rejectedResult(), 1));
        foreach (['one', 'two'] as $dir) {
            mkdir($this->workspace->copy . '/tests/Plugins/Demo/' . $dir, 0o700, true);
            file_put_contents($this->workspace->copy . '/tests/Plugins/Demo/' . $dir . '/GreetTest.php', '<?php // judge');
        }
        $ambiguous = FileEffectObserver::trialSnapshot($this->workspace);
        $prepared = AuthoringDiagnostic::prepare('implement', $this->arguments(), $this->workspace, $ambiguous, self::PATHS);
        self::assertCount(1, $prepared?->identities($ambiguous, $ambiguous, $this->staticResult(), 1));
        self::assertSame([], $prepared?->identities($ambiguous, $ambiguous, $this->rejectedResult(), 1));
    }

    /** @return array<string, mixed> */
    private function syntaxResult(string $body): array
    {
        return (new ImplementHandler(new RootResolver($this->workspace->copy)))->handle([
            'plugin' => 'Demo', 'class' => 'Greet', 'content' => $body,
        ]);
    }

    public function testSyntaxInformationSurvivesTransportButNotBodyLineOrCommentChurn(): void
    {
        $body = str_replace('class Greet {}', 'class Greet { public function broken( }', self::BODY);
        $before = FileEffectObserver::trialSnapshot($this->workspace);
        $prepare = fn (string $content): ?AuthoringDiagnostic => AuthoringDiagnostic::prepare('implement', [...$this->arguments(), 'content' => $content], $this->workspace, $before, self::PATHS);
        $result = $this->syntaxResult($body);
        $ids = $prepare($body)?->identities($before, $before, $result, 1);
        self::assertCount(1, $ids);
        $effect = FileEffectObserver::compare($before, $before, 'proposal', diagnostics: $ids);
        self::assertSame([], $effect->artifacts);
        self::assertSame([], $effect->evidence);
        $shift = str_replace('<?php', "<?php\n// Shift only.", $body);
        self::assertSame([], $prepare($shift)?->identities($before, $before, $result, 1), 'An old receipt cannot attribute different submitted bytes.');
        self::assertSame($ids, $prepare($shift)?->identities($before, $before, $this->syntaxResult($shift), 1));
        $different = str_replace('class Greet', 'class Greet ?', $body);
        $differentIds = $prepare($different)?->identities($before, $before, $this->syntaxResult($different), 1);
        self::assertCount(1, $differentIds);
        self::assertNotSame($ids, $differentIds);

        $other = TrialWorkspace::materialize($this->root, 'w-syntax-other', $this->root . '/' . self::SUBJECT);
        file_put_contents($other->copy . '/' . self::SUBJECT . '.milpa-part', $body);
        $staged = FileEffectObserver::trialSnapshot($other);
        $finish = AuthoringDiagnostic::prepare('implement', ['plugin' => 'Demo', 'class' => 'Greet', 'mode' => 'finish'], $other, $staged, self::PATHS);
        self::assertSame($ids, $finish?->identities($staged, $staged, $result, 1));
        file_put_contents($other->copy . '/criterion.txt', 'Different observed input');
        $changed = FileEffectObserver::trialSnapshot($other);
        $context = AuthoringDiagnostic::prepare('implement', [...$this->arguments(), 'content' => $body], $other, $changed, self::PATHS);
        self::assertNotSame($ids, $context?->identities($changed, $changed, $result, 1));
    }

    public function testSyntaxCannotCreditFabricatedRollbackResultsOrUnboundPreservation(): void
    {
        $body = str_replace('class Greet {}', 'class Greet ?', self::BODY);
        unlink($this->workspace->copy . '/' . self::TEST);
        $before = FileEffectObserver::trialSnapshot($this->workspace);
        $arguments = [...$this->arguments(), 'content' => $body];
        $prepared = AuthoringDiagnostic::prepare('implement', $arguments, $this->workspace, $before, self::PATHS);
        self::assertNotNull($prepared);
        $result = $this->syntaxResult($body);
        self::assertCount(1, $prepared->identities($before, $before, $result, 1), 'Syntax does not need a behavioral selector.');
        foreach (['schema', 'phase', 'subject', 'submitted_sha256', 'judged_sha256', 'preserved_sha256', 'stable_subject', 'candidate_installed', 'destination_preserved'] as $field) {
            $bad = $result;
            $bad['diagnostic'][$field] = 'unrelated';
            self::assertSame([], $prepared->identities($before, $before, $bad, 1), $field);
        }
        foreach (['rolled_back' => true, 'restored_sha256' => $before[self::SUBJECT]] as $field => $value) {
            $bad = $result;
            $bad['diagnostic'][$field] = $value;
            self::assertSame([], $prepared->identities($before, $before, $bad, 1), 'No fictitious restoration');
        }
        foreach (['parser', 'message', 'line', 'fingerprint', 'extra'] as $field) {
            $bad = $result;
            $bad['diagnostic']['result'][$field] = 'invented';
            self::assertSame([], $prepared->identities($before, $before, $bad, 1), $field);
        }
        $bad = $result;
        $bad['diagnostic']['result'] = null;
        self::assertSame([], $prepared->identities($before, $before, $bad, 1));
        $valid = AuthoringDiagnostic::prepare('implement', $this->arguments(), $this->workspace, $before, self::PATHS);
        self::assertSame([], $valid?->identities($before, $before, $result, 1));
        self::assertSame([], $prepared->identities($before, [...$before, self::SUBJECT => hash('sha256', 'changed')], $result, 1));
        self::assertSame([], $prepared->identities($before, $before, $result, 77));
        self::assertSame([], $prepared->identities($before, $before, ['ok' => false, 'error' => 'Unstructured lint error'], 1));
        self::assertNull(AuthoringDiagnostic::prepare('implement', $arguments, $this->workspace, $before, ['tests/Plugins/Demo']));
    }
}
