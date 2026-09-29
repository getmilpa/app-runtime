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
 * A promotion the house cannot boot with does not land (greenhouse decisions/0506).
 *
 * Measured on the published train (greenhouse evidence/1036, R1, seq 442): the resident promoted a seeder
 * whose constructor wanted a repository its plugin's `boot()` did not pass. The receipt said `ok: true`
 * beside «the house did not boot», and from then on every door of the house died at boot. Here the same
 * shape — and the compile-fatal shape of evidence/1038 n5 — are promoted into real houses on disk.
 *
 * @guards the house is asked in a fresh process after the write; a boot it cannot do is answered `ok: false`
 *         with the reason, the pre-image goes back, the trial is kept, and a later fixed promotion lands
 *
 * @refuses nothing — the positive control is the same promotion with no probe, which lands and breaks the house
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

        $receipt = $this->promote('w1', ['src/Plugins/Blog/BlogSeeder.php' => self::SEEDER, 'src/Plugins/Blog/Blog.php' => $this->seederPlugin(false)]);

        self::assertFalse($receipt['ok'], (string) json_encode($receipt));
        self::assertStringStartsWith('the house does not boot with this promotion: ArgumentCountError: Too few arguments to function App\Plugins\Blog\BlogSeeder::__construct()', $receipt['error']);
        self::assertSame(['src/Plugins/Blog/Blog.php', 'src/Plugins/Blog/BlogSeeder.php'], $receipt['rolled_back']);
        self::assertTrue($receipt['house_boots']);
        self::assertSame($before, file_get_contents($this->root . '/src/Plugins/Blog/Blog.php'), 'the edited file is back');
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Blog/BlogSeeder.php', 'the added file is gone');
        self::assertNull((new BootProbe())->whyNot($this->root));
        self::assertFileDoesNotExist($this->root . '/var/trials/w1/promoted.json', 'nothing to undo: nothing landed');
        self::assertDirectoryExists($this->root . '/var/trials/w1/copy', 'the trial is kept to be fixed');
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
    private function promote(string $id, array $files, bool $probe = true): array
    {
        $ws = TrialWorkspace::materialize($this->root, $id, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        foreach ($files as $path => $contents) {
            @mkdir(\dirname($ws->copy . '/' . $path), 0o777, true);
            file_put_contents($ws->copy . '/' . $path, $contents);
        }

        return $this->promoteTrial($id, $probe);
    }

    /** @return array<string, mixed> */
    private function promoteTrial(string $id, bool $probe = true): array
    {
        foreach ((new TrialOperations(new DIContainer(), null, $this->root, null, $probe ? new BootProbe() : null))->operations() as $op) {
            if ($op->name === 'sandbox:promote') {
                return ($op->handler)(['workspace' => $id]);
            }
        }
        self::fail('no sandbox:promote');
    }
}
