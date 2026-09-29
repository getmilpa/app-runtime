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

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\BrokenBootAnswer;
use Milpa\AppRuntime\Support\KernelDefinition;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * A front controller whose boot fails answers `503` and the reason, never `200` and the fatal (greenhouse decisions/0512).
 *
 * Measured (greenhouse evidence/1035 F1, evidence/1039): `php -S` and FrankenPHP classic answered a house that
 * did not boot with HTTP 200 and `Fatal error: … in /home/…` in the body. The last test here reproduces it on a
 * real `php -S` — the same front controller without the watch — and then answers it with the watch.
 *
 * @guards a boot that throws or dies of a compile fatal is answered 503 with `Milpa-House-Does-Not-Boot` and the
 *         one-line reason, with no absolute path; a boot that finishes is served as before; after `booted()` a fatal
 *         is not this watch's to answer
 *
 * @refuses nothing — the positive control is the unwatched front controller, which answers 200 with the fatal
 *
 * @subject-in milpa/app-runtime
 */
final class ABrokenBootAnswers503Test extends TestCase
{
    private const ROOT = '/srv/house';

    public function testAThrowableNobodyCaughtIsSaidAsTheProbeSaysIt(): void
    {
        $why = BrokenBootAnswer::reason([
            'type' => \E_ERROR,
            'message' => "Uncaught ArgumentCountError: Too few arguments to function App\\Plugins\\Blog\\BlogSeeder::__construct(), 0 passed in /srv/house/src/Plugins/Blog/Blog.php on line 26 and exactly 1 expected in /srv/house/src/Plugins/Blog/BlogSeeder.php:5\nStack trace:\n#0 /srv/house/src/Plugins/Blog/Blog.php(26): ...",
            'file' => '/srv/house/src/Plugins/Blog/BlogSeeder.php',
            'line' => 5,
        ], self::ROOT);

        self::assertSame('ArgumentCountError: Too few arguments to function App\Plugins\Blog\BlogSeeder::__construct(), 0 passed in src/Plugins/Blog/Blog.php on line 26 and exactly 1 expected', $why);
    }

    public function testACompileFatalIsSaidWithItsHouseRelativePlace(): void
    {
        $why = BrokenBootAnswer::reason([
            'type' => \E_COMPILE_ERROR,
            'message' => 'Class App\Plugins\Roto\Roto contains 1 abstract method and must therefore be declared abstract or implement the remaining methods (Milpa\Interfaces\Plugin\PluginInterface::install)',
            'file' => '/srv/house/src/Plugins/Roto/Roto.php',
            'line' => 5,
        ], self::ROOT);

        self::assertStringStartsWith('Fatal error: Class App\Plugins\Roto\Roto contains 1 abstract method', $why);
        self::assertStringEndsWith(' in src/Plugins/Roto/Roto.php on line 5', $why);
    }

    public function testAPathOutsideTheHouseIsCutToItsFileName(): void
    {
        $why = BrokenBootAnswer::reason(['type' => \E_PARSE, 'message' => 'syntax error, unexpected token "}"', 'file' => '/home/someone/family/getmilpa-x/src/Broken.php', 'line' => 3], self::ROOT);

        self::assertSame('Parse error: syntax error, unexpected token "}" in …/Broken.php on line 3', $why);
    }

    public function testTheAnswerIsTheWorkersAnswer(): void
    {
        $watch = BrokenBootAnswer::watch(self::ROOT);
        $watch->booted();
        $fresh = (new \ReflectionClass(BrokenBootAnswer::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BrokenBootAnswer::class, 'root'))->setValue($fresh, self::ROOT);
        $fatal = ['type' => \E_ERROR, 'message' => 'Uncaught RuntimeException: no in /srv/house/x.php:1', 'file' => '/srv/house/x.php', 'line' => 1];

        // Not told app.debug (the default): generic — the header name stays, the reason does not.
        $generic = $fresh->answerFor($fatal);
        self::assertNotNull($generic);
        self::assertSame(503, $generic['status']);
        self::assertSame(KernelDefinition::HIDDEN_REASON, $generic['headers']['Milpa-House-Does-Not-Boot']);
        self::assertSame("This house does not boot; it answers again as soon as it does.\n", $generic['body']);

        $fresh->showReasons(true);
        $answer = $fresh->answerFor($fatal);
        self::assertNotNull($answer);
        self::assertSame(503, $answer['status']);
        self::assertSame('RuntimeException: no', $answer['headers']['Milpa-House-Does-Not-Boot']);
        self::assertSame('no-store', $answer['headers']['Cache-Control']);
        self::assertStringStartsWith('This house does not boot: RuntimeException: no', $answer['body']);
        self::assertNull($fresh->answerFor(['type' => \E_WARNING, 'message' => 'a warning', 'file' => '/srv/house/x.php', 'line' => 1]), 'a warning is not a boot that failed');
        self::assertNull($fresh->answerFor(null));
        self::assertNull($watch->answerFor(['type' => \E_ERROR, 'message' => 'late', 'file' => '/srv/house/x.php', 'line' => 1]), 'after booted() a fatal is the 500 of serving, not this');
        self::assertFalse($watch->answer(['type' => \E_ERROR, 'message' => 'late', 'file' => '/srv/house/x.php', 'line' => 1]));
    }

    public function testWatchingHoldsOutputAndHidesErrorsUntilBooted(): void
    {
        $display = (string) ini_get('display_errors');
        ob_start();
        $level = ob_get_level();

        $watch = BrokenBootAnswer::watch(self::ROOT);
        self::assertSame('0', ini_get('display_errors'));
        self::assertSame($level + 1, ob_get_level());
        echo 'held';
        $watch->booted();
        $watch->booted();

        self::assertSame($display, ini_get('display_errors'));
        self::assertSame($level, ob_get_level());
        self::assertSame('held', ob_get_clean(), 'what was held went out when the boot finished');
    }

    /** The emission itself, in a process of its own (its headers are not sent yet): what was held is dropped, the 503 is written. */
    #[RunInSeparateProcess]
    public function testTheAnswerDropsWhatWasHeldAndWritesThe503(): void
    {
        ob_start();
        $watch = BrokenBootAnswer::watch(self::ROOT);
        $watch->showReasons(true);
        echo 'half a page the boot printed';

        $answered = $watch->answer(['type' => \E_COMPILE_ERROR, 'message' => 'Class A contains 1 abstract method', 'file' => '/srv/house/src/A.php', 'line' => 3]);
        $out = (string) ob_get_clean();

        self::assertTrue($answered);
        self::assertStringStartsWith('This house does not boot: Fatal error: Class A contains 1 abstract method in src/A.php on line 3', $out);
        self::assertStringNotContainsString('half a page', $out);
    }

    /** What the shutdown function does when the process ends without a fatal: nothing. */
    public function testTheShutdownOfABootThatEndedWellAnswersNothing(): void
    {
        ob_start();
        $watch = BrokenBootAnswer::watch(self::ROOT);
        $watch->booted();
        (new \ReflectionMethod($watch, 'shutdown'))->invoke($watch);

        self::assertSame('', ob_get_clean());
    }

    /** On a real `php -S`: the unwatched front controller is the defect (200 + fatal); the watched one answers 503. */
    public function testOnARealServerABrokenBootIsA503AndNeverA200(): void
    {
        $root = TinyHouse::create('Blog');
        try {
            $front = '<?php
require __DIR__ . "/../vendor/autoload.php";
$root = dirname(__DIR__);
$watch = %s;
$watch?->showReasons(is_file($root . "/var/debug")); // the house\'s app.debug, read before anything that can fail
$boot = require $root . "/config/boot.php";
$kernel = \Milpa\Runtime\Kernel::boot(["root" => $root, "plugins" => $boot["plugins"], "config" => [], "container" => $boot["container"]]);
$watch?->booted();
echo "served";
';
            file_put_contents($root . '/public/index.php', \sprintf($front, '\Milpa\AppRuntime\Support\BrokenBootAnswer::watch($root)'));
            file_put_contents($root . '/public/bare.php', \sprintf($front, 'null'));
            [$server, $port] = $this->serve($root);
            try {
                self::assertSame([200, 'served'], array_slice($this->get($port, '/index.php'), 0, 2));

                // seq 442's shape: a plugin whose boot() throws.
                file_put_contents($root . '/src/Plugins/Blog/Blog.php', str_replace('public function boot(): void {}', 'public function boot(): void { throw new \ArgumentCountError("Too few arguments"); }', TinyHouse::pluginSource('Blog')));
                [$bareStatus, $bareBody] = $this->get($port, '/bare.php');
                self::assertSame(200, $bareStatus, 'POSITIVE CONTROL: unwatched, the broken boot is a 200');
                self::assertStringContainsString('Fatal error', $bareBody);
                self::assertStringContainsString($root, $bareBody, 'POSITIVE CONTROL: and the page carries the house\'s path');

                // Not in debug: generic.
                [$status, $body, $headers] = $this->get($port, '/index.php');
                self::assertSame(503, $status);
                self::assertSame(KernelDefinition::HIDDEN_REASON, $headers['milpa-house-does-not-boot'] ?? null);
                self::assertSame("This house does not boot; it answers again as soon as it does.\n", $body);

                // In debug: the reason, still without a path.
                touch($root . '/var/debug');
                [$status, $body, $headers] = $this->get($port, '/index.php');
                self::assertSame(503, $status);
                self::assertSame('ArgumentCountError: Too few arguments', $headers['milpa-house-does-not-boot'] ?? null);
                self::assertStringStartsWith('This house does not boot: ArgumentCountError: Too few arguments', $body);
                self::assertStringNotContainsString($root, $body);
                self::assertStringNotContainsString('Stack trace', $body);

                // evidence/1038 n5's shape: a compile fatal nobody can catch — in debug, then not.
                file_put_contents($root . '/src/Plugins/Blog/Blog.php', TinyHouse::pluginSource('Blog', broken: true));
                [$status, $body, $headers] = $this->get($port, '/index.php');
                self::assertSame(503, $status);
                self::assertStringStartsWith('Fatal error: Class App\Plugins\Blog\Blog contains 1 abstract method', $headers['milpa-house-does-not-boot'] ?? '');
                self::assertStringContainsString('in src/Plugins/Blog/Blog.php on line', $body);
                self::assertStringNotContainsString($root, $body);
                unlink($root . '/var/debug');
                [$status, $body, $headers] = $this->get($port, '/index.php');
                self::assertSame(503, $status);
                self::assertSame(KernelDefinition::HIDDEN_REASON, $headers['milpa-house-does-not-boot'] ?? null);
                self::assertStringNotContainsString('Blog', $body);

                TinyHouse::plugin($root, 'Blog');
                self::assertSame([200, 'served'], array_slice($this->get($port, '/index.php'), 0, 2), 'fixed, it serves again');
            } finally {
                proc_terminate($server);
                proc_close($server);
            }
        } finally {
            TinyHouse::remove($root);
        }
    }

    /** @return array{0: resource, 1: int} */
    private function serve(string $root): array
    {
        for ($try = 0; $try < 5; ++$try) {
            $port = random_int(20000, 45000);
            $server = proc_open(
                [\PHP_BINARY, '-d', 'display_errors=1', '-d', 'opcache.enable_cli=0', '-S', '127.0.0.1:' . $port, '-t', $root . '/public'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
            self::assertIsResource($server);
            for ($wait = 0; $wait < 50; ++$wait) {
                usleep(40_000);
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($socket !== false) {
                    fclose($socket);

                    return [$server, $port];
                }
                if (!proc_get_status($server)['running']) {
                    break;
                }
            }
            proc_terminate($server);
            proc_close($server);
        }
        self::markTestSkipped('php -S could not bind a port here');
    }

    /** @return array{0: int, 1: string, 2: array<string, string>} */
    private function get(int $port, string $path): array
    {
        $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
        $headers = [];
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return [$status, (string) $body, $headers];
    }
}
