<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Tui;

use Milpa\AppRuntime\Tui\EnvelopeInput;
use PHPUnit\Framework\TestCase;

/**
 * The terminal's «sí, pero…»: a human tightening a gate's effect ceiling types `axis=level` pairs,
 * and this builds the envelope array agent:answer adjudicates (greenhouse evidence/0299). It never
 * asks for JSON — the console coercer cannot decode an object from a string, and the in-TUI path
 * passes native arrays — so the screen parses a simple, typo-tolerant `axis=level` form itself.
 */
final class EnvelopeInputTest extends TestCase
{
    public function testItBuildsAnEnvelopeArrayFromAxisEqualsLevelPairs(): void
    {
        self::assertSame(
            ['authority' => 'read', 'reversibility' => 'compensatable'],
            EnvelopeInput::parse('authority=read, reversibility=compensatable'),
        );
    }

    public function testItAcceptsAColonAndTrimsAndLowercasesTheAxis(): void
    {
        self::assertSame(['authority' => 'read'], EnvelopeInput::parse('  Authority : read  '));
    }

    public function testTextThatNamesNoKnownAxisIsNotAnEnvelope(): void
    {
        // "use 200 not 250" is a value counter, not a tighten — it must not masquerade as an envelope.
        self::assertSame([], EnvelopeInput::parse('use 200 not 250'));
        self::assertSame([], EnvelopeInput::parse(''));
        self::assertSame([], EnvelopeInput::parse('nonsense=whatever'));
    }

    public function testAKnownAxisWithAnUnknownLevelIsStillOfferedForTheBackendToReject(): void
    {
        // The screen does not re-implement the effect algebra: it forwards a known axis with whatever
        // level was typed, and agent:answer/EffectProfile::fromPartial refuses a bad level with its
        // own message — one source of truth for what a level may be.
        self::assertSame(['authority' => 'banana'], EnvelopeInput::parse('authority=banana'));
    }
}
