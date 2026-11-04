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

namespace Milpa\AppRuntime\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A pattern that judges a WHOLE value ends at the end (greenhouse decisions/0624, point 3).
 *
 * In PCRE `$` also matches just before a final newline, so `/^[a-z]+$/` says yes to "name\n". A pattern that starts
 * at `^` decides what a whole value may be — a recipe name that picks an executable file, a config key, a host — and
 * that one byte past the check is how the line offered to sign was split in two. Such a pattern ends with `\z` (or
 * carries `D`, or `m` when it really reads lines); a search that does not start at `^` is not this test's business.
 */
final class APatternThatJudgesAWholeValueEndsAtTheEndTest extends TestCase
{
    public function testNoPatternThatStartsAtTheBeginningEndsBeforeAFinalNewline(): void
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            preg_match_all("/preg_[a-z_]+\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $calls, \PREG_OFFSET_CAPTURE);
            foreach ($calls[1] as [$literal, $at]) {
                if (self::admitsAFinalNewline(str_replace(["\\'", '\\\\'], ["'", '\\'], $literal))) {
                    $found[] = substr($file->getPathname(), \strlen($src) + 1) . ':' . (substr_count($code, "\n", 0, $at) + 1) . '  ' . $literal;
                }
            }
        }

        self::assertSame([], $found, 'end a pattern that judges a whole value with \z: $ also matches before a final newline');
    }

    /**
     * The guard can fail: each planted pattern is one it must name, and each kept one it must leave.
     */
    public function testTheGuardNamesWhatItIsAbout(): void
    {
        self::assertTrue(self::admitsAFinalNewline('/^[a-z]+$/'));
        self::assertTrue(self::admitsAFinalNewline('~^/(?:a|$)~i'));
        self::assertTrue(self::admitsAFinalNewline('#^x$#u'));
        self::assertFalse(self::admitsAFinalNewline('/\A[a-z]+\z/'));
        self::assertFalse(self::admitsAFinalNewline('/^[a-z]+$/D'));
        self::assertFalse(self::admitsAFinalNewline('/^key: (.*)$/m'));
        self::assertFalse(self::admitsAFinalNewline('/^\$[a-z]+\z/'));
        self::assertFalse(self::admitsAFinalNewline('/(Fatal error):\s*(.+)$/'));
        self::assertFalse(self::admitsAFinalNewline('/^[$]x\z/'));
        self::assertSame(1, preg_match('/^[a-z]+$/', "name\n"), 'the defect itself: PCRE says yes to a final newline');
        self::assertSame(0, preg_match('/\A[a-z]+\z/', "name\n"));
    }

    /**
     * Whether a pattern starts at `^` and uses an unescaped `$` (outside a class) as an end, without `m` or `D`.
     */
    private static function admitsAFinalNewline(string $pattern): bool
    {
        $delimiter = $pattern[0] ?? '';
        $close = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'][$delimiter] ?? $delimiter;
        $end = strrpos($pattern, $close);
        if ($end === false || $end === 0) {
            return false;
        }
        $regex = substr($pattern, 1, $end - 1);
        $flags = substr($pattern, $end + 1);
        if (!str_starts_with($regex, '^') || str_contains($flags, 'm') || str_contains($flags, 'D')) {
            return false;
        }
        $inClass = false;
        for ($i = 0, $n = \strlen($regex); $i < $n; ++$i) {
            $c = $regex[$i];
            if ($c === '\\') {
                ++$i;

                continue;
            }
            if ($inClass) {
                $inClass = $c !== ']';

                continue;
            }
            if ($c === '[') {
                $inClass = true;
            } elseif ($c === '$' && \in_array($regex[$i + 1] ?? '', ['', ')', '|'], true)) {
                return true;
            }
        }

        return false;
    }
}
