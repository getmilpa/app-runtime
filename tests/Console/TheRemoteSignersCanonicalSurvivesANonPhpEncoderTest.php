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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\ToolRuntime\Identity\OperationAuthorization;
use PHPUnit\Framework\TestCase;

/**
 * WHAT SURVIVES A NON-PHP ENCODER (greenhouse decisions/0611, the cross-repo contract the coordinator asked to fix
 * before building the Desktop signer in JavaScript).
 *
 * The lab host signer was PHP; the Desktop's will be JavaScript. The house does not compare the signed bytes to the
 * host's bytes directly — {@see OperationAuthorizer} PARSES the signed canonical, REBUILDS one from the real call, and
 * compares the two RE-ENCODED by PHP ({@see OperationAuthorization::canonical()}, which sorts every map and emits
 * unescaped unicode and slashes). So what must survive the trip is the STRUCTURE after a JSON round-trip, not the
 * bytes. This pins, by execution, which encoder differences the comparison forgives and which it refuses — and
 * refusing is acceptable (it fails closed); what is not acceptable is not knowing before the JavaScript is written.
 *
 * `issuedAt` and `nonce` are the HOST's (its clock, its nonce): the house reads them from the parsed canonical and
 * rebuilds the rest around them, so they are equal on both sides and never the cause of a mismatch here.
 */
final class TheRemoteSignersCanonicalSurvivesANonPhpEncoderTest extends TestCase
{
    private const string OP = 'capabilities:enable';
    private const string HOST = 'labhouse';
    private const string ISSUED = '2026-10-10T12:00:00+00:00';
    private const string NONCE = 'a1b2c3d4';

    /**
     * @param array<string, mixed> $realArguments what the house is about to run (PHP values)
     * @param string               $hostArguments how a JavaScript host would have encoded `"arguments"` on the wire
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('encoderDifferences')]
    public function testTheComparisonForgivesWhatANonPhpEncoderChangesButNotATypeChange(string $_case, array $realArguments, string $hostArguments, bool $granted): void
    {
        // The bytes a JS host would sign: a canonical with those arguments. fromCanonical parses it exactly as the
        // house does on the verifying side.
        $wire = '{"arguments":' . $hostArguments . ',"host":"' . self::HOST . '","issuedAt":"' . self::ISSUED . '","nonce":"' . self::NONCE . '","operation":"' . self::OP . '"}';
        $parsed = OperationAuthorization::fromCanonical($wire);
        self::assertNotNull($parsed, 'the wire is well-formed JSON the house can parse');

        // What the house rebuilds from the real call, around the HOST's issuedAt and nonce.
        $expected = new OperationAuthorization(self::OP, $realArguments, self::HOST, $parsed->issuedAt, $parsed->nonce);

        self::assertSame(
            $granted,
            hash_equals($expected->canonical(), $parsed->canonical()),
            $granted ? 'this difference must be forgiven (the same call, spelled differently)' : 'this difference must be refused (fail closed)',
        );
    }

    /** @return iterable<string, array{string, array<string, mixed>, string, bool}> */
    public static function encoderDifferences(): iterable
    {
        // GRANTED — the map is re-sorted on both sides, so key order carries no meaning.
        yield 'key order' => ['key order', ['alpha' => 1, 'beta' => 2], '{"beta":2,"alpha":1}', true];

        // GRANTED — json_decode normalizes the escape; canonical() emits unescaped unicode on both sides.
        yield 'escaped unicode' => ['escaped unicode', ['name' => 'café'], '{"name":"café"}', true];

        // GRANTED — canonical() emits unescaped slashes on both sides.
        yield 'escaped slash' => ['escaped slash', ['path' => 'a/b'], '{"path":"a\/b"}', true];

        // GRANTED — an empty JS object and an empty PHP array both decode to [] and encode to [].
        yield 'empty object for empty array' => ['empty object for empty array', ['items' => []], '{"items":{}}', true];

        // GRANTED — a JS number 1.0 serializes as 1, and so does PHP: `json_encode(1.0)` is "1" under the default
        // serialize_precision (-1, stable on 8.3/8.4). So an integer-valued float round-trips as the same canonical.
        // CAVEAT: a deployment that sets serialize_precision to a fixed width would spell PHP's float "1.0" and then
        // refuse this (fail closed) — safe, but the JS side should still avoid integer-valued floats to be sure.
        yield 'integer-valued float' => ['integer-valued float', ['qty' => 1.0], '{"qty":1}', true];

        // GRANTED — a true fractional float is spelled the same by both encoders.
        yield 'fractional float' => ['fractional float', ['ratio' => 1.5], '{"ratio":1.5}', true];

        // REFUSED — a genuine TYPE change is not an encoder spelling: the string "1" and the number 1 are different
        // calls, and the comparison fails closed. This is the boundary — the house forgives how a value is written,
        // never what it is.
        yield 'string where a number is signed' => ['string where a number is signed', ['qty' => '1'], '{"qty":1}', false];
    }
}
