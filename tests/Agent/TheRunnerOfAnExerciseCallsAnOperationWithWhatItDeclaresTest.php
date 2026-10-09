<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\CapabilityExercise;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Tests\Fixtures\ExercisedTaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE RUNNER OF AN EXERCISE, ON A REAL CAPABILITY (greenhouse decisions/0605, R1): `resources/exercise-run.php`, the
 * script a call of {@see CapabilityExercise} runs from the copy.
 *
 * A capability is built in a house's own tree, as a promotion leaves it, with operations that answer each of the ways
 * an operation can. The runner finds each by the name it declares, calls it with one value of the declared type per
 * required input — read from the declaration, in that process — and says what happened in fields.
 */
final class TheRunnerOfAnExerciseCallsAnOperationWithWhatItDeclaresTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/milpa-exercise-runner-' . bin2hex(random_bytes(6));
        ExercisedTaller::in($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return iterable<string, array{0: string, 1: int, 2: array<string, mixed>}> */
    public static function answers(): iterable
    {
        yield 'it answers data, with no verdict' => ['taller:lista', 0, ['herramientas' => []]];
        yield 'it is called with what it declares' => ['taller:alta', 0, ['ok' => true, 'given' => ['nombre' => '7', 'cantidad' => 7, 'tipo' => 'manual', 'activa' => true]]];
        yield 'it refuses in its own words, and they read like a class' => ['taller:niega', 1, ['ok' => false, 'error' => 'Validation: falta el nombre']];
        yield 'it throws an error of the engine' => ['taller:rota', 1, ['ok' => false, 'error' => 'Error: Call to undefined method MilpaTest\Exercised\Almacen::guardar()', 'thrown' => ['class' => 'Error', 'engine' => true]]];
        yield 'it throws an exception of its own' => ['taller:lanza', 1, ['ok' => false, 'error' => "DomainException: no hay herramienta «7»\nen el almacén", 'thrown' => ['class' => 'DomainException', 'engine' => false]]];
        yield 'its name has an underscore' => ['taller:dar_de_baja', 0, ['ok' => true, 'ran' => 'taller:dar_de_baja']];
        yield 'it answers something that is no list of fields' => ['taller:cuenta', 0, ['answer' => 3]];
        yield 'no capability declares it' => ['taller:no_existe', 1, ['ok' => false, 'error' => 'no operation «taller:no_existe» in this app', 'missing' => true]];
    }

    /** @param array<string, mixed> $said */
    #[DataProvider('answers')]
    public function testWhatTheRunnerSaysOfACall(string $operation, int $exit, array $said): void
    {
        [$code, $answer] = $this->child($operation);

        self::assertSame($said, $answer);
        self::assertSame($exit, $code);
    }

    /**
     * Those two fields are the runner's alone. An operation that could write them would say of itself «not found» —
     * and a capability nobody could find is left unjudged, and closes.
     */
    public function testAnOperationCannotSayItWasNotFoundNorThatItThrew(): void
    {
        [$code, $answer] = $this->child('taller:finge');

        self::assertSame(['ok' => true, 'ran' => 'taller:finge'], $answer);
        self::assertSame(0, $code);
    }

    public function testWhatACallLeavesTheNextOneFinds(): void
    {
        self::assertSame(['ok' => true, 'veces' => 1], $this->child('taller:segunda')[1]);
        self::assertSame(
            ['ok' => false, 'error' => 'TypeError: array_merge(): Argument #2 must be of type array, int given', 'thrown' => ['class' => 'TypeError', 'engine' => true]],
            $this->child('taller:segunda')[1],
        );
    }

    /** The whole, confined: the capability as the house would run it before closing on it. */
    public function testTheHouseExercisesItThroughThatRunner(): void
    {
        $runner = new TrialRunner();
        if (! $runner->available()) {
            self::markTestSkipped('no unprivileged user namespace here: the sandbox cannot be exercised');
        }
        @unlink($this->root . '/trial-run.php');
        @unlink($this->root . '/var/segunda');
        $before = $this->digest();

        $exercise = CapabilityExercise::of(
            $this->root,
            'Taller',
            ['taller:lista', 'taller:alta', 'taller:niega', 'taller:rota', 'taller:segunda', 'taller:dar_de_baja'],
            $runner,
            \dirname(__DIR__, 2) . '/resources/exercise-run.php',
        );

        self::assertSame('threw', $exercise['exercised']);
        self::assertSame(
            [
                'taller:alta' => ['answered', 'answered'],
                'taller:dar_de_baja' => ['answered', 'answered'],
                'taller:lista' => ['answered', 'answered'],
                'taller:niega' => ['refused', 'refused'],
                'taller:rota' => ['threw', 'threw'],
                'taller:segunda' => ['answered', 'threw'],
            ],
            $exercise['answers'],
        );
        self::assertSame(
            [
                ['operation' => 'taller:rota', 'class' => 'Error', 'kind' => 'engine', 'line' => 'Call to undefined method MilpaTest\Exercised\Almacen::guardar()', 'pass' => 1],
                ['operation' => 'taller:segunda', 'class' => 'TypeError', 'kind' => 'engine', 'line' => 'array_merge(): Argument #2 must be of type array, int given', 'pass' => 2],
            ],
            $exercise['thrown'],
        );
        self::assertSame($before, $this->digest(), 'and the house is what it was: what the second call found, it found in the copy');
    }

    public function testACapabilityWhoseOperationsAllRunRan(): void
    {
        $runner = new TrialRunner();
        if (! $runner->available()) {
            self::markTestSkipped('no unprivileged user namespace here: the sandbox cannot be exercised');
        }

        $exercise = CapabilityExercise::of($this->root, 'Taller', ['taller:lista', 'taller:alta', 'taller:niega', 'taller:dar_de_baja', 'taller:cuenta'], $runner, \dirname(__DIR__, 2) . '/resources/exercise-run.php');

        self::assertSame('ran', $exercise['exercised'], json_encode($exercise) ?: '');
        self::assertSame(['operations' => 5, 'calls' => 10, 'answered' => 4, 'refused' => 1, 'threw' => 0], array_intersect_key($exercise, ['operations' => 1, 'calls' => 1, 'answered' => 1, 'refused' => 1, 'threw' => 1]));
    }

    /**
     * Run the runner as a call of the exercise does: a PHP process of its own, from where it was placed.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function child(string $operation): array
    {
        copy(\dirname(__DIR__, 2) . '/resources/exercise-run.php', $this->root . '/trial-run.php');
        $process = proc_open([\PHP_BINARY, $this->root . '/trial-run.php', $operation, '[]'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);
        $lines = explode("\n", $out);
        $answer = json_decode((string) end($lines), true);
        self::assertIsArray($answer, "the runner prints one answer: {$out}\n{$err}");

        return [$code, $answer];
    }

    private function digest(): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $entries[] = substr($entry->getPathname(), \strlen($this->root)) . ($entry->isDir() ? '/' : ':' . hash_file('sha256', $entry->getPathname()));
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }
}
