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
 * render-target seam (greenhouse evidence/0295, wired into the web host in 0296). It reads the SAME
 * StateSnapshot the TUI renderer reads; the only thing host-specific is the output substrate.
 *
 * The markup is byte-parity with the client painter it replaces (board-painter.js's paintBoard /
 * paintCard, greenhouse evidence/0296): a waiting alert when a question paused the session, a
 * `milpa-board-columns` grid, a `milpa-column` section per status carrying its count, and a
 * `milpa-card` per card with the same data-attributes and the same badges — «appeared already done»
 * for a born-done card (so the surface never paints as a crossing what nobody observed), «waiting for
 * an answer» for a card the fold holds on a question, and the unexplained-mutations count. No writable
 * control: the one write (answering) stays in the page shell.
 */
final class BoardHtmlRenderer implements ComponentRendererInterface
{
    /** This renderer paints to HTML and refuses every other target, so the registry never mis-picks it. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * Paint the board component's snapshot to semantic HTML at parity with the old client painter.
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

        return new RenderResult(output: $this->board($state->data), state: $state, format: RenderTarget::HTML);
    }

    /** @param array<string, mixed> $fold the board fold held in the snapshot's data */
    private function board(array $fold): string
    {
        if (($fold['ok'] ?? false) !== true) {
            $error = \is_string($fold['error'] ?? null) ? $fold['error'] : 'the board could not be read';

            return '<p class="mui-alert mui-alert--danger milpa-board-error">' . $this->esc($error) . '</p>';
        }

        $html = '';

        $question = $fold['pending_question'] ?? null;
        if (\is_string($question) && $question !== '') {
            $session = \is_string($fold['session'] ?? null) ? $fold['session'] : '';
            $html .= '<p class="mui-alert mui-alert--warning milpa-board-waiting">' . $this->esc($question)
                . ' <span class="milpa-board-waiting-hint">answer from the terminal: '
                . '<code>php bin/coa agent:answer --session=' . $this->esc($session) . ' --answer=…</code></span></p>';
        }

        $html .= '<div class="milpa-board-columns">';
        $columns = \is_array($fold['columns'] ?? null) ? $fold['columns'] : [];
        foreach ($columns as $status => $cards) {
            $cards = \is_array($cards) ? $cards : [];
            $html .= '<section class="mui-card mui-card--compact milpa-column" data-status="' . $this->esc((string) $status) . '">'
                . '<h2 class="milpa-column-title">' . $this->esc((string) $status)
                . ' <span class="milpa-column-count">' . \count($cards) . '</span></h2>'
                . '<ul class="milpa-column-cards">';
            foreach ($cards as $card) {
                $html .= $this->card(\is_array($card) ? $card : []);
            }
            $html .= '</ul></section>';
        }
        $html .= '</div>';

        return $html;
    }

    /** @param array<string, mixed> $card */
    private function card(array $card): string
    {
        $origin = \is_string($card['origin'] ?? null) ? $card['origin'] : 'unknown';
        // BOTH born-done origins are set apart: painting either as a crossing would be the board
        // telling a story nobody observed (board-painter.js, found live 2026-08-06).
        $appearedDone = $origin === 'retrospective' || $origin === 'unsupported';
        $unexplained = \is_int($card['unexplained'] ?? null) ? $card['unexplained'] : 0;

        $inner = '<span class="milpa-card-text">' . $this->esc((string) ($card['text'] ?? '')) . '</span>';
        if ($appearedDone) {
            $inner .= '<span class="mui-badge milpa-card-flag">appeared already done</span>';
        }
        if (($card['held_by'] ?? null) === 'question') {
            $inner .= '<span class="mui-badge milpa-card-flag">waiting for an answer</span>';
        }
        if ($unexplained > 0) {
            $inner .= '<span class="mui-badge milpa-card-unexplained" title="mutations since this card was touched">' . $unexplained . '</span>';
        }

        return '<li class="milpa-card" data-id="' . $this->esc((string) ($card['id'] ?? '')) . '"'
            . ' data-origin="' . $this->esc($origin) . '"'
            . ' data-crossed="' . ($appearedDone ? 'false' : 'true') . '"'
            . ' data-version="' . $this->esc((string) ($card['version'] ?? 1)) . '">'
            . $inner . '</li>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES);
    }
}
