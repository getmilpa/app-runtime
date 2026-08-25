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

namespace Milpa\AppRuntime\Recipe;

use Milpa\Agent\PausedSequence;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\GovernedExecutor;
use Milpa\AppRuntime\Agent\GovernedSequenceRunner;
use Milpa\AppRuntime\Agent\SequenceCursor;
use Milpa\AppRuntime\Agent\SequenceResult;
use Milpa\AppRuntime\Agent\SequenceStep;

/**
 * Drives one declared unit of intent through the ONE governed door: it expands the recipe into an
 * ordered step list ({@see RecipeExpander}), runs it through the {@see GovernedSequenceRunner}, and
 * at a consent frontier persists WHERE it stopped as a first-class session fact so another process
 * can resume it. It holds no domain knowledge — no entity, no recipe name, no operation is spelled
 * out here; the executor, the store and the recipe travel in, a self-describing result travels out.
 *
 * Two contracts from greenhouse decisions/0076 are load-bearing and enforced here rather than left
 * to a caller: (a) a resume authenticates its declaration against the PERSISTED digest before the
 * executor is ever touched, and (b) a pause whose append fails is reported as unresumable and NEVER
 * dressed up as an in-memory cursor nobody wrote down.
 */
final class RecipeDriver
{
    /**
     * Expands the recipe and runs it, persisting the pause fail-closed at the first consent frontier.
     *
     * @param callable(): array{verdict: string, domain: ?string} $verdict   the app's founding verdict, read when the run begins
     * @param callable(): list<string>                            $installed the package names already installed, read when the run begins
     *
     * @return array<string, mixed> a self-describing outcome: ok, applied, paused, and — when paused —
     *                              whether it is resumable and which operation is pending
     */
    public function apply(
        Recipe $recipe,
        GovernedExecutor $executor,
        SessionStore $store,
        string $sessionId,
        callable $verdict,
        callable $installed,
    ): array {
        $v = $verdict();

        $steps = (new RecipeExpander())->expand($recipe, $v['verdict'], $v['domain'], $installed());

        $result = (new GovernedSequenceRunner())->run($steps, $executor);

        return $this->settle($result, $steps, $store, $sessionId, $recipe->name, resuming: false);
    }

    /**
     * Resumes a persisted pause with fresh consent, authenticating the declaration by its stored digest.
     *
     * @return array<string, mixed> a self-describing outcome, in the same shape {@see apply()} returns
     */
    public function resume(SessionStore $store, string $sessionId, GovernedExecutor $executor): array
    {
        $paused = $store->load($sessionId)?->pausedSequence;
        if ($paused === null) {
            return [
                'ok' => false,
                'applied' => false,
                'paused' => false,
                'resumable' => false,
                'reason' => 'no paused sequence to resume',
            ];
        }

        $steps = array_map(
            static fn (array $declared): SequenceStep => new SequenceStep($declared['operation'], $declared['arguments']),
            $paused->steps,
        );

        // 0076 CONTRACT (a): pass the PERSISTED digest — a declaration mutated across the pause is
        // rejected by the cursor itself, before the executor is ever touched (cross-process property 4).
        $cursor = SequenceCursor::rehydrate($steps, $paused->nextIndex, $paused->digest);

        $result = (new GovernedSequenceRunner())->resume($steps, $cursor, $executor);

        return $this->settle($result, $steps, $store, $sessionId, $paused->sequenceId, resuming: true);
    }

    /**
     * Turns a run's outcome into the self-describing result, persisting a fresh pause fail-closed.
     *
     * @param list<SequenceStep> $steps
     *
     * @return array<string, mixed>
     */
    private function settle(
        SequenceResult $result,
        array $steps,
        SessionStore $store,
        string $sessionId,
        string $sequenceId,
        bool $resuming,
    ): array {
        $cursor = $result->pausedCursor($steps);
        if ($cursor !== null) {
            $paused = new PausedSequence(
                $sequenceId,
                $cursor->digest,
                array_map(
                    static fn (SequenceStep $step): array => ['operation' => $step->operation, 'arguments' => $step->arguments],
                    $steps,
                ),
                $cursor->nextIndex,
            );

            // 0076 CONTRACT (b): the append IS the frontier's persistence. If it throws, the pause was
            // not written — so it is not resumable, and there is no in-memory cursor to hand back. A
            // process that dies here leaves nothing; a process that catches this reports nothing more.
            try {
                $store->recordSequencePaused($sessionId, $paused);
            } catch (\Throwable) {
                return [
                    'ok' => false,
                    'applied' => false,
                    'paused' => true,
                    'resumable' => false,
                    'reason' => 'pause could not be persisted',
                    'executed_count' => $result->executedCount(),
                    'steps_total' => \count($steps),
                ];
            }

            return [
                'ok' => true,
                'applied' => false,
                'paused' => true,
                'resumable' => true,
                'pending_operation' => $result->frontier()?->step->operation,
                'executed_count' => $result->executedCount(),
                'steps_total' => \count($steps),
            ];
        }

        if ($result->completed()) {
            if ($resuming) {
                $store->recordSequenceResumed($sessionId, $sequenceId);
            }

            return [
                'ok' => true,
                'applied' => true,
                'paused' => false,
                'executed_count' => $result->executedCount(),
                'steps_total' => \count($steps),
            ];
        }

        // Not paused, not complete: a step failed (something broke, nobody is waiting on a human).
        return [
            'ok' => false,
            'applied' => false,
            'paused' => false,
            'reason' => $result->frontier()?->reason,
            'executed_count' => $result->executedCount(),
            'steps_total' => \count($steps),
        ];
    }
}
