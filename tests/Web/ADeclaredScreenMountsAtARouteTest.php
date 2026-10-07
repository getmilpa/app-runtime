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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\Controllers\LiveComponentPageController;
use Milpa\AppRuntime\Web\Controllers\MountedScreenController;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\AppRuntime\Web\ScreenDrafts;
use Milpa\AppRuntime\Web\ScreenOperations;
use Milpa\AppRuntime\Web\ScreenRoute;
use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Data\EntityInterface;
use Milpa\Data\InMemoryRepository;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Runtime\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * A DECLARED SCREEN MOUNTS AT A ROUTE (greenhouse decisions/0567 §3, slice BV-1).
 *
 * Measured (greenhouse evidence/1101): a public, anonymous page made of the house's own components was possible
 * with no HTML and no CSS — and it answered only at `/live/page?component=blog`. A goal that writes `GET /blog`
 * closes on that route and no other (decisions/0555), so the composed path could not meet it; the resident
 * pushed to compose rebuilt the mount by hand, a controller that forwarded `/blog` to the house's page
 * controller. A declaration now says where its page is served, and the house serves it there.
 */
final class ADeclaredScreenMountsAtARouteTest extends TestCase
{
    private string $dir = '';

    private DIContainer $container;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-mounted-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        $this->container = new DIContainer();
        $this->container->registerService(Config::class, new Config(['live' => [
            'secret' => str_repeat('k', 32),
            'screens_path' => $this->dir . '/screens.json',
        ]]));
        (new LivePlugin($this->container))->boot();

        $posts = new InMemoryRepository(MountedPost::class);
        $posts->save(MountedPost::fromArray(['id' => 1, 'title' => 'Hello, blog', 'body' => 'the public body', 'published' => true]));
        $posts->save(MountedPost::fromArray(['id' => 2, 'title' => 'Secret draft', 'body' => 'the draft body', 'published' => false]));
        $this->container->registerService(MountedPost::class . 'Repository', $posts);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}[!.]*', \GLOB_BRACE) ?: [] as $left) {
            @unlink($left);
        }
        @rmdir($this->dir);
    }

    public function testAScreenDeclaredWithARouteIsServedThere(): void
    {
        $declared = $this->declare($this->blog() + ['route' => '/blog']);

        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame('/blog', $declared['route'], 'the result says where the page is served');
        self::assertSame('/live/page?component=blog', $declared['servedAt'], 'and the live door still answers for it');

        $mounted = $this->mountedRoutes();
        self::assertSame(['/blog'], array_keys($mounted));
        self::assertSame([HttpMethod::GET], $mounted['/blog']->methods, 'a page is read, never posted to');
        self::assertSame(MountedScreenController::class, $mounted['/blog']->handler?->controller);
        self::assertSame('show', $mounted['/blog']->handler?->method);
        self::assertSame('live.screen.blog', $mounted['/blog']->name);

        $response = $this->container->get(MountedScreenController::class)->show(new ServerRequest('GET', '/blog'));
        $html = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('data-milpa-component="content"', $html, 'painted by the house\'s component');
        self::assertStringContainsString('Hello, blog', $html);
        self::assertStringContainsString('the public body', $html);
        self::assertStringNotContainsString('Secret draft', $html, 'what the entity does not declare public is not served here either');
        self::assertStringContainsString('milpa-tokens.css', $html, 'with the house\'s look, which nobody wrote');
    }

    public function testTheMountedPageIsThePageTheLiveDoorServes(): void
    {
        $this->declare($this->blog() + ['route' => '/blog']);

        $atTheDoor = $this->container->get(LiveComponentPageController::class)
            ->show((new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'blog']));
        $atTheRoute = $this->container->get(MountedScreenController::class)->show(new ServerRequest('GET', '/blog'));

        self::assertSame(200, $atTheDoor->getStatusCode());
        self::assertSame(self::article((string) $atTheDoor->getBody()), self::article((string) $atTheRoute->getBody()), 'one page, two addresses');
    }

    public function testARouteIsNotASecondDoorToTheOtherScreens(): void
    {
        $this->declare($this->blog() + ['route' => '/blog']);
        $this->declare(['name' => 'plain', 'columns' => [['key' => 't', 'label' => 'T']], 'rows' => [['t' => 'only in plain']]]);

        $response = $this->container->get(MountedScreenController::class)
            ->show((new ServerRequest('GET', '/blog?component=plain'))->withQueryParams(['component' => 'plain', 'page' => '2']));
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello, blog', $html, 'the screen is the one mounted at the path');
        self::assertStringNotContainsString('only in plain', $html, 'never one the visitor names');
    }

    public function testAScreenWithoutARouteMountsNowhere(): void
    {
        $declared = $this->declare($this->blog());

        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertArrayNotHasKey('route', $declared);
        self::assertSame([], $this->mountedRoutes());
    }

    public function testAPathNoScreenIsMountedAtAnswers404(): void
    {
        $this->declare($this->blog() + ['route' => '/blog']);

        $response = $this->container->get(MountedScreenController::class)->show(new ServerRequest('GET', '/elsewhere'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('no_screen_mounted_here', (string) $response->getBody());
    }

    public function testTheRouteIsALiteralGetPathAndAnythingElseIsRefusedByName(): void
    {
        foreach ([
            'blog' => 'starts with /',
            '/blog/{id}' => 'no parameters',
            '/blog?page=2' => 'no query',
            '/the blog' => 'only letters, digits',
            '/blog//archive' => 'empty segment',
            '/blog#top' => 'only letters, digits',
        ] as $route => $why) {
            $refused = $this->declare($this->blog() + ['route' => $route]);
            self::assertFalse($refused['ok'], "«{$route}» must be refused");
            self::assertSame('route', $refused['path'] ?? null, json_encode($refused) ?: '');
            self::assertStringContainsString($why, (string) ($refused['reason'] ?? ''), "«{$route}»");
        }
        self::assertSame([], ScreenStore::fromConfig($this->live(), $this->dir)->names(), 'a refused declaration stores nothing');

        self::assertSame('/blog', $this->declare($this->blog() + ['route' => '/blog/'])['route'], 'a trailing slash is the same route');
        self::assertSame('/', ScreenRoute::parse('/'), 'the root is a path');
        self::assertSame('/feed.xml', ScreenRoute::parse('/feed.xml'));
    }

    public function testTwoScreensCannotBeMountedAtOneRoute(): void
    {
        $this->declare($this->blog() + ['route' => '/blog']);

        $second = $this->declare(['name' => 'news', 'route' => '/Blog/'] + $this->blog());

        self::assertFalse($second['ok']);
        self::assertSame('route', $second['path']);
        self::assertStringContainsString('«blog» is already mounted at /blog', $second['reason']);
        self::assertSame(['blog'], ScreenStore::fromConfig($this->live(), $this->dir)->names());

        self::assertTrue($this->declare($this->blog() + ['route' => '/blog'])['ok'], 'declaring the same screen again at its own route is not a collision');
    }

    public function testARouteTheHouseAlreadyServesIsRefusedAndSaysWhoServesIt(): void
    {
        // The booted door knows its own routes even with no kernel to ask.
        $door = new LivePlugin($this->container);
        $door->boot();
        $ownDoor = (self::operation($door->operations(), 'screen:declare')->handler)($this->blog() + ['route' => '/live/page']);
        self::assertFalse($ownDoor['ok']);
        self::assertSame('route', $ownDoor['path']);
        self::assertStringContainsString('GET /live/page is already served by «live.page»', $ownDoor['reason']);

        $operations = new ScreenOperations(
            ScreenStore::fromConfig($this->live(), $this->dir),
            routes: static fn (): array => [
                ['method' => 'GET', 'path' => '/blog', 'name' => 'blog_index', 'plugin' => 'Blog'],
                ['method' => 'POST', 'path' => '/posts', 'name' => 'posts_create', 'plugin' => 'Blog'],
                ['method' => 'GET', 'path' => '/posts/{id}', 'name' => 'posts_show', 'plugin' => 'Blog'],
            ],
        );
        $declare = self::operation($operations->operations(), 'screen:declare');

        $taken = ($declare->handler)(['name' => 'blog', 'rows' => [], 'route' => '/blog']);
        self::assertFalse($taken['ok']);
        self::assertStringContainsString('GET /blog is already served by «blog_index» of plugin Blog', $taken['reason']);

        $notARoute = ($declare->handler)(['name' => 'blog', 'rows' => [], 'route' => 'blog']);
        self::assertSame('route', $notARoute['path'] ?? null, 'what is not a route is refused by name, not compared with the house');
        self::assertStringContainsString('starts with /', $notARoute['reason']);

        self::assertTrue(($declare->handler)(['name' => 'posts', 'rows' => [], 'route' => '/posts'])['ok'], 'a path only POST answers is free to GET');
        $parameterised = ($declare->handler)(['name' => 'post', 'rows' => [], 'route' => '/posts/7']);
        self::assertFalse($parameterised['ok'], 'a parameterised route of the house would answer this path too');
        self::assertStringContainsString('«posts_show»', $parameterised['reason']);
    }

    public function testABootedHouseIsAskedForItsWholeRouteTable(): void
    {
        $container = new DIContainer();
        $kernel = \Milpa\Runtime\Kernel::boot([
            'root' => $this->dir,
            'plugins' => [LivePlugin::class, MountedTakenPlugin::class],
            'config' => ['live' => ['secret' => str_repeat('k', 32), 'screens_path' => $this->dir . '/screens.json', 'nonce_path' => $this->dir . '/nonces.json']],
            'container' => $container,
        ]);
        $container->registerService(\Milpa\Runtime\Kernel::class, $kernel);
        $door = array_values(array_filter($kernel->plugins(), static fn (object $plugin): bool => $plugin instanceof LivePlugin))[0];
        $declare = self::operation($door->operations(), 'screen:declare');

        $taken = ($declare->handler)(['name' => 'pages', 'rows' => [], 'route' => '/taken']);
        self::assertFalse($taken['ok'], 'another plugin of the booted house answers there');
        self::assertStringContainsString('GET /taken is already served by «taken_index» of plugin MountedTakenPlugin', $taken['reason']);

        $free = ($declare->handler)(['name' => 'pages', 'rows' => [], 'route' => '/pages']);
        self::assertTrue($free['ok'], json_encode($free) ?: '');
        self::assertSame('/pages', $free['route']);
    }

    public function testTheStoreItselfRefusesARouteItCannotKeep(): void
    {
        $store = ScreenStore::fromConfig($this->live(), $this->dir);

        $refused = $store->declare(['name' => 'blog', 'rows' => [], 'route' => 'blog']);

        self::assertSame(['ok' => false, 'error' => 'invalid screen tree', 'path' => 'route', 'reason' => 'a route starts with /, as HTTP writes it: /<path>'], $refused);
        self::assertSame([], $store->names(), 'whoever writes the store directly — a trial, a test — meets the same rule');
        self::assertTrue($store->declare(['name' => 'blog', 'rows' => [], 'route' => ''])['ok'], 'an empty route is no route');
        self::assertSame([], $store->mounts());
    }

    public function testAMountedScreenIsNotACollisionWithItself(): void
    {
        $this->declare($this->blog() + ['route' => '/blog']);

        // The route table of a booted house now carries the mount; declaring the screen again must not trip on it.
        $door = new LivePlugin($this->container);
        $door->boot();
        self::assertContains('/blog', array_map(static fn (Route $route): string => $route->path, $door->routes()));
        $again = (self::operation($door->operations(), 'screen:declare')->handler)(['props' => ['heading' => 'Blog', 'roles' => ['title' => 'title', 'body' => 'body']], 'route' => '/blog'] + $this->blog());

        self::assertTrue($again['ok'], json_encode($again) ?: '');
    }

    public function testForgettingOrRedeclaringWithoutARouteUnmounts(): void
    {
        $this->declare($this->blog() + ['route' => '/blog']);
        $this->declare($this->blog());
        self::assertSame([], $this->mountedRoutes(), 'a declaration replaces the screen: no route, no mount');

        $this->declare($this->blog() + ['route' => '/blog']);
        self::assertTrue((self::operation((new LivePlugin($this->container))->operations(), 'screen:forget')->handler)(['name' => 'blog'])['ok']);
        self::assertSame([], $this->mountedRoutes());
    }

    public function testTheListSaysWhereEachScreenIsMounted(): void
    {
        $this->declare($this->blog() + ['route' => '/blog']);
        $this->declare(['name' => 'plain', 'rows' => []]);

        $list = (self::operation((new LivePlugin($this->container))->operations(), 'screen:list')->handler)([]);
        $byName = array_column($list['screens'], null, 'name');

        self::assertSame('/blog', $byName['blog']['route']);
        self::assertArrayNotHasKey('route', $byName['plain']);
    }

    public function testAPromotedDraftKeepsTheRouteItsScreenIsMountedAt(): void
    {
        $this->declare($this->blog() + ['route' => '/blog']);
        $store = ScreenStore::fromConfig($this->live(), $this->dir);
        $current = $store->screen('blog');
        self::assertNotNull($current);

        // A draft proposes a declaration (type and props); where the screen is mounted is not part of the proposal.
        self::assertTrue($store->compareAndSwap('blog', ScreenDrafts::hash($current), ['type' => 'content', 'props' => ['heading' => 'Changed'] + $current['props']]));

        self::assertSame(['/blog' => 'blog'], $store->mounts());
        self::assertSame('Changed', $store->screen('blog')['props']['heading'] ?? null);

        self::assertTrue($store->compareAndSwap('blog', ScreenDrafts::hash($store->screen('blog')), null));
        self::assertSame([], $store->mounts(), 'a rollback to «no screen» leaves no mount behind');
    }

    public function testTheContractOfTheOperationSaysARouteCanBeNamed(): void
    {
        $declare = self::operation((new LivePlugin($this->container))->operations(), 'screen:declare');

        self::assertStringContainsString('route', $declare->description);
        self::assertSame('string', $declare->inputSchema['properties']['route']['type'] ?? null);
        self::assertStringContainsString('GET', (string) ($declare->inputSchema['properties']['route']['description'] ?? ''));
    }

    /** @return array<string, mixed> */
    private function blog(): array
    {
        return [
            'name' => 'blog',
            'type' => 'content',
            'props' => ['roles' => ['title' => 'title', 'body' => 'body']],
            'source' => ['entity' => MountedPost::class, 'columns' => ['title', 'body']],
        ];
    }

    /** @return array<string, mixed> */
    private function live(): array
    {
        return ['screens_path' => $this->dir . '/screens.json'];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function declare(array $input): array
    {
        return (self::operation((new LivePlugin($this->container))->operations(), 'screen:declare')->handler)($input);
    }

    /** @return array<string, Route> the routes a freshly booted live door mounts for declared screens, by path */
    private function mountedRoutes(): array
    {
        $plugin = new LivePlugin($this->container);
        $plugin->boot();
        $mounted = [];
        foreach ($plugin->routes() as $route) {
            if (str_starts_with((string) $route->name, 'live.screen.')) {
                $mounted[$route->path] = $route;
            }
        }

        return $mounted;
    }

    /** @param list<mixed> $operations */
    private static function operation(array $operations, string $name): Operation
    {
        foreach ($operations as $operation) {
            if ($operation instanceof Operation && $operation->name === $name) {
                return $operation;
            }
        }
        self::fail($name . ' is not offered');
    }

    /** The painted entries, without the per-request nonce and session of the document around them. */
    private static function article(string $html): string
    {
        self::assertSame(1, preg_match('~<section data-milpa-component="content".*?</section>~s', $html, $match), $html);

        return $match[0];
    }
}

/** A generated-shape entity that declares what of it is public. */
final readonly class MountedPost implements EntityInterface
{
    public const PUBLIC_WHEN = 'published';

    public function __construct(
        public int|string|null $id,
        public string $title,
        public string $body,
        public bool $published,
    ) {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'body' => $this->body, 'published' => $this->published];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): static
    {
        return new self($row['id'] ?? null, $row['title'], $row['body'], $row['published']);
    }
}

/** A plugin of the house that already serves a route. */
#[\Milpa\Attributes\PluginMetadata(version: '0.1.0', author: 't', site: 'https://example.com', name: 'MountedTaken', type: 'Service')]
final class MountedTakenPlugin implements \Milpa\Interfaces\Plugin\PluginInterface, \Milpa\Runtime\Http\RouteProviderInterface
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container)
    {
    }

    public function boot(): void
    {
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return [new Route('/taken', HttpMethod::GET, 'taken_index', [], new \Milpa\Http\Routing\HandlerReference(self::class, 'boot'))];
    }
}
