<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\Controllers\LiveComponentPageController;
use Milpa\AppRuntime\Web\LivePageProvider;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Container\DIContainer;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Runtime\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The shipped interactive render path (greenhouse decisions/0092): `GET /live/page?component=<name>` renders
 * a live component bound to the request's verified actor — the state is born owned by `actor:<id>` without the
 * app touching the format — and answers 404 rather than paint a page with data no provider supplied.
 */
final class LiveComponentPageControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(LiveEndpoint::class)) {
            self::markTestSkipped('milpa/live-web is not installed');
        }
    }

    private function bootedController(?LivePageProvider $provider): LiveComponentPageController
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['live' => ['secret' => str_repeat('k', 32)]]));
        if ($provider !== null) {
            $c->registerService(LivePageProvider::class, $provider);
        }
        (new LivePlugin($c))->boot();

        return $c->get(LiveComponentPageController::class);
    }

    private function dataTableProvider(): LivePageProvider
    {
        return new class () implements LivePageProvider {
            public function propsFor(string $component, ServerRequestInterface $request): ?array
            {
                if ($component !== 'data-table') {
                    return null;
                }

                return [
                    'columns' => [['key' => 'n', 'label' => 'Nombre']],
                    'rows' => [['id' => 'a', 'n' => 'Acme']],
                ];
            }
        };
    }

    private function asActor(string $id): ServerRequestInterface
    {
        return (new ServerRequest('GET', '/live/page?component=data-table'))
            ->withQueryParams(['component' => 'data-table'])
            ->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::authenticated(new Actor($id, ActorType::Service, ['milpa:component:data-table:*'])));
    }

    public function testTheStateIsBornOwnedByTheRequestActor(): void
    {
        $response = $this->bootedController($this->dataTableProvider())->show($this->asActor('rod'));

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertMatchesRegularExpression('/<milpa-state/', $html, 'the signed state is embedded');

        preg_match('#>([A-Za-z0-9+/=]+)</milpa-state>#', $html, $m);
        $state = json_decode(base64_decode($m[1] ?? ''), true);
        self::assertSame('actor:rod', $state['meta']['principal'] ?? null, 'the owner is the verified actor, not a hand-written string');
        self::assertStringContainsString('milpa-live-boot', $html, 'the boot the endpoint verifies is embedded');
    }

    public function testAnAnonymousRequestRendersAnOwnerlessState(): void
    {
        $req = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'data-table']);
        $html = (string) $this->bootedController($this->dataTableProvider())->show($req)->getBody();
        preg_match('#>([A-Za-z0-9+/=]+)</milpa-state>#', $html, $m);
        $state = json_decode(base64_decode($m[1] ?? ''), true);
        self::assertNull($state['meta']['principal'] ?? null, 'no verified actor → ownerless state');
    }

    public function testAnUnknownComponentIs404(): void
    {
        $req = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'nope']);
        self::assertSame(404, $this->bootedController($this->dataTableProvider())->show($req)->getStatusCode());
    }

    public function testNoProviderForTheComponentIs404NotAnInventedPage(): void
    {
        // provider present but declines 'metric-card' → 404, never a page with invented data
        $req = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'metric-card']);
        self::assertSame(404, $this->bootedController($this->dataTableProvider())->show($req)->getStatusCode());
    }
}
