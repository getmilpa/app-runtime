<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Telegram;

/**
 * Starts tests/Telegram/fake-bot-api.php on a free loopback port for one test class. It is NOT Telegram.
 */
trait FakeTelegram
{
    private const TOKEN = '123456:TEST-not-a-real-token';

    /** @var resource|null */
    private static $server = null;
    private static string $api = '';
    private static string $state = '';

    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('needs proc_open');
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($probe, false), \strlen('127.0.0.1:'));
        fclose($probe);
        self::$state = sys_get_temp_dir() . '/milpa-fake-bot-' . bin2hex(random_bytes(4)) . '.json';
        self::$api = 'http://127.0.0.1:' . $port;
        self::$server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake-bot-api.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['FAKE_BOT_TOKEN' => self::TOKEN, 'FAKE_BOT_STATE' => self::$state, 'PATH' => (string) getenv('PATH')],
        ) ?: null;
        for ($i = 0; $i < 100 && @file_get_contents(self::$api . '/__state') === false; ++$i) {
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$state);
        @unlink(self::$state . '.lock');
    }

    /** @return array<string, mixed> */
    private static function told(): array
    {
        return (array) json_decode((string) file_get_contents(self::$api . '/__state'), true);
    }
}
