<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Web\PasskeySessionMiddleware;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\AuthState;
use Milpa\Auth\Contracts\AuthContextFactory;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\SessionRecord;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The passkey session as a principal of the operations surface (greenhouse decisions/0208), its four
 * falsifiers: PRECEDENCE (a Bearer verdict — authenticated or invalid — is never touched), the cookie
 * → `passkey:<id>` with the enrollment's scopes, REVOCATION PARITY (revoked → anonymous, the session
 * dies, the cookie expires, no error), and the CSRF POSTURE (a mutating request authenticates from the
 * cookie only as same-origin JSON; a safe method always). Plus the factory face: `fromRequest()` says
 * the same thing the middleware would attach.
 */
final class PasskeySessionMiddlewareTest extends TestCase
{
    private const COOKIE = 'milpa_session';
    private const NOW = '2026-09-04T12:00:00+00:00';

    private InMemorySessionStore $sessions;

    private FileEnrollmentStore $enrollments;

    protected function setUp(): void
    {
        $this->sessions = new InMemorySessionStore(static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::NOW));
        $this->enrollments = new FileEnrollmentStore(sys_get_temp_dir() . '/milpa-psm-' . bin2hex(random_bytes(6)) . '.json');
        $this->enrollments->record(new IdentityEnrolled('cred-1', ['agent:run'], 'key:TEST'));
    }

    public function testItIsAMiddlewareAndAnAuthContextFactoryAtOnce(): void
    {
        $middleware = $this->middleware();

        self::assertInstanceOf(MiddlewareInterface::class, $middleware);
        self::assertInstanceOf(AuthContextFactory::class, $middleware);
        self::assertSame(self::COOKIE, $middleware->cookieName());
    }

    // --- precedence: the Bearer decides ---

    public function testAnAuthenticatedBearerContextPassesThroughUntouchedEvenWithAValidCookie(): void
    {
        $bearer = AuthContext::authenticated(new Actor('token:ci', ActorType::Service, ['plugins:read']));
        $req = $this->get()->withCookieParams([self::COOKIE => $this->session(['agent:run'])])
            ->withAttribute(AuthenticateMiddleware::ATTRIBUTE, $bearer);

        $res = $this->middleware()->process($req, $this->handler($seen));

        self::assertSame(200, $res->getStatusCode());
        self::assertSame($bearer, $seen, 'the very same context object: the cookie never spoke');
        self::assertSame('', $res->getHeaderLine('Set-Cookie'));
    }

    public function testAnInvalidBearerContextIsNeverLaunderedByACookie(): void
    {
        $rejected = AuthContext::invalid('token revoked');
        $req = $this->get()->withCookieParams([self::COOKIE => $this->session(['agent:run'])])
            ->withAttribute(AuthenticateMiddleware::ATTRIBUTE, $rejected);

        $this->middleware()->process($req, $this->handler($seen));

        self::assertSame($rejected, $seen);
        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertSame(AuthState::Invalid, $seen->state, 'a rejected Bearer stays rejected');
        self::assertNull($seen->actor);
    }

    public function testABearerVerdictLeavesTheStoreAloneEvenWhenTheCookieWouldHaveDied(): void
    {
        // The control for «untouched»: a revoked passkey's session dies on any read. With an invalid
        // Bearer standing, the cookie is not even read — so the session is still there afterwards.
        $id = $this->session(['agent:run']);
        $this->enrollments->revoke('cred-1', 'key:TEST');
        $req = $this->get()->withCookieParams([self::COOKIE => $id])
            ->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::invalid('token revoked'));

        $res = $this->middleware()->process($req, $this->handler($seen));

        self::assertNotNull($this->sessions->read($id), 'the store was not read, so nothing was destroyed');
        self::assertSame('', $res->getHeaderLine('Set-Cookie'));
        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertSame(AuthState::Invalid, $seen->state);
    }

    public function testAnAnonymousStandingContextLetsTheCookieSpeak(): void
    {
        // AuthenticateMiddleware attaches anonymous when there is no Bearer at all: that is not a verdict.
        $req = $this->get()->withCookieParams([self::COOKIE => $this->session(['agent:run'])])
            ->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::anonymous());

        $this->middleware()->process($req, $this->handler($seen));

        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertTrue($seen->isAuthenticated());
    }

    // --- the cookie → the passkey principal, with the enrollment's scopes ---

    public function testTheCookieAuthenticatesThePasskeyPrincipalWithItsScopes(): void
    {
        $req = $this->get()->withCookieParams([self::COOKIE => $this->session(['agent:run', 'agent:read'])]);

        $res = $this->middleware()->process($req, $this->handler($seen));

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('handled', (string) $res->getBody());
        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertTrue($seen->isAuthenticated());
        self::assertNotNull($seen->actor);
        self::assertSame('passkey:cred-1', $seen->actor->id);
        self::assertSame(ActorType::User, $seen->actor->type);
        self::assertSame(['agent:run', 'agent:read'], $seen->actor->scopes);
        self::assertTrue($seen->hasScope('agent:run'));
        self::assertSame('', $res->getHeaderLine('Set-Cookie'), 'a live session sets nothing');
    }

    public function testWithoutACookieTheRequestGoesOnAnonymous(): void
    {
        $res = $this->middleware()->process($this->get(), $this->handler($seen));

        self::assertSame(200, $res->getStatusCode(), 'never a refusal from here: the guard downstream decides');
        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertSame(AuthState::Anonymous, $seen->state);
        self::assertSame('', $res->getHeaderLine('Set-Cookie'));
    }

    public function testACookieTheStoreDoesNotKnowAndAnExpiredSessionAreAnonymous(): void
    {
        $this->middleware()->process($this->get()->withCookieParams([self::COOKIE => str_repeat('f', 64)]), $this->handler($forged));
        $this->middleware()->process($this->get()->withCookieParams([self::COOKIE => $this->session(['agent:run'], expired: true)]), $this->handler($expired));

        self::assertInstanceOf(AuthContext::class, $forged);
        self::assertSame(AuthState::Anonymous, $forged->state);
        self::assertInstanceOf(AuthContext::class, $expired);
        self::assertSame(AuthState::Anonymous, $expired->state);
    }

    // --- revocation parity with the panel's door ---

    public function testARevokedPasskeyGoesOnAnonymousItsSessionDiesAndTheCookieExpires(): void
    {
        $id = $this->session(['agent:run']);
        $req = $this->get()->withCookieParams([self::COOKIE => $id]);
        $this->middleware()->process($req, $this->handler($before));
        self::assertInstanceOf(AuthContext::class, $before);
        self::assertTrue($before->isAuthenticated(), 'enrolled: the principal');

        self::assertTrue($this->enrollments->revoke('cred-1', 'key:TEST'));
        $res = $this->middleware()->process($req, $this->handler($after));

        self::assertSame(200, $res->getStatusCode(), 'no error here — the request goes on, anonymous');
        self::assertInstanceOf(AuthContext::class, $after);
        self::assertSame(AuthState::Anonymous, $after->state);
        self::assertNull($this->sessions->read($id), 'the session is destroyed with the recognition');
        self::assertStringContainsString(self::COOKIE . '=; Max-Age=0', $res->getHeaderLine('Set-Cookie'), 'and the cookie is told to expire');
    }

    public function testASessionOfAPrincipalThatIsNotAPasskeysIsAttachedAsItIsAndNeverDestroyed(): void
    {
        // Parity reaches as far as the ledger does. A host that mints its own sessions through
        // milpa/auth under the same cookie has principals the ledger never enrolled — `token:svc` here.
        // `scopesFor('token:svc')` is null, and that must NOT read as a revocation.
        $id = $this->session(['agent:run'], actorId: 'token:svc', actorType: ActorType::Service);
        $req = $this->get()->withCookieParams([self::COOKIE => $id]);

        $res = $this->middleware()->process($req, $this->handler($seen));

        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertTrue($seen->isAuthenticated(), 'what StartSession resolved is what the request carries');
        self::assertSame('token:svc', $seen->actor?->id);
        self::assertSame(ActorType::Service, $seen->actor?->type);
        self::assertSame(['agent:run'], $seen->actor?->scopes);
        self::assertNotNull($this->sessions->read($id), 'the ledger has no say over it: nothing destroyed');
        self::assertSame('', $res->getHeaderLine('Set-Cookie'), 'and nothing expired');

        $factory = $this->middleware()->fromRequest($req);
        self::assertSame('token:svc', $factory->actor?->id, 'the factory face says the same');
        self::assertNotNull($this->sessions->read($id));
    }

    public function testTheExpiringCookieIsAddedToTheHandlersOwnCookies(): void
    {
        $id = $this->session(['agent:run']);
        $this->enrollments->revoke('cred-1', 'key:TEST');
        $handler = $this->handler($seen, static fn (): ResponseInterface => new Response(200, ['Set-Cookie' => 'other=1; Path=/'], 'handled'));

        $res = $this->middleware()->process($this->get()->withCookieParams([self::COOKIE => $id]), $handler);

        // Added, not replaced — and with SessionCookie's attributes for this origin (no host here, so not
        // loopback, so `Secure`): the same rule the ceremony and the panel's door apply.
        self::assertSame(['other=1; Path=/', self::COOKIE . '=; Max-Age=0; HttpOnly; SameSite=Strict; Path=/; Secure'], $res->getHeader('Set-Cookie'));
    }

    // --- the CSRF posture ---

    public function testAMutatingRequestWithAFormContentTypeIgnoresTheCookie(): void
    {
        $req = (new ServerRequest('POST', '/agent'))->withHeader('Content-Type', 'text/plain')
            ->withCookieParams([self::COOKIE => $this->session(['agent:run'])]);

        $res = $this->middleware()->process($req, $this->handler($seen));

        self::assertSame(200, $res->getStatusCode(), 'ignored, not refused');
        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertSame(AuthState::Anonymous, $seen->state);
    }

    public function testAMutatingJsonRequestAuthenticatesFromTheCookie(): void
    {
        $req = (new ServerRequest('POST', '/agent'))->withHeader('Content-Type', 'application/json')
            ->withCookieParams([self::COOKIE => $this->session(['agent:run'])]);

        $this->middleware()->process($req, $this->handler($seen));

        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertTrue($seen->isAuthenticated());
        self::assertSame('passkey:cred-1', $seen->actor?->id);
    }

    public function testTheMediaTypeMayCarryParameters(): void
    {
        $req = (new ServerRequest('POST', '/agent'))->withHeader('Content-Type', 'Application/JSON; charset=utf-8')
            ->withCookieParams([self::COOKIE => $this->session(['agent:run'])]);

        $this->middleware()->process($req, $this->handler($seen));

        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertTrue($seen->isAuthenticated());
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function secFetchSites(): iterable
    {
        yield 'same-origin' => ['same-origin', true];
        yield 'none (a navigation the user typed)' => ['none', true];
        yield 'same-site (a sibling subdomain)' => ['same-site', false];
        yield 'cross-site' => ['cross-site', false];
    }

    #[DataProvider('secFetchSites')]
    public function testSecFetchSiteDecidesAMutatingJsonRequest(string $site, bool $authenticated): void
    {
        $req = (new ServerRequest('POST', '/agent'))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Sec-Fetch-Site', $site)
            ->withCookieParams([self::COOKIE => $this->session(['agent:run'])]);

        $this->middleware()->process($req, $this->handler($seen));

        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertSame($authenticated, $seen->isAuthenticated(), "Sec-Fetch-Site: {$site}");
    }

    /** @return iterable<string, array{0: string}> */
    public static function mutatingMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('mutatingMethods')]
    public function testEveryMutatingMethodHoldsThePosture(string $method): void
    {
        $id = $this->session(['agent:run']);
        $form = (new ServerRequest($method, '/x'))->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withCookieParams([self::COOKIE => $id]);
        $json = (new ServerRequest($method, '/x'))->withHeader('Content-Type', 'application/json')->withCookieParams([self::COOKIE => $id]);

        $this->middleware()->process($form, $this->handler($fromForm));
        $this->middleware()->process($json, $this->handler($fromJson));

        self::assertInstanceOf(AuthContext::class, $fromForm);
        self::assertSame(AuthState::Anonymous, $fromForm->state, "{$method} as a form: ignored");
        self::assertInstanceOf(AuthContext::class, $fromJson);
        self::assertTrue($fromJson->isAuthenticated(), "{$method} as JSON: the principal");
    }

    /** @return iterable<string, array{0: string}> */
    public static function safeMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    #[DataProvider('safeMethods')]
    public function testASafeMethodAuthenticatesFromTheCookieWhateverTheHeadersSay(string $method): void
    {
        $req = (new ServerRequest($method, '/agent/sessions'))
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->withHeader('Content-Type', 'text/plain')
            ->withCookieParams([self::COOKIE => $this->session(['agent:run'])]);

        $this->middleware()->process($req, $this->handler($seen));

        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertTrue($seen->isAuthenticated(), "{$method} reads nothing that changes state");
    }

    public function testAnIgnoredCookieIsNotEvenReadSoACrossSitePostCannotCloseASession(): void
    {
        // The control for «ignored»: a revoked session would be destroyed on any read. A cross-site
        // POST carrying it must leave the store exactly as it was.
        $id = $this->session(['agent:run']);
        $this->enrollments->revoke('cred-1', 'key:TEST');
        $req = (new ServerRequest('POST', '/agent'))->withHeader('Content-Type', 'application/json')
            ->withHeader('Sec-Fetch-Site', 'cross-site')->withCookieParams([self::COOKIE => $id]);

        $res = $this->middleware()->process($req, $this->handler($seen));

        self::assertNotNull($this->sessions->read($id), 'the store was not touched');
        self::assertSame('', $res->getHeaderLine('Set-Cookie'));
        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertSame(AuthState::Anonymous, $seen->state);
    }

    // --- the factory face ---

    public function testFromRequestSaysWhatTheMiddlewareWouldAttach(): void
    {
        $factory = $this->middleware();
        $id = $this->session(['agent:run']);

        $live = $factory->fromRequest($this->get()->withCookieParams([self::COOKIE => $id]));
        self::assertTrue($live->isAuthenticated());
        self::assertSame('passkey:cred-1', $live->actor?->id);
        self::assertSame(['agent:run'], $live->actor?->scopes);

        $ignored = $factory->fromRequest((new ServerRequest('POST', '/agent'))->withHeader('Content-Type', 'text/plain')->withCookieParams([self::COOKIE => $id]));
        self::assertSame(AuthState::Anonymous, $ignored->state, 'the posture holds at the factory too');

        $rejected = AuthContext::invalid('bad token');
        self::assertSame($rejected, $factory->fromRequest($this->get()->withCookieParams([self::COOKIE => $id])->withAttribute(AuthenticateMiddleware::ATTRIBUTE, $rejected)), 'precedence holds at the factory too');

        self::assertSame(AuthState::Anonymous, $factory->fromRequest($this->get())->state);
    }

    public function testFromRequestDestroysARevokedSessionAndAnswersAnonymous(): void
    {
        $id = $this->session(['agent:run']);
        $this->enrollments->revoke('cred-1', 'key:TEST');

        $context = $this->middleware()->fromRequest($this->get()->withCookieParams([self::COOKIE => $id]));

        self::assertSame(AuthState::Anonymous, $context->state);
        self::assertNull($this->sessions->read($id), 'parity: the session dies at the factory as at the door');
    }

    public function testTheCookieNameMatters(): void
    {
        $other = new PasskeySessionMiddleware($this->sessions, $this->enrollments, 'other_cookie');
        $id = $this->session(['agent:run']);

        $other->process($this->get()->withCookieParams([self::COOKIE => $id]), $this->handler($underDefault));
        $other->process($this->get()->withCookieParams(['other_cookie' => $id]), $this->handler($underOther));

        self::assertInstanceOf(AuthContext::class, $underDefault);
        self::assertSame(AuthState::Anonymous, $underDefault->state);
        self::assertInstanceOf(AuthContext::class, $underOther);
        self::assertTrue($underOther->isAuthenticated());
    }

    // --- helpers ---

    private function middleware(): PasskeySessionMiddleware
    {
        return new PasskeySessionMiddleware($this->sessions, $this->enrollments, self::COOKIE);
    }

    private function get(): ServerRequest
    {
        return (new ServerRequest('GET', '/agent/sessions'))->withHeader('Accept', 'application/json');
    }

    /** @param list<string> $scopes */
    private function session(array $scopes, bool $expired = false, string $actorId = 'passkey:cred-1', ActorType $actorType = ActorType::User): string
    {
        $now = new \DateTimeImmutable(self::NOW);
        $id = bin2hex(random_bytes(32));
        $this->sessions->write(new SessionRecord(
            id: $id,
            actorId: $actorId,
            actorType: $actorType,
            createdAt: $now->sub(new \DateInterval('PT2H')),
            expiresAt: $expired ? $now->sub(new \DateInterval('PT1H')) : $now->add(new \DateInterval('PT1H')),
            scopes: $scopes,
        ));

        return $id;
    }

    /**
     * A handler that records the AuthContext it was handed in `$seen` and answers 200 `handled`
     * (or whatever `$respond` builds).
     *
     * @param-out mixed $seen
     *
     * @param (callable(): ResponseInterface)|null $respond
     */
    private function handler(mixed &$seen, ?callable $respond = null): RequestHandlerInterface
    {
        $seen = null;
        $record = static function (ServerRequestInterface $req) use (&$seen): void {
            $seen = $req->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
        };

        return new class ($record, $respond) implements RequestHandlerInterface {
            /**
             * @param \Closure(ServerRequestInterface): void $record
             * @param (callable(): ResponseInterface)|null   $respond
             */
            public function __construct(private readonly \Closure $record, private $respond)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->record)($request);

                return $this->respond !== null ? ($this->respond)() : new Response(200, [], 'handled');
            }
        };
    }
}
