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
 * THE HOUSE'S SECRETS, TAKEN OUT OF ANYTHING A TOOL HANDS THE RESIDENT (greenhouse decisions/0569).
 *
 * The run that founded this slice sent `live.secret` to the model endpoint in 15 of 43 requests, because
 * `source_read config/app.php` returned the file verbatim (evidence/1103). A tool that gives the resident
 * files or configuration must redact the house's secrets first.
 *
 * ── HOW THE HOUSE KNOWS WHAT A SECRET IS, WITHOUT A LIST ──────────────────────────────────────────
 *
 * Not a hand-kept list of key names — that fails OPEN the first time someone adds a credential and forgets
 * to add its name, which is the anti-pattern the slice's brief names. A secret is a VALUE the house
 * actually keeps as one: every scalar in the secret overlay ({@see SecretOverlay}, `.milpa/secrets.json` —
 * the copy that never leaves the machine, greenhouse decisions/0267). Redaction matches the values that are
 * really there, so a credential the house holds is covered the moment it is declared, named or not.
 *
 * ── IT FAILS CLOSED ──────────────────────────────────────────────────────────────────────────────
 *
 * If the overlay is MISSING, the house holds no secret and nothing is touched — the ordinary case. If it is
 * PRESENT but cannot be parsed, the house HAS secrets whose values we cannot read to match; handing the
 * content through anyway would be the fail-open mistake, so the whole content is withheld. «Cannot tell»
 * resolves to «withhold», never to «pass».
 *
 * Only values of at least {@see MIN_LENGTH} characters are matched: a one- or two-character secret would
 * redact half of any file, and a secret that short is not one worth protecting this way.
 *
 * ── AND THE CREDENTIALS IT READS FROM THE ENVIRONMENT (greenhouse decisions/0589) ───────────────────
 *
 * A provider key handed to the house in a variable is a value the house keeps as a secret too: it sends it. It
 * was not in the overlay, so nothing here knew it — measured (evidence/1130): once a test printed it, it went to
 * the model in 5 of 8 requests. {@see ProviderCredentials} is the one reader of those variables, and every value
 * it finds is matched like the overlay's. They need no house root: a caller that does not know it still keeps
 * the environment out.
 */
final class SecretRedaction
{
    public const REDACTED = '[secret]';

    /** Below this a value is not treated as a secret to match on: it would mask ordinary text. */
    private const MIN_LENGTH = 4;

    /**
     * Redact every secret value from a tool result, in place of structure: strings are masked, the shape
     * and every non-secret value are preserved so the resident still reads the rest.
     */
    public static function inResult(mixed $result, ?string $root): mixed
    {
        if (\is_string($result)) {
            return self::inText($result, $root);
        }
        if (\is_array($result)) {
            $out = [];
            foreach ($result as $key => $value) {
                $out[$key] = self::inResult($value, $root);
            }

            return $out;
        }

        return $result;
    }

    /**
     * Redact every secret value wherever it appears in a string. The rest of the text is untouched — the
     * control the slice measured: the resident keeps reading everything that is not a secret.
     */
    public static function inText(string $text, ?string $root): string
    {
        if ($root !== null && self::overlayUnreadable($root)) {
            // The house has secrets whose values we cannot read to match — withhold, do not pass.
            return self::REDACTED;
        }
        foreach (self::values($root) as $secret) {
            $text = str_replace($secret, self::REDACTED, $text);
        }

        return $text;
    }

    /**
     * Every value the house keeps as a secret, longest first so a secret that contains another (a token
     * that ends in a shorter one) is masked whole instead of leaving its tail dangling.
     *
     * @return list<string>
     */
    public static function values(?string $root): array
    {
        $values = [];
        $tree = [$root === null ? [] : SecretOverlay::sobre([], $root), ProviderCredentials::ofTheEnvironment()];
        array_walk_recursive(
            $tree,
            static function (mixed $value) use (&$values): void {
                if (\is_scalar($value) && \strlen((string) $value) >= self::MIN_LENGTH) {
                    $values[] = (string) $value;
                }
            },
        );
        usort($values, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return array_values(array_unique($values));
    }

    /**
     * The length of the longest value the house keeps as a secret, or 0 when it keeps none. A redactor that
     * reads a stream in pieces holds back this many characters (less one) at the tail, so a value split across
     * two consecutive pieces is caught whole before any of it is let out ({@see StreamingSecretRedaction}).
     */
    public static function maxValueLength(?string $root): int
    {
        $longest = 0;
        foreach (self::values($root) as $value) {
            $longest = max($longest, \strlen($value));
        }

        return $longest;
    }

    /**
     * The overlay file is present but is not readable JSON: the house holds secrets we cannot enumerate.
     * A missing file is not this — it is the ordinary «no secrets» case, which {@see values()} answers [].
     */
    private static function overlayUnreadable(string $root): bool
    {
        $file = $root . SecretOverlay::RUTA;
        if (!is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return true;
        }
        $decoded = json_decode($raw, true);

        return !\is_array($decoded);
    }
}
