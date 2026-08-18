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

namespace Milpa\AppRuntime\Policy;

/**
 * Loads the app's declared PolicyProvider from `config/policy.php` — the same reviewed-code seam
 * `config/operations.php` already walks (greenhouse decisions/0056).
 *
 * A missing file is an app that declares nothing: null, and every consumer fails closed. A file
 * that EXISTS and returns something that is not a PolicyProvider throws, because a silent null
 * there would read as «no policy» to the runtime while the author believes they declared one —
 * the same class of quiet lie evidence/0152 measured in a field nobody read.
 */
final class PolicyConfig
{
    /** The app's declared provider, or null when `config/policy.php` does not exist. */
    public static function load(string $root): ?PolicyProvider
    {
        $archivo = $root . '/config/policy.php';
        if (! is_file($archivo)) {
            return null;
        }

        $declarado = require $archivo;
        if (\is_string($declarado) && class_exists($declarado)) {
            $declarado = new $declarado();
        }
        if (! $declarado instanceof PolicyProvider) {
            throw new \InvalidArgumentException(
                'config/policy.php must return a ' . PolicyProvider::class . ' instance or class-string; '
                . 'a declaration that loads as something else is an error the author must see, not a silent null',
            );
        }

        return $declarado;
    }
}
