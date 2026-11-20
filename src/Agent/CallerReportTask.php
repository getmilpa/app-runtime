<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/** Opt-in capability of trusted host PHP code, not a model-facing declaration or caller authentication. */
final readonly class CallerReportTask
{
    private function __construct(public string $session, public string $id, public string $tool)
    {
    }

    /** Select one finite failed-readonly-attempt reporting task before the session executes. */
    public static function failedReadOnlyAttempt(string $session, string $id, string $tool): self
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,140}$/D', $session)
            || !preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $id)
            || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $tool)) {
            throw new \InvalidArgumentException('A report task requires bounded session, task and tool identifiers.');
        }
        return new self($session, $id, $tool);
    }

    /** The durable identity; serialization alone cannot invoke the trusted host entry. */
    /** Serialize the immutable caller task identity.
     * @return array{session:string,task_id:string,target_tool:string} */
    public function toArray(): array
    {
        return ['session' => $this->session, 'task_id' => $this->id, 'target_tool' => $this->tool];
    }

    /** One finite relation, not an arbitrary task language or a statement-truth judgment. */
    /** Select the finite failed-attempt report relation.
     * @return array<string,mixed> */
    public function criterion(): array
    {
        return ['schema' => 'milpa.report-task/v1', 'target_tool' => $this->tool,
            'outcome' => 'failed', 'attempt' => 'exactly_one_in_logical_task',
            'report' => ['owner' => 'environment', 'kind' => 'tool_error'],
            'readback' => 'native_reader_delivered_in_current_model_input', 'final' => 'exact_object_finding_id'];
    }
}
