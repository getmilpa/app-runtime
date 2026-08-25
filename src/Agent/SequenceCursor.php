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
     * @param list<SequenceStep> $steps
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

        return \hash('sha256', (string) \json_encode($encoded));
    }
}
