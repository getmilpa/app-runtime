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
 * was given over — the ones it covers, the ones that measure its conditions, the one that watches its falsifier
 * (greenhouse decisions/0615, «Lo que la segunda revisión encontró», 4).
 *
 * ── WHY THE CONTRACT'S DIGEST IS NOT ENOUGH HERE ────────────────────────────────────────────────
 *
 * `Operation::instrumentDigest()` reads the declaration and the lines of the handler. The handler of nearly every
 * operation is ONE line that calls a method of its class, and that line does not change when the method does:
 * measured, two providers whose methods did different things had the same digest, and the second ran under a grant
 * given over the first. So the house adds what it can read without guessing: the bytes of the file the handler is
 * written in, and of the files of the class it belongs to — its parents and its traits with it.
 *
 * ── WHAT THIS STILL DOES NOT SEE ────────────────────────────────────────────────────────────────
 *
 * Code in ANOTHER class that the handler reaches — a service, a helper, a package it calls. And it is strict in the
 * other direction: any change to one of those files, even to a comment, is «not the operation it was», for every
 * operation the file defines. That is the side this errs on: a grant that stops being honoured is given again by a
 * person; one that goes on being honoured over other code is nobody's decision.
 */
final class OperationPin
{
    /** @var array<string, string> a file's digest by its path, modification time and size: read once per process */
    private static array $read = [];

    /** The digest that pins this operation, or `null` when its handler or the code around it cannot be read. */
    public static function of(Operation $operation): ?string
    {
        $handler = $operation->handlerDigest();
        $code = \is_callable($operation->handler) ? self::code(\Closure::fromCallable($operation->handler)) : null;
        if ($handler === null || $code === null) {
            return null;
        }
        $effects = $operation->effectCeiling();
        $scopes = $operation->scopes;
        sort($scopes);
        // What it declares, read here as the contract reads it — because the contract reads it only for an operation
        // that says what it acts on, and a watcher has to be pinned and need not act on anything.
        $declared = [
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
        ];

        return 'sha256:' . hash('sha256', Canonical::json([
            'contract' => $operation->instrumentDigest(),
            'declared' => $declared,
            'code' => $code,
        ]));
    }

    /**
     * The digests of the files this handler's own code is written in — never their paths: the same house moved to
     * another folder holds the same operations.
     *
     * @return list<string>|null `null` when one of them cannot be read
     */
    private static function code(\Closure $handler): ?array
    {
        $function = new \ReflectionFunction($handler);
        $files = [];
        $file = $function->getFileName();
        if (\is_string($file)) {
            $files[$file] = true;
        }
        $bound = $function->getClosureThis();
        foreach ([$function->getClosureScopeClass(), $bound === null ? null : new \ReflectionObject($bound)] as $class) {
            self::filesOf($class, $files);
        }
        $digests = [];
        foreach (array_keys($files) as $path) {
            $digest = self::digestOf($path);
            if ($digest === null) {
                return null;
            }
            $digests[] = $digest;
        }
        sort($digests);

        return $digests;
    }

    /**
     * @param \ReflectionClass<object>|null $class
     * @param array<string, true>           $files
     */
    private static function filesOf(?\ReflectionClass $class, array &$files): void
    {
        for (; $class !== null; $class = $class->getParentClass() ?: null) {
            $file = $class->getFileName();
            if (\is_string($file)) {
                $files[$file] = true;
            }
            foreach ($class->getTraits() as $trait) {
                self::filesOf($trait, $files);
            }
        }
    }

    private static function digestOf(string $path): ?string
    {
        clearstatcache(true, $path);
        $key = $path . '|' . (string) @filemtime($path) . '|' . (string) @filesize($path);
        if (!isset(self::$read[$key])) {
            $digest = @hash_file('sha256', $path);
            if ($digest === false) {
                return null;
            }
            self::$read[$key] = $digest;
        }

        return self::$read[$key];
    }
}
