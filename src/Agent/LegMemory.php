<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * The memory a leg of the agent runs with, declared by the house and not left to php.ini (greenhouse
 * decisions/0511 §3–§4).
 *
 * Measured (evidence/1045): a leg holds its session's stream once — about 1 MB of memory per MB of stream, on top of
 * ~24 MB of its own — and PHP's default 128 MB is where the 21 MB session of 1036 lived on the edge and died. The
 * number is not guessed: it is the smallest power of two at least twice the real peak of a leg over a session four
 * times 1036's (108 MB). Measured too, how far it reaches: ten times 1036's with 42 MB to spare, twelve times on the
 * edge, fourteen times dies at it — a session nearing that is measured again with the same rule, not raised by feel.
 *
 * Declared where every leg passes — the start of the `agent` operation — so `coa agent`, the panel's doors under
 * `coa serve` or FrankenPHP, `coa mcp` and the Desktop's `docker exec` all run with it. It only RAISES: a process
 * that already has more, or no limit (`-1`), keeps what it has.
 */
final class LegMemory
{
    /** What a leg declares, from the rule above. */
    public const DECLARED = '256M';

    /**
     * Raise `memory_limit` to {@see self::DECLARED} when it is lower, and say what happened: what was declared, what
     * the process had, and what it has now — a limit that could not be raised is seen, not assumed.
     *
     * @return array{declared: string, before: string, effective: string}
     */
    public static function declare(): array
    {
        $before = (string) \ini_get('memory_limit');
        if (self::raises($before, self::DECLARED)) {
            @ini_set('memory_limit', self::DECLARED);
        }

        return ['declared' => self::DECLARED, 'before' => $before, 'effective' => (string) \ini_get('memory_limit')];
    }

    /**
     * Whether `$declared` is more than `$current` — never for no limit (`-1`, or a `0` PHP reads as none).
     */
    public static function raises(string $current, string $declared): bool
    {
        $now = self::bytes($current);

        return $now > 0 && $now < self::bytes($declared);
    }

    /**
     * A php.ini size (`128M`, `1G`, `512k`, `134217728`) in bytes; `-1` and an empty value answer 0.
     */
    public static function bytes(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1') {
            return 0;
        }
        $value = (int) $size;

        return match (strtolower($size[\strlen($size) - 1])) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
