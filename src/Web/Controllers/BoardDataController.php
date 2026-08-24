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

use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Interfaces\Di\DIContainerInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Serves  (the four columns, folded from the session stream) as JSON for the page to paint. */
final class BoardDataController
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** The board columns for the session named in the query, as JSON. */
    public function data(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $session = \is_string($params['session'] ?? null) ? $params['session'] : '';
        $r = ['ok' => false, 'error' => 'no board'];
        foreach ((new SessionOperations($this->container))->operations() as $op) {
            if ($op->name === 'agent:board') {
                $r = ($op->handler)(['session' => $session]);
                break;
            }
        }

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($r, \JSON_UNESCAPED_SLASHES));
    }
}
