<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\FileEffectObserver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A native red assertion is diagnostic novelty, never positive verification (greenhouse0751).
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */
final class TestDiagnosticObservationTest extends TestCase
{
    public function testRepeatedInputIgnoresTransportNoiseAndPathAliases(): void
    {
        $state = ['tests/XTest.php' => hash('sha256', 'test'), 'src/X.php' => hash('sha256', 'subject')];
        $args = ['path' => 'tests/XTest.php'];
        $output = self::failure();
        $observed = FileEffectObserver::testDiagnostics('test', $args, $state, $output);
        self::assertCount(1, $observed);
        self::assertSame([], FileEffectObserver::testEvidence('test', $args, $state, $output));
        foreach ([' ./tests//XTest.php ', 'tests/unused/../XTest.php'] as $path) {
            self::assertSame($observed, FileEffectObserver::testDiagnostics(
                'test',
                ['path' => $path, 'filter' => ' ', 'timeout' => 10],
                array_reverse($state, true),
                $output + ['workspace' => 'different', 'output' => 'another elapsed time']
            ));
        }
        self::assertNotSame($observed, FileEffectObserver::testDiagnostics(
            'test',
            $args,
            array_replace($state, ['src/X.php' => hash('sha256', 'changed')]),
            $output
        ));
        self::assertNotSame($observed, FileEffectObserver::testDiagnostics('test', $args + ['filter' => 'Other'], $state, $output));
    }

    #[DataProvider('invalidOutputs')]
    public function testOnlyExecutedAssertionFailuresProduceDiagnostics(array $patch): void
    {
        self::assertSame([], FileEffectObserver::testDiagnostics(
            'test',
            ['path' => 'tests'],
            ['src/X.php' => hash('sha256', 'subject')],
            array_replace(self::failure(), $patch)
        ));
    }

    /** @return list<array{array<string,mixed>}> */
    public static function invalidOutputs(): array
    {
        return [[['ok' => true]], [['ok' => 0]], [['ran' => false]], [['ran' => 1]], [['tests' => 0]],
            [['tests' => '1']], [['assertions' => 0]], [['assertions' => '1']], [['errors' => 1]],
            [['errors' => '0']], [['failures' => 0]], [['failures' => '1']], [['failures' => 3]]];
    }

    public function testMissingInputsOrUnsupportedScopeCannotInventObservation(): void
    {
        $state = ['src/X.php' => hash('sha256', 'subject')];
        foreach ([null, [], ['src/X.php' => 'invalid']] as $bad) {
            self::assertSame([], FileEffectObserver::testDiagnostics('test', ['path' => 'tests'], $bad, self::failure()));
        }
        foreach ([[], ['path' => ''], ['path' => '/tmp/tests'], ['path' => '../tests'], ['path' => ['tests']], ['path' => 'tests', 'filter' => []]] as $args) {
            self::assertSame([], FileEffectObserver::testDiagnostics('test', $args, $state, self::failure()));
        }
        self::assertSame([], FileEffectObserver::testDiagnostics('edit', ['path' => 'tests'], $state, self::failure()));
        self::assertSame([], FileEffectObserver::testDiagnostics('test', ['path' => 'tests'], $state, null));
        $diagnostics = FileEffectObserver::testDiagnostics('test', ['path' => 'tests'], $state, self::failure());
        $witness = FileEffectObserver::compare($state, $state, 'proposal', diagnostics: $diagnostics);
        self::assertSame($diagnostics, $witness->diagnostics);
        self::assertSame([], $witness->evidence);
        self::assertFalse(FileEffectObserver::compare(null, $state, 'proposal', diagnostics: $diagnostics)->known);
    }

    /** @return array<string,mixed> */
    private static function failure(): array
    {
        return ['ok' => false, 'ran' => true, 'tests' => 2, 'assertions' => 3, 'errors' => 0, 'failures' => 1];
    }
}
