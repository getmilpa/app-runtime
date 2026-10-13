<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Support;

/**
 * Forget the bytecode of PHP files this process just wrote into the house — so the next request runs them.
 *
 * ── THE WINDOW, MEASURED (greenhouse evidence/1038, o4; decisions/0506) ─────────────────────────
 *
 * OPcache revalidates a file at most every `opcache.revalidate_freq` seconds (2 by default). After a
 * promotion wrote an edit, FrankenPHP classic and `php -S` served the OLD code for ~2.35 s: the servers
 * that start a fresh kernel per request, whose whole promise is «always the house as it is now».
 *
 * The process that writes knows what it wrote. When it IS the server — a resident's turn promoting from
 * inside the panel, on FrankenPHP (threads) or `php -S` (forked workers) — its cache is the server's, and
 * invalidating those files closes the window for every later request. When it is another process (a `coa`
 * in a terminal), this reaches nothing; that half is the server's own setting (`opcache.revalidate_freq=0`,
 * which `coa serve` passes). Invalidate by name, NEVER `opcache_reset()`: under FrankenPHP it restarts
 * every worker and kills a request in flight (1038, o2).
 */
final class CompiledCode
{
    /**
     * Invalidate the PHP files among `$paths` (relative to `$root`) — by force, content moved even if the mtime did not.
     *
     * @param iterable<string> $paths
     *
     * @return int how many cached scripts were dropped (0 without OPcache, or when none was cached)
     */
    public static function forget(string $root, iterable $paths): int
    {
        if (!\function_exists('opcache_invalidate')) {
            return 0;
        }
        $dropped = 0;
        foreach ($paths as $path) {
            if (str_ends_with($path, '.php') && opcache_invalidate(rtrim($root, '/') . '/' . ltrim($path, '/'), true)) {
                ++$dropped;
            }
        }

        return $dropped;
    }

    /**
     * The one line `coa doctor` says about OPcache to a house that keeps a book of standing grants (greenhouse
     * decisions/0615 — the seventh review, 7-L5; decided by Rod, 2026-10-13: a limit of whoever operates the house,
     * said — the judge neither asks OPcache what it holds nor refuses a yes for it).
     *
     * The judge dates an instrument's FILES against the second the asking process started. A server answers each
     * request as a new process, over code OPcache may have compiled before a file changed: with PHP's own default
     * a yes of the OLD code was written for two seconds after every change, and with `validate_timestamps=0` until
     * the server was started again. `!` when PHP, as this process reads it, is set so; `·` otherwise — and it says
     * that it is THIS process's reading: a server may load another configuration, and a terminal often loads no
     * OPcache at all.
     *
     * @param string|false $validates what `ini_get('opcache.validate_timestamps')` gives this process
     * @param string|false $every     what `ini_get('opcache.revalidate_freq')` gives this process
     */
    public static function saidToAHouseThatLends(string|false $validates, string|false $every): string
    {
        $rule = 'standing grants: the judge dates the files of an instrument, it does not ask OPcache what it compiled. '
            . 'A server that does not look at its files on every request answers with an instrument\'s OLD code after it changed, and a yes of it is written. '
            . '`' . Capabilities::cli() . 'serve` looks every time (opcache.revalidate_freq=0); whoever runs another server sets it so, or starts it again after an instrument changes. ';
        if ($validates === false || $every === false) {
            return '  · ' . $rule . 'This process loads no OPcache: it cannot say how a server of this house is set.';
        }
        $read = \sprintf('opcache.validate_timestamps=%s, opcache.revalidate_freq=%s', $validates === '' ? '0' : $validates, $every === '' ? '0' : $every);
        if (!filter_var($validates, \FILTER_VALIDATE_BOOL)) {
            return '  ! ' . $rule . 'PHP, as this process reads it, never looks again (' . $read . '): a server run so answers with the old code until it is started again.';
        }

        return (int) $every > 0
            ? '  ! ' . $rule . 'PHP, as this process reads it, looks again only every ' . (int) $every . ' s (' . $read . '): a server run so answers with the old code for that long after each change.'
            : '  · ' . $rule . 'PHP, as this process reads it, looks every time (' . $read . ').';
    }
}
