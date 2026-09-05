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

/**
 * What one request's passkey cookie resolved to, once the recognition ledger had its say — the single
 * answer both doors read ({@see PasskeySessionResolver}; greenhouse decisions/0206 and 0208).
 *
 * Four outcomes, kept apart because the doors answer them differently:
 *
 *   - NO live session (no cookie, an id the store does not know, expired, revoked in the store): the
 *     panel gate sends a browser to sign in, the operations surface goes on anonymous;
 *   - a session whose passkey the ledger NO LONGER RECOGNIZES: the store entry is already destroyed by
 *     the time this is returned and the cookie is to be expired — the gate refuses with 403, the
 *     operations surface goes on anonymous; {@see self::$revokedPrincipal} names the passkey for the page;
 *   - a live session whose principal is NOT a passkey's (`token:…`, `user:…` — a session the host
 *     minted through milpa/auth under the same cookie): FOREIGN to the ledger, which has no say over
 *     it, so nothing is destroyed here. The gate refuses and closes it (not this door's session); the
 *     operations surface takes it as the principal it is, exactly as milpa/auth's own `StartSession`
 *     would have;
 *   - a LIVE, recognized session: {@see self::$context} is authenticated to `passkey:<credential id>`
 *     with the scopes the enrollment granted.
 */
final readonly class ResolvedPasskeySession
{
    private function __construct(
        public AuthContext $context,
        public ?string $revokedPrincipal,
        private bool $foreign,
    ) {
    }

    /** No live session: the context is anonymous, and nothing was revoked. */
    public static function none(): self
    {
        return new self(AuthContext::anonymous(), null, false);
    }

    /** A live, recognized session: `$context` is the authenticated context the cookie resolved to. */
    public static function live(AuthContext $context): self
    {
        return new self($context, null, false);
    }

    /** The passkey behind `$principal` is no longer recognized: anonymous, and the cookie must expire. */
    public static function revoked(string $principal): self
    {
        return new self(AuthContext::anonymous(), $principal, false);
    }

    /** A live session whose principal is not a passkey's: `$context` as StartSession resolved it, the ledger silent. */
    public static function foreign(AuthContext $context): self
    {
        return new self($context, null, true);
    }

    /** An authenticated context — a recognized passkey's or a foreign principal's. */
    public function isLive(): bool
    {
        return $this->context->isAuthenticated();
    }

    /** A passkey the ledger no longer recognizes: its session was destroyed and the cookie must expire. */
    public function isRevoked(): bool
    {
        return $this->revokedPrincipal !== null;
    }

    /** Whether the live session belongs to a principal the enrollment ledger has no say over. */
    public function isForeign(): bool
    {
        return $this->foreign;
    }
}
