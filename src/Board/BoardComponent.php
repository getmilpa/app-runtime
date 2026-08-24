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

use Milpa\Live\Components\Dashboard\AbstractDashboardComponent;
use Milpa\Live\ValueObjects\ComponentContract;

/**
 * The agent board as ONE render-target-agnostic Live component (greenhouse evidence/0295).
 *
 * The board used to be two bespoke renderings — a hand-written HTML page (BoardPage + board-painter.js)
 * and a bespoke TUI tree — sharing only the fold. This expresses it ONCE as a
 * {@see \Milpa\Live\Contracts\Component\ComponentDefinitionInterface}: it owns the board's shape and
 * its state (the four columns of the `agent:board` fold), and owns NO rendering — a
 * {@see \Milpa\Live\Contracts\Rendering\ComponentRendererInterface} per target paints it. The
 * component never names a host; the caller mounts it with the fold and points a render target at it.
 *
 * It is read-only for now: the fold is a Read projection, so no action is handled (the one write —
 * answering a paused question — stays in the page shell, deferred). `mount()` and `handle()` come from
 * {@see AbstractDashboardComponent}; this class declares the contract and maps the fold into state.
 */
final class BoardComponent extends AbstractDashboardComponent
{
    public const CONTRACT_NAME = 'board';

    /** The board's runtime contract: a `columns` prop/state (the four-column fold), no actions (read-only). */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::CONTRACT_NAME,
            contractVersion: '0.1.0',
            summary: 'The agent board: four columns of work derived from a session stream.',
            propsSchema: [
                'ok' => ['type' => 'bool', 'default' => true],
                'columns' => ['type' => 'object', 'required' => false],
                'pending_question' => ['type' => 'string', 'required' => false],
                'session' => ['type' => 'string', 'required' => false],
                'error' => ['type' => 'string', 'required' => false],
            ],
            stateSchema: [
                'ok' => ['type' => 'bool'],
                'columns' => ['type' => 'object'],
                'pending_question' => ['type' => 'string'],
                'session' => ['type' => 'string'],
                'error' => ['type' => 'string'],
            ],
        );
    }

    /**
     * Map the mounted `agent:board` fold (passed as props) into the snapshot's data: the columns and,
     * so a renderer reaches parity with the old client painter, whether the read was `ok`, the pending
     * question, the session and any error.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    protected function initialData(array $props): array
    {
        $columns = $props['columns'] ?? [];

        return [
            'ok' => (bool) ($props['ok'] ?? true),
            'columns' => \is_array($columns) ? $columns : [],
            'pending_question' => \is_string($props['pending_question'] ?? null) ? $props['pending_question'] : null,
            'session' => \is_string($props['session'] ?? null) ? $props['session'] : null,
            'error' => \is_string($props['error'] ?? null) ? $props['error'] : null,
        ];
    }
}
