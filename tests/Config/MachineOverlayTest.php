<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\MachineOverlay;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse evidence/0145 froze before this class existed.
 *
 * The third case is the control and it is the one that decides whether this is a layer at all: a
 * key the human declared and the machine never touched has to arrive intact. A merge that erases
 * what it did not write is not an overlay, it is a hijack — and it would look like it worked,
 * because every other case would still pass.
 */
final class MachineOverlayTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/overlay-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.milpa', 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . MachineOverlay::RUTA);
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    /** 1 · what the machine wrote reaches the configuration. */
    public function testTheMachinesValueIsRead(): void
    {
        $this->machineWrote(['agent' => ['instructions' => 'from the machine']]);

        $merged = MachineOverlay::sobre(['agent' => []], $this->root);

        self::assertSame('from the machine', $merged['agent']['instructions']);
    }

    /** 2 · declared in both places, the machine's value wins — it passed through consent. */
    public function testTheMachineWinsOverTheHuman(): void
    {
        $this->machineWrote(['agent' => ['permissionWindow' => 'PT1H']]);

        $merged = MachineOverlay::sobre(['agent' => ['permissionWindow' => 'P3D']], $this->root);

        self::assertSame('PT1H', $merged['agent']['permissionWindow']);
    }

    /**
     * 3 · THE CONTROL: what only the human declared survives untouched.
     *
     * Without this, a merge that replaced the whole bag would pass cases one and two while
     * silently deleting every key the operation never mentions.
     */
    public function testWhatOnlyTheHumanDeclaredSurvives(): void
    {
        $this->machineWrote(['agent' => ['instructions' => 'from the machine']]);

        $merged = MachineOverlay::sobre([
            'agent' => ['compaction' => ['maxTurns' => 40], 'instructions' => 'from the human'],
            'storage' => ['driver' => 'sqlite'],
        ], $this->root);

        self::assertSame(['maxTurns' => 40], $merged['agent']['compaction'], 'a sibling key was erased');
        self::assertSame(['driver' => 'sqlite'], $merged['storage'], 'a sibling section was erased');
    }

    /** 4 · no file at all means the human's configuration is exactly what it was. */
    public function testWithoutTheFileNothingChanges(): void
    {
        $delHumano = ['agent' => ['instructions' => 'only mine']];

        self::assertSame($delHumano, MachineOverlay::sobre($delHumano, $this->root));
    }

    /**
     * 5 · an unreadable file does not empty anybody's configuration.
     *
     * The alternative — booting with nothing — would turn one stray comma into an app with no
     * instructions and no deadlines, and would do it in silence.
     */
    public function testABrokenFileLeavesTheHumansConfigurationAlone(): void
    {
        file_put_contents($this->root . MachineOverlay::RUTA, '{roto');

        $delHumano = ['agent' => ['instructions' => 'still here']];

        self::assertSame($delHumano, MachineOverlay::sobre($delHumano, $this->root));
    }

    /** 6 · the same key in both files is divergence, and it gets named by its dotted path. */
    public function testAKeyDeclaredInBothPlacesIsReported(): void
    {
        $this->machineWrote(['agent' => ['permissionWindow' => 'PT1H']]);

        self::assertSame(
            ['agent.permissionWindow'],
            MachineOverlay::divergencias(['agent' => ['permissionWindow' => 'P3D']], $this->root),
        );
    }

    /**
     * 7 · THE CONTROL FOR THE DETECTOR: sharing a parent section is not divergence.
     *
     * Both files declaring `agent` while touching different keys inside is exactly how this layer is
     * meant to be used. A detector that reported the parent would fire on every healthy app and be
     * ignored within a week — which is worse than no detector, because it also grants confidence.
     */
    public function testSharingAParentSectionIsNotDivergence(): void
    {
        $this->machineWrote(['agent' => ['instructions' => 'mine']]);

        self::assertSame(
            [],
            MachineOverlay::divergencias(['agent' => ['compaction' => ['maxTurns' => 40]]], $this->root),
        );
    }

    /** 8 · no machine file, nothing to diverge from. */
    public function testWithoutTheFileThereIsNoDivergence(): void
    {
        self::assertSame([], MachineOverlay::divergencias(['agent' => ['instructions' => 'mine']], $this->root));
    }

    /** @param array<string, mixed> $valores */
    private function machineWrote(array $valores): void
    {
        file_put_contents(
            $this->root . MachineOverlay::RUTA,
            json_encode($valores, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }
}
