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
use Milpa\Auth\AuthContext;
use Milpa\Auth\AuthState;
use Milpa\Auth\Contracts\AuthContextFactory;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The passkey session is a principal of the operations surface (greenhouse decisions/0208).
 *
 * Before this, the session the passkey ceremony mints opened exactly one thing: the panel behind
 * {@see PasskeyGateMiddleware}. The operations served over HTTP (`POST /agent`, `agent:goal`, …) were
 * governed by the Bearer policy alone, and a browser that had just signed in with a key was, to them,
 * nobody. This middleware puts the SAME session on the request where the whole auth pipeline reads
 * identity — {@see AuthenticateMiddleware::ATTRIBUTE} — so `AuthOperationHttpPolicy` judges a passkey
 * actor exactly as it judges a token actor: by scopes. It sits in the chain right after
 * `AuthenticateMiddleware` and before the `RequestHandler`; `PasskeyPlugin` registers it under its own
 * class name AND under {@see AuthContextFactory} (the same instance), which is what tells the policy
 * an auth chain is installed when no token verifier is.
 *
 * PRECEDENCE — the Bearer decides. When the attribute already holds a context that is authenticated
 * OR invalid, the request passes through UNTOUCHED: a token that verified is the actor, and a token
 * that was REJECTED stays rejected — a cookie never launders an invalid Bearer into a session. Only an
 * absent or anonymous context (no Bearer at all) lets the cookie speak.
 *
 * REVOCATION PARITY — the cookie is worth exactly what it is worth at the panel's door: resolved by
 * milpa/auth's {@see \Milpa\Auth\Http\StartSession} through the shared {@see PasskeySessionResolver},
 * and re-checked against the enrollment ledger on every request. A session whose passkey is no longer
 * recognized is destroyed in the store, the response carries an expiring `Set-Cookie`, and the request
 * goes on ANONYMOUS — no error here; the operation's own policy answers 401 if it needed an actor.
 * Parity reaches exactly as far as the ledger does: a live session under the same cookie whose
 * principal is NOT a passkey's (`token:…`, `user:…` — one the host minted through milpa/auth) is
 * attached as the principal it is, untouched, precisely what milpa/auth's own `StartSession` would
 * have put there; its scopes, not the ledger, are what the policy then judges.
 *
 * CSRF POSTURE — a cookie travels with every request the browser makes, including the ones a foreign
 * page provokes; a Bearer does not. So the cookie authenticates a request with a mutating method
 * (POST, PUT, PATCH, DELETE — and, fail-closed, any method that is not GET/HEAD/OPTIONS) ONLY IF its
 * `Content-Type` is `application/json` (the media type; parameters such as `charset` are allowed — an
 * HTML form cannot send that type, and a cross-origin `fetch()` that does gets a preflight) AND, when a
 * `Sec-Fetch-Site` header is present, it says `same-origin` or `none`. Otherwise the cookie is IGNORED
 * — not even read from the store — and the request continues anonymous: the Bearer chain or the
 * operation's policy decides, never a 403 from here. GET, HEAD and OPTIONS authenticate from the cookie
 * unconditionally; they must not change state.
 *
 * As an {@see AuthContextFactory}, {@see self::fromRequest()} answers with the same context the
 * middleware would attach — precedence, posture and parity included — without touching a response: a
 * revoked session is still destroyed, and the caller simply gets anonymous.
 */
final class PasskeySessionMiddleware implements MiddlewareInterface, AuthContextFactory
{
    /** The methods a cookie authenticates unconditionally: by contract they do not change state. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** The only media type under which a mutating request may authenticate from the cookie. */
    private const JSON_MEDIA_TYPE = 'application/json';

    /** The `Sec-Fetch-Site` values that name a request this origin made itself. */
    private const OWN_ORIGIN_SITES = ['same-origin', 'none'];

    private readonly PasskeySessionResolver $resolver;

    /**
     * @param SessionStore    $sessions    where the ceremony wrote the session — the same store {@see PasskeyPlugin} hands `PasskeyLogin`
     * @param EnrollmentStore $enrollments the recognition ledger, re-read on every request so a revocation lands at once
     * @param string          $cookieName  the cookie the session id travels in (`passkey.cookie`)
     */
    public function __construct(SessionStore $sessions, EnrollmentStore $enrollments, string $cookieName)
    {
        $this->resolver = new PasskeySessionResolver($sessions, $enrollments, $cookieName);
    }

    /**
     * Attach the context the cookie is worth under {@see AuthenticateMiddleware::ATTRIBUTE} — unless
     * the Bearer already decided — and pass the request on. Never refuses: the guard downstream does.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (self::bearerDecided($request)) {
            return $handler->handle($request);
        }

        $resolved = $this->resolveCookie($request);
        $response = $handler->handle($request->withAttribute(AuthenticateMiddleware::ATTRIBUTE, $resolved->context));

        return $resolved->isRevoked()
            ? $response->withAddedHeader('Set-Cookie', $this->resolver->expiringCookie($request))
            : $response;
    }

    /**
     * The context this middleware would attach to `$request`: the standing Bearer context when it
     * decided, otherwise what the cookie is worth under the posture — anonymous when it may not speak.
     */
    public function fromRequest(ServerRequestInterface $request): AuthContext
    {
        if (self::bearerDecided($request)) {
            $standing = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);

            // A Bearer header nobody judged is not a session either: anonymous, never the cookie.
            return $standing instanceof AuthContext ? $standing : AuthContext::anonymous();
        }

        return $this->resolveCookie($request)->context;
    }

    /** The name of the cookie this middleware reads. */
    public function cookieName(): string
    {
        return $this->resolver->cookieName();
    }

    /**
     * Whether the Bearer channel owns this request, so the cookie must not speak: the attribute already
     * carries a verdict (authenticated or invalid), OR the request presented an `Authorization` header
     * at all. The second clause is what a house WITHOUT a token verifier needs (greenhouse
     * evidence/0521, F2): there nobody judges the header, so it never becomes «invalid» — and a caller
     * who chose the Bearer channel with a credential nobody can verify must be answered as a Bearer
     * caller (401 by the policy), not quietly admitted on a cookie it also happened to carry.
     */
    private static function bearerDecided(ServerRequestInterface $request): bool
    {
        if ($request->hasHeader('Authorization')) {
            return true;
        }
        $standing = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);

        return $standing instanceof AuthContext && $standing->state !== AuthState::Anonymous;
    }

    /** The cookie, resolved only when the posture lets it speak; ignored (anonymous, store untouched) otherwise. */
    private function resolveCookie(ServerRequestInterface $request): ResolvedPasskeySession
    {
        return self::cookieMaySpeak($request)
            ? $this->resolver->resolve($request)
            : ResolvedPasskeySession::none();
    }

    /** THE CSRF POSTURE, as one predicate: safe methods always; a mutating one only as same-origin JSON. */
    private static function cookieMaySpeak(ServerRequestInterface $request): bool
    {
        if (\in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return true;
        }

        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        if ($mediaType !== self::JSON_MEDIA_TYPE) {
            return false;
        }

        if (!$request->hasHeader('Sec-Fetch-Site')) {
            return true;
        }

        return \in_array(strtolower(trim($request->getHeaderLine('Sec-Fetch-Site'))), self::OWN_ORIGIN_SITES, true);
    }
}
