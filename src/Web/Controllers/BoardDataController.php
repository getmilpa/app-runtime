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

use Milpa\AppRuntime\Board\BoardComponent;
use Milpa\AppRuntime\Board\BoardHtmlRenderer;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Serves the board — the four columns folded from the session stream — as HTML rendered by the one Live component. */
final class BoardDataController
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** The board for the session named in the query, server-rendered to HTML by BoardComponent + BoardHtmlRenderer. */
    public function data(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $session = \is_string($params['session'] ?? null) ? $params['session'] : '';
        $fold = ['ok' => false, 'error' => 'no board'];
        foreach ((new SessionOperations($this->container))->operations() as $op) {
            if ($op->name === 'agent:board') {
                $fold = ($op->handler)(['session' => $session]);
                break;
            }
        }

        // The board is now server-rendered by the ONE Live component (greenhouse evidence/0296):
        // mount BoardComponent with the fold and paint it through BoardHtmlRenderer, so the browser
        // sets this HTML as-is instead of re-deriving the markup client-side. The fragment carries the
        // waiting alert when a question is open, which is how the page arms its answer bar.
        $html = (new BoardHtmlRenderer())->render(
            new BoardComponent(),
            new RenderRequest(
                context: new ComponentContext(componentId: 'board-' . $session),
                props: \is_array($fold) ? $fold : [],
                target: RenderTarget::HTML,
            ),
        )->output;

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}
