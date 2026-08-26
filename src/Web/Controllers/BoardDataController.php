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
use Milpa\Command\OperationHttpPolicy;
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
        $board = null;
        foreach ((new SessionOperations($this->container))->operations() as $op) {
            if ($op->name === 'agent:board') {
                $board = $op;
                break;
            }
        }
        if ($board === null) {
            return new Response(404, ['Content-Type' => 'application/json'], '{"error":"agent:board is not offered by this app"}');
        }

        // ONE DOOR, ONE JUDGE (greenhouse decisions/0082). This route used to call the handler directly,
        // so the OperationHttpPolicy the projector consults for /agent/board never saw /board/data and an
        // anonymous caller read the fold — goal, plan, the pending question's text (evidence/0318, 0319).
        // The same policy judges here, by the operation's own declared scopes; and with no policy
        // registered this door REFUSES instead of serving: a door nobody judges is not a door. The seeded
        // config/boot.php registers the policy once milpa/auth and milpa/data are installed.
        if (! $this->container->has(OperationHttpPolicy::class)) {
            return new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => 'the board is a session\'s own facts and this app registered no OperationHttpPolicy to judge who may read them',
                'hint' => 'composer require milpa/auth milpa/data — the seeded config/boot.php then wires the token store, the verifier and the policy',
            ], \JSON_UNESCAPED_SLASHES));
        }
        $policy = $this->container->get(OperationHttpPolicy::class);
        $denied = $policy instanceof OperationHttpPolicy ? $policy->enforce($board, $request) : null;
        if ($denied !== null) {
            return $denied;
        }

        $fold = ($board->handler)(['session' => $session]);

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
