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

use Milpa\AppRuntime\Web\BoardPage;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Serves the board page for a session — the real {@see BoardPage}, rendered by the framework's router. */
final class BoardPageController
{
    /** @param string|null $hubUrl the public real-time-stream URL the browser subscribes to, from config; null = a photograph */
    public function __construct(private readonly ?string $hubUrl = null)
    {
    }

    /** Render the board for the session named in the query, live if a realtime stream is configured. */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $session = \is_string($params['session'] ?? null) ? $params['session'] : 'default';

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (new BoardPage())->render($session, '/board/data', $this->hubUrl));
    }
}
