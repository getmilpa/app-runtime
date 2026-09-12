<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web\Controllers;

use Milpa\AppRuntime\Auth\LivePrincipal;
use Psr\Http\Message\{ServerRequestInterface,ResponseInterface};
use Nyholm\Psr7\Response;

/** The review page requires its own read permission before returning proposals or active configuration. */
final readonly class ScreenReviewController
{
    public function __construct(private LiveComponentPageController $page, private LiveController $live)
    {
    }
    /** Serve the normal signed component page after checking the verified actor. */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $p = LivePrincipal::fromRequest($request);
        if ($p === null) {
            return new Response(401);
        }
        if (!$p->can('milpa:component:screen-review:read') && !$p->can('milpa:component:screen-review:*')) {
            return new Response(403);
        }
        if ($request->getMethod() === 'POST') {
            return $this->live->handle($request);
        }
        try {
            return $this->page->show($request->withQueryParams(['component' => 'screen-review'] + $request->getQueryParams()));
        } catch (\DomainException $e) {
            return new Response(409, ['Content-Type' => 'application/json','Cache-Control' => 'no-store'], json_encode(['error' => $e->getMessage()]));
        }
    }
}
