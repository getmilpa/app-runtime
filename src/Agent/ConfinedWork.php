<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * One call of work, run in the house: the pre-image kept, the call confined, and what its state was and is
 * (greenhouse decisions/0588, rules 2, 5 and 6).
 *
 * {@see HouseWork} judged that the call is work and where its state lives; this runs it. Before the call, the
 * house digests each path of the state and keeps what was there under `var/work/<id>/pre/`. Then the child runs
 * with nothing but that state to write ({@see TrialRunner::work()}). After it, the house digests again: the two
 * digests are the receipt, and they are the house's — a handler that answers `ok: true` and writes nothing leaves
 * two equal digests.
 *
 * A PATH THAT IS NOT THERE YET is made so it can be mounted — an empty file when its name has an extension, a
 * directory when it has none — and taken away again if the call left it empty: the house does not leave behind
 * what the call did not write.
 *
 * UNDOING IS NOT HERE. The pre-image is what there will be to return to; `var/work/` keeps the newest
 * {@see KEEP} of them.
 */
final class ConfinedWork
{
    /** The most pre-images kept at once: `var/work/` shares the disk the session writes to. */
    public const KEEP = 24;

    public function __construct(
        private readonly string $root,
        private readonly TrialRunner $runner,
        private readonly string $runnerScript,
    ) {
    }

    /**
     * Run this call confined to its state and say what the state was and is.
     *
     * @param array<string, mixed> $input
     *
     * @throws \LogicException   when the plan is not one the house runs confined
     * @throws \RuntimeException when a path is no longer a place for state, or the house cannot prepare the call
     */
    public function run(WorkPlan $plan, array $input): WorkOutcome
    {
        if (! $plan->confined || $plan->refused !== null || $plan->state === []) {
            throw new \LogicException('Only a call the house can confine to a declared state is run here.');
        }
        $root = rtrim($this->root, '/');
        $id = 'k' . bin2hex(random_bytes(8));
        $base = $root . '/var/work/' . $id;
        if (! mkdir($base . '/scratch', 0o700, true) && ! is_dir($base . '/scratch')) {
            throw new \RuntimeException('The house could not prepare a place for this call.');
        }
        $made = [];
        $before = [];
        try {
            foreach ($plan->state as $path) {
                $why = HouseWork::notAPlaceForState($root, $path);
                if ($why !== null) {
                    throw new \RuntimeException("«{$path}» is not a place work may keep state: {$why}.");
                }
                $before[$path] = self::digest($root . '/' . $path);
                if ($before[$path] === null) {
                    $this->make($root . '/' . $path);
                    $made[] = $path;
                } elseif ($plan->preImage) {
                    self::copy($root . '/' . $path, $base . '/pre/' . $path);
                }
            }
            $run = $this->runner->work($root, $this->runnerScript, $plan->operation, $input, $plan->state, $base . '/scratch');
            $state = [];
            foreach ($plan->state as $path) {
                if (\in_array($path, $made, true) && self::empty($root . '/' . $path)) {
                    is_dir($root . '/' . $path) ? rmdir($root . '/' . $path) : unlink($root . '/' . $path);
                }
                $state[] = ['path' => $path, 'before' => $before[$path], 'after' => self::digest($root . '/' . $path)];
            }
        } catch (\Throwable $failure) {
            foreach ($made as $path) {
                if (self::empty($root . '/' . $path)) {
                    is_dir($root . '/' . $path) ? @rmdir($root . '/' . $path) : @unlink($root . '/' . $path);
                }
            }
            self::remove($base);
            throw $failure;
        }
        self::remove($base . '/scratch');
        $changed = array_filter($state, static fn (array $entry): bool => $entry['before'] !== $entry['after']) !== [];
        if (! $changed || ! $plan->preImage) {
            self::remove($base);

            return new WorkOutcome($run, $state, $changed, null);
        }
        file_put_contents($base . '/manifest.json', json_encode(
            ['id' => $id, 'operation' => $plan->operation, 'at' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'state' => $state],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ) . "\n");
        self::cap($root . '/var/work', self::KEEP);

        return new WorkOutcome($run, $state, true, $id);
    }

    /** The digest of a file, or of a directory's files by name — null for a path that is not there. */
    public static function digest(string $path): ?string
    {
        if (is_file($path)) {
            return 'sha256:' . hash_file('sha256', $path);
        }
        if (! is_dir($path)) {
            return null;
        }
        $entries = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && ! $file->isLink()) {
                $entries[substr($file->getPathname(), \strlen($path) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($entries);

        return 'sha256:' . hash('sha256', (string) json_encode($entries, \JSON_UNESCAPED_SLASHES));
    }

    /** Make a path that is not there yet, so it can be mounted: a file when its name has an extension. */
    private function make(string $path): void
    {
        // The failure is handled right below, with a sentence of the house's; PHP's own warning adds nothing.
        $made = pathinfo($path, \PATHINFO_EXTENSION) === ''
            ? (@mkdir($path, 0o775, true) || is_dir($path))
            : ((is_dir(\dirname($path)) || @mkdir(\dirname($path), 0o775, true)) && @touch($path));
        if (! $made) {
            throw new \RuntimeException('The house could not prepare «' . basename($path) . '» for this call.');
        }
    }

    private static function empty(string $path): bool
    {
        return is_dir($path) ? \count(scandir($path) ?: []) === 2 : is_file($path) && filesize($path) === 0;
    }

    private static function copy(string $from, string $to): void
    {
        if (is_file($from)) {
            if ((! is_dir(\dirname($to)) && ! mkdir(\dirname($to), 0o700, true) && ! is_dir(\dirname($to))) || ! copy($from, $to)) {
                throw new \RuntimeException('The house could not keep what the state was before this call.');
            }

            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && ! $file->isLink()) {
                self::copy($file->getPathname(), $to . '/' . substr($file->getPathname(), \strlen($from) + 1));
            }
        }
        if (! is_dir($to) && ! mkdir($to, 0o700, true) && ! is_dir($to)) {
            throw new \RuntimeException('The house could not keep what the state was before this call.');
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }

    /** Keep the newest `$keep` pre-images; the oldest go whole. */
    private static function cap(string $dir, int $keep): void
    {
        $kept = array_filter(glob($dir . '/k*') ?: [], is_dir(...));
        usort($kept, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (\array_slice($kept, $keep) as $old) {
            self::remove($old);
        }
    }
}
