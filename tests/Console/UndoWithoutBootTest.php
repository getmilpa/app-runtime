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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Tests\Fixtures\LabSigner;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * `coa sandbox:undo` returns a house whose promoted code broke the boot (greenhouse decisions/0506).
 *
 * Measured in evidence/1038 (n5): the CLI booted the same broken house and died of the same compile fatal;
 * the pre-image was there and only a person could put it back. Every call here runs `bin/coa`'s own
 * `Application` in a CHILD process — a regression is a fatal, and a fatal must not take the test runner with it.
 *
 * @guards a signed undo returns the house without booting it, and says whether it boots again
 *
 * @refuses a presented token (it cannot be judged without the kernel) and a key whose enrollment does not
 *          answer for the files the undo would write — the same checks as a booting house, never a wider one
 *
 * @subject-in milpa/app-runtime
 */
final class UndoWithoutBootTest extends TestCase
{
    private string $root;

    private string $plugins;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
        // The house declares the trial doors the way a founded app does.
        file_put_contents($this->root . '/config/operations.php', '<?php return [' . TrialOperations::class . "::class];\n");
        $this->plugins = (string) file_get_contents($this->root . '/config/plugins.php');
        $this->promote(broken: true);
        self::assertNotNull((new BootProbe())->whyNot($this->root), 'the promotion broke the boot');
    }

    /**
     * Promote a trial that registers a plugin `Roto` — whose class misses an interface method when `$broken`
     * (the shape of evidence/1038 n5: a compile fatal at boot), or a well-formed one otherwise.
     */
    private function promote(bool $broken): void
    {
        $ws = TrialWorkspace::materialize($this->root, 'w1', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        @mkdir($ws->copy . '/src/Plugins/Roto', 0o777, true);
        file_put_contents($ws->copy . '/src/Plugins/Roto/Roto.php', TinyHouse::pluginSource('Roto', broken: $broken));
        file_put_contents($ws->copy . '/config/plugins.php', TinyHouse::pluginsFile('Blog', 'Roto'));
        // Promoted the way the published train did (evidence/1036, R1): without asking whether the house still
        // boots. Since 0506 a promotion refuses to land a broken boot; the undo is for houses broken anyway —
        // by an older runtime, a person's editor, a `composer` step.
        foreach ((new TrialOperations(new DIContainer(), null, $this->root, null, null))->operations() as $op) {
            if ($op->name === 'sandbox:promote') {
                $receipt = ($op->handler)(['workspace' => 'w1']);
                self::assertTrue($receipt['ok'] ?? false, (string) json_encode($receipt));
            }
        }
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    public function testASignedUndoReturnsTheHouseWithoutBootingIt(): void
    {
        [$exit, $out] = $this->coa(['sandbox:undo', '--workspace=w1', '--sign']);

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('The house does not boot: Fatal error: Class App\Plugins\Roto\Roto contains 1 abstract method', $out);
        self::assertStringContainsString('✓ authorized by', $out);
        self::assertStringContainsString('✓ The house boots again.', $out);
        self::assertSame($this->plugins, file_get_contents($this->root . '/config/plugins.php'), 'the pre-image is back');
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Roto/Roto.php', 'what the promotion added is gone');
        self::assertNull((new BootProbe())->whyNot($this->root));
    }

    /**
     * Unsigned, from a local shell, the undo runs — exactly as it does in a house that boots.
     *
     * `sandbox:undo` declares `WriteAsUser`, below the `Privileged` that makes the terminal demand a signature
     * (Consent::demanded, rule S2), and an unidentified local shell keeps its wildcard (greenhouse decisions/0311).
     * This door must not be STRICTER in secret either: the parity is asserted against the ordinary door below.
     */
    public function testAnUnsignedUndoFromALocalShellRunsAsItDoesWhenTheHouseBoots(): void
    {
        [$exit, $out] = $this->coa(['sandbox:undo', '--workspace=w1']);

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('authorized by', $out);
        self::assertStringContainsString('✓ The house boots again.', $out);
    }

    public function testAPresentedTokenIsRefusedNotReadAsNoToken(): void
    {
        [$exit, $out] = $this->coa(['sandbox:undo', '--workspace=w1', '--sign'], ['MILPA_TOKEN' => 'a-narrow-token']);

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('MILPA_TOKEN is presented', $out);
        self::assertStringNotContainsString('authorized by', $out, 'refused before any signature is spent');
        $this->assertStillBroken();
    }

    /** A seat's key answers for its own plugin, not for the house's plugin list: the write set refuses, as when the house boots. */
    public function testAKeyWhoseEnrollmentDoesNotCoverTheWriteSetIsRefused(): void
    {
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))->record(new IdentityEnrolled(
            (new LabSigner())->fingerprint,
            ['agent:run', 'agent:read', 'plugins:read', 'plugins:write'],
            'key:0000',
        ));

        [$exit, $out] = $this->coa(['sandbox:undo', '--workspace=w1', '--sign']);

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString("Missing required permission 'plugins.config:write'", $out);
        $this->assertStillBroken();
    }

    /**
     * PARITY with the ordinary door: the same undo, asked of a house that boots (the kernel judges) and of one
     * that does not (this door judges) — the same verdict for every caller that both doors can judge.
     *
     * @return iterable<string, array{0: list<string>, 1: ?list<string>, 2: int}>
     */
    public static function callers(): iterable
    {
        yield 'unsigned local shell' => [[], null, 0];
        yield 'signed, key never enrolled' => [['--sign'], null, 0];
        yield 'signed, seat key without plugins.config:write' => [['--sign'], ['agent:run', 'plugins:write'], 1];
        yield 'signed, key that answers for the write set' => [['--sign'], ['plugins.config:write', 'plugins.Roto:write'], 0];
    }

    /**
     * @param list<string>      $flags
     * @param list<string>|null $enrolled
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('callers')]
    public function testBothDoorsJudgeTheSameCallerTheSameWay(array $flags, ?array $enrolled, int $expected): void
    {
        $verdicts = [];
        foreach (['does not boot', 'boots'] as $house) {
            if ($house === 'boots') {
                TinyHouse::remove($this->root);
                $this->setUpBootingHouse();
            }
            if ($enrolled !== null) {
                (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))->record(new IdentityEnrolled((new LabSigner())->fingerprint, $enrolled, 'key:0000'));
            }
            [$exit, $out] = $this->coa(['sandbox:undo', '--workspace=w1', ...$flags]);
            self::assertSame($house === 'does not boot', str_contains($out, 'Recovering without booting it'), $out);
            $verdicts[$house] = $exit;
            self::assertSame($expected, $exit, "{$house}: {$out}");
        }
        self::assertSame($verdicts['boots'], $verdicts['does not boot']);
    }

    private function setUpBootingHouse(): void
    {
        $this->root = TinyHouse::create('Blog');
        file_put_contents($this->root . '/config/operations.php', '<?php return [' . TrialOperations::class . "::class];\n");
        $this->promote(broken: false);
        self::assertNull((new BootProbe())->whyNot($this->root), 'this house boots after its promotion');
    }

    private function assertStillBroken(): void
    {
        self::assertSame(TinyHouse::pluginsFile('Blog', 'Roto'), file_get_contents($this->root . '/config/plugins.php'));
        self::assertFileExists($this->root . '/var/trials/w1/promoted.json', 'the promotion stays undoable');
    }

    /**
     * Run `coa` in a child process with the lab key in place of gpg.
     *
     * @param list<string>          $argv
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string}
     */
    private function coa(array $argv, array $env = []): array
    {
        $script = $this->root . '/var/coa.php';
        file_put_contents($script, '<?php
require ' . var_export($this->root . '/vendor/autoload.php', true) . ';
$key = new Milpa\AppRuntime\Tests\Fixtures\LabSigner();
$app = new Milpa\AppRuntime\Console\Application(' . var_export($this->root, true) . ', $key, $key);
exit($app->run(["coa", ...json_decode($argv[1], true)]));
');
        $prefix = 'MILPA_TOKEN= ';
        foreach ($env as $name => $value) {
            $prefix .= $name . '=' . escapeshellarg($value) . ' ';
        }
        exec($prefix . escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script) . ' ' . escapeshellarg((string) json_encode($argv)) . ' 2>&1', $out, $exit);

        return [$exit, implode("\n", $out)];
    }
}
