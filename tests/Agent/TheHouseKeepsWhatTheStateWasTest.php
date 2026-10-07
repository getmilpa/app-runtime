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

use Milpa\AppRuntime\Agent\ConfinedWork;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\WorkPlan;
use PHPUnit\Framework\TestCase;

/**
 * The house keeps what the state was, and says what it is now (greenhouse decisions/0588, rules 5 and 6).
 *
 * A work call leaves the house's own account of it: for each path of its state, the digest before and after. The
 * same digest is «it did not change» — whatever the handler answered. And before the call runs the house keeps a
 * pre-image of that state, so there is something to return to. Undoing is the next slice; without the pre-image
 * there would be nothing to undo then.
 *
 * @guards the digests of each path before and after; equal digests say «it did not change» even when the handler
 *         said ok; a store that did not exist is «absent» before, and absent again when the call wrote nothing; the
 *         pre-image holds the bytes that were there; a call that changed nothing keeps no pre-image; the pre-images
 *         kept are bounded
 *
 * @refuses calling «changed» what was not; losing the bytes that were there; leaving behind a file the call did not
 *          write
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseKeepsWhatTheStateWasTest extends TestCase
{
    private string $root;
    private string $bwrap;

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/milpa-work-preimage-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var', 0o755, true);
        $this->bwrap = $this->root . '-bwrap';
        file_put_contents($this->bwrap, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($this->bwrap, 0o755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->bwrap);
    }

    public function testACallThatWroteSaysWhatTheStateWasAndIs(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', '[]');

        $outcome = $this->work()->run($this->plan(), ['nombre' => 'Sierra']);

        self::assertSame(0, $outcome->run->exit, $outcome->run->stderr);
        self::assertTrue($outcome->changed);
        self::assertSame([[
            'path' => 'var/herramientas.json',
            'before' => 'sha256:' . hash('sha256', '[]'),
            'after' => 'sha256:' . hash_file('sha256', $this->root . '/var/herramientas.json'),
        ]], $outcome->state);
        self::assertNotSame($outcome->state[0]['before'], $outcome->state[0]['after']);
    }

    public function testThePreImageHoldsTheBytesThatWereThere(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', '[{"id":1,"nombre":"Martillo","prestada":false}]');

        $outcome = $this->work()->run($this->plan(), ['nombre' => 'Sierra']);

        self::assertNotNull($outcome->preImage);
        $kept = $this->root . '/var/work/' . $outcome->preImage;
        self::assertSame('[{"id":1,"nombre":"Martillo","prestada":false}]', file_get_contents($kept . '/pre/var/herramientas.json'));
        $manifest = json_decode((string) file_get_contents($kept . '/manifest.json'), true);
        self::assertSame('herramientas.agregar', $manifest['operation']);
        self::assertSame($outcome->state, $manifest['state']);
        self::assertDirectoryDoesNotExist($kept . '/scratch', 'the scratch of the call is not kept');
    }

    public function testAnOkThatWroteNothingSaysItDidNotChange(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', '[]');

        $outcome = $this->work()->run($this->plan(), ['fixture' => 'nothing']);

        self::assertSame(['ok' => true, 'id' => 1], $outcome->run->output, 'the handler said it added tool 1');
        self::assertFalse($outcome->changed, 'and the house says its state is what it was');
        self::assertSame($outcome->state[0]['before'], $outcome->state[0]['after']);
        self::assertNull($outcome->preImage, 'nothing to return to: nothing is kept');
        self::assertSame([], glob($this->root . '/var/work/*') ?: []);
    }

    public function testAStoreThatDidNotExistIsAbsentBeforeAndTheCallCreatesIt(): void
    {
        $outcome = $this->work()->run($this->plan(), ['nombre' => 'Sierra']);

        self::assertTrue($outcome->changed);
        self::assertNull($outcome->state[0]['before'], 'absent, not empty');
        self::assertSame('sha256:' . hash_file('sha256', $this->root . '/var/herramientas.json'), $outcome->state[0]['after']);
        self::assertNotNull($outcome->preImage, 'that it was absent is what there is to return to');
        self::assertFileDoesNotExist($this->root . '/var/work/' . $outcome->preImage . '/pre/var/herramientas.json');
    }

    public function testAStoreThatDidNotExistIsAbsentAgainWhenTheCallWroteNothing(): void
    {
        $outcome = $this->work()->run($this->plan(), ['fixture' => 'nothing']);

        self::assertFalse($outcome->changed);
        self::assertSame([['path' => 'var/herramientas.json', 'before' => null, 'after' => null]], $outcome->state);
        self::assertFileDoesNotExist($this->root . '/var/herramientas.json', 'the house did not leave behind the file it made to mount');
    }

    public function testARefusalOfTheDomainThatWroteNothingDidNotChangeTheHouse(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', '[]');

        $outcome = $this->work()->run($this->plan(), ['fixture' => 'refuses']);

        self::assertSame(1, $outcome->run->exit);
        self::assertSame('ya_prestada', $outcome->run->output['error']);
        self::assertFalse($outcome->changed);
    }

    public function testAFailureThatWroteSaysSo(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', '[]');

        $outcome = $this->work()->run($this->plan(), ['fixture' => 'add-and-fail']);

        self::assertSame(1, $outcome->run->exit);
        self::assertTrue($outcome->changed, 'the house says what happened to its state, not what the handler answered');
        self::assertNotNull($outcome->preImage);
    }

    public function testADirectoryOfItsOwnIsDigestedAndKeptWhole(): void
    {
        mkdir($this->root . '/var/data', 0o755, true);
        file_put_contents($this->root . '/var/data/a.json', 'a');
        file_put_contents($this->root . '/var/data/b.json', 'b');
        $work = $this->work(\dirname(__DIR__) . '/Fixtures/work-runner.php');

        $outcome = $work->run($this->plan(['var/data']), ['fixture' => 'write', 'path' => 'var/data/c.json']);

        self::assertTrue($outcome->changed);
        self::assertStringStartsWith('sha256:', (string) $outcome->state[0]['before']);
        $kept = $this->root . '/var/work/' . $outcome->preImage . '/pre/var/data';
        self::assertSame(['a.json', 'b.json'], array_values(array_diff(scandir($kept) ?: [], ['.', '..'])));
    }

    public function testWhatMayNotBeKeptIsNeverCopied(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', '[]');

        $kept = $this->work()->run($this->plan(), ['fixture' => 'peek']);
        self::assertSame(['herramientas.json'], $kept->run->output['pre'] ?? null, 'the control: with a pre-image, it is there while the call runs');

        $none = $this->work()->run(new WorkPlan('herramientas.agregar', ['var/herramientas.json'], 'entities', confined: true, preImage: false), ['fixture' => 'peek']);
        self::assertSame([], $none->run->output['pre'] ?? null, 'a state too large to keep is not copied to find that out');
    }

    public function testAStateWithNoExtensionIsADirectoryOfItsOwn(): void
    {
        $outcome = $this->work()->run($this->plan(['var/nueva']), ['fixture' => 'write', 'path' => 'var/nueva/x.json']);

        self::assertSame(0, $outcome->run->exit, (string) json_encode($outcome->run->output));
        self::assertTrue($outcome->changed);
        self::assertNull($outcome->state[0]['before']);
        self::assertDirectoryExists($this->root . '/var/nueva');

        $untouched = $this->work()->run($this->plan(['var/otra']), ['fixture' => 'nothing']);
        self::assertFalse($untouched->changed);
        self::assertDirectoryDoesNotExist($this->root . '/var/otra', 'made to mount, left empty, taken away');
    }

    public function testADirectoryIsDigestedByNameAndBytes(): void
    {
        mkdir($this->root . '/var/data', 0o755, true);
        file_put_contents($this->root . '/var/data/a.json', 'a');
        $before = ConfinedWork::digest($this->root . '/var/data');

        rename($this->root . '/var/data/a.json', $this->root . '/var/data/b.json');

        self::assertNotSame($before, ConfinedWork::digest($this->root . '/var/data'), 'the same bytes under another name are another state');
        self::assertNull(ConfinedWork::digest($this->root . '/var/nunca'));
    }

    public function testACallThatMayNotKeepAPreImageKeepsNone(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', '[]');

        $outcome = $this->work()->run(new WorkPlan('herramientas.agregar', ['var/herramientas.json'], 'entities', confined: true, preImage: false), ['nombre' => 'Sierra']);

        self::assertTrue($outcome->changed);
        self::assertNull($outcome->preImage);
    }

    public function testThePreImagesKeptAreBounded(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', '[]');
        $work = $this->work();
        $kept = [];
        for ($i = 0; $i < ConfinedWork::KEEP + 3; ++$i) {
            $kept[] = $work->run($this->plan(), ['nombre' => 'Herramienta ' . $i])->preImage;
            touch($this->root . '/var/work/' . end($kept), time() - (ConfinedWork::KEEP + 3 - $i) * 60);
        }

        $work->run($this->plan(), ['nombre' => 'La última']);

        $remaining = array_map('basename', glob($this->root . '/var/work/*') ?: []);
        self::assertCount(ConfinedWork::KEEP, $remaining);
        self::assertNotContains($kept[0], $remaining, 'the oldest went');
        self::assertContains(end($kept), $remaining);
    }

    public function testAPathThatIsNotAPlaceForStateIsNeverMadeForTheCall(): void
    {
        mkdir($this->root . '/config', 0o755, true);

        try {
            $this->work()->run($this->plan(['config/nuevo/dentro.json']), ['nombre' => 'Sierra']);
            self::fail('the call was run');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('config/', $refused->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->root . '/config/nuevo', 'the house made nothing where state may not live — not even on its way to refusing');
        self::assertSame([], glob($this->root . '/var/work/*') ?: [], 'and kept nothing of a call that never ran');
    }

    public function testACallThatCouldNotBeMountedLeavesNothingBehind(): void
    {
        $unmountable = new ConfinedWork($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/work-runner.php');
        file_put_contents($this->root . '/var/herramientas.json', '[]');

        try {
            // Its second path cannot be made: its parent is a file.
            $unmountable->run($this->plan(['var/nuevo.json', 'var/herramientas.json/dentro.json']), []);
            self::fail('the call was run');
        } catch (\RuntimeException) {
        }
        self::assertFileDoesNotExist($this->root . '/var/nuevo.json', 'what the house made to mount is taken away again');
        self::assertSame([], glob($this->root . '/var/work/*') ?: []);
    }

    public function testAPlanThatIsNotConfinedIsNeverRunHere(): void
    {
        $this->expectException(\LogicException::class);

        $this->work()->run(new WorkPlan('herramientas.agregar', ['var/herramientas.json'], 'entities', asks: 'no sandbox'), []);
    }

    /** @param list<string> $state */
    private function plan(array $state = ['var/herramientas.json']): WorkPlan
    {
        return new WorkPlan('herramientas.agregar', $state, 'entities', confined: true, preImage: true);
    }

    private function work(?string $runner = null): ConfinedWork
    {
        return new ConfinedWork($this->root, new TrialRunner(bwrap: $this->bwrap), $runner ?? \dirname(__DIR__) . '/Fixtures/work-runner.php');
    }
}
