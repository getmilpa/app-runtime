<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\FileEffectObserver;
use PHPUnit\Framework\TestCase;

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
final class FileEffectObserverTest extends TestCase
{
    public function testChangesHaveStableIdentitiesAndPromotionIsAnotherStage(): void
    {
        $empty = [];
        $a = ['src/X.php' => hash('sha256', 'a')];
        $b = ['src/X.php' => hash('sha256', 'b')];
        $added = FileEffectObserver::compare($empty, $a, 'proposal');
        self::assertTrue($added->known);
        self::assertCount(1, $added->artifacts);
        self::assertEquals($added, FileEffectObserver::compare($empty, $a, 'proposal'));
        self::assertSame([], FileEffectObserver::compare($a, $a, 'proposal')->artifacts);
        self::assertNotSame($added->artifacts, FileEffectObserver::compare($empty, $a, 'applied')->artifacts);
        self::assertNotSame($added->artifacts, FileEffectObserver::compare($a, $b, 'proposal')->artifacts);
        self::assertCount(1, FileEffectObserver::compare($a, $empty, 'proposal')->artifacts);
        self::assertFalse(FileEffectObserver::compare(null, $a, 'proposal')->known);
        self::assertFalse(FileEffectObserver::compare($a, null, 'proposal')->known);
    }

    public function testNativeBehaviorEvidenceNeedsARealPositiveVerdictAndStableInputs(): void
    {
        $output = ['ok' => true, 'ran' => true, 'tests' => 1, 'assertions' => 1, 'errors' => 0, 'failures' => 0];
        $state = ['tests/XTest.php' => hash('sha256', 'test'), 'src/X.php' => hash('sha256', 'subject')];
        $args = ['path' => 'tests/XTest.php'];
        $proof = FileEffectObserver::testEvidence('test', $args, $state, $output);
        self::assertCount(1, $proof);
        self::assertSame($proof, FileEffectObserver::testEvidence('test', $args + ['timeout' => 10], $state, $output));
        self::assertNotSame($proof, FileEffectObserver::testEvidence('test', $args, $state + ['src/Y.php' => hash('sha256', 'new')], $output));
        self::assertSame([], FileEffectObserver::testEvidence('make', $args, $state, $output));
        self::assertSame([], FileEffectObserver::testEvidence('test', $args, null, $output));
        foreach (['ok' => false, 'ran' => false, 'tests' => 0, 'assertions' => 0, 'errors' => 1, 'failures' => 1] as $key => $value) {
            self::assertSame([], FileEffectObserver::testEvidence('test', $args, $state, array_replace($output, [$key => $value])));
        }
        self::assertSame([], FileEffectObserver::testEvidence('test', $args, $state, null));
    }

    public function testHostSnapshotPreservesDeletionAndRejectsLinks(): void
    {
        $root = sys_get_temp_dir() . '/effect-observer-' . bin2hex(random_bytes(5));
        mkdir($root);
        try {
            file_put_contents($root . '/x', 'x');
            self::assertSame(['x' => hash('sha256', 'x')], FileEffectObserver::hostSnapshot($root, ['x', 'missing']));
            symlink($root . '/x', $root . '/link');
            self::assertNull(FileEffectObserver::hostSnapshot($root, ['link']));
        } finally {
            @unlink($root . '/link');
            @unlink($root . '/x');
            rmdir($root);
        }
    }
}
