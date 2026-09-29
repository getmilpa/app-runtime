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
}
