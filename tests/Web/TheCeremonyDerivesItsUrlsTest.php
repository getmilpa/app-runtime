<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Live\Support\DesignTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 THE CEREMONY DERIVES ITS DESIGN-SYSTEM URLS; IT DOES NOT TYPE THEM.
 *
 * `decisions/0243` already stopped the tokens being COPIED — three packages carried identical copies
 * that had drifted, all three missing `--space-32`, and now the file ships in `milpa/live-web`. What
 * it did not stop was the typing: eight sites in this package spelled the names into route
 * declarations, `<link>` tags and an `<img>`, and `DesignTokens::defaultUrls()` — written for exactly
 * that — was called by nothing in any `src/` (greenhouse decisions/0308).
 *
 * The prefix stays this plugin's. Each host serving these itself is a decision written where it is
 * made ({@see DesignTokens::iconLink()}: «each serves this file from its own asset route, with its own
 * cache policy»), because a plugin whose pages work the moment it is installed cannot depend on
 * another plugin's routes being mounted. Only the typing moved.
 */
#[CoversClass(PasskeyPlugin::class)]
final class TheCeremonyDerivesItsUrlsTest extends TestCase
{
    /**
     * 🚨 NO STRING IN `src/` SPELLS A DESIGN-SYSTEM URL.
     *
     * A guard rather than an example: this was eight sites at once, so the ninth would arrive
     * somewhere nobody was looking — which is exactly how a fourth prefix got invented in one
     * afternoon while three already existed.
     *
     * The prefix constant itself is the one exemption, because something has to say where this plugin
     * mounts them, and it says it once.
     */
    public function testNoStringInTheSourceSpellsADesignSystemUrl(): void
    {
        $offenders = [];

        foreach (self::phpFiles(\dirname(__DIR__, 2) . '/src') as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                $trimmed = ltrim($line);
                // Comments and docblocks name these URLs to explain them; that is prose, not a link.
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                // The prefix constant is where the mount point is declared, once.
                if (str_contains($line, 'DESIGN_PREFIX = ')) {
                    continue;
                }
                // 🚨 `BoardPage` IS NOT THIS DUPLICATION, and the guard caught it before I could
                // pretend otherwise. It links the npm distribution — `@milpa/design@0.9.0` on a
                // versioned CDN, whose layout is `dist/`, `primitives/`, `components/`, `motion/`,
                // `layouts/`, `artifacts/` — and `DesignTokens::urls()` cannot produce those: it knows
                // only the five files `resources/design/` ships. Measured: the CDN's
                // `dist/milpa-tokens.css` and this package's copy are BYTE-IDENTICAL (9892 bytes, 236
                // tokens), so the two channels agree today, but they are two channels and not one
                // typing mistake. Bringing them under one authority is its own slice.
                if (str_contains($line, '{$assetBase}')) {
                    continue;
                }
                if (preg_match('#[\'"][^\'"]*milpa-(tokens|fonts|wordmark|wordmark-light|app-icon)\.(css|svg)#', $line) === 1) {
                    $offenders[] = basename($file) . ':' . ($n + 1) . '  ' . trim($line);
                }
            }
        }

        self::assertSame([], $offenders, "derive these from DesignTokens::urls() instead:\n" . implode("\n", $offenders));
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $root): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
