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

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionEvent;
use Milpa\Command\Consent\OperationId;
use Milpa\EventStore\Event;

/**
 * Whether a recorded call reached the house, or stayed in the trial it ran in (greenhouse decisions/0587).
 *
 * A mutating call of a session runs first in a disposable copy, and the house records it as it answered: `ok: true`,
 * `ran_in_trial: true, applied: false`. It becomes a fact about the house only when that trial is promoted
 * (decisions/0463). The closure the house derives already read it so — «the rehearsal did not land» (decisions/0494
 * §4) — but the claims door and the verdict over a session's own record did not. Measured on published 0.211.1
 * (decisions/0585): a domain write ran in a trial that had «nothing to apply», `operation-ok` over it was accepted,
 * and the session closed `verified: true` with the house's store untouched.
 *
 * This is that one reading, for whoever has to tell an answer from the house from an answer from a copy:
 *
 * - a succeeded call REACHED the house when it ran in it, when the house applied its trial itself (`applied`), or
 *   when a later promotion of that trial succeeded and carried something;
 * - a REHEARSAL is a succeeded call that did none of those. It says nothing of the house: it neither backs a claim
 *   nor takes back an earlier call that did land.
 *
 * What a rehearsal is, is {@see self::keptInATrial()} — the definition decisions/0494 fixed, asked by
 * {@see HouseObservedClosure} too, so the two cannot drift. A result the store kept only in part cannot say where it
 * ran; the house's own record of the trial (`session.trial_run_recorded`, written before the call) says it instead.
 *
 * Only a call that would have CHANGED the house has to land in it. A trial also confines calls that leave nothing
 * behind — a test run, a dry run — and those answered what they answered: whether a call lasts is its operation's own
 * declaration ({@see LastingCalls}, decisions/0523), read exactly as the house's closure reads it.
 */
final class LandedCalls
{
    /** @var array<int, array{tool: string, succeeded: bool, rehearsed: bool, workspace: ?string, promotable: bool, unchanged: bool}> seq => call */
    private array $calls = [];

    /** @var array<int, array{operation: string, call: ?int}> seq => the receipt of an execution and the call it belongs to */
    private array $receipts = [];

    /** @var array<string, int> workspace => seq of the promotion that carried it into the house */
    private array $promoted = [];

    private function __construct()
    {
    }

    /**
     * Read where each call of a session's stream ran.
     *
     * @param list<Event>                                          $stream  the session's own stream
     * @param (\Closure(string, array<string, mixed>): ?bool)|null $lasting the house's reading of which calls last; null takes every call as lasting
     */
    public static function of(array $stream, ?\Closure $lasting = null): self
    {
        $self = new self();
        // The trial the house recorded since the last call: the call that follows ran in it.
        $trial = null;
        $last = null;
        foreach ($stream as $event) {
            $payload = $event->payload;
            if ($event->type === SessionEvent::TrialRunRecorded->value) {
                $trial = \is_string($payload['workspace'] ?? null) ? $payload['workspace'] : '';

                continue;
            }
            if ($event->type === SessionEvent::OperationExecuted->value) {
                $operation = \is_string($payload['operation'] ?? null) ? $payload['operation'] : '';
                $call = $last !== null && (new OperationId($operation))->is($self->calls[$last]['tool']) ? $last : null;
                $self->receipts[$event->seq] = ['operation' => $operation, 'call' => $call];
                // WORK THAT LEFT THE HOUSE AS IT WAS DID NOT REACH IT (greenhouse decisions/0588, rule 5). A work call
                // runs in the house, and its receipt carries the house's own account of its state: the same digest
                // before and after is «it did not change», whatever the handler answered.
                if ($call !== null && ($payload['environment'] ?? null) === 'house' && ($payload['changed'] ?? null) === false) {
                    $self->calls[$call]['unchanged'] = true;
                }

                continue;
            }
            if ($event->type !== SessionEvent::ToolCalled->value || ! \is_string($payload['tool'] ?? null)) {
                continue;
            }

            $result = json_decode(\is_string($payload['result'] ?? null) ? $payload['result'] : '', true);
            $readable = \is_array($result);
            $result = $readable ? $result : [];
            $succeeded = ($payload['ok'] ?? true) === true && (! \is_bool($result['ok'] ?? null) || $result['ok']);
            $rehearsed = ($readable ? self::keptInATrial($result) : $trial !== null)
                && HouseObservedClosure::lasts($payload, $readable ? $result : null, $lasting);
            $workspace = \is_string($result['workspace'] ?? null) ? $result['workspace'] : $trial;

            $self->calls[$event->seq] = [
                'tool' => $payload['tool'],
                'succeeded' => $succeeded,
                'rehearsed' => $rehearsed,
                'workspace' => $rehearsed && $workspace !== '' ? $workspace : null,
                // The house says so itself: a trial with something to apply answers with the call that applies it.
                'promotable' => ! $readable || \is_array($result['to_apply'] ?? null),
                'unchanged' => false,
            ];
            if ($succeeded && ! $rehearsed) {
                $carried = self::carriedBy($payload, $readable ? $result : null);
                if ($carried !== null) {
                    $self->promoted[$carried] = $event->seq;
                }
            }
            $trial = null;
            $last = $event->seq;
        }

        return $self;
    }

    /**
     * Whether a recorded result is a rehearsal the house did not apply (greenhouse decisions/0494 §4).
     *
     * @param array<mixed> $result a call's recorded result, decoded
     */
    public static function keptInATrial(array $result): bool
    {
        return ($result['ran_in_trial'] ?? false) === true && ($result['applied'] ?? false) !== true;
    }

    /**
     * Whether the succeeded call recorded at `$seq` reached the house — or null when no succeeded call is recorded there.
     */
    public function reached(int $seq): ?bool
    {
        $call = $this->calls[$seq] ?? null;
        if ($call === null || ! $call['succeeded']) {
            return null;
        }

        if ($call['unchanged']) {
            return false;
        }

        return ! $call['rehearsed'] || ($call['workspace'] !== null && isset($this->promoted[$call['workspace']]));
    }

    /**
     * When a trial of this stream reached the house: the seq of the promotion that carried it — or null while nothing
     * has promoted it (greenhouse decisions/0596).
     *
     * For whoever has to say what a session BROUGHT into the house, and in which order: the trial's own fact says
     * which files it changed, and this says whether, and when, that change became the house's.
     */
    public function carriedAt(string $workspace): ?int
    {
        return $this->promoted[$workspace] ?? null;
    }

    /**
     * The call that answers for a tool in the house: its last recorded call, rehearsals aside. Null when that call
     * failed, or when the tool was never called outside a rehearsal.
     *
     * @return array{seq: int}|null
     */
    public function answeredOk(string $tool): ?array
    {
        foreach (array_reverse($this->calls, true) as $seq => $call) {
            if ($call['tool'] !== $tool || $this->reached($seq) === false) {
                continue;
            }

            return $call['succeeded'] ? ['seq' => $seq] : null;
        }

        return null;
    }

    /**
     * The receipt of an execution of `$operation` in the house, or null: a receipt whose call never left its trial
     * says the operation ran — in the copy.
     *
     * @return array{seq: int}|null
     */
    public function executed(string $operation): ?array
    {
        foreach ($this->receipts as $seq => $receipt) {
            if ($receipt['operation'] === $operation && ($receipt['call'] === null || $this->reached($receipt['call']) !== false)) {
                return ['seq' => $seq];
            }
        }

        return null;
    }

    /**
     * The last rehearsal of a tool or operation that nothing promoted, in either spelling — or null when there is none.
     *
     * @return array{seq: int, workspace: ?string, promotable: bool, left_as_it_was?: true}|null
     */
    public function rehearsalOf(string $tool): ?array
    {
        $identity = new OperationId($tool);

        return $this->lastRehearsal(static fn (int $seq, string $called): bool => $identity->is($called));
    }

    /**
     * The rehearsal an artifact rests on when ONLY trials hold it: every call that made it stayed in a trial nothing
     * promoted. Null when one of them reached the house, or when the stream records none.
     *
     * @param array<mixed> $attempts the artifact's recorded attempts ({@see \Milpa\Agent\SessionFacts::workStateFor()})
     *
     * @return array{seq: int, workspace: ?string, promotable: bool, left_as_it_was?: true}|null
     */
    public function madeOnlyInATrial(array $attempts): ?array
    {
        $made = [];
        foreach ($attempts as $attempt) {
            if (! \is_array($attempt) || ! \is_int($attempt['seq'] ?? null) || ($attempt['mutating'] ?? false) !== true
                || ($attempt['succeeded'] ?? false) !== true || ($attempt['awaitingConfirmation'] ?? null) === true) {
                continue;
            }
            if ($this->reached($attempt['seq']) !== false) {
                return null;
            }
            $made[] = $attempt['seq'];
        }

        return $this->lastRehearsal(static fn (int $seq, string $called): bool => \in_array($seq, $made, true));
    }

    /**
     * @param \Closure(int, string): bool $asked which calls to look at
     *
     * @return array{seq: int, workspace: ?string, promotable: bool, left_as_it_was?: true}|null
     */
    private function lastRehearsal(\Closure $asked): ?array
    {
        foreach (array_reverse($this->calls, true) as $seq => $call) {
            if ($asked($seq, $call['tool']) && $this->reached($seq) === false) {
                return $call['unchanged']
                    ? ['seq' => $seq, 'workspace' => null, 'promotable' => false, 'left_as_it_was' => true]
                    : ['seq' => $seq, 'workspace' => $call['workspace'], 'promotable' => $call['promotable']];
            }
        }

        return null;
    }

    /**
     * Why a claim over that rehearsal is refused, with the call that would land it when there is one
     * (greenhouse decisions/0571: a hint names a call the house runs, or none).
     *
     * @param array{seq: int, workspace: ?string, promotable: bool, left_as_it_was?: true} $rehearsal
     * @param string                                                                       $did       what the reference did there, as the claim's kind says it
     */
    public static function refusal(string $reference, array $rehearsal, string $did = 'answered ok'): string
    {
        if (($rehearsal['left_as_it_was'] ?? false) === true) {
            return "«{$reference}» {$did} in the house (seq {$rehearsal['seq']}) and left the house as it was: its state is "
                . 'what it was before the call, so there is nothing of it in the house to claim';
        }
        $said = "«{$reference}» {$did} only inside a trial (seq {$rehearsal['seq']})";
        if (! $rehearsal['promotable'] || $rehearsal['workspace'] === null) {
            return $said . ' that changed nothing the house keeps, so there is nothing to promote: a rehearsal is not a fact '
                . 'about the house, and this one left nothing in it';
        }

        return $said . ' that nothing has promoted: a rehearsal is not a fact about the house. Apply it with sandbox:promote '
            . json_encode(['workspace' => $rehearsal['workspace']], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . ' and claim again';
    }

    /**
     * The trial a succeeded promotion carried into the house, or null when the call is no promotion or carried nothing.
     *
     * A promotion whose recorded result cannot be read whole does not SHOW that it carried nothing: it is taken to
     * have carried the trial it was asked for, so a ledger that kept only part of it never turns a landed call into
     * a rehearsal.
     *
     * @param array<string, mixed> $payload
     * @param array<mixed>|null    $result  null when the recorded result is not a readable object
     */
    private static function carriedBy(array $payload, ?array $result): ?string
    {
        $asked = \is_array($payload['arguments'] ?? null) ? ($payload['arguments']['workspace'] ?? null) : null;
        if ($result === null) {
            $workspace = $payload['tool'] === 'sandbox_promote' ? $asked : null;
        } else {
            $evidence = \is_array($result['evidence'] ?? null) ? $result['evidence'] : [];
            if (($evidence['predicate'] ?? null) !== 'promoted' || ! \is_array($result['promoted'] ?? null) || $result['promoted'] === []) {
                return null;
            }
            $workspace = (\is_array($evidence['from'] ?? null) ? ($evidence['from']['workspace'] ?? null) : null) ?? $asked;
        }

        return \is_string($workspace) && $workspace !== '' ? $workspace : null;
    }
}
