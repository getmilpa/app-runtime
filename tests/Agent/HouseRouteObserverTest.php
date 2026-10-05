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
            // The house's own log, wired as the skeleton wires it — unless this house swapped it for one that writes elsewhere.
            $logger = is_file($root . '/var/logs-elsewhere') ? null : new \Milpa\Runtime\Observability\ErrorLogLogger();
            $response = (new \Milpa\Runtime\Http\ExceptionMiddleware($psr17, $logger))->process($request, new \Milpa\Runtime\Http\RequestHandler($kernel, $psr17));
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

    /**
     * The route was already broken before the promotion: since greenhouse decisions/0540 a promotion that takes a route
     * from 200 to 500 does not land (APromotionThatBreaksARouteDoesNotLandTest), so the 500 the observer records here is
     * one the promotion did not cause.
     */
    public function testARouteThatThrowsAnswers500AndIsNotServed(): void
    {
        touch($this->root . '/var/logs-elsewhere');
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', $this->controller('already', throws: true));

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('boom', throws: true)]);

        self::assertSame([['route' => 'GET /blog', 'subject' => '/blog', 'status' => 500, 'environment' => ['kind' => 'house']]], $receipt['observed'] ?? null);
        self::assertStringContainsString('GET /blog answered HTTP 500', (string) $receipt['note']);
        self::assertStringContainsString('the house logged no cause this observer could read', (string) $receipt['note'], 'a house whose logger writes elsewhere leaves no cause, and the receipt says so');
    }

    /** Greenhouse evidence/1071 B2: the house had the cause of /blog's 500 in its hand and threw it away (decisions/0539). */
    public function testARouteThatThrowsCarriesTheCauseTheHouseLoggedInTheReceipt(): void
    {
        $this->alreadyBroken();
        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('boom', throws: true)]);

        $cause = $receipt['observed'][0]['cause'] ?? null;
        self::assertIsArray($cause, (string) json_encode($receipt));
        self::assertSame('RuntimeException', $cause['class'] ?? null);
        self::assertSame('boom', $cause['message'] ?? null);
        self::assertSame('src/Plugins/Blog/Controller.php:8', $cause['at'] ?? null, 'relative to the house');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) ($cause['reference'] ?? ''));
        self::assertStringContainsString('GET /blog answered HTTP 500 — RuntimeException: boom (at src/Plugins/Blog/Controller.php:8)', (string) $receipt['note']);
        self::assertStringNotContainsString($this->root, (string) json_encode($receipt, \JSON_UNESCAPED_SLASHES), 'no absolute path of the host travels');
    }

    /** Decisions/0506 stands: what the receipt says, the visitor is never shown. */
    public function testThePublicAnswerStaysMuteAboutTheCause(): void
    {
        $this->alreadyBroken();
        $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('boom', throws: true)]);

        [$status, $body] = $this->visit('/blog');

        self::assertSame(500, $status);
        self::assertStringContainsString('Reference', $body);
        self::assertStringNotContainsString('boom', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('Controller.php', $body);
    }

    public function testTheCauseKeepsTheHousesSecretsAndTheHostsPathsOut(): void
    {
        $this->alreadyBroken();
        mkdir($this->root . '/.milpa');
        file_put_contents($this->root . '/.milpa/secrets.json', (string) json_encode(['agent' => ['apiKey' => 'sk-live-0123456789abcdef']]));
        $message = 'key sk-live-0123456789abcdef at https://ana:hunter2@db.example.com/x password=letmein token: abc.def '
            . 'Authorization: Bearer eyJhbGciOi.payload.sig in ' . $this->root . '/src/Plugins/Blog/Controller.php and /opt/elsewhere/lib/thing.php';

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $this->controller('boom', throws: true, message: $message)]);

        $said = (string) ($receipt['observed'][0]['cause']['message'] ?? '');
        self::assertSame('key [secret] at https://ana:[secret]@db.example.com/x password=[secret] token: [secret] '
            . 'Authorization: Bearer [secret] in src/Plugins/Blog/Controller.php and …/thing.php', $said);
        $whole = (string) json_encode($receipt, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        foreach (['sk-live-0123456789abcdef', 'hunter2', 'letmein', 'abc.def', 'eyJhbGciOi', '/opt/elsewhere', $this->root] as $leak) {
            self::assertStringNotContainsString($leak, $whole);
        }
    }

    /** A server whose php.ini sends `error_log()` to a file still hands the observer its line: the child overrides it. */
    public function testTheCauseReachesTheObserverWhateverLogTheServerConfigured(): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', $this->controller('boom', throws: true));
        $php = $this->root . '/var/php-with-a-log-file';
        file_put_contents($php, "#!/bin/sh\nexec " . escapeshellarg(\PHP_BINARY) . ' -d error_log=' . escapeshellarg($this->root . '/var/server.log') . ' "$@"' . "\n");
        chmod($php, 0o755);

        $seen = (new HouseRouteObserver($php))->observe($this->root, ['src/Plugins/Blog/Controller.php']);

        self::assertSame('boom', $seen['observed'][0]['cause']['message'] ?? null, (string) json_encode($seen));
        self::assertFileDoesNotExist($this->root . '/var/server.log', 'the observing process did not write the server\'s log');
    }

    public function testAFatalNobodyCaughtIsTheCauseOfARequestThatDied(): void
    {
        $this->alreadyBroken();
        file_put_contents($this->root . '/src/Plugins/Blog/Broken.php', "<?php\nnamespace App\\Plugins\\Blog;\nfinal class Broken implements \\Countable {}\n");

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => str_replace('return new', 'new Broken(); return new', $this->controller('blog'))]);

        $entry = $receipt['observed'][0] ?? [];
        self::assertArrayNotHasKey('predicate', $entry);
        self::assertStringStartsWith('the request process exited', (string) ($entry['error'] ?? ''));
        self::assertStringContainsString('App\\Plugins\\Blog\\Broken contains 1 abstract method', (string) ($entry['cause']['message'] ?? ''), (string) json_encode($entry));
        self::assertSame('src/Plugins/Blog/Broken.php:3', $entry['cause']['at'] ?? null);
        self::assertArrayNotHasKey('reference', $entry['cause'], 'nobody logged it under a reference');
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

    /** Since greenhouse decisions/0506 a promotion the house cannot boot with does not land, so nothing is observed. */
    public function testAHouseThatDoesNotBootIsNotObservedBecauseNothingLanded(): void
    {
        $receipt = $this->promote(['src/Plugins/Blog/Blog.php' => "<?php\nthrow new \\RuntimeException('broken');\n"]);

        self::assertFalse($receipt['ok'] ?? true, 'the promotion did not land');
        self::assertArrayNotHasKey('observed', $receipt);
        self::assertStringStartsWith('the house does not boot with this promotion: RuntimeException: broken', (string) ($receipt['error'] ?? ''));
        self::assertStringNotContainsString('broken', (string) file_get_contents($this->root . '/src/Plugins/Blog/Blog.php'), 'the pre-image is back');
    }

    public function testAPromotionThatTouchesNoPluginAsksNothing(): void
    {
        $receipt = $this->promote(['config/app.php' => "<?php return ['debug' => false];\n"]);

        self::assertTrue($receipt['ok'] ?? false);
        self::assertArrayNotHasKey('observed', $receipt);
        self::assertArrayNotHasKey('observation_error', $receipt);
    }

    /**
     * A MOUNTED SCREEN IS A ROUTE THAT LANDED (greenhouse decisions/0567 §3, slice BV-1). The declarations are not a
     * plugin's source, so a promotion that mounted a screen at `/pages` used to ask the house nothing — and a goal
     * that writes `GET /pages` closes only on the house having seen that route served (decisions/0494, 0555).
     */
    public function testAPromotionThatMountsAScreenObservesItsRoute(): void
    {
        $this->withTheLiveDoor();
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Serve GET /pages as a page', AutonomyMode::Ask);

        $receipt = $this->promote(['config/screens.json' => (string) json_encode([
            'pages' => ['type' => 'data-table', 'props' => ['columns' => [['key' => 't', 'label' => 'T']], 'rows' => [['t' => 'a mounted row']], 'name' => 'pages'], 'route' => '/pages'],
            'unmounted' => ['type' => 'data-table', 'props' => ['columns' => [], 'rows' => [], 'name' => 'unmounted']],
        ])]);

        self::assertTrue($receipt['ok'] ?? false, (string) json_encode($receipt));
        self::assertCount(1, $receipt['observed'] ?? [], 'the mounted route, and only it: a screen without a route has no route to ask');
        self::assertSame('GET /pages', $receipt['observed'][0]['route']);
        self::assertSame(200, $receipt['observed'][0]['status'], (string) json_encode($receipt));
        self::assertSame('served', $receipt['observed'][0]['predicate']);

        $store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode($receipt), mutating: true);
        $session = $store->load('s');
        self::assertNotNull($session);
        $closure = ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));
        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('/pages', $closure['derivedFrom']['observation']['subject'] ?? null);
    }

    public function testAPromotionOfPluginSourceAndAMountObservesBothOnce(): void
    {
        $this->withTheLiveDoor();

        $receipt = $this->promote([
            'src/Plugins/Blog/Controller.php' => $this->controller('blog'),
            'config/screens.json' => (string) json_encode(['pages' => ['type' => 'data-table', 'props' => ['columns' => [], 'rows' => [], 'name' => 'pages'], 'route' => '/pages']]),
        ]);

        self::assertSame(['GET /blog', 'GET /pages'], array_column($receipt['observed'] ?? [], 'route'));
    }

    public function testAMountThatDidNotLandIsNotAskedAgain(): void
    {
        $this->withTheLiveDoor();
        file_put_contents($this->root . '/config/screens.json', (string) json_encode(['pages' => ['type' => 'data-table', 'props' => ['columns' => [], 'rows' => [], 'name' => 'pages'], 'route' => '/pages']]));

        $receipt = $this->promote(['config/notes.php' => "<?php return [];\n"]);

        self::assertTrue($receipt['ok'] ?? false, (string) json_encode($receipt));
        self::assertArrayNotHasKey('observed', $receipt, 'the house asks what a promotion landed, not everything it serves');
    }

    public function testARouteThatLandedTwiceOverIsAskedOnce(): void
    {
        $this->withTheLiveDoor();

        // Written past the declaration's own collision check: a plugin and a mount name the same path.
        $receipt = $this->promote([
            'src/Plugins/Blog/Controller.php' => $this->controller('blog'),
            'config/screens.json' => (string) json_encode(['pages' => ['type' => 'data-table', 'props' => ['columns' => [], 'rows' => [], 'name' => 'pages'], 'route' => '/blog']]),
        ]);

        self::assertSame(['GET /blog'], array_column($receipt['observed'] ?? [], 'route'));
    }

    public function testDeclarationsTheHouseCannotReadMountNothingToObserve(): void
    {
        self::assertSame(['observed' => []], (new HouseRouteObserver())->observe($this->root, ['config/screens.json']), 'no declarations file: nothing is mounted');
        file_put_contents($this->root . '/config/screens.json', '{not json');
        self::assertSame(['observed' => []], (new HouseRouteObserver())->observe($this->root, ['config/screens.json']));
    }

    /** The fixture house gains the live door, the way the skeleton wires it: its plugin and a secret. */
    private function withTheLiveDoor(): void
    {
        file_put_contents($this->root . '/config/plugins.php', '<?php return [Milpa\AppRuntime\Web\LivePlugin::class, App\Plugins\Blog\Blog::class, App\Plugins\Other\Other::class];');
        // The store's path is named: this fixture borrows the package's own vendor/, so Composer would say the app
        // root is the package. A real house has its own vendor/ and needs no such line.
        file_put_contents($this->root . '/config/app.php', '<?php return ["live" => ["secret" => "' . str_repeat('k', 32) . '", "screens_path" => __DIR__ . "/screens.json"]];');
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

    /**
     * /blog already answers 500 before the promotion. Since greenhouse decisions/0540 a promotion that takes a serving route
     * to a 5xx does not land (APromotionThatBreaksARouteDoesNotLandTest); these cases are about what the observer reads
     * AFTER a promotion lands, so they start from a route the promotion cannot be blamed for.
     */
    private function alreadyBroken(): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', $this->controller('already', throws: true, message: 'already broken'));
    }

    private function controller(string $says, bool $throws = false, string $message = 'boom'): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\Blog;
final class Controller
{
    public function index(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        ' . ($throws ? 'throw new \RuntimeException(' . var_export($message, true) . ');' : '') . '
        return new \Nyholm\Psr7\Response(200, ["Content-Type" => "text/html"], "<h1>' . $says . '</h1>");
    }
}
';
    }

    /**
     * What a visitor is served at `$path` — the house's own front controller, in a process of its own.
     *
     * @return array{0: int, 1: string}
     */
    private function visit(string $path): array
    {
        $script = $this->root . '/var/visit.php';
        file_put_contents($script, '<?php $_SERVER = ["REQUEST_METHOD" => "GET", "REQUEST_URI" => ' . var_export($path, true) . ', "HTTP_ACCEPT" => "text/html"] + $_SERVER;'
            . ' ob_start(); register_shutdown_function(static function (): void { $b = (string) ob_get_clean(); fwrite(STDOUT, http_response_code() . "\\n" . $b); });'
            . ' chdir(' . var_export($this->root . '/public', true) . '); require "index.php";');
        $out = (string) shell_exec(escapeshellarg(\PHP_BINARY) . ' -d display_errors=0 -d error_log=' . escapeshellarg($this->root . '/var/php.log') . ' ' . escapeshellarg($script));
        [$status, $body] = explode("\n", $out, 2) + [1 => ''];

        return [(int) $status, $body];
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
