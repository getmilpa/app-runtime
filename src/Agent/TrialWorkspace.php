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

/**
 * A DISPOSABLE COPY of the app root, where a trial may run and only the HOST decides what changed.
 *
 * ── WHY A COPY AND NOT A BRANCH ─────────────────────────────────────────────────────────────────
 *
 * greenhouse decisions/0068: the trial must not create a second house. A copy under
 * `var/trials/<id>/` is the app minus its state — no `var/` (the session stream lives there, and a
 * second stream would be a second truth) and no `.env` (a secret does not travel into a throwaway).
 * The copy carries its own empty `var/` so the trial process boots, and the runner
 * (`trial-run.php`) so it can execute one operation the way the TUI does.
 *
 * ── WHY THE HOST COMPUTES THE DIFF ──────────────────────────────────────────────────────────────
 *
 * Because the diff is what the human audits, and a copy that reported its own changes could lie. The
 * host walks the copy against itself: the runner file and the copy's `var/` are never part of a
 * change, because they are the trial's own machinery, not its result (0069 §8).
 */
final class TrialWorkspace
{
    /** The confinement the runner imposes — named here so the runner and the confinement agree. */
    public const BOUNDS = ['fs' => 'ro-root+rw-copy', 'net' => 'unshared', 'pid' => 'unshared'];

    private const RUNNER = 'trial-run.php';

    // Top-level directories the trial never copies, hashes or diffs: `var/` (state — a second stream
    // would fork the truth) and `vendor/` (bound READ-ONLY at run time, so a mutation cannot touch it,
    // and copying/hashing 160 MB of it is the tax decisions/0070 removes).
    private const PRUNE = ['var', 'vendor'];

    private function __construct(
        public readonly string $root,
        public readonly string $id,
        public readonly string $copy,
    ) {
    }

    /**
     * Copy the app root into a fresh trial, without its state, with the runner in place.
     *
     * @throws \InvalidArgumentException if the id could escape the trials directory
     * @throws \RuntimeException         if the copy cannot be made
     */
    public static function materialize(string $root, string $id, string $runnerPath): self
    {
        self::guardId($id);
        $base = self::baseDir($root, $id);
        $copy = $base . '/copy';
        if (is_dir($base)) {
            self::rmrf($base);
        }
        if (! mkdir($copy, 0o777, true) && ! is_dir($copy)) {
            throw new \RuntimeException("could not make the trial directory at {$copy}");
        }

        self::copyTree($root, $copy);
        // THE COPY STARTS WITH AN EMPTY `var/`: the trial gets somewhere to write its own state
        // without inheriting — or forking — the host's session stream (0069 §B2).
        if (! is_dir($copy . '/var')) {
            mkdir($copy . '/var', 0o777, true);
        }
        copy($runnerPath, $copy . '/' . self::RUNNER);

        // THE BASELINE: what the host had, path → sha256, at the instant of the copy. `stale()` reads
        // it to tell whether the target moved out from under the trial before a promotion (Rule 1).
        file_put_contents($base . '/manifest.json', (string) json_encode(self::hashTree($copy), \JSON_UNESCAPED_SLASHES));

        return new self($root, $id, $copy);
    }

    /** Reopen an existing trial, or `null` if there is none by that id. */
    public static function open(string $root, string $id): ?self
    {
        $copy = self::baseDir($root, $id) . '/copy';

        return is_dir($copy) ? new self($root, $id, $copy) : null;
    }

    /**
     * The ids of every trial under this root, in a stable order.
     *
     * @return list<string>
     */
    public static function ids(string $root): array
    {
        $dir = $root . '/var/trials';
        if (! is_dir($dir)) {
            return [];
        }
        $ids = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($dir . '/' . $entry . '/copy')) {
                $ids[] = $entry;
            }
        }
        sort($ids);

        return $ids;
    }

    /** The runner inside this copy — what a trial process executes. */
    public function runnerPath(): string
    {
        return $this->copy . '/' . self::RUNNER;
    }

    /**
     * What changed in the copy, as the HOST sees it — path → {status, sha256}.
     *
     * The copy's `var/` and the runner are the trial's machinery, never its result, so they are not
     * changes. Everything else is compared against the host: a file the copy has and the host does
     * not is `added`; one the host has and the copy does not is `deleted`; a differing one is
     * `modified`.
     *
     * @return array<string, array{status: string, sha256: ?string}>
     */
    public function diff(): array
    {
        $out = [];
        foreach (self::relFiles($this->copy) as $rel) {
            if ($rel === self::RUNNER || str_starts_with($rel, 'var/')) {
                continue;
            }
            $copyHash = hash_file('sha256', $this->copy . '/' . $rel);
            $hostFile = $this->root . '/' . $rel;
            if (! is_file($hostFile)) {
                $out[$rel] = ['status' => 'added', 'sha256' => $copyHash ?: null];
            } elseif (hash_file('sha256', $hostFile) !== $copyHash) {
                $out[$rel] = ['status' => 'modified', 'sha256' => $copyHash ?: null];
            }
        }
        foreach (self::relFiles($this->root) as $rel) {
            if (str_starts_with($rel, 'var/') || $rel === '.env') {
                continue;
            }
            if (! is_file($this->copy . '/' . $rel)) {
                $out[$rel] = ['status' => 'deleted', 'sha256' => null];
            }
        }

        return $out;
    }

    /**
     * Of the paths this trial touched, the ones whose host content MOVED since the copy — the moved
     * target of Rule 1. A promotion over a moved target is a new proposal, not a merge (0068).
     *
     * @return list<string>
     */
    public function stale(): array
    {
        $manifest = $this->manifest();
        $stale = [];
        foreach (array_keys($this->diff()) as $rel) {
            $baseline = $manifest[$rel] ?? null;
            $current = is_file($this->root . '/' . $rel) ? (hash_file('sha256', $this->root . '/' . $rel) ?: null) : null;
            if ($baseline !== $current) {
                $stale[] = $rel;
            }
        }
        sort($stale);

        return $stale;
    }

    /**
     * What the host held for each copied path at materialize time.
     *
     * @return array<string, string>
     */
    public function manifest(): array
    {
        $raw = @file_get_contents(self::baseDir($this->root, $this->id) . '/manifest.json');
        $decoded = \is_string($raw) ? json_decode($raw, true) : null;

        $out = [];
        foreach (\is_array($decoded) ? $decoded : [] as $path => $hash) {
            if (\is_string($path) && \is_string($hash)) {
                $out[$path] = $hash;
            }
        }

        return $out;
    }

    /** The trial's own directory, where a promotion keeps its pre-image. */
    public function baseDirectory(): string
    {
        return self::baseDir($this->root, $this->id);
    }

    /** Erase this trial entirely. */
    public function discard(): void
    {
        self::rmrf(self::baseDir($this->root, $this->id));
    }

    private static function baseDir(string $root, string $id): string
    {
        return $root . '/var/trials/' . $id;
    }

    private static function guardId(string $id): void
    {
        if ($id === '' || str_contains($id, '/') || str_contains($id, '\\') || str_contains($id, '..')) {
            throw new \InvalidArgumentException("a trial id must name one directory, not a path: {$id}");
        }
    }

    private static function copyTree(string $root, string $copy): void
    {
        // rsync is the measured mechanism (0272); the contract is «the copy, minus var/ and .env»,
        // and a host without rsync still gets it through the plain-PHP walk below.
        if (self::hasRsync()) {
            $cmd = sprintf(
                'rsync -a --exclude=/var/ --exclude=/vendor/ --exclude=/.env %s %s',
                escapeshellarg(rtrim($root, '/') . '/'),
                escapeshellarg(rtrim($copy, '/') . '/'),
            );
            exec($cmd, $_, $code);
            if ($code === 0) {
                return;
            }
        }

        foreach (self::relFiles($root) as $rel) {
            $top = explode('/', $rel)[0];
            if ($top === 'var' || $rel === '.env') {
                continue;
            }
            $dest = $copy . '/' . $rel;
            $dir = \dirname($dest);
            if (! is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            copy($root . '/' . $rel, $dest);
        }
    }

    private static function hasRsync(): bool
    {
        exec('command -v rsync 2>/dev/null', $out, $code);

        return $code === 0;
    }

    /** @return array<string, string> */
    private static function hashTree(string $dir): array
    {
        $out = [];
        foreach (self::relFiles($dir) as $rel) {
            if ($rel === self::RUNNER || str_starts_with($rel, 'var/')) {
                continue;
            }
            $out[$rel] = hash_file('sha256', $dir . '/' . $rel) ?: '';
        }

        return $out;
    }

    /** @return list<string> */
    private static function relFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $base = \strlen($dir);
        // PRUNE `var/` AND `vendor/` DURING THE WALK, not after — that is where the cost is
        // (decisions/0070): the diff hashed all 9843 files, ~160 MB of them vendor the mutation can
        // never touch. Pruning at the top level means we never descend into vendor at all, and a stray
        // vendor path can never enter the diff to be silently dropped — it is out of scope by
        // construction. Deeper `vendor/`/`var/` (e.g. `src/var/…`) are real app files and stay.
        $prune = static function (\SplFileInfo $f) use ($base): bool {
            $rel = ltrim(substr($f->getPathname(), $base), '/');

            return ! \in_array(explode('/', $rel)[0], self::PRUNE, true);
        };
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                $prune,
            ),
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[] = ltrim(substr($file->getPathname(), $base), '/');
            }
        }
        sort($out);

        return $out;
    }

    private static function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }
}
