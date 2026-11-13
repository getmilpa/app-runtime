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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Support\HouseBootWitness;
use Milpa\AppRuntime\Tests\Fixtures\InsideATrial;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use PHPUnit\Framework\TestCase;

/**
 * A PROCESS IS IN A TRIAL BY WHAT ITS ROOM SHOWS, NOT BY WHAT IT IS TOLD (greenhouse decisions/0631).
 *
 * The confinement sets MILPA_TRIAL in what it runs, so a boot check asked from inside a trial does not nest another
 * confinement. Told it alone, a process is not in a trial: it is when its room shows it too — the confinement's own
 * mark and no route off the host. Told it and not in one, every door that asks refuses instead of booting plain.
 */
final class AProcessIsInATrialByWhatItsRoomShowsTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
        TinyHouse::remove($this->root);
    }

    public function testInsideARealTrialTheRoomShowsIt(): void
    {
        $said = $this->inside('echo json_encode(["claim" => Milpa\AppRuntime\Agent\TrialRunner::trialClaim(), "inside" => Milpa\AppRuntime\Agent\TrialRunner::insideTrial()]);');

        self::assertSame(['claim' => true, 'inside' => true], $said);
    }

    public function testOutsideNothingSaysItAndNothingIsClaimed(): void
    {
        $this->set(TrialRunner::INSIDE_TRIAL_ENV, false);

        self::assertNull(TrialRunner::trialClaim());
        self::assertFalse(TrialRunner::insideTrial());
    }

    public function testToldItOutsideOneIsAFalseClaim(): void
    {
        $this->set(TrialRunner::INSIDE_TRIAL_ENV, '1');

        self::assertFalse(TrialRunner::trialClaim());
        self::assertFalse(TrialRunner::insideTrial());
    }

    /** @return iterable<string, array{string}> */
    public static function marksAnyoneCanMake(): iterable
    {
        yield 'a link to the null device' => ['link'];
        yield 'an empty file' => ['file'];
        yield 'a directory' => ['dir'];
    }

    /** Only the confinement binds the device over the mark; what an unprivileged process can make there is not one. */
    #[\PHPUnit\Framework\Attributes\DataProvider('marksAnyoneCanMake')]
    public function testAMarkAnyoneCanMakeIsNotATrialsMark(string $kind): void
    {
        $home = $this->root . '/home';
        mkdir($home . '/var', 0o777, true);
        $mark = $home . '/' . TrialRunner::MARK;
        match ($kind) {
            'link' => symlink('/dev/null', $mark),
            'file' => touch($mark),
            'dir' => mkdir($mark),
        };
        $this->set('HOME', $home);
        $this->set(TrialRunner::INSIDE_TRIAL_ENV, '1');

        self::assertFalse(TrialRunner::trialClaim(), $kind);
    }

    public function testToldItOutsideOneComposerRunsNothingWhateverWasAccepted(): void
    {
        $this->set(TrialRunner::INSIDE_TRIAL_ENV, '1');
        $ran = [];
        $composed = (new HouseBootWitness($this->root, new BootProbe(runner: new TrialRunner('/nonexistent/bwrap')), unconfinedAccepted: 'my own laptop, I accept the risk'))
            ->composeIfItBoots('composer require lab/good', static function (string $cmd, string $cwd) use (&$ran): array {
                $ran[] = $cmd;

                return [0, []];
            });

        self::assertSame(TrialRunner::FALSE_CLAIM . '. Nothing was written; the house is as it was.', $composed['refused']);
        self::assertSame(BootProbe::FALSE_ROOM, $composed['said']['boot_room'] ?? null);
        self::assertSame([], $ran, 'Composer never ran');
        self::assertFileDoesNotExist($this->root . '/' . HouseBootWitness::JOURNAL, 'nothing was recorded as accepted');
    }

    public function testEveryConfinedCommandCarriesTheMark(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Agent/TrialRunner.php');

        // The three commands that carry the mask carry the mark after it: the confinement, work, and a trial's run.
        self::assertSame(3, preg_match_all('/\.\.\.\$this->maskArgs\(\$\w+\), \.\.\.self::mark\(\$\w+\)/', $source));
    }

    /** @return array<string, mixed> */
    private function inside(string $code): array
    {
        $script = $this->root . '/var/inside.php';
        file_put_contents($script, '<?php require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n" . $code);
        $run = InsideATrial::run($this->root, $script);
        if ($run === null) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $said = json_decode($run['stdout'], true);
        self::assertIsArray($said, 'inside the trial it said nothing readable: ' . $run['stdout'] . $run['stderr']);

        return $said;
    }

    private function set(string $name, string|false $value): void
    {
        if (!\array_key_exists($name, $this->env)) {
            $this->env[$name] = getenv($name);
        }
        $value === false ? putenv($name) : putenv("{$name}={$value}");
    }
}
