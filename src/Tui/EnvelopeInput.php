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

namespace Milpa\AppRuntime\Tui;

/**
 * Parses a human's «sí, pero…» typed at a gate into the effect envelope agent:answer adjudicates
 * (greenhouse evidence/0299): a comma-separated list of `axis=level` (or `axis: level`) pairs, e.g.
 * `authority=read, reversibility=compensatable` → `['authority'=>'read','reversibility'=>'compensatable']`.
 *
 * It never asks for JSON — the console coercer cannot decode an object from a string, and the in-TUI
 * path hands operations native arrays, so the screen builds the array here. It knows only the AXIS
 * NAMES (so it can tell a structural tighten from a plain value counter); it does NOT re-implement the
 * effect algebra — a known axis with any typed level is forwarded, and EffectProfile::fromPartial is
 * the single source of truth for whether that level is legal.
 */
final class EnvelopeInput
{
    /** The effect axes an envelope may lower — the keys agent:answer's `envelope` schema accepts. */
    private const AXES = ['mutation', 'externality', 'reversibility', 'authority', 'subject'];

    /**
     * The envelope a typed «axis=level» line means, or an empty array when it names no known axis
     * (which the caller reads as «this was a value counter, not a tighten»).
     *
     * @return array<string, string>
     */
    public static function parse(string $text): array
    {
        $envelope = [];
        foreach (preg_split('/\s*,\s*/', trim($text)) ?: [] as $pair) {
            if (! preg_match('/^\s*([a-zA-Z_]+)\s*[:=]\s*(.+?)\s*$/', $pair, $m)) {
                continue;
            }
            $axis = strtolower($m[1]);
            if (\in_array($axis, self::AXES, true)) {
                $envelope[$axis] = $m[2];
            }
        }

        return $envelope;
    }
}
