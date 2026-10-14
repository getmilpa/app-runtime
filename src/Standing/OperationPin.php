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

namespace Milpa\AppRuntime\Standing;

use Milpa\Command\Consent\OperationId;
use Milpa\Command\Operation;
use Milpa\Command\Standing\Canonical;

/**
 * WHAT AN OPERATION IS, as far as this house can read it: the digest a standing grant keeps of every operation it
 * was given over — the ones it covers, the ones that measure its conditions, the one that watches its falsifier —
 * and that every receipt and every sign of life carries, of the operation that produced it (greenhouse
 * decisions/0615, «Lo que la segunda revisión encontró», 4, and «Lo que la tercera revisión encontró», M1).
 *
 * ── WHY THE CONTRACT'S DIGEST IS NOT ENOUGH HERE ────────────────────────────────────────────────
 *
 * `Operation::instrumentDigest()` reads the declaration and the lines of the handler. The handler of nearly every
 * operation is ONE line that calls a method of its class, and that line does not change when the method does:
 * measured, two providers whose methods did different things had the same digest, and the second ran under a grant
 * given over the first.
 *
 * ── WHAT «IT CHANGED» MEANS: THE UNIT IT SHIPS IN (decided by Rod, 2026-10-12) ──────────────────
 *
 * An operation lives in a UNIT, and it is another operation when its unit is another:
 *
 *   · a PACKAGE this house installed — what `vendor/composer/installed.json` says of it: its version and the
 *     reference it was installed at;
 *   · a PLUGIN — every PHP file under its directory, which is where a class named `…\Plugins\<Name>\…` is written.
 *
 * The units are those of the files the handler's own code is written in: the file the handler is in, the class it
 * belongs to, its parents and its traits. And the bytes of those very files are read as well, as before: what the
 * unit says and what those files hold both have to be what they were.
 *
 * AN OPERATION WHOSE UNIT CANNOT BE TOLD IS NOT PINNED, and nothing is granted over it: code that belongs to no
 * package this house installed and to no plugin is code the house could not tell had changed.
 *
 * ── WHAT THIS STILL DOES NOT SEE ────────────────────────────────────────────────────────────────
 *
 * Code in ANOTHER unit that the handler reaches — a service of another package. A package edited in place under
 * `vendor/`, outside the files of the handler's own class: its reference says what was installed, not what is
 * there. And it is strict in the other direction: any PHP file of a plugin that changes, even in a comment, is
 * «not the operation it was» for every operation that plugin declares, and so is every update of a package. That
 * is the side this errs on: a grant that stops being honoured is given again by a person; one that goes on being
 * honoured over other code is nobody's decision. It is not the whole tree of the house.
 */
final class OperationPin
{
    /**
     * What a house installed, as its file was last read — kept by the DIGEST of that file's bytes, never by its time
     * and size: a file written again within the same second, to the same length, is another file, and a process that
     * lives long would have gone on reading the one before it.
     *
     * @var array<string, array{digest: string, packages: list<array{name: string, at: string, state: ?string}>}>
     */
    private static array $installed = [];

    /** The second this process started, once it has been asked ({@see self::processStarted()}). */
    private static ?int $started = null;

    /**
     * The digest that pins this operation in the house at this root, or `null` when it cannot be pinned
     * ({@see self::whyNot()} says why).
     */
    public static function of(Operation $operation, string $root): ?string
    {
        $read = self::read($operation, $root);

        return \is_string($read) ? null : 'sha256:' . hash('sha256', Canonical::json($read));
    }

    /** Why this operation cannot be pinned in the house at this root — or `null`: it can. */
    public static function whyNot(Operation $operation, string $root): ?string
    {
        $read = self::read($operation, $root);

        return \is_string($read) ? $read : null;
    }

    /**
     * Everything the pin is the digest of — or the sentence that says what could not be read.
     *
     * @param list<string> $dated filled with what was read to say it: each file, each plugin's directory, and what
     *                            the house wrote down that it installed
     *
     * @return array<string, mixed>|string
     */
    private static function read(Operation $operation, string $root, array &$dated = []): array|string
    {
        $handler = $operation->handlerDigest();
        $function = \is_callable($operation->handler) ? new \ReflectionFunction(\Closure::fromCallable($operation->handler)) : null;
        $file = $function?->getFileName();
        if ($handler === null || $function === null || !\is_string($file)) {
            return 'its handler cannot be read: it is not written in a file';
        }
        $scope = $function->getClosureScopeClass();
        $bound = $function->getClosureThis();
        /** @var array<string, ?string> $files each file the handler's own code is written in => the class written there */
        $files = [$file => null];
        foreach ([$scope, $bound === null ? null : new \ReflectionObject($bound)] as $class) {
            self::filesOf($class, $files);
        }
        $code = [];
        $units = [];
        foreach ($files as $path => $class) {
            $real = realpath($path);
            $digest = $real === false ? null : self::digestOf($real);
            if ($real === false || $digest === null) {
                return 'a file its code is written in cannot be read';
            }
            $code[] = $digest;
            $dated[] = $real;
            $unit = self::unitOf($real, $class, rtrim($root, '/'), $dated);
            if (\is_string($unit)) {
                return $unit;
            }
            $units[$unit['name']] = $unit['state'];
        }
        sort($code);
        ksort($units);
        $effects = $operation->effectCeiling();
        $scopes = $operation->scopes;
        sort($scopes);

        return [
            'contract' => $operation->instrumentDigest(),
            // What it declares, read here as the contract reads it — because the contract reads it only for an
            // operation that says what it acts on, and a watcher has to be pinned and need not act on anything.
            'declared' => [
                'effects' => [
                    'authority' => $effects->authority->value,
                    'externality' => $effects->externality->value,
                    'mutation' => $effects->mutation->value,
                    'reversibility' => $effects->reversibility->value,
                    'subject' => $effects->subject->value,
                ],
                'handler' => $handler,
                'inputs' => $operation->inputSchema,
                'mutating' => $operation->mutating,
                'operation' => (new OperationId($operation->name))->canonical,
                'permission' => $operation->permission,
                'scopes' => $scopes,
            ],
            'code' => $code,
            'units' => $units,
        ];
    }

    /**
     * WHAT A PROCESS RUNS IS WHAT IT LOADED, AND IT LOADED IT AFTER IT STARTED (decided by Rod, 2026-10-13: D19).
     *
     * A pin is read from the disk, and a process answers with the code it loaded: a guard loaded before a person
     * hardened it said yes under the pin of the hardened one, and a file softened, loaded and put back was run soft
     * and pinned hard. So ONE rule, in place of reading every pin when a process is told its catalogue and loading
     * every class of a plugin before that: a process speaks for an operation — writes its yes, its sign of calm, runs
     * it under a grant — only when NOTHING THE PIN READS IS DATED AT OR AFTER THE SECOND THAT PROCESS STARTED. Then
     * whatever it loaded, whenever it loaded it, is what the disk holds now. Whoever updates an instrument starts
     * again what was running.
     *
     * WHAT IS DATED is what the pin reads, and where it is: each file the handler's code is written in and the
     * directory it is in; for a plugin, every directory under it and every PHP file in them; for a package, what the
     * house wrote down that it installed. THE DATE of each is the later of when its bytes were written and when
     * anything about it was last changed — a file copied or moved into place with the date it had is dated when it
     * arrived, and so is one whose date was set back. Only whoever moves the clock of the machine gets past that:
     * its operator (D17).
     *
     * IT ERRS NARROW, three ways. A file touched and not changed is dated when it was touched. A date is a whole
     * second, so a file written in the very second a process started is one it cannot speak for: run a second
     * later, it does. And a directory is dated when a file is added to it or taken from it, whatever that file is.
     *
     * WHAT IT DOES NOT SEE: a process that does not read its code from the disk when it loads it — PHP's opcache
     * serving a file it compiled before the file changed. Nothing here asks opcache what it holds.
     *
     * @return string|null what was found dated at or after the second this process started — its path from the
     *                     house's root when it is under it, its name otherwise — or `null`: nothing was, or the
     *                     operation cannot be pinned and nothing is written under its pin anyway
     */
    public static function changedSinceThisProcessStarted(Operation $operation, string $root): ?string
    {
        $read = [];
        if (\is_string(self::read($operation, $root, $read))) {
            return null;
        }
        $dated = [];
        foreach (array_unique($read) as $path) {
            $dated[$path] = true;
            if (!is_dir($path)) {
                $dated[\dirname($path)] = true;
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $found) {
                if ($found instanceof \SplFileInfo && ($found->isDir() || strtolower($found->getExtension()) === 'php')) {
                    $dated[$found->getPathname()] = true;
                }
            }
        }
        // PHP keeps, per process, its last look at a file, and gives it again: a process that lives is given today's
        // date because this line says so — not because of the order in which the lines above happen to look at
        // things (the seventh review, 7-L7). Every date below is read after it.
        clearstatcache();
        $house = realpath($root);
        foreach (array_keys($dated) as $path) {
            $stat = @stat($path);
            if ($stat === false || max($stat['mtime'], $stat['ctime']) >= self::processStarted()) {
                return $house !== false && str_starts_with($path, $house . '/') ? substr($path, \strlen($house) + 1) : basename($path);
            }
        }

        return null;
    }

    /**
     * The second this process started — read the first time it is asked and kept, so that a process that answers
     * many requests keeps the one it started in, not the one of the request it is answering.
     */
    public static function processStarted(): int
    {
        return self::$started ??= (int) ($_SERVER['REQUEST_TIME_FLOAT'] ?? time());
    }

    /**
     * The unit a file of this house belongs to, and what that unit is today — never its path: the same house moved
     * to another folder holds the same operations. Or the sentence that says no unit could be told.
     *
     * @param string       $real  the file, by its real path
     * @param list<string> $dated filled with what says what that unit is: a plugin's directory, or what the house
     *                            wrote down that it installed
     *
     * @return array{name: string, state: string}|string
     */
    private static function unitOf(string $real, ?string $class, string $root, array &$dated = []): array|string
    {
        // A PACKAGE, when the house installed one there: the most specific one, by where Composer says it put it.
        $package = null;
        foreach (self::installedIn($root) as $known) {
            if (str_starts_with($real, $known['at'] . '/') && ($package === null || \strlen($known['at']) > \strlen($package['at']))) {
                $package = $known;
            }
        }
        if ($package !== null) {
            $dated[] = $root . '/vendor/composer/installed.json';

            return $package['state'] === null
                ? \sprintf('it ships in the package `%s`, which this house installed with no reference: the day that package changed could not be told', $package['name'])
                : ['name' => 'package:' . $package['name'], 'state' => $package['state']];
        }
        // A PLUGIN, by the house's own convention: the class is named `…\Plugins\<Name>\…`, and the directory of that
        // namespace — under this house, outside what it installed — is the plugin's.
        $house = realpath($root);
        if ($class !== null && $house !== false && preg_match('/(?:^|\\\\)Plugins\\\\([^\\\\]+)\\\\(.+)$/D', $class, $named) === 1) {
            $directory = \dirname($real, substr_count($named[2], '\\') + 1);
            if (str_starts_with($directory . '/', $house . '/') && $directory !== $house && !str_starts_with($directory . '/', $house . '/vendor/')) {
                $state = self::walked($directory);
                $dated[] = $directory;

                return $state === null
                    ? \sprintf('it ships in the plugin `%s`, and a PHP file of that plugin cannot be read', $named[1])
                    : ['name' => 'plugin:' . $named[1], 'state' => $state];
            }
        }

        return 'its code belongs to no unit this house can tell — neither a package it installed (`vendor/composer/installed.json`) nor a plugin (a class named `…\\Plugins\\<Name>\\…`, written under this house) — so the day it changed could not be told';
    }

    /**
     * What every PHP file under a plugin's directory holds, as one digest — by each file's place inside the
     * directory, never by where the directory is.
     */
    private static function walked(string $directory): ?string
    {
        $files = [];
        try {
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($walk as $found) {
                if ($found instanceof \SplFileInfo && $found->isFile() && strtolower($found->getExtension()) === 'php') {
                    $digest = self::digestOf($found->getPathname());
                    if ($digest === null) {
                        return null;
                    }
                    $files[substr($found->getPathname(), \strlen($directory) + 1)] = $digest;
                }
            }
        } catch (\UnexpectedValueException) {
            return null;
        }

        return 'sha256:' . hash('sha256', Canonical::json($files));
    }

    /**
     * The packages Composer says this house installed: each one's name, where it is, and what pins it — its
     * version and the reference it was installed at, or `null` when it records none.
     *
     * @return list<array{name: string, at: string, state: ?string}>
     */
    private static function installedIn(string $root): array
    {
        return self::installedBy($root . '/vendor/composer/installed.json');
    }

    /** @return list<array{name: string, at: string, state: ?string}> */
    private static function installedBy(string $file): array
    {
        $bytes = is_file($file) ? @file_get_contents($file) : false;
        if ($bytes === false) {
            return [];
        }
        $digest = hash('sha256', $bytes);
        if ((self::$installed[$file]['digest'] ?? null) === $digest) {
            return self::$installed[$file]['packages'];
        }
        $said = json_decode($bytes, true);
        $packages = [];
        foreach (\is_array($said) && \is_array($said['packages'] ?? null) ? $said['packages'] : [] as $package) {
            $name = \is_array($package) ? ($package['name'] ?? null) : null;
            $where = \is_array($package) ? ($package['install-path'] ?? null) : null;
            if (!\is_string($name) || !\is_string($where) || $where === '') {
                continue;
            }
            $at = realpath(str_starts_with($where, '/') ? $where : \dirname($file) . '/' . $where);
            if ($at === false) {
                continue;
            }
            $references = [];
            foreach (['source', 'dist'] as $from) {
                $reference = \is_array($package[$from] ?? null) ? ($package[$from]['reference'] ?? null) : null;
                if (\is_string($reference) && $reference !== '') {
                    $references[] = $from . ':' . $reference;
                }
            }
            $version = \is_string($package['version'] ?? null) ? $package['version'] : '';
            $packages[] = ['name' => $name, 'at' => $at, 'state' => $references === [] ? null : $version . '@' . implode('|', $references)];
        }

        self::$installed[$file] = ['digest' => $digest, 'packages' => $packages];

        return $packages;
    }

    /**
     * @param \ReflectionClass<object>|null $class
     * @param array<string, ?string>        $files
     */
    private static function filesOf(?\ReflectionClass $class, array &$files): void
    {
        for (; $class !== null; $class = $class->getParentClass() ?: null) {
            $file = $class->getFileName();
            if (\is_string($file)) {
                $files[$file] = $class->getName();
            }
            foreach ($class->getTraits() as $trait) {
                self::filesOf($trait, $files);
            }
        }
    }

    /**
     * What a file holds, read every time it is asked. Nothing is kept between two readings: its time and its size do
     * not change when it is written again within the same second to the same length, and a softened instrument is
     * exactly such a file.
     */
    private static function digestOf(string $path): ?string
    {
        $digest = is_file($path) ? @hash_file('sha256', $path) : false;

        return $digest === false ? null : $digest;
    }
}
