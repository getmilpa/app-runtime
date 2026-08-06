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
     * @return array{capabilities: array<string, array<string, mixed>>, undeclared: list<string>, error?: string}
     */
    public static function derive(?callable $fetch = null): array
    {
        $fetch ??= self::httpFetcher();

        $listing = $fetch(self::LIST_URL);
        if ($listing === null) {
            return ['capabilities' => [], 'undeclared' => [], 'error' => 'the registry was unreachable — nothing derived, nothing invented'];
        }

        $json = json_decode($listing, true);
        $names = \is_array($json) && \is_array($json['packageNames'] ?? null) ? $json['packageNames'] : [];

        $capabilities = [];
        $withoutContract = [];
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
            $cap = \is_array($newest) ? ($newest['extra']['milpa']['capability'] ?? null) : null;

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

        return ['capabilities' => $capabilities, 'undeclared' => $withoutContract];
    }

    /**
     * Persist the artifact WITH ITS DATE — the reader must know how stale it is reading.
     *
     * The date arrives as an argument instead of being minted here: whoever runs the derivation
     * owns the moment, and a class that stamps its own clock cannot be replayed in a test.
     *
     * @param array{capabilities: array<string, array<string, mixed>>, undeclared: list<string>, error?: string} $index
     */
    public static function write(array $index, string $derivedAt, ?string $root = null): void
    {
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
            return ['ok' => false, 'error' => (string) $index['error']];
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
