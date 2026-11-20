<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\{AnswerJudge, AnswerVerdict};
use Milpa\EventStore\{Event, EventStoreInterface};

/** Native factory judge; persist its candidate-bound result before termination can accept. */
final readonly class SessionReportTaskJudge implements AnswerJudge
{
    public const EVENT = 'session.report_task_judged';

    public function __construct(private EventStoreInterface $events, private string $session, private int $afterModelSeq)
    {
    }

    /** Judge current model input under the prior task, never a saved answer from an earlier leg. */
    public function judge(string $candidate): AnswerVerdict
    {
        $verdict = ReportTaskJudge::derive($this->session, (new SessionStore($this->events))->stream($this->session), $candidate, $this->afterModelSeq);
        $this->events->append(new Event(
            SessionStore::PREFIX . $this->session,
            self::EVENT,
            ['candidate' => $candidate, 'verdict' => $verdict->toArray()],
            $this->events->nextSeq()
        ));
        return $verdict;
    }
}
