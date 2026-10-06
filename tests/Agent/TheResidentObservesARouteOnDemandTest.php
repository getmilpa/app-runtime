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
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The resident asks the house what a route answers, when it wants to know — not only when a promotion lands
 * (greenhouse decisions/0549, B-c of evidence/t-0074).
 *
 * Measured on a copy of Rod's first live run, on 0.205.0: GET /blog answered 500, and the resident read
 * `BlogController.php` and concluded «GET /blog → 200». The house could say why it failed (0539), but only in
 * a promotion's receipt, and the resident had no way to request a route outside one.
 *
 * A real house on disk, booted from its own `config/boot.php` and served by its own `public/index.php`.
 *
 * @guards `route:observe` requests a concrete GET path of the house, anonymously, and answers its status, a
 *         bounded excerpt of the body and, on a 5xx or a request that died, the cause the house logged; given a
 *         `workspace`, the same of the house as that trial would leave it; the house closes on a served one
 *
 * @refuses a path that is not one (a placeholder, a URL, no leading slash), a trial that does not exist, a house
 *          with no front controller; a cause in the visitor's page; a trial's observation as the house's
 *
 * @subject-in milpa/app-runtime
 */
final class TheResidentObservesARouteOnDemandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-route-observe-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/Plugins/Blog', 0o777, true);
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
            $request = new \Nyholm\Psr7\ServerRequest($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], array_filter(['Authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'Cookie' => $_SERVER['HTTP_COOKIE'] ?? null]), null, '1.1', $_SERVER);
            $request = $request->withQueryParams($_GET)->withParsedBody($_POST)
                ->withBody($psr17->createStreamFromResource(fopen('php://input', 'r')))
                ->withHeader('Content-Type', (string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            $response = (new \Milpa\Runtime\Http\ExceptionMiddleware($psr17, new \Milpa\Runtime\Observability\ErrorLogLogger()))->process($request, new \Milpa\Runtime\Http\RequestHandler($kernel, $psr17));
            (new \Milpa\Runtime\Http\ResponseEmitter())->emit($response);
            PHP);
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', '<?php
declare(strict_types=1);
namespace App\Plugins\Blog;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
#[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "Blog", type: "Service")]
final class Blog implements \Milpa\Interfaces\Plugin\PluginInterface, \Milpa\Runtime\Http\RouteProviderInterface
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
    public function boot(): void { $this->container->registerService(Controller::class, new Controller()); }
    public function install(): void {}
    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}
    public function routes(): array
    {
        return [
            new Route("/blog", HttpMethod::GET, "blog", [], new HandlerReference(Controller::class, "index")),
            new Route("/blog/{id}", HttpMethod::GET, "blog_post", [], new HandlerReference(Controller::class, "post")),
            new Route("/admin", HttpMethod::GET, "admin", [], new HandlerReference(Controller::class, "admin")),
            new Route("/blog", HttpMethod::POST, "blog_write", [], new HandlerReference(Controller::class, "write")),
            new Route("/blog/escape", HttpMethod::POST, "blog_escape", [], new HandlerReference(Controller::class, "escape")),
            new Route("/feed", HttpMethod::GET, "feed", [], new HandlerReference(Controller::class, "feed")),
        ];
    }
}
');
        $this->controller('<h1>blog</h1>');
    }

    protected function tearDown(): void
    {
        self::rmrf($this->root);
    }

    public function testTheOperationIsAReadTheResidentHasAtHandWithoutConsentOrSignature(): void
    {
        $op = $this->operation();

        self::assertFalse($op->mutating);
        self::assertEquals(EffectProfile::readOnly(), $op->effects, 'a read: no consent, no signature, no trial');
        // The cause is for the agent, never the visitor (0506, 0539): the op carries the scopes of the readers of the agent's
        // own receipts (`agent:result`), which the seat holds — so no surface can serve it to an anonymous caller unjudged.
        self::assertSame(['agent:read', 'agent:answer'], $op->scopes);
        self::assertSame(['path'], $op->inputSchema['required'] ?? null);
        self::assertStringContainsString('confirm a route serves', $op->description, 'the catalogue says what it is for');
    }

    public function testAServingRouteAnswersItsStatusAndAnExcerptOfWhatAVisitorGets(): void
    {
        $seen = $this->observe(['path' => '/blog']);

        self::assertTrue($seen['ok'] ?? false, (string) json_encode($seen));
        self::assertSame([[
            'predicate' => 'served', 'route' => 'GET /blog', 'subject' => '/blog', 'status' => 200, 'environment' => ['kind' => 'house'],
            'servedAt' => '/blog', 'bytes' => \strlen('<h1>blog</h1>'), 'sha256' => hash('sha256', '<h1>blog</h1>'),
            // What kind of thing answered (greenhouse decisions/0579): a page, and no declared screen serves it.
            'contentType' => 'text/html', 'surface' => ['kind' => 'visual', 'screen' => null],
        ]], $seen['observed'] ?? null);
        self::assertSame('<h1>blog</h1>', $seen['excerpt'] ?? null);
        self::assertArrayNotHasKey('truncated', $seen);
        self::assertStringContainsString('GET /blog answered HTTP 200', (string) ($seen['note'] ?? ''));
    }

    /** B-c (t-0074): the resident read the controller and said 200; the house would have said 500 and why. */
    public function testAFailingRouteAnswersTheCauseTheHouseLogged(): void
    {
        $this->controller('', throws: 'Cannot resolve parameter $container for class BlogController');

        $seen = $this->observe(['path' => '/blog']);

        self::assertTrue($seen['ok'] ?? false, 'the house was asked: what it answered is the observation, not a failure of the call');
        $entry = $seen['observed'][0] ?? [];
        self::assertSame(500, $entry['status'] ?? null);
        self::assertArrayNotHasKey('predicate', $entry, 'a 500 is never served');
        self::assertSame('RuntimeException', $entry['cause']['class'] ?? null, (string) json_encode($seen));
        self::assertSame('Cannot resolve parameter $container for class BlogController', $entry['cause']['message'] ?? null);
        self::assertSame('src/Plugins/Blog/Controller.php:8', $entry['cause']['at'] ?? null);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) ($entry['cause']['reference'] ?? ''));
        self::assertStringContainsString('GET /blog answered HTTP 500 — RuntimeException: Cannot resolve parameter $container for class BlogController (at src/Plugins/Blog/Controller.php:8)', (string) ($seen['note'] ?? ''));
        self::assertStringNotContainsString('Cannot resolve', (string) ($seen['excerpt'] ?? ''), 'the excerpt is the page a visitor gets, and it stays mute (0506)');
        self::assertStringNotContainsString($this->root, (string) json_encode($seen, \JSON_UNESCAPED_SLASHES), 'no absolute path of the host travels');
    }

    public function testARouteWithParametersIsObservedWithItsValuesNeverWithAPlaceholder(): void
    {
        $seen = $this->observe(['path' => '/blog/7?draft=0']);

        self::assertSame(200, $seen['observed'][0]['status'] ?? null, (string) json_encode($seen));
        self::assertSame('GET /blog/7?draft=0', $seen['observed'][0]['route'] ?? null);
        self::assertSame('/blog/7', $seen['observed'][0]['subject'] ?? null, 'the subject is the path the goal can name, not its query');
        self::assertSame('<p>post 7, draft 0</p>', $seen['excerpt'] ?? null, 'the value reached the controller, and so did the query');

        $refused = $this->observe(['path' => '/blog/{id}']);
        self::assertFalse($refused['ok'] ?? true);
        self::assertStringContainsString('give the value', (string) ($refused['error'] ?? ''));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function notAPath(): iterable
    {
        yield 'no leading slash' => ['blog'];
        yield 'a URL' => ['http://example.com/blog'];
        yield 'protocol-relative' => ['//example.com/blog'];
        yield 'empty' => [''];
        yield 'a fragment' => ['/blog#top'];
        yield 'a space' => ['/blog post'];
        yield 'a newline' => ["/blog\nX-Header: 1"];
        yield 'not a string' => [['/blog']];
        yield 'too long' => ['/' . str_repeat('a', 2048)];
    }

    /** @dataProvider notAPath */
    #[\PHPUnit\Framework\Attributes\DataProvider('notAPath')]
    public function testWhatIsNotAPathOfThisHouseIsRefusedBeforeAnythingRuns(mixed $path): void
    {
        touch($this->root . '/var/die');

        $refused = $this->observe(['path' => $path]);

        self::assertFalse($refused['ok'] ?? true, (string) json_encode($refused));
        self::assertStringContainsString('a path of this house', (string) ($refused['error'] ?? ''));
        self::assertArrayNotHasKey('observed', $refused);
    }

    /** It is a visitor: no credential of the principal travels, and a guarded route says so instead of serving. */
    public function testAProtectedRouteIsAskedAnonymouslyAndTheNoteSaysSo(): void
    {
        // What the process that observes inherits reaches the child's `$_SERVER` — a server's own request headers included.
        putenv('HTTP_AUTHORIZATION=Bearer the-principals-token');
        putenv('HTTP_COOKIE=session=the-principals-cookie');
        try {
            $seen = $this->observe(['path' => '/admin']);
        } finally {
            putenv('HTTP_AUTHORIZATION');
            putenv('HTTP_COOKIE');
        }

        self::assertSame(401, $seen['observed'][0]['status'] ?? null, (string) json_encode($seen));
        self::assertSame('who are you', $seen['excerpt'] ?? null, 'the controller saw no credential');
        self::assertStringContainsString('anonymous visitor', (string) ($seen['note'] ?? ''));
    }

    public function testTheExcerptIsBoundedAndSaysWhatItLeftOut(): void
    {
        $this->controller(str_repeat('x', 20000));

        $default = $this->observe(['path' => '/blog']);
        self::assertSame(HouseRouteObserver::EXCERPT, \strlen((string) ($default['excerpt'] ?? '')));
        self::assertSame(20000, $default['observed'][0]['bytes'] ?? null, 'the size and digest are of the whole body');
        self::assertSame(20000 - HouseRouteObserver::EXCERPT, $default['truncated'] ?? null);

        $asked = $this->observe(['path' => '/blog', 'excerpt' => 100]);
        self::assertSame(100, \strlen((string) ($asked['excerpt'] ?? '')));

        $greedy = $this->observe(['path' => '/blog', 'excerpt' => 1_000_000]);
        self::assertSame(HouseRouteObserver::EXCERPT_MAX, \strlen((string) ($greedy['excerpt'] ?? '')), 'never past the ceiling, whatever is asked');

        $none = $this->observe(['path' => '/blog', 'excerpt' => 0]);
        self::assertArrayNotHasKey('excerpt', $none);
        self::assertSame(20000, $none['truncated'] ?? null);
    }

    public function testAnExcerptCutInsideACharacterStaysValidText(): void
    {
        $this->controller(str_repeat('ñ', 10));

        $seen = $this->observe(['path' => '/blog', 'excerpt' => 5]);

        self::assertSame('ññ', $seen['excerpt'] ?? null, 'cut at the last whole character');
        self::assertNotFalse(json_encode($seen));

        $this->controller(str_repeat('€', 3));
        self::assertSame('€', $this->observe(['path' => '/blog', 'excerpt' => 5])['excerpt'] ?? null, 'a cut two bytes into a three-byte character');
    }

    public function testARequestThatDiedAnsweredNothing(): void
    {
        touch($this->root . '/var/die');

        $seen = $this->observe(['path' => '/blog']);

        self::assertTrue($seen['ok'] ?? false);
        self::assertSame([['route' => 'GET /blog', 'subject' => '/blog', 'status' => null, 'environment' => ['kind' => 'house'],
            'error' => 'the request process exited 3', 'bytes' => 0, 'sha256' => hash('sha256', '')]], $seen['observed'] ?? null);
        self::assertStringContainsString('GET /blog answered nothing (the request process exited 3)', (string) ($seen['note'] ?? ''));
        self::assertStringContainsString('the house logged no cause this observer could read', (string) ($seen['note'] ?? ''));
    }

    public function testAHouseWithoutAFrontControllerCannotBeAsked(): void
    {
        unlink($this->root . '/public/index.php');

        $seen = $this->observe(['path' => '/blog']);

        self::assertFalse($seen['ok'] ?? true);
        self::assertStringContainsString('no front controller', (string) ($seen['error'] ?? ''));
    }

    /** Asked of a rehearsal: the house as the trial would leave it, built beside it — and the house itself untouched. */
    public function testAWorkspaceIsObservedAsTheHouseWouldBeWithThatTrial(): void
    {
        $this->controller('', throws: 'broken in the house');
        file_put_contents($this->root . '/public/unused.txt', 'the trial removes me');
        $ws = TrialWorkspace::materialize($this->root, 'w1', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($ws->copy . '/src/Plugins/Blog/Controller.php', $this->controllerSource('<h1>fixed in the trial</h1>'));
        unlink($ws->copy . '/public/unused.txt');

        $trial = $this->observe(['path' => '/blog', 'workspace' => 'w1']);
        $house = $this->observe(['path' => '/blog']);

        self::assertSame(200, $trial['observed'][0]['status'] ?? null, (string) json_encode($trial));
        self::assertSame(['kind' => 'trial', 'workspace' => 'w1'], $trial['observed'][0]['environment'] ?? null);
        self::assertSame('<h1>fixed in the trial</h1>', $trial['excerpt'] ?? null);
        self::assertStringContainsString('as trial «w1» would leave it', (string) ($trial['note'] ?? ''));
        self::assertSame(500, $house['observed'][0]['status'] ?? null, 'the house still answers what it answers');
        self::assertSame([], glob($this->root . '/var/boot-candidates/*') ?: [], 'the copy it was asked in is gone');
        self::assertFileExists($this->root . '/public/unused.txt', 'what the trial deletes, it deletes in the copy');
    }

    public function testATrialThatIsNotThereIsRefused(): void
    {
        $seen = $this->observe(['path' => '/blog', 'workspace' => 'nope']);

        self::assertFalse($seen['ok'] ?? true);
        self::assertSame('no trial «nope» to observe', $seen['error'] ?? null);
    }

    public function testATrialsCauseNamesItsFilesAsTheHousesNeverTheCopysPath(): void
    {
        $ws = TrialWorkspace::materialize($this->root, 'w2', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($ws->copy . '/src/Plugins/Blog/Controller.php', $this->controllerSource('', 'broken in the trial'));

        $seen = $this->observe(['path' => '/blog', 'workspace' => 'w2']);

        self::assertSame('src/Plugins/Blog/Controller.php:8', $seen['observed'][0]['cause']['at'] ?? null, (string) json_encode($seen));
        self::assertStringNotContainsString('boot-candidates', (string) json_encode($seen, \JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString($this->root, (string) json_encode($seen, \JSON_UNESCAPED_SLASHES));
    }

    /** The house closes on what it observed itself serving, named by the goal — and never on a rehearsal's answer. */
    public function testTheHouseClosesOnAServedObservationAndNotOnATrialsOrAFailingOne(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog', AutonomyMode::Auto);
        $store->recordToolCall('s', 'implement', ['target' => 'Blog'], '{"ok":true}', mutating: true);

        $this->controller('', throws: 'boom');
        $store->recordToolCall('s', 'route_observe', ['path' => '/blog'], (string) json_encode($this->observe(['path' => '/blog'])));
        self::assertStringContainsString('HTTP 500', implode('; ', $this->closure($store)['reasons']));

        $ws = TrialWorkspace::materialize($this->root, 'w3', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($ws->copy . '/src/Plugins/Blog/Controller.php', $this->controllerSource('<h1>blog</h1>'));
        $store->recordToolCall('s', 'route_observe', ['path' => '/blog', 'workspace' => 'w3'], (string) json_encode($this->observe(['path' => '/blog', 'workspace' => 'w3'])));
        self::assertFalse($this->closure($store)['verified'], 'a rehearsal serving says nothing about the house');

        $this->controller('<h1>blog</h1>');
        $store->recordToolCall('s', 'route_observe', ['path' => '/blog'], (string) json_encode($this->observe(['path' => '/blog'])));
        $closure = $this->closure($store);
        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('/blog', $closure['derivedFrom']['observation']['subject'] ?? null);
    }

    /** The model is told, where it reads how the house works, that a route is confirmed by asking it. */
    public function testThePromptSaysARouteIsConfirmedByObservingItWhenTheToolTravels(): void
    {
        $ops = new AgentOperations(new DIContainer());
        $prompt = new \ReflectionMethod($ops, 'systemPrompt');

        $with = (string) $prompt->invoke($ops, ['route_observe', 'implement'], null);
        $without = (string) $prompt->invoke($ops, ['implement'], null);

        self::assertStringContainsString('To confirm a route serves, call `route_observe`', $with);
        self::assertStringContainsString('Never conclude it from reading its code', $with);
        self::assertStringNotContainsString('route_observe', $without, 'a tool that does not travel is never named');
    }

    /** A request that writes is never observed on the live house: it would be a mutation without its operation's judgment. */
    public function testARequestThatWritesIsRefusedOnTheLiveHouse(): void
    {
        $seen = $this->observe(['path' => '/blog', 'method' => 'POST', 'body' => '{"title":"x"}']);

        self::assertFalse($seen['ok'] ?? true);
        self::assertStringContainsString('only in a rehearsal', (string) ($seen['error'] ?? ''));
        self::assertFileDoesNotExist($this->root . '/var/written.txt', 'nothing ran');
    }

    /** Fricción 6: a verification that needs a POST is observed in a rehearsal, and what it wrote is seen by the GETs after it. */
    public function testAPostIsObservedInARehearsalAndTheGetsAfterItSeeWhatItWrote(): void
    {
        $this->trial('w4');

        $seen = $this->observe(['path' => '/blog', 'workspace' => 'w4', 'method' => 'POST', 'body' => '{"title":"hola"}',
            'content_type' => 'application/json', 'then' => ['/feed']]);

        self::assertTrue($seen['ok'] ?? false, (string) json_encode($seen));
        self::assertSame('POST /blog', $seen['observed'][0]['route'] ?? null);
        self::assertSame(200, $seen['observed'][0]['status'] ?? null);
        self::assertArrayNotHasKey('predicate', $seen['observed'][0], 'only a GET is ever «served»');
        self::assertSame('wrote {"title":"hola"}|[]|application/json', $seen['excerpt'] ?? null, 'the body reached the controller as sent');
        self::assertSame('GET /feed', $seen['observed'][1]['route'] ?? null);
        self::assertSame(['kind' => 'trial', 'workspace' => 'w4'], $seen['observed'][1]['environment'] ?? null);
        self::assertSame('feed: {"title":"hola"}|[]|application/json', $seen['then'][0]['excerpt'] ?? null, 'the GET after it saw what it wrote, in the same copy');
        self::assertStringContainsString('POST /blog answered HTTP 200', (string) ($seen['note'] ?? ''));
        self::assertFileDoesNotExist($this->root . '/var/written.txt', 'the live house never saw the write');
        self::assertSame([], glob($this->root . '/var/boot-candidates/*') ?: []);
    }

    public function testAFormBodyArrivesAsAForm(): void
    {
        $this->trial('w5');

        $seen = $this->observe(['path' => '/blog', 'workspace' => 'w5', 'method' => 'POST', 'body' => 'title=hola&draft=0',
            'content_type' => 'application/x-www-form-urlencoded']);

        self::assertStringContainsString('|{"title":"hola","draft":"0"}|application/x-www-form-urlencoded', (string) ($seen['excerpt'] ?? ''), (string) json_encode($seen));
    }

    /** The copy links the house's secrets and vendor/: a request that writes runs where only the copy can be written. */
    public function testAWriteThroughALinkOfTheCopyNeverReachesTheLiveHouse(): void
    {
        mkdir($this->root . '/.milpa');
        file_put_contents($this->root . '/.milpa/secrets.json', '{"agent":{"apiKey":"sk-live-0123456789"}}');
        $this->trial('w6');

        $seen = $this->observe(['path' => '/blog/escape', 'workspace' => 'w6', 'method' => 'POST']);

        self::assertSame(409, $seen['observed'][0]['status'] ?? null, (string) json_encode($seen));
        self::assertSame('{"agent":{"apiKey":"sk-live-0123456789"}}', file_get_contents($this->root . '/.milpa/secrets.json'));
    }

    public function testWithoutConfinementARequestThatWritesIsNotObserved(): void
    {
        $this->trial('w7');
        $ops = (new TrialOperations(new DIContainer(), null, $this->root, new HouseRouteObserver(bwrap: '/nonexistent/bwrap')))->operations();
        $op = array_values(array_filter($ops, static fn (Operation $o): bool => $o->name === 'route:observe'))[0];

        $seen = ($op->handler)(['path' => '/blog', 'workspace' => 'w7', 'method' => 'POST', 'body' => 'x']);

        self::assertFalse($seen['ok'] ?? true);
        self::assertStringContainsString('confine', (string) ($seen['error'] ?? ''));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function notARequest(): iterable
    {
        yield 'a method it does not know' => [['method' => 'TRACE'], 'method must be'];
        yield 'a body on a GET' => [['body' => 'x'], 'a GET carries no body'];
        yield 'a body that is not text' => [['method' => 'POST', 'body' => ['title' => 'x']], 'body must be a string'];
        yield 'a body past the ceiling' => [['method' => 'POST', 'body' => str_repeat('x', 65537)], 'at most 65536 bytes'];
        yield 'then is not a list of paths' => [['method' => 'POST', 'then' => ['blog']], 'then'];
        yield 'too many then' => [['method' => 'POST', 'then' => ['/a', '/b', '/c', '/d', '/e']], 'then'];
        yield 'a content type with a newline' => [['method' => 'POST', 'content_type' => "text/plain\nX: 1"], 'content_type'];
    }

    /**
     * @dataProvider notARequest
     *
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notARequest')]
    public function testWhatIsNotARequestIsRefusedBeforeAnythingRuns(array $input, string $says): void
    {
        $this->trial('w8');

        $seen = $this->observe(['path' => '/blog', 'workspace' => 'w8'] + $input);

        self::assertFalse($seen['ok'] ?? true, (string) json_encode($seen));
        self::assertStringContainsString($says, (string) ($seen['error'] ?? ''));
    }

    private function trial(string $id): void
    {
        TrialWorkspace::materialize($this->root, $id, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
    }

    /** @return array{verified: bool, reasons: list<string>, derivedFrom?: array<string, mixed>} */
    private function closure(SessionStore $store): array
    {
        $session = $store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function observe(array $input): array
    {
        return ($this->operation()->handler)($input);
    }

    private function operation(): Operation
    {
        foreach ((new TrialOperations(new DIContainer(), null, $this->root))->operations() as $op) {
            if ($op->name === 'route:observe') {
                return $op;
            }
        }
        self::fail('no route:observe');
    }

    private function controller(string $says, ?string $throws = null): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Controller.php', $this->controllerSource($says, $throws));
    }

    private function controllerSource(string $says, ?string $throws = null): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\Blog;
final class Controller
{
    public function index(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        ' . ($throws !== null ? 'throw new \RuntimeException(' . var_export($throws, true) . ');' : '') . '
        return new \Nyholm\Psr7\Response(200, ["Content-Type" => "text/html"], ' . var_export($says, true) . ');
    }
    public function post(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new \Nyholm\Psr7\Response(200, [], "<p>post " . $request->getAttribute(\Milpa\Http\Routing\RouteResult::ATTRIBUTE)->parameters["id"] . ", draft " . ($request->getQueryParams()["draft"] ?? "?") . "</p>");
    }
    public function admin(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $who = $request->getHeaderLine("Authorization") . $request->getHeaderLine("Cookie");
        return $who === "" ? new \Nyholm\Psr7\Response(401, [], "who are you") : new \Nyholm\Psr7\Response(200, [], "welcome " . $who);
    }
    /** Writes what it was sent where the house keeps its state — the var/ of the copy when asked in a rehearsal. */
    public function write(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $said = (string) $request->getBody() . "|" . json_encode($request->getParsedBody()) . "|" . $request->getHeaderLine("Content-Type");
        file_put_contents(dirname(__DIR__, 3) . "/var/written.txt", $said);
        return new \Nyholm\Psr7\Response(200, [], "wrote " . $said);
    }
    /** Writes through the linked secret file of the house — what confinement must stop. */
    public function escape(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $ok = @file_put_contents(dirname(__DIR__, 3) . "/.milpa/secrets.json", "pwned");
        return new \Nyholm\Psr7\Response($ok === false ? 409 : 200, [], $ok === false ? "could not write" : "wrote the secret");
    }
    public function feed(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $file = dirname(__DIR__, 3) . "/var/written.txt";
        return new \Nyholm\Psr7\Response(200, [], is_file($file) ? "feed: " . file_get_contents($file) : "feed: empty");
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
            $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }
}
