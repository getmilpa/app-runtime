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

use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Agent\RouteRegression;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Support\BootCandidate;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * CODE NOBODY APPLIED RUNS CONFINED AT EVERY DOOR (greenhouse decisions/0626, review round 1: H-1, M-1, M-2, L-1, L-3).
 *
 * The trial's plugin, in its `boot()`, appends one line to a log OUTSIDE the tree it boots from: the house-observe mode
 * that booted it, what it read of the house's envelope, and what it read of the launcher's environment. A confined
 * process cannot write there — only its own tree is writable — so a line in the log is the proof of a plain process,
 * and of what it read. The instrument has its positive control: the same candidate asked plain does write the line.
 * Canaries are inert random strings.
 */
final class CodeNobodyAppliedRunsConfinedAtEveryDoorTest extends TestCase
{
    private const ENV = 'MILPA_TEST_ENV_CANARY';

    private string $root;

    private string $canary;

    private string $envCanary;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-every-door-' . bin2hex(random_bytes(4));
        $this->canary = 'canary-envelope-' . bin2hex(random_bytes(6));
        $this->envCanary = 'canary-environment-' . bin2hex(random_bytes(6));
        foreach (['src/Plugins/Blog', 'vendor', 'config', 'public', 'var', '.milpa'] as $dir) {
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
        file_put_contents($this->root . '/config/plugins.php', '<?php return [App\Plugins\Blog\Blog::class];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => new \Milpa\Container\DIContainer(), "plugins" => require __DIR__ . "/plugins.php"];');
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/.milpa/secrets.json', '{"note":"' . $this->canary . '"}');
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
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', $this->plugin(false));
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', self::controller());
        putenv(self::ENV . '=' . $this->envCanary);
    }

    protected function tearDown(): void
    {
        putenv(self::ENV);
        putenv(TrialRunner::INSIDE_TRIAL_ENV);
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
    }

    /** The positive control of the instrument: the trial's plugin asked PLAIN writes its line, with both canaries. */
    public function testTheInstrumentSeesAPlainBootOfTheTrialsCode(): void
    {
        $candidate = BootCandidate::of($this->root, ['src/Plugins/Blog/Blog.php' => $this->plugin(true)]);
        try {
            $seen = (new HouseRouteObserver())->observeRoute($candidate->path, '/blog', 100);
        } finally {
            $candidate->remove();
        }

        self::assertSame(200, $seen['entry']['status'] ?? null, (string) json_encode($seen));
        $plain = $this->plainBoots();
        self::assertNotSame([], $plain, 'a plain boot of the trial\'s code writes its line');
        self::assertStringContainsString($this->canary, $plain[0]['read'] ?? '');
        self::assertStringContainsString($this->envCanary, $plain[0]['env'] ?? '');
    }

    /** H-1: a GET of a trial on a page the trial itself mounts is judged on its content — and that boot is confined too. */
    public function testObservingAMountedPageOfATrialBootsNothingPlain(): void
    {
        $this->requireConfinement();
        $ws = $this->trialThatLogs('w1');
        $this->mount($ws->copy);

        $seen = $this->operation('route:observe')(['path' => '/blog', 'workspace' => 'w1']);

        self::assertTrue($seen['ok'] ?? false, (string) json_encode($seen));
        self::assertSame(200, $seen['observed'][0]['status'] ?? null, (string) json_encode($seen));
        self::assertSame([], $this->plainBoots(), 'a process booted the trial\'s code plain');
    }

    /** H-1, the path that regressed in round 1: a write and its follow-up GET on a mounted page. */
    public function testAWriteAndItsFollowUpOnAMountedPageBootNothingPlain(): void
    {
        $this->requireConfinement();
        $ws = $this->trialThatLogs('w2');
        $this->mount($ws->copy);

        $seen = $this->operation('route:observe')(['path' => '/blog', 'workspace' => 'w2', 'method' => 'POST', 'body' => 'x', 'then' => ['/blog']]);

        self::assertTrue($seen['ok'] ?? false, (string) json_encode($seen));
        self::assertSame([], $this->plainBoots(), 'a process booted the trial\'s code plain');
    }

    /**
     * H-1 and M-2: a promotion judged on its routes boots its candidate to LIST them, to request them and, on a mounted
     * page, to read the page's content — all confined. Refused, so nothing lands and any line is from unapplied code.
     */
    public function testAPromotionJudgedOnAMountedPageBootsNothingPlain(): void
    {
        $this->requireConfinement();
        $ws = $this->trialThatLogs('w3');
        $this->mount($ws->copy);
        file_put_contents($ws->copy . '/src/Plugins/Blog/Controller.php', str_replace('return new \Nyholm\Psr7\Response(200, [], "env");', 'throw new \RuntimeException("broken by the trial");', self::controller()));

        $receipt = $this->promote('w3');

        self::assertFalse($receipt['ok'], 'control: the broken route refuses the promotion: ' . json_encode($receipt));
        self::assertSame([], $this->plainBoots(), 'a process booted the candidate plain');
        self::assertStringStartsWith('a trial: confined', (string) ($receipt['route_room'] ?? ''));
    }

    /** M-2 without a page: the listing of the candidate's routes is the first process it boots, and it is confined. */
    public function testAPromotionWithoutAMountedPageBootsNothingPlainEither(): void
    {
        $this->requireConfinement();
        $ws = $this->trialThatLogs('w4');
        file_put_contents($ws->copy . '/src/Plugins/Blog/Controller.php', str_replace('return new \Nyholm\Psr7\Response(200, [], "env");', 'throw new \RuntimeException("broken by the trial");', self::controller()));

        $receipt = $this->promote('w4');

        self::assertFalse($receipt['ok'], (string) json_encode($receipt));
        self::assertSame([], $this->plainBoots(), 'a process booted the candidate plain');
    }

    /** M-1: a confined request reads nothing of the launcher's environment. Control: the applied house, asked plain, does. */
    public function testAConfinedRequestReadsNothingOfTheLaunchersEnvironment(): void
    {
        $this->requireConfinement();
        $this->trialThatLogs('w5');

        $trial = $this->operation('route:observe')(['path' => '/whoami', 'workspace' => 'w5']);
        $house = $this->operation('route:observe')(['path' => '/whoami']);

        self::assertSame(200, $trial['observed'][0]['status'] ?? null, (string) json_encode($trial));
        self::assertStringContainsString($this->envCanary, (string) ($house['excerpt'] ?? ''), 'control: the applied house runs with its launcher\'s environment');
        self::assertStringNotContainsString($this->envCanary, (string) ($trial['excerpt'] ?? ''), 'the trial\'s request read the launcher\'s environment');
        self::assertStringContainsString('trial=1', (string) ($trial['excerpt'] ?? ''), 'it ran as a trial runs');
    }

    /** L-1: a change no route answers for started no process, and its receipt says no room. */
    public function testAChangeNoRouteAnswersForSaysNoRoom(): void
    {
        $ws = TrialWorkspace::materialize($this->root, 'w6', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($ws->copy . '/config/app.php', '<?php return ["changed" => true];');

        $receipt = $this->promote('w6');

        self::assertTrue($receipt['ok'], (string) json_encode($receipt));
        self::assertArrayNotHasKey('route_room', $receipt, 'no route was asked, so there is no room to say');
    }

    /** L-3 (mutant 7): when the boot refused the promotion the routes were never judged, and the receipt says no room. */
    public function testAPromotionTheHouseCannotBootWithSaysNoRoom(): void
    {
        $ws = TrialWorkspace::materialize($this->root, 'w7', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($ws->copy . '/src/Plugins/Blog/Blog.php', '<?php namespace App\Plugins\Blog; final class Blog implements \Milpa\Interfaces\Plugin\PluginInterface {}');

        $receipt = $this->promote('w7');

        self::assertFalse($receipt['ok'], (string) json_encode($receipt));
        self::assertStringStartsWith('the house does not boot with this promotion', (string) ($receipt['error'] ?? ''));
        self::assertArrayNotHasKey('route_room', $receipt, 'the routes were never judged');
    }

    /** Told it is a trial outside one, a promotion writes nothing and says why — not that the house does not boot (0631). */
    public function testToldItIsATrialAPromotionSaysWhyNotThatTheHouseDoesNotBoot(): void
    {
        $this->trialThatLogs('w9');
        putenv(TrialRunner::INSIDE_TRIAL_ENV . '=1');

        $refused = $this->promote('w9');

        self::assertFalse($refused['ok'] ?? true, (string) json_encode($refused));
        self::assertSame(TrialRunner::FALSE_CLAIM . '. Nothing was written; the house is as it was.', $refused['error'] ?? null);
        self::assertSame(BootProbe::FALSE_ROOM, $refused['boot_room'] ?? null);
        self::assertArrayNotHasKey('house_boots', $refused, 'it does not say the house does not boot: nothing was booted');
        self::assertSame([], $this->plainBoots());
    }

    /** L-2: inside a trial no confinement nests, and the room says the enclosing trial confines — not «no bwrap». */
    public function testInsideATrialTheRoomNamesTheEnclosingTrial(): void
    {
        self::assertStringStartsWith('UNCONFINED', RouteRegression::room(false), 'control: outside a trial, unconfined is said as such');

        // Told it is a trial, outside one: the room is not named a trial (greenhouse decisions/0631).
        putenv(TrialRunner::INSIDE_TRIAL_ENV . '=1');
        self::assertStringStartsWith('UNCONFINED', RouteRegression::room(false));
        putenv(TrialRunner::INSIDE_TRIAL_ENV);

        $script = $this->root . '/var/room.php';
        file_put_contents($script, '<?php require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
echo Milpa\AppRuntime\Agent\RouteRegression::room(false), "\n", Milpa\AppRuntime\Agent\RouteRegression::room(true), "\n";');
        $inside = \Milpa\AppRuntime\Tests\Fixtures\InsideATrial::run($this->root, $script);
        if ($inside === null) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        [$unconfined, $confined] = explode("\n", $inside['stdout']) + ['', ''];
        self::assertStringStartsWith('within the enclosing trial', $unconfined, $inside['stderr']);
        self::assertStringStartsWith('a trial: confined', $confined);
    }

    /**
     * M-A (review round 2): the listing of a candidate is masked on the HOUSE, not on the copy. The candidate's plugin
     * names, in the route it lists, what it read — the house's envelope by its own path, the copy's link to it, and the
     * launcher's environment. Control: listed plain, it reads all three.
     */
    public function testTheListingOfACandidateMasksTheHousesEnvelope(): void
    {
        $this->requireConfinement();
        $candidate = BootCandidate::of($this->root, ['src/Plugins/Blog/Blog.php' => $this->pluginThatSaysWhatItReads()]);
        try {
            $plain = (new HouseRouteObserver())->observe($candidate->path, ['src/Plugins/Blog/Blog.php']);
            $confined = (new HouseRouteObserver())->observe($candidate->path, ['src/Plugins/Blog/Blog.php'], true, $this->root);
        } finally {
            $candidate->remove();
        }

        self::assertContains('/read-abs-READ-copy-READ-env-READ', self::subjects($plain), 'control: listed plain, the plugin reads what it names: ' . json_encode(self::subjects($plain)));
        self::assertContains('/read-abs-masked-copy-masked-env-cleared', self::subjects($confined), (string) json_encode(self::subjects($confined)));
    }

    /** M-A (review round 2): the content judge of a candidate's page is masked on the HOUSE too, and runs as a trial. */
    public function testTheContentJudgeOfACandidateMasksTheHousesEnvelope(): void
    {
        $this->requireConfinement();
        $candidate = BootCandidate::of($this->root, [
            'src/Plugins/Blog/Blog.php' => $this->pluginThatSaysWhatItReads(),
            'config/screens.json' => (string) json_encode(['posts' => ['type' => 'data-table', 'props' => ['columns' => [], 'rows' => []], 'route' => '/blog']]),
        ]);
        try {
            $plain = (new HouseRouteObserver())->observeRoute($candidate->path, '/blog', 100);
            $confined = (new HouseRouteObserver())->observeRoute($candidate->path, '/blog', 100, 'GET', null, null, true, $this->root);
        } finally {
            $candidate->remove();
        }

        self::assertStringContainsString($this->canary, (string) ($plain['entry']['content']['abs'] ?? ''), 'control: judged plain, the page\'s process reads the envelope: ' . json_encode($plain));
        self::assertSame(['abs' => '', 'copy' => '', 'env' => '', 'trial' => '1'], $confined['entry']['content'] ?? null, (string) json_encode($confined));
    }

    /** L-A (review round 2): a confinement that cannot be built — no namespace, or no house to mask — runs nothing. */
    public function testAConfinementThatCannotBeBuiltRunsNothing(): void
    {
        $confineWith = new \ReflectionMethod(HouseRouteObserver::class, 'confineWith');

        self::assertSame([], $confineWith->invoke(new HouseRouteObserver(), null, null), 'control: an unconfined request has no prefix');
        self::assertSame(['false'], $confineWith->invoke(new HouseRouteObserver(bwrap: '/nonexistent/bwrap-' . bin2hex(random_bytes(4))), $this->root . '/var', $this->root));
        self::assertSame(['false'], $confineWith->invoke(new HouseRouteObserver(), $this->root . '/var', null), 'a confinement that does not name whose secrets to mask');
    }

    /** L-B (review round 2): a listing that started and failed still says a process of the house was asked. */
    public function testAListingThatFailsStillSaysItAsked(): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', str_replace('public function routes(): array
    {', 'public function routes(): array
    {
        throw new \RuntimeException("the listing breaks");', $this->plugin(false)));

        $seen = (new HouseRouteObserver())->observe($this->root, ['src/Plugins/Blog/Blog.php']);

        self::assertArrayHasKey('error', $seen, (string) json_encode($seen));
        self::assertTrue($seen['asked'] ?? false, (string) json_encode($seen));
    }

    private function requireConfinement(): void
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
    }

    private function trialThatLogs(string $id): TrialWorkspace
    {
        $ws = TrialWorkspace::materialize($this->root, $id, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($ws->copy . '/src/Plugins/Blog/Blog.php', $this->plugin(true));

        return $ws;
    }

    /** A screen mounted at `/blog`, declared by the tree at `$tree` — the trial's own copy, so the house has none. */
    private function mount(string $tree): void
    {
        file_put_contents($tree . '/config/screens.json', (string) json_encode(['posts' => ['type' => 'data-table', 'props' => ['columns' => [], 'rows' => []], 'route' => '/blog']]));
    }

    /** @return array<string, mixed> */
    private function promote(string $id): array
    {
        return $this->operation('sandbox:promote')(['workspace' => $id]);
    }

    private function operation(string $name): callable
    {
        foreach ((new TrialOperations(new DIContainer(), null, $this->root, bootProbe: new BootProbe()))->operations() as $op) {
            if ($op->name === $name) {
                return $op->handler;
            }
        }
        self::fail('no ' . $name);
    }

    /** @return list<array<string, string>> the lines plain boots of the trial's code wrote */
    private function plainBoots(): array
    {
        $file = $this->root . '/plain-boots.log';
        if (!is_file($file)) {
            return [];
        }

        return array_values(array_map(static fn (string $line): array => (array) json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($file)))));
    }

    /**
     * The plugin, saying in band what it read: listing, it adds a route named after it; judging a page, it answers the
     * content judge itself, on the observer's own channel, at the end of the process.
     */
    private function pluginThatSaysWhatItReads(): string
    {
        $read = '$h = dirname(__DIR__, 3); $abs = (string) @file_get_contents(' . var_export($this->root . '/.milpa/secrets.json', true) . '); $copy = (string) @file_get_contents($h . "/.milpa/secrets.json"); $env = (string) getenv("' . self::ENV . '");';
        $content = 'if (($GLOBALS["argv"][1] ?? "") === "content") { ' . $read . ' register_shutdown_function(static function () use ($abs, $copy, $env): void { echo "\n" . ' . var_export(HouseRouteObserver::MARK, true) . ' . json_encode(["ok" => true, "content" => ["abs" => $abs, "copy" => $copy, "env" => $env, "trial" => (string) getenv("MILPA_TRIAL")]]) . "\n"; }); }';
        $listed = 'if (($GLOBALS["argv"][1] ?? "") === "routes") { ' . $read . ' $said = "/read-abs-" . (str_contains($abs, ' . var_export($this->canary, true) . ') ? "READ" : "masked") . "-copy-" . (str_contains($copy, ' . var_export($this->canary, true) . ') ? "READ" : "masked") . "-env-" . ($env !== "" ? "READ" : "cleared"); $routes[] = new Route($said, HttpMethod::GET, "read", [], new HandlerReference(Controller::class, "env")); }';
        $plugin = str_replace('public function boot(): void { ', 'public function boot(): void { ' . $content . ' ', $this->plugin(false));

        return str_replace('        return [
            new Route("/blog", HttpMethod::GET', '        $routes = [
            new Route("/blog", HttpMethod::GET', str_replace('            new Route("/env", HttpMethod::GET, "env", [], new HandlerReference(Controller::class, "env")),
        ];
    }', '            new Route("/env", HttpMethod::GET, "env", [], new HandlerReference(Controller::class, "env")),
        ];
        ' . $listed . '
        return $routes;
    }', $plugin));
    }

    /**
     * @param array<string, mixed> $observation
     *
     * @return list<string>
     */
    private static function subjects(array $observation): array
    {
        return array_values(array_map(static fn (array $entry): string => (string) ($entry['subject'] ?? ''), $observation['observed'] ?? []));
    }

    private function plugin(bool $logs): string
    {
        $boot = $logs
            ? '@file_put_contents(' . var_export($this->root . '/plain-boots.log', true) . ', json_encode(["mode" => $GLOBALS["argv"][1] ?? "?", "read" => (string) @file_get_contents('
                . var_export($this->root . '/.milpa/secrets.json', true) . '), "env" => (string) getenv("' . self::ENV . '")]) . "\n", FILE_APPEND);'
            : '';

        return '<?php
declare(strict_types=1);
namespace App\Plugins\Blog;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
#[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "Blog", type: "Service")]
final class Blog implements \Milpa\Interfaces\Plugin\PluginInterface, \Milpa\Runtime\Http\RouteProviderInterface
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
    public function boot(): void { ' . $boot . ' $this->container->registerService(Controller::class, new Controller()); }
    public function install(): void {}
    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}
    public function routes(): array
    {
        return [
            new Route("/blog", HttpMethod::GET, "blog", [], new HandlerReference(Controller::class, "index")),
            new Route("/blog", HttpMethod::POST, "blog_write", [], new HandlerReference(Controller::class, "write")),
            new Route("/whoami", HttpMethod::GET, "whoami", [], new HandlerReference(Controller::class, "whoami")),
            new Route("/env", HttpMethod::GET, "env", [], new HandlerReference(Controller::class, "env")),
        ];
    }
}
';
    }

    private static function controller(): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\Blog;
final class Controller
{
    public function index(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new \Nyholm\Psr7\Response(200, ["Content-Type" => "text/html"], "<h1>blog</h1>");
    }
    public function write(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new \Nyholm\Psr7\Response(200, [], "wrote");
    }
    public function whoami(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new \Nyholm\Psr7\Response(200, [], "env=" . (string) getenv("' . self::ENV . '") . "|trial=" . (string) getenv("MILPA_TRIAL"));
    }
    public function env(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new \Nyholm\Psr7\Response(200, [], "env");
    }
}
';
    }
}
