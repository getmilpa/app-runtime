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

use Milpa\AppRuntime\Support\BootCandidate;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use PHPUnit\Framework\TestCase;

/**
 * The house as it would be after a change is booted BESIDE it, never in it (greenhouse decisions/0512).
 *
 * The house here has Composer's shape where it matters: `vendor/autoload.php` hands off to a file under
 * `vendor/composer/` that resolves `App\` from ITS OWN directory, two levels up — as `autoload_static.php`
 * does. That is the trap a linked `vendor/` falls into: PHP resolves the link in `__DIR__`, the candidate
 * boots the live `src/`, and every answer is the live house's.
 *
 * @guards a change is applied to a copy that resolves `App\` to itself; the live tree is never written; the
 *         copy is removed; `.env` is linked, not copied; `.git/` and `var/` are not copied; a path that leaves
 *         the house is refused
 *
 * @refuses a change naming `../` — the candidate is not built and the reason says so
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseAsItWouldBeBootsBesideItTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
        // Composer's shape: the map that places `App\` lives in vendor/composer/ and counts up from itself.
        $runtime = var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        mkdir($this->root . '/vendor/composer');
        file_put_contents($this->root . '/vendor/composer/autoload_real.php', '<?php
$loader = require ' . $runtime . ';
$loader->addPsr4("App\\\\", __DIR__ . "/../../src");
return $loader;
');
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\nreturn require __DIR__ . '/composer/autoload_real.php';\n");
        mkdir($this->root . '/vendor/acme');
        file_put_contents($this->root . '/vendor/acme/Tool.php', '<?php // a package');
        file_put_contents($this->root . '/.env', "SECRET=1\n");
        mkdir($this->root . '/.git');
        file_put_contents($this->root . '/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents($this->root . '/var/state.json', '{}');
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    public function testABreakingChangeIsSeenWithoutTouchingTheHouse(): void
    {
        $live = $this->root . '/src/Plugins/Blog/Blog.php';
        $bytes = (string) file_get_contents($live);
        $inode = fileinode($live);

        $why = (new BootProbe())->whyNotWith($this->root, ['src/Plugins/Blog/Blog.php' => TinyHouse::pluginSource('Blog', broken: true)]);

        self::assertNotNull($why);
        self::assertStringContainsString('Class App\Plugins\Blog\Blog contains 1 abstract method', $why);
        self::assertStringNotContainsString('boot-candidates', $why, 'the reason names the house\'s paths, not the candidate\'s');
        self::assertStringNotContainsString($this->root, $why);
        self::assertSame($bytes, file_get_contents($live));
        self::assertSame($inode, fileinode($live));
        self::assertNull((new BootProbe())->whyNot($this->root), 'the house itself still boots');
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates');
    }

    /** The mirror: a broken house, and a change that fixes it — the candidate boots its OWN `src/`, not the live one. */
    public function testAFixIsSeenAsAFix(): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', TinyHouse::pluginSource('Blog', broken: true));
        self::assertNotNull((new BootProbe())->whyNot($this->root));

        self::assertNull((new BootProbe())->whyNotWith($this->root, ['src/Plugins/Blog/Blog.php' => TinyHouse::pluginSource('Blog')]));
    }

    public function testADeletionIsPartOfTheCandidate(): void
    {
        $why = (new BootProbe())->whyNotWith($this->root, [], ['src/Plugins/Blog/Blog.php']);

        self::assertNotNull($why, 'config/plugins.php still names a class the change removes');
        self::assertFileExists($this->root . '/src/Plugins/Blog/Blog.php');
    }

    public function testTheCandidateIsTheHouseMinusItsStateWithItsOwnMaps(): void
    {
        $candidate = BootCandidate::of($this->root, ['src/New.php' => '<?php // new']);
        try {
            $path = $candidate->path;
            self::assertStringStartsWith($this->root . '/var/boot-candidates/', $path);
            self::assertFileExists($path . '/src/New.php');
            self::assertFileDoesNotExist($this->root . '/src/New.php');
            self::assertFileExists($path . '/config/plugins.php');
            self::assertTrue(is_link($path . '/.env'), 'a secret is linked, never copied');
            self::assertDirectoryDoesNotExist($path . '/.git');
            self::assertDirectoryExists($path . '/var');
            self::assertFileDoesNotExist($path . '/var/state.json', 'state does not travel');
            self::assertTrue(is_link($path . '/vendor/acme'), 'a package is linked: the one installed code');
            self::assertFalse(is_link($path . '/vendor/composer'), 'the maps are copied: they decide where App\ lives');
            self::assertFalse(is_link($path . '/vendor/autoload.php'));
        } finally {
            $candidate->remove();
        }
        self::assertDirectoryDoesNotExist($candidate->path);
        self::assertFileExists($this->root . '/.env', 'removing the candidate removed the link, not the secret');
        self::assertFileExists($this->root . '/vendor/acme/Tool.php');
    }

    /** A link in the house stays a link in the candidate; a change that writes over a link writes a file, and the house's link is untouched. */
    public function testLinksAreCopiedAsLinksAndAWriteOverOneIsAFile(): void
    {
        symlink('../config/app.php', $this->root . '/public/app-link.php');
        symlink('../config/app.php', $this->root . '/public/overwritten.php');

        $candidate = BootCandidate::of($this->root, ['public/overwritten.php' => '<?php return 1;'], ['public/app-link.php']);
        try {
            self::assertFileDoesNotExist($candidate->path . '/public/app-link.php');
            self::assertFalse(is_link($candidate->path . '/public/overwritten.php'));
            self::assertSame('<?php return 1;', file_get_contents($candidate->path . '/public/overwritten.php'));
        } finally {
            $candidate->remove();
        }
        self::assertTrue(is_link($this->root . '/public/app-link.php'));
        self::assertSame('<?php return [];', file_get_contents($this->root . '/public/overwritten.php'), 'the link in the house still points where it did');

        $kept = BootCandidate::of($this->root, []);
        try {
            self::assertTrue(is_link($kept->path . '/public/app-link.php'), 'an untouched link is copied as a link');
        } finally {
            $kept->remove();
        }
    }

    /** A house with no `vendor/` has nothing to boot with — said, never read as «boots». */
    public function testAHouseWithoutVendorIsSaid(): void
    {
        TinyHouse::remove($this->root . '/vendor');

        self::assertSame('the house has no vendor/autoload.php', (new BootProbe())->whyNotWith($this->root, []));
    }

    public function testAPathThatLeavesTheHouseIsRefused(): void
    {
        $why = (new BootProbe())->whyNotWith($this->root, ['../escaped.php' => '<?php']);

        self::assertSame('the house as it would be could not be built to boot it: a change names a path outside the house: ../escaped.php', $why);
        self::assertFileDoesNotExist(\dirname($this->root) . '/escaped.php');
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates');
    }
}
