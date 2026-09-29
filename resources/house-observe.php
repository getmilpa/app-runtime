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
 *   php house-observe.php get <root> <path>             what the house's own front controller answers an anonymous GET
 *   php house-observe.php boot <root>                   whether the house, as it is now, boots at all (decisions/0506)
 *
 * `get` goes through `public/index.php` itself — the file a browser reaches — with no credentials, so what it
 * answers is what a visitor is served. The body is captured, never printed; only its size and digest travel.
 */
const HOUSE_OBSERVE_MARK = '@@house-observe ';

$mode = $argv[1] ?? '';
$root = $argv[2] ?? '';
if (!\in_array($mode, ['routes', 'get', 'boot'], true) || !is_dir($root)) {
    fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode(['ok' => false, 'error' => 'usage: house-observe.php routes|get|boot <root> <argument>']) . "\n");
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

$path = $argv[3] ?? '/';
// An anonymous visitor: no cookie, no Authorization, no REMOTE_ADDR — so a loopback-only door answers as it
// answers anyone who is not on this machine.
$_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $root . '/public/index.php',
    'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'HTTP_HOST' => 'localhost', 'SERVER_PROTOCOL' => 'HTTP/1.1',
    'HTTP_ACCEPT' => 'text/html', 'REQUEST_TIME' => time(), 'REQUEST_TIME_FLOAT' => microtime(true)] + array_diff_key($_SERVER, ['argv' => 0, 'argc' => 0]);
$_GET = [];
$_POST = [];
$_COOKIE = [];

// The answer is written at shutdown, so a front controller that ends with `exit` still reports what it served.
register_shutdown_function(static function (): void {
    $body = '';
    while (ob_get_level() > 0) {
        $body = (string) ob_get_clean() . $body;
    }
    $status = http_response_code();
    fwrite(\STDOUT, HOUSE_OBSERVE_MARK . json_encode([
        'ok' => true,
        'status' => \is_int($status) ? $status : null,
        'bytes' => \strlen($body),
        'sha256' => hash('sha256', $body),
    ]) . "\n");
});

ob_start();
chdir($root . '/public');
require $root . '/public/index.php';
