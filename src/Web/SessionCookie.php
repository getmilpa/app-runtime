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

namespace Milpa\AppRuntime\Web;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The attributes of the session cookie the passkey ceremony mints — one rule, written once, read by
 * the controller that sets it and the gate that clears it (greenhouse decisions/0206, review).
 *
 * Always `HttpOnly` (script never reads it), `SameSite=Strict` (no cross-site request carries it) and
 * `Path=/`. `Secure` is added whenever the request came over `https`, OR whenever the host is not a
 * loopback name — `localhost`, `127.0.0.1`, `::1` — because those are the only origins a browser treats
 * as a secure context over plain `http`. The consequence is deliberate: on a plain-http LAN host
 * (`http://192.168.1.24:8080`) the ceremony still answers 200, but the browser refuses to store a
 * `Secure` cookie from an insecure origin, so the panel keeps sending the human to sign in. A session
 * that would travel in clear across a network is not one this door is willing to mint; serve `https`
 * or use the loopback name.
 */
final class SessionCookie
{
    /** The hosts a browser treats as a secure context over plain http. */
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /** The `Set-Cookie` value that stores `$value` under `$name` for this request's origin. */
    public static function set(string $name, string $value, ServerRequestInterface $request): string
    {
        return $name . '=' . $value . self::attributes($request);
    }

    /** The `Set-Cookie` value that removes the cookie `$name` for this request's origin. */
    public static function expire(string $name, ServerRequestInterface $request): string
    {
        return $name . '=; Max-Age=0' . self::attributes($request);
    }

    /** True when the cookie must carry `Secure`: an https request, or any host that is not loopback. */
    public static function secure(ServerRequestInterface $request): bool
    {
        $uri = $request->getUri();

        return strtolower($uri->getScheme()) === 'https'
            || !\in_array(strtolower($uri->getHost()), self::LOOPBACK, true);
    }

    private static function attributes(ServerRequestInterface $request): string
    {
        return '; HttpOnly; SameSite=Strict; Path=/' . (self::secure($request) ? '; Secure' : '');
    }
}
