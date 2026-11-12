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
use Milpa\AppRuntime\Support\ChildProcess;
use PHPUnit\Framework\TestCase;

/**
 * A CONFINED PROCESS STARTS FROM NOTHING OF ITS LAUNCHER.
 *
 * The confinement gives the command it runs a floor for an environment and its own namespaces. This pins the rest of
 * the property: nothing that lives inside the confinement — the command, or any process beside it — carries the
 * launcher's environment or one of the descriptors the launcher held open, and the command's standard input is
 * nothing. Each probe has its control: the same command, run plain, does see the launcher's variable and descriptor.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(TrialRunner::class)]
final class AConfinedProcessStartsFromNothingOfItsLauncherTest extends TestCase
{
    /** Printed by every probe once it runs, so a confinement that never ran is not read as one that saw nothing. */
    private const RAN = '__the-probe-ran__';

    private string $tree;

    protected function setUp(): void
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('bubblewrap cannot confine here');
        }
        $this->tree = sys_get_temp_dir() . '/milpa-from-nothing-' . bin2hex(random_bytes(4));
        mkdir($this->tree, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tree) && is_dir($this->tree)) {
            array_map('unlink', glob($this->tree . '/*') ?: []);
            rmdir($this->tree);
        }
    }

    public function testNoProcessInsideTheConfinementCarriesTheLaunchersEnvironment(): void
    {
        $canary = 'canary' . bin2hex(random_bytes(8));
        putenv('MILPA_TEST_LAUNCHER_VARIABLE=' . $canary);
        $everyEnvironment = ['sh', '-c', 'echo ' . self::RAN . '; cat /proc/[0-9]*/environ 2>/dev/null | tr "\0" "\n"; true'];

        try {
            $confined = $this->confined($everyEnvironment);
            $plain = ChildProcess::run($everyEnvironment);
        } finally {
            putenv('MILPA_TEST_LAUNCHER_VARIABLE');
        }

        self::assertNotNull($confined);
        self::assertSame(0, $confined['exit'], $confined['stderr']);
        self::assertStringContainsString(self::RAN, $confined['stdout']);
        self::assertStringNotContainsString($canary, $confined['stdout'], 'a process inside the confinement carries the launcher\'s environment');
        self::assertStringContainsString('MILPA_TRIAL=1', $confined['stdout'], 'the confined command keeps its floor');
        self::assertNotNull($plain);
        self::assertStringContainsString($canary, $plain['stdout'], 'the control: a plain child does carry it, so the probe can see it');
    }

    public function testAConfinedCommandHoldsNoneOfTheLaunchersDescriptors(): void
    {
        $held = $this->tree . '/held-by-the-launcher.txt';
        file_put_contents($held, 'held');
        $handle = fopen($held, 'a+');
        $listening = stream_socket_server('tcp://127.0.0.1:0');
        // Enough further handles that some are numbered above 9: the launcher closes those too.
        $more = [];
        for ($i = 0; $i < 12; ++$i) {
            $more[] = fopen($held, 'r');
        }
        $openDescriptors = ['sh', '-c', 'echo ' . self::RAN . '; for f in /proc/self/fd/*; do readlink "$f"; done; true'];

        try {
            $confined = $this->confined($openDescriptors);
            $plain = ChildProcess::run($openDescriptors);
        } finally {
            \is_resource($handle) && fclose($handle);
            \is_resource($listening) && fclose($listening);
            array_map('fclose', $more);
        }

        self::assertNotNull($confined);
        self::assertSame(0, $confined['exit'], $confined['stderr']);
        self::assertStringContainsString(self::RAN, $confined['stdout']);
        self::assertStringNotContainsString('held-by-the-launcher.txt', $confined['stdout'], 'the confined command holds a file the launcher had open');
        self::assertStringNotContainsString('socket:', $confined['stdout'], 'the confined command holds a socket the launcher had open');
        self::assertNotNull($plain);
        self::assertStringContainsString('held-by-the-launcher.txt', $plain['stdout'], 'the control: a plain child does hold it');
        self::assertStringContainsString('socket:', $plain['stdout'], 'the control: a plain child does hold the socket');
    }

    /** @return iterable<string, array{string, string}> */
    public static function whatALauncherMayCarryForItsShell(): iterable
    {
        yield 'shell options' => ['SHELLOPTS', 'noglob'];
        yield 'a start-up file' => ['BASH_ENV', 'set -f'];
        yield 'a function named as a builtin' => ['BASH_FUNC_echo%%', '() { :; }'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatALauncherMayCarryForItsShell')]
    public function testWhatTheLauncherCarriesForItsShellDoesNotLeaveADescriptorOpen(string $variable, string $value): void
    {
        $held = $this->tree . '/held-by-the-launcher.txt';
        file_put_contents($held, 'held');
        if ($variable === 'BASH_ENV') {
            file_put_contents($this->tree . '/startup', $value . "\n");
            $value = $this->tree . '/startup';
        }
        $handles = [];
        for ($i = 0; $i < 12; ++$i) {
            $handles[] = fopen($held, 'r');
        }
        putenv($variable . '=' . $value);

        try {
            $confined = $this->confined(['sh', '-c', 'echo ' . self::RAN . '; for f in /proc/self/fd/*; do readlink "$f"; done; true']);
        } finally {
            putenv($variable);
            array_map('fclose', $handles);
        }

        // It runs with the descriptors closed, or it refuses: it never runs holding one.
        self::assertNotNull($confined);
        self::assertStringNotContainsString('held-by-the-launcher.txt', $confined['stdout']);
        self::assertContains($confined['exit'], [0, 126], $confined['stderr']);
        self::assertSame($confined['exit'] === 0, str_contains($confined['stdout'], self::RAN));
    }

    public function testTheStandardInputOfAConfinedCommandIsNothing(): void
    {
        $confined = $this->confined(['sh', '-c', 'echo ' . self::RAN . '; readlink /proc/self/fd/0']);

        self::assertNotNull($confined);
        self::assertSame(self::RAN . "\n/dev/null\n", $confined['stdout'], $confined['stderr']);
    }

    public function testALaunchThatCannotCloseADescriptorRefusesAndRunsNothing(): void
    {
        $names = ChildProcess::run(['sh', '-c', 'eval "exec 10>&-"']);
        if ($names === null || $names['exit'] === 0) {
            self::markTestSkipped('the `sh` of this machine names any descriptor, so it closes them all');
        }
        $held = [];
        // Enough handles that one of them is numbered above 9, whatever this process already holds.
        for ($i = 0; $i < 12; ++$i) {
            $held[] = fopen(__FILE__, 'r');
        }
        $runner = new TrialRunner();
        (new \ReflectionProperty($runner, 'launcherShell'))->setValue($runner, 'sh');
        $confinement = $runner->confinement($this->tree, $this->tree);
        self::assertNotNull($confinement);

        try {
            $refused = ChildProcess::run([...$confinement, '--', 'sh', '-c', 'echo ' . self::RAN]);
        } finally {
            array_map('fclose', $held);
        }

        self::assertNotNull($refused);
        self::assertSame(126, $refused['exit']);
        self::assertStringNotContainsString(self::RAN, $refused['stdout'], 'the command ran although a descriptor of the launcher was still open');
        self::assertStringContainsString('could not be closed', $refused['stderr']);
    }

    public function testEveryConfinedCommandOfTheRunnerIsStartedTheSameWay(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Agent/TrialRunner.php');

        // The confinement a door asks for, a trial's run and a call of work in the house: the three commands that
        // carry the mask. The two probes that only ask whether a namespace can be made run `exit(0)` and nothing else.
        self::assertSame(3, substr_count($source, '...$this->fromNothing(),'), 'a confined command of the runner is not started from nothing');
        self::assertSame(3, substr_count($source, '$this->maskArgs('), 'a command carries the mask that this test does not know');
        self::assertSame(2, substr_count($source, 'ChildProcess::run([$this->bwrap,'), 'bubblewrap is started directly somewhere other than the two namespace probes');
        self::assertSame(2, preg_match_all('/\$this->bwrap, \.\.\.\$\w+, .*\'exit\(0\);\'\]/', $source), 'a direct start of bubblewrap runs something other than `exit(0)`');
    }

    /**
     * @param list<string> $command
     *
     * @return array{exit: int, stdout: string, stderr: string}|null
     */
    private function confined(array $command): ?array
    {
        $confinement = (new TrialRunner())->confinement($this->tree, $this->tree);
        self::assertNotNull($confinement);

        return ChildProcess::run([...$confinement, '--', ...$command]);
    }
}
