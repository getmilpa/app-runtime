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

use Milpa\AppRuntime\Config\StreamingSecretRedaction;
use PHPUnit\Framework\TestCase;

/**
 * THE SAME REDACTION, OVER A STREAM IN PIECES — A VALUE SPLIT BETWEEN TWO CHUNKS IS STILL MASKED
 * (greenhouse decisions/0608, measured in evidence/1170 and 1174).
 *
 * The model's thinking reaches a surface a batch at a time, and a secret can be split across two of them.
 * This proves the stream redactor catches the value cut at EVERY position, lets a non-secret through whole,
 * and — stated as the known limit, not promised closed — still passes a transformed value (1170).
 */
final class StreamingSecretRedactionTest extends TestCase
{
    private const CANARY = 'canary-stream-9f3a7c2e1b8d4056';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-stream-redaction-' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/.milpa', 0o700, true);
        file_put_contents($this->root . '/.milpa/secrets.json', json_encode(['agent' => ['apiKey' => self::CANARY]]));
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.milpa/secrets.json');
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    public function testAValueSplitBetweenTwoChunksAtEveryPositionIsRedacted(): void
    {
        $canary = self::CANARY;
        for ($cut = 1; $cut < \strlen($canary); $cut++) {
            $redactor = new StreamingSecretRedaction($this->root);
            $out = $redactor->push('thinking… ' . substr($canary, 0, $cut));
            $out .= $redactor->push(substr($canary, $cut) . ' …done');
            $out .= $redactor->flush();

            self::assertStringNotContainsString($canary, $out, "the value cut at {$cut} leaked across the chunk boundary");
            self::assertStringContainsString('[secret]', $out, "the value cut at {$cut} was not masked");
            self::assertStringContainsString('thinking…', $out, 'the non-secret text around it survives');
            self::assertStringContainsString('…done', $out);
        }
    }

    public function testAValueWholeInsideOneChunkIsRedacted(): void
    {
        $redactor = new StreamingSecretRedaction($this->root);
        $out = $redactor->push('here it is: ' . self::CANARY . ' and more') . $redactor->flush();

        self::assertStringNotContainsString(self::CANARY, $out);
        self::assertStringContainsString('[secret]', $out);
    }

    public function testAValueSpreadOverManyTinyChunksIsRedacted(): void
    {
        $redactor = new StreamingSecretRedaction($this->root);
        $out = '';
        foreach (str_split('x ' . self::CANARY . ' y', 1) as $char) {
            $out .= $redactor->push($char);
        }
        $out .= $redactor->flush();

        self::assertStringNotContainsString(self::CANARY, $out);
        self::assertStringContainsString('[secret]', $out);
    }

    public function testANonSecretStreamPassesThroughWhole(): void
    {
        $redactor = new StreamingSecretRedaction($this->root);
        $text = 'the house is founded and two capabilities are on';
        $out = '';
        foreach (str_split($text, 7) as $piece) {
            $out .= $redactor->push($piece);
        }
        $out .= $redactor->flush();

        self::assertSame($text, $out, 'a stream with no secret in it is let through unchanged');
    }

    public function testATransformedValueStillPassesKnownLimitNotBoundary(): void
    {
        // evidence/1170: exact-value redaction is the last line, not the boundary. A transform of the value
        // (here base64) is a different string and is NOT caught — stated, never promised closed.
        $redactor = new StreamingSecretRedaction($this->root);
        $b64 = base64_encode(self::CANARY);
        $out = $redactor->push('encoded: ' . substr($b64, 0, 5)) . $redactor->push(substr($b64, 5)) . $redactor->flush();

        self::assertStringContainsString($b64, $out, 'a transformed value still passes — the documented limit of exact-value redaction');
    }

    public function testNoSecretsMeansEveryPushPassesStraightThrough(): void
    {
        $empty = sys_get_temp_dir() . '/milpa-stream-none-' . bin2hex(random_bytes(4));
        $redactor = new StreamingSecretRedaction($empty);
        self::assertSame('abcdefgh', $redactor->push('abcdefgh'), 'with no secrets the hold-back is zero');
        self::assertSame('', $redactor->flush());
    }
}
