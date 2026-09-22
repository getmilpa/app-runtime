<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\AuthoringInputWitness;
use Milpa\AppRuntime\Agent\SterileLoopGuard;
use Milpa\AppRuntime\Agent\TestInputWitness;
use Milpa\AppRuntime\Agent\TrialInputAttempt;
use Milpa\AppRuntime\Agent\TrialInputObserver;
use Milpa\AppRuntime\Agent\TrialInputWitness;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A repaired prerequisite changes admission without erasing the old failing input. */
final class AuthoringInputWitnessTest extends TestCase
{
    private string $root;
    private string $part = 'src/Plugins/Owned/Services/Body.php.milpa-part';
    private array $args = ['plugin' => 'Owned', 'class' => 'Renderer', 'mode' => 'append', 'content' => 'next section'];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/authoring-input-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Plugins/Owned/Services', 0700, true);
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

    public function testMaterialRepairPermitsContinuationAndUndoFindsItsOldFailures(): void
    {
        $guard = new SterileLoopGuard();
        $this->failTwice($guard, $this->witness());
        self::assertNotNull($guard->motivoParaNoRepetir('implement', $this->args));
        file_put_contents($this->root . '/README.md', 'unrelated change');
        $guard->anota('sandbox_promote', ['workspace' => 'unrelated'], '{"ok":true}', true);
        self::assertNotNull($guard->motivoParaNoRepetir('implement', $this->args));
        file_put_contents($this->root . '/' . $this->part, 'first section');
        self::assertNull($guard->motivoParaNoRepetir('implement', $this->args));
        $attempt = $this->attempt();
        $partial = $this->observation($attempt);
        $partial['status'] = 'partial';
        $guard->anota('implement', $this->args, '{"ok":true}', true, AuthoringInputWitness::fromObservation($attempt, $partial));
        self::assertNull($guard->motivoParaNoRepetir('implement', $this->args));
        unlink($this->root . '/' . $this->part);
        self::assertNotNull($guard->motivoParaNoRepetir('implement', $this->args));
    }

    public function testUnknownAndAbsentObservationsKeepTheConservativeHistory(): void
    {
        foreach ([null, AuthoringInputWitness::unknown($this->attempt())] as $witness) {
            $guard = new SterileLoopGuard();
            $this->failTwice($guard, $witness);
            file_put_contents($this->root . '/' . $this->part, 'new');
            self::assertNotNull($guard->motivoParaNoRepetir('implement', $this->args));
            $guard->anota('implement', $this->args, '{"ok":true}', true, $this->witness());
            self::assertNotNull($guard->motivoParaNoRepetir('implement', $this->args));
            unlink($this->root . '/' . $this->part);
        }
    }

    public function testPartialAndUnknownSuccessCannotClearKnownFailures(): void
    {
        $guard = new SterileLoopGuard();
        $this->failTwice($guard, $this->witness());
        $attempt = $this->attempt();
        $record = $this->observation($attempt);
        $record['status'] = 'partial';
        foreach ([AuthoringInputWitness::unknown($attempt), AuthoringInputWitness::fromObservation($attempt, $record)] as $witness) {
            $guard->anota('implement', $this->args, '{"ok":true}', true, $witness);
            self::assertNotNull($guard->motivoParaNoRepetir('implement', $this->args));
        }
    }

    public function testSuccessOnOneKnownInputDoesNotForgetAnotherVersion(): void
    {
        $guard = new SterileLoopGuard();
        $this->failTwice($guard, $this->witness());
        file_put_contents($this->root . '/' . $this->part, 'present');
        $present = $this->witness();
        $this->failTwice($guard, $present);
        self::assertNotNull($guard->motivoParaNoRepetir('implement', $this->args));
        $guard->anota('implement', $this->args, '{"ok":true}', true, $present);
        self::assertNull($guard->motivoParaNoRepetir('implement', $this->args));
        unlink($this->root . '/' . $this->part);
        self::assertNotNull($guard->motivoParaNoRepetir('implement', $this->args));
    }

    public function testAChangedCallCannotBorrowTheWitness(): void
    {
        foreach (['tool', 'arguments'] as $change) {
            $guard = new SterileLoopGuard();
            $tool = $change === 'tool' ? 'test' : 'implement';
            $args = $change === 'arguments' ? [...$this->args, 'content' => 'different'] : $this->args;
            $witness = $this->witness();
            $guard->anota($tool, $args, 'failed', false, $witness);
            $guard->anota($tool, $args, 'failed', false, $witness);
            file_put_contents($this->root . '/' . $this->part, 'present');
            self::assertNotNull($guard->motivoParaNoRepetir($tool, $args));
            unlink($this->root . '/' . $this->part);
        }
    }

    public function testTheTestContractDoesNotAdmitAuthoringEvidence(): void
    {
        $attempt = $this->attempt();
        self::assertSame('unknown', TestInputWitness::fromObservation($attempt, $this->observation($attempt))->status);
        $test = new TrialInputAttempt('test', $this->root, $this->root . '/copy', 'test', ['path' => 'test.php']);
        self::assertSame('unknown', AuthoringInputWitness::fromObservation($test, $this->observation($test))->status);
    }

    /** @return iterable<string, array{string, string}> */
    public static function continuations(): iterable
    {
        foreach (['append', 'amend', 'finish'] as $mode) {
            foreach (['src', 'tests'] as $tree) {
                yield $mode . '-' . $tree => [$mode, $tree];
            }
        }
    }

    #[DataProvider('continuations')]
    public function testOnlyTheConsultedStagingPathIsRequiredNotAGuessedClassFilename(string $mode, string $tree): void
    {
        $args = [...$this->args, 'mode' => $mode];
        $attempt = $this->attempt($args);
        $record = $this->observation($attempt);
        $record['inputs'] = [$tree . '/Plugins/Owned/Nested/DifferentFile.php.milpa-part' => ['facets' => ['presence'], 'before' => ['kind' => 'missing']]];
        $witness = AuthoringInputWitness::fromObservation($attempt, $record);
        self::assertSame('known', $witness->status);
        self::assertInstanceOf(TrialInputWitness::class, $witness);
        self::assertCount(1, $witness->inputs);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidObservations(): iterable
    {
        foreach (['id', 'copy', 'operation', 'arguments', 'scope', 'complete', 'mode', 'inline', 'start',
                  'plugin-array', 'class-array', 'plugin-path', 'class-path', 'foreign-plugin', 'no-stage',
                  'two-stages', 'no-presence', 'entry-array', 'facets-array', 'traversal'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidObservations')]
    public function testUncorrelatedOrOutOfScopeInputsStayUnknown(string $case): void
    {
        $args = $this->args;
        if (in_array($case, ['mode', 'inline', 'start'], true)) {
            $args['mode'] = $case;
        } elseif ($case === 'plugin-array') {
            $args['plugin'] = [];
        } elseif ($case === 'class-array') {
            $args['class'] = [];
        } elseif ($case === 'plugin-path') {
            $args['plugin'] = '../Owned';
        } elseif ($case === 'class-path') {
            $args['class'] = 'Owned/Renderer';
        }
        $attempt = $this->attempt($args);
        $record = $this->observation($attempt);
        if (in_array($case, ['id', 'copy', 'operation', 'scope'], true)) {
            $record[$case] = 'foreign';
        } elseif ($case === 'arguments') {
            $record['arguments']['content'] = 'foreign';
        } elseif ($case === 'complete') {
            $record['complete_execution_inputs'] = true;
        } elseif (in_array($case, ['foreign-plugin', 'no-stage', 'traversal'], true)) {
            $path = match ($case) {
                'foreign-plugin' => 'src/Plugins/Other/Body.php.milpa-part',
                'no-stage' => 'src/Plugins/Owned/Services/Body.php',
                default => 'src/Plugins/Owned/../Other/Body.php.milpa-part',
            };
            $record['inputs'] = [$path => $record['inputs'][$this->part]];
        } elseif ($case === 'two-stages') {
            $record['inputs']['src/Plugins/Owned/Services/Other.php.milpa-part'] = $record['inputs'][$this->part];
        } elseif ($case === 'no-presence') {
            $record['inputs'][$this->part]['facets'] = ['content'];
        } elseif ($case === 'entry-array') {
            $record['inputs'][$this->part] = 'invalid';
        } elseif ($case === 'facets-array') {
            $record['inputs'][$this->part]['facets'] = 'presence';
        }
        self::assertSame('unknown', AuthoringInputWitness::fromObservation($attempt, $record)->status);
    }

    public function testASymlinkCannotProveThatTheMissingInputChanged(): void
    {
        $witness = $this->witness();
        file_put_contents($this->root . '/target.php', 'present');
        symlink($this->root . '/target.php', $this->root . '/' . $this->part);
        self::assertNull($witness->matchesCurrent());
        $guard = new SterileLoopGuard();
        $this->failTwice($guard, $witness);
        self::assertNotNull($guard->motivoParaNoRepetir('implement', $this->args));
    }

    public function testTheNativeObservationWindowConsumesAuthoringEvidenceExactlyOnce(): void
    {
        $router = new TrialRouter($this->root, new TrialRunner(), 'unused');
        $witness = $this->witness();
        $router->beginInputCall('s', 'implement', $this->args);
        $router->recordInputCall('s', 'implement', $this->args, $witness);
        self::assertSame($witness, $router->takeInputCall('s', 'implement', $this->args));
        self::assertNull($router->takeInputCall('s', 'implement', $this->args));
        $router->beginInputCall('s', 'implement', $this->args);
        $router->recordInputCall('s', 'implement', $this->args, $witness);
        $router->recordInputCall('s', 'implement', $this->args, $witness);
        self::assertNull($router->takeInputCall('s', 'implement', $this->args));
    }

    public function testTheRunnerUsesTheAuthoringContractAndKeepsObserverFailuresUnknown(): void
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('Native trial confinement required.');
        }
        $stub = $this->root . '/runner.php';
        file_put_contents($stub, '<?php echo json_encode(["ok"=>false,"error"=>"fixture missing","input_witness"=>["status"=>"known"]]);exit(1);');
        $workspace = TrialWorkspace::materialize($this->root, 'observed', $stub);
        $observer = new class ($this) implements TrialInputObserver {
            public string $mode = 'known';
            private array $record = [];

            public function __construct(private AuthoringInputWitnessTest $owner)
            {
            }

            public function before(TrialInputAttempt $attempt): void
            {
                if ($this->mode === 'before_throw') {
                    throw new \RuntimeException('Unavailable');
                }
                if ($this->mode !== 'replay') {
                    $this->record = $this->owner->observation($attempt);
                }
            }

            public function after(TrialInputAttempt $attempt, int $exit): array
            {
                if ($this->mode === 'after_throw') {
                    throw new \RuntimeException('Lost');
                }
                return $this->record;
            }
        };
        $runner = new TrialRunner(inputObserver: $observer);
        $run = $runner->run($workspace, 'implement', $this->args);
        self::assertSame(1, $run->exit);
        self::assertInstanceOf(AuthoringInputWitness::class, $run->inputWitness);
        self::assertSame('known', $run->inputWitness->status);
        foreach (['replay', 'before_throw', 'after_throw'] as $mode) {
            $observer->mode = $mode;
            $run = $runner->run($workspace, 'implement', $this->args);
            self::assertSame(1, $run->exit);
            self::assertSame('unknown', $run->inputWitness?->status, $mode);
        }
        self::assertNull((new TrialRunner())->run($workspace, 'implement', $this->args)->inputWitness);
        self::assertNull($runner->run($workspace, 'unrelated', $this->args)->inputWitness);
    }

    /** Host-supplied fixture observations test transport and admission, not discovery of consultations. */
    public function observation(TrialInputAttempt $attempt): array
    {
        $state = is_file($this->root . '/' . $this->part) ? ['kind' => 'file'] : ['kind' => 'missing'];

        return ['id' => $attempt->id, 'copy' => $attempt->copy, 'operation' => $attempt->operation,
            'arguments' => $attempt->arguments, 'scope' => TrialInputWitness::SCOPE,
            'complete_execution_inputs' => false, 'status' => 'known',
            'inputs' => [$this->part => ['facets' => ['presence'], 'before' => $state]]];
    }

    private function attempt(?array $arguments = null): TrialInputAttempt
    {
        return new TrialInputAttempt(bin2hex(random_bytes(8)), $this->root, $this->root . '/copy', 'implement', $arguments ?? $this->args);
    }

    private function witness(): AuthoringInputWitness
    {
        $attempt = $this->attempt();

        return AuthoringInputWitness::fromObservation($attempt, $this->observation($attempt));
    }

    private function failTwice(SterileLoopGuard $guard, ?TrialInputWitness $witness): void
    {
        $guard->anota('implement', $this->args, 'missing staging', false, $witness);
        $guard->anota('implement', $this->args, 'missing staging', false, $witness);
    }
}
