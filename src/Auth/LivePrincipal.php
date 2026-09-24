<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Auth;

use Milpa\Auth\AuthContext;
use Milpa\Live\ValueObjects\SecurityPrincipal;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ONE identity for the live wire: the actor `milpa/auth` verified is the principal a live action runs as.
 *
 * `milpa/live-web` ships its own `StaticBearerTokenVerifier → SecurityPrincipal`; this runtime already
 * verifies a Bearer into an {@see AuthContext} actor (`TokenVerifier`, measured on cattle in greenhouse
 * evidence/0318) and records that actor as the principal of every consent fact. Two verifiers for one
 * header are two authorities for «who are you» — the shape decisions/0081–0082 closed for HTTP. So the
 * live endpoint never authenticates: it reads the actor the authentication middleware already attached
 * to the request and projects it into the {@see SecurityPrincipal} the endpoint's authorizer judges by
 * (greenhouse decisions/0083). No actor → no principal → an action the component restricts is denied.
 */
final class LivePrincipal
{
    /**
     * The request attribute the authentication middleware writes, by NAME.
     *
     * `AuthenticateMiddleware::ATTRIBUTE` is the same string — and reading it THROUGH the class was
     * the defect. `milpa/auth` is opt-in, so in an app without it that constant fetch threw
     * «Class "Milpa\Auth\Http\AuthenticateMiddleware" not found» and every live surface answered
     * 500: the four controllers below all enter here. Measured on fresh cattle serving a declared
     * screen (greenhouse `evidence/0993`).
     *
     * A constant fetch on an absent class throws; `instanceof` on one answers false. So the name is
     * read as a literal and the narrowing below stays exactly as it was — the same seam, and the
     * same reason, as the framework's own HTTP projector: «se lee el ATRIBUTO, no la clase».
     */
    private const ATTRIBUTE = 'milpa.auth';

    /** The principal of this request — `actor:<id>` with the actor's scopes — or null for an anonymous caller. */
    public static function fromRequest(ServerRequestInterface $request): ?SecurityPrincipal
    {
        $context = $request->getAttribute(self::ATTRIBUTE);
        if (! $context instanceof AuthContext || ! $context->isAuthenticated() || $context->actor === null) {
            return null;
        }

        return new SecurityPrincipal(
            'actor:' . $context->actor->id,
            array_map('strval', $context->actor->scopes),
            ['type' => $context->actor->type->value],
        );
    }
}
