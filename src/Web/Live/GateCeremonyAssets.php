<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Web\Live;

/**
 * The two client files the ceremony component declares, and the only two it will serve.
 *
 * A NAMED LIST, not a directory read. The name arrives from a route parameter, so the question is not
 * «is this path safe» but «is this one of the files this package ships» — and answering it from a map
 * makes traversal unrepresentable rather than filtered. The pattern is {@see \Milpa\Live\Support\DesignTokens::path()}'s,
 * which serves the tokens the same way and for the same reason.
 *
 * A declaration pointing at a file that is not here is a LYING declaration: the `<link>` 404s and the
 * page looks styled while it is not, in silence. So the suite asserts every entry exists on disk —
 * the check is cheap and the failure it prevents is invisible (greenhouse decisions/0263).
 */
final class GateCeremonyAssets
{
    public const STYLESHEET = 'gate-ceremony.css';

    public const MODULE = 'gate-ceremony.js';

    /** The URL prefix the plugin routes these under; the component's declarations use it verbatim. */
    public const URL_PREFIX = '/webauthn/assets/';

    /** @var list<string> */
    public const FILES = [self::STYLESHEET, self::MODULE];

    /** The file on disk this package ships under that name, or null when it ships none. */
    public static function path(string $name): ?string
    {
        if (!\in_array($name, self::FILES, true)) {
            return null;
        }
        $file = \dirname(__DIR__, 3) . '/resources/components/gate-ceremony/' . $name;

        return is_file($file) ? $file : null;
    }

    /** The URL a host serves one under. */
    public static function url(string $name): string
    {
        return self::URL_PREFIX . $name;
    }

    /** The content type a host answers with — said here so the route does not have to guess. */
    public static function contentType(string $name): string
    {
        return $name === self::MODULE ? 'text/javascript; charset=utf-8' : 'text/css; charset=utf-8';
    }
}
