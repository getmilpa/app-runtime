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
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Auth\Http\StartSession;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The one place «passkey cookie → AuthContext, with revocation applied» is written — shared by the
 * panel's door ({@see PasskeyGateMiddleware}, greenhouse decisions/0206) and the operations surface's
 * principal ({@see PasskeySessionMiddleware}, decisions/0208), so the two cannot drift on what a
 * cookie is worth.
 *
 * The cookie is resolved by milpa/auth's own {@see StartSession} — composed, not copied — so there is
 * ONE authority for «cookie → AuthContext»: the store's fail-closed `read()` (an expired or revoked
 * session reads as absent) is the single «no session» signal. Then the ledger judges: a session whose
 * passkey the enrollment ledger no longer recognizes is DESTROYED on the spot — revocation is immediate,
 * not «when the TTL runs out». The ledger is re-read on every request, so `identity:revoke` closes a
 * live session on its next request.
 *
 * ONLY A PASSKEY'S PRINCIPAL IS THE LEDGER'S TO JUDGE. The credential the ledger is asked about is
 * derived from the principal the ceremony minted (`passkey:<credential id>`). A live session under this
 * cookie whose principal is not a passkey's — `token:…`, `user:…`, anything a host minted through
 * milpa/auth under the same cookie name — is reported as FOREIGN and left exactly as it was: the
 * ledger cannot revoke what it never enrolled, so this resolver destroys nothing it does not own. What
 * a foreign session is worth is each door's call: the panel's door refuses and closes it (it is not
 * this door's session, {@see self::close()}); the operations surface takes it as the principal it is.
 */
final class PasskeySessionResolver
{
    /** The prefix {@see \Milpa\Auth\WebAuthn\VerifiedPasskey::principal()} puts before the credential id. */
    public const PRINCIPAL_PREFIX = 'passkey:';

    private readonly StartSession $session;

    /**
     * @param SessionStore    $sessions    where the ceremony wrote the session — the same store {@see PasskeyPlugin} hands `PasskeyLogin`
     * @param EnrollmentStore $enrollments the recognition ledger, re-read on every request so a revocation lands at once
     * @param string          $cookieName  the cookie the session id travels in (`passkey.cookie`)
     */
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly EnrollmentStore $enrollments,
        private readonly string $cookieName,
    ) {
        $this->session = new StartSession($sessions, $cookieName);
    }

    /** The name of the cookie this resolver reads. */
    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /**
     * Resolve the request's cookie through StartSession, then — for a passkey's principal — ask the
     * ledger whether that passkey is still recognized. A revoked one has its session destroyed before
     * this returns; a principal that is not a passkey's comes back foreign, untouched.
     */
    public function resolve(ServerRequestInterface $request): ResolvedPasskeySession
    {
        $context = $this->contextOf($request);
        $actor = $context->actor;
        if (!$context->isAuthenticated() || $actor === null) {
            return ResolvedPasskeySession::none();
        }

        // NOT A PASSKEY'S: the ledger has no say (it never enrolled `token:…` or `user:…`), so it is
        // neither judged nor destroyed here. The doors decide what a foreign session is worth.
        if (!str_starts_with($actor->id, self::PRINCIPAL_PREFIX)) {
            return ResolvedPasskeySession::foreign($context);
        }

        // REVOCATION IS IMMEDIATE. The session froze the scopes at sign-in; the ledger is the living
        // authority on whether that passkey is still recognized at all. Asked on every request.
        if ($this->enrollments->scopesFor(self::credentialIdOf($actor->id)) === null) {
            $this->close($request);

            return ResolvedPasskeySession::revoked($actor->id);
        }

        return ResolvedPasskeySession::live($context);
    }

    /** The `Set-Cookie` value that removes this cookie for the request's origin. */
    public function expiringCookie(ServerRequestInterface $request): string
    {
        return SessionCookie::expire($this->cookieName, $request);
    }

    /**
     * Close the session the cookie names, server side. A cookie that names nothing is a no-op.
     *
     * Called here for a revoked passkey; public for a door that closes a session on its own grounds —
     * the panel's gate does, for a foreign principal that is not its session to keep.
     */
    public function close(ServerRequestInterface $request): void
    {
        $id = $request->getCookieParams()[$this->cookieName] ?? null;
        if (\is_string($id) && $id !== '') {
            $this->sessions->destroy($id);
        }
    }

    /** The credential id behind a passkey's principal: `passkey:<id>` → `<id>`. */
    private static function credentialIdOf(string $principal): string
    {
        return substr($principal, \strlen(self::PRINCIPAL_PREFIX));
    }

    /**
     * «cookie → AuthContext» through StartSession. The inner handler is only the shape StartSession
     * needs to hand the resolved request back; its response goes nowhere.
     */
    private function contextOf(ServerRequestInterface $request): AuthContext
    {
        $seen = null;
        $capture = static function (ServerRequestInterface $resolved) use (&$seen): void {
            $seen = $resolved->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
        };

        $this->session->process($request, new class ($capture) implements RequestHandlerInterface {
            /** @param \Closure(ServerRequestInterface): void $capture */
            public function __construct(private readonly \Closure $capture)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->capture)($request);

                return new Response(204);
            }
        });

        return $seen instanceof AuthContext ? $seen : AuthContext::anonymous();
    }
}
