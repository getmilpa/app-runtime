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

use Milpa\Agent\Session;
use Milpa\Agent\SessionFacts;
use Milpa\EventStore\Event;

/**
 * The ONE closure verdict of a leg: what its final answer records, and what the epilogue opens on (greenhouse
 * decisions/0517).
 *
 * The epilogue (decisions/0477) announces «the work phase is closed» and spends the output budget on that claim;
 * the final answer then records the house's verdict ({@see ClosureVerdict}, decisions/0487, 0509). They were two
 * readings of the same question: the probe asked the todos alone whenever a session had any, while the verdict
 * also asked the artifacts, the judges, the last test run and what the house observed. Measured (evidence/1042):
 * a session could open its epilogue on eight done todos and record `verified: false` after it; or the verdict
 * could close on the house's observation while the probe waited for todos. Here both read the same function over
 * the same stream, so they agree by construction, not by coincidence.
 *
 * A declared delivery ({@see DeliveryScope}) is judged on evidence read FRESH from the house, which only the
 * natural end reads. Between steps that evidence does not exist yet, so the verdict is not known —
 * `null` — and no epilogue opens on it.
 */
final class LegClosure
{
    /**
     * The verdict the final answer records, reading the declared delivery's evidence now.
     *
     * @param list<Event>                                          $stream   the session's stream, as $session was folded from
     * @param \Closure(array<string, mixed>): array<string, mixed> $observe  reads the declared delivery's acceptance evidence
     * @param (\Closure(string, array<string, mixed>): ?bool)|null $lasting  which calls last, by their own declaration ({@see LastingCalls})
     * @param (\Closure(string, ?string): ?bool)|null              $admitted whether a person's admission covers an operation for a principal ({@see HouseExecutedWork})
     *
     * @return array<string, mixed>
     */
    public static function atTheEnd(Session $session, array $stream, \Closure $observe, ?\Closure $lasting = null, ?\Closure $admitted = null): array
    {
        return self::verdict($session, $stream, $observe, $lasting, $admitted)
            ?? DeliveryClosure::derive($session, SessionFacts::fromEvents($session->id, $stream), [], null);
    }

    /**
     * The same verdict between steps — `null` while it depends on evidence only the natural end reads.
     *
     * WORK IS NOT READ HERE (greenhouse decisions/0599, measured in evidence/1150). Between steps this verdict opens the
     * epilogue: for a route or a capability the house knows what done is — the goal names it and the house saw it. For
     * work it does not: the request is in the words of the domain, and the house cannot tell the first act from the last.
     * Read here, the receipts of work closed a session after its first act, with five more asked for. So the work a
     * session did closes it only at its natural end ({@see atTheEnd()}), when the session itself says it is done.
     *
     * @param list<Event>                                          $stream  the session's stream, as $session was folded from
     * @param (\Closure(string, array<string, mixed>): ?bool)|null $lasting which calls last, by their own declaration ({@see LastingCalls})
     *
     * @return array<string, mixed>|null
     */
    public static function betweenSteps(Session $session, array $stream, ?\Closure $lasting = null): ?array
    {
        return self::verdict($session, $stream, null, $lasting);
    }

    /**
     * @param list<Event>                                                 $stream
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $observe
     * @param (\Closure(string, array<string, mixed>): ?bool)|null        $lasting
     *
     * @return array<string, mixed>|null
     */
    private static function verdict(Session $session, array $stream, ?\Closure $observe, ?\Closure $lasting, ?\Closure $admitted = null): ?array
    {
        $facts = SessionFacts::fromEvents($session->id, $stream);
        try {
            $declaration = DeliveryScope::read($stream, $session->id);
            if ($declaration === null) {
                $expectation = DeliveryExpectation::read($stream, $session->id);
                if ($expectation !== null) {
                    return DeliveryClosure::derive($session, $facts, [], null)
                        + ['expectation' => ['declarationSeq' => $expectation['seq'], 'sha256' => $expectation['sha256']],
                            'bindingState' => 'awaiting_candidate'];
                }

                return ClosureVerdict::derive($session, $facts, $stream, $lasting, $admitted);
            }
            if ($observe === null) {
                return null;
            }
            $contract = ['session' => $session->id] + $declaration['scope'];
            $evidence = $observe($contract);

            return DeliveryClosure::derive($session, $facts, $contract, $evidence)
                + ['delivery' => ['declarationSeq' => $declaration['seq'], 'sha256' => $declaration['sha256']],
                    'observation' => $evidence];
        } catch (\Throwable) {
            return DeliveryClosure::derive($session, $facts, [], null);
        }
    }
}
