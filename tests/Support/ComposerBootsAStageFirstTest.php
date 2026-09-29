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

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\HouseBootWitness;
use Milpa\AppRuntime\Support\StagedComposerRunner;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use PHPUnit\Framework\TestCase;

/**
 * Composer boots a stage before it lands (greenhouse decisions/0527) — real boots, a composer that writes only where it runs.
 *
 * The composer here is a stand-in with one property of the real one that matters: it writes the tree of the
 * directory it is run in — `composer.json`, `composer.lock`, a package under `vendor/`, and the autoloader
 * that loads it (a package's autoloaded `files`, which run on every boot). A package whose file throws is a
 * house that cannot boot; the live tree must not learn of it.
 *
 * @guards a composer change whose stage does not boot is refused with `unwritten` and the live `vendor/`,
 *         `composer.json` and `composer.lock` untouched (same inode, same bytes); a good one lands by swapping
 *         the stage's `vendor/` in and says `house_boots`; what only the live house shows is put back
 *         (`rolled_back`, the previous `vendor/` renamed back); recovery on a broken house lands with
 *         `still_broken`; a recovery that would break a booting house is refused; composer runs in the stage,
 *         never in the house; relative path repositories and links leave the house with their own strings;
 *         capabilities:enable, repair and update go through it
 *
 * @refuses an ordinary composer change whose stage does not boot; a recovery that would break a house that boots
 *
 * @subject-in milpa/app-runtime
 */
final class ComposerBootsAStageFirstTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $ranIn = [];

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
        file_put_contents($this->root . '/composer.json', "{\n    \"require\": {}\n}\n");
        file_put_contents($this->root . '/composer.lock', "{\n    \"content-hash\": \"0\"\n}\n");
    }

    protected function tearDown(): void
    {
        putenv('MILPA_WITNESS_NO_EXCHANGE');
        TinyHouse::remove($this->root);
    }

    /**
     * A stand-in composer: `require lab/<name>` adds a package whose autoloaded file runs `$code` on every boot.
     *
     * @return callable(string, string): array{0: int, 1: list<string>}
     */
    private function composer(string $code = '', int $exit = 0): callable
    {
        return function (string $command, string $cwd) use ($code, $exit): array {
            $this->ranIn[] = $cwd;
            if ($exit !== 0) {
                return [$exit, ['Your requirements could not be resolved to an installable set of packages.']];
            }
            if (preg_match('~require lab/(\w+)~', $command, $m) !== 1) {
                return [0, ['Nothing to install, update or remove']];
            }
            $package = $cwd . '/vendor/lab/' . $m[1];
            mkdir($package, 0o777, true);
            file_put_contents($package . '/files.php', "<?php\n" . $code . "\n");
            // Composer REWRITES the autoloader in place — the file a linked vendor/ would have shared with the house.
            file_put_contents($cwd . '/vendor/autoload.php', self::before(
                (string) file_get_contents($cwd . '/vendor/autoload.php'),
                "require_once __DIR__ . '/lab/{$m[1]}/files.php';",
            ));
            file_put_contents($cwd . '/composer.json', "{\n    \"require\": {\"lab/{$m[1]}\": \"*\"}\n}\n");
            file_put_contents($cwd . '/composer.lock', "{\n    \"content-hash\": \"1\"\n}\n");

            return [0, ["  - Installing lab/{$m[1]} (1.0.0)"]];
        };
    }

    /** A line run by the autoloader, before it returns its loader — where Composer's `files` run. */
    private static function before(string $autoload, string $line): string
    {
        return str_replace('return $loader;', $line . "\nreturn \$loader;", $autoload);
    }

    /** @return array<string, array{0: int|false, 1: string}> every file under vendor/ and the two files: inode and bytes */
    private function tree(): array
    {
        $tree = [];
        foreach (['composer.json', 'composer.lock'] as $file) {
            $tree[$file] = [fileinode($this->root . '/' . $file), (string) file_get_contents($this->root . '/' . $file)];
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/vendor', \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            $tree[substr($file->getPathname(), \strlen($this->root) + 1)] = [fileinode($file->getPathname()), (string) file_get_contents($file->getPathname())];
        }
        ksort($tree);

        return $tree;
    }

    private function breakTheHouse(): void
    {
        file_put_contents($this->root . '/vendor/autoload.php', self::before(
            (string) file_get_contents($this->root . '/vendor/autoload.php'),
            "throw new \\RuntimeException('the house was already broken');",
        ));
    }

    public function testAComposerChangeTheStageCannotBootWithNeverTouchesTheLiveTree(): void
    {
        $before = $this->tree();

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots(
            'composer require lab/poison',
            $this->composer("throw new \\RuntimeException('lab/poison cannot start');"),
        );

        self::assertStringContainsString('lab/poison cannot start', (string) $composed['refused']);
        self::assertStringContainsString('boots as it is', (string) $composed['refused']);
        self::assertSame(['composer.json', 'composer.lock', 'vendor/'], $composed['said']['unwritten']);
        self::assertTrue($composed['said']['house_boots']);
        self::assertStringNotContainsString($this->root . '/', (string) $composed['said']['reason'], 'a reason names house-relative paths');
        self::assertSame($before, $this->tree(), 'the live vendor/, composer.json and composer.lock: same inodes, same bytes');
        self::assertCount(1, $this->ranIn);
        self::assertStringStartsWith($this->root . '/var/boot-candidates/', $this->ranIn[0], 'composer ran in the stage, not in the house');
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates', 'the stage is gone');
    }

    public function testAFileComposerRewritesInPlaceInsideAPackageNeverReachesTheLiveTree(): void
    {
        // Why the stage's vendor/ is a copy and not links: Composer rewrites files in place (installed.json, bin
        // proxies, a plugin's own output). Through a linked package directory — or a hard link — that write is the
        // house's. Here the stage's composer rewrites a package file in place and the change is refused.
        mkdir($this->root . '/vendor/lab/existing', 0o777, true);
        file_put_contents($this->root . '/vendor/lab/existing/code.php', "<?php // live bytes\n");
        $before = $this->tree();

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/poison', function (string $command, string $cwd): array {
            $handle = fopen($cwd . '/vendor/lab/existing/code.php', 'r+');
            self::assertIsResource($handle);
            fwrite($handle, "<?php // STAGE bytes\n");
            fclose($handle);

            return $this->composer("throw new \\RuntimeException('lab/poison cannot start');")($command, $cwd);
        });

        self::assertNotNull($composed['refused']);
        self::assertSame($before, $this->tree(), 'the live package file keeps its bytes and inode');
    }

    public function testAComposerChangeTheStageBootsWithLandsBySwapping(): void
    {
        $autoload = fileinode($this->root . '/vendor/autoload.php');

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/good', $this->composer('$labGood = true;'));

        self::assertNull($composed['refused']);
        self::assertTrue($composed['said']['house_boots']);
        self::assertContains($composed['said']['landed_by'], ['exchange', 'swap'], 'one step where the system has one, two renames where not');
        self::assertSame(0, $composed['code']);
        self::assertFileExists($this->root . '/vendor/lab/good/files.php');
        self::assertStringContainsString('lab/good', (string) file_get_contents($this->root . '/composer.json'));
        self::assertStringContainsString('"1"', (string) file_get_contents($this->root . '/composer.lock'));
        self::assertNotSame($autoload, fileinode($this->root . '/vendor/autoload.php'), 'the vendor/ that serves is the one that booted');
        self::assertCount(1, $this->ranIn, 'composer ran once: the stage landed, it was not run again');
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates', 'the stage and the previous vendor/ are gone');
    }

    public function testWithoutAnExchangeTheSwapIsTwoRenamesAndSaysSo(): void
    {
        putenv('MILPA_WITNESS_NO_EXCHANGE=1');

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertSame(['house_boots' => true, 'landed_by' => 'swap'], $composed['said']);
        self::assertFileExists($this->root . '/vendor/lab/good/files.php');
    }

    public function testWhereTheSystemExchangesTheSwapIsOneStep(): void
    {
        $probe = sys_get_temp_dir() . '/milpa-exchange-' . bin2hex(random_bytes(4));
        mkdir($probe . '/a', 0o777, true);
        mkdir($probe . '/b');
        exec('mv --exchange --no-target-directory ' . escapeshellarg($probe . '/a') . ' ' . escapeshellarg($probe . '/b') . ' 2>/dev/null', $out, $code);
        rmdir($probe . '/a');
        rmdir($probe . '/b');
        rmdir($probe);
        if ($code !== 0) {
            self::markTestSkipped('this system has no `mv --exchange` (coreutils 9.5+): the two-rename arm is tested above');
        }

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertSame(['house_boots' => true, 'landed_by' => 'exchange'], $composed['said']);
        self::assertFileExists($this->root . '/vendor/lab/good/files.php');
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates', 'the previous vendor/ went with the stage');
    }

    public function testWhatOnlyTheLiveHouseShowsIsPutBackWithTwoRenamesToo(): void
    {
        putenv('MILPA_WITNESS_NO_EXCHANGE=1');
        file_put_contents($this->root . '/var/poison', '1');
        $before = $this->tree();

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/stateful', $this->composer(
            "if (is_file(dirname(__DIR__, 3) . '/var/poison')) { throw new \\RuntimeException('var/poison says no'); }",
        ));

        self::assertArrayHasKey('rolled_back', $composed['said']);
        self::assertSame($before, $this->tree());
    }

    public function testWhatOnlyTheLiveHouseShowsIsPutBack(): void
    {
        file_put_contents($this->root . '/var/poison', '1');
        $before = $this->tree();

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/stateful', $this->composer(
            "if (is_file(dirname(__DIR__, 3) . '/var/poison')) { throw new \\RuntimeException('var/poison says no'); }",
        ));

        self::assertStringContainsString('var/poison says no', (string) $composed['refused'], 'the stage booted; the live house did not');
        self::assertSame(['composer.json', 'composer.lock', 'vendor/'], $composed['said']['rolled_back']);
        self::assertTrue($composed['said']['house_boots'], 'and it boots again once put back');
        self::assertSame($before, $this->tree(), 'the previous vendor/ is back: same inodes, same bytes');
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates');
    }

    public function testWithoutTheCopyTheSameChangeLandsAndIsOnlyCaughtAfter(): void
    {
        // The positive control of the stage: 0506's order (ask only after) lets the poisoned tree onto the live
        // house before the net puts it back — exactly the window the stage closes.
        $composed = (new HouseBootWitness($this->root, probeBefore: false))->composeIfItBoots(
            'composer require lab/poison',
            $this->composer("throw new \\RuntimeException('lab/poison cannot start');"),
        );

        self::assertArrayHasKey('rolled_back', $composed['said']);
        self::assertArrayNotHasKey('unwritten', $composed['said']);
        self::assertFileDoesNotExist($this->root . '/vendor/lab/poison/files.php', 'put back by the net');
    }

    public function testRecoveryOnABrokenHouseLandsAndSaysWhatIsStillWrong(): void
    {
        $this->breakTheHouse();

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/missing', $this->composer(), recovery: true);

        self::assertNull($composed['refused']);
        self::assertFalse($composed['said']['house_boots']);
        self::assertStringContainsString('already broken', (string) $composed['said']['still_broken']);
        self::assertFileExists($this->root . '/vendor/lab/missing/files.php', 'written: it may be one step of several');
    }

    public function testTheSameChangeWithoutRecoveryIsRefused(): void
    {
        $this->breakTheHouse();
        $before = $this->tree();

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/missing', $this->composer());

        self::assertStringContainsString('does not boot as it is either', (string) $composed['refused']);
        self::assertFalse($composed['said']['house_boots']);
        self::assertSame($before, $this->tree());
    }

    public function testARecoveryThatWouldBreakABootingHouseIsRefused(): void
    {
        $before = $this->tree();

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots(
            'composer require lab/poison',
            $this->composer("throw new \\RuntimeException('lab/poison cannot start');"),
            recovery: true,
        );

        self::assertNotNull($composed['refused']);
        self::assertTrue($composed['said']['house_boots']);
        self::assertSame($before, $this->tree());
    }

    public function testAComposerFailureIsComposersAnswerAndTouchedOnlyTheStage(): void
    {
        $before = $this->tree();

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/nowhere', $this->composer('', 2));

        self::assertNull($composed['refused'], 'composer refused, not the witness');
        self::assertSame(2, $composed['code']);
        self::assertStringContainsString('could not be resolved', implode("\n", $composed['output']));
        self::assertSame($before, $this->tree());
    }

    public function testAHouseWithoutVendorRunsComposerWhereItAlwaysDid(): void
    {
        unlink($this->root . '/vendor/autoload.php');

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer install', $this->composer());

        self::assertNull($composed['refused']);
        self::assertSame([], $composed['said']);
        self::assertSame([$this->root], $this->ranIn);
    }

    public function testARelativePathRepositoryLeavesTheHouseAsItWasWritten(): void
    {
        $json = "{\n    \"repositories\": [{\"type\": \"path\", \"url\": \"../pkgs/x\"}],\n    \"require\": {}\n}\n";
        file_put_contents($this->root . '/composer.json', $json);
        $seen = null;

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/x', function (string $command, string $cwd) use (&$seen): array {
            $seen = (string) file_get_contents($cwd . '/composer.json');
            $recorded = str_replace('"require": {}', '"require": {"lab/x": "*"}', $seen);
            file_put_contents($cwd . '/composer.json', $recorded);
            file_put_contents($cwd . '/composer.lock', json_encode([
                'content-hash' => HouseBootWitness::contentHash($recorded),
                'packages' => [['name' => 'lab/x', 'dist' => ['type' => 'path', 'url' => json_decode($recorded, true)['repositories'][0]['url']]]],
            ], \JSON_PRETTY_PRINT));

            return [0, []];
        });

        self::assertNull($composed['refused']);
        self::assertStringContainsString('"url": "' . $this->root . '/../pkgs/x"', (string) $seen, 'in the stage, the url resolves from the house');
        $live = (string) file_get_contents($this->root . '/composer.json');
        self::assertSame(str_replace('"require": {}', '"require": {"lab/x": "*"}', $json), $live, 'the house keeps its own string and formatting');
        $lock = json_decode((string) file_get_contents($this->root . '/composer.lock'), true);
        self::assertSame('../pkgs/x', $lock['packages'][0]['dist']['url']);
        self::assertSame(HouseBootWitness::contentHash($live), $lock['content-hash'], 'and the lock still locks the file the house has');
    }

    public function testTheContentHashIsComposers(): void
    {
        // Composer 2's own hash of this file, taken from the lock `composer update` wrote for it.
        $json = '{"name":"lab/p","repositories":[{"type":"path","url":"../pkgs/x"}],"require":{"lab/x":"*"},"extra":{"a/b":"ñ"}}' . "\n";

        self::assertSame('9be6c646bfdbaf57b34fcc96e9a221b5', HouseBootWitness::contentHash($json));
        // config.platform is part of it; the rest of config is not.
        $platform = '{"name":"lab/p","repositories":[{"type":"path","url":"../pkgs/x"}],"require":{"lab/x":"*"},"config":{"platform":{"php":"8.3.0"},"sort-packages":true}}' . "\n";
        self::assertSame('953e8ee9c9bd17cbbcb8b73f564291dd', HouseBootWitness::contentHash($platform));
    }

    public function testLinksThatLeaveVendorKeepTheirFormAndLinksComposerMadeFromTheStageAreMadeFromTheHouse(): void
    {
        mkdir($this->root . '/packages/kept', 0o777, true);
        mkdir($this->root . '/vendor/lab', 0o777, true);
        symlink('../../packages/kept', $this->root . '/vendor/lab/kept');
        $outside = sys_get_temp_dir() . '/milpa-outside-' . bin2hex(random_bytes(4));
        mkdir($outside);

        try {
            $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/linked', function (string $command, string $cwd) use ($outside): array {
                self::assertSame($this->root . '/packages/kept', readlink($cwd . '/vendor/lab/kept'), 'in the stage, it resolves to the house');
                // What Composer does for a path repository: a link relative to where it runs.
                $from = explode('/', trim($cwd . '/vendor/lab', '/'));
                $to = explode('/', trim($outside, '/'));
                $common = 0;
                while (($from[$common] ?? null) === ($to[$common] ?? null) && $common < \count($from)) {
                    ++$common;
                }
                symlink(str_repeat('../', \count($from) - $common) . implode('/', \array_slice($to, $common)), $cwd . '/vendor/lab/linked');

                return [0, []];
            });

            self::assertNull($composed['refused']);
            self::assertSame('../../packages/kept', readlink($this->root . '/vendor/lab/kept'), 'given back its own string');
            self::assertStringStartsNotWith('/', (string) readlink($this->root . '/vendor/lab/linked'));
            self::assertSame(realpath($outside), realpath($this->root . '/vendor/lab/linked'), 'relative from the house, to the same place');
        } finally {
            rmdir($outside);
        }
    }

    public function testAFileComposerLeftAsItWasIsNotWritten(): void
    {
        $json = fileinode($this->root . '/composer.json');
        $ctime = filectime($this->root . '/composer.json');
        sleep(1);

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer update', function (string $command, string $cwd): array {
            file_put_contents($cwd . '/composer.lock', "{\n    \"content-hash\": \"2\"\n}\n");

            return [0, []];
        });

        self::assertNull($composed['refused']);
        clearstatcache();
        self::assertSame($json, fileinode($this->root . '/composer.json'), 'an update leaves composer.json alone');
        self::assertSame($ctime, filectime($this->root . '/composer.json'), 'not even a second name: its ctime is the same (evidence/1061)');
        self::assertStringContainsString('"2"', (string) file_get_contents($this->root . '/composer.lock'));
    }

    public function testARootSpelledThroughItsOwnVendorStillLands(): void
    {
        // How Capabilities::raizDeLaApp() finds the house: from inside vendor/. Once the swap renames vendor/ away,
        // that spelling resolves to nothing — evidence/1061, run 1, lost the house's vendor/ exactly so. Two renames:
        // an exchange resolves both paths in one call and would hide it.
        putenv('MILPA_WITNESS_NO_EXCHANGE=1');
        mkdir($this->root . '/vendor/composer', 0o777, true);

        $composed = (new HouseBootWitness($this->root . '/vendor/composer/../..'))->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertNull($composed['refused'], (string) $composed['refused']);
        self::assertContains($composed['said']['landed_by'], ['exchange', 'swap']);
        self::assertFileExists($this->root . '/vendor/autoload.php', 'the house keeps a vendor/');
        self::assertFileExists($this->root . '/vendor/lab/good/files.php');
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates');
    }

    public function testALinkComposerRemadeAbsoluteFromTheStageGetsBackTheHousesString(): void
    {
        mkdir($this->root . '/packages/kept', 0o777, true);
        mkdir($this->root . '/vendor/lab', 0o777, true);
        symlink('../../packages/kept/', $this->root . '/vendor/lab/kept');

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer update', function (string $command, string $cwd): array {
            // What Composer did in run 1: the stage's url is absolute, so the link it re-made is absolute too.
            unlink($cwd . '/vendor/lab/kept');
            symlink($this->root . '/packages/kept/', $cwd . '/vendor/lab/kept');

            return [0, []];
        });

        self::assertNull($composed['refused']);
        self::assertSame('../../packages/kept/', readlink($this->root . '/vendor/lab/kept'), 'the house\'s own string, trailing slash and all');
    }

    public function testAStageThatCannotBeBuiltIsARefusalThatWritesNothing(): void
    {
        file_put_contents($this->root . '/var/boot-candidates', 'a file where the stages would live');
        $before = $this->tree();

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertStringContainsString('could not be staged', (string) $composed['refused']);
        self::assertSame(['composer.json', 'composer.lock', 'vendor/'], $composed['said']['unwritten']);
        self::assertSame([], $this->ranIn, 'composer never ran');
        self::assertSame($before, $this->tree());
    }

    public function testWhenTheSwapCannotRenameComposerRunsAgainOnTheHouseOnlyAfterTheStageBooted(): void
    {
        putenv('MILPA_WITNESS_NO_EXCHANGE=1');
        $composer = $this->composer();
        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/good', function (string $command, string $cwd) use ($composer): array {
            if (str_contains($cwd, '/var/boot-candidates/')) {
                // Something already sits where the house's vendor/ would be set aside: the rename cannot happen.
                mkdir($cwd . '/vendor.previous/in-the-way', 0o777, true);
            }

            return $composer($command, $cwd);
        });

        self::assertNull($composed['refused']);
        self::assertSame('rerun', $composed['said']['landed_by']);
        self::assertTrue($composed['said']['house_boots']);
        self::assertCount(2, $this->ranIn);
        self::assertSame($this->root, $this->ranIn[1], 'the second run is on the house');
        self::assertFileExists($this->root . '/vendor/lab/good/files.php');
    }

    public function testARerunThatFailsIsComposersAnswer(): void
    {
        putenv('MILPA_WITNESS_NO_EXCHANGE=1');
        $calls = 0;
        $composer = $this->composer();
        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/good', function (string $command, string $cwd) use ($composer, &$calls): array {
            if (++$calls === 2) {
                return [3, ['the network went away']];
            }
            mkdir($cwd . '/vendor.previous/in-the-way', 0o777, true);

            return $composer($command, $cwd);
        });

        self::assertNull($composed['refused']);
        self::assertSame(3, $composed['code']);
        self::assertSame(['landed_by' => 'rerun'], $composed['said']);
    }

    public function testAFileComposerRemovedIsRemovedFromTheHouse(): void
    {
        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer update', static function (string $command, string $cwd): array {
            unlink($cwd . '/composer.lock');

            return [0, []];
        });

        self::assertNull($composed['refused']);
        self::assertFileDoesNotExist($this->root . '/composer.lock');
    }

    public function testAPutBackRemovesAFileTheChangeCreated(): void
    {
        unlink($this->root . '/composer.lock');
        file_put_contents($this->root . '/var/poison', '1');

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/stateful', $this->composer(
            "if (is_file(dirname(__DIR__, 3) . '/var/poison')) { throw new \\RuntimeException('var/poison says no'); }",
        ));

        self::assertArrayHasKey('rolled_back', $composed['said']);
        self::assertFileDoesNotExist($this->root . '/composer.lock', 'it did not exist before');
        self::assertStringNotContainsString('lab/stateful', (string) file_get_contents($this->root . '/composer.json'));
    }

    public function testLinksInsideVendorAndAbsoluteOnesAreLeftAsTheyAre(): void
    {
        mkdir($this->root . '/vendor/lab/tool/bin', 0o777, true);
        file_put_contents($this->root . '/vendor/lab/tool/bin/run', '#!/bin/sh');
        mkdir($this->root . '/vendor/bin', 0o777, true);
        symlink('../lab/tool/bin/run', $this->root . '/vendor/bin/run');
        symlink(sys_get_temp_dir(), $this->root . '/vendor/lab/tmp');

        $composed = (new HouseBootWitness($this->root))->composeIfItBoots('composer require lab/good', $this->composer());

        self::assertNull($composed['refused']);
        self::assertSame('../lab/tool/bin/run', readlink($this->root . '/vendor/bin/run'));
        self::assertSame(sys_get_temp_dir(), readlink($this->root . '/vendor/lab/tmp'));
    }

    public function testRepairThroughTheOperationStagesItsComposerAndSaysWhatItRefused(): void
    {
        $repair = new \ReflectionMethod(\Milpa\AppRuntime\Operations\CapabilityOperations::class, 'repair');

        /** @var array<string, mixed> $refused */
        $refused = $repair->invoke(new \Milpa\AppRuntime\Operations\CapabilityOperations(), ['package' => 'milpa/nothing'], ['milpa/data']);
        self::assertFalse($refused['ok']);
        self::assertSame(['milpa/data'], $refused['recommended'], 'not recommended: refused before any composer ran');
        self::assertArrayNotHasKey('unwritten', $refused, 'and nothing was staged');

        /** @var array<string, mixed> $dry */
        $dry = $repair->invoke(new \Milpa\AppRuntime\Operations\CapabilityOperations(), ['package' => 'milpa/data', 'dry_run' => true], ['milpa/data']);
        self::assertTrue($dry['dry_run']);
    }

    public function testTheRunnerHandsBackComposersAnswerWhenTheStageLanded(): void
    {
        $runner = new StagedComposerRunner($this->root, new HouseBootWitness($this->root), run: $this->composer());

        [$code, $out] = $runner('composer require lab/good --no-interaction');

        self::assertSame(0, $code);
        self::assertSame(['  - Installing lab/good (1.0.0)'], $out);
        self::assertTrue($runner->said()['house_boots']);
        self::assertContains($runner->said()['landed_by'], ['exchange', 'swap']);
    }

    public function testTheRunnerDevtoolsIsHandedStagesComposerAndRunsTheRestInTheHouse(): void
    {
        $runner = new StagedComposerRunner($this->root, new HouseBootWitness($this->root));

        [$code, $out] = $runner('echo "$PWD"');
        self::assertSame(0, $code);
        self::assertSame([realpath($this->root)], array_map('realpath', $out), 'not Composer: runs in the house');
        self::assertSame([], $runner->said());

        [$code] = $runner('composer --version --dry-run');
        self::assertSame([], $runner->said(), 'a dry run writes nothing, and is not staged');

        $asked = '';
        $poisoned = new StagedComposerRunner($this->root, new HouseBootWitness($this->root), recovery: true, run: function (string $command, string $cwd) use (&$asked): array {
            $asked = $command;

            return $this->composer("throw new \\RuntimeException('lab/poison cannot start');")($command, $cwd);
        });
        [$code, $out] = $poisoned('composer require lab/poison --no-interaction');
        self::assertSame(1, $code);
        self::assertStringContainsString('lab/poison cannot start', $out[0]);
        self::assertSame('composer require lab/poison --no-interaction', $asked, 'one --no-interaction: the witness adds it');
        self::assertSame(['composer.json', 'composer.lock', 'vendor/'], $poisoned->said()['unwritten'], 'what the witness said is joined');
        self::assertTrue($poisoned->said()['house_boots'], 'a recovery that would break a booting house is still refused');
    }

    public function testEnablingACapabilityWhoseInstallTheHouseCannotBootWithInstallsNothing(): void
    {
        [$vendor, $index] = $this->registry();
        $before = $this->tree();

        $answer = Capabilities::install('lab/poison', $vendor, $this->composer("throw new \\RuntimeException('lab/poison cannot start');"), index: $index, root: $this->root, witness: new HouseBootWitness($this->root));

        self::assertFalse($answer['ok']);
        self::assertFalse($answer['installed']);
        self::assertStringContainsString('lab/poison cannot start', (string) $answer['error']);
        self::assertSame(['composer.json', 'composer.lock', 'vendor/'], $answer['unwritten']);
        self::assertSame($before, $this->tree());
    }

    public function testEnablingACapabilityWhoseInstallBootsSaysHowComposerLanded(): void
    {
        [$vendor, $index] = $this->registry();

        $answer = Capabilities::install('lab/good', $vendor, $this->composer(), index: $index, vendorAfter: $this->delivered('lab/good'), root: $this->root, witness: new HouseBootWitness($this->root));

        self::assertTrue($answer['ok'], (string) ($answer['error'] ?? ''));
        self::assertTrue($answer['composer']['house_boots']);
        self::assertContains($answer['composer']['landed_by'], ['exchange', 'swap']);
        self::assertFileExists($this->root . '/vendor/lab/good/files.php');
    }

    public function testAComposerFailureDuringEnableSaysNothingLanded(): void
    {
        [$vendor, $index] = $this->registry();

        $answer = Capabilities::install('lab/good', $vendor, $this->composer('', 1), index: $index, root: $this->root, witness: new HouseBootWitness($this->root));

        self::assertFalse($answer['ok']);
        self::assertSame(['composer.json', 'composer.lock', 'vendor/'], $answer['unwritten']);
    }

    /** @return array{0: string, 1: array<string, mixed>} an empty vendor to read, and a registry offering lab/poison and lab/good */
    private function registry(): array
    {
        $vendor = $this->root . '/var/registry-vendor';
        mkdir($vendor . '/composer', 0o777, true);
        file_put_contents($vendor . '/composer/installed.json', json_encode(['packages' => []]));
        $cap = static fn (string $id): array => ['id' => $id, 'title' => $id, 'unlocks' => ['x'], 'provides' => ['lab.' . $id], 'briefing' => '', 'version' => 'v1.0.0'];

        return [$vendor, ['derived_at' => '2026-09-29T00:00:00+00:00', 'capabilities' => ['lab/poison' => $cap('poison'), 'lab/good' => $cap('good')], 'undeclared' => []]];
    }

    private function delivered(string $package): string
    {
        $vendor = $this->root . '/var/delivered-vendor';
        mkdir($vendor . '/composer', 0o777, true);
        file_put_contents($vendor . '/composer/installed.json', json_encode(['packages' => [[
            'name' => $package,
            'extra' => ['milpa' => ['capability' => ['id' => 'good', 'title' => 'good', 'unlocks' => ['x'], 'provides' => ['lab.good'], 'briefing' => '']]],
        ]]]));

        return $vendor;
    }
}
