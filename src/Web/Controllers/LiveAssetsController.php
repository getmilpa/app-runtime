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

use Milpa\Live\Support\ClientRuntime;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the three client files `milpa/live-web` ships — the local runtime, the remote runtime and
 * the vendored Alpine — verbatim, by name, never by path.
 *
 * The host does not inline or rewrite them (ADR#10, no-build): a page loads them from the URLs
 * {@see ClientRuntime::defaultUrls()} promises, and what it gets is byte for byte what the package
 * ships. One method per file so the route table names each one.
 */
final class LiveAssetsController
{
    /** `milpa-live.js` — the local runtime (frozen contract: never touches the network). */
    public function local(ServerRequestInterface $request): ResponseInterface
    {
        return $this->file(ClientRuntime::LOCAL);
    }

    /** `milpa-live-remote.js` — the remote runtime (takes declared actions to the endpoint, applies the answer). */
    public function remote(ServerRequestInterface $request): ResponseInterface
    {
        return $this->file(ClientRuntime::REMOTE);
    }

    /** `alpine.min.js` — the vendored Alpine.js (MIT; see live-web's resources/vendor/README.md). */
    public function alpine(ServerRequestInterface $request): ResponseInterface
    {
        return $this->file(ClientRuntime::ALPINE);
    }

    private function file(string $name): ResponseInterface
    {
        $path = ClientRuntime::path($name);
        if ($path === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], "{$name} is not shipped by the installed milpa/live-web");
        }

        return new Response(
            200,
            ['Content-Type' => ClientRuntime::contentType(), 'Cache-Control' => 'public, max-age=3600'],
            (string) file_get_contents($path),
        );
    }
}
