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

use Milpa\AppRuntime\Identity\IdentityKey;
use Milpa\AppRuntime\Web\LocalPath;
use PHPUnit\Framework\TestCase;

/**
 * A pattern that judges a WHOLE value ends at the end (greenhouse decisions/0624, point 3).
 *
 * In PCRE `$` (and `\Z`) also matches just before a final newline, so `/^[a-z]+$/` says yes to "name\n". A pattern
 * that starts at the beginning decides what a whole value may be — a sign-in redirect, a reading's name, a host — and
 * that one byte past the check is how the line offered to sign was split in two. Such a pattern ends with `\z`, or
 * says `\n?\z` where accepting a final newline is the deliberate answer, or carries `D`, or `m` when it really reads
 * lines. A search that does not start at the beginning is not this test's business.
 *
 * It reads EVERY string literal in `src/` that compiles as a pattern — inside a call, in a constant, as a named
 * argument, single- or double-quoted — because the first version read only `preg_*('…')` and six patterns held in
 * constants, the sign-in redirect among them, stayed out of its sight.
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
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $value = (string) eval('return ' . $token[1] . ';');
                if (self::isPattern($value) && self::admitsAFinalNewline($value)) {
                    $found[] = substr($file->getPathname(), \strlen($src) + 1) . ':' . $token[2] . '  ' . $token[1];
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
        foreach (['/^[a-z]+$/', '~^/(?:a|$)~i', '#^x$#u', '/\A[a-z]+$/', '/^[a-z]+\Z/', '/(?i)^[a-z]+$/', '/(?:^[a-z]+)$/', '/(^[a-z]+$)/'] as $bad) {
            self::assertTrue(self::admitsAFinalNewline($bad), $bad);
        }
        foreach (['/\A[a-z]+\z/', '/^[a-z]+$/D', '/^key: (.*)$/m', '/^\$[a-z]+\z/', '/(Fatal error):\s*(.+)$/', '/^[$]x\z/', '/\A\d+\n?\z/'] as $good) {
            self::assertFalse(self::admitsAFinalNewline($good), $good);
        }
        self::assertSame(1, preg_match('/^[a-z]+$/', "name\n"), 'the defect itself: PCRE says yes to a final newline');
        self::assertSame(0, preg_match('/\A[a-z]+\z/', "name\n"));
    }

    /** The sign-in redirect: a path with a final newline is not a local path, so the page goes home instead. */
    public function testTheSignInRedirectRefusesAPathWithAFinalNewline(): void
    {
        self::assertSame('/account', LocalPath::orRoot('/account'));
        self::assertSame('/', LocalPath::orRoot("/account\n"));
    }

    /**
     * The one place where a stricter end would WIDEN authority: a seat that stopped being a fingerprint would stop
     * having its ledger closures applied. A fingerprint with a final newline stays a fingerprint, as it always was.
     */
    public function testAFingerprintWithAFinalNewlineIsStillAFingerprint(): void
    {
        $fp = str_repeat('AB12', 10);

        self::assertTrue(IdentityKey::isFingerprint($fp));
        self::assertTrue(IdentityKey::isFingerprint($fp . "\n"));
        self::assertFalse(IdentityKey::isFingerprint($fp . "\n\n"));
    }

    /** A literal that compiles as a delimited PCRE pattern. */
    private static function isPattern(string $value): bool
    {
        if (\strlen($value) < 3 || !\in_array($value[0], ['/', '~', '#', '@', '%', '!', '{', '('], true)) {
            return false;
        }

        return @preg_match($value, '') !== false;
    }

    /**
     * Whether a pattern starts at the beginning (`^` or `\A`, after any opening groups and inline flags) and uses an
     * unescaped `$` (outside a class) or `\Z` as an end, without `m` or `D`.
     */
    private static function admitsAFinalNewline(string $pattern): bool
    {
        $delimiter = $pattern[0];
        $close = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'][$delimiter] ?? $delimiter;
        $end = strrpos($pattern, $close);
        if ($end === false || $end === 0) {
            return false;
        }
        $regex = substr($pattern, 1, $end - 1);
        $flags = substr($pattern, $end + 1);
        if (str_contains($flags, 'm') || str_contains($flags, 'D')) {
            return false;
        }
        $head = (string) preg_replace('/\A(?:\(\?[a-zA-Z]*[:)]|\()*/', '', $regex);
        if (!str_starts_with($head, '^') && !str_starts_with($head, '\A')) {
            return false;
        }
        $inClass = false;
        for ($i = 0, $n = \strlen($regex); $i < $n; ++$i) {
            $c = $regex[$i];
            if ($c === '\\') {
                if (!$inClass && ($regex[$i + 1] ?? '') === 'Z') {
                    return true;
                }
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
