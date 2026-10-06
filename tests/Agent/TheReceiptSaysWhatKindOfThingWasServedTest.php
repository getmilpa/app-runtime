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
use PHPUnit\Framework\TestCase;

/**
 * THE RECEIPT SAYS WHAT KIND OF THING WAS SERVED (greenhouse decisions/0577 §1–2, slice BV-3).
 *
 * The `served` receipt carried a status, a size and a digest: it did not say whether a page or a JSON document had
 * answered. So a page written by hand closed exactly like one the house read row by row (decisions/0576), and nothing
 * could tell the two apart. A real house on disk, asked the way a browser asks: the receipt says the Content-Type it
 * was answered with and, for `text/html`, that it is a visual surface and which declared screen serves it — or that
 * none does.
 */
final class TheReceiptSaysWhatKindOfThingWasServedTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-served-house-' . bin2hex(random_bytes(4));
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
                public function routes(): array
                {
                    return array_map(static fn (string $at): Route => new Route('/' . $at, HttpMethod::GET, $at, [], new HandlerReference(self::class, $at)), ['raw', 'data', 'bare', 'gone']);
                }
                public function raw(): \Psr\Http\Message\ResponseInterface { return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'Text/HTML; charset=UTF-8'], '<h1>by hand</h1>'); }
                public function data(): \Psr\Http\Message\ResponseInterface { return new \Nyholm\Psr7\Response(200, ['content-type' => 'application/json'], '{"posts":[]}'); }
                public function bare(): \Psr\Http\Message\ResponseInterface { return new \Nyholm\Psr7\Response(200, [], 'no type was named'); }
                public function gone(): \Psr\Http\Message\ResponseInterface { return new \Nyholm\Psr7\Response(404, ['Content-Type' => 'text/html'], '<h1>not here</h1>'); }
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

    public function testAMountedScreenIsAVisualSurfaceOfThatScreen(): void
    {
        $entry = (new HouseRouteObserver())->observeRoute($this->root, '/pages')['entry'];

        self::assertSame('text/html; charset=utf-8', $entry['contentType'] ?? null, (string) json_encode($entry));
        self::assertSame(['kind' => 'visual', 'screen' => 'pages'], $entry['surface'] ?? null);
        self::assertIsArray($entry['content'] ?? null, 'and the house read it, as before (decisions/0576)');
    }

    public function testAPageNoDeclarationServesIsAVisualSurfaceOfNoScreen(): void
    {
        $entry = (new HouseRouteObserver())->observeRoute($this->root, '/raw')['entry'];

        self::assertSame('Text/HTML; charset=UTF-8', $entry['contentType'] ?? null, 'as the response named it: ' . json_encode($entry));
        self::assertSame(['kind' => 'visual', 'screen' => null], $entry['surface'] ?? null, 'the house has no declaration to compare this page with');
        self::assertArrayNotHasKey('content', $entry);
    }

    public function testWhatIsNotAPageIsNotCalledASurface(): void
    {
        $observer = new HouseRouteObserver();

        $data = $observer->observeRoute($this->root, '/data')['entry'];
        self::assertSame('application/json', $data['contentType'] ?? null, 'however the response spelled the header');
        self::assertArrayNotHasKey('surface', $data);

        $bare = $observer->observeRoute($this->root, '/bare')['entry'];
        self::assertSame(200, $bare['status']);
        self::assertArrayHasKey('contentType', $bare);
        self::assertNull($bare['contentType'], 'the response named no type, and the house does not name one for it');
        self::assertArrayNotHasKey('surface', $bare);
    }

    public function testOnlyWhatWasServedIsDescribed(): void
    {
        $gone = (new HouseRouteObserver())->observeRoute($this->root, '/gone')['entry'];

        self::assertSame(404, $gone['status']);
        self::assertArrayNotHasKey('contentType', $gone, 'a 404 is not a served receipt: the house says nothing about what it carried');
        self::assertArrayNotHasKey('surface', $gone);
    }

    public function testAPromotionsReceiptsSayItToo(): void
    {
        $observed = (new HouseRouteObserver())->observe($this->root, ['config/screens.json', 'src/Plugins/Blog/Blog.php'])['observed'];
        $by = array_column($observed, null, 'subject');

        self::assertSame(['kind' => 'visual', 'screen' => 'pages'], $by['/pages']['surface'] ?? null, (string) json_encode($observed));
        self::assertSame(['kind' => 'visual', 'screen' => null], $by['/raw']['surface'] ?? null);
        self::assertSame('application/json', $by['/data']['contentType'] ?? null);
        self::assertArrayNotHasKey('surface', $by['/data']);
    }

    public function testTheScreenIsNamedHoweverTheRouteIsSpelledAndWhateverItsQuery(): void
    {
        $entry = (new HouseRouteObserver())->observeRoute($this->root, '/pages/?page=2')['entry'];

        self::assertSame(['kind' => 'visual', 'screen' => 'pages'], $entry['surface'] ?? null, (string) json_encode($entry));
    }
}
