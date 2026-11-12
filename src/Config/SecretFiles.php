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

use Milpa\AppRuntime\Standing\StandingLedger;

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

    /**
     * Stores that keep a RAW BEARER CREDENTIAL under `var/`/`storage/` — a value that, presented, opens
     * something (greenhouse decisions/0618). The passkey session store keys each record by the session id
     * itself, which is exactly the cookie that authenticates; the HTTP confirm-token store keys each entry by
     * the confirm token the client sends back. Neither lives under `.env`/the envelope, so {@see isSecret()}
     * does not name them and {@see scan()} (which skips `var/`) would never reach the session store. A confined
     * process reaches the real house's copy of these through `--ro-bind / /` by absolute path, so the mask names
     * them explicitly. The token store and the enrollment ledger are NOT here: the token store keeps only
     * sha256 hashes, and the ledger keeps grants over public key fingerprints — neither is a bearer credential
     * (whether a trial should also be blind to those books is a separate question, greenhouse decisions/0618).
     */
    public const BEARER_CREDENTIAL_STORES = ['var/passkey/sessions.json', 'storage/confirm-tokens.json'];

    /**
     * NON-BEARER STATE the house keeps that still names WHO MAY DO WHAT — the enrollment ledger (grants over
     * public key fingerprints), the invitation ledger (the root of trust: every key that redeemed an invitation is
     * rooted), the token store (sha256 hashes), the declaration ledger (what the house's
     * declaration writers wrote into its config lists, {@see \Milpa\AppRuntime\Support\DeclarationLedger}), and
     * the agent session log. None is a presented-and-it-opens bearer credential, so {@see isSecret()} does not
     * name them; but a confined process reaching them through `--ro-bind / /` learns the house's authority map
     * and reads whatever a session recorded. The confinement mask names them explicitly so agent-authored code
     * cannot read them from the house (greenhouse CH, hueco B2), and NO COPY OF THE HOUSE CARRIES THEM
     * ({@see withheldFromCopy()}, greenhouse decisions/0625).
     *
     * THE LEG THE MASK BROKE, STATED (greenhouse evidence/1205 and 1209): while copies still carried them, a writer
     * inside a trial built a boot candidate that tried to copy a masked book, failed, and refused the whole write —
     * in an equipped house the agent's seat could write nothing. A boot does not read these books (the declaration
     * ledger is read only by `capabilities:enable`), so a copy that leaves them out boots the same. Kept apart from
     * the bearer stores because the two answer different questions (0618).
     */
    public const NON_BEARER_STATE = ['storage/identity/enrollments.json', 'storage/identity/invitations.json', 'storage/tokens.json', 'storage/capabilities/declarations.json', 'var/agent-sessions.jsonl'];

    /**
     * The book of standing grants and its head (greenhouse decisions/0615): what a person granted, what the house
     * measured and what it absorbed. No copy carries it either. The trial runner masks it through the ledger's own
     * list of what exists ({@see StandingLedger::existingUnder()}), so it is kept apart from {@see NON_BEARER_STATE}.
     */
    private const STANDING_BOOK = [StandingLedger::PATH, StandingLedger::PATH . StandingLedger::HEAD];

    /** `.env*` names that are placeholders, not secrets. */
    private const TEMPLATES = ['.env.example', '.env.dist', '.env.template', '.env.sample'];

    /** Top-level directories a scan for secrets does not descend into. */
    private const SKIP = ['var', 'vendor', '.git', 'node_modules'];

    /**
     * Whether a path from the root of a house names a file the house keeps a secret in. Matched as a
     * case-insensitive filesystem (macOS, the Desktop's) resolves it ({@see fold()}), because such a filesystem
     * resolves `.ENV` and `.Milpa/Secrets.json` to the real file.
     */
    public static function isSecret(string $relative): bool
    {
        $low = self::fold($relative);
        $base = basename($low);
        if (str_starts_with($base, '.env') && ! \in_array($base, self::TEMPLATES, true)) {
            return true;
        }
        foreach (self::PATHS as $tail) {
            if ($low === $tail || str_ends_with($low, '/' . $tail)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a path, relative to a house, must be left OUT of any COPY of the house: a file that holds a secret
     * ({@see isSecret}, any depth), a bearer-credential store ({@see BEARER_CREDENTIAL_STORES}, decisions/0618), a
     * book of who may do what ({@see NON_BEARER_STATE}, decisions/0625), or the book of standing grants
     * ({@see STANDING_BOOK}, decisions/0615). Every copier leaves them out and asks this one rule —
     * `TrialWorkspace` (trials and exercises, by either of its two ways) and `BootCandidate` (boot candidates and
     * Composer stages) — and what no copy carries, no promotion carries back (`sandbox:promote`).
     */
    public static function withheldFromCopy(string $relative): bool
    {
        // As a case-insensitive filesystem resolves the path ({@see fold()}): there `storage/Tokens.json` IS the
        // token store, and a rule that missed it let a promotion write over it (the independent review of
        // decisions/0625, M1).
        return self::isSecret($relative) || self::names([...self::BEARER_CREDENTIAL_STORES, ...self::NON_BEARER_STATE, ...self::STANDING_BOOK], $relative);
    }

    /**
     * Whether `$relative` names one of `$paths` (relative to a house), compared as a case-insensitive filesystem
     * resolves it ({@see fold()}). For every guardian that refuses a path by its name.
     *
     * @param list<string> $paths
     */
    public static function names(array $paths, string $relative): bool
    {
        $relative = self::fold($relative);
        foreach ($paths as $path) {
            if (self::fold($path) === $relative) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path as a case-insensitive filesystem compares a PLAIN name: separators normalised, ASCII case folded.
     *
     * Only plain names. A case-folding filesystem also folds Unicode case and ignores invisible characters
     * (zero-width space, soft hyphen, the BOM…), each by rules of its own, so a name that differs from a named file
     * only by such characters IS that file there. Two reviews found a new spelling each time the fold tried to
     * follow those rules (decisions/0625, M1). It no longer tries: what writes into the house from a rehearsal
     * carries printable ASCII names only (`sandbox:promote`), and over those this comparison is exact on any
     * filesystem.
     */
    private static function fold(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }

    /**
     * The paths, relative to a house root, that {@see withheldFromCopy()} names for this house: every secret file
     * that exists under it ({@see under()}), and every store and book by its name, present or not. For a copier that
     * needs the list up front (rsync's `--exclude`) instead of asking path by path — the same rule, never a second.
     *
     * @return list<string>
     */
    public static function withheldUnder(string $root): array
    {
        return array_values(array_unique([...self::under($root), ...self::BEARER_CREDENTIAL_STORES, ...self::NON_BEARER_STATE, ...self::STANDING_BOOK]));
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
     * copy a trial, an exercise or a boot candidate kept (`var/trials/<id>/copy/`, `var/exercises/<id>/copy/`,
     * `var/boot-candidates/<id>/`). The trial
     * runner masks these from the confined process; a boot candidate LINKS them, but one left by a crashed probe
     * of a version before they were linked would be a copy, so it is covered too.
     *
     * The raw-bearer-credential stores ({@see BEARER_CREDENTIAL_STORES}) are added by their explicit paths: a
     * confined process reaches the REAL house's session store and confirm tokens through `--ro-bind / /` by
     * absolute path, and the session store lives under `var/`, which {@see scan()} never descends into.
     *
     * @return list<string>
     */
    public static function existingUnder(string $root): array
    {
        $paths = array_map(static fn (string $rel): string => $root . '/' . $rel, self::under($root));
        $bases = [$root, ...glob($root . '/var/trials/*/copy') ?: [], ...glob($root . '/var/exercises/*/copy') ?: [], ...glob($root . '/var/boot-candidates/*') ?: []];
        foreach ($bases as $base) {
            if (!is_dir($base)) {
                continue;
            }
            if ($base !== $root) {
                foreach (self::scan($base, '') as $rel) {
                    $paths[] = $base . '/' . $rel;
                }
            }
            foreach ([...self::BEARER_CREDENTIAL_STORES, ...self::NON_BEARER_STATE] as $store) {
                $path = $base . '/' . $store;
                if (is_file($path) && !is_link($path)) {
                    $paths[] = $path;
                }
            }
        }
        $paths = array_values(array_unique($paths));
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
