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

    private readonly ?string $unconfinedAccepted;

    /** What was given to accept the risk and did not count as naming it — said back, so nobody believes it accepted. */
    private readonly ?string $acceptanceRefused;

    /**
     * @param bool                  $probeBefore        false skips the copy and keeps only the ask after the write — 0506's order, kept
     *                                                  as the positive control of the window a copy closes (decisions/0512)
     * @param string|null           $unconfinedAccepted the risk someone accepted BY NAME so that, on a machine that cannot confine,
     *                                                  the boot of what Composer would install runs unconfined anyway — null, or
     *                                                  words that name nothing ({@see acceptance()}), refuses that boot there
     *                                                  instead (decisions/0627, Rod's answer 1). Only a person gives it, on the
     *                                                  console: no operation carries it in its input (C11 review of 0627, H-1)
     * @param array<string, string> $acceptedUnder      what the journal keeps beside an accepted boot: who authorized the call that
     *                                                  carried it, and the id of that authorization (decisions/0630)
     */
    public function __construct(
        string $root,
        private readonly BootProbe $probe = new BootProbe(),
        private readonly bool $probeBefore = true,
        ?string $unconfinedAccepted = null,
        private readonly array $acceptedUnder = [],
    ) {
        $this->unconfinedAccepted = self::acceptance($unconfinedAccepted);
        $given = $unconfinedAccepted === null ? '' : trim($unconfinedAccepted);
        $this->acceptanceRefused = $this->unconfinedAccepted === null && $given !== '' ? self::shown($given) : null;
        // THE ROOT BY ITS REAL NAME (greenhouse evidence/1061). The house's root often arrives spelled through its own
        // `vendor/` (`…/vendor/composer/../..`, how Capabilities::raizDeLaApp() finds it); once a swap renames
        // `vendor/` away that spelling resolves to nothing, and every path after it with it.
        $real = realpath($root);
        $this->root = rtrim($real !== false ? $real : $root, '/');
    }

    /**
     * Run `$commit` only if the house boots with `$writes` written and `$deletes` removed — the three rules above.
     *
     * @param array<string, string>          $writes  path relative to the app root → the bytes it will hold
     * @param callable(): void               $commit  the write itself, on the live house
     * @param list<string>                   $deletes paths relative to the app root the write removes
     * @param null|callable(string): ?string $judge   asked of the copy once it BOOTED, before anything is written: null lets
     *                                                the write go on, a sentence refuses it (`judged`, nothing written) —
     *                                                how `sandbox:promote` refuses a route it breaks (decisions/0540)
     *
     * @return array{refused: ?string, said: array<string, mixed>}
     */
    public function writeIfItBoots(array $writes, callable $commit, bool $recovery = false, array $deletes = [], ?callable $judge = null): array
    {
        if (!is_file($this->root . '/vendor/autoload.php')) {
            $commit();

            return ['refused' => null, 'said' => []];
        }
        $paths = array_values(array_unique([...array_keys($writes), ...$deletes]));
        sort($paths);

        $judged = null;
        $ask = $judge === null ? null : static function (string $candidate) use ($judge, &$judged): void {
            $judged = $judge($candidate);
        };
        // The boot check runs as a trial and says the two things 1180 §4.1 asks for: the ROOM it ran in (confined,
        // no network, the envelope and the keyring masked — or, with no bwrap, unconfined, said), and the plugins
        // that mounted FEWER routes without their secret. Carried into `said` so a writer's answer names them.
        $check = $this->probeBefore ? $this->probe->check($this->root, $writes, $deletes, $ask) : null;
        $why = $check['why'] ?? null;
        $room = $check === null ? [] : ['boot_room' => $check['room']]
            + ($check['mounted_less'] === [] ? [] : ['mounted_less_without_their_secret' => $check['mounted_less']])
            + ($check['new_plugins'] === [] ? [] : ['new_plugins' => $check['new_plugins']]);
        if ($why === null && $judged !== null) {
            return ['refused' => $judged, 'said' => ['unwritten' => $paths, 'judged' => $judged] + $room];
        }
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
            return ['refused' => null, 'said' => ['house_boots' => true] + $room];
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
     * ── WHAT NOBODY APPLIED BOOTS CONFINED HERE TOO (greenhouse decisions/0627; evidence/1210) ─────────
     *
     * The stage's `vendor/` is code nobody has applied yet, so its boot is a trial's: {@see BootProbe::checkTree()},
     * the one confinement, with the house's secrets masked, and `boot_room` says where it ran. Where this machine
     * cannot confine (no bwrap), the door REFUSES before Composer runs — saying UNCONFINED is not authorizing — unless
     * a person accepted that risk in words (`$unconfinedAccepted`, {@see acceptance()}); then the stage boots
     * unconfined, and the answer carries both the room and the risk accepted (`unconfined_accepted`). That acceptance
     * reaches this class only from the console a person types on (`coa update`, `coa repair` and
     * `coa capabilities:enable`, with `--accept-unconfined=…`); no operation carries it in its input (decisions/0627,
     * Rod's answer 1; C11 review of 0627, H-1; decisions/0630). The live house's
     * ask after the swap stays plain: that one IS the applied house (decisions/0606 §3).
     *
     * Composer runs on the stage, and on a rerun, with `--no-plugins --no-scripts` (0627 answer 2, measured in 1210:
     * no installable capability declares scripts or ships a plugin, and every one installs and boots without them).
     * A package that IS a plugin then lands unloaded, and a later plain `composer install` will meet `allow-plugins`:
     * the answer says which (`plugins_not_loaded`) instead of dropping Composer's line. The house's first install,
     * with no `vendor/` yet, is another door and keeps its command as it was.
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
            exec('cd ' . ShellArgument::quote($cwd) . ' && ' . $cmd . ' 2>&1', $out, $code);

            return [$code, $out];
        };
        if (!is_file($this->root . '/vendor/autoload.php')) {
            [$code, $out] = $run($command . ' --no-interaction', $this->root);

            return ['refused' => null, 'said' => [], 'code' => $code, 'output' => $out];
        }
        $command .= ' --no-plugins --no-scripts --no-interaction';
        $files = ['composer.json', 'composer.lock'];
        $paths = [...$files, 'vendor/'];

        // ASKED BEFORE COMPOSER RUNS: a boot that will not be run unconfined makes the install pointless, and the
        // refusal can say that nothing ran at all.
        $recorded = [];
        if ($this->probeBefore && !$this->probe->canConfine()) {
            if ($this->unconfinedAccepted === null) {
                return $this->refusedUnconfined($paths, 0, []);
            }
            // THE ACCEPTANCE STAYS IN THE RECORD next to the UNCONFINED (0627, Rod's answer 1; C11 review of 0627, L-D),
            // written BEFORE anything runs unconfined: a boot that cannot be recorded is not run.
            if (!$this->record($command)) {
                return [
                    'refused' => 'The acceptance of an unconfined boot could not be recorded in ' . self::JOURNAL . ', so that boot is not run '
                        . '(decisions/0627). Nothing was written; the house is as it was.',
                    'said' => ['unwritten' => $paths, 'unconfined_accepted' => $this->unconfinedAccepted],
                    'code' => 1,
                    'output' => [],
                ];
            }
            $recorded = ['unconfined_recorded_in' => self::JOURNAL];
        }

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

            $why = null;
            $plugins = self::pluginsNotLoaded($out);
            $room = $plugins === [] ? [] : ['plugins_not_loaded' => $plugins];
            if ($this->probeBefore) {
                $check = $this->probe->checkTree($stage->path, $this->root);
                if (!$check['confined'] && $this->unconfinedAccepted === null) {
                    // The machine lost its namespace between the two asks: still not run unconfined unaccepted.
                    return $this->refusedUnconfined($paths, $code, $out);
                }
                $why = $check['why'];
                $room = ['boot_room' => $check['room']]
                    + ($check['confined'] ? [] : ['unconfined_accepted' => $this->unconfinedAccepted] + $recorded)
                    + $room;
            }
            if ($why !== null) {
                $now = $this->probe->whyNot($this->root);
                if (!$recovery || $now === null) {
                    return [
                        'refused' => 'The house does not boot with this change: ' . rtrim($why, '.') . '. Nothing was written; the house '
                            . ($now === null ? 'boots as it is.' : 'does not boot as it is either: ' . $now . '.'),
                        'said' => ['unwritten' => $paths, 'house_boots' => $now === null, 'reason' => $why] + $room,
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
                    return ['refused' => null, 'said' => ['landed_by' => $landedBy] + $room, 'code' => $code, 'output' => $out];
                }
            }
            CompiledCode::forget($this->root, ['vendor/autoload.php', ...self::composerMaps($this->root)]);

            $after = $this->probe->whyNot($this->root);
            if ($after === null) {
                return ['refused' => null, 'said' => ['house_boots' => true, 'landed_by' => $landedBy] + $room, 'code' => $code, 'output' => $out];
            }
            if ($recovery && $why !== null) {
                return ['refused' => null, 'said' => ['house_boots' => false, 'still_broken' => $after, 'landed_by' => $landedBy] + $room, 'code' => $code, 'output' => $out];
            }
            $this->putBack($stage, $before);
            CompiledCode::forget($this->root, ['vendor/autoload.php', ...self::composerMaps($this->root)]);

            return [
                'refused' => 'The house did not boot after this change was written: ' . rtrim($after, '.') . '. It was put back to what it held.',
                'said' => ['rolled_back' => $paths, 'house_boots' => $this->probe->whyNot($this->root) === null, 'reason' => $after] + $room,
                'code' => $code,
                'output' => $out,
            ];
        } finally {
            // NEVER THE ONLY vendor/ THE HOUSE HAS (evidence/1061). If a swap left the house without one, the previous
            // one goes back before the stage is removed — and a stage still holding it is kept, not deleted.
            $previous = self::previousIn($stage);
            if (!is_dir($this->root . '/vendor') && $previous !== null) {
                @rename($previous, $this->root . '/vendor');
            }
            if (is_dir($this->root . '/vendor') || self::previousIn($stage) === null) {
                $stage->remove();
            }
        }
    }

    /**
     * The refusal of a boot that would run unconfined with nobody having accepted it (decisions/0627, answer 1).
     *
     * @param list<string> $paths
     * @param list<string> $out
     *
     * @return array{refused: string, said: array<string, mixed>, code: int, output: list<string>}
     */
    private function refusedUnconfined(array $paths, int $code, array $out): array
    {
        $room = 'UNCONFINED: this machine has no unprivileged namespace (no bwrap), so what Composer would install cannot boot confined here (decisions/0627)';

        return [
            'refused' => 'This machine cannot confine the boot of what Composer would install (no bwrap), and that boot is not run unconfined '
                . 'unless a person accepts the risk by name — saying UNCONFINED is not authorizing (decisions/0627). '
                . ($this->acceptanceRefused === null ? '' : 'What was given («' . $this->acceptanceRefused . '») does not name a risk: it takes words, two at least. ')
                . 'Nothing was written; the house is as it was.',
            'said' => ['unwritten' => $paths, 'boot_room' => $room, 'unconfined_accepted' => null]
                + ($this->acceptanceRefused === null ? [] : ['acceptance_refused' => $this->acceptanceRefused])
                + ['hint' => 'run it where bwrap can confine; or a person, on the console, accepts the risk in words: `' . Capabilities::cli()
                    . 'update <package> --accept-unconfined="<the risk you accept>"` (`' . Capabilities::cli() . 'repair` and `' . Capabilities::cli()
                    . 'capabilities:enable` take it too). No operation carries that acceptance in its input.'],
            'code' => $code,
            'output' => $out,
        ];
    }

    /**
     * What counts as accepting the risk BY NAME (decisions/0627, Rod's answer 1; C11 review of 0627, M-1 and its round 2,
     * L-A and L-B): words that say something, after trimming every Unicode blank. Two of them at least must be words —
     * two letters or more — that are not a token: not `false`, `no`, `not` or `0` (a script's "off" would turn it on),
     * not `yes` or `ok`, and not the room's own word — saying UNCONFINED, even twice, is not authorizing. No control or
     * format character inside: what is accepted is printed back and recorded, and must read as what it is. Null when it
     * names nothing.
     */
    public static function acceptance(?string $given): ?string
    {
        if ($given === null) {
            return null;
        }
        $words = preg_replace('/\A[\s\p{Z}\p{Cc}\p{Cf}]+|[\s\p{Z}\p{Cc}\p{Cf}]+\z/u', '', $given);
        if (!\is_string($words) || preg_match('/[\p{Cc}\p{Cf}]/u', $words) === 1) {
            return null;
        }
        $named = array_filter(
            preg_split('/[\s\p{Z}]+/u', $words) ?: [],
            static function (string $word): bool {
                $letters = (string) preg_replace('/\P{L}+/u', '', $word);

                return preg_match_all('/\p{L}/u', $letters) >= 2 && preg_match(self::TOKENS, $letters) !== 1;
            },
        );

        return \count($named) >= 2 ? $words : null;
    }

    /** Words that answer a question instead of naming a risk. */
    private const string TOKENS = '/\A(?:false|no|not|nope|none|null|nil|off|zero|yes|yep|true|on|ok|okay|sure|si|sí|unconfined|confined)\z/iu';

    /** Where each accepted unconfined boot is recorded, under the house's root, one JSON line each. */
    public const string JOURNAL = 'var/unconfined-acceptances.jsonl';

    /** One line in {@see JOURNAL}: when, what Composer was about to do, where it boots, and the words accepted. */
    private function record(string $command): bool
    {
        $line = json_encode([
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'command' => $command,
            'boot_room' => 'UNCONFINED: no bwrap on this machine (decisions/0627)',
            'unconfined_accepted' => $this->unconfinedAccepted,
        ] + array_filter($this->acceptedUnder, static fn (string $v): bool => $v !== ''), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $path = $this->root . '/' . self::JOURNAL;
        if ($line === false || (!is_dir(\dirname($path)) && !@mkdir(\dirname($path), 0o775, true))) {
            return false;
        }

        return @file_put_contents($path, $line . "\n", \FILE_APPEND | \LOCK_EX) !== false;
    }

    /** What was given, said back with its control and format characters escaped, so the answer reads as what it is. */
    private static function shown(string $given): string
    {
        return preg_replace_callback('/[\p{Cc}\p{Cf}]/u', static fn (array $m): string => trim((string) json_encode($m[0]), '"'), $given)
            ?? 'not valid UTF-8: ' . bin2hex(substr($given, 0, 48));
    }

    /**
     * The plugins Composer did not load because the stage runs it with `--no-plugins` (decisions/0627; evidence/1210):
     * the install lands without them, and a later plain `composer install` will meet `allow-plugins` — said, not dropped
     * (C11 review of 0627, L-3).
     *
     * @param list<string> $out
     *
     * @return list<string>
     */
    private static function pluginsNotLoaded(array $out): array
    {
        $names = [];
        foreach ($out as $line) {
            if (preg_match('/The "([^"]+)" plugin was not loaded/', $line, $m) === 1) {
                $names[] = $m[1];
            }
        }

        return array_values(array_unique($names));
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
        // ONE STEP WHERE THE SYSTEM HAS ONE (evidence/1061, run 7): between two renames the house has no vendor/ for a
        // moment, and a request booting across it died of a class not found. renameat2's exchange swaps both paths at
        // once — GNU `mv --exchange` (coreutils 9.5+, Linux). Where it is absent, the two renames, and the answer says so.
        if (self::exchange($stage->path . '/vendor', $this->root . '/vendor')) {
            $landedBy = 'exchange';
            // The previous vendor/ now sits where the stage's was; it takes the name a put-back looks for.
            @rename($stage->path . '/vendor', $stage->path . '/vendor.previous');
        } else {
            $landedBy = 'swap';
            if (!@rename($this->root . '/vendor', $stage->path . '/vendor.previous')) {
                return null;
            }
            if (!@rename($stage->path . '/vendor', $this->root . '/vendor')) {
                rename($stage->path . '/vendor.previous', $this->root . '/vendor');

                return null;
            }
        }
        // The bytes each file will hold: the stage's, with the path urls given back — and the lock's content hash
        // taken again over the composer.json the house will have, so the lock still locks it.
        $final = [];
        foreach ($files as $file) {
            $final[$file] = is_file($stage->path . '/' . $file) ? strtr((string) file_get_contents($stage->path . '/' . $file), $urls) : null;
        }
        if ($urls !== [] && isset($final['composer.lock'], $final['composer.json'])) {
            $final['composer.lock'] = (string) preg_replace('/("content-hash":\s*")[0-9a-f]{32}(")/', '${1}' . self::contentHash($final['composer.json']) . '${2}', $final['composer.lock'], 1);
        }
        foreach ($final as $file => $bytes) {
            $live = $this->root . '/' . $file;
            if ($bytes !== null && is_file($live) && file_get_contents($live) === $bytes) {
                // What Composer left as it was is not written — not even a second name, which moves its ctime.
                continue;
            }
            // The file that was here is kept aside by a second name (the same inode), so a put-back is a rename.
            if (is_file($live)) {
                @link($live, $stage->path . '/' . $file . '.previous');
            }
            if ($bytes !== null) {
                // WRITE-THEN-RENAME: the house never reads a half-written composer.json.
                file_put_contents($live . '.witness-tmp', $bytes);
                rename($live . '.witness-tmp', $live);
            } elseif (is_file($live)) {
                unlink($live);
            }
        }

        return $landedBy;
    }

    /** Where the stage keeps the house's previous `vendor/` after a swap — null before one. */
    private static function previousIn(BootCandidate $stage): ?string
    {
        return is_dir($stage->path . '/vendor.previous') ? $stage->path . '/vendor.previous' : null;
    }

    /**
     * Exchange two directories in one step (renameat2 RENAME_EXCHANGE, through GNU `mv --exchange`) — false when the
     * system cannot, and then nothing moved. Off with MILPA_WITNESS_NO_EXCHANGE, the two-rename arm evidence/1061 measures.
     */
    private static function exchange(string $a, string $b): bool
    {
        if (getenv('MILPA_WITNESS_NO_EXCHANGE') !== false || !\function_exists('exec')) {
            return false;
        }
        $out = [];
        $code = 1;
        @exec('mv --exchange --no-target-directory ' . ShellArgument::quote($a) . ' ' . ShellArgument::quote($b) . ' 2>&1', $out, $code);

        return $code === 0;
    }

    /**
     * The `vendor/` that booted before goes back, and the two files get the bytes they held.
     *
     * @param array<string, ?string> $before
     */
    private function putBack(BootCandidate $stage, array $before): void
    {
        $previous = self::previousIn($stage);
        if ($previous !== null && !self::exchange($previous, $this->root . '/vendor')) {
            rename($this->root . '/vendor', $stage->path . '/vendor.rejected');
            rename($previous, $this->root . '/vendor');
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
