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

namespace Milpa\AppRuntime\Framework;

/**
 * WHICH `milpa/framework` THIS HOUSE RUNS ON — read from the birth record, because the lock cannot say.
 *
 * 🚨 THIS FAMILY MOVED HERE FROM `milpa/admin`, and the move is the correction. It lived there because
 * the panel's footer was its first consumer, which is a reason about who asked FIRST and not about
 * whose fact it is: what a house was born from is a fact about the APP. It moved when applying a
 * reconciliation turned out to be a governed act — and a governed act is an Operation, projected to
 * every surface, which cannot be declared from a panel (greenhouse decisions/0295).
 *
 * 🚨 THE FOOTER HAS BEEN ASKING FOR THIS AND SILENTLY GETTING NOTHING. `AdminShell::FOOTER_PACKAGES`
 * has listed `milpa/framework` FIRST since it was written, and its docblock says so in as many words —
 * «`milpa/framework` is what the app was founded on». It resolves those names against `composer.lock`,
 * and `milpa/framework` is never there: it is a SKELETON, so `create-project` copies its files and the
 * package is gone. Measured on cattle: zero occurrences in the lock. The row was dropped by the very
 * clause written to handle a missing package, and the footer quietly showed the next two names down
 * the list instead — which is what Rod saw and asked about (greenhouse decisions/0291).
 *
 * The answer now comes from `.milpa/framework.json`, which `milpa/framework` (>=0.48) ships with its
 * version and stamps with this house's birth record at create-project time.
 *
 * Absent the file the answer is null and the footer drops the row exactly as before — a house created
 * before the stamp existed cannot know, and a guessed version is worse than a missing one: it is the
 * first thing a bug report quotes.
 */
final class FrameworkStamp
{
    /** Where the skeleton writes what it is, relative to the app root. */
    public const string PATH = '.milpa/framework.json';

    /**
     * The framework version this house runs on, or null when the tree cannot say.
     *
     * `version` and not `born.version`: the two agree the day a house is created and diverge the day
     * the skeleton's files are reconciled with a newer release, and what a person wants in a footer is
     * what they are RUNNING. The birth record answers a different question — what to diff from — and it
     * is the reconciliation that reads it.
     */
    public static function version(string $root): ?string
    {
        $record = self::read($root);
        $version = $record['version'] ?? null;

        return \is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The birth record: the version the house was created from and the sha256 of every file it received.
     *
     * @return array{version: string, at: string, files: array<string, string>}|null
     */
    public static function born(string $root): ?array
    {
        $born = self::read($root)['born'] ?? null;
        if (!\is_array($born) || !\is_string($born['version'] ?? null) || !\is_array($born['files'] ?? null)) {
            return null;
        }

        /** @var array<string, string> $files */
        $files = array_filter($born['files'], '\\is_string');

        return [
            'version' => $born['version'],
            'at' => \is_string($born['at'] ?? null) ? $born['at'] : '',
            'files' => $files,
        ];
    }

    /**
     * What each recorded file is compared against: the bytes it was BORN with, unless a later
     * `framework:apply` took newer ones — then the bytes it TOOK (greenhouse decisions/0483). A file equal
     * to what was taken is the skeleton's, not the house's; before this, a taken file read as customized
     * and the next comparison saw a conflict nobody made. Files an apply ADDED are recorded here too.
     *
     * @return array{version: string, at: string, files: array<string, string>}|null
     */
    public static function baseline(string $root): ?array
    {
        $born = self::born($root);
        if ($born === null) {
            return null;
        }
        $taken = self::read($root)['taken'] ?? [];
        foreach (\is_array($taken) ? $taken : [] as $path => $took) {
            if (\is_string($path) && \is_array($took) && \is_string($took['sha256'] ?? null)) {
                $born['files'][$path] = $took['sha256'];
            }
        }

        return $born;
    }

    /**
     * Writes what an apply took: per path, the release and the sha256 it arrived with — and the version the
     * house now runs (greenhouse decisions/0483). The birth record is never rewritten: it says where the house
     * came from, and that does not change.
     *
     * @param array<string, string> $hashes path => sha256 of the bytes written
     */
    public static function recordTaken(string $root, string $version, array $hashes): void
    {
        if ($hashes === []) {
            return;
        }
        $record = self::read($root);
        $record['version'] = $version;
        $taken = \is_array($record['taken'] ?? null) ? $record['taken'] : [];
        $at = gmdate('Y-m-d\\TH:i:s\\Z');
        foreach ($hashes as $path => $sha256) {
            $taken[$path] = ['version' => $version, 'sha256' => $sha256, 'at' => $at];
        }
        ksort($taken);
        $record['taken'] = $taken;
        @mkdir(\dirname($root . '/' . self::PATH), 0o775, true);
        file_put_contents($root . '/' . self::PATH, json_encode($record, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * The record as written, or an empty array.
     *
     * @return array<string, mixed>
     */
    private static function read(string $root): array
    {
        $file = $root . '/' . self::PATH;
        if ($root === '' || !is_file($file)) {
            return [];
        }
        $read = json_decode((string) file_get_contents($file), true);

        return \is_array($read) ? $read : [];
    }
}
