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

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use PHPUnit\Framework\TestCase;

/**
 * A writer the house boots a copy for (greenhouse decisions/0515) still boots it from inside a rehearsal's trial.
 *
 * ── THE DEFECT, MEASURED (greenhouse evidence/1060, finding 1) ──────────────────────────────────
 *
 * `plugins_disable` from `coa chat` or `coa agent` answered «no process could be started to boot the house»,
 * on the published train too. The tool runs in the confined trial (`bwrap --ro-bind / /`), and bwrap binds
 * read-only WITHOUT devices: `/dev/null` is there and cannot be opened. BootProbe handed it to the child as
 * stdin, so proc_open failed before any process existed. git opens `/dev/null` at every start, so git did
 * not run there either.
 *
 * Two confinements are asked from inside. The one of 1060 exactly — no device at all — where the house's
 * own children must not need one; and the trial a leg runs today, through the real TrialRunner, which binds
 * `/dev/null` back (and nothing else) so git and a shell's redirects work.
 *
 * @guards with no device at all, a fresh process boots the house, a witnessed write lands and says
 *         `house_boots`, and the route observer lists routes; in a leg's trial `/dev/null` opens and no other
 *         device does, the witnessed write lands in the copy and never in the host, and git answers for
 *         framework:apply's way back and a secret overlay's ignore line
 *
 * @subject-in milpa/app-runtime
 */
final class AWriterBootsItsCopyInsideATrialTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    public function testWithNoDeviceAtAllTheControlCannotOpenDevNull(): void
    {
        self::assertFalse($this->withoutDevices('none')['dev_null_opens'], 'the confinement of evidence/1060, where /dev/null does not open');
    }

    public function testWithNoDeviceAtAllAFreshProcessBootsTheHouse(): void
    {
        self::assertNull($this->withoutDevices('probe')['why_not']);
    }

    public function testWithNoDeviceAtAllAWitnessedWriteLandsAndSaysTheHouseBoots(): void
    {
        $run = $this->withoutDevices('disable');

        self::assertNull($run['boot']['refused'], (string) json_encode($run['boot']));
        self::assertSame(['house_boots' => true], $run['boot']['said']);
        self::assertSame("<?php return [];\n", $run['plugins']);
    }

    public function testWithNoDeviceAtAllTheRouteObserverListsRoutes(): void
    {
        file_put_contents($this->root . '/public/index.php', "<?php\n");

        self::assertSame(['observed' => []], $this->withoutDevices('observe')['routes'], 'the house booted to list its routes (it declares none)');
    }

    public function testALegsTrialOpensDevNullAndNoOtherDevice(): void
    {
        $run = $this->inTrial('none');

        self::assertTrue($run['dev_null_opens']);
        self::assertFalse($run['dev_zero_opens'], 'the sink is bound back, not the host /dev');
    }

    public function testInALegsTrialAWitnessedWriteLandsInTheCopyAndNeverInTheHost(): void
    {
        $run = $this->inTrial('disable');

        self::assertNull($run['boot']['refused'], (string) json_encode($run['boot']));
        self::assertSame(['house_boots' => true], $run['boot']['said']);
        self::assertSame("<?php return [];\n", $run['plugins'], 'written in the copy');
        self::assertStringContainsString('Blog::class', (string) file_get_contents($this->root . '/config/plugins.php'), 'and never in the host');
    }

    public function testInALegsTrialGitAnswersForWhatAsksItBeforeWriting(): void
    {
        file_put_contents($this->root . '/.gitignore', "/var/\n/vendor/\n/.milpa/secrets.json\n");
        foreach (['init -q', 'add -A', '-c user.email=t@t -c user.name=t commit -q -m house'] as $git) {
            exec('git -C ' . escapeshellarg($this->root) . ' ' . $git, $_, $code);
            self::assertSame(0, $code, "git {$git}");
        }

        $run = $this->inTrial('git');

        self::assertNull($run['way_back'], 'framework:apply sees the repository, and git holds a clean copy of the file');
        self::assertNull($run['ignore_missing'], 'a secret overlay sees its ignore line');
    }

    /** @return array<string, mixed> */
    private function inTrial(string $operation): array
    {
        $runner = new TrialRunner();
        if (!$runner->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $workspace = TrialWorkspace::materialize($this->root, 'boot-' . $operation, $this->script());

        $run = $runner->run($workspace, $operation, []);

        self::assertSame(0, $run->exit, $run->stdout . $run->stderr);
        self::assertIsArray($run->output, $run->stdout . $run->stderr);

        return $run->output;
    }

    /**
     * The confinement 1060 measured, and nothing added: the whole host read-only (so no device opens), the house writable.
     *
     * @return array<string, mixed>
     */
    private function withoutDevices(string $operation): array
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        copy($this->script(), $this->root . '/probe.php');
        $command = ['bwrap', '--unshare-net', '--unshare-pid', '--die-with-parent', '--ro-bind', '/', '/',
            '--bind', $this->root, $this->root, '--', \PHP_BINARY, $this->root . '/probe.php', $operation];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $exit);
        $output = json_decode((string) end($lines), true);

        self::assertSame(0, $exit, implode("\n", $lines));
        self::assertIsArray($output, implode("\n", $lines));

        return $output;
    }

    private function script(): string
    {
        return \dirname(__DIR__) . '/Fixtures/trial-boot-witness-runner.php';
    }
}
