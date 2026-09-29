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
     * copied it with the rest of `.milpa/` (found in greenhouse decisions/0515). `auth.json` is Composer's own
     * credential file (a registry token): a staged composer run reads it through the link (decisions/0527).
     */
    private const LINKED = ['.env', '.milpa/secrets.json', 'auth.json'];

    /**
     * Symlinks in a staged `vendor/` that pointed OUTSIDE it by a relative path (a Composer path repository),
     * made absolute so they resolve from the stage — link path relative to the stage → what it held.
     *
     * @var array<string, array{was: string, set: string}>
     */
    private array $relinked = [];

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

    /**
     * A candidate Composer can WRITE: the house copied as {@see of()} does, but `vendor/` a full copy of its own.
     *
     * ── WHY NOT THE LINKED `vendor/` (greenhouse decisions/0527) ────────────────────────────────────
     *
     * {@see of()} links every package directory to the live one: nothing in the candidate writes there. A
     * staged `composer require`/`update`/`remove` writes exactly there — it removes a package's directory and
     * unpacks another, rewrites `vendor/composer/`, and a Composer plugin may write where it likes. Through a
     * link, every one of those writes would land on the LIVE `vendor/`, and the copy would be the house. So
     * the stage's `vendor/` is a real copy: `cp -a --reflink=auto`, which on a copy-on-write filesystem (btrfs,
     * XFS, APFS through its own `cp`) costs no data blocks, and elsewhere is a plain copy. Hard links were
     * refused: a file rewritten in place (`installed.json`, a bin proxy, a plugin's own output) is the same
     * inode in both trees.
     *
     * A relative link that pointed outside `vendor/` (a path repository) would resolve from the stage to
     * nowhere; it is made absolute here and given back its own form by {@see relinkFor()} before it lands.
     *
     * @throws \RuntimeException when the stage cannot be built
     */
    public static function staged(string $root): self
    {
        $root = rtrim($root, '/');
        $path = $root . '/var/boot-candidates/' . bin2hex(random_bytes(6));
        // Silenced: its failure is said by the exception, as the refusal's reason.
        if (!@mkdir($path . '/var', 0o777, true) && !is_dir($path . '/var')) {
            throw new \RuntimeException('could not make a directory for the stage');
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
            if (is_dir($root . '/vendor')) {
                self::copyVendor($root . '/vendor', $path . '/vendor');
                $candidate->relinked = self::absolutize($root . '/vendor', $path . '/vendor');
            }
        } catch (\Throwable $e) {
            $candidate->remove();

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }

        return $candidate;
    }

    /**
     * Give the stage's `vendor/` the links it will need once it is the house's: every link that leaves it is
     * re-pointed from `$liveVendor` — the one this stage made absolute back to its own string, one Composer
     * wrote relative to the stage re-computed relative to the house.
     */
    public function relinkFor(string $liveVendor): void
    {
        $vendor = $this->path . '/vendor';
        foreach (self::linksIn($vendor) as $relative => $target) {
            $resolved = self::normalize(str_starts_with($target, '/') ? $target : \dirname($vendor . '/' . $relative) . '/' . $target);
            if (str_starts_with($resolved, $vendor . '/')) {
                continue;
            }
            if (isset($this->relinked[$relative])) {
                // A link the house had relative: its own string when it still points where it did (Composer may have
                // re-made it, absolute, from the stage's absolute url), else relative from the house to where it points.
                $new = $resolved === $this->relinked[$relative]['set']
                    ? $this->relinked[$relative]['was']
                    : self::relativeFrom(\dirname(rtrim($liveVendor, '/') . '/' . $relative), $resolved);
            } elseif (!str_starts_with($target, '/')) {
                $new = self::relativeFrom(\dirname(rtrim($liveVendor, '/') . '/' . $relative), $resolved);
            } else {
                continue;
            }
            if ($new === $target) {
                continue;
            }
            unlink($vendor . '/' . $relative);
            symlink($new, $vendor . '/' . $relative);
        }
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

    /**
     * `vendor/` copied whole, copy-on-write where the filesystem can: GNU `cp`, and a plain copy when it is absent.
     */
    private static function copyVendor(string $from, string $to): void
    {
        $out = [];
        $code = 1;
        if (\function_exists('exec')) {
            @exec('cp -a --reflink=auto ' . escapeshellarg($from) . ' ' . escapeshellarg($to) . ' 2>&1', $out, $code);
        }
        if ($code === 0 && is_dir($to)) {
            return;
        }
        if (is_dir($to)) {
            (new self($to))->remove();
        }
        mkdir($to, 0o777);
        self::copyTree($from, $to, false);
    }

    /**
     * Make absolute every relative link in the staged `vendor/` that leaves it; return what each one held.
     *
     * @return array<string, array{was: string, set: string}>
     */
    private static function absolutize(string $liveVendor, string $stagedVendor): array
    {
        $held = [];
        foreach (self::linksIn($stagedVendor) as $relative => $target) {
            if (str_starts_with($target, '/')) {
                continue;
            }
            $resolved = self::normalize(\dirname($liveVendor . '/' . $relative) . '/' . $target);
            if (str_starts_with($resolved, rtrim($liveVendor, '/') . '/')) {
                continue;
            }
            unlink($stagedVendor . '/' . $relative);
            symlink($resolved, $stagedVendor . '/' . $relative);
            $held[$relative] = ['was' => $target, 'set' => $resolved];
        }

        return $held;
    }

    /**
     * Every symlink under a directory (links are not followed), relative path → what it holds.
     *
     * @return array<string, string>
     */
    private static function linksIn(string $directory): array
    {
        $links = [];
        if (!is_dir($directory)) {
            return $links;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isLink()) {
                $links[substr($entry->getPathname(), \strlen($directory) + 1)] = (string) readlink($entry->getPathname());
            }
        }

        return $links;
    }

    /** `..` and `.` folded out of an absolute path, without asking the filesystem (the target may not exist). */
    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }

    /** The relative path from directory `$from` to `$to`, both absolute. */
    private static function relativeFrom(string $from, string $to): string
    {
        $a = array_values(array_filter(explode('/', self::normalize($from)), static fn (string $p): bool => $p !== ''));
        $b = array_values(array_filter(explode('/', self::normalize($to)), static fn (string $p): bool => $p !== ''));
        $common = 0;
        while ($common < \count($a) && $common < \count($b) && $a[$common] === $b[$common]) {
            ++$common;
        }

        return str_repeat('../', \count($a) - $common) . implode('/', \array_slice($b, $common));
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
