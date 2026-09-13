<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\SterileLoopGuard;
use Milpa\AppRuntime\Agent\TestInputWitness;
use Milpa\AppRuntime\Agent\TrialInputAttempt;
use Milpa\AppRuntime\Agent\TrialInputObserver;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use PHPUnit\Framework\TestCase;

/** Failure histories must follow observed inputs without being reset by another version. */
final class WitnessedFailuresTest extends TestCase
{
    private string $root;
    private array $args = ['path' => 'test.php'];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/witness-guard-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        file_put_contents($this->root . '/test.php', 'immutable test');
        file_put_contents($this->root . '/source.php', 'bad');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testRepairAndSuccessPreserveTheOldFailureHistory(): void
    {
        $guard = new SterileLoopGuard();
        $bad = $this->witness();
        $this->failTwice($guard, $bad);
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args));
        file_put_contents($this->root . '/unrelated.php', 'new');
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args));
        file_put_contents($this->root . '/source.php', 'fixed');
        self::assertNull($guard->motivoParaNoRepetir('test', $this->args));
        $guard->anota('test', $this->args, '{"ok":true}', true, $this->witness());
        self::assertNull($guard->motivoParaNoRepetir('test', $this->args));
        file_put_contents($this->root . '/source.php', 'bad');
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args));
        $guard->olvidar();
        self::assertNull($guard->motivoParaNoRepetir('test', $this->args));
    }

    public function testEveryObservedFailureVariantRetainsItsOwnCount(): void
    {
        $guard = new SterileLoopGuard();
        $guard->anota('test', $this->args, 'failed', false, $this->witness());
        file_put_contents($this->root . '/source.php', 'also bad');
        $this->failTwice($guard, $this->witness());
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args));
        file_put_contents($this->root . '/source.php', 'bad');
        self::assertNull($guard->motivoParaNoRepetir('test', $this->args));
        $guard->anota('test', $this->args, 'failed again', false, $this->witness());
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args));
    }

    public function testUnknownFailuresCannotBeBypassedByAChangedFile(): void
    {
        $guard = new SterileLoopGuard();
        $this->failTwice($guard, TestInputWitness::unknown($this->attempt()));
        file_put_contents($this->root . '/source.php', 'fixed');
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args));
        $guard->anota('test', $this->args, '{"ok":true}', true, $this->witness());
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args), 'A known success cannot erase unlocated failures');
    }

    public function testUnknownAndPartialSuccessCannotEraseKnownFailures(): void
    {
        $guard = new SterileLoopGuard();
        $this->failTwice($guard, $this->witness());
        $guard->anota('test', $this->args, '{"ok":true}', true, TestInputWitness::unknown($this->attempt()));
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args));
        $record = $this->record($this->attempt());
        $record['status'] = 'partial';
        $attempt = $this->attempt();
        $record['id'] = $attempt->id;
        $guard->anota('test', $this->args, '{"ok":true}', true, TestInputWitness::fromObservation($attempt, $record));
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args));
    }

    public function testSuccessOnlyClearsTheExactKnownInputAndCountsMatchingProjections(): void
    {
        $guard = new SterileLoopGuard();
        $witness = $this->witness();
        $guard->anota('test', $this->args, 'failed', false, $witness);
        $guard->anota('test', $this->args, '{"ok":true}', true, $witness);
        $guard->anota('test', $this->args, 'failed', false, $witness);
        self::assertNull($guard->motivoParaNoRepetir('test', $this->args));
        file_put_contents($this->root . '/extra.php', 'another consulted input');
        $attempt = $this->attempt();
        $record = $this->record($attempt);
        $record['inputs']['extra.php'] = ['facets' => ['content'], 'before' => ['kind' => 'file', 'sha256' => hash_file('sha256', $this->root . '/extra.php')]];
        $guard->anota('test', $this->args, 'failed', false, TestInputWitness::fromObservation($attempt, $record));
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args), 'An expanded observed set does not lose a matching failure');
    }

    public function testCrossedCaptureAndUnsafePathsCannotProduceKnownInputs(): void
    {
        $attempt = $this->attempt();
        foreach (['id', 'copy', 'operation', 'arguments', 'scope', 'complete_execution_inputs'] as $key) {
            $record = $this->record($attempt);
            $record[$key] = 'crossed';
            self::assertSame('unknown', TestInputWitness::fromObservation($attempt, $record)->status, $key);
        }
        foreach (['../secret', '/absolute', 'vendor/pkg.php', 'a/../test.php', 'a//b'] as $path) {
            $record = $this->record($attempt);
            $record['inputs'][$path] = $record['inputs']['test.php'];
            self::assertSame('unknown', TestInputWitness::fromObservation($attempt, $record)->status, $path);
        }
        $witness = $this->witness();
        unlink($this->root . '/source.php');
        symlink($this->root . '/test.php', $this->root . '/source.php');
        self::assertNull($witness->matchesCurrent());
    }

    public function testUnknownCurrentStateRetainsTheFailuresEvenIfAnotherInputChanged(): void
    {
        $guard = new SterileLoopGuard();
        $this->failTwice($guard, $this->witness());
        file_put_contents($this->root . '/test.php', 'changed');
        unlink($this->root . '/source.php');
        symlink($this->root . '/test.php', $this->root . '/source.php');
        self::assertNotNull($guard->motivoParaNoRepetir('test', $this->args));
    }

    public function testTheTrustedChannelIsBoundToSessionArgumentsAndOneUse(): void
    {
        $router = new TrialRouter($this->root, new TrialRunner(), '/unused');
        $witness = $this->witness();
        $router->recordInputCall('s', 'test', $this->args, $witness);
        self::assertNull($router->takeInputCall('s', 'test', $this->args));
        $router->beginInputCall('s', 'test', $this->args);
        $router->recordInputCall('other', 'test', $this->args, $witness);
        self::assertNull($router->takeInputCall('s', 'test', $this->args));
        $router->beginInputCall('s', 'test', $this->args);
        $router->recordInputCall('s', 'test', $this->args, $witness);
        self::assertSame($witness, $router->takeInputCall('s', 'test', $this->args));
        self::assertNull($router->takeInputCall('s', 'test', $this->args));
        $router->beginInputCall('s', 'test', $this->args);
        $router->recordInputCall('s', 'test', $this->args, $witness);
        $router->beginInputCall('s', 'test', $this->args);
        self::assertNull($router->takeInputCall('s', 'test', $this->args), 'A new call clears an unconsumed previous observation');
        $router->beginInputCall('s', 'test', $this->args);
        $router->recordInputCall('s', 'test', $this->args, $witness);
        $router->recordInputCall('s', 'test', $this->args, $witness);
        self::assertNull($router->takeInputCall('s', 'test', $this->args), 'Two executions cannot attest one call');
    }

    public function testMalformedObservationsCannotBeUsedAsKnownInputs(): void
    {
        $attempt = $this->attempt();
        foreach ([null, [], 'text', ['test.php' => 'text'], ['test.php' => []],
            ['test.php' => ['facets' => [], 'before' => ['kind' => 'file']]],
            ['test.php' => ['facets' => [['nested']], 'before' => ['kind' => 'file']]],
            ['test.php' => ['facets' => ['content'], 'before' => ['kind' => 'symlink']]],
            ['test.php' => ['facets' => ['content'], 'before' => ['kind' => 'file', 'sha256' => 'invalid']]],
            ['test.php' => ['facets' => ['members'], 'before' => ['kind' => 'directory']]],
            ['test.php' => ['facets' => ['members'], 'before' => ['kind' => 'directory', 'members' => ['../escape']]]],
        ] as $inputs) {
            $record = $this->record($attempt);
            $record['inputs'] = $inputs;
            $witness = TestInputWitness::fromObservation($attempt, $record);
            self::assertSame('unknown', $witness->status);
            self::assertNull($witness->matchesCurrent());
        }
        $record = $this->record($attempt);
        $record['status'] = 'future-status';
        self::assertSame('unknown', TestInputWitness::fromObservation($attempt, $record)->status);
    }

    public function testPresenceAbsenceAndDirectoryMembersAreDifferentFacets(): void
    {
        $attempt = $this->attempt();
        $record = $this->record($attempt);
        $record['inputs']['missing.php'] = ['facets' => ['presence'], 'before' => ['kind' => 'missing']];
        $record['inputs']['.'] = ['facets' => ['presence'], 'before' => ['kind' => 'directory']];
        $presence = TestInputWitness::fromObservation($attempt, $record);
        self::assertTrue($presence->matchesCurrent());
        file_put_contents($this->root . '/unrelated', 'noise');
        self::assertTrue($presence->matchesCurrent());
        file_put_contents($this->root . '/missing.php', 'present');
        self::assertFalse($presence->matchesCurrent());
        unlink($this->root . '/missing.php');
        self::assertTrue($presence->matchesCurrent());
        $record['inputs']['.'] = ['facets' => ['members'], 'before' => ['kind' => 'directory', 'members' => ['unrelated', 'test.php', 'source.php']]];
        $members = TestInputWitness::fromObservation($attempt, $record);
        self::assertSame('known', $members->status);
        self::assertTrue($members->matchesCurrent());
        mkdir($this->root . '/var');
        self::assertTrue($members->matchesCurrent(), 'Excluded top-level state does not enter membership');
        file_put_contents($this->root . '/another', 'new member');
        self::assertFalse($members->matchesCurrent());
    }

    public function testAChangedInputCannotHideAnUnreadableOrSpecialInput(): void
    {
        $witness = $this->witness();
        file_put_contents($this->root . '/test.php', 'changed');
        chmod($this->root . '/source.php', 0000);
        self::assertNull($witness->matchesCurrent());
        chmod($this->root . '/source.php', 0600);
        unlink($this->root . '/source.php');
        posix_mkfifo($this->root . '/source.php', 0600);
        self::assertNull($witness->matchesCurrent());
    }

    public function testTheRunnerCorrelatesTheExternalRecordAndIgnoresStdoutClaims(): void
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('This integration requires native trial confinement.');
        }
        $stub = $this->root . '/runner.php';
        file_put_contents($stub, '<?php echo json_encode(["input_witness"=>["status"=>"known"]]);');
        $workspace = TrialWorkspace::materialize($this->root, 'observed', $stub);
        $owner = $this;
        $observer = new class ($owner) implements TrialInputObserver {
            public string $mode = 'known';
            public array $seen = [];
            private array $record = [];

            public function __construct(private WitnessedFailuresTest $owner)
            {
            }

            public function before(TrialInputAttempt $attempt): void
            {
                $this->seen[] = ['before', $attempt->id];
                if ($this->mode === 'before_throw') {
                    throw new \RuntimeException('Unavailable');
                }
                if ($this->mode !== 'replay') {
                    $this->record = $this->owner->observation($attempt);
                }
            }

            public function after(TrialInputAttempt $attempt, int $exit): array
            {
                $this->seen[] = ['after', $attempt->id, $exit];
                if ($this->mode === 'after_throw') {
                    throw new \RuntimeException('Lost observation');
                }
                return $this->record;
            }
        };
        $runner = new TrialRunner(inputObserver: $observer);
        $run = $runner->run($workspace, 'test', $this->args);
        self::assertTrue($run->ok());
        self::assertSame('known', $run->inputWitness?->status);
        self::assertSame('before', $observer->seen[0][0]);
        self::assertSame(['after', $observer->seen[0][1], 0], $observer->seen[1]);
        foreach (['replay', 'before_throw', 'after_throw'] as $mode) {
            $observer->mode = $mode;
            $run = $runner->run($workspace, 'test', $this->args);
            self::assertTrue($run->ok());
            self::assertSame('unknown', $run->inputWitness?->status, $mode);
        }
        self::assertNull((new TrialRunner())->run($workspace, 'test', $this->args)->inputWitness);
        self::assertNull($runner->run($workspace, 'another-operation', $this->args)->inputWitness);
    }

    /** A supplied observer fixture; this test measures transport, not consultation discovery. */
    public function observation(TrialInputAttempt $attempt): array
    {
        return $this->record($attempt);
    }

    private function failTwice(SterileLoopGuard $guard, TestInputWitness $witness): void
    {
        $guard->anota('test', $this->args, 'failed', false, $witness);
        $guard->anota('test', $this->args, 'failed', false, $witness);
    }

    private function attempt(): TrialInputAttempt
    {
        return new TrialInputAttempt(bin2hex(random_bytes(8)), $this->root, $this->root . '/copy', 'test', $this->args);
    }

    private function witness(): TestInputWitness
    {
        $attempt = $this->attempt();

        return TestInputWitness::fromObservation($attempt, $this->record($attempt));
    }

    private function record(TrialInputAttempt $attempt): array
    {
        $inputs = [];
        foreach (['source.php', 'test.php'] as $path) {
            $inputs[$path] = ['facets' => ['content'], 'before' => ['kind' => 'file', 'sha256' => hash_file('sha256', $this->root . '/' . $path)]];
        }

        return ['id' => $attempt->id, 'operation' => 'test', 'arguments' => $this->args, 'copy' => $attempt->copy,
            'scope' => TestInputWitness::SCOPE, 'complete_execution_inputs' => false, 'status' => 'known', 'inputs' => $inputs];
    }
}
