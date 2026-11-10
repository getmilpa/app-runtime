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

use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Agent\RouteRegression;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * A promotion that takes a route the house served to a 5xx does not land (greenhouse decisions/0540).
 *
 * Measured in Rod's first live run (greenhouse evidence/1071, B3, seq 284 → 291): the resident promoted a controller
 * whose constructor wanted the container; the house booted, so the boot witness of 0512 let it through, `GET /blog`
 * went from 200 to 500, and the receipt said `ok: true`. Here the same shape — a controller the container cannot
 * build — and its neighbours are promoted into a real house on disk with a real front controller.
 *
 * @guards the GET routes of the touched plugins are requested in the boot copy BEFORE the write; a route that answered
 *         non-5xx without the promotion and 5xx with it (or is new and born 5xx) refuses the promotion: nothing is
 *         written, the trial is kept, the answer names the route and both statuses
 *
 * @refuses a false refusal — a route already 5xx without the promotion, a route that fails only because the copy has no
 *          `var/`, a route that goes to 404; and the positive control, the published order (no copy): the 500 lands
 *
 * @subject-in milpa/app-runtime
 */
final class APromotionThatBreaksARouteDoesNotLandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-route-regression-' . bin2hex(random_bytes(4));
        foreach (['src/Plugins/Blog', 'vendor', 'config', 'public', 'var'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o777, true);
        }
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
        file_put_contents($this->root . '/config/plugins.php', self::pluginsFile('Blog'));
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => new \Milpa\Container\DIContainer(), "plugins" => require __DIR__ . "/plugins.php"];');
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/public/index.php', <<<'PHP'
            <?php
            require __DIR__ . '/../vendor/autoload.php';
            $root = dirname(__DIR__);
            $boot = require $root . '/config/boot.php';
            $kernel = \Milpa\Runtime\Kernel::boot(['root' => $root, 'plugins' => $boot['plugins'], 'config' => require $root . '/config/app.php', 'container' => $boot['container']]);
            $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
            $request = new \Nyholm\Psr7\ServerRequest($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], [], null, '1.1', $_SERVER);
            $response = (new \Milpa\Runtime\Http\ExceptionMiddleware($psr17, new \Milpa\Runtime\Observability\ErrorLogLogger()))->process($request, new \Milpa\Runtime\Http\RequestHandler($kernel, $psr17));
            (new \Milpa\Runtime\Http\ResponseEmitter())->emit($response);
            PHP);
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', self::plugin('Blog', ['/blog']));
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', self::controller('Blog', 'return new \Nyholm\Psr7\Response(200, [], "the stub");'));
    }

    protected function tearDown(): void
    {
        self::rmrf($this->root);
    }

    /** THE SHAPE OF SEQ 291: the controller now wants a constructor argument the container cannot give it. */
    public function testAControllerThatTakesAServedRouteTo500IsRefusedAndNothingIsWritten(): void
    {
        $inode = fileinode($this->root . '/src/Plugins/Blog/Controller.php');

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => self::controllerThatWantsTheContainer()]);

        self::assertFalse($receipt['ok'], (string) json_encode($receipt));
        self::assertStringStartsWith('the promotion breaks GET /blog: it answered HTTP 200 without this promotion and HTTP 500 with it', (string) $receipt['error']);
        self::assertSame(['route' => 'GET /blog', 'before' => 200, 'after' => 500], array_diff_key($receipt['regressed'][0], ['cause' => 0]));
        // THE CAUSE, AS S2 READS IT (decisions/0539): the line the copy's house logged — its paths the house's, never the copy's.
        self::assertStringContainsString('with it — Milpa\Exceptions\ContainerResolutionException: Cannot resolve parameter $container for class App\Plugins\Blog\Controller', (string) $receipt['error']);
        self::assertStringNotContainsString('boot-candidates', (string) json_encode($receipt, \JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString($this->root, (string) json_encode($receipt, \JSON_UNESCAPED_SLASHES));
        self::assertSame(['src/Plugins/Blog/Controller.php'], $receipt['unwritten']);
        self::assertTrue($receipt['house_boots'], 'the house boots with it: this is not a boot refusal');
        self::assertArrayNotHasKey('rolled_back', $receipt);
        self::assertSame($inode, fileinode($this->root . '/src/Plugins/Blog/Controller.php'), 'the live file was never replaced');
        self::assertStringContainsString('the stub', (string) file_get_contents($this->root . '/src/Plugins/Blog/Controller.php'));
        self::assertDirectoryDoesNotExist($this->root . '/var/trials/w1/pre', 'no pre-image: the house was not about to change');
        self::assertDirectoryExists($this->root . '/var/trials/w1/copy', 'the trial is kept to be fixed');
        self::assertFileDoesNotExist($this->root . '/var/trials/w1/promoted.json');
        self::assertSame([], glob($this->root . '/var/boot-candidates/*') ?: [], 'both copies are gone');
        self::assertStringContainsString('Nothing was written', (string) $receipt['note']);
    }

    /** POSITIVE CONTROL — the published order (no copy before the write): the same promotion lands, `ok: true`, and /blog answers 500. */
    public function testWithoutTheCopyTheSamePromotionLandsAndTheRouteAnswers500(): void
    {
        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => self::controllerThatWantsTheContainer()], probeBeforeWriting: false);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertSame(500, $receipt['observed'][0]['status'] ?? null);
    }

    /** A new route that is born 5xx: without the promotion it did not exist, with it the house serves an error. */
    public function testANewRouteBornIn500IsRefused(): void
    {
        $receipt = $this->promote([
            'src/Plugins/Fresh/Fresh.php' => self::plugin('Fresh', ['/fresh']),
            'src/Plugins/Fresh/Controller.php' => self::controller('Fresh', 'throw new \RuntimeException("born broken");'),
            'config/plugins.php' => self::pluginsFile('Blog', 'Fresh'),
        ]);

        self::assertFalse($receipt['ok'], (string) json_encode($receipt));
        self::assertStringContainsString('GET /fresh is new with this promotion and answers HTTP 500', (string) $receipt['error']);
        self::assertSame([['route' => 'GET /fresh', 'before' => null, 'after' => 500]], self::withoutCause($receipt['regressed']));
        self::assertSame('born broken', $receipt['regressed'][0]['cause']['message'] ?? null);
        self::assertSame(self::pluginsFile('Blog'), file_get_contents($this->root . '/config/plugins.php'));
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Fresh/Fresh.php');
    }

    public function testAGoodPromotionStillLandsAndIsObserved(): void
    {
        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => self::controller('Blog', 'return new \Nyholm\Psr7\Response(200, [], "the posts");')]);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertSame('served', $receipt['observed'][0]['predicate'] ?? null);
        self::assertArrayNotHasKey('unjudged', $receipt);
        self::assertStringContainsString('the posts', (string) file_get_contents($this->root . '/src/Plugins/Blog/Controller.php'));
    }

    /** NEGATIVE — a route already broken before the promotion was not broken BY it: it lands, and the receipt says it could not judge that route. */
    public function testARouteAlready500WithoutThePromotionIsNotItsFault(): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', self::controller('Blog', 'throw new \RuntimeException("already broken");'));

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => self::controller('Blog', 'throw new \RuntimeException("still broken");')]);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertSame([['route' => 'GET /blog', 'before' => 500, 'after' => 500]], self::withoutCause(self::withoutWhy($receipt['unjudged'])));
        self::assertSame(self::maskedWhy(), $receipt['unjudged'][0]['why'] ?? null, 'under the mask the receipt says the route was not judged, not that it is not the promotion\'s doing');
        self::assertSame('still broken', $receipt['unjudged'][0]['cause']['message'] ?? null, 'the cause is the promotion\'s copy, not the house\'s');
        self::assertStringContainsString('still broken', (string) file_get_contents($this->root . '/src/Plugins/Blog/Controller.php'));
    }

    /**
     * NEGATIVE — the copy has no `var/`. A route that needs the house's state answers 500 in BOTH copies and 200 in the
     * house: the promotion is not the cause, so it is not refused (the «before» is a copy, not the live house).
     */
    public function testARouteThatFailsOnlyInTheCopyIsNotAFalseRefusal(): void
    {
        file_put_contents($this->root . '/var/posts.json', '[]');
        $needsState = 'if (!is_file(dirname(__DIR__, 3) . "/var/posts.json")) { throw new \RuntimeException("no posts file"); } return new \Nyholm\Psr7\Response(200, [], "%s");';
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', self::controller('Blog', sprintf($needsState, 'v1')));

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => self::controller('Blog', sprintf($needsState, 'v2'))]);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertSame([['route' => 'GET /blog', 'before' => 500, 'after' => 500]], self::withoutCause(self::withoutWhy($receipt['unjudged'])));
        self::assertSame(self::maskedWhy(), $receipt['unjudged'][0]['why'] ?? null, 'under the mask the receipt says the route was not judged, not that it is not the promotion\'s doing');
        self::assertSame('served', $receipt['observed'][0]['predicate'] ?? null, 'in the house it serves');
    }

    /** NEGATIVE — only a 5xx breaks a route: a route the promotion removes (now 404) is not a regression. */
    public function testARouteThatGoesTo404IsNotBroken(): void
    {
        $receipt = $this->promote(['src/Plugins/Blog/Blog.php' => self::plugin('Blog', ['/posts'])]);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
    }

    /**
     * @param array<string, string> $files
     *
     * @return array<string, mixed>
     */
    private function promote(array $files, bool $probeBeforeWriting = true, ?RouteRegression $routes = null): array
    {
        $ws = TrialWorkspace::materialize($this->root, 'w1', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        foreach ($files as $path => $contents) {
            @mkdir(\dirname($ws->copy . '/' . $path), 0o777, true);
            file_put_contents($ws->copy . '/' . $path, $contents);
        }
        foreach ((new TrialOperations(new DIContainer(), null, $this->root, bootProbe: new BootProbe(), probeBeforeWriting: $probeBeforeWriting, routes: $routes ?? new RouteRegression()))->operations() as $op) {
            if ($op->name === 'sandbox:promote') {
                return ($op->handler)(['workspace' => 'w1']);
            }
        }
        self::fail('no sandbox:promote');
    }

    /**
     * THE CANDIDATE IS ASKED CONFINED (greenhouse decisions/0626). It carries code nobody applied yet, so the routes the
     * promotion is judged on are requested the way a trial runs: the envelope of the house reads empty. The control
     * — the house without the promotion — is asked the same way, so the two are compared under one mask.
     */
    public function testThePromotionAsksItsCandidateWithTheEnvelopeMasked(): void
    {
        if (!(new \Milpa\AppRuntime\Agent\TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $canary = 'canary-route-judge-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.milpa');
        // The canary sits where the cause logger's redaction does not reach (it hides an `apiKey`, so a canary there
        // would not show even when it was read — the first form of this test was blind to its own defect).
        file_put_contents($this->root . '/.milpa/secrets.json', '{"note":"' . $canary . '"}');

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => self::controller('Blog',
            'throw new \\RuntimeException("envelope: " . (string) @file_get_contents(dirname(__DIR__, 3) . "/.milpa/secrets.json"));')]);

        self::assertFalse($receipt['ok'], 'control: a route taken to 500 is refused: ' . json_encode($receipt));
        self::assertSame(['route' => 'GET /blog', 'before' => 200, 'after' => 500], array_diff_key($receipt['regressed'][0], ['cause' => 0]), 'the control answered 200 under the same mask');
        self::assertStringContainsString('envelope: ', (string) $receipt['error'], 'control: the cause the copy logged is read');
        self::assertStringNotContainsString($canary, (string) json_encode($receipt), 'the candidate read the envelope of the house: it was not asked confined');
        // The logger redacts what it recognises as a secret, so the canary alone cannot tell a read from no read; the
        // message can: unconfined it carries the envelope's shape (`{"note":"[secret]"}`), confined nothing.
        self::assertSame('envelope:', $receipt['regressed'][0]['cause']['message'] ?? null, 'the envelope reads empty under the mask: ' . json_encode($receipt['regressed'][0]['cause'] ?? null));
        self::assertStringStartsWith('a trial: confined', (string) ($receipt['route_room'] ?? ''), 'the refusal says where the routes were asked');
    }

    /**
     * ONE MASK FOR BOTH, AND ITS BLIND SPOT SAID (greenhouse decisions/0626, review round 1, L-3, mutant 2b; review
     * round 2, M-B, Rod's option A). A route that needs the envelope answers 5xx under the mask, with the promotion and
     * without it: asked the same way, the two agree and the promotion is not blamed — were the control asked plain, it
     * would answer 200 and the promotion would be refused for the mask. The price is that such a route is not judged:
     * the promotion lands, and the receipt says why that route was not judged, instead of calling it «not its doing».
     */
    public function testTheControlIsAskedUnderTheSameMaskAsTheCandidate(): void
    {
        if (!(new \Milpa\AppRuntime\Agent\TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        mkdir($this->root . '/.milpa');
        file_put_contents($this->root . '/.milpa/secrets.json', '{"note":"the envelope"}');
        $needsTheEnvelope = static fn (string $said): string => self::controller('Blog', 'if (!str_contains((string) @file_get_contents(dirname(__DIR__, 3) . "/.milpa/secrets.json"), "the envelope")) { throw new \\RuntimeException("no envelope"); } return new \\Nyholm\\Psr7\\Response(200, [], "' . $said . '");');
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', $needsTheEnvelope('before'));

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => $needsTheEnvelope('after')]);

        self::assertTrue($receipt['ok'], 'the control was not asked under the candidate\'s mask: ' . json_encode($receipt));
        self::assertSame([['route' => 'GET /blog', 'before' => 500, 'after' => 500, 'why' => RouteRegression::MASKED]], self::withoutCause($receipt['unjudged'] ?? []), (string) json_encode($receipt));
    }

    /** Without confinement the routes are asked plain, so a 5xx on both sides is the house's own — no mask to blame. */
    public function testWithoutConfinementAnUnjudgedRouteBlamesNoMask(): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', self::controller('Blog', 'throw new \\RuntimeException("broken before");'));
        $unconfined = new RouteRegression(new HouseRouteObserver(bwrap: '/nonexistent/bwrap-' . bin2hex(random_bytes(4))));

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => self::controller('Blog', 'throw new \\RuntimeException("broken after");')], routes: $unconfined);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertSame([['route' => 'GET /blog', 'before' => 500, 'after' => 500]], self::withoutCause($receipt['unjudged'] ?? []), 'a route already broken is not the promotion\'s doing, and no mask hid anything: ' . json_encode($receipt));
    }

    /**
     * WHERE THE HOST CANNOT CONFINE, THE RECEIPT SAYS SO (greenhouse decisions/0626, Rod's answer (a)). With no bwrap the
     * routes are still judged — a promotion is not refused for the host it runs on (decisions/0607) — but unconfined,
     * and the receipt of the landing carries that sentence, the way a writer's answer carries the boot check's room.
     */
    public function testWithoutConfinementThePromotionSaysItsRoutesWereAskedUnconfined(): void
    {
        $unconfined = new RouteRegression(new HouseRouteObserver(bwrap: '/nonexistent/bwrap-' . bin2hex(random_bytes(4))));

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => self::controller('Blog', 'return new \\Nyholm\\Psr7\\Response(200, [], "changed");')], routes: $unconfined);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertStringStartsWith('UNCONFINED', (string) ($receipt['route_room'] ?? ''), 'the landing says its routes were asked unconfined: ' . json_encode($receipt));
    }

    /**
     * Control of the one above: where the host confines, the same landing says its routes were asked as a trial.
     */
    public function testWithConfinementThePromotionSaysItsRoutesWereAskedAsATrial(): void
    {
        if (!(new \Milpa\AppRuntime\Agent\TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }

        $receipt = $this->promote(['src/Plugins/Blog/Controller.php' => self::controller('Blog', 'return new \\Nyholm\\Psr7\\Response(200, [], "changed");')]);

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertStringStartsWith('a trial: confined', (string) ($receipt['route_room'] ?? ''), (string) json_encode($receipt));
    }

    /**
     * The rows without the cause S2 reads (decisions/0539) — its message is asserted where it teaches something.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutCause(array $rows): array
    {
        return array_map(static fn (array $row): array => array_diff_key($row, ['cause' => 0]), $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutWhy(array $rows): array
    {
        return array_map(static fn (array $row): array => array_diff_key($row, ['why' => 0]), $rows);
    }

    /** Where this host confines, an unjudged route says the mask did not judge it (greenhouse decisions/0626, M-B, option A). */
    private static function maskedWhy(): ?string
    {
        return (new \Milpa\AppRuntime\Agent\TrialRunner())->available() ? RouteRegression::MASKED : null;
    }

    private static function pluginsFile(string ...$plugins): string
    {
        return '<?php return [' . implode(', ', array_map(static fn (string $p): string => "App\\Plugins\\{$p}\\{$p}::class", $plugins)) . "];\n";
    }

    /**
     * A plugin whose GET routes are served by its Controller, resolved through the container (as ContainerHandlerResolver does).
     *
     * @param list<string> $paths
     */
    private static function plugin(string $name, array $paths): string
    {
        $routes = implode(', ', array_map(static fn (string $p): string => "new Route('{$p}', HttpMethod::GET, '" . trim($p, '/') . "', [], new HandlerReference(Controller::class, 'index'))", $paths));

        return '<?php
declare(strict_types=1);
namespace App\Plugins\\' . $name . ';
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
#[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "' . $name . '", type: "Service")]
final class ' . $name . ' implements \Milpa\Interfaces\Plugin\PluginInterface, \Milpa\Runtime\Http\RouteProviderInterface
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
    public function boot(): void {}
    public function install(): void {}
    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}
    public function routes(): array { return [' . $routes . ']; }
}
';
    }

    private static function controller(string $plugin, string $body): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\\' . $plugin . ';
final class Controller
{
    public function index(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        ' . $body . '
    }
}
';
    }

    /** evidence/1071's BlogController, reduced: a constructor that wants the container, which nobody registered. */
    private static function controllerThatWantsTheContainer(): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\Blog;
final class Controller
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
    public function index(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new \Nyholm\Psr7\Response(200, [], "the posts");
    }
}
';
    }

    private static function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }
}
