<?php

/**
 * This file is part of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Framework;

use Milpa\AppRuntime\Framework\FrameworkApply;
use Milpa\AppRuntime\Framework\FrameworkReconciliation;
use Milpa\AppRuntime\Framework\FrameworkStamp;
use Milpa\AppRuntime\Framework\FrameworkUpdate;
use Milpa\AppRuntime\Operations\FrameworkOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * APPLYING TAKES `offered` AND `added`, AND NOTHING ELSE — the safety story of the whole arc.
 *
 * `offered` is «the skeleton moved this file and the house did not»: the bytes being replaced are the
 * ones the skeleton handed over, so taking the new ones loses nothing. Everything else is left, and the
 * two that are left BY NAME are the dangerous ones — `conflicted`, where overwriting destroys the
 * house's work, and `unrecorded`, which is the same risk without the evidence to see it
 * (greenhouse decisions/0294, 0295).
 */
#[CoversClass(FrameworkApply::class)]
#[CoversClass(FrameworkUpdate::class)]
#[CoversClass(FrameworkOperations::class)]
final class ApplyingTakesOnlyWhatIsSafeTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/{,*/,*/*/}*', \GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            exec('rm -rf ' . escapeshellarg($dir . '/.git'));
            foreach (['config', 'public', '.milpa', 'tools', 'bin', 'storage/framework-releases', 'storage'] as $d) {
                @rmdir($dir . '/' . $d);
            }
            @rmdir($dir);
        }
    }

    private function dir(string $tag): string
    {
        $dir = sys_get_temp_dir() . '/milpa-' . $tag . '-' . uniqid('', true);
        foreach (['config', 'public', '.milpa', 'tools'] as $d) {
            mkdir($dir . '/' . $d, 0o777, true);
        }
        $this->dirs[] = $dir;

        return $dir;
    }

    /** It writes the paths it was given, and skips one the release does not carry. */
    public function testItWritesWhatItIsGivenAndSkipsWhatTheReleaseDoesNotCarry(): void
    {
        $tree = $this->dir('release');
        $house = $this->dir('house');
        file_put_contents($tree . '/public/index.php', "<?php // the newer entry\n");
        file_put_contents($tree . '/tools/new.php', "<?php // grew\n");
        file_put_contents($house . '/public/index.php', "<?php // the older entry\n");

        $written = FrameworkApply::take($tree, $house, ['public/index.php', 'tools/new.php', 'config/gone.php']);

        self::assertSame(['public/index.php', 'tools/new.php'], $written, 'a path the release does not carry is skipped, not reported as written');
        self::assertSame("<?php // the newer entry\n", file_get_contents($house . '/public/index.php'));
        self::assertFileExists($house . '/tools/new.php', 'an added file is created');
        self::assertFileDoesNotExist($house . '/config/gone.php');
    }

    /** A path it was not given is never touched, however tempting the diff looks. */
    public function testItTouchesNothingItWasNotGiven(): void
    {
        $tree = $this->dir('release');
        $house = $this->dir('house');
        file_put_contents($tree . '/config/app.php', "<?php return ['theirs' => true];\n");
        file_put_contents($house . '/config/app.php', "<?php return ['mine' => true];\n");

        FrameworkApply::take($tree, $house, []);

        self::assertSame("<?php return ['mine' => true];\n", file_get_contents($house . '/config/app.php'), 'the house keeps its own bytes');
    }

    /**
     * 🚨 THE ROWS THE OPERATION WOULD TAKE ARE EXACTLY `offered` AND `added`.
     *
     * Asserted against the reconciliation's own answers rather than by re-deriving them, because two
     * places deciding what is safe to overwrite is one place too many — and the one that would be wrong
     * is whichever was written second.
     */
    public function testOnlyOfferedAndAddedAreEverConsideredSafe(): void
    {
        $house = $this->dir('house');
        $birth = [
            'composer.json' => "{\"name\":\"milpa/framework\"}\n",
            'public/index.php' => "<?php // entry\n",
            'config/app.php' => "<?php return [];\n",
        ];
        foreach ($birth as $path => $bytes) {
            file_put_contents($house . '/' . $path, $bytes);
        }
        file_put_contents($house . '/tools/kept.php', "<?php // present, unrecorded\n");
        file_put_contents($house . '/' . FrameworkStamp::PATH, (string) json_encode([
            'version' => '0.48.1',
            'born' => ['version' => '0.48.1', 'at' => 'now', 'files' => array_map(
                static fn (string $b): string => hash('sha256', $b),
                $birth,
            )],
        ]));
        // the house moved config/app.php, so the skeleton moving it too is a conflict
        file_put_contents($house . '/config/app.php', "<?php return ['mine' => true];\n");

        $ships = array_map(static fn (string $b): string => hash('sha256', $b), $birth);
        $ships['public/index.php'] = hash('sha256', "<?php // newer entry\n");   // offered
        $ships['config/app.php'] = hash('sha256', "<?php return ['theirs' => true];\n"); // conflicted
        $ships['tools/kept.php'] = hash('sha256', "<?php // theirs\n");          // unrecorded (on disk)
        $ships['tools/brand-new.php'] = hash('sha256', "<?php // added\n");      // added (not on disk)

        $rows = FrameworkReconciliation::rows($house, $ships);
        self::assertNotNull($rows);
        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']][] = $row['path'];
        }

        self::assertSame(['public/index.php'], $byStatus[FrameworkReconciliation::OFFERED] ?? []);
        self::assertSame(['tools/brand-new.php'], $byStatus[FrameworkReconciliation::ADDED] ?? []);
        self::assertSame(['config/app.php'], $byStatus[FrameworkReconciliation::CONFLICTED] ?? [], 'never taken');
        self::assertSame(['tools/kept.php'], $byStatus[FrameworkReconciliation::UNRECORDED] ?? [], 'never taken either');
    }

    /** The act declares what it is: privileged, persistent, on something executable, and scoped. */
    public function testTheActDeclaresItselfPrivilegedAndScoped(): void
    {
        $apply = null;
        foreach ((new FrameworkOperations())->operations() as $operation) {
            if ($operation->name === 'framework:apply') {
                $apply = $operation;
            }
        }

        self::assertInstanceOf(Operation::class, $apply);
        self::assertSame(Mutation::Persistent, $apply->effects?->mutation);
        self::assertSame(Authority::Privileged, $apply->effects?->authority, 'it rewrites the files this app boots from');
        self::assertSame(\Milpa\Command\Effect\Reversibility::ManualRecovery, $apply->effects?->reversibility, 'git is the way back, and the operation refuses when git cannot be it');
        self::assertSame(Subject::Executable, $apply->effects?->subject);
        self::assertSame(['framework:apply'], $apply->scopes, 'a scope, so no host can expose it over HTTP without a judge');
        self::assertTrue($apply->mutating);
    }

    /** And the two readers next to it declare that they read. */
    public function testTheReadersDeclareThatTheyRead(): void
    {
        $names = [];
        foreach ((new FrameworkOperations())->operations() as $operation) {
            $names[$operation->name] = $operation;
        }

        self::assertSame(Mutation::None, $names['framework:provenance']->effects?->mutation);
        self::assertSame(Mutation::None, $names['framework:diff']->effects?->mutation);
        self::assertSame([], $names['framework:diff']->scopes, 'reading a public registry needs no scope');
        self::assertFalse($names['framework:provenance']->mutating);
    }

    /**
     * 🚨 GIT MUST BE ABLE TO BE THE WAY BACK, and «tracked» is not the same as «clean».
     *
     * `Reversibility::ManualRecovery` is a promise that a person can get back, and git is how. The first
     * guard asked `git status --porcelain`, which answers EMPTY for a file git has never seen — an
     * untracked or ignored one — and empty reads exactly like clean.
     *
     * Measured in the real lab layout, where a cattle app sits inside this house's own repository under
     * a gitignored `var/lab/`: `rev-parse` said yes, `status` said clean, and git held no copy of a
     * single file. The guard would have declared a way back that did not exist
     * (greenhouse decisions/0295, evidence/0621).
     *
     * Run here rather than read out of the source: these three refusals are what a person is told, and
     * the earlier version of this test asserted them by grepping the file — which passes just as well
     * when the sentence has moved somewhere it can no longer be reached.
     */
    public function testItRefusesWhenGitCannotBeTheWayBack(): void
    {
        $house = $this->houseWithAnOfferedFile();

        $noRepo = FrameworkUpdate::apply($house, self::AGAINST);
        self::assertSame([], $noRepo['applied']);
        self::assertStringContainsString('not a git repository', (string) $noRepo['refused']);

        exec('git -C ' . escapeshellarg($house) . ' init -q 2>/dev/null');
        $untracked = FrameworkUpdate::apply($house, self::AGAINST);
        self::assertSame([], $untracked['applied']);
        self::assertStringContainsString('git has never seen these files', (string) $untracked['refused'], 'the refusal `git status` alone could never make');
        self::assertStringContainsString('composer.json', (string) $untracked['refused'], 'and it names which');

        // THE DIRTY ARM NEEDS A FILE THAT IS STILL `offered`. Editing the file to make git see a change
        // also makes the HOUSE the one who moved it, which turns it `conflicted` — and «nothing is safe
        // to take» then fires before the git guard is ever reached. So the commit carries OTHER bytes
        // and the birth bytes are written back: born == now, and git still sees a modification. The two
        // views are independent, which is the point of asking git at all.
        $birth = (string) file_get_contents($house . '/composer.json');
        file_put_contents($house . '/composer.json', "{\"name\":\"committed/other\"}\n");
        exec('git -C ' . escapeshellarg($house) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($house) . ' -c user.email=t@t -c user.name=t commit -qm other 2>/dev/null');
        file_put_contents($house . '/composer.json', $birth);

        $dirty = FrameworkUpdate::apply($house, self::AGAINST);
        self::assertSame([], $dirty['applied']);
        self::assertStringContainsString('uncommitted changes', (string) $dirty['refused'], 'an uncommitted edit is not overwritten, even where the reconciliation says the file is safe');

        self::rmGit($house);
    }

    /** Without a birth record nothing can be judged safe, so nothing is written. */
    public function testItRefusesWithoutABirthRecord(): void
    {
        $house = $this->houseWithAnOfferedFile(stamped: false);

        $answer = FrameworkUpdate::apply($house, self::AGAINST);

        self::assertSame([], $answer['applied']);
        self::assertStringContainsString('no birth record', (string) $answer['refused']);
    }

    /** And it says so when every file is one of the ones it must leave. */
    public function testItSaysSoWhenNothingIsSafeToTake(): void
    {
        $house = $this->houseWithAnOfferedFile(offered: false);

        $answer = FrameworkUpdate::apply($house, self::AGAINST);

        self::assertSame([], $answer['applied']);
        self::assertStringContainsString('nothing is safe to take', (string) $answer['refused']);
        self::assertNotSame([], $answer['left'], 'and the ones it left are named, with why');
    }

    /** `provenance` reads without a network, and says «cannot say» rather than zero. */
    public function testProvenanceReadsTheRecordAndSaysWhenThereIsNone(): void
    {
        $with = FrameworkUpdate::provenance($this->houseWithAnOfferedFile());
        self::assertSame('0.48.1', $with['born']);
        self::assertArrayNotHasKey('cannot_say', $with);

        $without = FrameworkUpdate::provenance($this->houseWithAnOfferedFile(stamped: false));
        self::assertNull($without['born']);
        self::assertStringContainsString('no birth record', (string) $without['cannot_say']);
        self::assertSame(0, $without['customized'], 'zeros ARE returned, but next to the sentence that says they mean nothing');
    }

    /** `diff` answers from the cache alone — the version is given, so nothing is asked of the registry. */
    public function testDiffReadsTheCachedReleaseAndNamesWhatWouldNeedADecision(): void
    {
        $house = $this->houseWithAnOfferedFile();

        $answer = FrameworkUpdate::diff($house, self::AGAINST);

        self::assertSame(self::AGAINST, $answer['against']);
        self::assertSame(1, $answer['actionable']);
        self::assertSame([['path' => 'composer.json', 'status' => FrameworkReconciliation::OFFERED]], $answer['files']);
    }

    /** The release this fixture compares against — cached, so no test reaches the network. */
    private const string AGAINST = '9.9.9';

    /**
     * A house born from 0.48.1 whose `composer.json` the release moved and the house did not.
     *
     * The `ships` cache is SEEDED, which is what keeps every test above offline: `FrameworkRelease`
     * reads a release's hashes from `storage/framework-releases/<version>.json` before fetching
     * anything, so a seeded file is a release that was already asked about.
     */
    private function houseWithAnOfferedFile(bool $stamped = true, bool $offered = true): string
    {
        $house = $this->dir('house');
        $birth = ['composer.json' => "{\"name\":\"milpa/framework\"}\n"];
        file_put_contents($house . '/composer.json', $birth['composer.json']);
        if (!$offered) {
            // the house moved it too, so the same release makes it CONFLICTED and nothing is safe
            file_put_contents($house . '/composer.json', "{\"name\":\"my/app\"}\n");
        }
        if ($stamped) {
            file_put_contents($house . '/' . FrameworkStamp::PATH, (string) json_encode([
                'version' => '0.48.1',
                'born' => ['version' => '0.48.1', 'at' => 'now', 'files' => array_map(
                    static fn (string $b): string => hash('sha256', $b),
                    $birth,
                )],
            ]));
        } else {
            file_put_contents($house . '/' . FrameworkStamp::PATH, (string) json_encode(['version' => '0.48.1']));
        }

        mkdir($house . '/storage/framework-releases', 0o777, true);
        file_put_contents(
            $house . '/storage/framework-releases/' . self::AGAINST . '.json',
            (string) json_encode(['composer.json' => hash('sha256', "{\"name\":\"milpa/framework\",\"newer\":true}\n")]),
        );

        return $house;
    }

    /** `git init` leaves a tree phpunit's tearDown will not clear on its own. */
    private static function rmGit(string $house): void
    {
        exec('rm -rf ' . escapeshellarg($house . '/.git'));
    }
}
