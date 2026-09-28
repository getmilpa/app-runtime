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

use Milpa\AppRuntime\Support\PhpBinary;
use Milpa\Attributes\PluginMetadata;
use Milpa\Http\HttpMethod;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Runtime\Kernel;

/**
 * The house observes the routes a promotion landed, the way a browser reaches them (greenhouse decisions/0494).
 *
 * ── THE DEBT THIS PAYS, MEASURED (greenhouse evidence/1024, B4) ─────────────────────────────────
 *
 * The resident delivered a blog as a controller route: an anonymous GET /blog answered 200 with the
 * published post. The house never knew. Its derived closure (decisions/0487) reads an observation of the
 * house serving, and only `screen:observe` emitted one — a route had no observer, so the session ended
 * with no verdict and no epilogue.
 *
 * ── WHY A FRESH PROCESS, AND WHY AT THE PROMOTION ──────────────────────────────────────────────
 *
 * The process that promotes booted its kernel BEFORE the promoted plugin existed: asked in-process, it
 * would answer 404 for a route that serves. Only a new process of the house sees the house that is now
 * there, and the promotion is the moment the house knows code landed. So after the promotion writes,
 * one fresh process lists the GET routes the touched plugins declare, and one fresh process per route
 * requests it through the house's own front controller (`public/index.php`) — anonymous, as a visitor.
 *
 * ── WHAT IT DOES NOT PROVE ──────────────────────────────────────────────────────────────────────
 *
 * That the body is the one the goal asked for. A 200 earns `served` with a digest of the body; any
 * other status is recorded as what the house answered, and never as served. Routes with parameters and
 * methods other than GET are not requested: the house does not invent arguments.
 */
final class HouseRouteObserver
{
    /** Past this many routes a promotion is observed in part, and the receipt says how many were left. */
    public const MAX_ROUTES = 8;

    /** The line the observing script prefixes its answer with, so a body that leaks to stdout is not read as one. */
    public const MARK = '@@house-observe ';

    private readonly string $script;

    /** The PHP each observing process runs — found by {@see PhpBinary}, because under FrankenPHP `PHP_BINARY` is empty (0505). */
    private readonly string $php;

    public function __construct(
        ?string $php = null,
        private readonly int $timeoutSeconds = 20,
        ?string $script = null,
    ) {
        $this->php = $php ?? PhpBinary::path();
        $this->script = $script ?? \dirname(__DIR__, 2) . '/resources/house-observe.php';
    }

    /**
     * The app plugins a set of landed paths touches, by directory — `['*']` when the paths change which plugins boot.
     *
     * @param list<string> $paths paths relative to the house root, as a promotion names them
     *
     * @return list<string>
     */
    public static function touchedPlugins(array $paths): array
    {
        $dirs = [];
        foreach ($paths as $path) {
            if (\in_array($path, ['config/plugins.php', 'storage/plugins.json'], true)) {
                return ['*'];
            }
            if (preg_match('~^(?:src|tests)/Plugins/([A-Za-z_][A-Za-z0-9_]*)/~', $path, $match) === 1) {
                $dirs[$match[1]] = true;
            }
        }

        return array_keys($dirs);
    }

    /**
     * The GET routes without parameters that the touched plugins of a booted house declare.
     *
     * A plugin is touched when its class file lives under `src/Plugins/<dir>/` for one of `$dirs`
     * (`['*']` touches every app plugin). Only plugins whose `boot()` ran count: a vetoed plugin's routes
     * are declared but not served.
     *
     * @param list<string> $dirs
     *
     * @return list<array{path: string, name: string, plugin: string}>
     */
    public static function routesOf(Kernel $kernel, string $root, array $dirs): array
    {
        $root = rtrim((string) (realpath($root) ?: $root), '/') . '/';
        $booted = $kernel->bootedPluginNames();
        $rows = [];
        foreach ($kernel->plugins() as $plugin) {
            if (!$plugin instanceof RouteProviderInterface) {
                continue;
            }
            $class = new \ReflectionClass($plugin);
            $attributes = $class->getAttributes(PluginMetadata::class);
            if ($attributes === [] || !\in_array($attributes[0]->newInstance()->name, $booted, true)) {
                continue;
            }
            $file = (string) (realpath((string) $class->getFileName()) ?: '');
            if (!str_starts_with($file, $root)
                || preg_match('~^src/Plugins/([A-Za-z_][A-Za-z0-9_]*)/~', substr($file, \strlen($root)), $match) !== 1
                || ($dirs !== ['*'] && !\in_array($match[1], $dirs, true))) {
                continue;
            }
            foreach ($plugin->routes() as $route) {
                if (!\in_array(HttpMethod::GET, $route->methods, true) || str_contains($route->path, '{')) {
                    continue;
                }
                $rows[$route->path] = ['path' => $route->path, 'name' => (string) ($route->name ?? ''), 'plugin' => $class->getShortName()];
            }
        }
        ksort($rows);

        return array_values($rows);
    }

    /**
     * Observe, in the house at `$root`, the GET routes the landed paths declare.
     *
     * Each entry names the route and what the house answered. Only a 200 from a process that finished
     * cleanly carries `predicate: served` with `environment: house`, the body's size and digest. `error`
     * is set when the house could not be asked at all — it did not boot, or it has no front controller.
     *
     * @param list<string> $paths paths relative to the house root, as a promotion names them
     *
     * @return array{observed: list<array<string, mixed>>, error?: string, unobserved?: int}
     */
    public function observe(string $root, array $paths): array
    {
        $dirs = self::touchedPlugins($paths);
        if ($dirs === []) {
            return ['observed' => []];
        }
        if (!is_file($root . '/public/index.php') || !is_file($root . '/vendor/autoload.php')) {
            return ['observed' => []];
        }

        [$exit, $listed] = $this->run(['routes', $root, (string) json_encode($dirs)]);
        if ($exit !== 0 || !\is_array($listed['routes'] ?? null)) {
            $why = \is_string($listed['error'] ?? null) ? $listed['error'] : 'exit ' . $exit;

            return ['observed' => [], 'error' => "the house did not boot to list its routes after the change ({$why})"];
        }

        $routes = array_values(array_filter($listed['routes'], static fn (mixed $r): bool => \is_array($r) && \is_string($r['path'] ?? null)));
        $observed = [];
        foreach (\array_slice($routes, 0, self::MAX_ROUTES) as $route) {
            [$exit, $answer] = $this->run(['get', $root, $route['path']]);
            $status = \is_int($answer['status'] ?? null) ? $answer['status'] : null;
            $entry = ['route' => 'GET ' . $route['path'], 'subject' => $route['path'], 'status' => $status, 'environment' => ['kind' => 'house']];
            if ($status === 200 && $exit === 0) {
                $entry = ['predicate' => 'served', ...$entry, 'servedAt' => $route['path'],
                    'bytes' => \is_int($answer['bytes'] ?? null) ? $answer['bytes'] : null,
                    'sha256' => \is_string($answer['sha256'] ?? null) ? $answer['sha256'] : null];
            } elseif ($exit !== 0) {
                // A process that died answered nothing a browser could trust, whatever status it had set.
                $entry['status'] = $status !== null && $status >= 500 ? $status : null;
                $entry['error'] = $exit === 124 || $exit === 137 ? "timed out after {$this->timeoutSeconds}s" : "the request process exited {$exit}";
            }
            $observed[] = $entry;
        }

        return ['observed' => $observed] + (\count($routes) > self::MAX_ROUTES ? ['unobserved' => \count($routes) - self::MAX_ROUTES] : []);
    }

    /**
     * Run the observing script once, bounded by `timeout`, and read its marked answer line.
     *
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function run(array $arguments): array
    {
        $command = ['timeout', '-k', '2', (string) $this->timeoutSeconds, $this->php, '-d', 'display_errors=stderr', $this->script, ...$arguments];
        $proc = proc_open(implode(' ', array_map('escapeshellarg', $command)), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($proc)) {
            return [127, []];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($proc);

        $answer = [];
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, self::MARK)) {
                $decoded = json_decode(substr($line, \strlen(self::MARK)), true);
                $answer = \is_array($decoded) ? $decoded : [];
            }
        }

        return [$exit, $answer];
    }
}
