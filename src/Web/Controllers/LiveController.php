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

namespace Milpa\AppRuntime\Web\Controllers;

use Milpa\AppRuntime\Auth\LivePrincipal;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The PSR-15 door of the live wire: translates the request the remote runtime sends into the
 * {@see LiveHttpRequest} the {@see LiveEndpoint} judges, and its verdict back into JSON.
 *
 * It adds ONE judgement of its own — identity, the outermost gate (greenhouse decisions/0084). Mounting
 * the wire does not grant an anonymous caller the right to act on it: without a verified actor this door
 * answers 401 BEFORE the endpoint, exactly as a write over HTTP demands an actor (evidence/0318), unless
 * the app declared the wire public (`live.anonymous`). Everything after — CSRF, the signed state
 * envelope, replay, the contract's action allowlist and the principal's scopes — is the endpoint's
 * (decisions/0083); the principal is whoever `milpa/auth` verified ({@see LivePrincipal}). Bad JSON is the
 * other thing this controller answers itself, because the endpoint never saw a request.
 */
final class LiveController
{
    /**
     * @param bool $allowAnonymous when true, an unauthenticated caller reaches the endpoint (a wire the app
     *                             declared public); the default fails closed — no actor, no action
     */
    public function __construct(
        private readonly LiveEndpoint $endpoint,
        private readonly bool $allowAnonymous = false,
    ) {
    }

    /** One interaction: `{action, payload, state, sessionId, csrfToken}` in → `{html, state, data, …}` or the refusal out. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $principal = LivePrincipal::fromRequest($request);
        if ($principal === null && ! $this->allowAnonymous) {
            // IDENTITY IS THE OUTERMOST GATE (greenhouse decisions/0084). Exposing the wire does not grant
            // the right to act; a live action needs a verified actor, the same way a write over HTTP does
            // (evidence/0318). Refuse before the endpoint sees anything — and teach what is missing.
            return $this->json(401, [
                'error' => 'live_unauthenticated',
                'message' => 'This live action needs a verified actor. Present a Bearer token, or declare the wire public with live.anonymous.',
            ]);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (! \is_array($body)) {
            return $this->json(400, ['error' => 'live_bad_request', 'message' => 'The body is not a JSON object.']);
        }

        $payload = $body['payload'] ?? [];
        $response = $this->endpoint->handle(
            new LiveHttpRequest(
                method: strtoupper($request->getMethod()),
                action: \is_string($body['action'] ?? null) ? $body['action'] : '',
                stateEnvelope: \is_string($body['state'] ?? null) ? $body['state'] : '',
                payload: \is_array($payload) ? $payload : [],
                sessionId: \is_string($body['sessionId'] ?? null) ? $body['sessionId'] : '',
                csrfToken: \is_string($body['csrfToken'] ?? null) ? $body['csrfToken'] : '',
            ),
            $principal,
        );

        return $this->json($response->status, $response->body);
    }

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            (string) json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    }
}
