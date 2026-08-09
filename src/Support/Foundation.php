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

namespace Milpa\AppRuntime\Support;

/**
 * The constitution of this app: read it, and write it exactly once.
 *
 * ── WHY THE SYSTEM NEEDS HANDS FOR THIS ─────────────────────────────────────────────────────────
 *
 * The rite of foundation was doctrine without a system to teach it: no operation could read or
 * write `.milpa/foundation.json`, so an agent asked to found an app COULD NOT — measured three
 * times out of three before these hands existed (greenhouse evidence/0004, decisions/0004). A
 * maturity criterion that says «the system teaches without the founder in the prompt» forbids
 * exactly that gap.
 *
 * ── WHAT FOUNDING IS, AND IS NOT ────────────────────────────────────────────────────────────────
 *
 * The constitution is minimal constitutional identity — domain, objective, boundaries,
 * authorities, birth date — and it is written ONCE. Amending it is a recorded decision under
 * `.milpa/decisions/`, never a silent overwrite, which is why {@see found} refuses a second
 * founding instead of merging one. And a foundation without its record is hearsay: the same act
 * that writes the constitution writes its acta.
 */
final class Foundation
{
    private const SCHEMA = 'milpa.foundation/v1';

    /** Closed vocabulary, v1: who may hold an authority. Authenticating the actual principal
     * belongs to governance/identity — this arrow only demands a RECOGNIZABLE form. */
    private const AUTHORITY_FORMS = ['human'];

    private const REQUIRED_AUTHORITIES = ['product', 'destructive_changes'];

    /**
     * The first arrow's condition, adjudicated from durable state (greenhouse evidence/0009).
     *
     * Four verdicts, and the distinction is the point: «could not verify» is not «is wrong».
     * `unfounded` — no sufficient foundation, the rite proceeds. `founded` — the transition is
     * earned. `invalid` — an artifact pretends and contradicts: repair, never re-found.
     * `indeterminate` — honest inability to adjudicate, typed reason, touch nothing. The frozen
     * property: ONLY ABSENCE authorizes the rite; defective presence authorizes repair, never
     * silent substitution — a future version's foundation must never read as «not founded».
     *
     * @param null|string $root test seam
     *
     * @return array{verdict: string, reason: string, foundation: null|array<string, mixed>}
     */
    public static function verdict(?string $root = null): array
    {
        $root ??= Capabilities::raizDeLaApp();
        $file = $root . '/.milpa/foundation.json';

        if (!is_file($file)) {
            return ['verdict' => 'unfounded', 'reason' => 'foundation_absent', 'foundation' => null];
        }

        $doc = json_decode((string) file_get_contents($file), true);
        if (!\is_array($doc)) {
            return ['verdict' => 'invalid', 'reason' => 'foundation_json_invalid', 'foundation' => null];
        }

        $schema = $doc['schema'] ?? null;
        if (!\is_string($schema) || '' === $schema) {
            // Not even claiming the contract is malformed, not mysterious.
            return ['verdict' => 'invalid', 'reason' => 'foundation_schema_missing', 'foundation' => null];
        }
        if (self::SCHEMA !== $schema) {
            // Possibly written by a future Milpa. Reading «I don't understand it» as «not
            // founded» would invite re-founding a house that already has a constitution.
            return ['verdict' => 'indeterminate', 'reason' => 'foundation_schema_unsupported', 'foundation' => null];
        }

        $domain = $doc['domain'] ?? null;
        $foundedAt = $doc['founded_at'] ?? null;
        if (null === $domain && null === $foundedAt) {
            // The pristine placeholder a newborn ships: nothing pretends a completed founding.
            // This IS absence of declaration — the rite proceeds and fills it (evidence/0007).
            return ['verdict' => 'unfounded', 'reason' => 'foundation_placeholder', 'foundation' => null];
        }
        if (!\is_string($domain) || '' === trim($domain)) {
            return ['verdict' => 'invalid', 'reason' => 'foundation_domain_empty', 'foundation' => null];
        }

        $authorities = $doc['authorities'] ?? null;
        if (!\is_array($authorities)) {
            return ['verdict' => 'invalid', 'reason' => 'foundation_authority_missing', 'foundation' => null];
        }
        foreach (self::REQUIRED_AUTHORITIES as $role) {
            $form = $authorities[$role] ?? null;
            if (!\is_string($form)) {
                return ['verdict' => 'invalid', 'reason' => 'foundation_authority_missing', 'foundation' => null];
            }
            if (!\in_array($form, self::AUTHORITY_FORMS, true)) {
                return ['verdict' => 'invalid', 'reason' => 'foundation_authority_unrecognized', 'foundation' => null];
            }
        }

        return ['verdict' => 'founded', 'reason' => 'ok', 'foundation' => $doc];
    }

    /**
     * What this app IS — or, if nothing yet, how it becomes something.
     *
     * An unfounded app answering an empty object would leave the caller exactly where it started.
     * The teaching IS the answer: which operation founds, and what it declares. It is the same
     * argument `capabilities` makes for growth, applied to identity.
     *
     * @param null|string $root test seam, same as {@see Capabilities::declaredBy()}
     *
     * @return array<string, mixed>
     */
    public static function answer(?string $root = null): array
    {
        $v = self::verdict($root);

        return match ($v['verdict']) {
            'founded' => ['founded' => true, 'verdict' => 'founded', 'foundation' => $v['foundation']],
            'unfounded' => ['founded' => false, 'verdict' => 'unfounded', 'teach' => self::teach()],
            // Defective presence teaches REPAIR, never the rite: offering the rite here would
            // invite substituting a constitution that already exists, however damaged.
            'invalid' => ['founded' => false, 'verdict' => 'invalid', 'reason' => $v['reason'],
                'repair' => '.milpa/foundation.json exists and contradicts its contract — repair the document; re-founding is refused'],
            default => ['founded' => false, 'verdict' => 'indeterminate', 'reason' => $v['reason'],
                'hint' => 'this foundation cannot be adjudicated honestly (possibly a newer schema) — do not build on it and do not rewrite it'],
        };
    }

    /**
     * The rite: write the constitution and its acta, exactly once.
     *
     * @param array<string, mixed> $input
     * @param null|string          $root  test seam
     *
     * @return array<string, mixed>
     */
    public static function found(array $input, ?string $root = null): array
    {
        $root ??= Capabilities::raizDeLaApp();
        $file = $root . '/.milpa/foundation.json';

        // ONLY absence authorizes the rite (evidence/0009): `unfounded` covers the missing file
        // and the pristine placeholder the rite fills. Everything else refuses — each for its
        // own reason, because «could not verify» must never read as «may overwrite».
        $v = self::verdict($root);
        if ('founded' === $v['verdict']) {
            return [
                'ok' => false,
                'error' => 'this app is already founded — amending a constitution is a recorded '
                    . 'decision under .milpa/decisions/, never a second founding',
            ];
        }
        if ('invalid' === $v['verdict']) {
            return ['ok' => false, 'error' => '.milpa/foundation.json exists and contradicts its contract ('
                . $v['reason'] . ') — repair it; re-founding over defective presence is refused'];
        }
        if ('indeterminate' === $v['verdict']) {
            return ['ok' => false, 'error' => 'this foundation cannot be adjudicated honestly ('
                . $v['reason'] . ') — refusing to touch it'];
        }

        $domain = \is_string($input['domain'] ?? null) ? trim($input['domain']) : '';
        $objective = \is_string($input['objective'] ?? null) ? trim($input['objective']) : '';
        if ('' === $domain || '' === $objective) {
            // Guessing an identity nobody declared would be the agent deciding what the app is —
            // the exact move the named-target gate exists to prevent.
            return ['ok' => false, 'error' => 'founding declares at least a domain and an objective'];
        }

        $boundaries = array_values(array_filter(
            \is_array($input['boundaries'] ?? null) ? $input['boundaries'] : [],
            static fn ($b): bool => \is_string($b) && '' !== trim($b),
        ));

        // Authorities the caller does not declare default to the HUMAN. An agent founding an app
        // must not be able to grant itself authority by omission.
        $authorities = \is_array($input['authorities'] ?? null) ? $input['authorities'] : [];
        $authorities += ['product' => 'human', 'destructive_changes' => 'human'];

        $foundation = [
            'schema' => self::SCHEMA,
            'domain' => $domain,
            'objective' => $objective,
            'boundaries' => $boundaries,
            'authorities' => $authorities,
            'founded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $actaFile = self::actaPath($root);
        $number = substr(basename($actaFile), 0, 4);
        $acta = "# {$number} · Founding acta — {$domain}\n\n"
            . "**When**: {$foundation['founded_at']}.\n"
            . "**What**: this app was founded through `foundation:found` — the domain was named by "
            . "the human and the write passed the operation's consent gate.\n\n"
            . "**Declared**:\n\n```json\n"
            . json_encode($foundation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n```\n\n"
            . "Amending this constitution is a recorded decision in this directory — never a "
            . "silent edit of `foundation.json`.\n";

        if (($input['dry_run'] ?? false) === true) {
            // Consent is given to something legible, not to a name — the exact writes, shown.
            return ['ok' => true, 'dry_run' => true, 'would_write' => [
                'foundation' => $foundation,
                'acta' => $actaFile,
            ]];
        }

        if (!is_dir(\dirname($actaFile)) && !mkdir(\dirname($actaFile), 0o775, true)) {
            return ['ok' => false, 'error' => 'could not create .milpa/decisions/ under ' . $root];
        }

        file_put_contents($file, json_encode($foundation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        file_put_contents($actaFile, $acta);

        return ['ok' => true, 'foundation' => $foundation, 'wrote' => [$file, $actaFile]];
    }

    /**
     * The unfounded answer IS the teaching: which operation founds, and what it declares.
     *
     * @return array<string, mixed>
     */
    private static function teach(): array
    {
        return [
            'what' => 'This app has no constitution yet: nobody has declared what it is for.',
            'how' => 'Call `foundation:found` with the domain the HUMAN named, an objective, '
                . 'and the boundaries it must never cross. The rite writes '
                . '.milpa/foundation.json and its founding acta — once.',
            'declares' => ['domain', 'objective', 'boundaries', 'authorities'],
        ];
    }

    /**
     * The acta lands as the next numbered decision — 0001 in a newborn, later if `.milpa/decisions/`
     * already carries recorded choices (a template may ship some).
     */
    private static function actaPath(string $root): string
    {
        $dir = $root . '/.milpa/decisions';
        $next = 1;
        foreach (glob($dir . '/[0-9][0-9][0-9][0-9]-*.md') ?: [] as $existing) {
            $next = max($next, (int) basename($existing) + 1);
        }

        return \sprintf('%s/%04d-fundacion.md', $dir, $next);
    }
}
