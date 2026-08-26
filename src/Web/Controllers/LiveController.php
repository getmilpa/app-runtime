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
 * It adds no judgement of its own. Method, CSRF, the signed state envelope, replay, the contract's
 * action allowlist and the principal's scopes are all the endpoint's (greenhouse decisions/0083); the
 * principal is whoever `milpa/auth` verified ({@see LivePrincipal}). Bad JSON is the one thing this
 * controller answers itself, because the endpoint never saw a request.
 */
final class LiveController
{
    public function __construct(private readonly LiveEndpoint $endpoint)
    {
    }

    /** One interaction: `{action, payload, state, sessionId, csrfToken}` in → `{html, state, data, …}` or the refusal out. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
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
            LivePrincipal::fromRequest($request),
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
