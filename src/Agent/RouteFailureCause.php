<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AppRuntime\Config\SecretOverlay;
use Milpa\AppRuntime\Support\BootProbe;

/**
 * Why a route of the house failed, read from the line the house itself logged — for the agent, never the visitor.
 *
 * ── THE DEFECT THIS CLOSES, MEASURED (greenhouse evidence/1071, B2) ─────────────────────────────
 *
 * In Rod's first live run GET /blog answered 500, and the promotion's receipt said «answered HTTP 500» and
 * nothing more. The resident spent its last four legs guessing. The cause was in the house's hand: the
 * observing process logged `Unhandled ContainerResolutionException at …DIContainer.php:526 — Cannot resolve
 * parameter $container for class …BlogController` on its stderr, and {@see HouseRouteObserver} read only
 * its stdout.
 *
 * ── WHAT IS SAID, AND WHAT IS NOT (greenhouse decisions/0539) ───────────────────────────────────
 *
 * The class, the message, `file:line` and the reference the public page shows — relative to the house. A
 * value the house keeps in `.milpa/secrets.json`, URL credentials, a bearer token and `password=`-style
 * pairs read `[secret]`; a path outside the house is cut to its file name. Never the trace, the body, or
 * the JSON context a logger appends. This lives only in the result of the operation that observed the
 * route: the public 500 page stays as decisions/0506 left it.
 */
final class RouteFailureCause
{
    /** The line `Milpa\Runtime\Http\ExceptionMiddleware` logs for a request that threw. */
    private const UNHANDLED = '/Unhandled ([A-Za-z_\\\\][\w\\\\]*) at (.+?):(\d+) — (.*?) \[ref ([0-9a-f]+)\]/s';

    /** The line PHP writes for a fatal nobody caught: `Uncaught X: m in f:l` or `m in f on line l`. */
    private const FATAL = '/(?:Fatal|Parse) error:\s+(?:Uncaught ([A-Za-z_\\\\][\w\\\\]*): )?(.*?) in (\S+?)(?::(\d+)| on line (\d+))(?=\s|$)/';

    /** What a secret reads as wherever it appeared. */
    private const REDACTED = '[secret]';

    /**
     * The cause the house logged on `$stderr`, or null when it logged none this reader knows.
     *
     * The request's own line wins over a fatal (the last `Unhandled` one — earlier lines are the boot's).
     *
     * @return array{class?: string, message: string, at: string, reference?: string}|null
     */
    public static function read(string $stderr, string $root): ?array
    {
        if (preg_match_all(self::UNHANDLED, $stderr, $all, \PREG_SET_ORDER) > 0) {
            $m = $all[\count($all) - 1];

            return [
                'class' => $m[1],
                'message' => self::clean($m[4], $root),
                'at' => self::clean($m[2] . ':' . $m[3], $root),
                'reference' => $m[5],
            ];
        }
        if (preg_match(self::FATAL, $stderr, $m) === 1) {
            $line = ($m[4] ?? '') !== '' ? $m[4] : ($m[5] ?? '');

            return [
                ...($m[1] !== '' ? ['class' => $m[1]] : []),
                'message' => self::clean($m[2], $root),
                'at' => self::clean($m[3] . ':' . $line, $root),
            ];
        }

        return null;
    }

    /**
     * The cause in one line for a receipt's note: `Class: message (at file:line)`.
     *
     * @param array{class?: string, message: string, at: string, reference?: string} $cause
     */
    public static function oneLine(array $cause): string
    {
        return (isset($cause['class']) ? $cause['class'] . ': ' : '') . $cause['message'] . ' (at ' . $cause['at'] . ')';
    }

    /** Secrets out, the house's root off, the host's other paths cut to a file name — one bounded line. */
    private static function clean(string $said, string $root): string
    {
        foreach (self::secretsOf($root) as $secret) {
            $said = str_replace($secret, self::REDACTED, $said);
        }
        $said = (string) preg_replace('~(\b[a-z][a-z0-9+.-]*://[^\s:/@]+):[^\s@/]+@~i', '$1:' . self::REDACTED . '@', $said);
        $said = (string) preg_replace('~\b(Bearer)\s+[^\s,;]+~i', '$1 ' . self::REDACTED, $said);
        $said = (string) preg_replace('~\b((?:password|passwd|secret|token|api[_-]?key)["\']?\s*[=:]\s*)["\']?[^\s,;"\']+["\']?~i', '$1' . self::REDACTED, $said);

        return BootProbe::oneLine($said, $root);
    }

    /**
     * Every value the house keeps as a secret, longest first so a secret that contains another goes whole.
     *
     * @return list<string>
     */
    private static function secretsOf(string $root): array
    {
        $values = [];
        $tree = SecretOverlay::sobre([], $root);
        array_walk_recursive(
            $tree,
            static function (mixed $value) use (&$values): void {
                if (\is_scalar($value) && \strlen((string) $value) >= 4) {
                    $values[] = (string) $value;
                }
            },
        );
        usort($values, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $values;
    }
}
