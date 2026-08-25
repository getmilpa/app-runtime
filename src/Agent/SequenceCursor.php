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

/**
 * Where a paused governed sequence stopped: the digest of the FULL declared step list it was
 * paused against, the index of the step to try next, and the Executed prefix carried untouched
 * across the pause (never re-run). `GovernedSequenceRunner::resume` rejects a cursor whose digest
 * disagrees with the steps it is handed — a mutated sequence must not continue under a grant
 * given to a different one (greenhouse decisions/0075, property 4).
 */
final readonly class SequenceCursor
{
    /**
     * @param list<StepOutcome> $done the Executed prefix, in order — carried, never re-run
     */
    public function __construct(
        public string $digest,
        public int $nextIndex,
        public array $done,
    ) {
    }

    /**
     * The stable digest of a declared step list: the same steps always hash the same, and any
     * change to an operation or its arguments changes the digest.
     *
     * Each step's arguments are canonicalized before encoding — array keys sorted recursively, so
     * the same call hashes the same regardless of the order its arguments happened to be built in.
     * This matches the encoding `ConsentBridge::digest` uses to digest a call's arguments
     * (`src/Agent/ConsentBridge.php`) — "the ONE recipe" the house already uses when a tightened
     * grant records the exact call it was given over. It is reproduced here as an ALGORITHM, not
     * called directly: `ConsentBridge` extends an `ai-gateway` service class, a require-dev-only
     * dependency of this package (see `GovernedSequenceRunnerConsentTest::setUp`'s own
     * `class_exists` skip guard), and this value object must stay usable without it — Task 1's own
     * shape never touches `GovernedExecutor` or `ai-gateway` at all.
     *
     * FAILS CLOSED: a step whose arguments cannot be JSON-encoded (a NAN/INF float, a resource, a
     * non-UTF-8 string) throws rather than silently digesting as `sha256('')` — the old
     * `(string) json_encode(...)` cast turned `json_encode`'s failure return (`false`) into an
     * empty string, so any two non-encodable step lists collided on the SAME digest, bypassing
     * the mutated-sequence guard `GovernedSequenceRunner::resume` relies on (property 4).
     *
     * @param list<SequenceStep> $steps
     *
     * @throws \JsonException when a step's arguments cannot be JSON-encoded
     */
    public static function digestOf(array $steps): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (! \is_array($value)) {
                return $value;
            }
            \ksort($value);

            return \array_map($canonical, $value);
        };

        $encoded = \array_map(
            static fn (SequenceStep $step): array => [$step->operation, $canonical($step->arguments)],
            $steps,
        );

        return \hash('sha256', \json_encode($encoded, \JSON_THROW_ON_ERROR));
    }

    /**
     * Rebuild a resumable cursor from a declaration and a nextIndex alone — PURE, no stream read,
     * no agent dependency. This is what a caller builds from a rehydrated pause fact (its `steps`
     * cast back to `SequenceStep[]`, its `nextIndex`) to hand to `GovernedSequenceRunner::resume`
     * in another process: the digest is recomputed here from `$declaredSteps` (so `resume()`'s own
     * re-hash against the caller-provided steps is the ONLY authority that decides whether the
     * declaration still matches — property 4 across processes), and `$done` is built as
     * `$nextIndex` PLACEHOLDER `Executed` outcomes rather than any content carried from the pause
     * fact — `resume()`'s `drive()` never inspects `done`'s content, only `count($done)`, so a
     * placeholder prefix is exactly as good as a real one for satisfying that guard.
     *
     * @param list<SequenceStep> $declaredSteps the FULL declared step list, exactly as originally run
     *
     * @throws \InvalidArgumentException when `$nextIndex` is negative or exceeds the declared step count
     * @throws \JsonException            when `$declaredSteps` carries a non-JSON-encodable argument
     *                                   (see `digestOf`)
     */
    public static function rehydrate(array $declaredSteps, int $nextIndex): self
    {
        if ($nextIndex < 0 || $nextIndex > \count($declaredSteps)) {
            throw new \InvalidArgumentException(
                "cannot rehydrate: nextIndex {$nextIndex} is out of range for " . \count($declaredSteps) . ' declared steps',
            );
        }

        $digest = self::digestOf($declaredSteps);
        $done = [];
        for ($i = 0; $i < $nextIndex; $i++) {
            $done[] = new StepOutcome($declaredSteps[$i], StepStatus::Executed);
        }

        return new self($digest, $nextIndex, $done);
    }
}
