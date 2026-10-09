<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * `coa doctor` says what contains what an agent runs in this house (greenhouse decisions/0607, annex, slice 2).
 *
 * Two things can: the machine confines a trial (bubblewrap), or the house runs inside something that holds it and
 * says so (`agent.contained`, slice 1). Measured on a published house (evidence/1181): with no bubblewrap there is no
 * trial — the change is asked for and, once allowed, written in the house itself — and the doctor said nothing of it,
 * with the tool or without.
 *
 * @guards one line, in the doctor's own marks: `!` when neither holds, with both steps; `·` when exactly one holds,
 *         naming which and the step for the other; nothing when both hold. Never a failure: the exit is what it was
 *
 * @refuses a house read as contained for a value that names nothing that holds one; a doctor that turns red — and
 *          `coa update`'s boot check with it — on every machine without bubblewrap
 */
final class TheDoctorSaysWhatContainsTheHouseTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-contained-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/vendor', 0o777, true);
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n");
        file_put_contents($this->root . '/config/boot.php', "<?php\nreturn ['container' => new \\" . DIContainer::class . "(), 'plugins' => []];\n");
        file_put_contents($this->root . '/config/plugins.php', "<?php\n\nreturn [];\n");
        // A confinement tool that answers the runner's probe, and none at all: what the test controls.
        file_put_contents($this->root . '/bwrap-that-confines', "#!/bin/sh\nexit 0\n");
        chmod($this->root . '/bwrap-that-confines', 0o755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testWithNoConfinementAndNoDeclarationItWarnsWithBothSteps(): void
    {
        $this->app([]);

        [$exit, $out] = $this->doctor(confines: false);
        $line = self::theLine($out);

        self::assertStringStartsWith('  ! nothing contains what an agent runs in this house', $line);
        self::assertStringContainsString('this machine cannot confine a trial', $line);
        self::assertStringContainsString('asked for and then runs in the house itself', $line, 'what the house does without the tool, as measured');
        self::assertStringContainsString('the house is not declared contained', $line);
        self::assertStringContainsString('install bubblewrap (bwrap)', $line, 'one step');
        self::assertStringContainsString('php bin/coa config:set agent.contained container --sign', $line, 'and the other, as it is typed');
        self::assertSame(0, $exit, 'named, not failed: `coa update` reads this exit as its boot check');
    }

    public function testWithATrialConfinedAndNoDeclarationItSaysSoWithoutWarning(): void
    {
        $this->app([]);

        [$exit, $out] = $this->doctor(confines: true);
        $line = self::theLine($out);

        self::assertStringStartsWith('  · a trial runs confined on this machine', $line);
        self::assertStringContainsString('the house itself is not declared contained', $line);
        self::assertStringContainsString('php bin/coa config:set agent.contained container --sign', $line);
        self::assertStringNotContainsString('bubblewrap', $line, 'the tool is there: no step about it');
        self::assertSame(0, $exit);
    }

    public function testWithTheHouseDeclaredContainedAndNoConfinementItSaysSoWithoutWarning(): void
    {
        $this->app(['agent' => ['contained' => 'container']]);

        [$exit, $out] = $this->doctor(confines: false);
        $line = self::theLine($out);

        self::assertStringStartsWith('  · this house is declared contained (container)', $line);
        self::assertStringContainsString('this machine cannot confine a trial', $line);
        self::assertStringContainsString('install bubblewrap (bwrap)', $line);
        self::assertStringNotContainsString('config:set', $line, 'the declaration is there: no step about it');
        self::assertSame(0, $exit);
    }

    public function testADedicatedUserIsNamedAsWhatHoldsIt(): void
    {
        $this->app(['agent' => ['contained' => 'user']]);

        [, $out] = $this->doctor(confines: false);

        self::assertStringStartsWith('  · this house is declared contained (user)', self::theLine($out));
    }

    public function testWithBothItSaysNothing(): void
    {
        $this->app(['agent' => ['contained' => 'container']]);

        [$exit, $out] = $this->doctor(confines: true);

        self::assertStringNotContainsString('contained', $out, 'a report that prints a line to say «nothing» trains people to skip it');
        self::assertStringNotContainsString('confine', $out);
        self::assertSame(0, $exit);
    }

    public function testTheMachinesOwnFileDeclaresItToo(): void
    {
        $this->app([]);
        mkdir($this->root . '/.milpa');
        file_put_contents($this->root . '/.milpa/agent.json', '{"agent":{"contained":"container"}}');

        [, $out] = $this->doctor(confines: false);

        self::assertStringStartsWith('  · this house is declared contained (container)', self::theLine($out), 'what `config:set` writes is what is read');
    }

    /**
     * A value that names nothing that holds a house is NOT a declaration — «true» does not say what contains it, and
     * a product's name is not something this house knows. It is said, and read as not contained.
     */
    public function testAValueThatNamesNothingThatHoldsAHouseIsSaidAndNotBelieved(): void
    {
        foreach ([true, 'docker', 'yes', 1] as $value) {
            $this->app(['agent' => ['contained' => $value]]);

            [, $out] = $this->doctor(confines: false);
            $line = self::theLine($out);

            self::assertStringStartsWith('  ! nothing contains what an agent runs in this house', $line, var_export($value, true) . ' is not believed');
            self::assertStringContainsString('«agent.contained» says ' . json_encode($value) . ', which names nothing that holds a house', $line);
        }
        foreach ([false, null] as $value) {
            $this->app(['agent' => ['contained' => $value]]);

            [, $out] = $this->doctor(confines: false);

            self::assertStringNotContainsString('«agent.contained» says', self::theLine($out), 'declared not contained is the default, said plainly');
        }
    }

    public function testAHouseThatDoesNotBootIsStillToldSoAndTheLineChangesNothingOfIt(): void
    {
        $this->app([]);

        [$exit, $out] = $this->doctor(confines: false, boot: ['ok' => false, 'error' => 'RuntimeException: a plugin boot']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('✗ this house does not boot: RuntimeException: a plugin boot', $out);
        self::assertStringContainsString('nothing contains what an agent runs in this house', $out, 'said before the verdict, like the trial that holds a secret');
    }

    /** The ONE line the doctor says about containment; fails when there is none, or more than one. */
    private static function theLine(string $out): string
    {
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => str_contains($line, 'contained')));
        self::assertCount(1, $lines, "one line about containment, in:\n" . $out);

        return $lines[0];
    }

    /** @param array<string, mixed> $config */
    private function app(array $config): void
    {
        file_put_contents($this->root . '/config/app.php', '<?php return ' . var_export($config, true) . ";\n");
    }

    /**
     * Runs `coa doctor` on a machine that can confine a trial, or cannot.
     *
     * @param array<string, mixed> $boot what the boot probe's child answers
     *
     * @return array{0: int, 1: string}
     */
    private function doctor(bool $confines, array $boot = ['ok' => true]): array
    {
        $script = $this->root . '/observe.php';
        file_put_contents($script, '<?php echo "@@house-observe " . ' . var_export((string) json_encode($boot), true) . ' . "\n"; exit(' . (($boot['ok'] ?? false) === true ? 0 : 1) . ');');
        $runner = new TrialRunner($confines ? $this->root . '/bwrap-that-confines' : $this->root . '/no-such-bwrap');

        ob_start();
        $exit = (new Application($this->root, bootProbe: new BootProbe(script: $script), trialRunner: $runner))->run(['coa', 'doctor']);

        return [$exit, (string) ob_get_clean()];
    }
}
