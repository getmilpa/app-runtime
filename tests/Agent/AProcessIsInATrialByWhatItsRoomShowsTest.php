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

    /**
     * A mark is the null device the confinement places with a mount; what can be made there without one is not — asked
     * in a process with NO route off the host, so only the mark decides (the review of 0631, M-1).
     */
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

        self::assertFalse($this->claimIn($home, route: false, mark: false), $kind);
    }

    public function testOnlyTheMountedMarkWithNoRouteIsATrial(): void
    {
        $home = $this->root . '/home';
        mkdir($home . '/var', 0o777, true);
        touch($home . '/' . TrialRunner::MARK);

        self::assertTrue($this->claimIn($home, route: false, mark: true), 'the mounted mark and no route: a trial');
        if (self::hostHasARoute()) {
            // Only where this host has a route off it can a route be kept for the child (the review of 0631, L-7).
            self::assertFalse($this->claimIn($home, route: true, mark: true), 'the mounted mark with a route off the host: not a trial');
        }
        self::assertFalse($this->claimIn($home, route: false, mark: false), 'no route and no mark: not a trial');
    }

    public function testTheMarkIsNeverMadeThroughALink(): void
    {
        $mark = new \ReflectionMethod(TrialRunner::class, 'mark');
        $elsewhere = $this->root . '/elsewhere';
        mkdir($elsewhere, 0o777, true);

        $linkedVar = $this->root . '/h1';
        mkdir($linkedVar, 0o777, true);
        symlink($elsewhere, $linkedVar . '/var');
        self::assertSame([], $mark->invoke(null, $linkedVar), 'a home whose var/ is a link gets no mark');

        $linkedHome = $this->root . '/h2';
        symlink($elsewhere, $linkedHome);
        $args = $mark->invoke(null, $linkedHome);
        self::assertSame(['--ro-bind', '/dev/null', $elsewhere . '/' . TrialRunner::MARK], $args, 'made where the home really is');

        $taken = $this->root . '/h3';
        mkdir($taken . '/' . TrialRunner::MARK, 0o777, true);
        self::assertSame([], $mark->invoke(null, $taken), 'a mark that is not a file is not used');

        $dangling = $this->root . '/h4';
        mkdir($dangling . '/var', 0o777, true);
        symlink($this->root . '/nowhere', $dangling . '/' . TrialRunner::MARK);
        self::assertSame([], $mark->invoke(null, $dangling), 'a link at the mark, even to nothing, is not used');
        self::assertFileDoesNotExist($this->root . '/nowhere', 'and nothing was made where it points');

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

    /** @return iterable<string, array{bool}> */
    public static function recovering(): iterable
    {
        yield 'a write' => [false];
        yield 'a recovery' => [true];
    }

    /** Told it is a trial outside one, a writer writes nothing — not even as recovery (the review of 0631, L-2/L-3). */
    #[\PHPUnit\Framework\Attributes\DataProvider('recovering')]
    public function testToldItOutsideOneAWriterWritesNothing(bool $recovery): void
    {
        $this->set(TrialRunner::INSIDE_TRIAL_ENV, '1');
        $committed = false;
        $written = (new HouseBootWitness($this->root, new BootProbe(runner: new TrialRunner('/nonexistent/bwrap'))))
            ->writeIfItBoots(['src/Plugins/Blog/Note.php' => '<?php'], static function () use (&$committed): void {
                $committed = true;
            }, recovery: $recovery);

        self::assertSame(TrialRunner::FALSE_CLAIM . '. Nothing was written; the house is as it was.', $written['refused']);
        self::assertSame(BootProbe::FALSE_ROOM, $written['said']['boot_room'] ?? null);
        self::assertFalse($committed, 'nothing was written');
    }

    public function testEveryConfinedCommandCarriesTheMark(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Agent/TrialRunner.php');

        // The three commands that carry the mask carry the mark after it, each in its own home: the confinement in the
        // tree it confines, work in its scratch, a trial's run in its temporary directory — never the house.
        self::assertSame(3, preg_match_all('/\.\.\.\$this->maskArgs\(\$\w+\), \.\.\.self::mark\(\$\w+\)/', $source));
        self::assertSame(1, substr_count($source, '...$this->maskArgs($secretsRoot), ...self::mark($tree)'));
        self::assertSame(1, substr_count($source, '...$this->maskArgs($house), ...self::mark($scratch)'));
        self::assertSame(1, substr_count($source, '...$this->maskArgs($house), ...self::mark($temporary)'));
    }

    /**
     * What `trialClaim()` says in a process told it is a trial, with `$home` its home, run under bubblewrap: with or
     * without a route off the host, with or without the null device mounted over the mark.
     */
    private function claimIn(string $home, bool $route, bool $mark): ?bool
    {
        $runner = new TrialRunner();
        if (!$runner->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $shape = (new \ReflectionMethod($runner, 'namespaces'))->invoke($runner);
        $shape = array_values(array_filter($shape, static fn (string $a): bool => !$route || $a !== '--unshare-net'));
        $args = ['bwrap', ...$shape, '--dev-bind', '/', '/'];
        if ($mark) {
            array_push($args, '--ro-bind', '/dev/null', $home . '/' . TrialRunner::MARK);
        }
        array_push(
            $args,
            '--setenv',
            'HOME',
            $home,
            '--setenv',
            TrialRunner::INSIDE_TRIAL_ENV,
            '1',
            '--',
            \PHP_BINARY,
            '-r',
            'require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; echo json_encode(Milpa\AppRuntime\Agent\TrialRunner::trialClaim());'
        );
        $run = \Milpa\AppRuntime\Support\ChildProcess::run($args);
        self::assertNotNull($run);
        self::assertSame(0, $run['exit'], $run['stderr']);

        return json_decode($run['stdout'], true);
    }

    /** Whether this process has a route off the host, over IPv4 or IPv6 (asked the way the claim asks it). */
    private static function hostHasARoute(): bool
    {
        foreach (['udp://192.0.2.1:9', 'udp://[2001:db8::1]:9'] as $address) {
            $socket = @stream_socket_client($address, $errno, $error, 1);
            if (\is_resource($socket)) {
                fclose($socket);

                return true;
            }
        }

        return false;
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
