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

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\Capabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 EVERY COMMAND THIS PACKAGE HANDS A HUMAN TO TYPE HAS TO RUN AS TYPED.
 *
 * `coa` is not on PATH after `composer create-project`: Composer does not link the ROOT package's
 * `bin`, so `vendor/bin/` holds php-cs-fixer, phpstan and phpunit and no `coa`. Measured in a clean
 * shell at an app root — `coa capabilities:refresh` answers «bash: coa: command not found», exit 127,
 * while `php bin/coa list` exits 0.
 *
 * Every command string in this package began with that bare `coa`. Fifteen of them appear on the
 * first screen of a newborn app, which is the screen the framework's welcome page now points at under
 * a heading that reads «Start here» — so the door opened onto a wall. And the house already knew the
 * right form: two HTML renderers here have always printed `php bin/coa`. Two vocabularies for one
 * act, and the one that shipped to the CLI was the one that does not run
 * (greenhouse decisions/0305).
 *
 * This is a GUARD, not a unit test. A defect that was in six places at once comes back in the
 * seventh, so the check scans the package rather than an example.
 */
#[CoversClass(Capabilities::class)]
final class EveryCommandItPrintsCanBeTypedTest extends TestCase
{
    /**
     * The one authority, and the door built from it.
     */
    public function testTheInvocationIsDeclaredOnceAndTheGovernedDoorUsesIt(): void
    {
        self::assertSame('php bin/coa ', Capabilities::CLI);
        self::assertSame('php bin/coa capabilities:enable ', Capabilities::ENABLE_COMMAND);
    }

    /**
     * 🚨 NO STRING IN `src/` OFFERS A BARE `coa` AS SOMETHING TO TYPE.
     *
     * The exceptions are named individually rather than by pattern, because each one is a different
     * KIND of thing and a pattern would let a real command in behind it:
     *
     * - `coa doctor · N plugin(s) declared` and `coa — the runtime of this app` are banners. A banner
     *   is the program's name being printed, not an instruction.
     * - `coa · agent` is a TUI panel title.
     * - `["coa chat", "agent:sessions"]` is a docblock example of an `unlocks` list, whose entries are
     *   operation names.
     */
    public function testNoStringInTheSourceOffersABareCoaToType(): void
    {
        // Named one by one rather than by pattern, because each is a different KIND of thing and a
        // pattern would let a real command in behind it: two banners, a TUI panel title, a docblock
        // example of an `unlocks` list, and the one place that names the operation `coa doctor` writes
        // into its own `action` field.
        $allowed = [
            "'coa doctor · '",
            "'coa — the runtime of this app.",
            "'coa · agent'",
            '"coa chat"',
            '`coa doctor` names in its `action`',
        ];

        $offenders = [];
        foreach (self::phpFiles(\dirname(__DIR__, 2) . '/src') as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                // COMMENTS AND DOCBLOCKS ARE NOT THIS GUARD'S SUBJECT. Prose discussing an operation
                // by name — «whoever reads `coa list` counts it as available» — is the code-language
                // ratchet's business, and a guard that flagged it would fail on 36 lines this slice
                // never touched while saying nothing new about what a human is told to type.
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                // 🚨 BACKTICKS TOO, AND THAT WAS THE HOLE. The first version of this guard matched only
                // `'coa ` at the START of a literal, so it passed while fourteen more sites offered a
                // bare `coa` INSIDE a sentence — «run `coa config`», «córrela con `coa agent …`», the
                // remedy at the end of an error, a usage line, and a comment written into the reader's
                // own `config/app.php`. A guard that only sees one shape of the defect certifies the
                // others (greenhouse decisions/0306).
                if (!preg_match('/[\'"`]coa /', $line)) {
                    continue;
                }
                foreach ($allowed as $exempt) {
                    if (str_contains($line, $exempt)) {
                        continue 2;
                    }
                }
                $offenders[] = basename($file) . ':' . ($n + 1) . ' ' . trim($line);
            }
        }

        self::assertSame([], $offenders, "a command a person is told to type must start with Capabilities::CLI:\n" . implode("\n", $offenders));
    }

    /**
     * And the other direction: the runnable form is what actually reaches a caller.
     *
     * Asserting the constants alone would pass while a caller concatenated its own `'coa '` — which is
     * exactly how this got to six places.
     */
    public function testTheCommandsTheCatalogueOffersAreRunnableAsPrinted(): void
    {
        // `state()` with no derived index IS the offline floor — the answer a newborn app gives, which
        // is the run this whole finding is about.
        $state = Capabilities::state(null, null);

        self::assertNotSame([], $state['available'], 'the offline floor is what a newborn app answers from');

        foreach ($state['available'] as $row) {
            $command = (string) ($row['command'] ?? '');
            self::assertStringStartsWith('php bin/coa ', $command, 'the floor offers a command nobody can run');
            self::assertStringNotContainsString(' coa ', ' ' . $command, 'a bare coa survived somewhere in the string');
        }
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $root): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
