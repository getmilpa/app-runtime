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

use Milpa\AppRuntime\Framework\FrameworkStamp;

/**
 * What the house's own writers wrote into its config lists — so «never declared» can be told from «removed».
 *
 * `capabilities:enable X` also wires what X requires when it is installed and was NEVER declared, and never
 * re-adds what somebody removed (greenhouse decisions/0520, Rod 2026-09-29). An absent class looks the same in
 * both cases; the difference is in the file's history. The house knows two stretches of it: the bytes each file
 * was BORN with (or took from a `framework:apply`, {@see FrameworkStamp::baseline()}), and — from now on — the
 * bytes each of its declaration writers left behind, recorded here.
 *
 * A file is ACCOUNTED FOR when every byte of it is explained by those two: it still holds its baseline and no
 * writer touched it since, or it holds exactly what the last writer left and every write started from the bytes
 * the one before it left. Then an absent class was never declared: nothing but the skeleton and the house wrote
 * the file, and neither removes. Any other state means somebody edited it by hand at some point, and what they
 * removed cannot be known — the file is marked BROKEN for good, and wiring a requirement into it is refused.
 *
 * It records; it never decides what is declared. The ledger lives at `storage/capabilities/declarations.json`.
 */
final class DeclarationLedger
{
    public const PATH = 'storage/capabilities/declarations.json';

    /**
     * Record a write a declaration writer just made to `$relative`, given the bytes it found and the bytes it left.
     * A write that did not start from the bytes the house last knew breaks the chain for that file, permanently.
     *
     * @param string $relative the file, relative to the app root (`config/plugins.php`)
     * @param string $before   the bytes the writer read
     * @param string $after    the bytes the writer wrote
     */
    public static function wrote(string $root, string $relative, string $before, string $after): void
    {
        $ledger = self::read($root);
        $entry = $ledger['files'][$relative] ?? [];
        $broken = ($entry['broken'] ?? false) === true || !self::isExpected($root, $relative, hash('sha256', $before), $entry);
        $ledger['files'][$relative] = [
            'last' => hash('sha256', $after),
            'writes' => (int) ($entry['writes'] ?? 0) + 1,
            'broken' => $broken,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        ksort($ledger['files']);
        $file = rtrim($root, '/') . '/' . self::PATH;
        @mkdir(\dirname($file), 0o775, true);
        file_put_contents($file, json_encode($ledger, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Whether every byte of `$relative` is explained by its baseline and the house's own writes — the only state
     * in which a class absent from it was never declared. A file the house has no baseline for is not accounted
     * for: without the start of the history there is no history.
     */
    public static function accounted(string $root, string $relative): bool
    {
        $file = rtrim($root, '/') . '/' . $relative;
        if (!is_file($file)) {
            return false;
        }
        $entry = self::read($root)['files'][$relative] ?? [];
        if (($entry['broken'] ?? false) === true) {
            return false;
        }

        return self::isExpected($root, $relative, (string) hash_file('sha256', $file), $entry);
    }

    /**
     * The bytes the house expects the file to hold: what its last writer left, or — before any writer — its baseline.
     *
     * @param array<string, mixed> $entry
     */
    private static function isExpected(string $root, string $relative, string $sha256, array $entry): bool
    {
        if (\is_string($entry['last'] ?? null)) {
            return hash_equals($entry['last'], $sha256);
        }
        $baseline = FrameworkStamp::baseline($root)['files'][$relative] ?? null;

        return \is_string($baseline) && hash_equals($baseline, $sha256);
    }

    /** @return array{files: array<string, array<string, mixed>>} */
    private static function read(string $root): array
    {
        $file = rtrim($root, '/') . '/' . self::PATH;
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $files = \is_array($data) && \is_array($data['files'] ?? null) ? $data['files'] : [];

        /** @var array<string, array<string, mixed>> $files */
        return ['files' => array_filter($files, '\\is_array')];
    }
}
