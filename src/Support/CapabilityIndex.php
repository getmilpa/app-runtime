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
 * The capability→package index, DERIVED from what the registry publishes.
 *
 * ── WHY DERIVED, AND FROM WHERE ──────────────────────────────────────────────────────────────────
 *
 * The hand-written list inside the framework was the exact defect ADR-0041 names: a package could
 * not announce itself without somebody editing the acoplador. Since Q-P19-T every announcing
 * package declares `"type": "milpa-capability"` — discoverable BY WHAT IT IS, not by its name
 * prefix, which is what lets a third party in — and Packagist publishes its full `composer.json`
 * per version, `extra.milpa.capability` included. Verified live on 2026-08-06: nine packages.
 *
 * ── THE RANK OF AUTHORITIES, WRITTEN DOWN ────────────────────────────────────────────────────────
 *
 * Three places answer «what exists»; unranked they diverge on the case nobody tested. The rank:
 *
 *   1. `installed.json` — what IS in this app. {@see Capabilities::declaredBy()}.
 *   2. this index       — what EXISTS in the registry, DATED: the reader knows how stale it reads.
 *   3. the offline floor — {@see Capabilities::knownOptIns()}, for the app that cannot reach the
 *      network. It answers less and it SAYS it is the floor.
 *
 * ── WHAT IT RECORDS ON PURPOSE ───────────────────────────────────────────────────────────────────
 *
 * The version and the whole published contract, per package. When `capabilities:enable` later
 * verifies that the installed package DELIVERED what the registry promised, this artifact is the
 * promise it compares against — the chain-of-supply risk gets a record, not a mitigation (that
 * decision has no evidence yet, and it is deferred SAID).
 *
 * ── AND WHAT AN ANSWER PROVES, WHICH IS NOT THE SAME AS ARRIVING ─────────────────────────────────
 *
 * A registry that ANSWERS is not a registry (greenhouse decisions/0239). Measured: milpahq.com serves
 * `200` with a 22 KB HTML page on every path, `/index.json` included — and this class used to read
 * that page, decode it to `null`, list zero names, and return an index with no error at all. The
 * refresh then wrote it: a good dated artifact replaced by an empty one carrying a FRESH date, after
 * which every «unknown capability» was caused by the blanking rather than by the registry.
 *
 * So the derivation now names three states that used to be one, and only the third is an index:
 *
 *   `registry_unreachable`  nothing came back.
 *   `not_an_index`          something came back and it is not the registry's listing.
 *   `registry_unreadable`   the listing is real and named packages, and NOT ONE could be read —
 *                           a network that half-answers, told apart from a registry that is empty.
 *
 * The refusal carries a `reason` beside its sentence, because a caller that has to string-match an
 * English phrase to decide what to do is a caller that breaks when the phrase improves. And the two
 * causes that `undeclared` used to collapse are split: {@see $unreadable} is «I could not read it»,
 * `undeclared` stays «I read it and it declares no contract».
 *
 * An EMPTY registry is not an error: zero names, zero capabilities, no reason. A house whose registry
 * legitimately lists nothing must be able to say so, or this class would refuse the truth.
 */
final class CapabilityIndex
{
    public const LIST_URL = 'https://packagist.org/packages/list.json?type=milpa-capability';
    private const P2_BASE = 'https://repo.packagist.org/p2/';

    /**
     * Derive the index from the registry: the type listing, then one p2 document per package.
     *
     * @param null|callable(string): ?string $fetch the network seam — a test that needs the live
     *                                              registry is a test nobody runs offline
     *
     * @return array{capabilities: array<string, array<string, mixed>>, undeclared: list<string>, unreadable: list<string>, error?: string, reason?: string}
     */
    public static function derive(?callable $fetch = null): array
    {
        $fetch ??= self::httpFetcher();

        $listing = $fetch(self::LIST_URL);
        if ($listing === null) {
            return self::refusal('registry_unreachable', 'the registry was unreachable — nothing derived, nothing invented');
        }

        $json = json_decode($listing, true);
        if (!\is_array($json) || !\is_array($json['packageNames'] ?? null)) {
            // ANSWERING IS NOT BEING A REGISTRY. A host that serves its landing page on every path
            // — milpahq.com does exactly this today — reaches here with 22 KB of HTML, and the old
            // code read it as «the registry lists nothing». Deriving zero from that and letting the
            // refresh stamp a fresh date on it is how a good catalogue disappears without a word.
            return self::refusal('not_an_index', 'the registry answered with something that is not its listing — nothing derived, and what was there was left alone');
        }
        $names = $json['packageNames'];

        $capabilities = [];
        $withoutContract = [];
        $unreadable = [];
        foreach ($names as $name) {
            // Each segment starts AND ends alphanumeric — «../evil» matched the loose class and
            // walked the p2 URL up a directory. Found by the test that existed to cover this line.
            if (!\is_string($name)
                || preg_match('#^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$#i', $name) !== 1) {
                continue;
            }

            $p2 = $fetch(self::P2_BASE . $name . '.json');
            $doc = $p2 === null ? null : json_decode($p2, true);
            $versions = \is_array($doc) ? ($doc['packages'][$name] ?? null) : null;
            $newest = \is_array($versions) ? ($versions[0] ?? null) : null;

            if (!\is_array($newest)) {
                // COULD NOT READ IT — the fetch failed, or what came back is not a p2 document. That
                // is a different fact from «it declares no contract», and collapsing the two made a
                // half-answering network look like a registry full of packages that announce nothing.
                $unreadable[] = $name;

                continue;
            }

            $cap = $newest['extra']['milpa']['capability'] ?? null;
            if (!\is_array($cap) || !\is_string($cap['id'] ?? null)) {
                // Declares the type but not the contract: left out AND said. Silence here would read
                // as «covered» when it is not.
                $withoutContract[] = $name;
                continue;
            }

            $capabilities[$name] = [
                'id' => $cap['id'],
                'title' => \is_string($cap['title'] ?? null) ? $cap['title'] : '',
                'unlocks' => \is_array($cap['unlocks'] ?? null) ? array_values($cap['unlocks']) : [],
                'provides' => \is_array($cap['provides'] ?? null) ? array_values($cap['provides']) : [],
                'briefing' => \is_string($cap['briefing'] ?? null) ? $cap['briefing'] : '',
                'version' => \is_string($newest['version'] ?? null) ? $newest['version'] : '',
            ];
        }

        ksort($capabilities);
        sort($withoutContract);
        sort($unreadable);

        if ($unreadable !== [] && $capabilities === [] && $withoutContract === []) {
            // The listing was real and named packages, and not one of them could be read. That is a
            // network that half-answers, and writing its zero over a good index would be the same
            // blanking by a slower road. An EMPTY registry —zero names— is not this, and is not an
            // error: it reaches the return below with three empty lists and no reason.
            return self::refusal(
                'registry_unreadable',
                'the registry listed ' . \count($unreadable) . ' package(s) and none could be read — nothing derived, and what was there was left alone',
                $unreadable,
            );
        }

        return ['capabilities' => $capabilities, 'undeclared' => $withoutContract, 'unreadable' => $unreadable];
    }

    /**
     * A refusal, shaped like an index so no caller has to branch on the shape to read the reason.
     *
     * The `reason` travels beside the sentence because a caller that string-matches English to decide
     * what to do breaks the day the sentence improves — and this one names three different worlds:
     * nobody answered, somebody answered something else, or the answer named packages it could not
     * deliver. The sentence is for a human; the reason is for the code.
     *
     * @param list<string> $unreadable
     *
     * @return array{capabilities: array<string, array<string, mixed>>, undeclared: list<string>, unreadable: list<string>, error: string, reason: string}
     */
    private static function refusal(string $reason, string $sentence, array $unreadable = []): array
    {
        return [
            'capabilities' => [],
            'undeclared' => [],
            'unreadable' => $unreadable,
            'error' => $sentence,
            'reason' => $reason,
        ];
    }

    /**
     * Persist the artifact WITH ITS DATE — the reader must know how stale it is reading.
     *
     * The date arrives as an argument instead of being minted here: whoever runs the derivation
     * owns the moment, and a class that stamps its own clock cannot be replayed in a test.
     *
     * @param array{capabilities: array<string, array<string, mixed>>, undeclared: list<string>, unreadable?: list<string>, error?: string} $index
     */
    public static function write(array $index, string $derivedAt, ?string $root = null): void
    {
        if (($index['error'] ?? null) !== null) {
            // A refusal is not an index, and this is the last door before a good catalogue is lost.
            // `refresh()` already declines to call this, but the class is public: the guarantee has
            // to live where the write happens, not only where today's caller happens to check.
            throw new \InvalidArgumentException('a refused derivation is not an index and is never written: ' . (string) $index['error']);
        }

        $root ??= Capabilities::raizDeLaApp();
        $dir = $root . '/var';
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }

        file_put_contents(
            $dir . '/capability-index.json',
            json_encode(
                ['derived_at' => $derivedAt, 'source' => self::LIST_URL, ...$index],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            ) . "\n",
        );
    }

    /**
     * The dated artifact, or `null` when none exists — never an empty index that looks derived.
     *
     * @return array<string, mixed>|null
     */
    public static function read(?string $root = null): ?array
    {
        $root ??= Capabilities::raizDeLaApp();
        $file = $root . '/var/capability-index.json';
        if (!is_file($file)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($file), true);

        return \is_array($json) && \is_string($json['derived_at'] ?? null) ? $json : null;
    }

    /**
     * Derive, persist dated, and summarise — the whole refresh in one governed step.
     *
     * Lives here and not in the operation handler so the seam reaches it: an operation closure
     * takes its input array and nothing else, and network logic that can only be exercised against
     * the live registry is logic that in practice nobody exercises.
     *
     * @param null|callable(string): ?string $fetch the network seam
     *
     * @return array<string, mixed>
     */
    public static function refresh(?callable $fetch = null, ?string $derivedAt = null, ?string $root = null): array
    {
        $index = self::derive($fetch);
        if (($index['error'] ?? null) !== null) {
            // The artifact is NOT touched on a refusal — that half was already right, and it is what
            // makes the three states above worth naming. What it did not do is SAY it: a caller that
            // reads «error» has no way to know whether yesterday's catalogue survived, and the answer
            // decides whether a human should worry now or at leisure.
            $kept = self::read($root);

            return [
                'ok' => false,
                'error' => (string) $index['error'],
                'reason' => (string) ($index['reason'] ?? 'unknown'),
                'unreadable' => $index['unreadable'],
                'kept' => $kept === null ? null : (string) ($kept['derived_at'] ?? ''),
            ];
        }

        $derivedAt ??= date(\DATE_ATOM);
        self::write($index, $derivedAt, $root);

        return [
            'ok' => true,
            'derived_at' => $derivedAt,
            'capabilities' => \count($index['capabilities']),
            'packages' => array_keys($index['capabilities']),
            // Said, never silent: a package that declares the type but not the contract would
            // otherwise look covered without being listable.
            'undeclared' => $index['undeclared'],
            // And the ones that could not be READ, which is a different fact with a different fix.
            'unreadable' => $index['unreadable'],
        ];
    }

    /** The production fetcher: plain HTTP GET with a short ceiling, `null` on any failure. */
    private static function httpFetcher(): callable
    {
        return static function (string $url): ?string {
            $context = stream_context_create(['http' => ['timeout' => 10, 'header' => 'User-Agent: milpa/app-runtime']]);
            $body = @file_get_contents($url, false, $context);

            return $body === false ? null : $body;
        };
    }
}
