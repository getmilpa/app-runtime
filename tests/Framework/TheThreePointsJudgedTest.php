<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Framework;

use Milpa\AppRuntime\Framework\FrameworkReconciliation;
use Milpa\AppRuntime\Framework\FrameworkRelease;
use Milpa\AppRuntime\Framework\FrameworkStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THE FIVE ANSWERS, one per case, because the same new bytes mean opposite things.
 *
 * A file the skeleton changed is safe to apply when the house left it alone and needs a person when the
 * house changed it too. That is the whole reason a reconciliation needs three points and not two, and
 * these tests are the table (greenhouse decisions/0294).
 *
 * No network here: `ships` is handed in. The fetch has its own test below, against a local tree.
 */
#[CoversClass(FrameworkReconciliation::class)]
#[CoversClass(FrameworkRelease::class)]
final class TheThreePointsJudgedTest extends TestCase
{
    /** @var list<string> */
    private array $trees = [];

    protected function tearDown(): void
    {
        foreach ($this->trees as $tree) {
            foreach (glob($tree . '/{,*/,*/*/}*', \GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            foreach (['public', 'config', 'bin', '.milpa', 'tools', 'storage/framework-releases', 'storage'] as $d) {
                @rmdir($tree . '/' . $d);
            }
            @rmdir($tree);
        }
    }

    /** The bytes the skeleton handed this house at birth. */
    private const array BIRTH = [
        'composer.json' => "{\"name\":\"milpa/framework\"}\n",
        'public/index.php' => "<?php // entry\n",
        'config/app.php' => "<?php return [];\n",
        'bin/coa' => "#!/usr/bin/env php\n",
    ];

    private function house(bool $stamped = true): string
    {
        $tree = sys_get_temp_dir() . '/milpa-rec-' . uniqid('', true);
        foreach (['public', 'config', 'bin', '.milpa', 'tools'] as $d) {
            mkdir($tree . '/' . $d, 0o777, true);
        }
        $this->trees[] = $tree;
        foreach (self::BIRTH as $path => $bytes) {
            file_put_contents($tree . '/' . $path, $bytes);
        }
        $record = ['version' => '0.48.0'];
        if ($stamped) {
            $record['born'] = [
                'version' => '0.48.0',
                'at' => '2026-09-10T00:00:00+00:00',
                'files' => array_map(static fn (string $b): string => hash('sha256', $b), self::BIRTH),
            ];
        }
        file_put_contents($tree . '/' . FrameworkStamp::PATH, (string) json_encode($record));

        return $tree;
    }

    /** @return array<string, string> */
    private static function ships(array $overrides = []): array
    {
        $ships = array_map(static fn (string $b): string => hash('sha256', $b), self::BIRTH);
        foreach ($overrides as $path => $bytes) {
            $ships[$path] = $bytes === null ? '' : hash('sha256', $bytes);
            if ($bytes === null) {
                unset($ships[$path]);
            }
        }

        return $ships;
    }

    /** All five answers at once, each from the one condition that produces it. */
    public function testEachOfTheFiveAnswersComesFromItsOwnCase(): void
    {
        $tree = $this->house();
        // the house moved this one, the skeleton did not  -> KEPT
        file_put_contents($tree . '/config/app.php', "<?php return ['mine' => true];\n");
        // the house moved this one AND the skeleton does too -> CONFLICTED
        file_put_contents($tree . '/composer.json', "{\"name\":\"my/app\"}\n");

        $rows = FrameworkReconciliation::rows($tree, self::ships([
            'composer.json' => "{\"name\":\"milpa/framework\",\"scripts\":{}}\n",  // skeleton moved
            'public/index.php' => "<?php // entry, rewritten\n",                    // skeleton moved, house did not
            'tools/stamp-framework.php' => "<?php // brand new\n",                  // never received
        ]));

        self::assertNotNull($rows);
        $byPath = array_column($rows, 'status', 'path');

        self::assertSame(FrameworkReconciliation::CONFLICTED, $byPath['composer.json'], 'both moved — a person decides');
        self::assertSame(FrameworkReconciliation::OFFERED, $byPath['public/index.php'], 'the skeleton moved, the house did not — safe to apply');
        self::assertSame(FrameworkReconciliation::KEPT, $byPath['config/app.php'], 'the house moved, the skeleton did not — leave it alone');
        self::assertSame(FrameworkReconciliation::SETTLED, $byPath['bin/coa'], 'nobody moved');
        self::assertSame(FrameworkReconciliation::ADDED, $byPath['tools/stamp-framework.php'], 'the skeleton grew a file this house never received');
    }

    /** The headline counts only what a person has to look at. */
    public function testTheHeadlineCountsOnlyWhatNeedsAttention(): void
    {
        $tree = $this->house();
        file_put_contents($tree . '/config/app.php', "<?php return ['mine' => true];\n");

        $summary = FrameworkReconciliation::summary($tree, self::ships([
            'public/index.php' => "<?php // moved\n",
            'tools/x.php' => "<?php // new\n",
        ]));

        self::assertNotNull($summary);
        self::assertSame(2, $summary['actionable'], 'one offered plus one added — settled and kept need nobody');
        self::assertSame(1, $summary['kept']);
        self::assertSame(2, $summary['settled'], 'composer.json and bin/coa — nobody moved either');
        self::assertSame(0, $summary['conflicted']);
    }

    /** A file the house DELETED and the skeleton changed is the same question: conflicted. */
    public function testADeletedFileTheSkeletonChangedIsConflictedAndNotASixthState(): void
    {
        $tree = $this->house();
        unlink($tree . '/bin/coa');

        $rows = FrameworkReconciliation::rows($tree, self::ships(['bin/coa' => "#!/usr/bin/env php\n// now with flags\n"]));

        self::assertNotNull($rows);
        self::assertSame(FrameworkReconciliation::CONFLICTED, array_column($rows, 'status', 'path')['bin/coa']);
    }

    /**
     * 🚨 WITH NO BIRTH RECORD IT ANSWERS NULL, never a table where every file reads as new.
     *
     * Without the originals, `ships` has paths the record does not — which is the `ADDED` condition. A
     * house that cannot say what it was born with would be told «all twenty of your files are new»,
     * the most confidently wrong sentence this screen could print.
     */
    public function testWithoutABirthRecordItRefusesToJudgeAtAll(): void
    {
        $tree = $this->house(stamped: false);

        self::assertNull(FrameworkReconciliation::rows($tree, self::ships()));
        self::assertNull(FrameworkReconciliation::summary($tree, self::ships()));
    }

    /**
     * A release already hashed comes back from cache, untouched and without asking anything.
     *
     * The cache is what lets a render show a reconciliation with no network at all: a release's bytes
     * never change, so hashing `0.48.0` twice is pure waste. The FETCH branch needs the network and is
     * measured on cattle instead — 567 ms to ask the registry, 1.0 s to fetch and hash, 0 ms cached
     * (greenhouse evidence/0620).
     */
    public function testAReleaseAlreadyHashedComesBackFromCache(): void
    {
        $tree = $this->house();
        $seeded = ['composer.json' => hash('sha256', "{\"name\":\"seeded\"}\n")];
        mkdir($tree . '/storage/framework-releases', 0o777, true);
        file_put_contents($tree . '/storage/framework-releases/9.9.9.json', (string) json_encode($seeded));

        self::assertSame($seeded, FrameworkRelease::ships('9.9.9', $tree), 'read back exactly, with no fetch');
    }

    /** The pointer the verb writes is what a render reads to know which release to compare against. */
    public function testTheCheckIsRememberedAndAnUnaskedHouseSaysSo(): void
    {
        $tree = $this->house();

        self::assertNull(FrameworkRelease::remembered($tree), 'nobody has asked — which is not «up to date»');

        FrameworkRelease::remember($tree, '0.48.1', '2026-09-10T21:00:00+00:00');

        self::assertSame(
            ['latest' => '0.48.1', 'at' => '2026-09-10T21:00:00+00:00'],
            FrameworkRelease::remembered($tree),
        );
    }

    /**
     * 🚨 THE TRACKED SET IS A SECOND COPY OF THE SKELETON'S, and this is the assertion that keeps them one.
     *
     * `tools/stamp-framework.php` declares the same globs, and it must: that file is COPIED into each
     * app and frozen there, so an app's copy is the OLD list while this package is the one
     * `composer update` moves. Two lists is a lie waiting to happen — so when the app being analysed
     * has its own copy, the two are compared.
     */
    public function testTheTrackedSetMatchesTheSkeletonsOwnWhenItIsPresent(): void
    {
        // Looked for in both places it can be: installed as a package, or checked out beside this one,
        // which is the workspace where the two lists actually drift apart. A skip that can never run
        // anywhere is a control that quietly finds nothing — the shape this session kept catching.
        $script = null;
        foreach ([
            \dirname(__DIR__, 2) . '/vendor/milpa/framework/tools/stamp-framework.php',
            \dirname(__DIR__, 3) . '/getmilpa-framework/tools/stamp-framework.php',
            \dirname(__DIR__, 2) . '/vendor/milpa/framework/tools/stamp-framework.php',
        ] as $candidate) {
            if (is_file($candidate)) {
                $script = $candidate;

                break;
            }
        }
        if ($script === null) {
            self::markTestSkipped('milpa/framework is neither installed nor checked out beside this package');
        }

        $source = (string) file_get_contents($script);
        preg_match('/private const array TRACKED = \[(.*?)\];/s', $source, $m);
        preg_match_all("/'([^']+)'/", $m[1] ?? '', $found);

        self::assertSame($found[1] ?? [], FrameworkRelease::TRACKED, 'the mirror drifted from the skeleton it mirrors');
    }

    /**
     * 🚨 A FILE THE HOUSE HAS BUT THE RECORD DOES NOT IS «UNRECORDED», NEVER «ADDED».
     *
     * Found by measuring a real house: born from 0.48.0 with 15 paths in its record while the release
     * now tracks 20 — the skeleton's tracked set was widened afterwards. `composer.json` came back
     * `ADDED`, «the skeleton grew a file this house never received». The house HAS that file. What it
     * lacks is a record of it, and telling those apart is what this whole arc is about
     * (greenhouse decisions/0294).
     *
     * It is actionable: offering bytes over a file whose original was never recorded is the conflicted
     * risk without the evidence.
     */
    public function testAFileOnDiskButNotInTheRecordIsUnrecordedRatherThanAdded(): void
    {
        $tree = $this->house();
        // present on disk, absent from the record — exactly the widened-tracked-set case
        file_put_contents($tree . '/tools/boot-proof.sh', "#!/bin/sh\n");

        $rows = FrameworkReconciliation::rows($tree, self::ships([
            'tools/boot-proof.sh' => "#!/bin/sh\n",
            'tools/never-shipped-here.php' => "<?php // truly new\n",
        ]));

        self::assertNotNull($rows);
        $byPath = array_column($rows, 'status', 'path');

        self::assertSame(FrameworkReconciliation::UNRECORDED, $byPath['tools/boot-proof.sh'], 'the house has it; nothing recorded its birth bytes');
        self::assertSame(FrameworkReconciliation::ADDED, $byPath['tools/never-shipped-here.php'], 'and a file that really is not here reads as added');

        $summary = FrameworkReconciliation::summary($tree, self::ships([
            'tools/boot-proof.sh' => "#!/bin/sh\n",
        ]));
        self::assertNotNull($summary);
        self::assertSame(1, $summary['unrecorded']);
        self::assertSame(0, $summary['added']);
        self::assertSame(1, $summary['actionable'], 'unrecorded is something a person must look at');
    }
}
