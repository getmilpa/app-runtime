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

use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Web\ComponentWordOperations;
use Milpa\AppRuntime\Web\ComponentWords;
use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * No merge of what actually collides (greenhouse decisions/0467, refining 0068) — Rod's falsifiers,
 * literally, on real trials, the real stores and the real promotion.
 *
 * Measured first (evidence/1001): three words in three trials; promoting the first moved
 * `config/components.json` and the resident had to write the other two again. A keyed declaration
 * store is judged per key: disjoint keys join, a key both sides changed is a conflict — and the first
 * definition is never lost or silently overwritten.
 *
 * @guards disjoint keys promoting from one base, for words and for screens; the merged bytes being
 *         what undo checks; every other moved file and every trial without a base staying file-level
 *
 * @refuses promoting a key the house changed since the trial began
 *
 * @subject-in milpa/app-runtime
 */
final class NoMergeOfWhatCollidesTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-keyed-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/var', 0o777, true);
        mkdir($this->root . '/src/Plugins/Owned', 0o777, true);
        file_put_contents($this->root . '/src/Plugins/Owned/A.php', "<?php // a\n");
    }

    protected function tearDown(): void
    {
        self::rmrf($this->root);
    }

    public function testTwoWordsFromOneBaseBothPromote(): void
    {
        // A house that already speaks: the base each trial saw is not empty, and only the snapshot
        // taken at its birth can say what it was.
        ComponentWords::forRoot($this->root)->define(self::word('seed', 'already here'), ['dashboard-grid', 'metric-card']);
        $a = $this->trial('a');
        $b = $this->trial('b');
        $this->define($a, 'post-article', 'one post, readable');
        $this->define($b, 'post-feed', 'every published post');

        self::assertTrue($this->promote($a)['ok']);
        $second = $this->promote($b);
        self::assertTrue($second['ok'], json_encode($second) ?: '');
        self::assertSame(['config/components.json' => ['post-feed']], $second['merged'], 'the receipt names what was joined');

        self::assertSame(['post-article', 'post-feed', 'seed'], array_keys(ComponentWords::forRoot($this->root)->all()));
    }

    public function testTheSameWordFromOneBaseIsAConflictAndTheFirstIsNeverLost(): void
    {
        $c = $this->trial('c');
        $d = $this->trial('d');
        $this->define($c, 'post-feed', 'definition C');
        $this->define($d, 'post-feed', 'definition D');

        self::assertTrue($this->promote($c)['ok']);
        $refused = $this->promote($d);
        self::assertFalse($refused['ok']);
        self::assertSame(['config/components.json' => ['post-feed']], $refused['conflicts'], 'the colliding key is named');
        self::assertSame('definition C', ComponentWords::forRoot($this->root)->word('post-feed')['summary'] ?? null, 'C is not lost or overwritten');
    }

    public function testTwoScreensFromOneBaseBothPromote(): void
    {
        $a = $this->trial('sa');
        $b = $this->trial('sb');
        ScreenStore::fromConfig([], $a->copy)->declare(['name' => 'home', 'columns' => [], 'rows' => []]);
        ScreenStore::fromConfig([], $b->copy)->declare(['name' => 'about', 'columns' => [], 'rows' => []]);

        self::assertTrue($this->promote($a)['ok']);
        self::assertTrue($this->promote($b)['ok']);
        self::assertSame(['home', 'about'], ScreenStore::fromConfig([], $this->root)->names());
    }

    public function testUndoingAMergedPromotionReturnsTheHouseItFound(): void
    {
        $a = $this->trial('ua');
        $b = $this->trial('ub');
        $this->define($a, 'post-article', 'A');
        $this->define($b, 'post-feed', 'B');
        $this->promote($a);
        $this->promote($b);

        $undo = TrialWorkspace::undo($this->root, $b->id);
        self::assertTrue($undo['ok'], json_encode($undo) ?: '');
        self::assertSame(['post-article'], array_keys(ComponentWords::forRoot($this->root)->all()), 'B leaves, A stays');
    }

    public function testEveryOtherMovedFileIsStillANewProposal(): void
    {
        $trial = $this->trial('other');
        file_put_contents($trial->copy . '/src/Plugins/Owned/A.php', "<?php // trial\n");
        file_put_contents($this->root . '/src/Plugins/Owned/A.php', "<?php // house moved\n");

        $refused = $this->promote($trial);
        self::assertFalse($refused['ok']);
        self::assertSame(['src/Plugins/Owned/A.php'], $refused['stale']);

        // Even a JSON object with disjoint keys: identity is declared for the two stores, not guessed
        // from a file's shape.
        // Absent when the trial began, created on both sides since: a base of «nothing» exists for any
        // file, so only the declared list keeps this from being joined.
        $json = $this->trial('json');
        file_put_contents($json->copy . '/config/extra.json', '{"b":2}');
        file_put_contents($this->root . '/config/extra.json', '{"c":3}');
        $refused = $this->promote($json);
        self::assertFalse($refused['ok']);
        self::assertContains('config/extra.json', $refused['stale'] ?? []);
    }

    public function testATrialWithoutABaseIsJudgedByFileAsBefore(): void
    {
        ComponentWords::forRoot($this->root)->define(self::word('seed', 'already here'), ['dashboard-grid', 'metric-card']);
        $old = $this->trial('old');
        $this->define($old, 'post-feed', 'old trial');
        self::rmrf($old->baseDirectory() . '/base'); // born before decisions/0467
        ComponentWords::forRoot($this->root)->define(self::word('other', 'moved the house'), ['dashboard-grid', 'metric-card']);

        $refused = $this->promote($old);
        self::assertFalse($refused['ok']);
        self::assertSame([ComponentWords::PATH], $refused['stale']);
    }

    private function trial(string $id): TrialWorkspace
    {
        return TrialWorkspace::materialize($this->root, $id, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
    }

    private function define(TrialWorkspace $trial, string $name, string $summary): void
    {
        $result = ComponentWords::forRoot($trial->copy)->define(self::word($name, $summary), ['dashboard-grid', 'metric-card']);
        self::assertTrue($result['ok'], json_encode($result) ?: '');
    }

    /** @return array<string, mixed> */
    private function promote(TrialWorkspace $trial): array
    {
        $promote = (new TrialOperations(new DIContainer(), root: $this->root))->operations()[0];

        return ($promote->handler)(['workspace' => $trial->id], null, new ToolContext(
            principal: 'w',
            channel: 'cli',
            scopes: [ComponentWordOperations::SCOPE, 'milpa:component:data-table:*'],
        ));
    }

    /** @return array<string, mixed> */
    private static function word(string $name, string $summary): array
    {
        return [
            'name' => $name,
            'summary' => $summary,
            'inputs' => ['value' => ['type' => 'integer']],
            'composition' => ['type' => 'metric-card', 'props' => ['title' => 'x', 'value' => '$value']],
        ];
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
