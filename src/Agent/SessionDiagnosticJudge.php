<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\{AnswerJudge, AnswerVerdict};
use Milpa\EventStore\{Event, EventStoreInterface};

/** Bind the pure diagnostic judgment to this session and retain the raw candidate for replay. */
final readonly class SessionDiagnosticJudge implements AnswerJudge
{
    public const EVENT = 'session.diagnostic_judged';

    public function __construct(private EventStoreInterface $events, private string $session)
    {
    }

    /** Judge and record before the loop decides termination; storage failure cannot accept. */
    public function judge(string $candidate): AnswerVerdict
    {
        $rows = (new SessionStore($this->events))->stream($this->session);
        $verdict = DiagnosticJudge::derive($this->session, $rows, $candidate);
        $this->events->append(new Event(
            SessionStore::PREFIX . $this->session,
            self::EVENT,
            ['candidate' => $candidate, 'verdict' => $verdict->toArray()],
            $this->events->nextSeq()
        ));
        return $verdict;
    }
}
