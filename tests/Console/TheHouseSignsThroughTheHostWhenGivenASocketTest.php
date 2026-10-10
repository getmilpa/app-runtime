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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Console\RemoteOperationSigner;
use Milpa\Console\OperationSigner;
use PHPUnit\Framework\TestCase;

/**
 * WHO SIGNS A `--sign` HERE (greenhouse decisions/0611, decided by Rod 2026-10-10, option (b)). The ordinary house
 * signs with gpg on this machine (the terminal's own default). A CONTAINED house holds no key: when it is given a
 * host sign-socket — the Desktop bound one in and signs on the host, where the person approves — the signing crosses
 * to it, through a {@see RemoteOperationSigner}. An injected signer (a test, another host) always wins over both.
 */
final class TheHouseSignsThroughTheHostWhenGivenASocketTest extends TestCase
{
    /** @var string|false */
    private $previous;

    protected function setUp(): void
    {
        $this->previous = getenv(RemoteOperationSigner::SIGN_SOCKET_ENV);
    }

    protected function tearDown(): void
    {
        $this->previous === false
            ? putenv(RemoteOperationSigner::SIGN_SOCKET_ENV)
            : putenv(RemoteOperationSigner::SIGN_SOCKET_ENV . '=' . $this->previous);
    }

    public function testWithoutAHostSocketTheSignerIsTheDefaultGpg(): void
    {
        putenv(RemoteOperationSigner::SIGN_SOCKET_ENV);

        self::assertNull($this->effectiveSignerOf(new Application('/nonexistent')), 'no host socket → null, so CliRunner signs with gpg on this machine');
    }

    public function testWithAHostSocketTheHouseSignsThroughTheHost(): void
    {
        putenv(RemoteOperationSigner::SIGN_SOCKET_ENV . '=/run/milpa-sign.sock');

        self::assertInstanceOf(RemoteOperationSigner::class, $this->effectiveSignerOf(new Application('/nonexistent')));
    }

    public function testAnEmptyHostSocketIsNoSocket(): void
    {
        putenv(RemoteOperationSigner::SIGN_SOCKET_ENV . '=');

        self::assertNull($this->effectiveSignerOf(new Application('/nonexistent')), 'an empty value names no socket');
    }

    public function testAnInjectedSignerAlwaysWins(): void
    {
        putenv(RemoteOperationSigner::SIGN_SOCKET_ENV . '=/run/milpa-sign.sock');
        $injected = new class () implements OperationSigner {
            public function sign(string $operation, array $arguments, string $host, int $now): ?array
            {
                return null;
            }
        };

        self::assertSame($injected, $this->effectiveSignerOf(new Application('/nonexistent', $injected)), 'a signer the host handed in is not second-guessed');
    }

    private function effectiveSignerOf(Application $app): ?OperationSigner
    {
        return (new \ReflectionMethod($app, 'firmanteEfectivo'))->invoke($app);
    }
}
