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

use Milpa\AppRuntime\Web\PasskeyGateMiddleware;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\SessionRecord;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The panel's door (greenhouse decisions/0206): no session → a browser is sent to sign in, a client
 * gets 401; a session without the scope → 403, said plainly; a session with it → the handler runs with
 * the AuthContext attached. The cookie is only ever a key into the store — never trusted on its own.
 */
final class PasskeyGateMiddlewareTest extends TestCase
{
    private const COOKIE = 'milpa_session';
    private const SCOPE = 'milpa.admin';
    private const NOW = '2026-09-04T12:00:00+00:00';

    private InMemorySessionStore $sessions;

    private FileEnrollmentStore $enrollments;

    protected function setUp(): void
    {
        $this->sessions = new InMemorySessionStore(static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::NOW));
        // The gate re-reads the recognition ledger on every request (greenhouse decisions/0206): the
        // session's passkey `cred-1` is enrolled here, and a test revokes it to watch the door close.
        $this->enrollments = new FileEnrollmentStore(sys_get_temp_dir() . '/milpa-gate-' . bin2hex(random_bytes(6)) . '.json');
        $this->enrollments->record(new IdentityEnrolled('cred-1', ['milpa.admin'], 'key:TEST'));
    }

    /** `identity:revoke` closes a live panel on its next click — a TTL is not a revocation. */
    public function testARevokedPasskeyIsRefusedOnTheNextRequestAndItsSessionDies(): void
    {
        $id = $this->session([self::SCOPE]);
        $signedIn = $this->browserGet('/milpa/admin')->withCookieParams([self::COOKIE => $id]);
        self::assertSame(200, $this->gate()->process($signedIn, $this->handler())->getStatusCode(), 'enrolled: through');

        self::assertTrue($this->enrollments->revoke('cred-1', 'key:TEST'));
        $res = $this->gate()->process($signedIn, $this->handler());

        self::assertSame(403, $res->getStatusCode());
        self::assertNull($this->sessions->read($id), 'the session is destroyed with the recognition');
        self::assertStringContainsString('Max-Age=0', $res->getHeaderLine('Set-Cookie'), 'and the cookie is told to expire');
    }

    public function testABrowserWithoutASessionIsSentToSignInWithNext(): void
    {
        $res = $this->gate()->process($this->browserGet('/milpa/admin?tab=routes'), $this->handler());

        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/webauthn/signin?next=' . rawurlencode('/milpa/admin?tab=routes'), $res->getHeaderLine('Location'));
        self::assertSame('no-store', $res->getHeaderLine('Cache-Control'));
    }

    public function testAClientWithoutASessionGets401Json(): void
    {
        $req = (new ServerRequest('GET', '/milpa/admin'))->withHeader('Accept', 'application/json');

        $res = $this->gate()->process($req, $this->handler());
        $body = json_decode((string) $res->getBody(), true);

        self::assertSame(401, $res->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'unauthenticated', 'signin' => '/webauthn/signin'], $body);
        self::assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
    }

    public function testOnlyAGetThatAcceptsHtmlIsRedirected(): void
    {
        // A POST that says it accepts HTML is not a navigation: it gets the JSON refusal, not a redirect
        // that would turn its body into nothing.
        $req = (new ServerRequest('POST', '/milpa/admin'))->withHeader('Accept', 'text/html');

        self::assertSame(401, $this->gate()->process($req, $this->handler())->getStatusCode());
    }

    public function testACookieTheStoreDoesNotKnowIsNoSession(): void
    {
        // The cookie value is never trusted: a session id nobody minted is exactly like no cookie.
        $req = $this->browserGet('/milpa/admin')->withCookieParams([self::COOKIE => str_repeat('f', 64)]);

        $res = $this->gate()->process($req, $this->handler());

        self::assertSame(302, $res->getStatusCode());
        self::assertStringNotContainsString(str_repeat('f', 64), $res->getHeaderLine('Location'), 'the id is never echoed');
    }

    public function testAnExpiredSessionIsNoSession(): void
    {
        $id = $this->session([self::SCOPE], expired: true);

        $res = $this->gate()->process($this->browserGet('/milpa/admin')->withCookieParams([self::COOKIE => $id]), $this->handler());

        self::assertSame(302, $res->getStatusCode());
    }

    public function testASessionWithoutTheScopeIsRefusedWithAnHtmlPageForABrowser(): void
    {
        $id = $this->session(['agent:read']);

        $res = $this->gate()->process($this->browserGet('/milpa/admin?tab=x')->withCookieParams([self::COOKIE => $id]), $this->handler());
        $html = (string) $res->getBody();

        self::assertSame(403, $res->getStatusCode());
        self::assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Authenticated, but the scope <code>milpa.admin</code> is not granted', $html);
        self::assertStringContainsString('passkey:cred-1', $html, 'the principal is named');
        self::assertStringContainsString('Use another passkey', $html);
        self::assertStringContainsString('href="/webauthn/signin?next=' . htmlspecialchars(rawurlencode('/milpa/admin?tab=x')) . '"', $html);
        self::assertStringNotContainsString($id, $html, 'the session id is never rendered');
    }

    public function testASessionWithoutTheScopeIsRefusedWithJsonForAClient(): void
    {
        $id = $this->session(['agent:read']);
        $req = (new ServerRequest('GET', '/milpa/admin'))->withHeader('Accept', 'application/json')->withCookieParams([self::COOKIE => $id]);

        $res = $this->gate()->process($req, $this->handler());

        self::assertSame(403, $res->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'scope_denied', 'scope' => self::SCOPE], json_decode((string) $res->getBody(), true));
    }

    public function testASessionWithTheScopeReachesTheHandlerCarryingTheAuthContext(): void
    {
        $id = $this->session(['agent:read', self::SCOPE]);
        $seen = null;
        $handler = $this->handler(static function (ServerRequestInterface $req) use (&$seen): void {
            $seen = $req->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
        });

        $res = $this->gate()->process($this->browserGet('/milpa/admin')->withCookieParams([self::COOKIE => $id]), $handler);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('handled', (string) $res->getBody());
        self::assertInstanceOf(AuthContext::class, $seen);
        self::assertTrue($seen->isAuthenticated());
        self::assertNotNull($seen->actor);
        self::assertSame('passkey:cred-1', $seen->actor->id);
        self::assertSame(ActorType::User, $seen->actor->type);
        self::assertSame(['agent:read', self::SCOPE], $seen->actor->scopes);
    }

    public function testTheWildcardScopeOpensThePanelAsMilpaAuthGuardsWouldAdmitIt(): void
    {
        // identity:bootstrap enrolls the first signer with ['*']; the gate honours it like RequireScopeMiddleware does.
        $id = $this->session(['*']);

        $res = $this->gate()->process($this->browserGet('/milpa/admin')->withCookieParams([self::COOKIE => $id]), $this->handler());

        self::assertSame(200, $res->getStatusCode());
    }

    public function testTheSignInPathAndTheScopeAreTheGatesOwn(): void
    {
        $gate = new PasskeyGateMiddleware($this->sessions, $this->enrollments, 'other_cookie', 'ops.panel', '/auth/signin');

        self::assertSame('other_cookie', $gate->cookieName());
        self::assertSame('ops.panel', $gate->scope());

        $res = $gate->process($this->browserGet('/x'), $this->handler());
        self::assertSame('/auth/signin?next=%2Fx', $res->getHeaderLine('Location'));

        // The cookie name matters: a session under the default name is invisible to a gate reading another.
        $id = $this->session(['ops.panel']);
        self::assertSame(302, $gate->process($this->browserGet('/x')->withCookieParams([self::COOKIE => $id]), $this->handler())->getStatusCode());
        self::assertSame(200, $gate->process($this->browserGet('/x')->withCookieParams(['other_cookie' => $id]), $this->handler())->getStatusCode());
    }

    public function testAnEmptyPathRedirectsWithNextAtTheRoot(): void
    {
        $res = $this->gate()->process((new ServerRequest('GET', ''))->withHeader('Accept', 'text/html'), $this->handler());

        self::assertSame('/webauthn/signin?next=%2F', $res->getHeaderLine('Location'));
    }

    // --- helpers ---

    private function gate(): PasskeyGateMiddleware
    {
        return new PasskeyGateMiddleware($this->sessions, $this->enrollments, self::COOKIE, self::SCOPE);
    }

    private function browserGet(string $target): ServerRequest
    {
        return (new ServerRequest('GET', $target))->withHeader('Accept', 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8');
    }

    /** @param list<string> $scopes */
    private function session(array $scopes, bool $expired = false): string
    {
        $now = new \DateTimeImmutable(self::NOW);
        $id = bin2hex(random_bytes(32));
        $this->sessions->write(new SessionRecord(
            id: $id,
            actorId: 'passkey:cred-1',
            actorType: ActorType::User,
            createdAt: $now->sub(new \DateInterval('PT2H')),
            expiresAt: $expired ? $now->sub(new \DateInterval('PT1H')) : $now->add(new \DateInterval('PT1H')),
            scopes: $scopes,
        ));

        return $id;
    }

    /** @param (callable(ServerRequestInterface): void)|null $spy */
    private function handler(?callable $spy = null): RequestHandlerInterface
    {
        return new class ($spy) implements RequestHandlerInterface {
            /** @param (callable(ServerRequestInterface): void)|null $spy */
            public function __construct(private $spy)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->spy !== null) {
                    ($this->spy)($request);
                }

                return new Response(200, [], 'handled');
            }
        };
    }
}
