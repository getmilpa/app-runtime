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

    /**
     * What was read while SEVERAL operations are pinned at one moment ({@see self::ofEach()}): each file, each
     * plugin's directory and the list of what was installed, once. `null` outside such a moment — where every
     * reading is made again, as it always was.
     *
     * @var array{files: array<string, ?string>, directories: array<string, ?string>, installed: array<string, list<array{name: string, at: string, state: ?string}>>}|null
     */
    private static ?array $once = null;

    /**
     * The digest that pins this operation in the house at this root, or `null` when it cannot be pinned
     * ({@see self::whyNot()} says why).
     */
    public static function of(Operation $operation, string $root): ?string
    {
        $read = self::read($operation, $root);

        return \is_string($read) ? null : 'sha256:' . hash('sha256', Canonical::json($read));
    }

    /**
     * The pin of each of these operations, read AT ONE MOMENT: a file many of them are written in is read once, and
     * so is the list of what this house installed. It is what a process does when its catalogue is told — a house
     * of seventy operations cost seventy readings of that list.
     *
     * @param list<Operation> $operations
     *
     * @return list<?string> in the order of the operations
     */
    public static function ofEach(array $operations, string $root): array
    {
        self::$once = ['files' => [], 'directories' => [], 'installed' => []];
        try {
            return array_map(static fn (Operation $operation): ?string => self::of($operation, $root), $operations);
        } finally {
            self::$once = null;
        }
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
     * @return array<string, mixed>|string
     */
    private static function read(Operation $operation, string $root): array|string
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
            $unit = self::unitOf($real, $class, rtrim($root, '/'));
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
     * The unit a file of this house belongs to, and what that unit is today — never its path: the same house moved
     * to another folder holds the same operations. Or the sentence that says no unit could be told.
     *
     * @param string $real the file, by its real path
     *
     * @return array{name: string, state: string}|string
     */
    private static function unitOf(string $real, ?string $class, string $root): array|string
    {
        // A PACKAGE, when the house installed one there: the most specific one, by where Composer says it put it.
        $package = null;
        foreach (self::installedIn($root) as $known) {
            if (str_starts_with($real, $known['at'] . '/') && ($package === null || \strlen($known['at']) > \strlen($package['at']))) {
                $package = $known;
            }
        }
        if ($package !== null) {
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
                $state = self::directory($directory);

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
    private static function directory(string $directory): ?string
    {
        if (self::$once !== null && \array_key_exists($directory, self::$once['directories'])) {
            return self::$once['directories'][$directory];
        }
        $state = self::walked($directory);
        if (self::$once !== null) {
            self::$once['directories'][$directory] = $state;
        }

        return $state;
    }

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
        $file = $root . '/vendor/composer/installed.json';
        if (isset(self::$once['installed'][$file])) {
            return self::$once['installed'][$file];
        }
        $packages = self::installedBy($file);
        if (self::$once !== null) {
            self::$once['installed'][$file] = $packages;
        }

        return $packages;
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
        if (self::$once !== null && \array_key_exists($path, self::$once['files'])) {
            return self::$once['files'][$path];
        }
        $digest = is_file($path) ? @hash_file('sha256', $path) : false;
        $digest = $digest === false ? null : $digest;
        if (self::$once !== null) {
            self::$once['files'][$path] = $digest;
        }

        return $digest;
    }
}
