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
 * A house with no `vendor/autoload.php` is not a house that boots at all; there is nothing to witness, and
 * the write happens as it always did — the same condition `sandbox:promote` uses.
 */
final class HouseBootWitness implements BootWitnessInterface
{
    private readonly string $root;

    public function __construct(string $root, private readonly BootProbe $probe = new BootProbe())
    {
        $this->root = rtrim($root, '/');
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

        $why = $this->probe->whyNotWith($this->root, $writes, $deletes);
        if ($why !== null) {
            $now = $this->probe->whyNot($this->root);
            if (!$recovery || $now === null) {
                return [
                    'refused' => 'The house does not boot with this change: ' . rtrim($why, '.') . '. Nothing was written; the house '
                        . ($now === null ? 'boots as it is.' : 'does not boot as it is either: ' . $now . '.'),
                    'said' => ['unwritten' => $paths, 'house_boots' => $now === null],
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
                file_put_contents($file, $bytes, \LOCK_EX);
            }
        }
        CompiledCode::forget($this->root, $paths);

        return [
            'refused' => 'The house did not boot after this change was written: ' . rtrim($after, '.') . '. It was put back to what it held.',
            'said' => ['rolled_back' => $paths, 'house_boots' => $this->probe->whyNot($this->root) === null],
        ];
    }
}
