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

use Milpa\AppRuntime\Framework\FrameworkDivergence;
use Milpa\AppRuntime\Framework\FrameworkStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * TWO OF THE UPDATE'S THREE POINTS: what the house was born with, and what it has now.
 *
 * A house is a COPY of `milpa/framework`, so «update the framework» is not a composer update. Telling
 * «the app customized this file» from «the app left it alone» needs the birth bytes, and that is what
 * decides, when a newer skeleton arrives, whether a file is safe to offer or needs a diff
 * (greenhouse decisions/0291, this slice 0293).
 *
 * 🚨 THE ASSERTION THAT MATTERS MOST IS THE LAST ONE: «nothing changed» and «I cannot say» are
 * different answers, and a summary that returned zeros for a house with no birth record would collapse
 * them into the reassuring one.
 */
#[CoversClass(FrameworkDivergence::class)]
#[CoversClass(FrameworkStamp::class)]
final class TheHouseSaysWhatItHasChangedTest extends TestCase
{
    /** @var list<string> */
    private array $trees = [];

    protected function tearDown(): void
    {
        foreach ($this->trees as $tree) {
            foreach (['public/index.php', 'config/app.php', 'bin/coa', FrameworkStamp::PATH] as $f) {
                @unlink($tree . '/' . $f);
            }
            foreach (['public', 'config', 'bin', '.milpa'] as $d) {
                @rmdir($tree . '/' . $d);
            }
            @rmdir($tree);
        }
    }

    /** A house whose three files were handed over with the bytes below. */
    private function house(bool $stamped = true): string
    {
        $tree = sys_get_temp_dir() . '/milpa-div-' . uniqid('', true);
        foreach (['public', 'config', 'bin', '.milpa'] as $d) {
            mkdir($tree . '/' . $d, 0o777, true);
        }
        $this->trees[] = $tree;

        $files = [
            'public/index.php' => "<?php // entry\n",
            'config/app.php' => "<?php return [];\n",
            'bin/coa' => "#!/usr/bin/env php\n",
        ];
        foreach ($files as $path => $bytes) {
            file_put_contents($tree . '/' . $path, $bytes);
        }

        $record = ['version' => '0.48.0'];
        if ($stamped) {
            $record['born'] = [
                'version' => '0.48.0',
                'at' => '2026-09-10T00:00:00+00:00',
                'files' => array_map(static fn (string $b): string => hash('sha256', $b), $files),
            ];
        }
        file_put_contents($tree . '/' . FrameworkStamp::PATH, (string) json_encode($record));

        return $tree;
    }

    /** A house that touched nothing reports every file untouched. */
    public function testAHouseThatTouchedNothingSaysSo(): void
    {
        $summary = FrameworkDivergence::summary($this->house());

        self::assertNotNull($summary);
        self::assertSame(['born' => '0.48.0', 'untouched' => 3, 'customized' => 0, 'deleted' => 0], [
            'born' => $summary['born'],
            'untouched' => $summary['untouched'],
            'customized' => $summary['customized'],
            'deleted' => $summary['deleted'],
        ]);
    }

    /** An edited file is CUSTOMIZED, and that is what protects it from being overwritten later. */
    public function testAnEditedFileIsCustomized(): void
    {
        $tree = $this->house();
        file_put_contents($tree . '/public/index.php', "<?php // entry, and my own middleware\n");

        $rows = FrameworkDivergence::rows($tree);
        $byPath = array_column($rows, 'status', 'path');

        self::assertSame(FrameworkDivergence::CUSTOMIZED, $byPath['public/index.php']);
        self::assertSame(FrameworkDivergence::UNTOUCHED, $byPath['config/app.php'], 'and only that one');
        self::assertSame(1, FrameworkDivergence::summary($tree)['customized']);
    }

    /** A deleted file is DELETED, not missing — dropping the starter plugin is a legitimate house. */
    public function testADeletedFileIsReportedAsDeletedNotAsAnError(): void
    {
        $tree = $this->house();
        unlink($tree . '/bin/coa');

        $byPath = array_column(FrameworkDivergence::rows($tree), 'status', 'path');

        self::assertSame(FrameworkDivergence::DELETED, $byPath['bin/coa']);
        self::assertSame(1, FrameworkDivergence::summary($tree)['deleted']);
        self::assertSame(2, FrameworkDivergence::summary($tree)['untouched']);
    }

    /** Rows come back sorted, so two runs of the same house read the same. */
    public function testTheRowsAreSortedByPath(): void
    {
        $paths = array_column(FrameworkDivergence::rows($this->house()), 'path');
        $sorted = $paths;
        sort($sorted);

        self::assertSame($sorted, $paths);
    }

    /**
     * 🚨 «NOTHING CHANGED» AND «I CANNOT SAY» ARE DIFFERENT ANSWERS.
     *
     * A house created before `milpa/framework` stamped a birth record has no originals to compare
     * against. Returning zeros would say «every file is exactly as it came» — the most reassuring
     * sentence available, and the one thing that is certainly not known. The screen shows a notice
     * instead, and the reconciliation that reads this must refuse to offer anything.
     */
    public function testAHouseWithNoBirthRecordSaysItCannotTellRatherThanZero(): void
    {
        $tree = $this->house(stamped: false);

        self::assertNull(FrameworkDivergence::summary($tree), 'null, never a summary full of zeros');
        self::assertSame([], FrameworkDivergence::rows($tree), 'and no rows to draw a false conclusion from');
    }

    /*
     * EL TEST DE RENDER SE QUEDÓ EN EL PANEL, donde vive el renderer. Estas clases se mudaron aquí
     * porque el hecho es de la app; pintar la tabla sigue siendo de `milpa/admin`
     * (greenhouse decisions/0295).
     */
}
