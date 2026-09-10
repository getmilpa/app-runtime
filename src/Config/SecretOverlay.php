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
 * WHERE A SECRET LIVES — the machine's overlay, in the copy that never leaves the machine.
 *
 * ── A MILPA APP HAD NOWHERE TO PUT ONE ───────────────────────────────────────────────────────────
 *
 * Measured on `milpa/framework`, the live template: it ships no `.env`, LOADS no `.env`, and does not
 * ignore one. Its three config homes are `config/app.php` (committed — it is the file a person
 * opens), `.milpa/agent.json` ({@see MachineOverlay}, committed on purpose, beside the constitution)
 * and nothing else. So an API key had two possible destinations and both were wrong: a file that goes
 * to git, or a file nothing reads (greenhouse decisions/0267).
 *
 * That is not an oversight to route around. It is the precondition of any operation that declares a
 * provider — and writing one before the destination existed would have made the house say
 * «declared» about a value no request could use, which is a refutation condition of the pin's own
 * rung.
 *
 * ── WHY A SIBLING AND NOT A FLAG ─────────────────────────────────────────────────────────────────
 *
 * `MachineOverlay` already holds what a governed operation wrote, and BOTH surfaces already read it —
 * `bin/coa` through `Application`, the web through the front controller. So this borrows a reading
 * path that is already proven instead of inventing one, and a wizard's write applies to the next
 * request with no restart. That is why it is not `.env`: a variable file needs a loader this
 * framework does not have and a restart a clean UX cannot ask for.
 *
 * It is a SEPARATE FILE because the two have different lifetimes and different visibility. «What the
 * machine wrote» belongs beside the constitution and travels with the repository — that is the acta
 * trail. «What the machine wrote and must never leave this machine» cannot travel at all. One file
 * with a flag would make a `.gitignore` decision depend on reading its contents, and the first time
 * somebody got that wrong they would get it wrong with a key in it.
 *
 * ── WHAT IT REFUSES TO DO ────────────────────────────────────────────────────────────────────────
 *
 * IT NEVER HANDS A VALUE BACK TO A READER THAT ONLY WANTED TO KNOW. {@see declared()} answers which
 * paths hold a secret and nothing else; there is no method here that returns one for display, because
 * a surface that can print a key is a surface that will. The value reaches configuration — where the
 * code that needs it already looks — and reaches nobody else.
 */
final class SecretOverlay
{
    /**
     * Beside the machine's other overlay, and NEVER committed.
     *
     * The name says what it is to whoever finds it in a diff, which is the only defence against
     * somebody adding it to a repository by hand.
     */
    public const RUTA = '/.milpa/secrets.json';

    /** The line an app's `.gitignore` must carry for this to be safe at all. */
    public const IGNORE_LINE = '/.milpa/secrets.json';

    /**
     * The configuration with the machine's secrets on top.
     *
     * Applied AFTER {@see MachineOverlay::sobre()} by whoever assembles configuration, so a secret
     * wins over both the human's file and the machine's public overlay: it is the most specific thing
     * anybody declared, and it is the only one that could not have been declared anywhere else.
     *
     * An unreadable file returns the configuration untouched, for the reason its sibling gives: the
     * alternative turns a stray comma into an app with no configuration, silently. A missing file is
     * the ordinary case and not a failure — most apps hold no secret.
     *
     * @param array<string, mixed> $config what the human declared, with the machine's overlay already on it
     *
     * @return array<string, mixed>
     */
    public static function sobre(array $config, string $root): array
    {
        $read = self::read($root);

        return $read === [] ? $config : self::fundir($config, $read);
    }

    /**
     * WHICH PATHS HOLD A SECRET — never what they hold.
     *
     * This is the whole read surface a human-facing screen gets: «there is a key» or «there is
     * none». A method that returned the value for display would be used for display, and then a
     * key would live in a rendered page, a log line and a screenshot. The value's only consumer is
     * configuration.
     *
     * @return list<string> dotted paths, sorted — the same spelling `Config::get()` takes
     */
    public static function declared(string $root): array
    {
        $paths = [];
        self::walk(self::read($root), '', $paths);
        sort($paths);

        return $paths;
    }

    /** Whether this app holds any secret at all — the one question a landing screen needs. */
    public static function any(string $root): bool
    {
        return self::declared($root) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $root): array
    {
        $file = $root . self::RUTA;
        if (!is_file($file)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($file), true);

        return \is_array($raw) ? $raw : [];
    }

    /**
     * Recursive for arrays, replacing for everything else — the same merge its sibling makes, and
     * for the same measured reason: writing one key must not erase a neighbour nobody touched.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $top
     *
     * @return array<string, mixed>
     */
    private static function fundir(array $base, array $top): array
    {
        foreach ($top as $key => $value) {
            $base[$key] = \is_array($value) && \is_array($base[$key] ?? null)
                ? self::fundir($base[$key], $value)
                : $value;
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string>         $out
     */
    private static function walk(array $tree, string $prefix, array &$out): void
    {
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (\is_array($value) && $value !== [] && !array_is_list($value)) {
                self::walk($value, $path, $out);

                continue;
            }
            $out[] = $path;
        }
    }
}
