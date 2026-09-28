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
 * The promotion observes the route it landed, in a fresh process of the house (greenhouse decisions/0494).
 *
 * A real house on disk — a kernel booted from its own `config/boot.php`, a front controller at
 * `public/index.php` — and a real promotion: the house is asked through processes of its own, and what
 * it answers is what a visitor is served.
 *
 * @guards the GET routes without parameters of the touched plugins are requested, and a 200 earns
 *         `served` in the house with the body's digest; the closure follows from the receipt
 *
 * @refuses a route with parameters, a POST, an untouched plugin, a server error, a request that died,
 *          and a house that does not boot
 *
 * @subject-in milpa/app-runtime
 */
final class HouseRouteObserverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-house-observe-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/Plugins/Blog', 0o777, true);
        mkdir($this->root . '/src/Plugins/Other', 0o777, true);
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
        file_put_contents($this->root . '/config/plugins.php', '<?php return [App\Plugins\Blog\Blog::class, App\Plugins\Other\Other::class];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => new \Milpa\Container\DIContainer(), "plugins" => require __DIR__ . "/plugins.php"];');
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
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
            $response = (new \Milpa\Runtime\Http\ExceptionMiddleware($psr17))->process($request, new \Milpa\Runtime\Http\RequestHandler($kernel, $psr17));
            (new \Milpa\Runtime\Http\ResponseEmitter())->emit($response);
            if (is_file($root . '/var/die-after')) {
                exit(4);
            }
            PHP);
        $this->plugin('Blog', [
            "new Route('/blog', HttpMethod::GET, 'blog', [], new HandlerReference(Controller::class, 'index'))",
            "new Route('/blog/{id}', HttpMethod::GET, 'blog_post', [], new HandlerReference(Controller::class, 'index'))",
            "new Route('/blog/new', HttpMethod::POST, 'blog_new', [], new HandlerReference(Controller::class, 'index'))",
        ]);
        $this->plugin('Other', ["new Route('/other', HttpMethod::GET, 'other', [], new HandlerReference(Controller::class, 'index'))"]);
    }

    protected function tearDown(): void
    {
        self::rmrf($this->root);
    }

    public function testTouchedPluginsAreReadFromTheLandedPaths(): void
    {
        self::assertSame(['Blog'], HouseRouteObserver::touchedPlugins(['src/Plugins/Blog/Blog.php', 'tests/Plugins/Blog/BlogTest.php']));
        self::assertSame(['*'], HouseRouteObserver::touchedPlugins(['src/Plugins/Blog/Blog.php', 'config/plugins.php']), 'registering a plugin changes which ones boot');
        self::assertSame([], HouseRouteObserver::touchedPlugins(['config/app.php', 'src/Plugins/README.md']));
    }

    public function testAPromotionObservesTheRouteItLandedAndTheHouseClosesOnIt(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog', AutonomyMode::Ask);
        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('blog')]);

        self::assertTrue($receipt['ok'] ?? false, (string) json_encode($receipt));
        self::assertSame([[
            'predicate' => 'served', 'route' => 'GET /blog', 'subject' => '/blog', 'status' => 200, 'environment' => ['kind' => 'house'],
            'servedAt' => '/blog', 'bytes' => \strlen('<h1>blog</h1>'), 'sha256' => hash('sha256', '<h1>blog</h1>'),
        ]], $receipt['observed'] ?? null, 'only the GET without parameters of the plugin it touched — not /blog/{id}, not the POST, not /other');
        self::assertStringContainsString('GET /blog answered HTTP 200', (string) $receipt['note']);

        $store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode($receipt), mutating: true);
        $session = $store->load('s');
        self::assertNotNull($session);
        $closure = ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));
        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('/blog', $closure['derivedFrom']['observation']['subject'] ?? null);
    }

    public function testTheHouseIsAskedAfterTheWriteAndNotBefore(): void
    {
        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('the new one')]);

        self::assertSame(hash('sha256', '<h1>the new one</h1>'), $receipt['observed'][0]['sha256'] ?? null, 'the observation sees the bytes the promotion wrote');
    }

    public function testARouteThatThrowsAnswers500AndIsNotServed(): void
    {
        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('boom', throws: true)]);

        self::assertSame([['route' => 'GET /blog', 'subject' => '/blog', 'status' => 500, 'environment' => ['kind' => 'house']]], $receipt['observed'] ?? null);
        self::assertStringContainsString('GET /blog answered HTTP 500', (string) $receipt['note']);
    }

    public function testARequestProcessThatDiesAnsweredNothing(): void
    {
        touch($this->root . '/var/die');

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('blog')]);

        self::assertSame([['route' => 'GET /blog', 'subject' => '/blog', 'status' => null, 'environment' => ['kind' => 'house'],
            'error' => 'the request process exited 3']], $receipt['observed'] ?? null);
    }

    public function testARequestThatAnsweredAndThenFailedIsNotServed(): void
    {
        touch($this->root . '/var/die-after');

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('blog')]);

        self::assertSame([['route' => 'GET /blog', 'subject' => '/blog', 'status' => null, 'environment' => ['kind' => 'house'],
            'error' => 'the request process exited 4']], $receipt['observed'] ?? null, 'a 200 from a process that did not finish cleanly is not what a browser can trust');
    }

    public function testAHouseThatDoesNotBootIsNamedInTheReceipt(): void
    {
        $receipt = $this->promote(['src/Plugins/Blog/Blog.php' => "<?php\nthrow new \\RuntimeException('broken');\n"]);

        self::assertTrue($receipt['ok'] ?? false, 'the promotion itself landed');
        self::assertArrayNotHasKey('observed', $receipt);
        self::assertStringStartsWith('the house did not boot to list its routes after the change', (string) ($receipt['observation_error'] ?? ''));
    }

    public function testAPromotionThatTouchesNoPluginAsksNothing(): void
    {
        $receipt = $this->promote(['config/app.php' => "<?php return ['debug' => false];\n"]);

        self::assertTrue($receipt['ok'] ?? false);
        self::assertArrayNotHasKey('observed', $receipt);
        self::assertArrayNotHasKey('observation_error', $receipt);
    }

    public function testAHouseWithoutAFrontControllerAsksNothing(): void
    {
        unlink($this->root . '/public/index.php');

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('blog')]);

        self::assertArrayNotHasKey('observed', $receipt);
        self::assertArrayNotHasKey('observation_error', $receipt);
    }

    /**
     * Land files through a real trial and a real promotion, and return the receipt.
     *
     * @param array<string, string> $files
     *
     * @return array<string, mixed>
     */
    private function promote(array $files): array
    {
        $ws = TrialWorkspace::materialize($this->root, 'w1', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        foreach ($files as $path => $contents) {
            file_put_contents($ws->copy . '/' . $path, $contents);
        }
        foreach ((new TrialOperations(new DIContainer(), null, $this->root))->operations() as $op) {
            if ($op->name === 'sandbox:promote') {
                return ($op->handler)(['workspace' => 'w1']);
            }
        }
        self::fail('no sandbox:promote');
    }

    /** @param list<string> $routes */
    private function plugin(string $name, array $routes): void
    {
        file_put_contents($this->root . "/src/Plugins/{$name}/{$name}.php", '<?php
declare(strict_types=1);
namespace App\Plugins\\' . $name . ';
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
#[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "' . $name . '", type: "Service")]
final class ' . $name . ' implements \Milpa\Interfaces\Plugin\PluginInterface, \Milpa\Runtime\Http\RouteProviderInterface
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
    public function boot(): void { $this->container->registerService(Controller::class, new Controller()); }
    public function install(): void {}
    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}
    public function routes(): array { return [' . implode(', ', $routes) . ']; }
}
');
        file_put_contents($this->root . "/src/Plugins/{$name}/Controller.php", str_replace('App\Plugins\Blog', 'App\Plugins\\' . $name, $this->controller($name)));
    }

    private function controller(string $says, bool $throws = false): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\Blog;
final class Controller
{
    public function index(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        ' . ($throws ? 'throw new \RuntimeException("boom");' : '') . '
        return new \Nyholm\Psr7\Response(200, ["Content-Type" => "text/html"], "<h1>' . $says . '</h1>");
    }
}
';
    }

    private static function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }
}
