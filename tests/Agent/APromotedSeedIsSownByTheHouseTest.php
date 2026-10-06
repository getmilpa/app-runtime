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

use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * THE HOUSE SEEDS WHEN THE DECLARATION LANDS (greenhouse decisions/0574 §4, slice BV-4).
 *
 * A trial cannot write the house's store: it lives in `var/` (or a database), which a trial neither copies nor
 * promotes (decisions/0463). So a seed is declared in the plugin's tree, and the promotion that lands it makes a
 * FRESH process of the house — one that boots with the plugin as it now is — save the rows. The receipt says how
 * many, and promoting again adds nothing.
 */
final class APromotedSeedIsSownByTheHouseTest extends TestCase
{
    private const SEED = 'src/Plugins/Blog/Seeds/Post.json';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-sown-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/Plugins/Blog/Entities', 0o777, true);
        mkdir($this->root . '/vendor');
        mkdir($this->root . '/config');
        mkdir($this->root . '/public');
        mkdir($this->root . '/var');
        file_put_contents($this->root . '/vendor/autoload.php', '<?php
$loader = require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . "/../src/" . str_replace("\\\\", "/", substr($class, 4)) . ".php";
    if (str_starts_with($class, "App\\\\") && is_file($file)) {
        require $file;
    }
});
return $loader;
');
        file_put_contents($this->root . '/config/plugins.php', '<?php return [App\Plugins\Blog\Blog::class];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => new \Milpa\Container\DIContainer(), "plugins" => require __DIR__ . "/plugins.php"];');
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/public/index.php', "<?php\n");
        file_put_contents($this->root . '/src/Plugins/Blog/Entities/Post.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App\Plugins\Blog\Entities;
            final readonly class Post implements \Milpa\Data\EntityInterface
            {
                public const PUBLIC_WHEN = 'published';
                public function __construct(public int|string|null $id, public string $title, public bool $published) {}
                public function id(): int|string|null { return $this->id; }
                public function toArray(): array { return ['id' => $this->id, 'title' => $this->title, 'published' => $this->published]; }
                public static function fromArray(array $row): static { return new self($row['id'] ?? null, $row['title'], $row['published']); }
            }
            PHP);
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App\Plugins\Blog;
            #[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "Blog", type: "Service")]
            final class Blog implements \Milpa\Interfaces\Plugin\PluginInterface
            {
                public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
                public function boot(): void
                {
                    $this->container->registerService(Entities\Post::class . 'Repository', \Milpa\Data\RepositoryFactory::fromConfig(
                        ['driver' => 'file', 'path' => \dirname(__DIR__, 3) . '/var/posts.json'],
                        Entities\Post::class,
                    ));
                }
                public function install(): void {}
                public function uninstall(): void {}
                public function enable(): void {}
                public function disable(): void {}
            }
            PHP);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAPromotionThatLandsASeedMakesTheHouseSaveItsRows(): void
    {
        self::assertFileDoesNotExist($this->root . '/var/posts.json');

        $receipt = $this->promote('w1', [['title' => 'Hello, blog', 'published' => true], ['title' => 'A draft', 'published' => false]]);

        self::assertTrue($receipt['ok'] ?? false, (string) json_encode($receipt));
        self::assertSame([['entity' => 'Blog/Post', 'declared' => 2, 'added' => 2, 'already' => 0]], $receipt['seeded'] ?? null);
        self::assertStringContainsString('seeded 2 row(s) of Blog/Post', (string) $receipt['note']);
        self::assertSame(['Hello, blog', 'A draft'], array_column($this->stored(), 'title'), 'in the HOUSE\'s store, by a process that booted with the plugin');
        self::assertNotNull($this->stored()[0]['id'] ?? null);
    }

    public function testPromotingAgainSeedsOnlyWhatIsNew(): void
    {
        $this->promote('w1', [['title' => 'Hello, blog', 'published' => true]]);

        $again = $this->promote('w2', [['title' => 'Hello, blog', 'published' => true], ['title' => 'Second', 'published' => true]]);

        self::assertSame([['entity' => 'Blog/Post', 'declared' => 2, 'added' => 1, 'already' => 1]], $again['seeded'] ?? null);
        self::assertSame(['Hello, blog', 'Second'], array_column($this->stored(), 'title'));
    }

    public function testAPromotionThatLandsNoSeedSaysNothingAboutSeeding(): void
    {
        $ws = TrialWorkspace::materialize($this->root, 'w1', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($ws->copy . '/config/notes.php', "<?php return [];\n");

        $receipt = $this->promoteWorkspace('w1');

        self::assertTrue($receipt['ok'] ?? false, (string) json_encode($receipt));
        self::assertArrayNotHasKey('seeded', $receipt);
        self::assertFileDoesNotExist($this->root . '/var/posts.json');
    }

    public function testASeedTheHouseCouldNotSowIsSaidInTheReceipt(): void
    {
        // The plugin no longer registers the repository: the declaration lands, and the receipt does not lie about rows.
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', str_replace("Entities\\Post::class . 'Repository'", "'somewhere.else'", (string) file_get_contents($this->root . '/src/Plugins/Blog/Blog.php')));

        $receipt = $this->promote('w1', [['title' => 'Hello, blog', 'published' => true]]);

        self::assertTrue($receipt['ok'] ?? false, (string) json_encode($receipt));
        self::assertSame(0, $receipt['seeded'][0]['added'] ?? null);
        self::assertStringContainsString('no repository is registered for Blog/Post', (string) ($receipt['seeded'][0]['unseeded'] ?? ''));
        self::assertStringContainsString('seeded 0 row(s) of Blog/Post', (string) $receipt['note']);
        self::assertStringContainsString('no repository is registered for Blog/Post', (string) $receipt['note'], 'and the note a reader sees says why');
    }

    public function testAHouseThatDoesNotBootSowsNothingAndSaysWhy(): void
    {
        $said = (new HouseRouteObserver())->seed($this->root . '/nowhere', [self::SEED]);
        self::assertSame([], $said['seeded']);
        self::assertStringContainsString('the house could not be asked to seed', (string) ($said['error'] ?? ''));

        self::assertSame(['seeded' => []], (new HouseRouteObserver())->seed($this->root . '/nowhere', ['src/Plugins/Blog/Blog.php']), 'no declaration landed: no house is started, so not even one that cannot boot is an error');
    }

    public function testSeedingMakesTheHouseLookAgainAtItsMountedScreens(): void
    {
        ScreenStore::fromConfig([], $this->root)->declare(['name' => 'blog', 'rows' => [], 'route' => '/blog']);

        self::assertSame(['/blog'], HouseRouteObserver::mountedScreens($this->root, [self::SEED]), 'the page that lists the rows is asked after they are sown');
        self::assertSame(['/blog'], HouseRouteObserver::mountedScreens($this->root, ['config/screens.json']));
        self::assertSame([], HouseRouteObserver::mountedScreens($this->root, ['src/Plugins/Blog/Blog.php']));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function promote(string $workspace, array $rows): array
    {
        $ws = TrialWorkspace::materialize($this->root, $workspace, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        @mkdir(\dirname($ws->copy . '/' . self::SEED), 0o777, true);
        file_put_contents($ws->copy . '/' . self::SEED, (string) json_encode(['entity' => 'Blog/Post', 'rows' => $rows]));

        return $this->promoteWorkspace($workspace);
    }

    /** @return array<string, mixed> */
    private function promoteWorkspace(string $workspace): array
    {
        foreach ((new TrialOperations(new DIContainer(), null, $this->root))->operations() as $operation) {
            if ($operation->name === 'sandbox:promote') {
                return ($operation->handler)(['workspace' => $workspace]);
            }
        }
        self::fail('no sandbox:promote');
    }

    /** @return list<array<string, mixed>> */
    private function stored(): array
    {
        $rows = json_decode((string) @file_get_contents($this->root . '/var/posts.json'), true);

        return \is_array($rows) ? array_values($rows) : [];
    }
}
