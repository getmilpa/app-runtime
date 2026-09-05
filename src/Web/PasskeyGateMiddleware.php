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

use Milpa\AppRuntime\Identity\EnrollmentStore;
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
 * session reads as absent) is the single «no session» signal. Since greenhouse decisions/0208 that
 * resolution, together with the revocation check, lives in {@see PasskeySessionResolver} and is shared
 * with {@see PasskeySessionMiddleware} — the operations surface and this door cannot disagree on what
 * a cookie is worth. Then the door judges:
 *
 *   - no live session → a browser (a GET or HEAD that accepts `text/html`) is sent to the sign-in page
 *     with `next` set to where it was going; anything else gets `401 {ok:false, error:'unauthenticated'}`;
 *   - a session whose passkey the enrollment ledger no longer recognizes → `403`, and the session is
 *     DESTROYED: revocation is immediate, not «when the TTL runs out». The ledger is re-read on every
 *     request — `identity:revoke` closes a live panel on its next click (`error:'not_enrolled'` for a
 *     client; a page saying the passkey is no longer recognised for a browser);
 *   - a session without the scope → `403`: a shell-less page naming the principal and the scope for a
 *     browser (wireframe 2j), `{ok:false, error:'scope_denied', scope}` for everyone else —
 *     authenticated is not authorized, and the page says which one failed;
 *   - a session with the scope → the request goes on carrying the {@see AuthContext} under
 *     {@see AuthenticateMiddleware::ATTRIBUTE}, exactly where the rest of the auth pipeline reads it.
 *
 * The credential the ledger is asked about is derived from the principal the ceremony minted
 * (`passkey:<credential id>`); a session under this cookie whose principal is not a passkey's is not
 * this door's session — the resolver reports it FOREIGN and touches nothing, and this door refuses it
 * and closes it the same way it does a revoked one. The scope check honors the `*`
 * wildcard the way milpa/auth's guards do (`Actor::hasScope`): a root enrolled by `identity:bootstrap`
 * with `['*']` opens the panel. The session id is never rendered, logged or echoed — only the
 * principal appears on the refusal pages.
 */
final class PasskeyGateMiddleware implements MiddlewareInterface
{
    public const DEFAULT_SIGNIN_PATH = '/webauthn/signin';

    /** The prefix {@see \Milpa\Auth\WebAuthn\VerifiedPasskey::principal()} puts before the credential id. */
    public const PRINCIPAL_PREFIX = PasskeySessionResolver::PRINCIPAL_PREFIX;

    private readonly PasskeySessionResolver $resolver;

    /**
     * @param SessionStore    $sessions    where the ceremony wrote the session — the same store {@see PasskeyPlugin} hands `PasskeyLogin`
     * @param EnrollmentStore $enrollments the recognition ledger, re-read on every request so a revocation lands at once
     * @param string          $cookieName  the cookie the session id travels in (`passkey.cookie`)
     * @param string          $scope       the ONE scope the panel requires (`passkey.gate.scope`, default `milpa.admin`)
     * @param string          $signinPath  where a browser without a session is sent
     */
    public function __construct(
        SessionStore $sessions,
        EnrollmentStore $enrollments,
        private readonly string $cookieName,
        private readonly string $scope,
        private readonly string $signinPath = self::DEFAULT_SIGNIN_PATH,
    ) {
        $this->resolver = new PasskeySessionResolver($sessions, $enrollments, $cookieName);
    }

    /** Resolve the session cookie (StartSession + the ledger), then admit, redirect or refuse. */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $resolved = $this->resolver->resolve($request);
        if ($resolved->isRevoked()) {
            return $this->revoked($request, (string) $resolved->revokedPrincipal);
        }

        $actor = $resolved->context->actor;
        if (!$resolved->isLive() || $actor === null) {
            return $this->unauthenticated($request);
        }

        // NOT THIS DOOR'S SESSION: a principal the ledger never enrolled cannot open the panel, whatever
        // scopes it carries. The resolver left it alone (the ledger has no say over it); this door
        // closes it, as it always did, and refuses like it does a passkey that is no longer recognised.
        if ($resolved->isForeign()) {
            $this->resolver->close($request);

            return $this->revoked($request, $actor->id);
        }
        if (!$actor->hasScope($this->scope)) {
            return $this->denied($request, $actor->id);
        }

        return $handler->handle($request->withAttribute(AuthenticateMiddleware::ATTRIBUTE, $resolved->context));
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

    /** The passkey is no longer recognized: the resolver already closed the session; clear the cookie, refuse. */
    private function revoked(ServerRequestInterface $request, string $principal): ResponseInterface
    {
        $expire = $this->resolver->expiringCookie($request);

        if (self::wantsHtml($request)) {
            return new Response(
                403,
                ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Set-Cookie' => $expire],
                $this->revokedHtml($principal, $this->signinUrl($request)),
            );
        }

        return self::json(403, ['ok' => false, 'error' => 'not_enrolled'])->withHeader('Set-Cookie', $expire);
    }

    /** The sign-in page with `next` pointing back here — path and query, validated as a local path. */
    private function signinUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath() === '' ? '/' : $uri->getPath();
        $next = LocalPath::orRoot($path . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()));

        return $this->signinPath . '?next=' . rawurlencode($next);
    }

    /** A browser navigating: GET or HEAD with an Accept that names text/html. A fetch() for JSON is not one. */
    private static function wantsHtml(ServerRequestInterface $request): bool
    {
        return \in_array($request->getMethod(), ['GET', 'HEAD'], true)
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
        $scope = $e($this->scope);

        // Shell-less on purpose: the panel's chrome belongs to the panel, and this page exists precisely
        // because the panel refused to open. The copy is wireframe 2j's, verbatim. It names the
        // principal so the human knows WHICH passkey answered; the session id never appears.
        return self::page(
            '403 · forbidden',
            '<span class="badge">403 · forbidden</span>'
            . '<h1>Authenticated, but the scope <code>' . $scope . '</code> is not granted</h1>'
            . '<p>Your passkey is recognised. Without <code>' . $scope . '</code> no part of the panel opens'
            . ' — there are no per-section scopes.</p>'
            . '<p class="principal">Signed in as <code>' . $e($principal) . '</code></p>'
            . '<p><a href="' . $e($signinUrl) . '">Use another passkey</a></p>',
        );
    }

    private function revokedHtml(string $principal, string $signinUrl): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return self::page(
            '403 · forbidden',
            '<span class="badge">403 · forbidden</span>'
            . '<h1>This passkey is no longer recognised</h1>'
            . '<p>Its enrollment was revoked, or was never made. The session has been closed'
            . ' — without a live enrollment no part of the panel opens.</p>'
            . '<p class="principal">Was signed in as <code>' . $e($principal) . '</code></p>'
            . '<p><a href="' . $e($signinUrl) . '">Use another passkey</a></p>',
        );
    }

    /** The shared skeleton of the door's pages: the brand line, then the body. */
    private static function page(string $title, string $body): string
    {
        return '<!doctype html>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title>'
            . '<style>'
            . 'body { font: 15px/1.5 system-ui, sans-serif; max-width: 34rem; margin: 4rem auto; padding: 0 1rem; color: #1a1a1a; }'
            . '.brand { font-weight: 600; letter-spacing: .02em; margin: 0 0 1.5rem; }'
            . '.badge { display: inline-block; font: 600 12px/1 ui-monospace, monospace; padding: .3rem .5rem; border: 1px solid #b4472e; border-radius: 4px; color: #b4472e; }'
            . 'h1 { font-size: 1.25rem; margin: .75rem 0 .5rem; }'
            . 'code { background: #f3f4f6; border-radius: 4px; padding: .1rem .3rem; word-break: break-all; }'
            . '.principal { color: #4b5563; }'
            . 'a { color: #111; }'
            . '</style>'
            . '<p class="brand">Milpa Admin</p>'
            . $body;
    }
}
