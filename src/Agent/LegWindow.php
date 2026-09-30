<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\Runtime\Config;

/**
 * What ONE leg gets out of the model's window: the steps it may take, the share of the window the
 * history it inherits may fill, and the room left for a page of an old result (greenhouse
 * decisions/0538, B1 + B4 of evidence/1071).
 *
 * ── THE INPUT LIMIT, NOT THE WINDOW ─────────────────────────────────────────────────────────────
 *
 * Every number here is a share of the INPUT LIMIT — the window minus the output the loop reserves on
 * every call — because that is the wall a request actually meets. evidence/1071 met it at 40,960 of a
 * 49,152-token window, four legs in a row, while every share was still computed on the 49,152.
 *
 * ── THE INHERITED SHARE (B4) ────────────────────────────────────────────────────────────────────
 *
 * A leg inherits a composed window (summary, operational facts, briefing, unsummarized tail) that
 * the gateway cannot trim during the leg: its loop only elides the leg's OWN tool results. What rides
 * beside it is fixed too — the system prompt and the tool schemas, 10–13k tokens on the 1071 train.
 * So what a leg inherits is decided here, before it starts: the composed window gets a fifth of the
 * input limit, and with the fixed part it fits in half of it. The other half is the leg's to work in.
 *
 * milpa/agent's {@see \Milpa\Agent\WindowBudget} composes at 60 % of the context it is handed, so the
 * context handed to it is a THIRD of the input limit (60 % × ⅓ = ⅕). The Compactor and the
 * composition receive the same number: the summary is written to the size the window will read.
 *
 * ── THE STEPS (B1) ──────────────────────────────────────────────────────────────────────────────
 *
 * An AUTO leg with no `--steps` takes its ceiling from the window: half the input limit — the room
 * the inherited share leaves free — over 512 tokens a step, between today's 12 and a cost ceiling of
 * 40 (Rod, decisions/0538). Measured in evidence/1072: the loop's elision keeps a long leg under its
 * budget, so it is this ceiling — not the window — that ends a long leg, and 40 steps cost ~1.2M
 * tokens on qwen. On qwen's 49,152-token window the derivation and the ceiling agree at 40.
 */
final readonly class LegWindow
{
    /** The ceiling nobody declared: what a leg takes without a window to derive from, and the floor with one. */
    public const DEFAULT_STEPS = 12;

    /** The cost ceiling of one AUTO leg (Rod, decisions/0538): 40 calls cost ~1.2M tokens on qwen (evidence/1072). */
    public const MAX_STEPS = 40;

    /** The least window a step is assumed to take — under the 751 measured per step in evidence/1069. */
    public const TOKENS_PER_STEP = 512;

    /** Characters per provider token for a page's estimate: chars/4 under-counts qwen by a third (decisions/0514). */
    public const CHARS_PER_TOKEN = 3;

    /** A page smaller than this is not worth a step: the leg is better ended with the room it still has. */
    public const MIN_PAGE_CHARS = 2000;

    /** The output the gateway reserves when nobody declared or derived one (ai-gateway's own default). */
    private const GATEWAY_OUTPUT_TOKENS = 4096;

    /**
     * @param int $contextTokens the window that governs, in tokens
     * @param int $outputTokens  the output the loop reserves on every call, in tokens
     */
    private function __construct(public int $contextTokens, public int $outputTokens)
    {
    }

    /** The leg's window as this house resolves it, or `null` when no window is known (every rule keeps today's). */
    public static function of(?Config $config): ?self
    {
        $context = AgentEndpoint::contextTokens($config);
        if ($context === null || $context < 1) {
            return null;
        }
        $output = AgentEndpoint::effectiveOutputTokens($config)['tokens']
            // The gateway's own reserve when no limit travels: a quarter of a small window, else 4096.
            ?? max(1, min(self::GATEWAY_OUTPUT_TOKENS, $context - (int) floor($context * 0.75)));

        return self::sized($context, $output);
    }

    /** A leg's window from its two numbers — the output clipped so the input limit is never below one token. */
    public static function sized(int $contextTokens, int $outputTokens): self
    {
        return new self(max(1, $contextTokens), max(0, min($outputTokens, $contextTokens - 1)));
    }

    /** The wall one request meets: the window minus the output reserved on every call. */
    public function inputLimit(): int
    {
        return max(1, $this->contextTokens - $this->outputTokens);
    }

    /**
     * The context handed to milpa/agent's WindowBudget — by the Compactor and by the composition alike —
     * so the composed inherited window is a fifth of the input limit.
     */
    public function inheritedContext(): int
    {
        return max(1, intdiv($this->inputLimit(), 3));
    }

    /** The steps an AUTO leg with no declared ceiling may take (decisions/0538 §1). */
    public function autoSteps(): int
    {
        return max(self::DEFAULT_STEPS, min(self::MAX_STEPS, intdiv(intdiv($this->inputLimit(), 2), self::TOKENS_PER_STEP)));
    }

    /**
     * The steps a leg takes: what someone typed, else the window's for an AUTO leg, else the default.
     *
     * @param mixed $asked the `steps` input as it arrived
     */
    public static function steps(mixed $asked, ?AutonomyMode $mode, ?self $window): int
    {
        if (\is_int($asked) && $asked > 0) {
            return $asked;
        }

        return $mode === AutonomyMode::Auto && $window !== null ? $window->autoSteps() : self::DEFAULT_STEPS;
    }

    /**
     * The tokens left before this leg's input limit, after the last call the provider counted.
     *
     * The next request carries everything the last one did plus the model's own answer, so both of the
     * provider's numbers are spent. `null` when the provider said nothing to count by.
     *
     * @param array<string, mixed> $usage the `usage` of the leg's last `session.model_returned`
     */
    public function roomAfter(array $usage): ?int
    {
        $prompt = $usage['prompt_tokens'] ?? null;
        if (!\is_int($prompt) || $prompt < 1) {
            return null;
        }
        $completion = \is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0;

        return max(0, $this->inputLimit() - $prompt - $completion);
    }

    /** The characters one page of an old result may take out of `$room` tokens: half of it, so the leg can still answer. */
    public static function pageChars(int $room): int
    {
        return intdiv($room, 2) * self::CHARS_PER_TOKEN;
    }
}
