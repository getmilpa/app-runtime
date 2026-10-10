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
use Milpa\AppRuntime\Config\Containment;
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
 * DECLARED, NOT CHECKED (greenhouse decisions/0612, decided by Rod on 2026-10-10; it amends the rule «with both,
 * silence» of evidence/1181). Measured in the public image (evidence/1186): a declaration silenced the doctor in a
 * container started privileged, with a home mounted, with Docker's socket, with a keyring — a house that says nothing
 * reads as a house that is safe. So a declared house is ALWAYS said: where the declaration is read, that the house
 * does not check it, and what an agent run in it still reaches.
 *
 * And the step tells a bubblewrap that is MISSING from one that is there and is refused a namespace: the image
 * carries it, and under Docker's own profile the doctor told a person to install what was installed.
 *
 * @guards one line, in the doctor's own marks, in every state: `!` when neither holds, with both steps; `·` when a
 *         trial is confined, or the house is declared — and then where it is declared, «declared, not checked», and
 *         what is still within reach. Never a failure: the exit is what it was
 *
 * @refuses a house read as contained for a value that names nothing that holds one; a doctor that turns red — and
 *          `coa update`'s boot check with it — on every machine without bubblewrap; a declared house the doctor is
 *          silent about; «install bubblewrap» said of a bubblewrap that is installed
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
        // …and one that is there and is refused a namespace: what the image's bubblewrap is under Docker's own profile.
        file_put_contents($this->root . '/bwrap-that-is-refused', "#!/bin/sh\necho 'bwrap: No permissions to create new namespace' >&2\nexit 1\n");
        chmod($this->root . '/bwrap-that-is-refused', 0o755);
        putenv(Containment::VARIABLE);
    }

    protected function tearDown(): void
    {
        putenv(Containment::VARIABLE);
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
        self::assertStringContainsString('declared, not checked', $line);
        self::assertStringContainsString('this machine cannot confine a trial', $line);
        self::assertStringContainsString('install bubblewrap (bwrap)', $line);
        self::assertStringNotContainsString('config:set agent.contained', $line, 'the declaration is there: no step about it');
        self::assertSame(0, $exit);
    }

    public function testADedicatedUserIsNamedAsWhatHoldsIt(): void
    {
        $this->app(['agent' => ['contained' => 'user']]);

        [, $out] = $this->doctor(confines: false);

        self::assertStringStartsWith('  · this house is declared contained (user)', self::theLine($out));
    }

    /**
     * THE RULE THIS AMENDS (evidence/1181: «with both, silence»). In the public image a declaration plus the trial
     * profile silenced the doctor in a container started privileged, with a home mounted, with Docker's socket, with
     * a keyring (evidence/1186). The house cannot check what it declares, so it never stops saying that it declares.
     */
    public function testWithBothItStillSaysTheHouseIsDeclaredAndThatNothingChecksIt(): void
    {
        $this->app(['agent' => ['contained' => 'container']]);

        [$exit, $out] = $this->doctor(confines: true);
        $line = self::theLine($out);

        self::assertStringStartsWith('  · this house is declared contained (container)', $line, 'said, not silent');
        self::assertStringContainsString('declared, not checked', $line);
        self::assertStringContainsString('a trial runs confined on this machine', $line, 'and what does hold is said too');
        self::assertStringNotContainsString('bubblewrap', $line, 'the tool confines: no step about it');
        self::assertStringNotContainsString('config:set agent.contained', $line, 'the declaration is there: no step about it');
        self::assertSame(0, $exit, 'named, not failed');
    }

    /** «Contained» is not «safe»: what holds a house also hands it things, and the line says they are within reach. */
    public function testADeclaredHouseIsNeverSaidAsSafe(): void
    {
        foreach ([true, false] as $confines) {
            $this->app(['agent' => ['contained' => 'container']]);
            [, $out] = $this->doctor($confines);
            self::assertStringContainsString('what an agent runs here still reaches everything mounted into that container', self::theLine($out));

            $this->app(['agent' => ['contained' => 'user']]);
            [, $out] = $this->doctor($confines);
            self::assertStringContainsString('what an agent runs here still reaches everything that user can read', self::theLine($out));
            self::assertStringNotContainsString('safe', self::theLine($out));
        }
    }

    /**
     * WHO DECLARED IT, as far as a house can tell: which of its two files says so — the person's `config/app.php`,
     * or the one `config:set` writes — and, when the person's file says what the variable of whoever started the
     * house says, that it came from there (in the image, the baked config reads it: greenhouse decisions/0612).
     */
    public function testTheLineSaysWhereTheDeclarationIsRead(): void
    {
        $this->app(['agent' => ['contained' => 'container']]);
        [, $out] = $this->doctor(confines: true);
        self::assertStringContainsString('declared contained (container) in config/app.php —', self::theLine($out));

        putenv(Containment::VARIABLE . '=container');
        [, $out] = $this->doctor(confines: true);
        self::assertStringContainsString('declared contained (container) by whoever started it (MILPA_AGENT_CONTAINED in its environment) —', self::theLine($out));

        putenv(Containment::VARIABLE . '=user');
        [, $out] = $this->doctor(confines: true);
        self::assertStringContainsString('declared contained (container) in config/app.php —', self::theLine($out), 'a variable that says something else did not declare this');

        putenv(Containment::VARIABLE . '=container');
        mkdir($this->root . '/.milpa');
        file_put_contents($this->root . '/.milpa/agent.json', '{"agent":{"contained":"user"}}');
        [, $out] = $this->doctor(confines: true);
        self::assertStringContainsString('declared contained (user) in .milpa/agent.json, where `config:set` writes it —', self::theLine($out), 'the machine\'s own file is the one read, and the one named');
        self::assertStringContainsString('«agent.contained» está en config/app.php Y en .milpa/agent.json', $out, 'and that both files declare it is the doctor\'s own line, as for any key');

        putenv(Containment::VARIABLE . '=user');
        [, $out] = $this->doctor(confines: true);
        self::assertStringContainsString('declared contained (user) in .milpa/agent.json, where `config:set` writes it —', self::theLine($out), 'the file that is read is the one named, whatever the variable says');
    }

    /** A variable nobody's configuration reads declares nothing: the house is not contained because a launcher wished it. */
    public function testTheVariableAloneDeclaresNothing(): void
    {
        $this->app([]);
        putenv(Containment::VARIABLE . '=container');

        [, $out] = $this->doctor(confines: true);

        self::assertStringStartsWith('  · a trial runs confined on this machine; the house itself is not declared contained', self::theLine($out));
    }

    /**
     * THE STEP THAT WAS FALSE (evidence/1186): the image carries bubblewrap, and under Docker's own profile the
     * doctor said «install bubblewrap (bwrap)». What is missing there is leave to make a namespace, not the tool.
     */
    public function testABubblewrapThatIsThereAndRefusedIsNotToldToBeInstalled(): void
    {
        $this->app([]);
        [$exit, $out] = $this->doctor(confines: false, toolIsThere: true);
        $line = self::theLine($out);

        self::assertStringStartsWith('  ! nothing contains what an agent runs in this house', $line);
        self::assertStringContainsString('this machine cannot confine a trial (bubblewrap is installed, and is refused a namespace here)', $line);
        self::assertStringContainsString('asked for and then runs in the house itself', $line);
        self::assertStringNotContainsString('install bubblewrap', $line, 'it is installed');
        self::assertStringContainsString('let bubblewrap make a user namespace here', $line, 'the step that is true');
        self::assertStringContainsString('the seccomp profile it is started with', $line, 'what refuses it in a container');
        self::assertStringContainsString('php bin/coa config:set agent.contained container --sign', $line, 'and the other step, as before');
        self::assertSame(0, $exit);

        $this->app(['agent' => ['contained' => 'container']]);
        [, $out] = $this->doctor(confines: false, toolIsThere: true);
        $line = self::theLine($out);

        self::assertStringStartsWith('  · this house is declared contained (container)', $line);
        self::assertStringContainsString('(bubblewrap is installed, and is refused a namespace here)', $line);
        self::assertStringNotContainsString('install bubblewrap', $line);
        self::assertStringContainsString('let bubblewrap make a user namespace here', $line);
        self::assertStringContainsString('to try it on a copy first', $line);
    }

    public function testABubblewrapThatIsMissingIsStillToldToBeInstalled(): void
    {
        foreach ([[], ['agent' => ['contained' => 'user']]] as $config) {
            $this->app($config);
            [, $out] = $this->doctor(confines: false, toolIsThere: false);
            $line = self::theLine($out);

            self::assertStringContainsString('install bubblewrap (bwrap)', $line);
            self::assertStringNotContainsString('is refused a namespace', $line);
            self::assertStringNotContainsString('let bubblewrap', $line);
        }
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

    /**
     * The ONE line the doctor says about containment; fails when there is none, or more than one. Every state of it
     * says «declared contained» — is, or is not. (A key both files declare has the doctor's own line, and is not this.)
     */
    private static function theLine(string $out): string
    {
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => str_contains($line, 'declared contained')));
        self::assertCount(1, $lines, "one line about containment, in:\n" . $out);

        return $lines[0];
    }

    /** @param array<string, mixed> $config */
    private function app(array $config): void
    {
        file_put_contents($this->root . '/config/app.php', '<?php return ' . var_export($config, true) . ";\n");
    }

    /**
     * Runs `coa doctor` on a machine that can confine a trial, or cannot — because bubblewrap is missing, or because
     * it is there and is refused a namespace.
     *
     * @param array<string, mixed> $boot what the boot probe's child answers
     *
     * @return array{0: int, 1: string}
     */
    private function doctor(bool $confines, array $boot = ['ok' => true], bool $toolIsThere = false): array
    {
        $script = $this->root . '/observe.php';
        file_put_contents($script, '<?php echo "@@house-observe " . ' . var_export((string) json_encode($boot), true) . ' . "\n"; exit(' . (($boot['ok'] ?? false) === true ? 0 : 1) . ');');
        $runner = new TrialRunner($this->root . match (true) {
            $confines => '/bwrap-that-confines',
            $toolIsThere => '/bwrap-that-is-refused',
            default => '/no-such-bwrap',
        });

        ob_start();
        $exit = (new Application($this->root, bootProbe: new BootProbe(script: $script), trialRunner: $runner))->run(['coa', 'doctor']);

        return [$exit, (string) ob_get_clean()];
    }
}
