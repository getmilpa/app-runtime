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

namespace Milpa\AppRuntime\Web;

use Milpa\AppRuntime\Auth\LivePrincipal;
use Milpa\Live\ValueObjects\ComponentContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The ONE call that renders a live component FOR the actor a request was authenticated as, so the signed
 * state is born owned by the same principal the {@see \Milpa\AppRuntime\Web\Controllers\LiveController}
 * will verify on the action (greenhouse decisions/0089: «a live action binds to its OWNER»).
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────────────────────────────
 *
 * The owner written at render MUST be the SAME id the POST verifies — `actor:<id>` from {@see LivePrincipal}.
 * A page that hand-writes the owner from the raw actor string (`'rod'`) signs state that even its owner
 * cannot act on, because the endpoint compares it against `actor:rod` and denies (greenhouse evidence/0328,
 * the case-A trap, measured RED→GREEN). This helper removes the chance to get it wrong: an app passes the
 * request, never the id, and the context is built from the ONE derivation the endpoint also uses. No actor
 * on the request → a null principal → an anonymous (ownerless) context, exactly as the endpoint expects.
 */
final class LiveRender
{
    /**
     * Build the {@see ComponentContext} for rendering `$componentId` as the request's verified actor.
     * The `principal` is `LivePrincipal::fromRequest($request)?->id` — the very value the endpoint's
     * authorizer matches — or null when the request carries no verified actor.
     *
     * @param array<string, mixed> $meta caller-defined extra context, passed through untouched
     */
    public static function contextForRequest(
        ServerRequestInterface $request,
        string $componentId,
        ?string $route = null,
        ?string $locale = null,
        array $meta = [],
    ): ComponentContext {
        $principal = LivePrincipal::fromRequest($request);

        return new ComponentContext(
            componentId: $componentId,
            principal: $principal?->id,
            locale: $locale,
            route: $route,
            meta: $meta,
        );
    }
}
