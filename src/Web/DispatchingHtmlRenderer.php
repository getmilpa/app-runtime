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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Routes a live component to the renderer for ITS contract type (greenhouse decisions/0164): the shipped
 * renderers are each single-family — {@see \Milpa\Live\Rendering\DashboardHtmlRenderer} renders dashboard
 * primitives and throws for the rest, {@see \Milpa\Live\Rendering\StateMachineHtmlRenderer} renders only
 * state-machines — so the page controller cannot hold one renderer for every component. This dispatcher
 * picks by the component's contract name, falling back to a default for the families it does not name.
 *
 * It is an app-runtime seam, not a milpa/live contract change: the interface stays as it is, and the
 * page controller depends on {@see ComponentRendererInterface} — this, a plain renderer, or a future one.
 */
final class DispatchingHtmlRenderer implements ComponentRendererInterface
{
    /** @param array<string, ComponentRendererInterface> $byContract contract name => the renderer for it */
    public function __construct(
        private readonly array $byContract,
        private readonly ComponentRendererInterface $fallback,
    ) {
    }

    /** This dispatcher targets HTML only; every renderer it routes to is an HTML renderer. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /** Route `$component` to the renderer registered for its contract type, or the fallback, and render it. */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $contract = $component::contract()->name;
        $renderer = $this->byContract[$contract] ?? $this->fallback;

        return $renderer->render($component, $request);
    }
}
