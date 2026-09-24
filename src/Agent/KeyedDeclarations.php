<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AppRuntime\Web\ComponentWords;
use Milpa\AppRuntime\Web\ScreenStore;

/**
 * No merge of what actually collides (greenhouse decisions/0467, refining 0068).
 *
 * A promotion over a moved target used to be a new proposal, full stop — judged by FILE. For the
 * house's declaration stores that was too coarse: three words defined in three trials never touch each
 * other, yet promoting the first moved `config/components.json`, and the other two had to be written
 * again (evidence/1001: five definitions for three words).
 *
 * These stores have IDENTITY — a screen by its name, a word by its name — so a promotion is judged per
 * key instead: a three-way merge over Milpa declarations, not over lines of a file.
 *
 *     for each key the trial touched:
 *         house[key] == base[key]  → the proposed value is promoted
 *         house[key] != base[key]  → conflict: a new proposal, as 0068 always said
 *
 * No algorithm decides which of two definitions is «better»; a key both sides changed is a conflict,
 * named. Every other file still moves by 0068's rule.
 */
final class KeyedDeclarations
{
    /** The declaration stores with identity, relative to the house root. Only these, measured first. */
    public const PATHS = [ScreenStore::DEFAULT_PATH, ComponentWords::PATH];

    /** Whether `$rel` is a store judged per key. */
    public static function handles(string $rel): bool
    {
        return \in_array($rel, self::PATHS, true);
    }

    /** Keep the BASE of every keyed store a trial starts from — what it saw when it was born. */
    public static function snapshot(string $root, string $baseDir): void
    {
        foreach (self::PATHS as $rel) {
            if (is_file($root . '/' . $rel)) {
                @mkdir(\dirname($baseDir . '/' . $rel), 0o777, true);
                copy($root . '/' . $rel, $baseDir . '/' . $rel);
            }
        }
    }

    /**
     * The per-key merge of one keyed store: the bytes to write and the keys it carries, or the keys that
     * collided. Null when this trial kept no BASE (born before decisions/0467) — judged by file then.
     *
     * @param array<string, string> $baseline the trial's manifest, path → sha256
     *
     * @return array{bytes: string, touched: list<string>, conflicts: list<string>, houseSha: ?string}|null
     */
    public static function merge(string $root, string $trialDir, string $copyDir, string $rel, array $baseline): ?array
    {
        $basePath = $trialDir . '/base/' . $rel;
        if (\array_key_exists($rel, $baseline) && ! is_file($basePath)) {
            return null;
        }
        $base = self::read($basePath);
        $proposed = self::read($copyDir . '/' . $rel);
        $housePath = $root . '/' . $rel;
        $house = self::read($housePath);
        if ($base === null || $proposed === null || $house === null) {
            return null;
        }

        $touched = [];
        foreach (array_unique([...array_keys($base), ...array_keys($proposed)]) as $key) {
            if (self::canonical($base[$key] ?? null) !== self::canonical($proposed[$key] ?? null)) {
                $touched[] = (string) $key;
            }
        }
        $conflicts = array_values(array_filter(
            $touched,
            static fn (string $key): bool => self::canonical($house[$key] ?? null) !== self::canonical($base[$key] ?? null),
        ));

        $merged = $house;
        foreach ($touched as $key) {
            if (\array_key_exists($key, $proposed)) {
                $merged[$key] = $proposed[$key];
            } else {
                unset($merged[$key]);
            }
        }
        if ($rel === ComponentWords::PATH) {
            ksort($merged);
        }

        return [
            'bytes' => (string) json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'touched' => $touched,
            'conflicts' => $conflicts,
            'houseSha' => is_file($housePath) ? (hash_file('sha256', $housePath) ?: null) : null,
        ];
    }

    /**
     * A store's declarations by key; an absent file is an empty store, an unreadable one is null.
     *
     * @return array<string, mixed>|null
     */
    private static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /** A value compared by meaning, not by key order — the same declaration written twice is equal. */
    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (! \is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return (string) json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
