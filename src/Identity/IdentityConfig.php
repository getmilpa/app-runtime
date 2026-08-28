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

namespace Milpa\AppRuntime\Identity;

/**
 * Reads the out-of-band root the operator declared — the fingerprints this app is willing to believe.
 *
 * Declared in `config/identity.php` as `['rooted' => ['<FP>', ...]]` (or a bare list of fingerprints).
 * Absent config means an EMPTY root: a house that declared no one it will believe enrolls no one, and
 * that is the safe default, not an error. The root is READ here and never written by the running app —
 * that is the whole point of it being out of band (decisions/0117).
 */
final class IdentityConfig
{
    /** Read the out-of-band root from the app's config/identity.php, or an empty root when absent. */
    public static function load(string $root): RootedSigners
    {
        $file = $root . '/config/identity.php';
        if (!is_file($file)) {
            return new RootedSigners([]);
        }

        $declared = require $file;
        $list = \is_array($declared) && isset($declared['rooted']) && \is_array($declared['rooted'])
            ? $declared['rooted']
            : (\is_array($declared) ? $declared : []);

        $fingerprints = [];
        foreach ($list as $fp) {
            if (\is_string($fp) && trim($fp) !== '') {
                $fingerprints[] = $fp;
            }
        }

        return new RootedSigners($fingerprints);
    }
}
