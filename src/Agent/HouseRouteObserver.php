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

use Milpa\AppRuntime\Entity\SeedDeclarations;
use Milpa\AppRuntime\Support\ChildProcess;
use Milpa\AppRuntime\Support\PhpBinary;
use Milpa\Attributes\PluginMetadata;
use Milpa\Http\HttpMethod;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\AppRuntime\Web\ScreenRoute;
use Milpa\AppRuntime\Web\ScreenStore;
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
 *
 * ── WHY IT FAILED, FOR THE AGENT ONLY (greenhouse decisions/0539) ───────────────────────────────
 *
 * A route that answers 5xx, or whose process dies, carries the `cause` the house logged for it — read by
 * {@see RouteFailureCause} from the child's stderr, where PHP's `error_log()` writes in that process. It
 * travels in the result of the operation that observed; the page a visitor gets does not change (0506).
 *
 * ── ON DEMAND, NOT ONLY AT A PROMOTION (greenhouse decisions/0549) ──────────────────────────────
 *
 * Measured on a copy of Rod's first live run (t-0074, B-c): /blog answered 500, and the resident — which only
 * promotes to land code — read `BlogController.php` and concluded «GET /blog → 200». {@see observeRoute()} asks
 * the house one concrete path whenever `route:observe` is called, the same way, as the same anonymous visitor,
 * and also hands back the first bytes of what that visitor was served.
 */
final class HouseRouteObserver
{
    /** Past this many routes a promotion is observed in part, and the receipt says how many were left. */
    public const MAX_ROUTES = 8;

    /** The bytes of the body an on-demand observation hands back unless asked for other — ~400 tokens of a page. */
    /** The most of a mounted screen's page the house reads to judge what it lists (decisions/0576). */
    private const JUDGED_MAX = 2_097_152;

    public const EXCERPT = 1500;

    /** The most bytes of a body an on-demand observation ever hands back, whatever is asked — ~2k tokens. */
    public const EXCERPT_MAX = 8000;

    /** The line the observing script prefixes its answer with, so a body that leaks to stdout is not read as one. */
    public const MARK = '@@house-observe ';

    private readonly string $script;

    /** The PHP each observing process runs — found by {@see PhpBinary}, because under FrankenPHP `PHP_BINARY` is empty (0505). */
    private readonly string $php;

    private ?TrialRunner $trialRunner = null;

    public function __construct(
        ?string $php = null,
        private readonly int $timeoutSeconds = 20,
        ?string $script = null,
        // What confines a request that may write to the copy it runs in — the trials' own bubblewrap (decisions/0549 §9).
        private readonly string $bwrap = 'bwrap',
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
     * The routes of the screens mounted in a house whose declarations just landed (greenhouse decisions/0567 §3).
     *
     * A declared screen is not a plugin's source, so {@see touchedPlugins()} never sees it; a mount is a route that
     * landed all the same, and the house asks it like any other. Read from the declarations as they are on disk —
     * nothing boots to answer this. A file the house cannot read mounts nothing.
     *
     * @param list<string> $paths what landed, relative to the root
     *
     * @return list<string>
     */
    public static function mountedScreens(string $root, array $paths): array
    {
        // Rows that were just sown change what a page lists (greenhouse decisions/0574 §7): the house looks again.
        if (!\in_array(ScreenStore::DEFAULT_PATH, $paths, true) && SeedDeclarations::landed($paths) === []) {
            return [];
        }
        try {
            return array_keys(ScreenStore::fromConfig([], $root)->mounts());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * What the screen mounted at a route had to list, against the page the house was served there (greenhouse
     * decisions/0576 §1): a FRESH process of the house reads the entity's rows and counts. Null when there is nothing
     * to compare — no screen is mounted there, it binds no entity's rows — or the house could not be asked. The page
     * travels in a file that does not outlive the question.
     *
     * @return array<string, mixed>|null
     */
    public function content(string $root, string $route, string $body = ''): ?array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'milpa-page-');
        file_put_contents($file, $body);
        try {
            [, $answer] = $this->run(['content', $root, $route, $file]);
        } finally {
            @unlink($file);
        }

        return \is_array($answer['content'] ?? null) ? $answer['content'] : null;
    }

    /** The declared screen mounted at that route, or null: read from the declarations on disk, nothing boots. */
    private static function screenAt(string $root, string $route): ?string
    {
        try {
            foreach (ScreenStore::fromConfig([], $root)->mounts() as $mounted => $screen) {
                if (ScreenRoute::key((string) $mounted) === ScreenRoute::key($route)) {
                    return $screen;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Sow the seed declarations that landed (greenhouse decisions/0574 §4): a FRESH process of the house — one that
     * boots with the plugins as they now are — saves each declared row not seeded before, through the entity's own
     * repository. The process that promoted booted before the declaration (and maybe the plugin) existed.
     *
     * @param list<string> $paths what landed, relative to the root
     *
     * @return array{seeded: list<array<string, mixed>>, error?: string}
     */
    public function seed(string $root, array $paths): array
    {
        $landed = SeedDeclarations::landed($paths);
        if ($landed === []) {
            return ['seeded' => []];
        }
        [$exit, $said] = $this->run(['seed', $root, (string) json_encode($landed)]);
        if ($exit !== 0 || !\is_array($said['seeded'] ?? null)) {
            $why = \is_string($said['error'] ?? null) ? $said['error'] : 'exit ' . $exit;

            return ['seeded' => [], 'error' => "the house could not be asked to seed what landed ({$why})"];
        }

        return ['seeded' => array_values(array_filter($said['seeded'], 'is_array'))];
    }

    /**
     * Observe, in the house at `$root`, the GET routes the landed paths declare.
     *
     * Each entry names the route and what the house answered. Only a 200 from a process that finished
     * cleanly carries `predicate: served` with `environment: house`, the body's size and digest. A 5xx or a
     * process that died carries the `cause` the house logged, when it logged one. `error` is set when the
     * house could not be asked at all — it did not boot, or it has no front controller.
     *
     * @param list<string> $paths paths relative to the house root, as a promotion names them
     *
     * @return array{observed: list<array<string, mixed>>, error?: string, unobserved?: int}
     */
    public function observe(string $root, array $paths): array
    {
        $dirs = self::touchedPlugins($paths);
        $mounted = self::mountedScreens($root, $paths);
        if ($dirs === [] && $mounted === []) {
            return ['observed' => []];
        }
        if (!is_file($root . '/public/index.php') || !is_file($root . '/vendor/autoload.php')) {
            return ['observed' => []];
        }

        $listed = ['routes' => []];
        if ($dirs !== []) {
            [$exit, $listed] = $this->run(['routes', $root, (string) json_encode($dirs)]);
            if ($exit !== 0 || !\is_array($listed['routes'] ?? null)) {
                $why = \is_string($listed['error'] ?? null) ? $listed['error'] : 'exit ' . $exit;

                return ['observed' => [], 'error' => "the house did not boot to list its routes after the change ({$why})"];
            }
        }

        $routes = [];
        foreach ([...$listed['routes'], ...array_map(static fn (string $path): array => ['path' => $path], $mounted)] as $route) {
            if (\is_array($route) && \is_string($route['path'] ?? null)) {
                $routes[$route['path']] ??= $route;
            }
        }
        $routes = array_values($routes);
        $observed = [];
        foreach (\array_slice($routes, 0, self::MAX_ROUTES) as $route) {
            $observed[] = $this->request($root, $route['path'], 0, 'GET', null, null, false)['entry'];
        }

        return ['observed' => $observed] + (\count($routes) > self::MAX_ROUTES ? ['unobserved' => \count($routes) - self::MAX_ROUTES] : []);
    }

    /**
     * Whether a request can be confined here: the bubblewrap the trials use, with a user namespace to run in.
     */
    public function confines(): bool
    {
        return $this->trialRunner()->available();
    }

    /** The trials' own runner, so a confined request runs in the namespaces a trial found here (evidence/1092). */
    private function trialRunner(): TrialRunner
    {
        return $this->trialRunner ??= new TrialRunner($this->bwrap, php: $this->php);
    }

    /**
     * Ask the house at `$root` one concrete path, the way {@see observe()} asks each route (decisions/0549).
     *
     * The entry is the one a promotion records — so the house's derived closure reads it the same way — and it
     * always carries the body's `bytes` and `sha256` when the process said them. `excerpt` is the first `$excerpt`
     * bytes of the body (bounded by {@see EXCERPT_MAX}), cut at a whole character; `truncated` is how many bytes
     * it left out. `$path`, `$method` and `$body` must already be judged by the caller.
     *
     * `$confined` runs the request where nothing but `$root` can be written, with no network — what a request that
     * may write needs, because a copy links the house's `vendor/` and secrets (§9). Only a GET is ever `served`.
     *
     * @return array{entry: array<string, mixed>, excerpt: ?string, truncated: int}
     */
    public function observeRoute(
        string $root,
        string $path,
        int $excerpt = self::EXCERPT,
        string $method = 'GET',
        ?string $body = null,
        ?string $contentType = null,
        bool $confined = false
    ): array {
        $excerpt = max(0, min($excerpt, self::EXCERPT_MAX));
        $sent = null;
        if ($body !== null) {
            // Every copy is built with its own var/ (BootCandidate), the only place a body is ever sent.
            $sent = rtrim($root, '/') . '/var/route-observe-body-' . bin2hex(random_bytes(4));
            file_put_contents($sent, $body);
        }
        // The body file stays where it was written: `route:observe` only ever sends one inside a copy it throws away.
        ['entry' => $entry, 'answer' => $answer] = $this->request($root, $path, $excerpt, $method, $sent, $contentType, $confined);
        $bytes = \is_int($answer['bytes'] ?? null) ? $answer['bytes'] : null;
        if ($bytes !== null) {
            $entry += ['bytes' => $bytes, 'sha256' => \is_string($answer['sha256'] ?? null) ? $answer['sha256'] : null];
        }
        $head = \is_string($answer['head'] ?? null) ? self::wholeCharacters((string) base64_decode($answer['head'], true), $bytes ?? 0) : null;

        return ['entry' => $entry, 'excerpt' => $head === '' ? null : $head, 'truncated' => max(0, ($bytes ?? 0) - \strlen((string) $head))];
    }

    /**
     * Request one path in a process of the house, and say what a browser was answered.
     *
     * Only a GET answered 200 by a process that finished cleanly carries `predicate: served`. A 5xx or a process
     * that died carries the `cause` the house logged, when it logged one. The subject is the path without its query.
     *
     * @return array{entry: array<string, mixed>, answer: array<string, mixed>}
     */
    private function request(string $root, string $path, int $excerpt, string $method, ?string $bodyFile, ?string $contentType, bool $confined): array
    {
        $subject = explode('?', $path, 2)[0];
        // A mounted screen is judged on the whole page it served (greenhouse decisions/0576), so the whole page is asked.
        $screen = self::screenAt($root, $subject);
        $judged = $method === 'GET' && ! $confined && $screen !== null;
        $arguments = ['request', $root, $method, $path, (string) ($judged ? max($excerpt, self::JUDGED_MAX) : $excerpt), ...($bodyFile !== null ? [$bodyFile, (string) $contentType] : [])];
        [$exit, $answer, $stderr] = $this->run($arguments, $confined ? $root : null);
        $page = $judged && \is_string($answer['head'] ?? null) ? (string) base64_decode($answer['head'], true) : null;
        if ($judged) {
            // Whoever asked for an excerpt gets the excerpt it asked for, not the page the house read to judge it.
            $answer = array_diff_key($answer, ['head' => 0]) + ($excerpt > 0 && $page !== null ? ['head' => base64_encode(substr($page, 0, $excerpt))] : []);
        }
        $status = \is_int($answer['status'] ?? null) ? $answer['status'] : null;
        $entry = ['route' => $method . ' ' . $path, 'subject' => $subject, 'status' => $status, 'environment' => ['kind' => 'house']];
        if ($status === 200 && $exit === 0 && $method === 'GET') {
            $entry = ['predicate' => 'served', ...$entry, 'servedAt' => $subject,
                'bytes' => \is_int($answer['bytes'] ?? null) ? $answer['bytes'] : null,
                'sha256' => \is_string($answer['sha256'] ?? null) ? $answer['sha256'] : null,
                // WHAT KIND OF THING ANSWERED (greenhouse decisions/0579): the type the response named, as it named it.
                'contentType' => \is_string($answer['contentType'] ?? null) ? $answer['contentType'] : null];
            if (strtolower(explode(';', (string) $entry['contentType'], 2)[0]) === 'text/html') {
                // A page — and the declared screen that serves it, or that none does: then the house has no
                // declaration to compare it with. Read from the declarations; no process is started for this.
                $entry['surface'] = ['kind' => 'visual', 'screen' => $screen];
            }
            // A page longer than the house read is not judged on the part it read.
            $content = $page !== null && \strlen($page) === $entry['bytes'] ? $this->content($root, $subject, $page) : null;
            if ($content !== null) {
                $entry['content'] = $content;
            }
        } elseif ($exit !== 0) {
            // A process that died answered nothing a browser could trust, whatever status it had set.
            $entry['status'] = $status !== null && $status >= 500 ? $status : null;
            $entry['error'] = $exit === 124 || $exit === 137 ? "timed out after {$this->timeoutSeconds}s" : "the request process exited {$exit}";
        }
        if ($exit !== 0 || ($status !== null && $status >= 500)) {
            $cause = RouteFailureCause::read($stderr, $root);
            if ($cause !== null) {
                $entry['cause'] = $cause;
            }
        }

        return ['entry' => $entry, 'answer' => $answer];
    }

    /**
     * The first bytes of a body as text a result can carry: a character the cut split is left out, a byte that is
     * not UTF-8 reads U+FFFD — a JSON result that cannot be encoded would lose the whole observation.
     */
    private static function wholeCharacters(string $head, int $whole): string
    {
        if (\strlen($head) < $whole) {
            $end = \strlen($head);
            $start = $end;
            while ($start > 0 && (\ord($head[$start - 1]) & 0xC0) === 0x80) {
                --$start;
            }
            $lead = $start > 0 ? \ord($head[$start - 1]) : 0;
            $needs = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : ($lead >= 0xC0 ? 2 : 1));
            if ($start > 0 && $end - ($start - 1) < $needs) {
                $head = substr($head, 0, $start - 1);
            }
        }
        $text = json_decode((string) json_encode($head, \JSON_INVALID_UTF8_SUBSTITUTE), true);

        return \is_string($text) ? $text : '';
    }

    /**
     * Run the observing script once, bounded by `timeout`, and read its marked answer line — and its stderr.
     *
     * `error_log=` empty sends PHP's `error_log()` to this child's stderr whatever the house configured for its
     * server, so the line the house logs for a failed request reaches the observer (decisions/0539).
     *
     * `$writable` confines the child the way a trial is confined ({@see TrialRunner}): the whole filesystem read-only
     * but that directory, no network, its own pids — so a link out of a copy (its `vendor/`, the house's secrets)
     * is read, never written.
     *
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private function run(array $arguments, ?string $writable = null): array
    {
        $confine = $writable === null ? [] : [$this->bwrap, ...$this->trialRunner()->namespaces() ?? ['--unshare-net', '--unshare-pid', '--die-with-parent'],
            '--ro-bind', '/', '/', '--dev-bind', '/dev/null', '/dev/null', '--bind', $writable, $writable];
        $command = ['timeout', '-k', '2', (string) $this->timeoutSeconds, ...$confine, $this->php,
            '-d', 'display_errors=stderr', '-d', 'html_errors=0', '-d', 'error_log=', $this->script, ...$arguments];
        // No `/dev/null` for the child: inside a rehearsal's trial it cannot be opened (evidence/1060).
        $run = ChildProcess::run($command);
        if ($run === null) {
            return [127, [], ''];
        }
        ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr] = $run;

        $answer = [];
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, self::MARK)) {
                $decoded = json_decode(substr($line, \strlen(self::MARK)), true);
                $answer = \is_array($decoded) ? $decoded : [];
            }
        }

        return [$exit, $answer, $stderr];
    }
}
