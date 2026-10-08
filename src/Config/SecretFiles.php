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

namespace Milpa\AppRuntime\Config;

/**
 * THE FILES OF A HOUSE THAT HOLD SECRETS — said once, for every copy the house makes of itself.
 *
 * A secret has one place to live, and a copy of the house is not it. The house makes copies — a boot candidate,
 * to boot a change before it is written; a trial, to run a call where nothing but the copy can be written — and
 * each must leave out the files where a secret lives: its environment file and the family beside it
 * (`.env`, `.env.local`, `.env.production`…), the envelope `provider:declare` writes, and Composer's
 * credentials. This is the one list; whatever copies the house reads it (greenhouse evidence/1161).
 *
 * `HouseWork` already refuses to write any file whose name begins with `.env`; this says the same of a copy and
 * of the trial runner's mask. The named templates are placeholders a project commits, not secrets.
 */
final class SecretFiles
{
    /** The envelope and Composer's credentials, matched by the tail of a path. */
    public const PATHS = ['.milpa/secrets.json', 'auth.json'];

    /** `.env*` names that are placeholders, not secrets. */
    private const TEMPLATES = ['.env.example', '.env.dist', '.env.template', '.env.sample'];

    /** Top-level directories a scan for secrets does not descend into. */
    private const SKIP = ['var', 'vendor', '.git', 'node_modules'];

    /**
     * Whether a path from the root of a house names a file the house keeps a secret in. Matched
     * case-insensitively, because a case-insensitive filesystem (macOS, the Desktop's) resolves `.ENV` and
     * `.Milpa/Secrets.json` to the real file.
     */
    public static function isSecret(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);
        $base = strtolower(basename($relative));
        if (str_starts_with($base, '.env') && ! \in_array($base, self::TEMPLATES, true)) {
            return true;
        }
        $low = strtolower($relative);
        foreach (self::PATHS as $tail) {
            if ($low === $tail || str_ends_with($low, '/' . $tail)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The paths, relative to a house root, of every file under it that holds a secret and exists now — the
     * environment family, the envelope, Composer's credentials, at any depth, outside `var/` and `vendor/`. A
     * trial's copy leaves these out.
     *
     * @return list<string>
     */
    public static function under(string $root): array
    {
        return self::scan($root, '');
    }

    /**
     * The absolute paths of every file that holds a secret and exists under a house: at its tree, and in each
     * copy a trial or a boot candidate kept (`var/trials/<id>/copy/`, `var/boot-candidates/<id>/`). The trial
     * runner masks these from the confined process; a boot candidate LINKS them, but one left by a crashed probe
     * of a version before they were linked would be a copy, so it is covered too.
     *
     * @return list<string>
     */
    public static function existingUnder(string $root): array
    {
        $paths = array_map(static fn (string $rel): string => $root . '/' . $rel, self::under($root));
        foreach ([...glob($root . '/var/trials/*/copy') ?: [], ...glob($root . '/var/boot-candidates/*') ?: []] as $copy) {
            if (is_dir($copy)) {
                foreach (self::scan($copy, '') as $rel) {
                    $paths[] = $copy . '/' . $rel;
                }
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * The ids of the trials under a house whose copy still holds a file that keeps a secret — named by
     * `coa doctor` so an operator can discard them (greenhouse evidence/1161).
     *
     * @return list<string>
     */
    public static function trialsHoldingASecret(string $root): array
    {
        $holding = [];
        foreach (glob($root . '/var/trials/*/copy', \GLOB_ONLYDIR) ?: [] as $copy) {
            if (self::scan($copy, '') !== []) {
                $holding[] = basename(\dirname($copy));
            }
        }
        sort($holding);

        return $holding;
    }

    /**
     * The secret files under a directory, as paths relative to it, `var/` and `vendor/` aside.
     *
     * @return list<string>
     */
    private static function scan(string $dir, string $prefix): array
    {
        $found = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ($prefix === '' && \in_array($entry, self::SKIP, true))) {
                continue;
            }
            $rel = $prefix === '' ? $entry : $prefix . '/' . $entry;
            $full = $dir . '/' . $entry;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $found = [...$found, ...self::scan($full, $rel)];
            } elseif (is_file($full) && self::isSecret($rel)) {
                $found[] = $rel;
            }
        }

        return $found;
    }
}
