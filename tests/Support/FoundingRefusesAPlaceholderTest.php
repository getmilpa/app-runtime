<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\Foundation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A house is not founded with the marker of a command somebody copied — greenhouse decisions/0566.
 *
 * Measured in Rod's second live run (greenhouse evidence/1100): the Desktop's boot screen printed
 * `foundation:found --domain="…" --objective="…" --sign`, he copied it as it stood, and the house
 * wrote a constitution whose domain and objective were both «…». A constitution is written once, so
 * the house had to be thrown away. The rite refused only the empty string; a marker is not empty.
 *
 * The contract these tests pin: a domain or an objective that says nothing — no letter and no digit,
 * a marker in brackets, or the field's own name — is refused before anything is written, in a
 * sentence that says what to put. A short real word is still a declaration.
 */
final class FoundingRefusesAPlaceholderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-founding-placeholder-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        // A recursive rm in a test teardown deletes exactly what setUp created, nothing else.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{string}> */
    public static function valuesThatSayNothing(): iterable
    {
        yield 'the ellipsis the Desktop printed' => ['…'];
        yield 'three dots' => ['...'];
        yield 'spaced dots' => ['. . .'];
        yield 'an ellipsis somebody quoted twice' => ['"…"'];
        yield 'a question mark' => ['?'];
        yield 'a dash' => ['-'];
        yield 'a marker in angle brackets' => ['<what this app is for>'];
        yield 'a marker in square brackets' => ['[fill me in]'];
        yield 'a marker in braces' => ['{{value}}'];
    }

    /** What Rod's command did: both fields «…». Nothing is written, and the sentence says what to put. */
    public function testTheCommandCopiedWithItsMarkersFoundsNothing(): void
    {
        $r = Foundation::found(['domain' => '…', 'objective' => '…'], $this->root);

        self::assertFalse($r['ok']);
        self::assertSame('domain', $r['field']);
        self::assertSame('placeholder', $r['reason']);
        self::assertStringContainsString('«…»', $r['error'], 'the refusal quotes what it was given');
        self::assertStringContainsString('what this app is for', $r['error'], 'the refusal says what to put');
        self::assertFileDoesNotExist($this->root . '/.milpa/foundation.json');
        self::assertDirectoryDoesNotExist($this->root . '/.milpa/decisions');
    }

    #[DataProvider('valuesThatSayNothing')]
    public function testADomainThatSaysNothingIsRefused(string $value): void
    {
        $r = Foundation::found(['domain' => $value, 'objective' => 'publish what the team writes'], $this->root);

        self::assertFalse($r['ok']);
        self::assertSame('domain', $r['field']);
        self::assertSame('placeholder', $r['reason']);
        self::assertFileDoesNotExist($this->root . '/.milpa/foundation.json');
    }

    #[DataProvider('valuesThatSayNothing')]
    public function testAnObjectiveThatSaysNothingIsRefused(string $value): void
    {
        $r = Foundation::found(['domain' => 'a blog for our team', 'objective' => $value], $this->root);

        self::assertFalse($r['ok']);
        self::assertSame('objective', $r['field']);
        self::assertSame('placeholder', $r['reason']);
        self::assertStringContainsString('what founding it is meant to achieve', $r['error'], 'the refusal says what to put');
        self::assertFileDoesNotExist($this->root . '/.milpa/foundation.json');
    }

    /** The field's own name is the other thing a template leaves behind: `--domain=DOMAIN`, `$objective`. */
    public function testTheFieldsOwnNameIsAMarker(): void
    {
        foreach (['domain', 'DOMAIN', '$domain', '<domain>', ' Domain '] as $value) {
            $r = Foundation::found(['domain' => $value, 'objective' => 'publish what the team writes'], $this->root);
            self::assertSame(['domain', 'placeholder'], [$r['field'] ?? null, $r['reason'] ?? null], $value);
        }
        foreach (['objective', '--objective', '[OBJECTIVE]'] as $value) {
            $r = Foundation::found(['domain' => 'a blog for our team', 'objective' => $value], $this->root);
            self::assertSame(['objective', 'placeholder'], [$r['field'] ?? null, $r['reason'] ?? null], $value);
        }
        self::assertFileDoesNotExist($this->root . '/.milpa/foundation.json');
    }

    /** One field's name in the OTHER field is a word like any other: an app may be about objectives. */
    public function testTheOtherFieldsNameIsADeclaration(): void
    {
        $r = Foundation::found(['domain' => 'objective', 'objective' => 'domain', 'dry_run' => true], $this->root);

        self::assertTrue($r['ok']);
    }

    /** An absent or blank field is refused as missing — and that sentence says what to put, too. */
    public function testAMissingFieldSaysWhatToPut(): void
    {
        $noDomain = Foundation::found(['objective' => 'publish what the team writes'], $this->root);
        self::assertFalse($noDomain['ok']);
        self::assertSame(['domain', 'missing'], [$noDomain['field'], $noDomain['reason']]);
        self::assertStringContainsString('what this app is for', $noDomain['error']);

        $blankObjective = Foundation::found(['domain' => 'a blog for our team', 'objective' => "  \t "], $this->root);
        self::assertFalse($blankObjective['ok']);
        self::assertSame(['objective', 'missing'], [$blankObjective['field'], $blankObjective['reason']]);
        self::assertStringContainsString('what founding it is meant to achieve', $blankObjective['error']);

        self::assertFileDoesNotExist($this->root . '/.milpa/foundation.json');
    }

    /**
     * AN EXAMPLE IS NOT THE EXAM (greenhouse decisions/0594 §5). The refusal says what to put in words: it
     * quotes the value it was given and nothing else. A sample of a domain IS a domain, read by whoever is
     * about to name theirs.
     */
    public function testTheRefusalQuotesWhatItWasGivenAndShowsNoSampleOfItsOwn(): void
    {
        $quoted = static function (array $refusal): array {
            preg_match_all('/«([^»]*)»/u', (string) $refusal['error'], $found);

            return $found[1];
        };

        $placeholder = Foundation::found(['domain' => '<what this app is for>', 'objective' => 'x'], $this->root);
        $missing = Foundation::found(['domain' => 'x'], $this->root);

        self::assertSame(['<what this app is for>'], $quoted($placeholder));
        self::assertSame([], $quoted($missing));
        foreach ([$placeholder, $missing] as $refusal) {
            self::assertStringNotContainsString('for example', (string) $refusal['error']);
            self::assertStringContainsString(' — ', (string) $refusal['error'], 'what to put, and how one reads');
            self::assertStringEndsWith('Nothing was written.', (string) $refusal['error']);
        }
    }

    /** A rehearsal is held to the same rule: a dry run that accepts «…» would teach that «…» founds. */
    public function testADryRunRefusesAPlaceholderToo(): void
    {
        $r = Foundation::found(['domain' => '…', 'objective' => '…', 'dry_run' => true], $this->root);

        self::assertFalse($r['ok']);
        self::assertArrayNotHasKey('would_write', $r);
    }

    /** @return iterable<string, array{string}> */
    public static function valuesThatSaySomething(): iterable
    {
        yield 'one letter' => ['x'];
        yield 'a slug' => ['travel-agency'];
        yield 'a sentence that trails off' => ['a blog…'];
        yield 'a word that is also a marker elsewhere' => ['todo'];
        yield 'a number' => ['42'];
        yield 'letters outside ASCII' => ['panadería'];
        yield 'no Latin letter at all' => ['ブログ'];
        yield 'a bracket that is part of the name' => ['[internal] tracker'];
        yield 'two bracketed words' => ['<a> and <b>'];
        yield 'the field name inside a real declaration' => ['a domain registrar'];
    }

    /** [negative control] The rule refuses markers, not brevity: these all found. */
    #[DataProvider('valuesThatSaySomething')]
    public function testAValueThatSaysSomethingFounds(string $value): void
    {
        $r = Foundation::found(['domain' => $value, 'objective' => $value], $this->root);

        self::assertTrue($r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($value, $r['foundation']['domain']);
        self::assertSame($value, $r['foundation']['objective']);
        self::assertFileExists($this->root . '/.milpa/foundation.json');
    }
}
