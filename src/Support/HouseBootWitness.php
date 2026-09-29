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

use Milpa\Plugin\Contracts\BootWitnessInterface;

/**
 * Every writer of what the house boots from writes through here: the house as it would be boots first.
 *
 * ── WHAT IT EXTENDS (greenhouse decisions/0515) ────────────────────────────────────────────────
 *
 * 0512 made `sandbox:promote` boot a {@see BootCandidate} before touching the live tree. The other writers
 * of the kernel's definition — `plugins.register` and the toggles (milpa/plugin), `framework:apply`,
 * `sandbox:undo` — still wrote first, and a class that misses an interface method, once declared or
 * switched on, is a compile fatal in every process that boots, the one that would undo it included. This
 * is the one rule they share, so it is written once:
 *
 *   1. The change is applied to a copy of the house and the copy is booted. If it does not boot, nothing
 *      is written: `unwritten` names the paths, and the answer says whether the house boots as it is.
 *   2. RECOVERY IS NEVER REFUSED BECAUSE THE HOUSE IS ALREADY BROKEN (0506). A recovery write whose copy
 *      does not boot, on a house that does not boot as it is either, is written — it may be one step of
 *      several — and says what is still wrong (`still_broken`). A «recovery» that would break a house that
 *      boots is not recovery, and is refused like any other write.
 *   3. After the write the live house is asked again — 0506's check, kept as the safety net for what a
 *      copy cannot see (a boot that reads the house's own `var/`). An ordinary write that fails it is put
 *      back to the bytes it replaced (`rolled_back`).
 *
 * Every refusal carries `reason`, the boot's own line, so a writer with its own sentence can say it (`sandbox:promote`
 * keeps 0512's). `sandbox:promote` writes through here too since Rod's answer to 0515 (4): the rule lives in one place.
 *
 * A house with no `vendor/autoload.php` is not a house that boots at all; there is nothing to witness, and
 * the write happens as it always did — the same condition `sandbox:promote` uses.
 *
 * Composer is a writer of what the house boots too — `vendor/`, `composer.json`, `composer.lock` — and its
 * bytes cannot be named before it runs, so it has its own door, {@see composeIfItBoots()}, under the same
 * three rules (decisions/0527).
 */
final class HouseBootWitness implements BootWitnessInterface
{
    private readonly string $root;

    /**
     * @param bool $probeBefore false skips the copy and keeps only the ask after the write — 0506's order, kept
     *                          as the positive control of the window a copy closes (decisions/0512)
     */
    public function __construct(
        string $root,
        private readonly BootProbe $probe = new BootProbe(),
        private readonly bool $probeBefore = true,
    ) {
        // THE ROOT BY ITS REAL NAME (greenhouse evidence/1061). The house's root often arrives spelled through its own
        // `vendor/` (`…/vendor/composer/../..`, how Capabilities::raizDeLaApp() finds it); once a swap renames
        // `vendor/` away that spelling resolves to nothing, and every path after it with it.
        $real = realpath($root);
        $this->root = rtrim($real !== false ? $real : $root, '/');
    }

    /**
     * Run `$commit` only if the house boots with `$writes` written and `$deletes` removed — the three rules above.
     *
     * @param array<string, string> $writes  path relative to the app root → the bytes it will hold
     * @param callable(): void      $commit  the write itself, on the live house
     * @param list<string>          $deletes paths relative to the app root the write removes
     *
     * @return array{refused: ?string, said: array<string, mixed>}
     */
    public function writeIfItBoots(array $writes, callable $commit, bool $recovery = false, array $deletes = []): array
    {
        if (!is_file($this->root . '/vendor/autoload.php')) {
            $commit();

            return ['refused' => null, 'said' => []];
        }
        $paths = array_values(array_unique([...array_keys($writes), ...$deletes]));
        sort($paths);

        $why = $this->probeBefore ? $this->probe->whyNotWith($this->root, $writes, $deletes) : null;
        if ($why !== null) {
            $now = $this->probe->whyNot($this->root);
            if (!$recovery || $now === null) {
                return [
                    'refused' => 'The house does not boot with this change: ' . rtrim($why, '.') . '. Nothing was written; the house '
                        . ($now === null ? 'boots as it is.' : 'does not boot as it is either: ' . $now . '.'),
                    'said' => ['unwritten' => $paths, 'house_boots' => $now === null, 'reason' => $why],
                ];
            }
        }

        $before = [];
        foreach ($paths as $path) {
            $file = $this->root . '/' . $path;
            $before[$path] = is_file($file) ? (string) file_get_contents($file) : null;
        }
        $commit();
        CompiledCode::forget($this->root, $paths);

        $after = $this->probe->whyNot($this->root);
        if ($after === null) {
            return ['refused' => null, 'said' => ['house_boots' => true]];
        }
        if ($recovery && $why !== null) {
            return ['refused' => null, 'said' => ['house_boots' => false, 'still_broken' => $after]];
        }
        foreach ($before as $path => $bytes) {
            $file = $this->root . '/' . $path;
            if ($bytes === null) {
                @unlink($file);
            } else {
                if (!is_dir(\dirname($file))) {
                    mkdir(\dirname($file), 0o777, true);
                }
                // WRITE-THEN-RENAME, as the promotion's own reversa did (0506): the house never sees a half-written file.
                file_put_contents($file . '.witness-tmp', $bytes);
                rename($file . '.witness-tmp', $file);
            }
        }
        CompiledCode::forget($this->root, $paths);

        return [
            'refused' => 'The house did not boot after this change was written: ' . rtrim($after, '.') . '. It was put back to what it held.',
            'said' => ['rolled_back' => $paths, 'house_boots' => $this->probe->whyNot($this->root) === null, 'reason' => $after],
        ];
    }

    /**
     * Run a Composer command on a staged copy of the house, boot it, and let it land only if it booted.
     *
     * ── WHY COMPOSER GETS ITS OWN DOOR (greenhouse decisions/0527) ──────────────────────────────────
     *
     * {@see writeIfItBoots()} is told the bytes before anything runs. Composer cannot say its bytes: what a
     * `require` writes is decided by the resolver, the registry and the package's own archive, and
     * `--dry-run` names packages, not files — it cannot be booted. So the command itself runs, on a stage
     * ({@see BootCandidate::staged()}: the house copied, `vendor/` a copy-on-write copy of its own), and the
     * stage is booted. The same three rules then hold:
     *
     *   1. The stage does not boot → nothing lands: the live `vendor/`, `composer.json` and `composer.lock` are
     *      the ones that were there (`unwritten`), and `house_boots` says whether the house boots as it is.
     *   2. With `$recovery`, a stage that does not boot on a house that does not boot either lands anyway
     *      (`still_broken`); a «recovery» that would break a house that boots is refused.
     *   3. What landed is asked again on the live house (0506's net); an ordinary change that fails it is put
     *      back — the previous `vendor/` renamed back, the two files rewritten (`rolled_back`).
     *
     * It lands by SWAPPING, not by running Composer again: the live `vendor/` is renamed into the stage and the
     * stage's `vendor/` renamed into the house — two renames on one filesystem, so the house never serves a
     * half-installed tree, and what serves is the very tree that booted. Only `vendor/`, `composer.json` and
     * `composer.lock` are taken from the stage; anything else a Composer plugin wrote there is left behind.
     * When the stage is not on the house's filesystem the rename cannot happen, and the command runs again on
     * the live house (`landed_by: rerun`), still only after its stage booted.
     *
     * A Composer failure (a conflict, no network) is Composer's answer, returned as `code`/`output` — and it
     * touched only the stage.
     *
     * @param string                                                        $command the Composer command, without `--no-interaction`
     * @param null|callable(string, string): array{0: int, 1: list<string>} $run     runs a command in a directory
     *
     * @return array{refused: ?string, said: array<string, mixed>, code: int, output: list<string>}
     */
    public function composeIfItBoots(string $command, ?callable $run = null, bool $recovery = false): array
    {
        $run ??= static function (string $cmd, string $cwd): array {
            $out = [];
            $code = 1;
            exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1', $out, $code);

            return [$code, $out];
        };
        $command .= ' --no-interaction';
        if (!is_file($this->root . '/vendor/autoload.php')) {
            [$code, $out] = $run($command, $this->root);

            return ['refused' => null, 'said' => [], 'code' => $code, 'output' => $out];
        }
        $files = ['composer.json', 'composer.lock'];
        $paths = [...$files, 'vendor/'];

        try {
            $stage = BootCandidate::staged($this->root);
        } catch (\RuntimeException $e) {
            return [
                'refused' => 'The house as it would be could not be staged to boot it: ' . $e->getMessage() . '. Nothing was written.',
                'said' => ['unwritten' => $paths, 'house_boots' => $this->probe->whyNot($this->root) === null, 'reason' => $e->getMessage()],
                'code' => 1,
                'output' => [],
            ];
        }
        try {
            $urls = self::pathRepositoriesMadeAbsolute($this->root, $stage->path);
            [$code, $out] = $run($command, $stage->path);
            if ($code !== 0) {
                return ['refused' => null, 'said' => ['unwritten' => $paths], 'code' => $code, 'output' => $out];
            }

            $why = $this->probeBefore ? $this->probe->whyNot($stage->path) : null;
            $why = $why === null ? null : BootProbe::oneLine($why, $stage->path);
            if ($why !== null) {
                $now = $this->probe->whyNot($this->root);
                if (!$recovery || $now === null) {
                    return [
                        'refused' => 'The house does not boot with this change: ' . rtrim($why, '.') . '. Nothing was written; the house '
                            . ($now === null ? 'boots as it is.' : 'does not boot as it is either: ' . $now . '.'),
                        'said' => ['unwritten' => $paths, 'house_boots' => $now === null, 'reason' => $why],
                        'code' => $code,
                        'output' => $out,
                    ];
                }
            }

            $before = [];
            foreach ($files as $file) {
                $before[$file] = is_file($this->root . '/' . $file) ? (string) file_get_contents($this->root . '/' . $file) : null;
            }
            $landedBy = $this->swapIn($stage, $files, $urls);
            if ($landedBy === null) {
                [$code, $out] = $run($command, $this->root);
                $landedBy = 'rerun';
                if ($code !== 0) {
                    return ['refused' => null, 'said' => ['landed_by' => $landedBy], 'code' => $code, 'output' => $out];
                }
            }
            CompiledCode::forget($this->root, ['vendor/autoload.php', ...self::composerMaps($this->root)]);

            $after = $this->probe->whyNot($this->root);
            if ($after === null) {
                return ['refused' => null, 'said' => ['house_boots' => true, 'landed_by' => $landedBy], 'code' => $code, 'output' => $out];
            }
            if ($recovery && $why !== null) {
                return ['refused' => null, 'said' => ['house_boots' => false, 'still_broken' => $after, 'landed_by' => $landedBy], 'code' => $code, 'output' => $out];
            }
            $this->putBack($stage, $before);
            CompiledCode::forget($this->root, ['vendor/autoload.php', ...self::composerMaps($this->root)]);

            return [
                'refused' => 'The house did not boot after this change was written: ' . rtrim($after, '.') . '. It was put back to what it held.',
                'said' => ['rolled_back' => $paths, 'house_boots' => $this->probe->whyNot($this->root) === null, 'reason' => $after],
                'code' => $code,
                'output' => $out,
            ];
        } finally {
            // NEVER THE ONLY vendor/ THE HOUSE HAS (evidence/1061). If a swap left the house without one, the previous
            // one goes back before the stage is removed — and a stage still holding it is kept, not deleted.
            if (!is_dir($this->root . '/vendor') && is_dir($stage->path . '/vendor.previous')) {
                @rename($stage->path . '/vendor.previous', $this->root . '/vendor');
            }
            if (is_dir($this->root . '/vendor') || !is_dir($stage->path . '/vendor.previous')) {
                $stage->remove();
            }
        }
    }

    /**
     * The stage's `vendor/` becomes the house's, and its two files are written — or null when a rename cannot.
     *
     * @param list<string>          $files
     * @param array<string, string> $urls  what {@see pathRepositoriesMadeAbsolute()} changed, absolute → as written
     */
    private function swapIn(BootCandidate $stage, array $files, array $urls): ?string
    {
        $stage->relinkFor($this->root . '/vendor');
        if (!@rename($this->root . '/vendor', $stage->path . '/vendor.previous')) {
            return null;
        }
        if (!@rename($stage->path . '/vendor', $this->root . '/vendor')) {
            rename($stage->path . '/vendor.previous', $this->root . '/vendor');

            return null;
        }
        foreach ($files as $file) {
            $staged = $stage->path . '/' . $file;
            // The file that was here is kept aside by a second name (the same inode), so a put-back is a rename.
            if (is_file($this->root . '/' . $file)) {
                @link($this->root . '/' . $file, $stage->path . '/' . $file . '.previous');
            }
            if (is_file($staged)) {
                $bytes = strtr((string) file_get_contents($staged), $urls);
                if (is_file($this->root . '/' . $file) && file_get_contents($this->root . '/' . $file) === $bytes) {
                    // What Composer left as it was is not written: an update does not touch composer.json.
                    continue;
                }
                // WRITE-THEN-RENAME: the house never reads a half-written composer.json.
                file_put_contents($this->root . '/' . $file . '.witness-tmp', $bytes);
                rename($this->root . '/' . $file . '.witness-tmp', $this->root . '/' . $file);
            } elseif (is_file($this->root . '/' . $file)) {
                unlink($this->root . '/' . $file);
            }
        }
        if ($urls !== [] && is_file($this->root . '/composer.lock')) {
            $lock = (string) file_get_contents($this->root . '/composer.lock');
            $hash = self::contentHash((string) file_get_contents($this->root . '/composer.json'));
            $lock = (string) preg_replace('/("content-hash":\s*")[0-9a-f]{32}(")/', '${1}' . $hash . '${2}', $lock, 1);
            file_put_contents($this->root . '/composer.lock.witness-tmp', $lock);
            rename($this->root . '/composer.lock.witness-tmp', $this->root . '/composer.lock');
        }

        return 'swap';
    }

    /**
     * The `vendor/` that booted before goes back, and the two files get the bytes they held.
     *
     * @param array<string, ?string> $before
     */
    private function putBack(BootCandidate $stage, array $before): void
    {
        if (is_dir($stage->path . '/vendor.previous')) {
            rename($this->root . '/vendor', $stage->path . '/vendor.rejected');
            rename($stage->path . '/vendor.previous', $this->root . '/vendor');
        }
        foreach ($before as $file => $bytes) {
            if ($bytes === null) {
                @unlink($this->root . '/' . $file);

                continue;
            }
            $kept = $stage->path . '/' . $file . '.previous';
            if (is_file($kept) && file_get_contents($kept) === $bytes && @rename($kept, $this->root . '/' . $file)) {
                continue;
            }
            file_put_contents($this->root . '/' . $file . '.witness-tmp', $bytes);
            rename($this->root . '/' . $file . '.witness-tmp', $this->root . '/' . $file);
        }
    }

    /**
     * A path repository written relative resolves from the working directory; in the stage that is the wrong one.
     * Its url string is made absolute in the STAGE's composer.json (the file's own formatting kept), and before
     * it lands every recorded copy of it gets back the string as written.
     *
     * @return array<string, string> the quoted, JSON-escaped absolute url → the same for the url as written
     */
    private static function pathRepositoriesMadeAbsolute(string $root, string $stage): array
    {
        $file = $stage . '/composer.json';
        $bytes = is_file($file) ? (string) file_get_contents($file) : '';
        $json = json_decode($bytes, true);
        if (!\is_array($json) || !\is_array($json['repositories'] ?? null)) {
            return [];
        }
        $map = [];
        foreach ($json['repositories'] as $repository) {
            $url = \is_array($repository) ? ($repository['url'] ?? null) : null;
            if (($repository['type'] ?? null) !== 'path' || !\is_string($url) || $url === '' || str_starts_with($url, '/')) {
                continue;
            }
            $absolute = rtrim($root, '/') . '/' . $url;
            foreach ([0, \JSON_UNESCAPED_SLASHES] as $flags) {
                $map[(string) json_encode($absolute, $flags)] = (string) json_encode($url, $flags);
            }
        }
        if ($map !== []) {
            file_put_contents($file, strtr($bytes, array_flip($map)));
        }

        return $map;
    }

    /**
     * Composer's own content hash of a composer.json (`Composer\Package\Locker::getContentHash()`), so a lock
     * whose repository strings were given back still matches the file it locks.
     */
    public static function contentHash(string $composerJson): string
    {
        $content = json_decode($composerJson, true);
        $content = \is_array($content) ? $content : [];
        $relevant = [];
        foreach (['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra'] as $key) {
            if (isset($content[$key])) {
                $relevant[$key] = $content[$key];
            }
        }
        if (isset($content['config']['platform'])) {
            $relevant['config']['platform'] = $content['config']['platform'];
        }
        ksort($relevant);

        return md5((string) json_encode($relevant));
    }

    /**
     * The autoloader's own maps under `vendor/composer/` — the files OPcache must forget after a swap.
     *
     * @return list<string>
     */
    private static function composerMaps(string $root): array
    {
        $maps = [];
        foreach (glob($root . '/vendor/composer/*.php') ?: [] as $file) {
            $maps[] = 'vendor/composer/' . basename($file);
        }

        return $maps;
    }
}
