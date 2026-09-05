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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\SessionCookie;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The session cookie's attributes: HttpOnly, SameSite=Strict, Path=/ always; `Secure` off loopback or over https.
 */
final class SessionCookieTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function origins(): iterable
    {
        yield 'plain http on localhost' => ['http://localhost:8080/milpa/admin', false];
        yield 'plain http on 127.0.0.1' => ['http://127.0.0.1:8080/milpa/admin', false];
        yield 'https on localhost' => ['https://localhost/milpa/admin', true];
        yield 'plain http on a LAN host' => ['http://192.168.1.47:8080/milpa/admin', true];
        yield 'https on a real host' => ['https://panel.example.test/milpa/admin', true];
    }

    #[DataProvider('origins')]
    public function testSecureIsSetOffLoopbackOrOverHttps(string $uri, bool $secure): void
    {
        $request = new ServerRequest('GET', $uri);

        self::assertSame($secure, SessionCookie::secure($request));
        $set = SessionCookie::set('milpa_session', 'abc', $request);
        self::assertStringStartsWith('milpa_session=abc;', $set);
        self::assertStringContainsString('HttpOnly', $set);
        self::assertStringContainsString('SameSite=Strict', $set);
        self::assertStringContainsString('Path=/', $set);
        self::assertSame($secure, str_contains($set, 'Secure'));
    }

    public function testExpireTellsTheBrowserToDropIt(): void
    {
        $expire = SessionCookie::expire('milpa_session', new ServerRequest('GET', 'http://localhost/x'));

        self::assertStringStartsWith('milpa_session=;', $expire);
        self::assertStringContainsString('Max-Age=0', $expire);
    }
}
