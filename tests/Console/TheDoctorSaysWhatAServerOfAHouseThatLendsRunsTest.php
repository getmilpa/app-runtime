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
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Support\CompiledCode;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `coa doctor` tells a house that keeps a book of standing grants what its judge does not ask of OPcache
 * (greenhouse decisions/0615 — the seventh review, 7-L5; decided by Rod, 2026-10-13).
 *
 * The judge dates the files of an instrument against the second the asking process started. A server answers each
 * request as a new process over code OPcache may have compiled before a file changed: executed, with PHP's own
 * default a yes of the old code was written for two seconds after every change, six times of six; with
 * `opcache.revalidate_freq=0`, never. That is the configuration of whoever operates the house, and it is SAID —
 * the judge neither asks OPcache what it holds nor refuses a yes for it.
 *
 * @guards one line, in the doctor's own marks, to a house that keeps a book: `!` when PHP as the doctor's own
 *         process reads it does not look at its files on every request, `·` otherwise — saying that it is that
 *         process's reading. Never a failure: the exit is what it was
 *
 * @refuses a doctor that says this to a house where nobody granted anything; a line that reads as the server's own
 *          configuration when it is the terminal's
 */
final class TheDoctorSaysWhatAServerOfAHouseThatLendsRunsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-lends-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/vendor', 0o777, true);
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n");
        file_put_contents($this->root . '/config/boot.php', "<?php\nreturn ['container' => new \\" . DIContainer::class . "(), 'plugins' => []];\n");
        file_put_contents($this->root . '/config/plugins.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->root . '/config/app.php', "<?php return [];\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return iterable<string, array{string|false, string|false, string, string}> what PHP says, the mark, and what the line says of it */
    public static function whatPhpSays(): iterable
    {
        yield 'no OPcache in this process' => [false, false, '·', 'This process loads no OPcache: it cannot say how a server of this house is set.'];
        yield 'it looks every time' => ['1', '0', '·', 'looks every time (opcache.validate_timestamps=1, opcache.revalidate_freq=0)'];
        yield "PHP's own default" => ['1', '2', '!', 'looks again only every 2 s (opcache.validate_timestamps=1, opcache.revalidate_freq=2)'];
        yield 'it never looks again' => ['0', '2', '!', 'never looks again (opcache.validate_timestamps=0, opcache.revalidate_freq=2)'];
        yield 'it never looks again, as PHP spells an off switch' => ['', '0', '!', 'never looks again (opcache.validate_timestamps=0, opcache.revalidate_freq=0)'];
    }

    #[DataProvider('whatPhpSays')]
    public function testTheLineSaysWhatTheJudgeDoesNotAskAndWhatThisProcessReadsOfPhp(string|false $validates, string|false $every, string $mark, string $says): void
    {
        $line = CompiledCode::saidToAHouseThatLends($validates, $every);

        self::assertStringStartsWith('  ' . $mark . ' standing grants: the judge dates the files of an instrument, it does not ask OPcache what it compiled.', $line);
        self::assertStringContainsString('serve` looks every time (opcache.revalidate_freq=0)', $line);
        self::assertStringContainsString($says, $line);
        self::assertStringNotContainsString("\n", $line);
    }

    public function testTheDoctorSaysItToAHouseThatKeepsABookAndItsExitIsWhatItWas(): void
    {
        [$without, $silent] = $this->doctor();
        self::assertStringNotContainsString('standing grants:', $silent, 'control: a house where nobody granted anything is told nothing of it');
        mkdir($this->root . '/storage/identity', 0o777, true);
        file_put_contents($this->root . '/' . StandingLedger::PATH, "{}\n");

        [$exit, $out] = $this->doctor();

        self::assertSame($without, $exit);
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => str_contains($line, 'standing grants:')));
        self::assertSame([CompiledCode::saidToAHouseThatLends(\ini_get('opcache.validate_timestamps'), \ini_get('opcache.revalidate_freq'))], $lines);
    }

    /** @return array{0: int, 1: string} */
    private function doctor(): array
    {
        $script = $this->root . '/observe.php';
        file_put_contents($script, '<?php echo "@@house-observe " . ' . var_export((string) json_encode(['ok' => true]), true) . ' . "\n"; exit(0);');

        ob_start();
        $exit = (new Application($this->root, bootProbe: new BootProbe(script: $script), trialRunner: new TrialRunner($this->root . '/no-such-bwrap')))->run(['coa', 'doctor']);

        return [$exit, (string) ob_get_clean()];
    }
}
