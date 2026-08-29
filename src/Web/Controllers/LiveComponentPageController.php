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

use Milpa\AppRuntime\Web\LivePageProvider;
use Milpa\AppRuntime\Web\LiveRender;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Http\LiveBoot;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The shipped interactive render path: `GET {route}/page?component=<name>` server-renders a live component
 * BOUND TO the request's verified actor, so the signed state is born owned WITHOUT the app touching the
 * `actor:` format (greenhouse decisions/0092; the adoption of {@see LiveRender}, decisions/0091).
 *
 * The framework owns OWNERSHIP: the context's principal is derived from the ONE {@see LiveRender::contextForRequest}
 * the endpoint's authorizer also matches. The app owns DATA: a registered {@see LivePageProvider} hands the
 * props; with no provider, or a provider that declines the component, this answers 404 rather than paint a
 * page with invented data. It embeds the SAME {@see LiveBoot} the endpoint verifies, so the page it returns
 * is immediately actionable over `POST {route}` by — and only by — the actor it was rendered for.
 */
final class LiveComponentPageController
{
    public function __construct(
        private readonly ComponentRegistryInterface $registry,
        private readonly ComponentRendererInterface $renderer,
        private readonly CsrfGuardInterface $csrf,
        private readonly string $route,
        private readonly ?LivePageProvider $provider = null,
    ) {
    }

    /**
     * Server-renders `?component=<name>` bound to the request's verified actor: 200 with the owned, signed,
     * bootable page; 404 when the component is not registered or no {@see LivePageProvider} supplied its props.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $name = \is_string($request->getQueryParams()['component'] ?? null) ? $request->getQueryParams()['component'] : '';
        if ($name === '' || ! $this->registry->has($name)) {
            return $this->json(404, ['error' => 'live_component_unknown', 'message' => 'No such live component is registered.']);
        }

        $props = $this->provider?->propsFor($name, $request);
        if ($props === null) {
            return $this->json(404, [
                'error' => 'live_no_page_provider',
                'message' => 'No LivePageProvider supplied props for this component; the framework will not render a page with invented data.',
            ]);
        }
        $props['endpoint'] ??= $this->route;
        $props['name'] ??= $name;

        // OWNERSHIP is the framework's half: the state is born owned by the request's verified actor, the same
        // `actor:<id>` the endpoint verifies on the action — never a hand-written string (decisions/0091, the trap).
        $context = LiveRender::contextForRequest($request, componentId: $name, route: $this->route);

        $rendered = $this->renderer->render(
            $this->registry->get($name),
            new RenderRequest(context: $context, props: $props, target: RenderTarget::HTML),
        );

        $authorization = $request->getHeaderLine('Authorization');
        $boot = LiveBoot::issue($this->csrf, $this->route, $authorization !== '' ? $authorization : null);

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            $rendered->output . "\n" . $boot->scriptTag(),
        );
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
