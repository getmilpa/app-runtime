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

namespace Milpa\AppRuntime\Board;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Paints a {@see BoardComponent}'s state to browser HTML — the board's HTML host, on the shared
 * render-target seam (greenhouse evidence/0295). It reads the SAME StateSnapshot the TUI renderer
 * reads; the only thing host-specific is the output substrate. Semantic, class-stable markup, no
 * writable control (the one write stays in the page shell).
 */
final class BoardHtmlRenderer implements ComponentRendererInterface
{
    /** This renderer paints to HTML and refuses every other target, so the registry never mis-picks it. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * Paint the board component's snapshot to semantic HTML: a section per column, a list item per card.
     *
     * Mounts the component if the request carries no state, and hands the drawn snapshot back so the
     * caller need not mount again. Refuses loudly any component that is not the board.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        if ($component::contract()->name !== BoardComponent::CONTRACT_NAME) {
            throw new \InvalidArgumentException('BoardHtmlRenderer renders the board, not «' . $component::contract()->name . '».');
        }

        $state = $request->state ?? $component->mount($request->props, $request->context);
        $columns = \is_array($state->data['columns'] ?? null) ? $state->data['columns'] : [];

        $html = '<div class="milpa-board">';
        foreach ($columns as $name => $cards) {
            $cards = \is_array($cards) ? $cards : [];
            $html .= '<section class="milpa-column" data-column="' . htmlspecialchars((string) $name, \ENT_QUOTES) . '">';
            $html .= '<h2 class="milpa-column-title">' . htmlspecialchars((string) $name, \ENT_QUOTES) . '</h2><ul class="milpa-column-cards">';
            foreach ($cards as $card) {
                $text = \is_array($card) ? (string) ($card['text'] ?? '') : '';
                $html .= '<li class="milpa-card">' . htmlspecialchars($text, \ENT_QUOTES) . '</li>';
            }
            $html .= '</ul></section>';
        }
        $html .= '</div>';

        return new RenderResult(output: $html, state: $state, format: RenderTarget::HTML);
    }
}
