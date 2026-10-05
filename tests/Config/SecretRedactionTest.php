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

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\SecretRedaction;
use PHPUnit\Framework\TestCase;

/**
 * A SECRET DOES NOT REACH THE MODEL, AND THE HOUSE KNOWS WHAT A SECRET IS WITHOUT A LIST
 * (greenhouse decisions/0569, evidence/1103).
 *
 * The run that founded this slice sent `live.secret` to the model endpoint in 15 of 43 requests,
 * because `source_read config/app.php` handed the file over verbatim. A tool that gives the resident
 * files or configuration must redact the house's secrets first — and it must decide what is a secret
 * from what the house ACTUALLY keeps as one (every value in the secret overlay), never a hand-kept
 * list of key names that fails open the first time somebody forgets to add one.
 */
final class SecretRedactionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-secret-redaction-' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/.milpa', 0o700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.milpa/secrets.json');
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    /** @param array<string, mixed> $secrets */
    private function writeOverlay(array $secrets): void
    {
        file_put_contents($this->root . '/.milpa/secrets.json', json_encode($secrets));
    }

    public function testASecretValueIsRedactedWhereverItAppearsInAToolResult(): void
    {
        $this->writeOverlay(['live' => ['secret' => 'c0ffeebabe0000111122223333444455556666777788889999aaaabbbbccccdd']]);

        $result = [
            'ok' => true,
            'path' => 'config/app.php',
            'content' => "return [\n 'live' => ['secret' => 'c0ffeebabe0000111122223333444455556666777788889999aaaabbbbccccdd'],\n];",
        ];

        $redacted = SecretRedaction::inResult($result, $this->root);

        self::assertIsArray($redacted);
        self::assertStringNotContainsString('c0ffeebabe0000', $redacted['content'], 'the secret value must not survive');
        self::assertStringContainsString(SecretRedaction::REDACTED, $redacted['content']);
    }

    public function testTheRestOfTheFileIsPreserved(): void
    {
        $this->writeOverlay(['live' => ['secret' => 'c0ffeebabe0000111122223333444455556666777788889999aaaabbbbccccdd']]);

        $content = "return [\n 'name' => 'My House',\n 'live' => ['secret' => 'c0ffeebabe0000111122223333444455556666777788889999aaaabbbbccccdd', 'route' => '/live'],\n];";
        $redacted = SecretRedaction::inText($content, $this->root);

        self::assertStringContainsString("'name' => 'My House'", $redacted, 'the control: the resident still reads the rest');
        self::assertStringContainsString("'route' => '/live'", $redacted);
        self::assertStringNotContainsString('c0ffeebabe0000', $redacted);
    }

    public function testNoSecretsMeansNothingIsTouched(): void
    {
        // No overlay file at all — the ordinary case, most houses hold no secret.
        $content = "return ['name' => 'My House'];";
        self::assertSame($content, SecretRedaction::inText($content, $this->root));
    }

    public function testAShortOrEmptySecretDoesNotRedactEverything(): void
    {
        // A one- or two-character value is not treated as a secret — it would redact half the file.
        $this->writeOverlay(['x' => 'ab', 'y' => '']);
        $content = 'the quick brown fox ab cd';
        self::assertSame($content, SecretRedaction::inText($content, $this->root));
    }

    public function testFailsClosedWhenTheOverlayExistsButCannotBeRead(): void
    {
        // The overlay is present (so the house HAS secrets) but unparseable. We cannot know the values
        // to match, so we must not hand the content through — the anti-pattern the brief names is a
        // redactor that fails OPEN when it cannot tell. The whole content is withheld.
        file_put_contents($this->root . '/.milpa/secrets.json', '{ this is not json ');
        $out = SecretRedaction::inText("return ['secret' => 'whatever'];", $this->root);

        self::assertStringNotContainsString('whatever', $out);
        self::assertStringContainsString(SecretRedaction::REDACTED, $out);
    }

    public function testLongestSecretFirstSoOneContainingAnotherGoesWhole(): void
    {
        $this->writeOverlay(['a' => 'SECRETTOKEN', 'b' => 'SECRET']);
        $out = SecretRedaction::inText('value=SECRETTOKEN', $this->root);
        self::assertStringNotContainsString('SECRETTOKEN', $out);
        self::assertStringNotContainsString('SECRET', $out, 'the longer value must be masked whole, not leave TOKEN dangling');
    }
}
