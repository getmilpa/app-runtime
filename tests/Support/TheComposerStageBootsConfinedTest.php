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

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Console\RemoteOperationSigner;
use Milpa\AppRuntime\Console\UnsignedTerminal;
use Milpa\AppRuntime\Operations\CapabilityOperations;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\AcceptedOnTheConsole;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Support\HouseBootWitness;
use Milpa\AppRuntime\Tests\Fixtures\NeedsTheDataCapability;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use Milpa\AppRuntime\Tests\Fixtures\WhatTheConsoleHeldOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What Composer brings into the stage is code nobody applied, and it boots as a trial (greenhouse decisions/0627).
 *
 * evidence/1210 measured the staged Composer door on cattle: the stage booted PLAIN — the house's environment, its
 * secrets readable through the stage's links, the network open — although every other boot of unapplied code is
 * confined (0607; 0626). Rod's answers: the stage boots confined; where the machine cannot confine, the door refuses
 * unless a risk is accepted BY NAME, and saying UNCONFINED is not authorizing; Composer runs there without plugins or
 * scripts (no installable capability needs them, 1210).
 *
 * After the C11 review of this change: only a person, on the console, accepts that risk — no operation an agent calls
 * carries it — and words that name nothing accept nothing.
 */
final class TheComposerStageBootsConfinedTest extends TestCase
{
    private const ACCEPTED = 'my own laptop, and I trust lab/good';

    private string $root;

    /** @var list<array{0: string, 1: string}> */
    private array $ran = [];

    /** A stand-in `composer` first on PATH, for the doors that run the real command: what it was asked lands here. */
    private string $fakeLog = '';

    private string|false $path = false;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
        file_put_contents($this->root . '/composer.json', "{\n    \"require\": {}\n}\n");
        file_put_contents($this->root . '/composer.lock', "{\n    \"content-hash\": \"0\"\n}\n");
        // A dummy credential where Composer keeps its own: the stage links it, the confinement masks it.
        file_put_contents($this->root . '/auth.json', '{"canary": "not-a-real-credential"}');
    }

    protected function tearDown(): void
    {
        if ($this->path !== false) {
            putenv('PATH=' . $this->path);
        }
        TinyHouse::remove($this->root);
    }

    public function testTheStageBootsAsATrialWithTheHousesSecretsMasked(): void
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('this machine cannot confine (no bwrap): the refusal is tested below');
        }
        // The package refuses to boot IN THE STAGE unless it is a trial that cannot read the house's credential. The
        // live house's boot after the swap is plain by design (0606 §3), so the check is for the stage only.
        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/watch', $this->composer(<<<'PHP'
            if (str_contains(__DIR__, '/var/boot-candidates/')) {
                if (getenv('MILPA_TRIAL') !== '1') {
                    throw new \RuntimeException('the stage booted outside a trial');
                }
                if ((string) @file_get_contents(dirname(__DIR__, 3) . '/auth.json') !== '') {
                    throw new \RuntimeException('the stage read the house credential');
                }
            }
            PHP));

        self::assertNull($composed['refused'], (string) $composed['refused']);
        self::assertSame('a trial: confined, no network, the envelope and the keyring masked', $composed['said']['boot_room']);
        self::assertArrayNotHasKey('unconfined_accepted', $composed['said'], 'nothing to accept: it was confined');
        self::assertFileExists($this->root . '/vendor/lab/watch/files.php', 'it booted confined, so it landed');
    }

    public function testWhereNothingCanConfineTheDoorRefusesBeforeComposerRuns(): void
    {
        $composed = (new HouseBootWitness($this->root, $this->probeWithoutBwrap()))
            ->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertNotNull($composed['refused']);
        self::assertStringContainsString('saying UNCONFINED is not authorizing', $composed['refused']);
        self::assertSame([], $this->ran, 'Composer did not run: the boot it would need is refused');
        self::assertStringStartsWith('UNCONFINED:', $composed['said']['boot_room']);
        self::assertArrayHasKey('unconfined_accepted', $composed['said']);
        self::assertNull($composed['said']['unconfined_accepted']);
        self::assertStringContainsString('--accept-unconfined=', $composed['said']['hint'], 'the hint names the console, the one door that carries it');
        self::assertSame(['composer.json', 'composer.lock', 'vendor/'], $composed['said']['unwritten']);
        self::assertDirectoryDoesNotExist($this->root . '/vendor/lab', 'the live vendor/ is the one that was there');
    }

    public function testARiskAcceptedByNameLetsItBootUnconfinedAndTheAnswerCarriesBoth(): void
    {
        $composed = (new HouseBootWitness($this->root, $this->probeWithoutBwrap(), unconfinedAccepted: '  ' . self::ACCEPTED . ' '))
            ->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertNull($composed['refused'], (string) $composed['refused']);
        self::assertStringStartsWith('UNCONFINED:', $composed['said']['boot_room'], 'said, not hidden');
        self::assertSame(self::ACCEPTED, $composed['said']['unconfined_accepted'], 'the risk, in the words it was accepted with');
        self::assertFileExists($this->root . '/vendor/lab/good/files.php');

        // It stays in the record next to the UNCONFINED (0627, Rod's answer 1; C11 review of 0627, round 2, L-D).
        self::assertSame(HouseBootWitness::JOURNAL, $composed['said']['unconfined_recorded_in']);
        $lines = file($this->root . '/' . HouseBootWitness::JOURNAL, \FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true);
        self::assertSame(self::ACCEPTED, $entry['unconfined_accepted'] ?? null);
        self::assertSame('composer require lab/good --no-plugins --no-scripts --no-interaction', $entry['command'] ?? null);
        self::assertStringStartsWith('UNCONFINED', (string) ($entry['boot_room'] ?? ''));
    }

    public function testAnUnconfinedBootThatCannotBeRecordedIsNotRun(): void
    {
        mkdir($this->root . '/' . HouseBootWitness::JOURNAL, 0o777, true); // a directory where the line would be appended

        $composed = (new HouseBootWitness($this->root, $this->probeWithoutBwrap(), unconfinedAccepted: self::ACCEPTED))
            ->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertStringContainsString('could not be recorded', (string) $composed['refused']);
        self::assertSame([], $this->ran, 'Composer did not run');
        self::assertDirectoryDoesNotExist($this->root . '/vendor/lab');
    }

    public function testAConfinedBootRecordsNothing(): void
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('this machine cannot confine (no bwrap)');
        }
        $composed = (new HouseBootWitness($this->root, unconfinedAccepted: self::ACCEPTED))->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertNull($composed['refused'], (string) $composed['refused']);
        self::assertArrayNotHasKey('unconfined_recorded_in', $composed['said']);
        self::assertFileDoesNotExist($this->root . '/' . HouseBootWitness::JOURNAL, 'nothing ran unconfined, nothing to record');
    }

    /** @return iterable<string, array{string}> */
    public static function wordsThatNameNoRisk(): iterable
    {
        yield 'false, as a script says off' => ['false'];
        yield 'no' => ['no'];
        yield 'zero' => ['0'];
        yield 'yes, which names nothing either' => ['yes'];
        yield 'the room\'s own word' => ['UNCONFINED'];
        yield 'the room\'s word in Unicode blanks' => ["\u{00A0}UNCONFINED\u{2003}"];
        yield 'a non-breaking space alone' => ["\u{00A0}"];
        yield 'one word and punctuation' => ['unconfined?!'];
        yield 'a token, twice' => ['no no'];
        yield 'false, twice' => ['false false'];
        yield 'the room\'s word, twice' => ['UNCONFINED UNCONFINED'];
        yield 'a refusal in words' => ['do not'];
        yield 'two letters' => ['a b'];
        yield 'two zeros' => ['0 0'];
    }

    #[DataProvider('wordsThatNameNoRisk')]
    public function testWordsThatNameNoRiskAcceptNothing(string $given): void
    {
        $composed = (new HouseBootWitness($this->root, $this->probeWithoutBwrap(), unconfinedAccepted: $given))
            ->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertNotNull($composed['refused'], 'it was not accepted');
        self::assertStringContainsString('does not name a risk', $composed['refused']);
        self::assertSame([], $this->ran, 'Composer did not run');
        self::assertArrayHasKey('unconfined_accepted', $composed['said']);
        self::assertNull($composed['said']['unconfined_accepted']);
        self::assertSame(trim($given), $composed['said']['acceptance_refused'], 'what was given is said back, not taken');
        self::assertNull(HouseBootWitness::acceptance($given));
    }

    public function testWhatCannotBeReadAsWordsAcceptsNothingAndIsSaidBackEscaped(): void
    {
        foreach (["my laptop\x1b[31m the risk", "my laptop, I accept\nok:          1", "my\u{202E} laptop risk", "\xff\xfe my laptop risk"] as $given) {
            self::assertNull(HouseBootWitness::acceptance($given), bin2hex($given));
            $composed = (new HouseBootWitness($this->root, $this->probeWithoutBwrap(), unconfinedAccepted: $given))
                ->composeIfItBoots('composer require lab/good', $this->composer());
            self::assertNotNull($composed['refused']);
            self::assertSame([], $this->ran);
            $said = (string) $composed['said']['acceptance_refused'];
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $said . $composed['refused'], 'no raw control character is printed back');
            self::assertNotSame($given, $said, 'escaped, not echoed');
        }
    }

    public function testTheAcceptanceIsTheWordsTrimmedOfEveryBlank(): void
    {
        self::assertSame('my laptop, I accept it', HouseBootWitness::acceptance("\u{00A0} my laptop, I accept it\u{2003}\n"));
        self::assertNull(HouseBootWitness::acceptance(null));
    }

    public function testABlankAcceptanceAcceptsNothing(): void
    {
        $composed = (new HouseBootWitness($this->root, $this->probeWithoutBwrap(), unconfinedAccepted: "  \n"))
            ->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertNotNull($composed['refused']);
        self::assertSame([], $this->ran);
        self::assertArrayNotHasKey('acceptance_refused', $composed['said'], 'nothing was given to refuse');
    }

    public function testComposerRunsOnTheStageWithoutPluginsOrScriptsAndTheFirstInstallKeepsItsCommand(): void
    {
        (new HouseBootWitness($this->root, $this->probeWithoutBwrap(), unconfinedAccepted: self::ACCEPTED))
            ->composeIfItBoots('composer require lab/good', $this->composer());
        self::assertSame('composer require lab/good --no-plugins --no-scripts --no-interaction', $this->ran[0][0]);
        self::assertStringContainsString('/var/boot-candidates/', $this->ran[0][1], 'it ran on the stage');

        // A house with no vendor/ yet is another door (0627, «NO decide»): its command is as it was.
        $this->ran = [];
        unlink($this->root . '/vendor/autoload.php');
        (new HouseBootWitness($this->root))->composeIfItBoots('composer install', $this->composer());
        self::assertSame(['composer install --no-interaction'], array_column($this->ran, 0));
    }

    public function testNoOperationAnAgentCallsCarriesTheAcceptance(): void
    {
        // No operation an agent calls carries the acceptance: only a person gives it, on the console (decisions/0627).
        $seen = [];
        foreach ((new CapabilityOperations())->operations() as $operation) {
            if (!\in_array($operation->name, ['capabilities:enable', 'repair'], true)) {
                continue;
            }
            $seen[] = $operation->name;
            self::assertArrayNotHasKey('accept_unconfined', $operation->inputSchema['properties'] ?? [], $operation->name);
            self::assertNotContains('accept_unconfined', $operation->effects->escalatesOn, $operation->name);
        }
        sort($seen);
        self::assertSame(['capabilities:enable', 'repair'], $seen, 'both operations that compose were looked at');
    }

    public function testTheOperationsRefuseWhereNothingConfinesWhateverTheirInputCarries(): void
    {
        // The real handlers are driven here with and without an acceptance in their input: whatever the input carries,
        // no acceptance reaches the stage from an operation (decisions/0627; C11 review of 0627, round 2, M-A).
        $this->fakeComposer();
        @mkdir($this->root . '/vendor/composer', 0o777, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', '{"packages": []}');
        $index = ['derived_at' => '2026-11-11T00:00:00+00:00', 'undeclared' => [], 'capabilities' => ['lab/good' => [
            'id' => 'good', 'title' => 'Good', 'unlocks' => ['good'], 'provides' => ['lab.good'], 'briefing' => '', 'version' => 'v1.0.0',
        ]]];
        $operations = new CapabilityOperations();
        $enable = new \ReflectionMethod($operations, 'enable');
        $repair = new \ReflectionMethod($operations, 'repair');

        foreach ([[], ['accept_unconfined' => self::ACCEPTED]] as $extra) {
            $enabled = $enable->invoke($operations, ['capability' => 'lab/good'] + $extra, null, null, $this->root, $index, $this->probeWithoutBwrap());
            self::assertFalse($enabled['ok'], json_encode($extra) ?: '');
            self::assertStringContainsString('saying UNCONFINED is not authorizing', (string) $enabled['error']);

            $repaired = $repair->invoke($operations, ['package' => 'lab/good'] + $extra, ['lab/good'], null, $this->root, $this->probeWithoutBwrap());
            self::assertFalse($repaired['ok'], json_encode($extra) ?: '');
            self::assertStringContainsString('saying UNCONFINED is not authorizing', (string) json_encode($repaired, \JSON_UNESCAPED_UNICODE));
        }
        self::assertSame('', $this->asked(), 'Composer never ran, whatever the input carried');
        self::assertFileDoesNotExist($this->root . '/' . HouseBootWitness::JOURNAL);
    }

    public function testOnTheConsoleWithoutConfinementRepairRefusesUnlessAPersonNamesTheRisk(): void
    {
        if (!Capabilities::installed('devtools')) {
            self::markTestSkipped('`coa repair` lives in the dev tools');
        }
        $this->fakeComposer();
        // The doctor in THIS process recommends milpa/data; a fresh boot of the house does not list the plugin that needs it.
        class_exists(NeedsTheDataCapability::class);
        $listed = var_export(require $this->root . '/config/plugins.php', true);
        file_put_contents($this->root . '/config/plugins.php', '<?php $listed = ' . $listed . '; return class_exists('
            . var_export(NeedsTheDataCapability::class, true) . ', false) ? [...$listed, ' . var_export(NeedsTheDataCapability::class, true) . '] : $listed;');
        self::assertContains('milpa/data', \Milpa\DevTools\Doctor\Repair::recommendedPackages($this->root), 'the instrument: the repair is a recommended one');

        [$code, $said] = $this->coa('repair', 'milpa/data');
        self::assertSame(1, $code);
        self::assertStringContainsString('saying UNCONFINED is not authorizing', $said);
        self::assertSame('', $this->asked(), 'Composer did not run');

        [, $said] = $this->coa('repair', 'milpa/data', '--accept-unconfined=my laptop, I accept the risk');
        self::assertStringNotContainsString('saying UNCONFINED is not authorizing', $said);
        self::assertStringContainsString('require', $this->asked(), 'past the refusal: Composer ran on the stage');
        $entry = json_decode((string) file_get_contents($this->root . '/' . HouseBootWitness::JOURNAL), true);
        self::assertSame('my laptop, I accept the risk', $entry['unconfined_accepted'] ?? null, 'recorded before it ran');
    }

    public function testOnTheConsoleWithoutConfinementUpdateRefusesBeforeComposerAndOnlyWordsAccept(): void
    {
        if (!Capabilities::installed('devtools')) {
            self::markTestSkipped('`coa update` lives in the dev tools');
        }
        $lock = (string) file_get_contents($this->root . '/composer.lock');

        [$code, $said] = $this->coa('update');
        self::assertSame(1, $code);
        self::assertStringContainsString('saying UNCONFINED is not authorizing', $said);
        self::assertStringNotContainsString('does not name a risk', $said);

        [$code, $said] = $this->coa('update', '--accept-unconfined=false');
        self::assertSame(1, $code);
        self::assertStringContainsString('does not name a risk', $said, '`=false` on a console is not a yes');

        self::assertSame($lock, (string) file_get_contents($this->root . '/composer.lock'), 'nothing was written');
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates', 'no stage was even made');
    }

    public function testOnTheConsoleTheFlagIsSaidOnceAndWithItsWords(): void
    {
        foreach (['update', 'repair'] as $verb) {
            if (!Capabilities::installed('devtools')) {
                self::markTestSkipped('`coa update` and `coa repair` live in the dev tools');
            }
            [$code, $said] = $this->coa($verb, '--accept-unconfined', 'lab/good');
            self::assertSame(1, $code, $verb);
            self::assertStringContainsString('takes its words after "="', $said, $verb . ': its words would have become a package name');

            [$code, $said] = $this->coa($verb, 'lab/good', '--accept-unconfined=my laptop, I accept', '--accept-unconfined=another machine');
            self::assertSame(1, $code, $verb);
            self::assertStringContainsString('more than once', $said, $verb);
        }
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates');
    }

    public function testEnablingWhereNothingConfinesSaysWhyAndNotThatThePackageBroke(): void
    {
        // The install door put its own hint over the witness's: «fix the package», where nothing booted (C11, L-1).
        @mkdir($this->root . '/vendor/composer', 0o777, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', '{"packages": []}');
        $index = ['derived_at' => '2026-11-11T00:00:00+00:00', 'undeclared' => [], 'capabilities' => ['lab/good' => [
            'id' => 'good', 'title' => 'Good', 'unlocks' => ['good'], 'provides' => ['lab.good'], 'briefing' => '', 'version' => 'v1.0.0',
        ]]];

        $composer = $this->composer();
        $r = Capabilities::install(
            'lab/good',
            $this->root . '/vendor',
            static fn (string $command, string $cwd = ''): array => $composer($command, $cwd),
            index: $index,
            root: $this->root,
            witness: new HouseBootWitness($this->root, $this->probeWithoutBwrap()),
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('saying UNCONFINED is not authorizing', (string) $r['error']);
        // It names who accepts that boot and where: a person, on the console, for this capability (decisions/0630).
        self::assertStringContainsString('capabilities:enable lab/good --sign --accept-unconfined="<the risk you accept>"', (string) $r['hint']);
        self::assertStringContainsString('No operation carries that acceptance in its input', (string) $r['hint']);
        self::assertStringNotContainsString('Fix the package', (string) $r['hint']);
        self::assertSame([], $this->ran);
    }

    public function testEnablingReceivesAPersonsAcceptanceFromTheConsoleAndFromNowhereElse(): void
    {
        // The real handler, on a machine that cannot confine: it boots unconfined only while a console is holding a
        // person's words for that call, and never for what its input carries (decisions/0630).
        $this->fakeComposer();
        [$operations, $enable, $index] = $this->enableHandler();
        $call = fn (array $extra = []): array => $enable->invoke($operations, ['capability' => 'lab/good'] + $extra, null, null, $this->root, $index, $this->probeWithoutBwrap());

        $held = AcceptedOnTheConsole::during(self::ACCEPTED, $call);
        self::assertStringNotContainsString('saying UNCONFINED is not authorizing', (string) json_encode($held, \JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('require lab/good', $this->asked(), 'past the refusal: Composer ran on the stage');
        $entries = file($this->root . '/' . HouseBootWitness::JOURNAL, \FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(1, $entries);
        self::assertSame(self::ACCEPTED, json_decode($entries[0], true)['unconfined_accepted'] ?? null, 'recorded before it ran');

        // The words were for that one call: the next one, in the same process, has none.
        self::assertNull(AcceptedOnTheConsole::words());
        $after = $call();
        self::assertFalse($after['ok']);
        self::assertStringContainsString('saying UNCONFINED is not authorizing', (string) $after['error']);
        self::assertCount(1, file($this->root . '/' . HouseBootWitness::JOURNAL) ?: [], 'and nothing more was recorded');
    }

    public function testWordsThatNameNoRiskAcceptNothingForACapabilityEither(): void
    {
        $this->fakeComposer();
        [$operations, $enable, $index] = $this->enableHandler();

        foreach (['false', 'UNCONFINED UNCONFINED', 'yes', ''] as $said) {
            $refused = AcceptedOnTheConsole::during($said, fn (): array => $enable->invoke($operations, ['capability' => 'lab/good'], null, null, $this->root, $index, $this->probeWithoutBwrap()));
            self::assertFalse($refused['ok'], $said);
            self::assertStringContainsString('saying UNCONFINED is not authorizing', (string) $refused['error'], $said);
        }
        self::assertSame('', $this->asked(), 'Composer never ran');
        self::assertFileDoesNotExist($this->root . '/' . HouseBootWitness::JOURNAL);
    }

    public function testTheConsoleForgetsTheWordsHoweverTheCallEnds(): void
    {
        try {
            AcceptedOnTheConsole::during(self::ACCEPTED, static function (): never {
                throw new \RuntimeException('the call broke');
            });
            self::fail('the call was expected to throw');
        } catch (\RuntimeException) {
        }
        self::assertNull(AcceptedOnTheConsole::words());
        self::assertSame(self::ACCEPTED, AcceptedOnTheConsole::during(self::ACCEPTED, AcceptedOnTheConsole::words(...)));
        self::assertNull(AcceptedOnTheConsole::words());
    }

    public function testOnlyTheConsoleSaysTheWordsAndOnlyEnablingReadsThem(): void
    {
        // Who says the words and who reads them, by the source: one console call says them, one handler reads them.
        $said = $read = [];
        $src = \dirname(__DIR__, 2) . '/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $text = (string) file_get_contents((string) $file);
            $name = substr((string) $file, \strlen($src) + 1);
            if ($name === 'Support/AcceptedOnTheConsole.php') {
                continue;
            }
            for ($i = substr_count($text, 'AcceptedOnTheConsole::during('); $i > 0; --$i) {
                $said[] = $name;
            }
            for ($i = substr_count($text, 'AcceptedOnTheConsole::words('); $i > 0; --$i) {
                $read[] = $name;
            }
        }
        self::assertSame(['Console/Application.php'], $said);
        self::assertSame(['Operations/CapabilityOperations.php'], $read);
        $console = (string) file_get_contents($src . '/Console/Application.php');
        self::assertSame(1, substr_count($console, "\$operacion->name !== 'capabilities:enable' =>"), 'the console takes the flag for one operation, by its name');
    }

    public function testOnTheConsoleTheFlagOfEnablingIsSaidOnceAndWithItsWords(): void
    {
        $this->aHouseThatSaysWhatItsConsoleHeld();

        foreach ([
            ['--accept-unconfined', '--accept-unconfined takes its words after "="'],
            ['--accept-unconfined=my laptop, I accept', '--accept-unconfined=another machine', 'given more than once'],
            ['--accept_unconfined=my laptop, I accept', 'the flag is spelled --accept-unconfined='],
            ['--ACCEPT-UNCONFINED=my laptop, I accept', 'the flag is spelled --accept-unconfined='],
            ['--accept-unconfined=my laptop, I accept', '--Accept_Unconfined', 'the flag is spelled --accept-unconfined='],
        ] as $given) {
            $why = array_pop($given);
            [$code, $said] = $this->console(['capabilities:enable', '--capability=lab/good', '--sign', ...$given]);
            self::assertSame(1, $code, $said);
            self::assertStringContainsString($why, $said);
            self::assertNull($this->held(), 'nothing ran: ' . implode(' ', $given));
        }
    }

    public function testTheConsoleTakesTheWordsOutOfTheCallAndHoldsThemForIt(): void
    {
        // The whole console, in a fresh process: the handler of the operation named `capabilities:enable` says what
        // reached it. The words are held for it and are not in its input (decisions/0630). The operation declares no
        // schema, so its input shows every flag that was left in the call: `sign`, and nothing of the acceptance.
        $this->aHouseThatSaysWhatItsConsoleHeld();

        [$code, $said] = $this->console(['capabilities:enable', '--capability=lab/good', '--sign', '--accept-unconfined=my laptop, I accept the risk']);
        self::assertSame(0, $code, $said);
        self::assertSame(['input' => ['capability' => 'lab/good', 'sign' => '1'], 'words' => 'my laptop, I accept the risk'], $this->held());

        [$code, $said] = $this->console(['capabilities:enable', '--capability=lab/good', '--sign']);
        self::assertSame(0, $code, $said);
        self::assertSame(['input' => ['capability' => 'lab/good', 'sign' => '1'], 'words' => null], $this->held());
    }

    public function testOnAnyOtherOperationTheFlagIsRefusedAndNothingIsHeld(): void
    {
        $this->aHouseThatSaysWhatItsConsoleHeld();

        foreach (['--accept-unconfined=my laptop, I accept the risk', '--accept-unconfined', '--accept_unconfined=my laptop, I accept the risk'] as $flag) {
            [$code, $said] = $this->console(['words:probe', '--sign', $flag]);
            self::assertSame(1, $code, $said);
            self::assertStringContainsString('«words:probe» does not take it', $said);
            self::assertNull($this->held(), 'the operation did not run: ' . $flag);
        }

        [$code, $said] = $this->console(['words:probe', '--sign']);
        self::assertSame(0, $code, $said);
        self::assertSame(['input' => ['sign' => '1'], 'words' => null], $this->held(), 'the control: without the flag it runs, and holds nothing');
    }

    public function testWhereTheHostSignsTheConsoleDoesNotTakeTheAcceptance(): void
    {
        // Whoever approves at the host is shown the call and not the acceptance, so it is not taken there. No socket is
        // opened: the refusal comes before anything is signed, and the path names nothing.
        $this->aHouseThatSaysWhatItsConsoleHeld();
        [$code, $said] = $this->console(
            ['capabilities:enable', '--capability=lab/good', '--sign', '--accept-unconfined=my laptop, I accept the risk'],
            [RemoteOperationSigner::SIGN_SOCKET_ENV => $this->root . '/var/no-such-socket'],
            signer: false,
        );
        self::assertSame(1, $code, $said);
        self::assertStringContainsString('this house signs through its host', $said);
        self::assertNull($this->held());
        // The control is the test above: the same call, signed by a key of this house, runs and holds the words.
    }

    /** @return iterable<string, array{string}> */
    public static function namesTheConsoleKeepsForAPerson(): iterable
    {
        yield 'as an input key' => ['accept_unconfined'];
        yield 'as the flag is typed' => ['accept-unconfined'];
        yield 'in another case' => ['Accept_UNCONFINED'];
        yield 'with its dashes' => ['--accept-unconfined'];
        yield 'with blanks around it' => [' accept_unconfined '];
    }

    #[DataProvider('namesTheConsoleKeepsForAPerson')]
    public function testALineTheHouseOffersNeverCarriesWhatOnlyAPersonSays(string $name): void
    {
        // The line an unsigned door offers is built from the caller's input and run by a person as given.
        foreach ((new CapabilityOperations())->operations() as $operation) {
            self::assertNull(UnsignedTerminal::signedLineOrNull($operation, ['capability' => 'lab/good', 'package' => 'lab/good', $name => self::ACCEPTED]), $operation->name);
            self::assertStringNotContainsStringIgnoringCase('unconfined', (string) UnsignedTerminal::signedLineOrNull($operation, ['capability' => 'lab/good']), 'the control: without the name a line is offered');
            self::assertNotNull(UnsignedTerminal::signedLineOrNull($operation, ['capability' => 'lab/good']));
        }
        $refusal = implode("\n", UnsignedTerminal::refusalOver('php bin/coa mcp', (new CapabilityOperations())->operations()[2] ?? (new CapabilityOperations())->operations()[0], ['capability' => 'lab/good', $name => self::ACCEPTED], null) ?? []);
        self::assertStringNotContainsStringIgnoringCase('unconfined', $refusal);
        self::assertStringContainsString('It is not offered signed: the call names what only a person says', $refusal);
    }

    public function testTheJournalSaysUnderWhichAuthorizationTheBootWasAccepted(): void
    {
        $composer = $this->composer();
        (new HouseBootWitness($this->root, $this->probeWithoutBwrap(), unconfinedAccepted: self::ACCEPTED, acceptedUnder: ['authorized_by' => 'AAAA1111', 'authorization' => 'auth-7', 'unconfined_accepted' => 'not these', 'empty' => '']))
            ->composeIfItBoots('composer require lab/good', static fn (string $command, string $cwd): array => $composer($command, $cwd));

        $entry = json_decode((string) file_get_contents($this->root . '/' . HouseBootWitness::JOURNAL), true);
        self::assertSame('AAAA1111', $entry['authorized_by'] ?? null);
        self::assertSame('auth-7', $entry['authorization'] ?? null);
        self::assertSame(self::ACCEPTED, $entry['unconfined_accepted'] ?? null, 'what is kept beside the words never replaces them');
        self::assertArrayNotHasKey('empty', $entry);
    }

    public function testAnAcceptanceThatCannotBeRecordedIsNotBlamedOnThePackage(): void
    {
        $this->fakeComposer();
        [$operations, $enable, $index] = $this->enableHandler();
        mkdir($this->root . '/' . HouseBootWitness::JOURNAL, 0o777, true); // a directory where the journal goes: it cannot be written

        $refused = AcceptedOnTheConsole::during(self::ACCEPTED, fn (): array => $enable->invoke($operations, ['capability' => 'lab/good'], null, null, $this->root, $index, $this->probeWithoutBwrap()));

        self::assertFalse($refused['ok']);
        self::assertStringContainsString('could not be recorded', (string) $refused['error']);
        self::assertStringNotContainsString('Fix the package', (string) $refused['hint']);
        self::assertSame('', $this->asked(), 'Composer did not run');
        rmdir($this->root . '/' . HouseBootWitness::JOURNAL);
    }

    /**
     * `coa …` in a fresh process of this test's house — its exit code and what it printed.
     *
     * @param list<string>          $argv
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string}
     */
    private function console(array $argv, array $env = [], bool $signer = true): array
    {
        @unlink($this->root . '/var/held.json');
        $script = $this->root . '/var/coa.php';
        file_put_contents($script, '<?php
require ' . var_export($this->root . '/vendor/autoload.php', true) . ';
$key = ' . ($signer ? 'new Milpa\AppRuntime\Tests\Fixtures\LabSigner()' : 'null') . ';
exit((new Milpa\AppRuntime\Console\Application(' . var_export($this->root, true) . ', $key, $key))->run(["coa", ...json_decode($argv[1], true)]));
');
        $given = '';
        foreach ($env + [WhatTheConsoleHeldOperations::OUT_ENV => $this->root . '/var/held.json'] as $name => $value) {
            $given .= $name . '=' . escapeshellarg($value) . ' ';
        }
        exec('MILPA_TOKEN= ' . $given
            . escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script) . ' ' . escapeshellarg((string) json_encode($argv)) . ' 2>&1', $out, $exit);

        return [$exit, implode("\n", $out)];
    }

    /** @return array{input: array<string, mixed>, words: ?string}|null what the handler said reached it; null when it did not run */
    private function held(): ?array
    {
        $said = @file_get_contents($this->root . '/var/held.json');

        return $said === false ? null : json_decode($said, true);
    }

    /** This test's house, made again as one whose kernel boots in a fresh process and whose operations say what reached them. */
    private function aHouseThatSaysWhatItsConsoleHeld(): void
    {
        TinyHouse::remove($this->root);
        $this->root = TinyHouse::create('HelloPlugin');
        file_put_contents($this->root . '/config/operations.php', '<?php return [\\' . WhatTheConsoleHeldOperations::class . "::class];\n");
    }

    /** @return array{0: CapabilityOperations, 1: \ReflectionMethod, 2: array<string, mixed>} the real handler of `capabilities:enable`, and an index that offers lab/good */
    private function enableHandler(): array
    {
        @mkdir($this->root . '/vendor/composer', 0o777, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', '{"packages": []}');
        $index = ['derived_at' => '2026-11-11T00:00:00+00:00', 'undeclared' => [], 'capabilities' => ['lab/good' => [
            'id' => 'good', 'title' => 'Good', 'unlocks' => ['good'], 'provides' => ['lab.good'], 'briefing' => '', 'version' => 'v1.0.0',
        ]]];
        $operations = new CapabilityOperations();

        return [$operations, new \ReflectionMethod($operations, 'enable'), $index];
    }

    public function testAPluginComposerLeftUnloadedIsSaid(): void
    {
        $composer = $this->composer();
        $composed = (new HouseBootWitness($this->root, $this->probeWithoutBwrap(), unconfinedAccepted: self::ACCEPTED))
            ->composeIfItBoots('composer require lab/good', static function (string $command, string $cwd) use ($composer): array {
                [$code, $out] = $composer($command, $cwd);

                return [$code, [...$out, 'The "lab/plug" plugin was not loaded as plugins are disabled.']];
            });

        self::assertNull($composed['refused'], (string) $composed['refused']);
        self::assertSame(['lab/plug'], $composed['said']['plugins_not_loaded']);
    }

    /** A stand-in `composer` first on PATH that records what it was asked and fails: enough to see whether it ran. */
    private function fakeComposer(): void
    {
        $bin = $this->root . '/var/fakebin';
        mkdir($bin, 0o777, true);
        $this->fakeLog = $this->root . '/var/fake-composer.log';
        file_put_contents($bin . '/composer', "#!/bin/sh\necho \"\$*\" >> " . escapeshellarg($this->fakeLog) . "\necho 'the stand-in composer ran'\nexit 1\n");
        chmod($bin . '/composer', 0o755);
        $this->path = getenv('PATH');
        putenv('PATH=' . $bin . ':' . (string) $this->path);
    }

    /** What the stand-in composer was asked, one line per run ('' when it never ran). */
    private function asked(): string
    {
        return is_file($this->fakeLog) ? (string) file_get_contents($this->fakeLog) : '';
    }

    private function probeWithoutBwrap(): BootProbe
    {
        return new BootProbe(runner: new TrialRunner('/nonexistent/bwrap'));
    }

    /**
     * `coa <verb> …` on this house, on a machine that cannot confine — what it printed, and its exit code.
     *
     * @return array{0: int, 1: string}
     */
    private function coa(string ...$argv): array
    {
        ob_start();
        try {
            $code = (new Application($this->root, bootProbe: $this->probeWithoutBwrap()))->run(['coa', ...$argv]);
        } finally {
            $said = (string) ob_get_clean();
        }

        return [$code, $said];
    }

    /**
     * A stand-in composer: `require lab/<name>` adds a package whose autoloaded file runs `$code` on every boot.
     *
     * @return callable(string, string): array{0: int, 1: list<string>}
     */
    private function composer(string $code = ''): callable
    {
        return function (string $command, string $cwd) use ($code): array {
            $this->ran[] = [$command, $cwd];
            if (preg_match('~require lab/(\w+)~', $command, $m) !== 1) {
                return [0, ['Nothing to install, update or remove']];
            }
            $package = $cwd . '/vendor/lab/' . $m[1];
            mkdir($package, 0o777, true);
            file_put_contents($package . '/files.php', "<?php\n" . $code . "\n");
            file_put_contents($cwd . '/vendor/autoload.php', str_replace(
                'return $loader;',
                "require_once __DIR__ . '/lab/{$m[1]}/files.php';\nreturn \$loader;",
                (string) file_get_contents($cwd . '/vendor/autoload.php'),
            ));
            file_put_contents($cwd . '/composer.json', "{\n    \"require\": {\"lab/{$m[1]}\": \"*\"}\n}\n");
            file_put_contents($cwd . '/composer.lock', "{\n    \"content-hash\": \"1\"\n}\n");

            return [0, ["  - Installing lab/{$m[1]} (1.0.0)"]];
        };
    }
}
