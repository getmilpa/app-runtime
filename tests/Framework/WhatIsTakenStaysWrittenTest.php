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

namespace Milpa\AppRuntime\Tests\Framework;

use Milpa\AppRuntime\Framework\FrameworkDivergence;
use Milpa\AppRuntime\Framework\FrameworkReconciliation;
use Milpa\AppRuntime\Framework\FrameworkStamp;
use Milpa\AppRuntime\Framework\FrameworkUpdate;
use PHPUnit\Framework\TestCase;

/**
 * What is taken stays written (greenhouse decisions/0483).
 *
 * Measured on Surco: framework:apply took config/operations.php from 0.52.3 and left no record — the version
 * stayed 0.52.1, provenance called the taken file customized and the next diff called it conflicted. The
 * record now carries what was taken, and a file equal to it is the skeleton's.
 *
 * @guards the version moving, the birth staying, a taken file reading untouched and settled, and a later edit
 *         reading as the house's again
 *
 * @subject-in milpa/app-runtime
 */
final class WhatIsTakenStaysWrittenTest extends TestCase
{
    private string $house = '';

    protected function setUp(): void
    {
        $this->house = sys_get_temp_dir() . '/milpa-taken-' . bin2hex(random_bytes(4));
        mkdir($this->house . '/.milpa', 0o777, true);
        $born = "<?php return ['born' => true];\n";
        file_put_contents($this->house . '/config.php', $born);
        file_put_contents($this->house . '/' . FrameworkStamp::PATH, (string) json_encode([
            'version' => '0.52.1',
            'born' => ['version' => '0.52.1', 'at' => 'then', 'files' => ['config.php' => hash('sha256', $born)]],
        ]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->house));
    }

    public function testATakenFileIsTheSkeletonsAndTheVersionMoves(): void
    {
        $newer = "<?php return ['born' => true, 'newer' => true];\n";
        file_put_contents($this->house . '/config.php', $newer);
        FrameworkStamp::recordTaken($this->house, '0.52.3', ['config.php' => hash('sha256', $newer)]);

        self::assertSame('0.52.3', FrameworkStamp::version($this->house), 'the house runs what it took');
        self::assertSame('0.52.1', FrameworkStamp::born($this->house)['version'] ?? null, 'where it came from does not change');
        self::assertSame([['path' => 'config.php', 'status' => FrameworkDivergence::UNTOUCHED]], FrameworkDivergence::rows($this->house));
        self::assertSame(
            [['path' => 'config.php', 'status' => FrameworkReconciliation::SETTLED]],
            FrameworkReconciliation::rows($this->house, ['config.php' => hash('sha256', $newer)]),
            'against the release it came from, nothing is left to take',
        );
        self::assertSame(0, FrameworkUpdate::provenance($this->house)['customized']);

        file_put_contents($this->house . '/config.php', "<?php return ['mine' => true];\n");
        self::assertSame([['path' => 'config.php', 'status' => FrameworkDivergence::CUSTOMIZED]], FrameworkDivergence::rows($this->house), 'an edit after taking is the house\'s again');
    }

    public function testWithoutAnApplyTheBirthIsTheBaseAsBefore(): void
    {
        file_put_contents($this->house . '/config.php', "<?php return ['mine' => true];\n");
        self::assertSame([['path' => 'config.php', 'status' => FrameworkDivergence::CUSTOMIZED]], FrameworkDivergence::rows($this->house));
        self::assertSame('0.52.1', FrameworkStamp::version($this->house));
    }
}
