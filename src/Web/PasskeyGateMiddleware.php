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

namespace Milpa\AppRuntime\Web;

use Milpa\Auth\AuthContext;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Auth\Http\StartSession;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The panel's door: one session, one scope, one middleware the app NAMES (greenhouse decisions/0206).
 *
 * Between «a passkey assertion in the browser» and «a request to `/milpa/admin` with a session that
 * carries `milpa.admin`» there was plumbing, not a missing login: {@see \Milpa\AppRuntime\Web\Controllers\PasskeyController}
 * already turns a recognized passkey into a `SessionRecord` and a cookie. What nothing did was READ
 * that cookie in front of a panel route and answer like a door instead of a stack trace —
 * `RequireScopeMiddleware` throws, and an uncaught `AuthException` is a 500. This middleware is the
 * whole answer, and the only thing `admin.middleware` needs to name; `milpa/admin` gains no dependency
 * on `milpa/auth` because identity lives where the ceremony lives, in {@see PasskeyPlugin}.
 *
 * The cookie is resolved by milpa/auth's own {@see StartSession} — composed, not copied — so there is
 * ONE authority for «cookie → AuthContext»: the store's fail-closed `read()` (an expired or revoked
 * session reads as absent) is the single «no session» signal. Then the door judges:
 *
 *   - no live session → a browser (GET that accepts `text/html`) is sent to the sign-in page with
 *     `next` set to where it was going; anything else gets `401 {ok:false, error:'unauthenticated'}`;
 *   - a session without the scope → `403`: a shell-less page naming the principal and the scope for a
 *     browser, `{ok:false, error:'scope_denied', scope}` for everyone else — authenticated is not
 *     authorized, and the page says which one failed;
 *   - a session with the scope → the request goes on carrying the {@see AuthContext} under
 *     {@see AuthenticateMiddleware::ATTRIBUTE}, exactly where the rest of the auth pipeline reads it.
 *
 * The scope check honors the `*` wildcard the way milpa/auth's guards do (`Actor::hasScope`): a root
 * enrolled by `identity:bootstrap` with `['*']` opens the panel. The session id is never rendered,
 * logged or echoed — only the principal (`passkey:<credential id>`) appears on the 403 page.
 */
final class PasskeyGateMiddleware implements MiddlewareInterface
{
    public const DEFAULT_SIGNIN_PATH = '/webauthn/signin';

    private readonly StartSession $session;

    /**
     * @param SessionStore $sessions   where the ceremony wrote the session — the same store {@see PasskeyPlugin} hands `PasskeyLogin`
     * @param string       $cookieName the cookie the session id travels in (`passkey.cookie`)
     * @param string       $scope      the ONE scope the panel requires (`passkey.gate.scope`, default `milpa.admin`)
     * @param string       $signinPath where a browser without a session is sent
     */
    public function __construct(
        SessionStore $sessions,
        private readonly string $cookieName,
        private readonly string $scope,
        private readonly string $signinPath = self::DEFAULT_SIGNIN_PATH,
    ) {
        $this->session = new StartSession($sessions, $cookieName);
    }

    /** Resolve the session cookie through StartSession, then admit, redirect or refuse. */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $gate = $this;
        $judge = new class ($gate, $handler) implements RequestHandlerInterface {
            public function __construct(
                private readonly PasskeyGateMiddleware $gate,
                private readonly RequestHandlerInterface $handler,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->gate->judge($request, $this->handler);
            }
        };

        return $this->session->process($request, $judge);
    }

    /**
     * The decision, once StartSession attached the context: continue, send to sign-in, or refuse.
     *
     * Public only because the inner handler in {@see process()} calls it; it is not a second door.
     */
    public function judge(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
        if (!$context instanceof AuthContext || !$context->isAuthenticated() || $context->actor === null) {
            return $this->unauthenticated($request);
        }
        if (!$context->actor->hasScope($this->scope)) {
            return $this->denied($request, $context->actor->id);
        }

        return $handler->handle($request);
    }

    /** The name of the cookie this gate reads — for a host that wants to declare the same one elsewhere. */
    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /** The scope this gate requires. */
    public function scope(): string
    {
        return $this->scope;
    }

    private function unauthenticated(ServerRequestInterface $request): ResponseInterface
    {
        if (self::wantsHtml($request)) {
            return new Response(302, [
                'Location' => $this->signinUrl($request),
                'Cache-Control' => 'no-store',
            ]);
        }

        return self::json(401, ['ok' => false, 'error' => 'unauthenticated', 'signin' => $this->signinPath]);
    }

    private function denied(ServerRequestInterface $request, string $principal): ResponseInterface
    {
        if (self::wantsHtml($request)) {
            return new Response(
                403,
                ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
                $this->deniedHtml($principal, $this->signinUrl($request)),
            );
        }

        return self::json(403, ['ok' => false, 'error' => 'scope_denied', 'scope' => $this->scope]);
    }

    /** The sign-in page with `next` pointing back here — path and query, validated as a local path. */
    private function signinUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath() === '' ? '/' : $uri->getPath();
        $next = LocalPath::orRoot($path . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()));

        return $this->signinPath . '?next=' . rawurlencode($next);
    }

    /** A browser navigating: GET with an Accept that names text/html. A fetch() for JSON is not one. */
    private static function wantsHtml(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'GET'
            && str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html');
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            (string) json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    }

    private function deniedHtml(string $principal, string $signinUrl): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        // Shell-less on purpose: the panel's chrome belongs to the panel, and this page exists precisely
        // because the panel refused to open. It names the principal so the human knows WHICH passkey
        // answered, and the scope so they know what it lacks; the session id never appears.
        return '<!doctype html>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Scope not granted</title>'
            . '<style>'
            . 'body { font: 15px/1.5 system-ui, sans-serif; max-width: 34rem; margin: 4rem auto; padding: 0 1rem; color: #1a1a1a; }'
            . 'code { background: #f3f4f6; border-radius: 4px; padding: .1rem .3rem; word-break: break-all; }'
            . 'a { color: #111; }'
            . '</style>'
            . '<h1>Authenticated, but the scope <code>' . $e($this->scope) . '</code> is not granted</h1>'
            . '<p>Signed in as <code>' . $e($principal) . '</code>. This passkey is recognized by the house, '
            . 'but its enrollment does not carry the scope this panel requires.</p>'
            . '<p><a href="' . $e($signinUrl) . '">Use another passkey</a></p>';
    }
}
