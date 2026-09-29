<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
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
 * The house as it WOULD be after a change, built beside it — so the change can be booted before it lands.
 *
 * ── THE WINDOW IT CLOSES (greenhouse evidence/1039, B5; decisions/0512) ─────────────────────────
 *
 * 0506 asked a fresh process whether the house boots AFTER a promotion wrote it, and rolled back when it
 * did not. For the ~0.1–0.3 s the witness took, the broken file was on disk, and a server that revalidates
 * every request served one fatal answer in ~60, in 2 of 3 repetitions. A witness that looks after the write
 * cannot close that: only one that looks BEFORE can. So the candidate is booted here, and the live tree
 * changes only to something that booted.
 *
 * ── WHAT THE CANDIDATE IS ───────────────────────────────────────────────────────────────────────
 *
 * A copy of the house's tree under `var/boot-candidates/<id>/`, with the change applied, minus what a
 * boot never reads and what is too heavy or too private to copy: `var/` (state; an empty one is made, as a
 * trial's), `.git/`, `node_modules/`, and `.env` and `.milpa/secrets.json` (LINKED, never copied: a secret does
 * not get a second file).
 *
 * `vendor/` is the one subtle part. Composer's autoloader resolves the app's own classes from the directory
 * its `vendor/composer/` files live in — PHP resolves symlinks in `__DIR__` — so a linked `vendor/` would
 * boot the LIVE `src/` and the candidate would prove nothing. So `vendor/autoload.php` and
 * `vendor/composer/` (~1 MB, the maps) are copied, and every package directory is linked: `App\` resolves
 * to the candidate, every package to the one installed code.
 */
final class BootCandidate
{
    /** Top-level entries a boot never needs from a copy: state, history, front-end builds. `vendor/` is rebuilt, not skipped. */
    private const SKIP = ['var', 'vendor', '.git', 'node_modules', '.env'];

    /**
     * Files that hold secrets: LINKED into the candidate, never copied — a secret does not get a second file.
     *
     * `.milpa/secrets.json` is where `provider:declare` keeps a credential (SecretOverlay); the first candidate
     * copied it with the rest of `.milpa/` (found in greenhouse decisions/0515).
     */
    private const LINKED = ['.env', '.milpa/secrets.json'];

    private function __construct(public readonly string $path)
    {
    }

    /**
     * Build the candidate: the house at `$root`, with `$writes` (path → bytes) written and `$deletes` removed.
     *
     * @param array<string, string> $writes  paths relative to the root
     * @param list<string>          $deletes paths relative to the root
     *
     * @throws \RuntimeException when the candidate cannot be built
     */
    public static function of(string $root, array $writes, array $deletes = []): self
    {
        $root = rtrim($root, '/');
        $path = $root . '/var/boot-candidates/' . bin2hex(random_bytes(6));
        if (!mkdir($path . '/var', 0o777, true) && !is_dir($path . '/var')) {
            throw new \RuntimeException('could not make a directory for the candidate');
        }
        $candidate = new self($path);
        try {
            self::copyTree($root, $path, true);
            foreach (self::LINKED as $secret) {
                if (is_file($root . '/' . $secret)) {
                    if (!is_dir(\dirname($path . '/' . $secret))) {
                        mkdir(\dirname($path . '/' . $secret), 0o777, true);
                    }
                    symlink($root . '/' . $secret, $path . '/' . $secret);
                }
            }
            self::vendor($root . '/vendor', $path . '/vendor');
            foreach ($writes as $relative => $bytes) {
                $target = $path . '/' . self::relative($relative);
                if (!is_dir(\dirname($target))) {
                    mkdir(\dirname($target), 0o777, true);
                }
                if (is_link($target)) {
                    unlink($target);
                }
                file_put_contents($target, $bytes);
            }
            foreach ($deletes as $relative) {
                $target = $path . '/' . self::relative($relative);
                if (is_file($target) || is_link($target)) {
                    unlink($target);
                }
            }
        } catch (\Throwable $e) {
            $candidate->remove();

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }

        return $candidate;
    }

    /** Remove the candidate — links are removed, never followed. */
    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->path);
        $parent = \dirname($this->path);
        if (\is_array($left = scandir($parent)) && \count($left) === 2) {
            @rmdir($parent);
        }
    }

    /** Copy a tree as it is — files as files, links as links — skipping {@see SKIP} at the top only. */
    private static function copyTree(string $from, string $to, bool $top, string $under = ''): void
    {
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..' || ($top && \in_array($name, self::SKIP, true)) || \in_array($under . $name, self::LINKED, true)) {
                continue;
            }
            $source = $from . '/' . $name;
            $target = $to . '/' . $name;
            if (is_link($source)) {
                symlink((string) readlink($source), $target);
            } elseif (is_dir($source)) {
                mkdir($target, 0o777);
                self::copyTree($source, $target, false, $under . $name . '/');
            } elseif (!copy($source, $target)) {
                throw new \RuntimeException('could not copy ' . $name . ' into the candidate');
            }
        }
    }

    /** `autoload.php` and `composer/` copied (they decide where `App\` lives); every other entry linked. */
    private static function vendor(string $from, string $to): void
    {
        if (!is_dir($from)) {
            return;
        }
        mkdir($to, 0o777);
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($name === 'autoload.php' || $name === 'composer') {
                is_dir($from . '/' . $name) ? self::copyTree($from . '/' . $name, self::made($to . '/' . $name), false) : copy($from . '/' . $name, $to . '/' . $name);
            } else {
                symlink($from . '/' . $name, $to . '/' . $name);
            }
        }
    }

    private static function made(string $directory): string
    {
        mkdir($directory, 0o777);

        return $directory;
    }

    /** A path the change names, refused when it could leave the candidate. */
    private static function relative(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || \in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('a change names a path outside the house: ' . $path);
        }

        return $path;
    }
}
