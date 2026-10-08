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

namespace Milpa\AppRuntime\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * 🚨 A CONTRACT IS WHAT THE MODEL READS OF A TOOL, and the contracts of this house are in English.
 *
 * Four descriptions were still in Spanish after the two of the sub-agent tools were translated: `plan` —
 * which every session receives — and its argument, one argument of `todo` and one of `token:revoke`. Nobody
 * had chosen that: they were written before the rule and no test read them.
 *
 * This is a GUARD, not a unit test: it reads every description in `src/`, because the next one is written in
 * a file this test has not heard of. A description is the value of a `description` key or named argument, or
 * the second argument of an operation built by position.
 */
final class EveryContractIsEnglishTest extends TestCase
{
    /**
     * Marks no English description carries: the letters of Spanish, and words English does not have. Words the
     * two languages share («sin», «con», «del») are left out on purpose: they would refuse English.
     */
    private const SPANISH = '/[áéíóúñ¿¡]|\b(el|los|las|una|uno|para|que|por|esta|este|sus?|ya|sesi[oó]n|herramientas?|hazlo|antes|pasos|p\. ej)\b/iu';

    public function testNoDescriptionInTheSourceIsReadInSpanish(): void
    {
        $spanish = [];
        $read = 0;
        foreach (self::descriptions() as $where => $text) {
            ++$read;
            if (preg_match(self::SPANISH, $text) === 1) {
                $spanish[] = "{$where}: {$text}";
            }
        }

        self::assertSame([], $spanish, 'the model reads Spanish here');
        self::assertGreaterThan(300, $read, 'the control: the descriptions of this package were read');
    }

    /** The control of the mark: it tells the sentences these contracts began with, and lets their English pass. */
    public function testTheMarkTellsSpanishFromEnglish(): void
    {
        foreach (['El plan, en pasos', 'Escribe o reemplaza el plan de trabajo', 'Hazlo ANTES de empezar algo largo', 'El id que `token:list` muestra', 'Útil'] as $spanish) {
            self::assertMatchesRegularExpression(self::SPANISH, $spanish);
        }
        foreach (['The plan, in steps', 'Write or replace the work plan of this session', 'The id that `token:list` shows', 'A request with no body, a pro and a con'] as $english) {
            self::assertDoesNotMatchRegularExpression(self::SPANISH, $english);
        }
    }

    /** The reader finds a description wherever one is written: by key, by name, and by position. */
    public function testItReadsADescriptionHoweverItIsWritten(): void
    {
        $found = array_values(iterator_to_array(self::read(<<<'PHP'
            <?php
            new Operation(name: 'a', description: 'by ' . 'name');
            new Operation('b', 'by position', fn () => []);
            $schema = ['x' => ['type' => 'string', 'description' => 'by key']];
            /** 'description' => 'in a comment' */
            PHP)));

        self::assertSame(['by name', 'by position', 'by key'], $found);
    }

    /** @return iterable<string, string> every description of `src/`, keyed by where it is */
    private static function descriptions(): iterable
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::read((string) file_get_contents($file->getPathname())) as $line => $text) {
                yield substr($file->getPathname(), \strlen($src) + 1) . ':' . $line => $text;
            }
        }
    }

    /**
     * The descriptions written in one source, keyed by `line#token`: a chain of literals joined with "." is one
     * text. Comments and docblocks are not read: a model is not handed those.
     *
     * @return iterable<string, string>
     */
    private static function read(string $source): iterable
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (mixed $t): bool => !(\is_array($t) && \in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)),
        ));
        $literal = static fn (mixed $t): bool => \is_array($t) && $t[0] === \T_CONSTANT_ENCAPSED_STRING;
        $unquoted = static fn (array $t): string => str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], substr($t[1], 1, -1));

        foreach ($tokens as $i => $token) {
            if (!$literal($token)) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $key = $tokens[$i - 2] ?? null;
            $byKey = \is_array($before) && $before[0] === \T_DOUBLE_ARROW && $literal($key) && $unquoted($key) === 'description';
            $byName = $before === ':' && \is_array($key) && $key[0] === \T_STRING && $key[1] === 'description';
            $byPosition = $before === ',' && $literal($key) && ($tokens[$i - 3] ?? null) === '('
                && \is_array($tokens[$i - 4] ?? null) && $tokens[$i - 4][1] === 'Operation'
                && \is_array($tokens[$i - 5] ?? null) && $tokens[$i - 5][0] === \T_NEW;
            if (!$byKey && !$byName && !$byPosition) {
                continue;
            }

            $text = $unquoted($token);
            for ($j = $i + 1; ($tokens[$j] ?? null) === '.' && $literal($tokens[$j + 1] ?? null); $j += 2) {
                $text .= $unquoted($tokens[$j + 1]);
            }

            yield "{$token[2]}#{$i}" => $text;
        }
    }
}
