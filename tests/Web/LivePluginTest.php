<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\Controllers\LiveAssetsController;
use Milpa\AppRuntime\Web\Controllers\LiveController;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Container\DIContainer;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Runtime\Config;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;

/**
 * THE ONE DOOR of the live wire (greenhouse decisions/0083): built from `live.secret`, it mounts the
 * endpoint and the three client files; without a secret it mounts nothing; and the loop it closes —
 * render, boot, action over the wire, re-render with a fresh signed state — refuses a tampered
 * envelope, a replayed one, an undeclared action and a missing CSRF token exactly as the endpoint
 * promises, with the verified auth actor as the principal.
 */
final class LivePluginTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        if (! class_exists(LiveEndpoint::class)) {
            self::markTestSkipped('milpa/live-web is not installed');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    public function testWithoutASecretTheDoorMountsNothing(): void
    {
        $plugin = new LivePlugin($this->container([]));
        $plugin->boot();

        self::assertSame([], $plugin->routes(), 'fail closed: no secret, no routes');
        self::assertNull($plugin->bootFor());
    }

    public function testAShortSecretIsNoSecret(): void
    {
        $plugin = new LivePlugin($this->container(['live' => ['secret' => 'short']]));
        $plugin->boot();

        self::assertSame([], $plugin->routes());
    }

    public function testWithASecretItMountsTheEndpointAndTheThreeFiles(): void
    {
        $container = $this->container($this->config());
        $plugin = new LivePlugin($container);
        $plugin->boot();

        $paths = array_map(static fn ($r): string => $r->path, $plugin->routes());
        self::assertSame(['/live', '/live/page', '/milpa-live.js', '/milpa-live-remote.js', '/vendor/alpine.min.js'], $paths);
        self::assertTrue($container->has(LiveEndpoint::class));
        self::assertTrue($container->has(LiveController::class));
        self::assertTrue($container->has(LiveAssetsController::class));
        self::assertTrue($container->has(CsrfGuardInterface::class));
        self::assertTrue($container->has(StateTransferCodecInterface::class));
        self::assertTrue($container->has(ComponentRegistryInterface::class));

        $assets = new LiveAssetsController();
        foreach (['local', 'remote', 'alpine'] as $method) {
            $r = $assets->{$method}(new ServerRequest('GET', '/x'));
            self::assertSame(200, $r->getStatusCode(), $method);
            self::assertStringContainsString('javascript', $r->getHeaderLine('Content-Type'));
            self::assertGreaterThan(100, \strlen((string) $r->getBody()));
        }
    }

    public function testTheLoopClosesAndTheEndpointsRefusalsHold(): void
    {
        $container = $this->container($this->config());
        $plugin = new LivePlugin($container);
        $plugin->boot();
        $boot = $plugin->bootFor();
        self::assertNotNull($boot);

        // 1 · the page renders the component with the door's own codec: the signed envelope is in the HTML
        $registry = $container->get(ComponentRegistryInterface::class);
        $renderer = $container->get(DashboardHtmlRenderer::class);
        $table = $registry->get('data-table');
        $rendered = $renderer->render($table, new RenderRequest(
            context: new ComponentContext('t1', route: '/live'),
            props: ['name' => 'ventas', 'endpoint' => '/live', 'columns' => [['key' => 'n', 'label' => 'N'], ['key' => 'v', 'label' => 'V']], 'rows' => [['id' => 'a', 'n' => 'A', 'v' => 1], ['id' => 'b', 'n' => 'B', 'v' => 2]]],
            target: RenderTarget::HTML,
        ));
        $html = $rendered->output;
        self::assertStringContainsString('data-milpa-state="t1"', $html);
        self::assertStringContainsString('@click="sort(', $html, 'the server declared the action the runtime will take');
        preg_match('#<script type="application/milpa\+xhtml" data-milpa-state="t1">(.*?)</script>#s', $html, $m);
        $envelope = html_entity_decode($m[1] ?? '');
        self::assertNotSame('', $envelope);

        $controller = $container->get(LiveController::class);
        // The live wire has its OWN scope vocabulary (`milpa:component:<name>:<action>` or `:*`), judged by
        // ContractInteractionAuthorizer for an AUTHENTICATED principal — the token carries it (decisions/0083).
        $actor = AuthContext::authenticated(new Actor('rod', ActorType::Service, ['agent:answer', 'milpa:component:data-table:*']));
        $post = fn (array $body) => $controller->handle(
            (new ServerRequest('POST', '/live'))
                ->withAttribute(AuthenticateMiddleware::ATTRIBUTE, $actor)
                ->withBody(Stream::create((string) json_encode($body))),
        );
        $base = ['sessionId' => $boot->sessionId, 'csrfToken' => $boot->csrfToken, 'state' => $envelope];

        // 2 · a declared action over the wire: re-rendered HTML + a FRESH signed state
        $ok = $post($base + ['action' => 'sort', 'payload' => ['key' => 'v']]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $data = json_decode((string) $ok->getBody(), true);
        self::assertSame('sort', $data['action']);
        self::assertSame('v', $data['data']['sortBy']);
        self::assertStringContainsString('data-milpa-state="t1"', (string) $data['html'], 'the re-render carries the new envelope');
        self::assertNotSame($envelope, $data['state'], 'the state the client must echo next is a new one');

        // 3 · replaying the OLD envelope is refused
        $replay = $post($base + ['action' => 'sort', 'payload' => ['key' => 'v']]);
        self::assertSame(409, $replay->getStatusCode());

        // 4 · a tampered envelope is refused
        $tampered = $post(['state' => substr_replace($data['state'], 'X', -12, 1)] + $base + ['action' => 'sort', 'payload' => ['key' => 'n']]);
        self::assertSame(400, $tampered->getStatusCode());

        // 5 · an undeclared action is refused, 6 · a missing CSRF token is refused
        $undeclared = $post(['state' => $data['state']] + $base + ['action' => 'drop-table', 'payload' => []]);
        self::assertSame(403, $undeclared->getStatusCode());
        $noCsrf = $post(['state' => $data['state'], 'csrfToken' => ''] + $base + ['action' => 'page', 'payload' => ['page' => 2]]);
        self::assertSame(403, $noCsrf->getStatusCode());

        // 7 · bad JSON never reaches the endpoint (from an authenticated caller — identity is checked first)
        $bad = $controller->handle(
            (new ServerRequest('POST', '/live'))->withAttribute(AuthenticateMiddleware::ATTRIBUTE, $actor)->withBody(Stream::create('not json')),
        );
        self::assertSame(400, $bad->getStatusCode());

        // 8 · IDENTITY IS THE OUTERMOST GATE (greenhouse decisions/0084): an anonymous caller — a fresh,
        // valid envelope and CSRF, but NO verified actor — is refused with 401 BEFORE the endpoint, and no
        // action runs. Exposing the wire does not grant the right to act (evidence/0320 finding G).
        $fresh = $plugin->bootFor();
        $freshHtml = $renderer->render($table, new RenderRequest(
            context: new ComponentContext('t1', route: '/live'),
            props: ['name' => 'ventas', 'endpoint' => '/live', 'columns' => [['key' => 'v', 'label' => 'V']], 'rows' => [['id' => 'a', 'v' => 1]]],
            target: RenderTarget::HTML,
        ))->output;
        preg_match('#<script type="application/milpa\\+xhtml" data-milpa-state="t1">(.*?)</script>#s', $freshHtml, $fm);
        $freshEnv = html_entity_decode($fm[1] ?? '');
        $anon = $controller->handle(
            (new ServerRequest('POST', '/live'))->withBody(Stream::create((string) json_encode([
                'action' => 'sort', 'payload' => ['key' => 'v'], 'state' => $freshEnv, 'sessionId' => $fresh->sessionId, 'csrfToken' => $fresh->csrfToken,
            ]))),
        );
        self::assertSame(401, $anon->getStatusCode(), 'no verified actor → 401, before the endpoint');
        self::assertStringContainsString('live_unauthenticated', (string) $anon->getBody());
    }

    public function testWithLiveAnonymousTheWireReopensToAnUnauthenticatedCaller(): void
    {
        $config = $this->config();
        $config['live']['anonymous'] = true;
        $container = $this->container($config);
        $plugin = new LivePlugin($container);
        $plugin->boot();
        $boot = $plugin->bootFor();

        $renderer = $container->get(DashboardHtmlRenderer::class);
        $table = $container->get(ComponentRegistryInterface::class)->get('data-table');
        $html = $renderer->render($table, new RenderRequest(
            context: new ComponentContext('t1', route: '/live'),
            props: ['name' => 'ventas', 'endpoint' => '/live', 'columns' => [['key' => 'v', 'label' => 'V']], 'rows' => [['id' => 'a', 'v' => 1]]],
            target: RenderTarget::HTML,
        ))->output;
        preg_match('#<script type="application/milpa\\+xhtml" data-milpa-state="t1">(.*?)</script>#s', $html, $m);
        $envelope = html_entity_decode($m[1] ?? '');

        // No Authorization at all, yet the app declared the wire public: the action reaches the endpoint and runs.
        $r = $container->get(LiveController::class)->handle(
            (new ServerRequest('POST', '/live'))->withBody(Stream::create((string) json_encode([
                'action' => 'sort', 'payload' => ['key' => 'v'], 'state' => $envelope, 'sessionId' => $boot->sessionId, 'csrfToken' => $boot->csrfToken,
            ]))),
        );
        self::assertSame(200, $r->getStatusCode(), 'live.anonymous reopens the wire — public is a written decision');
    }

    /** @param array<string, mixed> $config */
    private function container(array $config): DIContainer
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config($config));

        return $c;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $nonce = sys_get_temp_dir() . '/milpa-live-plugin-' . bin2hex(random_bytes(4)) . '.json';
        $this->tmp[] = $nonce;

        return ['live' => ['secret' => 'a-secret-long-enough-to-sign-with', 'nonce_path' => $nonce]];
    }
}
