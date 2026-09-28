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

use Milpa\AppRuntime\Config\MachineOverlay;
use Milpa\AppRuntime\Config\SecretOverlay;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What a booted kernel was defined by — so a process that outlives one request knows when it went stale.
 *
 * ── THE DEFECT, MEASURED (greenhouse evidence/1035, W1/W2; decisions/0505) ──────────────────────
 *
 * A FrankenPHP worker boots the kernel once and serves many requests with it. On fresh cattle, a
 * plugin installed THROUGH that worker answered 404 on 12 of 12 later requests, and a model written
 * through its own `/workspace/config` was unknown to the next turn — until the process restarted. It is
 * the defect of decisions/0494 (the process that promotes booted before the plugin existed), spread to
 * the whole server.
 *
 * ── DERIVED, NOT DECLARED ───────────────────────────────────────────────────────────────────────
 *
 * Nobody bumps a counter. The writers of what defines a kernel are many — `capabilities:enable`,
 * `plugins:*`, `sandbox:promote`, `config:set`, the wizards that write secrets, a person with an
 * editor — and the first one that forgot would leave a stale process nobody sees. Instead the process
 * remembers what it READ and asks whether it is still the same:
 *
 *   - the overlays the boot reads that are not code: `storage/plugins.json`, `.milpa/agent.json`,
 *     `.milpa/secrets.json`, and `vendor/composer/installed.php` (Composer rewrites it on every
 *     `require`, `update` and `remove`) — fingerprinted by {@see before()}, ahead of the boot;
 *   - every PHP file of the app the process included (`config/*.php`, `src/`, the front controller) —
 *     taken from `get_included_files()` as the process loads them, outside `vendor/`.
 *
 * The fingerprint is the CONTENT (`xxh128`; absent is a state too), never a clock: `filemtime` has a
 * one-second resolution, and a same-size write in the second of the boot would pass. An identical
 * rewrite — enabling a plugin that is already on — changes nothing and recycles nothing.
 *
 * ── WHAT A STALE PROCESS DOES: IT DOES NOT SERVE ────────────────────────────────────────────────
 *
 * Re-booting in the same process is not a new kernel: PHP never redefines a loaded class, and the
 * Composer autoloader in memory holds the maps from before the install — which in 1035 had moved
 * milpa/app-runtime itself to another version. So the host answers the request it cannot serve with
 * {@see retryHere()} and leaves its loop; the supervisor starts a clean process (0505 §3).
 */
final class KernelDefinition
{
    /** The overlays the boot reads that are not PHP — relative to the app root. */
    public const OVERLAYS = [
        'storage/plugins.json',
        MachineOverlay::RUTA,
        SecretOverlay::RUTA,
        'vendor/composer/installed.php',
    ];

    /** @var array<string, string> relative path → content fingerprint ('' when the file is absent) */
    private array $seen = [];

    /** @var array<string, true> relative paths whose content no longer matches what was read */
    private array $changed = [];

    private function __construct(
        private readonly string $root,
        private readonly string $vendor,
    ) {
    }

    /**
     * Fingerprint the overlays of the app at `$root` — call it BEFORE the kernel boots.
     *
     * Taken ahead of the boot so a write that lands while the kernel is booting reads as a change,
     * never as the state the kernel was built from.
     */
    public static function before(string $root): self
    {
        $real = rtrim((string) (realpath($root) ?: $root), '/');
        $definition = new self($real, $real . '/vendor/');
        foreach (self::OVERLAYS as $overlay) {
            $relative = ltrim($overlay, '/');
            $definition->seen[$relative] = self::fingerprint($real . '/' . $relative);
        }

        return $definition;
    }

    /**
     * The first input that changed since this kernel read it, as a path relative to the root — or null.
     *
     * Every call first takes in the app's PHP files the process included since the last one, at their
     * current content, then compares everything it holds. A host calls it when a request starts (another
     * process changed the house) and when it ends (this request did).
     */
    public function staleBecause(): ?string
    {
        clearstatcache();
        $prefix = $this->root . '/';
        foreach (get_included_files() as $file) {
            if (!str_starts_with($file, $prefix) || str_starts_with($file, $this->vendor)) {
                continue;
            }
            $relative = substr($file, \strlen($prefix));
            if (!isset($this->seen[$relative])) {
                $this->seen[$relative] = self::fingerprint($file);
            }
        }

        $first = null;
        foreach ($this->seen as $relative => $fingerprint) {
            if (isset($this->changed[$relative]) || self::fingerprint($prefix . $relative) !== $fingerprint) {
                $this->changed[$relative] = true;
                $first ??= $relative;
            }
        }

        return $first;
    }

    /**
     * The inputs this process holds, relative to the root, sorted — what it would notice change.
     *
     * @return list<string>
     */
    public function inputs(): array
    {
        $inputs = array_keys($this->seen);
        sort($inputs);

        return $inputs;
    }

    /**
     * Let the NEXT process compile what changed — call it before a stale process leaves.
     *
     * OPcache revalidates a file at most every `opcache.revalidate_freq` seconds (2 by default), and a
     * worker's replacement shares its cache: without this, the clean process could run the bytecode of
     * a file that just changed. The app files this process saw change are invalidated by force (their
     * content moved even if their mtime did not); every other cached script is invalidated only if its
     * file is newer than its bytecode — which is how the files Composer rewrote under `vendor/` are
     * found without knowing their names. Nothing happens without OPcache.
     *
     * NEVER `opcache_reset()`. Measured under FrankenPHP 1.12.7 (greenhouse evidence/1038, o2): it
     * restarts EVERY worker, and a request in flight in another one — a resident's turn — was killed
     * after 8 s and answered 200 with a fatal. `opcache_invalidate()` restarted nothing and let a 12 s
     * request finish (o3).
     */
    public function forgetCompiled(): void
    {
        if (!\function_exists('opcache_invalidate') || !(bool) \ini_get('opcache.enable')) {
            return;
        }
        foreach (array_keys($this->changed) as $relative) {
            if (str_ends_with($relative, '.php')) {
                opcache_invalidate($this->root . '/' . $relative, true);
            }
        }
        $status = \function_exists('opcache_get_status') ? opcache_get_status(true) : false;
        foreach (\is_array($status) && \is_array($status['scripts'] ?? null) ? array_keys($status['scripts']) : [] as $script) {
            opcache_invalidate((string) $script, false);
        }
    }

    /**
     * The answer to a request a stale process must not serve: go again, to the same place.
     *
     * `307` keeps the method and the body, and a browser or `fetch` repeats it on its own — the panel is
     * a browser, and `fetch` never retries a 503. The retry reaches a process whose kernel is current.
     * The location is the request's own path and query, with its leading slashes collapsed to one, so a
     * request for `//elsewhere` can never be turned into a redirect off this host.
     */
    public static function retryHere(ServerRequestInterface $request, ResponseFactoryInterface $factory): ResponseInterface
    {
        $uri = $request->getUri();
        $location = '/' . ltrim($uri->getPath(), '/');
        if ($uri->getQuery() !== '') {
            $location .= '?' . $uri->getQuery();
        }

        return $factory->createResponse(307)
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Retry-After', '0')
            ->withHeader('Connection', 'close');
    }

    private static function fingerprint(string $file): string
    {
        return is_file($file) ? (string) hash_file('xxh128', $file) : '';
    }
}
