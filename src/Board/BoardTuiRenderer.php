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
 * Paints a {@see BoardComponent}'s state to a terminal text block — the board's TUI host, on the same
 * render-target seam as {@see BoardHtmlRenderer} (greenhouse evidence/0295). Same StateSnapshot, a
 * different substrate: columns as headings, cards as bullets.
 */
final class BoardTuiRenderer implements ComponentRendererInterface
{
    /** This renderer paints to the terminal and refuses every other target, so the registry never mis-picks it. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::TUI;
    }

    /**
     * Paint the board component's snapshot to a terminal text block: a heading per column, a bullet per card.
     *
     * Mounts the component if the request carries no state, and hands the drawn snapshot back. Refuses
     * loudly any component that is not the board.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        if ($component::contract()->name !== BoardComponent::CONTRACT_NAME) {
            throw new \InvalidArgumentException('BoardTuiRenderer renders the board, not «' . $component::contract()->name . '».');
        }

        $state = $request->state ?? $component->mount($request->props, $request->context);
        $columns = \is_array($state->data['columns'] ?? null) ? $state->data['columns'] : [];

        $lines = [];
        foreach ($columns as $name => $cards) {
            $cards = \is_array($cards) ? $cards : [];
            $lines[] = strtoupper((string) $name);
            foreach ($cards as $card) {
                $text = \is_array($card) ? (string) ($card['text'] ?? '') : '';
                $lines[] = '  • ' . $text;
            }
        }

        return new RenderResult(output: implode("\n", $lines), state: $state, format: RenderTarget::TUI);
    }
}
