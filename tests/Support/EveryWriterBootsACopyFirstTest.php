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

use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Framework\FrameworkApply;
use Milpa\AppRuntime\Support\HouseBootWitness;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use Milpa\Plugin\Operations\PluginManagementPlugin;
use Milpa\Plugin\Operations\PluginOperations;
use Milpa\Plugin\Registry\FilePluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Every writer of what the house boots from boots a copy first (greenhouse decisions/0515) — one rule, real boots.
 *
 * Each case boots a real house in a child process: a class that misses `install()` is the compile fatal of
 * evidence/1038 (n5), and a plugin that dies when the house's own `var/` carries a flag is the thing only
 * the live house can show — the reason 0506's check after the write is kept.
 *
 * @guards a write the house as it would be cannot boot with is refused with `unwritten` and the live bytes
 *         untouched; a good write lands and says `house_boots`; what only the live house shows is put back
 *         (`rolled_back`); recovery on a broken house is written even when the house is still broken
 *         (`still_broken`); a recovery that would break a booting house is refused; plugins.register,
 *         plugins.enable and sandbox:undo go through it; milpa/plugin finds this witness by name
 *
 * @refuses an ordinary write whose copy does not boot; a recovery that would break a house that boots
 *
 * @subject-in milpa/app-runtime, milpa/plugin
 */
final class EveryWriterBootsACopyFirstTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
        TinyHouse::plugin($this->root, 'Good');
        mkdir($this->root . '/src/Plugins/Bad', 0o777, true);
        file_put_contents($this->root . '/src/Plugins/Bad/Bad.php', TinyHouse::pluginSource('Bad', broken: true));
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    private function list(): string
    {
        return (string) file_get_contents($this->root . '/config/plugins.php');
    }

    /** A plugin whose `boot()` dies when the LIVE house's `var/` carries a flag — a copy has an empty `var/`. */
    private function statefulPlugin(): void
    {
        mkdir($this->root . '/src/Plugins/Stateful', 0o777, true);
        file_put_contents($this->root . '/src/Plugins/Stateful/Stateful.php', str_replace(
            'public function boot(): void {}',
            'public function boot(): void { if (is_file(dirname(__DIR__, 3) . "/var/poison")) { throw new \RuntimeException("var/poison says no"); } }',
            TinyHouse::pluginSource('Stateful'),
        ));
        file_put_contents($this->root . '/var/poison', '1');
    }

    public function testAWriteTheCopyCannotBootWithIsNeverWritten(): void
    {
        $before = $this->list();
        $inode = fileinode($this->root . '/config/plugins.php');
        $ran = false;

        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['config/plugins.php' => TinyHouse::pluginsFile('Blog', 'Bad')],
            static function () use (&$ran): void {
                $ran = true;
            },
        );

        self::assertFalse($ran, 'the write itself never ran');
        self::assertStringContainsString('contains 1 abstract method', (string) $boot['refused']);
        self::assertStringContainsString('boots as it is', (string) $boot['refused']);
        self::assertSame(['config/plugins.php'], $boot['said']['unwritten']);
        self::assertTrue($boot['said']['house_boots']);
        self::assertSame($before, $this->list());
        self::assertSame($inode, fileinode($this->root . '/config/plugins.php'));
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates', 'the copy is gone');
    }

    public function testAWriteTheHouseBootsWithLandsAndSaysSo(): void
    {
        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['config/plugins.php' => TinyHouse::pluginsFile('Blog', 'Good')],
            fn () => TinyHouse::register($this->root, 'Blog', 'Good'),
        );

        self::assertNull($boot['refused']);
        self::assertTrue($boot['said']['house_boots'], (string) json_encode($boot['said']));
        self::assertStringContainsString('Good::class', $this->list());
    }

    public function testWhatOnlyTheLiveHouseShowsIsPutBack(): void
    {
        $this->statefulPlugin();
        $before = $this->list();

        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['config/plugins.php' => TinyHouse::pluginsFile('Blog', 'Stateful')],
            fn () => TinyHouse::register($this->root, 'Blog', 'Stateful'),
        );

        self::assertStringContainsString('var/poison says no', (string) $boot['refused'], 'the copy booted; the live house did not');
        self::assertSame(['config/plugins.php'], $boot['said']['rolled_back']);
        self::assertTrue($boot['said']['house_boots'], 'and it boots again once put back');
        self::assertSame($before, $this->list());
    }

    public function testAFileTheWriteCreatedIsRemovedWhenItIsPutBack(): void
    {
        $this->statefulPlugin();
        TinyHouse::register($this->root, 'Blog', 'Stateful');
        unlink($this->root . '/var/poison');
        $state = $this->root . '/storage/extra.php';

        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['storage/extra.php' => '<?php // new'],
            function () use ($state): void {
                file_put_contents($state, '<?php // new');
                file_put_contents($this->root . '/var/poison', '1');
            },
        );

        self::assertNotNull($boot['refused']);
        self::assertFileDoesNotExist($state, 'a path that did not exist does not exist again');
        self::assertFalse($boot['said']['house_boots'], 'and it says the house still does not boot: the poison was not its write');
    }

    public function testRecoveryOnABrokenHouseIsWrittenEvenWhenItIsNotEnough(): void
    {
        TinyHouse::register($this->root, 'Blog', 'Bad');
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', TinyHouse::pluginSource('Blog', broken: true));

        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['src/Plugins/Bad/Bad.php' => TinyHouse::pluginSource('Bad')],
            fn () => file_put_contents($this->root . '/src/Plugins/Bad/Bad.php', TinyHouse::pluginSource('Bad')),
            recovery: true,
        );

        self::assertNull($boot['refused'], 'the way back is never refused because the house is already broken');
        self::assertFalse($boot['said']['house_boots']);
        self::assertStringContainsString('Blog', (string) $boot['said']['still_broken'], 'one step of two: it says what is left');
        self::assertSame(TinyHouse::pluginSource('Bad'), file_get_contents($this->root . '/src/Plugins/Bad/Bad.php'));
    }

    public function testTheSameWriteIsRefusedWhenItIsNotRecovery(): void
    {
        TinyHouse::register($this->root, 'Blog', 'Bad');
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', TinyHouse::pluginSource('Blog', broken: true));

        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['src/Plugins/Bad/Bad.php' => TinyHouse::pluginSource('Bad')],
            static fn () => null,
        );

        self::assertNotNull($boot['refused'], 'the positive control of the recovery rule: the same bytes, not recovery');
        self::assertStringContainsString('does not boot as it is either', (string) $boot['refused']);
        self::assertFalse($boot['said']['house_boots']);
    }

    public function testARecoveryThatWouldBreakABootingHouseIsRefused(): void
    {
        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['config/plugins.php' => TinyHouse::pluginsFile('Blog', 'Bad')],
            static fn () => self::fail('never written'),
            recovery: true,
        );

        self::assertNotNull($boot['refused']);
        self::assertSame(['config/plugins.php'], $boot['said']['unwritten']);
    }

    public function testAHouseWithNoVendorIsWrittenAsBeforeAndClaimsNothing(): void
    {
        unlink($this->root . '/vendor/autoload.php');

        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['config/plugins.php' => TinyHouse::pluginsFile('Blog', 'Bad')],
            fn () => TinyHouse::register($this->root, 'Blog', 'Bad'),
        );

        self::assertSame(['refused' => null, 'said' => []], $boot);
        self::assertStringContainsString('Bad::class', $this->list());
    }

    // ── the writers, through it ─────────────────────────────────────────────────────────────────

    /** @return array<string, \Milpa\Command\Operation> */
    private function pluginOperations(): array
    {
        $ops = [];
        $registry = new FilePluginRegistry($this->root . '/storage/plugins.json');
        foreach ((new PluginOperations($registry, null, [], null, $this->root, null, new HouseBootWitness($this->root)))->operations() as $op) {
            $ops[$op->name] = $op;
        }

        return $ops;
    }

    public function testPluginsRegisterOfAClassThatDoesNotCompileWritesNothing(): void
    {
        file_put_contents($this->root . '/config/plugins.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    App\\Plugins\\Blog\\Blog::class,\n];\n");
        $before = $this->list();

        $r = ($this->pluginOperations()['plugins.register']->handler)(['name' => 'Bad']);

        self::assertFalse($r['ok']);
        self::assertSame(['config/plugins.php'], $r['unwritten']);
        self::assertSame($before, $this->list());

        $good = ($this->pluginOperations()['plugins.register']->handler)(['name' => 'Good']);
        self::assertTrue($good['ok']);
        self::assertTrue($good['house_boots']);
    }

    public function testPluginsEnableOfAPluginWhoseBootDiesWritesNothing(): void
    {
        // A declared class is loaded — and compiled — even while it is switched off (`ActivePlugins` reads its
        // metadata), so what an enable can break is a `boot()` that throws. Declared, and off in the store.
        mkdir($this->root . '/src/Plugins/Thrower', 0o777, true);
        file_put_contents($this->root . '/src/Plugins/Thrower/Thrower.php', str_replace(
            'public function boot(): void {}',
            'public function boot(): void { throw new \RuntimeException("Thrower cannot start"); }',
            TinyHouse::pluginSource('Thrower'),
        ));
        TinyHouse::register($this->root, 'Blog', 'Thrower');
        (new FilePluginRegistry($this->root . '/storage/plugins.json'))->register(new \Milpa\Plugin\Contracts\PluginRecord(
            name: 'Thrower',
            version: '0.1.0',
            author: 't',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: false,
            source: 'local',
        ));
        self::assertNull((new \Milpa\AppRuntime\Support\BootProbe())->whyNot($this->root), 'off, the house boots');
        $before = (string) file_get_contents($this->root . '/storage/plugins.json');

        try {
            ($this->pluginOperations()['plugins.enable']->handler)(['name' => 'Thrower']);
            self::fail('an enable the house cannot boot with is refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Thrower cannot start', $e->getMessage());
            self::assertStringContainsString('Nothing was written', $e->getMessage());
        }
        self::assertSame($before, file_get_contents($this->root . '/storage/plugins.json'));
    }

    public function testMilpaPluginFindsThisWitnessByName(): void
    {
        self::assertSame(HouseBootWitness::class, PluginManagementPlugin::HOST_WITNESS);
        self::assertInstanceOf(HouseBootWitness::class, PluginManagementPlugin::hostWitness($this->root));
    }

    public function testAnUndoThatWouldBreakABootingHouseIsRefusedAndKeepsItsRecord(): void
    {
        // A promotion that ADDED Good to the list; its pre-image is a list naming the class that does not compile.
        $id = 'trial-refused';
        $base = $this->root . '/var/trials/' . $id;
        mkdir($base . '/pre/config', 0o777, true);
        file_put_contents($base . '/pre/config/plugins.php', TinyHouse::pluginsFile('Blog', 'Bad'));
        file_put_contents($base . '/promoted.json', (string) json_encode(['config/plugins.php' => ['sha256' => hash_file('sha256', $this->root . '/config/plugins.php')]]));
        $before = $this->list();

        $r = TrialWorkspace::undo($this->root, $id, new HouseBootWitness($this->root));

        self::assertFalse($r['ok']);
        self::assertSame(['config/plugins.php'], $r['unwritten']);
        self::assertSame($before, $this->list());
        self::assertFileExists($base . '/promoted.json', 'nothing was put back, so the promotion is still there to undo');
    }

    public function testAnUndoOnABrokenHouseIsWritten(): void
    {
        $id = 'trial-recovers';
        $base = $this->root . '/var/trials/' . $id;
        mkdir($base . '/pre/config', 0o777, true);
        file_put_contents($base . '/pre/config/plugins.php', TinyHouse::pluginsFile('Blog'));
        TinyHouse::register($this->root, 'Blog', 'Bad');
        file_put_contents($base . '/promoted.json', (string) json_encode(['config/plugins.php' => ['sha256' => hash_file('sha256', $this->root . '/config/plugins.php')]]));

        $r = TrialWorkspace::undo($this->root, $id, new HouseBootWitness($this->root));

        self::assertTrue($r['ok']);
        self::assertTrue($r['house_boots']);
        self::assertSame(TinyHouse::pluginsFile('Blog'), $this->list());
        self::assertDirectoryDoesNotExist($base);
    }

    /** A release tree as `framework:apply` fetches it: the skeleton's files, one of them the plugin list. */
    private function release(string $list): string
    {
        $tree = $this->root . '/var/release';
        mkdir($tree . '/config', 0o777, true);
        file_put_contents($tree . '/config/plugins.php', $list);
        file_put_contents($tree . '/config/app.php', "<?php return ['from' => 'release'];");

        return $tree;
    }

    public function testFrameworkApplyOfAReleaseTheHouseCannotBootWithTakesNothing(): void
    {
        $tree = $this->release(TinyHouse::pluginsFile('Blog', 'Bad'));
        $before = [$this->list(), file_get_contents($this->root . '/config/app.php')];

        $taken = FrameworkApply::takeIfItBoots($tree, $this->root, ['config/plugins.php', 'config/app.php', 'config/absent.php'], new HouseBootWitness($this->root));

        self::assertSame([], $taken['applied']);
        self::assertStringContainsString('abstract method', (string) $taken['refused']);
        self::assertSame(['config/app.php', 'config/plugins.php'], $taken['said']['unwritten'], 'only what the release carries; not one file of it');
        self::assertSame($before, [$this->list(), file_get_contents($this->root . '/config/app.php')]);
    }

    public function testFrameworkApplyOfAReleaseTheHouseBootsWithTakesIt(): void
    {
        $tree = $this->release(TinyHouse::pluginsFile('Blog', 'Good'));

        $taken = FrameworkApply::takeIfItBoots($tree, $this->root, ['config/plugins.php', 'config/app.php'], new HouseBootWitness($this->root));

        self::assertNull($taken['refused']);
        self::assertSame(['config/plugins.php', 'config/app.php'], $taken['applied']);
        self::assertTrue($taken['said']['house_boots']);
        self::assertStringContainsString('Good::class', $this->list());
    }

    public function testFrameworkApplyWithoutAWitnessTakesAsItAlwaysDid(): void
    {
        $tree = $this->release(TinyHouse::pluginsFile('Blog', 'Bad'));

        $taken = FrameworkApply::takeIfItBoots($tree, $this->root, ['config/plugins.php'], null);

        self::assertSame(['config/plugins.php'], $taken['applied'], 'the positive control: unwitnessed, the release that breaks the boot lands');
        self::assertStringContainsString('Bad::class', $this->list());
    }

    public function testASecretIsLinkedIntoTheCopyNeverCopied(): void
    {
        mkdir($this->root . '/.milpa');
        file_put_contents($this->root . '/.milpa/secrets.json', '{"ai":{"key":"sk-test"}}');
        file_put_contents($this->root . '/.milpa/agent.json', '{}');

        $candidate = \Milpa\AppRuntime\Support\BootCandidate::of($this->root, []);
        try {
            self::assertTrue(is_link($candidate->path . '/.milpa/secrets.json'), 'the credential has one file');
            self::assertSame($this->root . '/.milpa/secrets.json', readlink($candidate->path . '/.milpa/secrets.json'));
            self::assertFalse(is_link($candidate->path . '/.milpa/agent.json'), 'what is not a secret is copied');
        } finally {
            $candidate->remove();
        }
        self::assertFileExists($this->root . '/.milpa/secrets.json');
    }
}
