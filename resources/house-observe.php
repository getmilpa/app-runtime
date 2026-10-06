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

/**
 * Observe the house in a process of its own (greenhouse decisions/0494) — run by HouseRouteObserver, never by hand.
 *
 * The process that promoted booted before the promoted code existed, so it cannot see it. This one boots
 * the house as it is now, and answers ONE question on a line marked `@@house-observe `:
 *
 *   php house-observe.php routes <root> '<json dirs>'   the GET routes without parameters the touched plugins declare
 *   php house-observe.php get <root> <path> [<n>]       what the house's own front controller answers an anonymous GET —
 *                                                       with the first <n> bytes of the body when asked (decisions/0549)
 *   php house-observe.php request <root> <method> <path> <n> [<body file> <content type>]
 *                                                       the same for any method, with a body — only ever run by
 *                                                       HouseRouteObserver inside a confined copy (decisions/0549 §9)
 *   php house-observe.php boot <root>                   whether the house, as it is now, boots at all (decisions/0506)
 *
 * `get` goes through `public/index.php` itself — the file a browser reaches — with no credentials, so what it
 * answers is what a visitor is served. The body is captured, never printed; its size and digest travel, and its
 * first <n> bytes (base64, so any bytes survive the line) only when `route:observe` asks for an excerpt.
 */
const HOUSE_OBSERVE_MARK = '@@house-observe ';

$mode = $argv[1] ?? '';
$root = $argv[2] ?? '';
if (!\in_array($mode, ['routes', 'get', 'request', 'boot', 'seed', 'content'], true) || !is_dir($root)) {
    fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => false, 'error' => 'usage: house-observe.php routes|get|request|boot|seed <root> <argument>']) . "\n");
    exit(2);
}
$root = (string) realpath($root);

// BOOT: the same kernel `coa` boots, and nothing else. A boot that throws says what it threw; one that dies of a
// fatal the engine does not let anybody catch (a class missing an interface method) says nothing here — it leaves
// its message on stderr and a non-zero exit, and the caller reads both (Milpa\AppRuntime\Support\BootProbe).
if ($mode === 'boot') {
    chdir($root);
    require $root . '/vendor/autoload.php';
    try {
        $app = new Milpa\AppRuntime\Console\Application($root);
        (new ReflectionMethod($app, 'kernel'))->invoke($app);
    } catch (Throwable $e) {
        fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => true]) . "\n");
    exit(0);
}

if ($mode === 'routes') {
    $dirs = json_decode($argv[3] ?? '[]', true);
    chdir($root);
    require $root . '/vendor/autoload.php';
    try {
        $app = new Milpa\AppRuntime\Console\Application($root);
        $kernel = (new ReflectionMethod($app, 'kernel'))->invoke($app);
        $routes = Milpa\AppRuntime\Agent\HouseRouteObserver::routesOf($kernel, $root, \is_array($dirs) ? array_values(array_filter($dirs, 'is_string')) : []);
    } catch (Throwable $e) {
        fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => false, 'error' => $e::class], \JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
    fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => true, 'routes' => $routes], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}

// SEED: the house, booted as it now is, saves the rows of the declarations that landed (greenhouse decisions/0574).
if ($mode === 'seed') {
    $landed = json_decode($argv[3] ?? '[]', true);
    chdir($root);
    require $root . '/vendor/autoload.php';
    try {
        $app = new Milpa\AppRuntime\Console\Application($root);
        $container = (new ReflectionMethod($app, 'kernel'))->invoke($app)->container();
        $seeded = (new Milpa\AppRuntime\Entity\SeedDeclarations($root))->apply(
            \is_array($landed) ? array_values(array_filter($landed, 'is_string')) : [],
            static fn (string $id): ?object => $container->has($id) && \is_object($service = $container->get($id)) ? $service : null,
        );
    } catch (Throwable $e) {
        fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => true, 'seeded' => $seeded], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}

// CONTENT: what the screen mounted at a route had to list, against the page the house was served there (greenhouse
// decisions/0576). The house, booted as it now is, reads the entity's rows; it decides nothing.
if ($mode === 'content') {
    $body = is_file($argv[4] ?? '') ? (string) file_get_contents($argv[4]) : '';
    chdir($root);
    require $root . '/vendor/autoload.php';
    try {
        $app = new Milpa\AppRuntime\Console\Application($root);
        $container = (new ReflectionMethod($app, 'kernel'))->invoke($app)->container();
        $config = $container->has(Milpa\Config\Config::class) ? $container->get(Milpa\Config\Config::class) : null;
        $live = $config instanceof Milpa\Config\Config ? $config->get('live') : null;
        $content = Milpa\AppRuntime\Web\ListedContent::of(
            Milpa\AppRuntime\Web\ScreenStore::fromConfig(\is_array($live) ? $live : [], $root),
            (string) ($argv[3] ?? ''),
            static fn (string $id): ?object => $container->has($id) && \is_object($service = $container->get($id)) ? $service : null,
            $body,
        );
    } catch (Throwable $e) {
        fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => true, 'content' => $content], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}

// `get <root> <path> [<n>]` is `request <root> GET <path> <n>`.
$request = $mode === 'request' ? array_slice($argv, 3) : ['GET', $argv[3] ?? '/', $argv[4] ?? '0'];
$method = strtoupper((string) ($request[0] ?? 'GET'));
$path = (string) ($request[1] ?? '/');
$head = max(0, (int) ($request[2] ?? 0));
$sent = isset($request[3]) && is_file($request[3]) ? (string) file_get_contents($request[3]) : null;
$contentType = (string) ($request[4] ?? '');
// An anonymous visitor: no cookie, no Authorization, no REMOTE_ADDR — so a loopback-only door answers as it
// answers anyone who is not on this machine. What this process inherited is no request of anybody's: a header or a
// credential its environment carries (`HTTP_*`, `PHP_AUTH_*`, `REMOTE_USER`) does not travel (decisions/0549).
$inherited = array_filter(
    array_diff_key($_SERVER, ['argv' => 0, 'argc' => 0, 'AUTH_TYPE' => 0, 'REMOTE_USER' => 0]),
    static fn (string $key): bool => !str_starts_with($key, 'HTTP_') && !str_starts_with($key, 'PHP_AUTH_'),
    \ARRAY_FILTER_USE_KEY,
);
$query = (string) parse_url('http://localhost' . $path, \PHP_URL_QUERY);
$_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'QUERY_STRING' => $query, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $root . '/public/index.php',
    'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'HTTP_HOST' => 'localhost', 'SERVER_PROTOCOL' => 'HTTP/1.1',
    'HTTP_ACCEPT' => 'text/html', 'REQUEST_TIME' => time(), 'REQUEST_TIME_FLOAT' => microtime(true)] + $inherited;
$_GET = [];
parse_str($query, $_GET);
$_POST = [];
$_COOKIE = [];

// A BODY, as a server hands it over: its type and length in `$_SERVER`, a form parsed into `$_POST`, and the bytes
// behind `php://input` — which the CLI leaves empty, so this process answers that one URL itself and opens every
// other `php://` stream (`temp`, `memory`, `stdout`…) with PHP's own wrapper.
if ($sent !== null) {
    $_SERVER['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'] = $contentType;
    $_SERVER['CONTENT_LENGTH'] = $_SERVER['HTTP_CONTENT_LENGTH'] = (string) \strlen($sent);
    if (str_starts_with(strtolower($contentType), 'application/x-www-form-urlencoded')) {
        parse_str($sent, $_POST);
    }
    HouseObserveInput::$bytes = $sent;
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', HouseObserveInput::class);
}

/** `php://input` with the body this request was given; any other `php://` URL opened by PHP's own wrapper. */
final class HouseObserveInput
{
    public static string $bytes = '';

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $inner = null;

    private int $at = 0;

    private bool $input = false;

    public function stream_open(string $url, string $mode, int $options, ?string &$opened): bool
    {
        if (strtolower($url) === 'php://input') {
            $this->input = true;

            return true;
        }
        stream_wrapper_restore('php');
        try {
            $inner = fopen($url, $mode);
        } finally {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
        }
        $this->inner = $inner === false ? null : $inner;

        return $this->inner !== null;
    }

    public function stream_read(int $count): string|false
    {
        if (!$this->input) {
            return $this->inner === null ? false : fread($this->inner, $count);
        }
        $chunk = (string) substr(self::$bytes, $this->at, $count);
        $this->at += \strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        return $this->input || $this->inner === null ? 0 : (int) fwrite($this->inner, $data);
    }

    public function stream_eof(): bool
    {
        return $this->input ? $this->at >= \strlen(self::$bytes) : ($this->inner === null || feof($this->inner));
    }

    public function stream_tell(): int
    {
        return $this->input ? $this->at : (int) ftell($this->inner);
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET): bool
    {
        if (!$this->input) {
            return $this->inner !== null && fseek($this->inner, $offset, $whence) === 0;
        }
        $to = match ($whence) {
            \SEEK_CUR => $this->at + $offset,
            \SEEK_END => \strlen(self::$bytes) + $offset,
            default => $offset,
        };
        if ($to < 0) {
            return false;
        }
        $this->at = $to;

        return true;
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->input ? ['size' => \strlen(self::$bytes)] : fstat($this->inner);
    }

    public function stream_flush(): bool
    {
        return $this->input || $this->inner === null || fflush($this->inner);
    }

    public function stream_truncate(int $size): bool
    {
        return !$this->input && $this->inner !== null && ftruncate($this->inner, $size);
    }

    public function stream_close(): void
    {
        if ($this->inner !== null) {
            fclose($this->inner);
        }
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /** @return resource|false */
    public function stream_cast(int $as)
    {
        return $this->inner ?? false;
    }
}

// The answer is written at shutdown, so a front controller that ends with `exit` still reports what it served.
register_shutdown_function(static function () use ($head): void {
    $body = '';
    while (ob_get_level() > 0) {
        $body = (string) ob_get_clean() . $body;
    }
    $status = http_response_code();
    // The Content-Type the response named, as it named it (decisions/0577): none named is none said.
    $contentType = null;
    foreach (\is_array($GLOBALS['__milpaHouseObservedHeaders'] ?? null) ? $GLOBALS['__milpaHouseObservedHeaders'] : [] as $line) {
        if (\is_string($line) && stripos($line, 'content-type:') === 0) {
            $contentType = trim(substr($line, 13));
        }
    }
    fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode([
        'ok' => true,
        'status' => \is_int($status) ? $status : null,
        'bytes' => \strlen($body),
        'sha256' => hash('sha256', $body),
        'contentType' => $contentType,
        ...($head > 0 ? ['head' => base64_encode(substr($body, 0, $head))] : []),
    ]) . "\n");
});

require __DIR__ . '/house-observe-headers.php';
ob_start();
chdir($root . '/public');
require $root . '/public/index.php';
