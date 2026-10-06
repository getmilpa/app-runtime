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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * THE RECEIPT SAYS WHAT A MOUNTED PAGE LISTED (greenhouse decisions/0576 §1, slice BV-3b).
 *
 * A real house on disk: the live door, a plugin with a public entity and its store, and a screen mounted at `/pages`.
 * The house that observes — after a promotion, or on demand — is a fresh process: it reads the entity's rows, paints
 * the page and counts, and the closure reads those counts from the receipt (H1 of decisions/0565).
 */
final class TheHouseSaysWhatAMountedPageListsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-listed-house-' . bin2hex(random_bytes(4));
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
        file_put_contents($this->root . '/config/plugins.php', '<?php return [Milpa\AppRuntime\Web\LivePlugin::class, App\Plugins\Blog\Blog::class];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => new \Milpa\Container\DIContainer(), "plugins" => require __DIR__ . "/plugins.php"];');
        // The store's path is named: this fixture borrows the package's own vendor/, so Composer would say the app
        // root is the package. A real house has its own vendor/ and needs no such line.
        file_put_contents($this->root . '/config/app.php', '<?php return ["live" => ["secret" => "' . str_repeat('k', 32) . '", "screens_path" => __DIR__ . "/screens.json"]];');
        file_put_contents($this->root . '/public/index.php', <<<'PHP'
            <?php
            require __DIR__ . '/../vendor/autoload.php';
            $root = dirname(__DIR__);
            if (is_file($root . '/var/die')) {
                exit(3);
            }
            $boot = require $root . '/config/boot.php';
            $kernel = \Milpa\Runtime\Kernel::boot(['root' => $root, 'plugins' => $boot['plugins'], 'config' => require $root . '/config/app.php', 'container' => $boot['container']]);
            $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
            $request = new \Nyholm\Psr7\ServerRequest($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], [], null, '1.1', $_SERVER);
            // The house's own log, wired as the skeleton wires it — unless this house swapped it for one that writes elsewhere.
            $logger = is_file($root . '/var/logs-elsewhere') ? null : new \Milpa\Runtime\Observability\ErrorLogLogger();
            $response = (new \Milpa\Runtime\Http\ExceptionMiddleware($psr17, $logger))->process($request, new \Milpa\Runtime\Http\RequestHandler($kernel, $psr17));
            (new \Milpa\Runtime\Http\ResponseEmitter())->emit($response);
            if (is_file($root . '/var/die-after')) {
                exit(4);
            }
            PHP);
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
            use Milpa\Http\HttpMethod;
            use Milpa\Http\Routing\HandlerReference;
            use Milpa\Http\Routing\Route;
            #[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "Blog", type: "Service")]
            final class Blog implements \Milpa\Interfaces\Plugin\PluginInterface, \Milpa\Runtime\Http\RouteProviderInterface
            {
                public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
                public function boot(): void
                {
                    $this->container->registerService(Entities\Post::class . 'Repository', \Milpa\Data\RepositoryFactory::fromConfig(
                        ['driver' => 'file', 'path' => \dirname(__DIR__, 3) . '/var/posts.json'],
                        Entities\Post::class,
                    ));
                    $this->container->registerService(self::class, $this);
                }
                public function install(): void {}
                public function uninstall(): void {}
                public function enable(): void {}
                public function disable(): void {}
                public function routes(): array { return [new Route('/raw', HttpMethod::GET, 'raw', [], new HandlerReference(self::class, 'raw'))]; }
                public function raw(): \Psr\Http\Message\ResponseInterface { return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'text/html'], '<h1>by hand</h1>'); }
            }
            PHP);
        file_put_contents($this->root . '/config/screens.json', (string) json_encode(['pages' => [
            'type' => 'data-table',
            'props' => ['name' => 'pages', 'columns' => [['key' => 'title', 'label' => 'Title']], 'source' => ['entity' => 'App\\Plugins\\Blog\\Entities\\Post', 'columns' => ['title'], 'limit' => 50]],
            'route' => '/pages',
        ]]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAnEmptyMountedPageIsSaidToListNothing(): void
    {
        $seen = (new HouseRouteObserver())->observeRoute($this->root, '/pages');

        self::assertSame(200, $seen['entry']['status'], (string) json_encode($seen));
        self::assertSame(
            ['entity' => 'Blog/Post', 'public' => 0, 'shown' => 0, 'withheld' => 0, 'leaked' => 0, 'withholding' => 'unexercised'],
            $seen['entry']['content'] ?? null,
        );
    }

    public function testAMountedPageWithItsRowsIsSaidToListThem(): void
    {
        $this->store([['id' => 1, 'title' => 'Hello, blog', 'published' => true], ['id' => 2, 'title' => 'A draft', 'published' => false]]);

        $seen = (new HouseRouteObserver())->observeRoute($this->root, '/pages');

        self::assertSame(
            ['entity' => 'Blog/Post', 'public' => 1, 'shown' => 1, 'withheld' => 1, 'leaked' => 0, 'withholding' => 'exercised'],
            $seen['entry']['content'] ?? null,
            (string) json_encode($seen),
        );
    }

    public function testWhoeverAsksForAnExcerptGetsTheExcerptItAskedFor(): void
    {
        $this->store([['id' => 1, 'title' => 'Hello, blog', 'published' => true]]);
        $observer = new HouseRouteObserver();

        $short = $observer->observeRoute($this->root, '/pages', 40);
        self::assertSame(40, \strlen((string) $short['excerpt']), 'the house read the whole page to judge it; the excerpt is still the excerpt');
        self::assertSame($short['entry']['bytes'] - 40, $short['truncated']);
        self::assertSame(1, $short['entry']['content']['shown'] ?? null);

        $none = $observer->observeRoute($this->root, '/pages', 0);
        self::assertNull($none['excerpt']);
        self::assertSame(1, $none['entry']['content']['shown'] ?? null, 'and it is judged whole when no excerpt was asked');
    }

    public function testARouteThatIsNotAMountedScreenCarriesNoContent(): void
    {
        $seen = (new HouseRouteObserver())->observeRoute($this->root, '/raw');

        self::assertSame(200, $seen['entry']['status'], (string) json_encode($seen));
        self::assertArrayNotHasKey('content', $seen['entry'], 'a page written by hand has no declaration to compare with: the house does not judge it');
    }

    public function testAPromotionThatMountsAnEmptyPageDoesNotCloseAndOneThatSeedsItDoes(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Serve GET /pages as a page listing the published posts.', AutonomyMode::Auto);

        // The mount lands with an empty store: H1 as it happened on cattle (greenhouse evidence/1108 §4).
        $mounted = $this->promote('w1', ['config/screens.json' => (string) file_get_contents($this->root . '/config/screens.json') . "\n"]);
        self::assertSame(0, $mounted['observed'][0]['content']['public'] ?? null, (string) json_encode($mounted));
        $store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode($mounted), mutating: true);
        $closure = $this->closure($store);
        self::assertFalse($closure['verified'], 'the published house closed here');
        self::assertStringContainsString('served with nothing to read', implode('; ', $closure['reasons']));

        // The seed lands: the house sows, looks at the page again, and the receipt says it lists.
        $seeded = $this->promote('w2', ['src/Plugins/Blog/Seeds/Post.json' => (string) json_encode(['entity' => 'Blog/Post', 'rows' => [
            ['title' => 'Hello, blog', 'published' => true], ['title' => 'A draft', 'published' => false],
        ]])]);
        self::assertSame(['public' => 1, 'shown' => 1, 'withheld' => 1, 'leaked' => 0], array_intersect_key(array_column($seeded['observed'], 'content', 'subject')['/pages'] ?? [], ['public' => 0, 'shown' => 0, 'withheld' => 0, 'leaked' => 0]), (string) json_encode($seeded));
        $store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w2'], (string) json_encode($seeded), mutating: true);
        $closure = $this->closure($store);
        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('exercised', $closure['derivedFrom']['observation']['content']['withholding'] ?? null);
    }

    public function testAHouseThatCannotBeAskedLeavesTheReceiptAsItWas(): void
    {
        $observer = new HouseRouteObserver();
        $before = glob(sys_get_temp_dir() . '/milpa-page-*') ?: [];

        self::assertNull($observer->content($this->root . '/nowhere', '/pages'));
        self::assertNull($observer->content($this->root, '/raw'), 'not a mounted screen');
        self::assertSame($before, glob(sys_get_temp_dir() . '/milpa-page-*') ?: [], 'the page the house judged does not outlive the question');
    }

    /** @param list<array<string, mixed>> $rows */
    private function store(array $rows): void
    {
        file_put_contents($this->root . '/var/posts.json', (string) json_encode(array_column($rows, null, 'id')));
    }

    /**
     * @param array<string, string> $files
     *
     * @return array<string, mixed>
     */
    private function promote(string $workspace, array $files): array
    {
        $ws = TrialWorkspace::materialize($this->root, $workspace, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        foreach ($files as $path => $contents) {
            @mkdir(\dirname($ws->copy . '/' . $path), 0o777, true);
            file_put_contents($ws->copy . '/' . $path, $contents);
        }
        foreach ((new TrialOperations(new DIContainer(), null, $this->root))->operations() as $operation) {
            if ($operation->name === 'sandbox:promote') {
                return ($operation->handler)(['workspace' => $workspace]);
            }
        }
        self::fail('no sandbox:promote');
    }

    /** @return array{verified: bool, reasons: list<string>, derivedFrom?: array<string, mixed>} */
    private function closure(SessionStore $store): array
    {
        $session = $store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));
    }
}
