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

/**
 * The one rule for a `next` target: a LOCAL absolute path, or the root — never a URL somebody else chose.
 *
 * The sign-in page redirects to wherever the gate sent the human from, and that value travels through
 * the query string, which means anyone can write it. An open redirect is the classic outcome: a link
 * to the house's own sign-in page that lands on a phishing site after a successful ceremony. So the
 * candidate must start with a single `/` (`//evil` is protocol-relative), carry no backslash (browsers
 * read `/\evil` as `//evil`), and no whitespace or control character; anything else falls back to `/`.
 * A scheme cannot appear in a value that starts with `/`, so the first rule covers `https://x` too.
 */
final class LocalPath
{
    private const PATTERN = '~^/(?!/)[^\\\\\s\x00-\x1f\x7f]*$~';

    /** The candidate when it is a local absolute path, otherwise `/` — the safe default, never an error. */
    public static function orRoot(mixed $candidate): string
    {
        if (!\is_string($candidate) || $candidate === '') {
            return '/';
        }

        return preg_match(self::PATTERN, $candidate) === 1 ? $candidate : '/';
    }
}
