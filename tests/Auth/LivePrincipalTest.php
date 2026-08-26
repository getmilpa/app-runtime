<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Auth;

use Milpa\AppRuntime\Auth\LivePrincipal;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * ONE identity for the live wire (greenhouse decisions/0083): the actor milpa/auth verified is the
 * principal a live action runs as; anonymous, invalid or missing contexts yield no principal at all.
 */
final class LivePrincipalTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(\Milpa\Live\ValueObjects\SecurityPrincipal::class) || ! class_exists(AuthContext::class)) {
            self::markTestSkipped('needs milpa/live-web and milpa/auth');
        }
    }

    public function testTheVerifiedActorBecomesThePrincipalWithItsScopes(): void
    {
        $request = (new ServerRequest('POST', '/live'))->withAttribute(
            AuthenticateMiddleware::ATTRIBUTE,
            AuthContext::authenticated(new Actor('rod', ActorType::Service, ['agent:answer', 'live:act'])),
        );

        $principal = LivePrincipal::fromRequest($request);

        self::assertNotNull($principal);
        self::assertSame('actor:rod', $principal->id, 'the same `actor:<id>` the consent facts record');
        self::assertSame(['agent:answer', 'live:act'], $principal->scopes);
        self::assertTrue($principal->can('live:act'));
        self::assertSame('service', $principal->claims['type']);
    }

    public function testAnonymousInvalidOrMissingContextsYieldNoPrincipal(): void
    {
        self::assertNull(LivePrincipal::fromRequest(new ServerRequest('POST', '/live')), 'no auth middleware ran');
        self::assertNull(LivePrincipal::fromRequest((new ServerRequest('POST', '/live'))->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::anonymous())));
        self::assertNull(LivePrincipal::fromRequest((new ServerRequest('POST', '/live'))->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::invalid('rejected'))));
    }
}
