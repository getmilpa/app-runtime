<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\LiveRender;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The one-call render path binds the request's verified actor as the state's owner (greenhouse
 * decisions/0089/0091): the context is born with `actor:<id>`, the SAME id the endpoint verifies — never
 * the raw actor string an app might hand-write, which would lock out even the owner (the case-A trap,
 * evidence/0328).
 */
final class LiveRenderTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(\Milpa\Live\ValueObjects\ComponentContext::class) || ! class_exists(AuthContext::class)) {
            self::markTestSkipped('needs milpa/live and milpa/auth');
        }
    }

    public function testTheContextIsBornOwnedByTheVerifiedActor(): void
    {
        $request = (new ServerRequest('GET', '/live'))->withAttribute(
            AuthenticateMiddleware::ATTRIBUTE,
            AuthContext::authenticated(new Actor('rod', ActorType::Service, ['milpa:component:data-table:*'])),
        );

        $ctx = LiveRender::contextForRequest($request, 'ventas', route: '/live', meta: ['name' => 'ventas']);

        self::assertSame('ventas', $ctx->componentId);
        self::assertSame('actor:rod', $ctx->principal, 'the owner is the id the endpoint matches, not the raw actor string');
        self::assertNotSame('rod', $ctx->principal, 'never the hand-writable literal that would lock out the owner (evidence/0328)');
        self::assertSame('/live', $ctx->route);
        self::assertSame(['name' => 'ventas'], $ctx->meta);
    }

    public function testAnAnonymousRequestYieldsAnOwnerlessContext(): void
    {
        self::assertNull(LiveRender::contextForRequest(new ServerRequest('GET', '/live'), 'ventas')->principal);
        self::assertNull(LiveRender::contextForRequest(
            (new ServerRequest('GET', '/live'))->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::anonymous()),
            'ventas',
        )->principal);
    }
}
