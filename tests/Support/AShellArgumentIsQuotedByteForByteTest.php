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

use Milpa\AppRuntime\Console\UnsignedTerminal;
use Milpa\AppRuntime\Support\ShellArgument;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A shell argument is quoted byte for byte, whatever the locale (greenhouse decisions/0624, evidence/1208).
 *
 * `escapeshellarg()` reads LC_CTYPE: on PHP 8.3 under the C locale it drops the bytes it cannot place, so
 * `escapeshellarg('sí')` is `'s'`. That turned the line a person is offered to sign into a call with ANOTHER answer,
 * and a path with an accent into another path — one of them under `rm -rf`. Every case here runs under LC_CTYPE=C,
 * the locale a PHP started by cron or systemd without LANG gets.
 */
final class AShellArgumentIsQuotedByteForByteTest extends TestCase
{
    private string|false $locale = false;

    protected function setUp(): void
    {
        $this->locale = setlocale(\LC_CTYPE, '0');
        setlocale(\LC_CTYPE, 'C');
    }

    protected function tearDown(): void
    {
        if ($this->locale !== false) {
            setlocale(\LC_CTYPE, $this->locale);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function values(): iterable
    {
        yield 'an accent' => ['sí'];
        yield 'a path with an ñ' => ['/var/lab/señal'];
        yield 'a single quote' => ["it's"];
        yield 'spaces and a dollar' => ['a $HOME b'];
        yield 'a backtick' => ['`id`'];
        yield 'a newline' => ["one\ntwo"];
        yield 'emoji' => ['🌽'];
        yield 'empty' => [''];
    }

    #[DataProvider('values')]
    public function testTheShellReceivesExactlyTheBytesThatWereQuoted(string $value): void
    {
        $said = shell_exec('printf %s ' . ShellArgument::quote($value));

        self::assertSame($value, (string) $said);
    }

    public function testANulByteIsRefusedAsEscapeshellargRefusesIt(): void
    {
        $this->expectException(\ValueError::class);

        ShellArgument::quote("a\0b");
    }

    public function testTheLineOfferedToSignCarriesTheAnswerThatWasGiven(): void
    {
        $op = new Operation('agent:answer', 'Answers.', static fn (): array => [], effects: EffectProfile::readOnly());

        $line = UnsignedTerminal::signedLine($op, ['session' => 'j.sub', 'answer' => 'sí']);

        self::assertStringContainsString(" --answer='sí' --sign", $line);
    }

    public function testNoSourceFileQuotesThroughTheLocale(): void
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'ShellArgument.php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                if (preg_match('/\bescapeshellarg\b/', $line) === 1) {
                    $found[] = substr($file->getPathname(), \strlen($src) + 1) . ':' . ($n + 1);
                }
            }
        }

        self::assertSame([], $found, 'quote through ShellArgument::quote(): escapeshellarg depends on the locale');
    }
}
