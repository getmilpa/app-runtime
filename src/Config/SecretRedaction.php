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
 *
 * ── AND WHAT THE OTHER SECRET FILES AND THE KNOWN ENVIRONMENT CARRY (greenhouse decisions/0628) ───────
 *
 * A route the agent wrote returned the house's `.env`, its `auth.json` and a variable of its launcher, and
 * `route_observe` handed them to the model: none of them was in the overlay. The values of a `.env` that are not
 * shaped like settings and every string of `auth.json` at the root are matched now, and so are the variables named as
 * credentials or shaped like keys, each in the forms it takes once encoded. It is a defence: a value transformed by the code that returns it passes (evidence/1170).
 * The boundary is that a person says yes to that code before it is promoted.
 */
final class SecretRedaction
{
    public const REDACTED = '[secret]';

    /** Below this a value is not treated as a secret to match on: it would mask ordinary text. */
    private const MIN_LENGTH = 4;

    /** Below this a value of a secret file or of the environment is not matched: they hold settings too. */
    private const FILE_MIN_LENGTH = 8;

    /**
     * A variable named as a checksum (the official image carries `PHP_SHA256`): shaped like a key, published on purpose,
     * so it is not matched by its shape.
     */
    private const CHECKSUM_NAME = '/(^|_)(SHA\d*|MD5|CHECKSUM)\z/i';

    /** A variable named as a credential: its last word is one credentials are named by. */
    private const CREDENTIAL_NAME = '/(^|_)(KEY|API_KEY|SECRET|TOKEN|PASSWORD|PASSWD|PASS|CREDENTIALS?|AUTH|DSN|SALT)\z/i';

    /** @var array<string, list<string>> the values of the secret files, by the digest of what the files said */
    private static array $fileValues = [];

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
     * Every value the house keeps as a secret, and the forms it takes once encoded, longest first so a secret that
     * contains another (a token that ends in a shorter one) is masked whole instead of leaving its tail dangling.
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
        array_push($values, ...self::ofTheSecretFiles($root), ...self::ofTheKnownEnvironment());

        $forms = [];
        foreach ($values as $value) {
            array_push($forms, ...self::encoded($value));
        }
        usort($forms, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return array_values(array_unique($forms));
    }

    /**
     * A value as it appears in the text a tool returns: as it is, inside a JSON string, in a URL and in HTML. The
     * ledger is written from rendered JSON, so a secret with a quote or a slash in it reached it escaped and the
     * plain match missed it (greenhouse decisions/0628).
     *
     * @return list<string>
     */
    private static function encoded(string $value): array
    {
        $json = json_encode($value, \JSON_UNESCAPED_UNICODE);
        $forms = [$value, rawurlencode($value), htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE)];
        if (\is_string($json) && \strlen($json) >= 2) {
            $forms[] = substr($json, 1, -1);
            $forms[] = substr((string) json_encode($value), 1, -1);
        }

        return array_values(array_unique(array_filter($forms, static fn (string $f): bool => \strlen($f) >= self::MIN_LENGTH)));
    }

    /**
     * The values in the files the house keeps its secrets in, besides the overlay (greenhouse decisions/0628):
     * every string of `auth.json` at the root of the house, and every value of its `.env` that is not shaped like a
     * setting ({@see isACredential()}). A `.env` holds settings beside its credentials, and masking a setting makes
     * what a tool returns harder to read and to act on. Values shorter than {@see FILE_MIN_LENGTH} are left.
     *
     * An unreadable file is skipped, not withheld: this redaction is a defence, and the boundary is that a person
     * says yes to the code that would read these files ({@see \Milpa\AppRuntime\Agent\WhatAPromotionLands}).
     *
     * @return list<string>
     */
    private static function ofTheSecretFiles(?string $root): array
    {
        if ($root === null || ! is_dir($root)) {
            return [];
        }
        $files = array_values(array_filter(
            array_merge(glob($root . '/.env*') ?: [], [$root . '/auth.json']),
            static fn (string $file): bool => is_file($file) && SecretFiles::isSecret(basename($file)),
        ));
        $raws = [];
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if (\is_string($raw)) {
                $raws[$file] = $raw;
            }
        }
        // Keyed by what the files say, not by when they were written: an edit in the same second is read again.
        $key = hash('sha256', serialize($raws));
        if (isset(self::$fileValues[$key])) {
            return self::$fileValues[$key];
        }

        $values = [];
        foreach ($raws as $file => $raw) {
            if (basename($file) === 'auth.json') {
                $decoded = json_decode($raw, true);
                if (\is_array($decoded)) {
                    array_walk_recursive($decoded, static function (mixed $value) use (&$values): void {
                        if (\is_string($value)) {
                            $values[] = $value;
                        }
                    });
                }

                continue;
            }
            foreach (preg_split('/\R/', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $name = trim((string) preg_replace('/^export\s+/', '', trim($name)));
                $value = trim($value);
                $quote = $value[0] ?? '';
                if (($quote === '"' || $quote === "'") && \strlen($value) >= 2 && str_ends_with($value, $quote)) {
                    $value = substr($value, 1, -1);
                } else {
                    $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
                }
                if (self::isACredential($name, $value)) {
                    $values[] = $value;
                    array_push($values, ...self::userinfo($value));
                }
            }
        }
        $values = array_values(array_filter($values, static fn (string $v): bool => \strlen($v) >= self::FILE_MIN_LENGTH));
        self::$fileValues = [$key => $values];

        return $values;
    }

    /**
     * The values of the environment a house knows are credentials (greenhouse decisions/0628): a variable whose name
     * ends in a word credentials are named by, the userinfo of a URL in any variable, and, whatever its name, a value
     * shaped like a key ({@see isShapedLikeAKey()}): a launcher hands a secret over under any name (the first
     * independent review of 0628 measured one passing). The environment holds far more settings than a `.env` — paths,
     * versions, a host name — so only that shape is matched there. A secret of another shape, under a name the house
     * cannot tell from a setting, passes: this is a defence, not a list of every secret a launcher can hand over.
     *
     * @return list<string>
     */
    private static function ofTheKnownEnvironment(): array
    {
        $values = [];
        foreach (getenv() as $name => $value) {
            if (\strlen($value) < self::FILE_MIN_LENGTH) {
                continue;
            }
            if (preg_match(self::CREDENTIAL_NAME, (string) $name) === 1
                || (self::isShapedLikeAKey($value) && preg_match(self::CHECKSUM_NAME, (string) $name) !== 1)) {
                $values[] = $value;
            }
            array_push($values, ...self::userinfo($value));
        }

        return $values;
    }

    /**
     * Whether a value of a `.env` is matched: every value is, unless it is shaped like a setting (Rod's answer to
     * decisions/0628 masks the values of the secret files; the first independent review, L3', measured the first cut
     * matching only what looked like a credential, so a passphrase of words passed). One named as a credential, or a
     * URL that carries a password, is matched whatever its shape.
     */
    private static function isACredential(string $name, string $value): bool
    {
        if (preg_match(self::CREDENTIAL_NAME, $name) === 1 || self::userinfo($value) !== []) {
            return true;
        }

        return ! self::isShapedLikeASetting($value);
    }

    /**
     * A value a `.env` holds as a setting, left so what a tool returns stays readable: a number or a flag, a URL
     * without a password, a path, a time zone (`America/Mexico_City`), a host name or an address, a word or a
     * `snake_case` name of letters (`production`, `milpa_app`), or a phrase with spaces (`Milpa House`). Anything else
     * — a key, a passphrase joined by dashes, a value of letters and digits — is matched.
     */
    private static function isShapedLikeASetting(string $value): bool
    {
        return preg_match('/\s/', $value) === 1
            || is_numeric($value)
            || \in_array(strtolower($value), ['true', 'false', 'null', 'empty', 'yes', 'no', 'on', 'off'], true)
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1
            || preg_match('#^(/|\./|\.\./|~/)#', $value) === 1
            || preg_match('#^[A-Z][A-Za-z_]+(/[A-Z][A-Za-z0-9_+-]+)+\z#', $value) === 1
            || preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+\z/', $value) === 1
            || (\strlen($value) <= 24 && preg_match('/^[a-z]+(_[a-z]+)*\z/', $value) === 1);
    }

    /** Long, unbroken, letters and digits mixed, and not a URL or a path: the shape of a key. */
    private static function isShapedLikeAKey(string $value): bool
    {
        return \strlen($value) >= 16
            && preg_match('/\s/', $value) !== 1
            && preg_match('/\d/', $value) === 1
            && preg_match('/[A-Za-z]/', $value) === 1
            && preg_match('#^([a-z][a-z0-9+.-]*://|/)#i', $value) !== 1;
    }

    /**
     * A URL with a password in it, and the password: both are matched.
     *
     * @return list<string>
     */
    private static function userinfo(string $value): array
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://([^/@\s:]+):([^/@\s]+)@#i', $value, $userinfo) !== 1) {
            return [];
        }

        return \strlen($userinfo[2]) >= self::MIN_LENGTH ? [$value, $userinfo[2]] : [$value];
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
