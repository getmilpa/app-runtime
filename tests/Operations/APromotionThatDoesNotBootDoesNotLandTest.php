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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * A promotion the house cannot boot with does not land (greenhouse decisions/0506) — and, since 0512, it never
 * touches the live tree: the house AS IT WOULD BE is booted beside it first.
 *
 * Measured on the published train (greenhouse evidence/1036, R1, seq 442): the resident promoted a seeder
 * whose constructor wanted a repository its plugin's `boot()` did not pass. The receipt said `ok: true`
 * beside «the house did not boot», and from then on every door of the house died at boot. Here the same
 * shape — and the compile-fatal shape of evidence/1038 n5 — are promoted into real houses on disk.
 *
 * @guards the house as it would be is booted in a fresh process BEFORE the write; a boot it cannot do is answered
 *         `ok: false` with the reason, the live files are never replaced (same inode), no pre-image is taken, the
 *         trial is kept, and a later fixed promotion lands
 *
 * @refuses nothing — the positive controls are the same promotion with no probe (it lands and breaks the house) and
 *          the 0506 order (write, ask, roll back: the file IS replaced, twice, and put back)
 *
 * @subject-in milpa/app-runtime
 */
final class APromotionThatDoesNotBootDoesNotLandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    /** The shape of seq 442: `boot()` builds a service whose constructor wants an argument it is not given. */
    private function seederPlugin(bool $passesTheRepository): string
    {
        return str_replace(
            'public function boot(): void {}',
            'public function boot(): void { $this->container->registerService(BlogSeeder::class, new BlogSeeder(' . ($passesTheRepository ? '[]' : '') . ')); }',
            TinyHouse::pluginSource('Blog'),
        );
    }

    private const SEEDER = "<?php\nnamespace App\\Plugins\\Blog;\nfinal class BlogSeeder\n{\n    public function __construct(private readonly array \$posts) {}\n}\n";

    public function testTheSeq442ShapeIsRefusedAndTheHouseKeepsBooting(): void
    {
        $before = (string) file_get_contents($this->root . '/src/Plugins/Blog/Blog.php');
        $inode = fileinode($this->root . '/src/Plugins/Blog/Blog.php');

        $receipt = $this->promote('w1', ['src/Plugins/Blog/BlogSeeder.php' => self::SEEDER, 'src/Plugins/Blog/Blog.php' => $this->seederPlugin(false)]);

        self::assertFalse($receipt['ok'], (string) json_encode($receipt));
        self::assertStringStartsWith('the house does not boot with this promotion: ArgumentCountError: Too few arguments to function App\Plugins\Blog\BlogSeeder::__construct()', $receipt['error']);
        self::assertStringContainsString('passed in src/Plugins/Blog/Blog.php on line', $receipt['error'], 'the path is the house\'s, relative — never the candidate\'s');
        self::assertStringNotContainsString($this->root, $receipt['error']);
        self::assertSame(['src/Plugins/Blog/Blog.php', 'src/Plugins/Blog/BlogSeeder.php'], $receipt['unwritten']);
        self::assertArrayNotHasKey('rolled_back', $receipt, 'nothing was written, so nothing was put back');
        self::assertTrue($receipt['house_boots']);
        self::assertSame($inode, fileinode($this->root . '/src/Plugins/Blog/Blog.php'), 'the live file was never replaced');
        self::assertSame($before, file_get_contents($this->root . '/src/Plugins/Blog/Blog.php'));
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Blog/BlogSeeder.php');
        self::assertDirectoryDoesNotExist($this->root . '/var/trials/w1/pre', 'no pre-image: the house was not about to change');
        self::assertDirectoryDoesNotExist($this->root . '/var/boot-candidates', 'the candidate is gone');
        self::assertNull((new BootProbe())->whyNot($this->root));
        self::assertFileDoesNotExist($this->root . '/var/trials/w1/promoted.json', 'nothing to undo: nothing landed');
        self::assertDirectoryExists($this->root . '/var/trials/w1/copy', 'the trial is kept to be fixed');
    }

    /** POSITIVE CONTROL of the window — the 0506 order: the broken file IS written, then put back. That write is what a request could see. */
    public function testThe0506OrderWritesTheBrokenFileAndPutsItBack(): void
    {
        $inode = fileinode($this->root . '/src/Plugins/Blog/Blog.php');

        $receipt = $this->promote('w1', ['src/Plugins/Blog/BlogSeeder.php' => self::SEEDER, 'src/Plugins/Blog/Blog.php' => $this->seederPlugin(false)], probeBeforeWriting: false);

        self::assertFalse($receipt['ok']);
        self::assertSame(['src/Plugins/Blog/Blog.php', 'src/Plugins/Blog/BlogSeeder.php'], $receipt['rolled_back']);
        self::assertNotSame($inode, fileinode($this->root . '/src/Plugins/Blog/Blog.php'), 'the live file was replaced (and replaced back)');
        self::assertNull((new BootProbe())->whyNot($this->root));
    }

    /** A promotion that FIXES a house that does not boot is booted as it would be — and lands: the refusal is about the change, not the house. */
    public function testAPromotionThatFixesABrokenHouseLands(): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', TinyHouse::pluginSource('Blog', broken: true));
        self::assertNotNull((new BootProbe())->whyNot($this->root));

        $receipt = $this->promote('w1', ['src/Plugins/Blog/Blog.php' => TinyHouse::pluginSource('Blog')]);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertNull((new BootProbe())->whyNot($this->root));
    }

    /** A promotion that does not fix a house that already does not boot says so — and still writes nothing. */
    public function testARefusalSaysWhenTheHouseDidNotBootEither(): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', TinyHouse::pluginSource('Blog', broken: true));

        $receipt = $this->promote('w1', ['src/Plugins/Blog/BlogSeeder.php' => self::SEEDER]);

        self::assertFalse($receipt['ok']);
        self::assertFalse($receipt['house_boots']);
        self::assertStringContainsString('The house does not boot as it is either', $receipt['note']);
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Blog/BlogSeeder.php');
    }

    /** The fix, made in the SAME trial, lands — the refusal did not spend it. */
    public function testTheFixedTrialLandsAfterwards(): void
    {
        $this->promote('w1', ['src/Plugins/Blog/BlogSeeder.php' => self::SEEDER, 'src/Plugins/Blog/Blog.php' => $this->seederPlugin(false)]);
        file_put_contents($this->root . '/var/trials/w1/copy/src/Plugins/Blog/Blog.php', $this->seederPlugin(true));

        $receipt = $this->promoteTrial('w1');

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertNull((new BootProbe())->whyNot($this->root));
    }

    public function testACompileFatalIsRefusedToo(): void
    {
        $receipt = $this->promote('w1', ['src/Plugins/Roto/Roto.php' => TinyHouse::pluginSource('Roto', broken: true), 'config/plugins.php' => TinyHouse::pluginsFile('Blog', 'Roto')]);

        self::assertFalse($receipt['ok']);
        self::assertStringContainsString('Fatal error: Class App\Plugins\Roto\Roto contains 1 abstract method', $receipt['error']);
        self::assertSame(TinyHouse::pluginsFile('Blog'), file_get_contents($this->root . '/config/plugins.php'));
    }

    /** POSITIVE CONTROL — the published train's promotion (no probe): the same seeder lands, `ok: true`, and the house no longer boots. */
    public function testWithoutAskingTheBrokenPromotionLandsAndTheHouseFalls(): void
    {
        $receipt = $this->promote('w1', ['src/Plugins/Blog/BlogSeeder.php' => self::SEEDER, 'src/Plugins/Blog/Blog.php' => $this->seederPlugin(false)], probe: false);

        self::assertTrue($receipt['ok']);
        self::assertStringStartsWith('ArgumentCountError: ', (string) (new BootProbe())->whyNot($this->root));
    }

    /**
     * THE NET, WITH THE COPY ON (decisions/0515, Rod's answer 4: the promotion writes through the one witness). A plugin
     * whose `boot()` reads the house's own `var/` boots in the copy (its `var/` is empty) and not in the house: the copy
     * lets it through, the ask after the write catches it, and what was written is put back — the trial kept.
     */
    public function testWhatOnlyTheLiveHouseShowsIsPutBackAfterTheCopyLetItThrough(): void
    {
        file_put_contents($this->root . '/var/poison', '1');
        $stateful = str_replace(
            'public function boot(): void {}',
            'public function boot(): void { if (is_file(dirname(__DIR__, 3) . "/var/poison")) { throw new \RuntimeException("var/poison says no"); } }',
            TinyHouse::pluginSource('Stateful'),
        );

        $receipt = $this->promote('w1', ['src/Plugins/Stateful/Stateful.php' => $stateful, 'config/plugins.php' => TinyHouse::pluginsFile('Blog', 'Stateful')]);

        self::assertFalse($receipt['ok']);
        self::assertSame(['config/plugins.php', 'src/Plugins/Stateful/Stateful.php'], $receipt['rolled_back']);
        self::assertTrue($receipt['house_boots']);
        self::assertStringContainsString('var/poison says no', $receipt['error']);
        self::assertSame(TinyHouse::pluginsFile('Blog'), file_get_contents($this->root . '/config/plugins.php'));
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Stateful/Stateful.php', 'a file the promotion added is gone');
        self::assertDirectoryDoesNotExist($this->root . '/var/trials/w1/pre', 'no pre-image of a promotion that never landed');
        self::assertDirectoryExists($this->root . '/var/trials/w1/copy', 'the trial is kept to be fixed');
    }

    public function testAGoodPromotionLandsAsBefore(): void
    {
        $receipt = $this->promote('w1', ['src/Plugins/Blog/BlogSeeder.php' => self::SEEDER, 'src/Plugins/Blog/Blog.php' => $this->seederPlugin(true)]);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertFileExists($this->root . '/var/trials/w1/promoted.json');
    }

    /**
     * @param array<string, string> $files
     *
     * @return array<string, mixed>
     */
    private function promote(string $id, array $files, bool $probe = true, bool $probeBeforeWriting = true): array
    {
        $ws = TrialWorkspace::materialize($this->root, $id, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        foreach ($files as $path => $contents) {
            @mkdir(\dirname($ws->copy . '/' . $path), 0o777, true);
            file_put_contents($ws->copy . '/' . $path, $contents);
        }

        return $this->promoteTrial($id, $probe, $probeBeforeWriting);
    }

    /** @return array<string, mixed> */
    private function promoteTrial(string $id, bool $probe = true, bool $probeBeforeWriting = true): array
    {
        foreach ((new TrialOperations(new DIContainer(), null, $this->root, null, $probe ? new BootProbe() : null, $probeBeforeWriting))->operations() as $op) {
            if ($op->name === 'sandbox:promote') {
                return ($op->handler)(['workspace' => $id]);
            }
        }
        self::fail('no sandbox:promote');
    }
}
