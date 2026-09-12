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
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/** Resolves at render time through the same per-contract registry the live endpoint uses. */
final readonly class RegisteredHtmlRenderer implements ComponentRendererInterface
{
    public function __construct(private ComponentRendererRegistry $renderers)
    {
    }

    /** Screen documents use the HTML transport. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /** A missing renderer is an invalid screen, not an invitation to guess a target-wide fallback. */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $name = $component::contract()->name;
        $renderer = $this->renderers->resolveFor($name, $request->target);
        if ($renderer === null) {
            throw new InvalidScreenTree('type', 'no HTML renderer registered for component: ' . $name);
        }
        $result = $renderer->render($component, $request);
        if (! $renderer instanceof DeclaresClientAssets) {
            return $result;
        }

        return new RenderResult(
            output: $result->output,
            state: $result->state,
            assets: $result->assets,
            effects: $result->effects,
            format: $result->format,
            clientAssets: $result->clientAssets()->merge($renderer->clientAssets()),
        );
    }
}
