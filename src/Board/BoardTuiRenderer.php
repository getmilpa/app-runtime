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
 * Paints a {@see BoardComponent}'s state to a compact terminal block — the board's TUI host, on the
 * same render-target seam as {@see BoardHtmlRenderer} (greenhouse evidence/0295, hosted in the chat
 * screen in 0297). Same StateSnapshot, a terminal substrate: non-empty columns as headings with
 * their count, cards as bullets — `∴` for a card born already done (so a terminal never paints as a
 * crossing what nobody observed), `?` for one the fold holds on a question — capped at a few per
 * column so the region stays bounded inside a chat.
 */
final class BoardTuiRenderer implements ComponentRendererInterface
{
    private const CARDS_SHOWN = 4;

    /** This renderer paints to the terminal and refuses every other target, so the registry never mis-picks it. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::TUI;
    }

    /**
     * Paint the board component's snapshot to a compact terminal text block.
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

        return new RenderResult(output: implode("\n", self::linesOf($state->data)), state: $state, format: RenderTarget::TUI);
    }

    /**
     * How many terminal lines a fold will paint to — for a host that must set a node's height up front.
     *
     * @param array<string, mixed> $fold the agent:board fold, the same shape the component mounts
     */
    public static function heightFor(array $fold): int
    {
        return max(1, \count(self::linesOf($fold)));
    }

    /**
     * The lines of the compact terminal board, from the fold held in the snapshot's data.
     *
     * @param array<string, mixed> $fold
     *
     * @return list<string>
     */
    private static function linesOf(array $fold): array
    {
        if (($fold['ok'] ?? false) !== true) {
            $error = \is_string($fold['error'] ?? null) ? $fold['error'] : 'the board could not be read';

            return [$error];
        }

        $lines = [];

        $question = $fold['pending_question'] ?? null;
        if (\is_string($question) && $question !== '') {
            $lines[] = '⏸ ' . $question;
        }

        $columns = \is_array($fold['columns'] ?? null) ? $fold['columns'] : [];
        $anyCard = false;
        foreach ($columns as $status => $cards) {
            $cards = \is_array($cards) ? $cards : [];
            if ($cards === []) {
                continue; // compact: an empty column is not worth a line inside a chat
            }
            $anyCard = true;
            $lines[] = strtoupper((string) $status) . ' (' . \count($cards) . ')';
            foreach (\array_slice($cards, 0, self::CARDS_SHOWN) as $card) {
                $card = \is_array($card) ? $card : [];
                $origin = \is_string($card['origin'] ?? null) ? $card['origin'] : '';
                $mark = ($origin === 'retrospective' || $origin === 'unsupported') ? '∴' : '·';
                if (($card['held_by'] ?? null) === 'question') {
                    $mark = '?';
                }
                $lines[] = '  ' . $mark . ' ' . (string) ($card['text'] ?? '');
            }
            if (\count($cards) > self::CARDS_SHOWN) {
                $lines[] = '  … +' . (\count($cards) - self::CARDS_SHOWN);
            }
        }

        if (! $anyCard && ($question === null || $question === '')) {
            $lines[] = '(sin trabajo aún)';
        }

        return $lines;
    }
}
