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
 * THE THIRD POINT: what a `milpa/framework` release actually ships — asked, cached, never on paint.
 *
 * The update's other two points need no network ({@see FrameworkDivergence}). This one is egress: it
 * asks a registry what exists and fetches a release to hash it. So it is a VERB somebody presses, and
 * the panel renders only what a previous press left behind. This house measured what probing on paint
 * costs — a single provider reach put five seconds into a render (greenhouse decisions/0281).
 *
 * ── HOW A RELEASE IS FETCHED, AND WHY NOT BY HAND ───────────────────────────────────────────────────
 *
 * `composer create-project --no-install --no-scripts --no-plugins` — measured at 2.1 seconds and 500 KB
 * for one release. Composer already knows how to resolve a constraint, verify a dist and unpack it;
 * hand-rolling an archive download would be a second implementation of that, with its own bugs, for a
 * tool every one of these houses already has. `--no-install` skips the vendor tree we do not read,
 * `--no-scripts` keeps the fetched skeleton's own `post-create-project-cmd` from stamping a birth
 * record into a temporary directory, and `--no-plugins` keeps a third party out of this process.
 *
 * ── THE GLOBS ARE A SECOND COPY, AND THE TEST SAYS SO ───────────────────────────────────────────────
 *
 * 🚨 {@see self::TRACKED} repeats what `tools/stamp-framework.php` declares in the skeleton. It has to:
 * that file is COPIED into each app and frozen there, so an app's copy is the OLD list, and this
 * package is the one `composer update` can move. Two lists is a lie waiting to happen, so a test
 * compares them against the app's own copy whenever one is present — the same shape as the footer's
 * version, which is asserted against the release manifest rather than trusted.
 */
final class FrameworkRelease
{
    /** Where the registry answers what versions exist. */
    public const string REGISTRY = 'https://repo.packagist.org/p2/milpa/framework.json';

    /**
     * The skeleton's tracked set, mirrored from `tools/stamp-framework.php`.
     *
     * @var list<string>
     */
    public const array TRACKED = [
        'composer.json',
        'bin/*',
        'config/*.php',
        'public/*.php',
        'src/*.php',
        'src/*/*.php',
        'src/*/*/*.php',
        'src/*/*/*/*.php',
        'recipes/*.json',
        'tools/*',
    ];

    /**
     * The newest published version, without the `v`, or null when the registry cannot be reached.
     *
     * Pre-releases are skipped: a house is not offered an alpha because it happens to be newest. Null
     * and not an exception, because «I could not ask» is an answer a screen can print, and one a person
     * reading a panel on a laptop with no network needs to see rather than a stack trace.
     *
     * @codeCoverageIgnore it reaches a package registry, so no unit test can run it. Measured on cattle
     *                     instead: 567 ms against `repo.packagist.org`, answering `0.48.1` out of the 84
     *                     versions it lists (greenhouse evidence/0620, 0621).
     */
    public static function latest(): ?string
    {
        $body = @file_get_contents(self::REGISTRY, false, stream_context_create([
            'http' => ['timeout' => 8, 'header' => "User-Agent: milpa-admin\r\n"],
        ]));
        if (!\is_string($body) || $body === '') {
            return null;
        }
        $read = json_decode($body, true);
        $versions = \is_array($read) ? ($read['packages']['milpa/framework'] ?? null) : null;
        if (!\is_array($versions)) {
            return null;
        }

        foreach ($versions as $entry) {
            $version = \is_array($entry) && \is_string($entry['version'] ?? null) ? $entry['version'] : '';
            $version = ltrim($version, 'v');
            if (preg_match('/^\d+\.\d+\.\d+$/', $version) === 1) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Every tracked file of a release, keyed to the sha256 of its bytes — from cache when it is there.
     *
     * Cached per version under the app's `storage/`, because a release's bytes never change: once
     * `0.48.0` has been hashed, asking again is pure waste, and the cache is what lets the panel render
     * a reconciliation without touching the network at all.
     *
     * @return array<string, string>|null null when the release could not be fetched
     */
    public static function ships(string $version, string $root, bool $cache = true): ?array
    {
        $cacheFile = $root . '/storage/framework-releases/' . $version . '.json';
        if (is_file($cacheFile)) {
            $read = json_decode((string) file_get_contents($cacheFile), true);
            if (\is_array($read)) {
                /** @var array<string, string> $hashes */
                $hashes = array_filter($read, '\\is_string');

                return $hashes;
            }
        }

        $tree = self::fetch($version, $root);
        if ($tree === null) {
            return null;
        }

        $hashes = self::hashes($tree);
        self::discard($tree);
        if ($hashes === []) {
            return null;
        }

        // A READ MUST NOT LEAVE THIS BEHIND. `Mutation::None` means «nothing a later run could
        // observe», and this file is read by the panel on every render — so `framework:diff` asks with
        // `cache: false` and the panel's own verb, which declares what it writes, asks with true
        // (greenhouse decisions/0296).
        if ($cache) {
            @mkdir(\dirname($cacheFile), 0o775, true);
            file_put_contents($cacheFile, json_encode($hashes, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
        }

        return $hashes;
    }

    /**
     * Remembers which release was found, so a render never has to ask the network to know.
     *
     * The version alone, plus when it was asked. The release's HASHES already live in their own cache
     * ({@see self::ships()}); this is only the pointer that says which of them the panel should compare
     * against. Two files rather than one because a release's bytes never change while «what is newest»
     * does — mixing them would make an answer that is still true expire with one that is not.
     */
    public static function remember(string $root, string $version, string $at): void
    {
        @mkdir($root . '/storage', 0o775, true);
        file_put_contents(
            $root . '/storage/framework-check.json',
            json_encode(['latest' => $version, 'at' => $at], \JSON_PRETTY_PRINT) . "\n",
        );
    }

    /**
     * What the last check found, or null when nobody has checked.
     *
     * Null is what makes the screen offer the verb instead of a table: «nobody has asked» and «you are
     * up to date» are different, and a panel that showed an empty reconciliation for the first would be
     * claiming the second.
     *
     * @return array{latest: string, at: string}|null
     */
    public static function remembered(string $root): ?array
    {
        $file = $root . '/storage/framework-check.json';
        if (!is_file($file)) {
            return null;
        }
        $read = json_decode((string) file_get_contents($file), true);
        if (!\is_array($read) || !\is_string($read['latest'] ?? null) || $read['latest'] === '') {
            return null;
        }

        return ['latest' => $read['latest'], 'at' => \is_string($read['at'] ?? null) ? $read['at'] : ''];
    }

    /**
     * Unpacks a release into a temporary directory and returns its path, or null.
     *
     * Public because the hashes are not enough for everything: {@see self::ships()} wants a fingerprint
     * and `framework:apply` wants the BYTES. A cache is a fine answer to «what would change»; it is not
     * one to «what shall I write into this house» — so the applier fetches again, and the caller that
     * asked for a tree is the one that {@see self::discard()}s it.
     *
     * `--no-scripts` matters specifically: without it the fetched skeleton runs its own
     * `post-create-project-cmd` and stamps a birth record into this temporary directory.
     *
     * @codeCoverageIgnore it shells out to composer, which reaches the registry. Measured on cattle:
     *                     2.1 s and 500 KB for one release, and the tree it leaves is what
     *                     `framework:apply` copies from (greenhouse evidence/0620, 0621).
     */
    public static function fetch(string $version, string $root): ?string
    {
        $tree = sys_get_temp_dir() . '/milpa-release-' . $version . '-' . bin2hex(random_bytes(4));
        $command = \sprintf(
            'composer create-project --no-install --no-scripts --no-plugins --quiet %s:%s %s 2>&1',
            escapeshellarg('milpa/framework'),
            escapeshellarg($version),
            escapeshellarg($tree),
        );
        exec($command, $output, $status);
        if ($status !== 0 || !is_dir($tree)) {
            self::discard($tree);

            return null;
        }

        return $tree;
    }

    /** Removes a fetched tree. A release already read is not worth keeping on disk. */
    public static function discard(string $tree): void
    {
        self::rm($tree);
    }

    /**
     * The tracked files of a tree, path relative to it, keyed to the sha256 of its bytes.
     *
     * PRIVATE, because nothing outside needs it: it is how {@see self::ships()} turns a fetched release
     * into hashes. It shipped public for one commit and the unwired-piece census named it the same day —
     * a same-file caller does not make a public method wired, and «something will want it» is the excuse
     * `decisions/0213` exists to refuse.
     *
     * @return array<string, string>
     */
    private static function hashes(string $root): array
    {
        $out = [];
        foreach (self::TRACKED as $glob) {
            foreach (glob($root . '/' . $glob) ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $out[substr($path, \strlen($root) + 1)] = hash_file('sha256', $path) ?: '';
            }
        }
        ksort($out);

        return $out;
    }

    /** The recursive delete `discard()` is the name for. */
    private static function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
