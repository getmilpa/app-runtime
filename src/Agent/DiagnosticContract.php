<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\StructuredOutput;
use Milpa\EventStore\{Event, EventStoreInterface};

/** An immutable caller-declared JSON projection, before execution (greenhouse 0418). */
final class DiagnosticContract
{
    public const EVENT = 'session.diagnostic_declared';
    private const EXECUTION = ['session.turn', 'session.model_called', 'session.tool_called', 'session.trial_run_recorded'];

    /** Parse only a finite scalar projection of one content-addressed document.
     * @return array{path:string,sha256:string,fields:array<string,string>,equals:array<string,array{string,string}>,output?:string}
     */
    public static function parse(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!is_array($input) || array_diff(array_keys($input), ['path', 'sha256', 'fields', 'equals', 'output']) !== []
            || !is_string($input['path'] ?? null) || !preg_match('~^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*$~D', $input['path'])
            || array_intersect(explode('/', $input['path']), ['.', '..']) !== []
            || !is_string($input['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['sha256'])
            || !is_array($input['fields'] ?? null) || $input['fields'] === [] || !is_array($input['equals'] ?? null)) {
            throw new \InvalidArgumentException('A diagnostic requires path, sha256, fields and equals maps.');
        }
        $key = static fn ($value): bool => is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value) === 1;
        foreach ($input['fields'] as $name => $field) {
            if (!$key($name) || !$key($field)) {
                throw new \InvalidArgumentException('Diagnostic fields map output names to top-level document keys.');
            }
        }
        foreach ($input['equals'] as $name => $pair) {
            if (!$key($name) || array_key_exists($name, $input['fields']) || !is_array($pair)
                || array_keys($pair) !== [0, 1] || !$key($pair[0]) || !$key($pair[1])) {
                throw new \InvalidArgumentException('Diagnostic equals maps distinct output names to two document keys.');
            }
        }
        if (array_key_exists('output', $input)
            && ($input['output'] !== 'json_schema' || count($input['fields']) + count($input['equals']) > 64
                || array_filter([...array_keys($input['fields']), ...array_keys($input['equals'])], static fn ($v): bool => strlen($v) > 64) !== [])) {
            throw new \InvalidArgumentException('Diagnostic output must be json_schema with at most 64 named scalar fields (64 characters each).');
        }
        ksort($input['fields']);
        ksort($input['equals']);
        return ['path' => $input['path'], 'sha256' => $input['sha256'], 'fields' => $input['fields'], 'equals' => $input['equals']]
            + (array_key_exists('output', $input) ? ['output' => $input['output']] : []);
    }

    /** Derive shape solely from the declared projection, without reading source or expected values.
     * @param array<string,mixed> $criterion
     */
    public static function outputFormat(array $criterion): ?StructuredOutput
    {
        $criterion = self::parse($criterion);
        if (!isset($criterion['output'])) {
            return null;
        }
        if (!class_exists(StructuredOutput::class)) {
            throw new \RuntimeException('The gateway does not support diagnostic structured output.');
        }
        $fields = array_fill_keys(array_keys($criterion['fields']), ['string', 'number', 'boolean', 'null']);
        foreach ($criterion['equals'] as $name => $_pair) {
            $fields[$name] = ['boolean'];
        }
        return new StructuredOutput('diagnostic', $fields);
    }

    /** Read native declaration provenance; corrupt, duplicate or late facts cannot become absence.
     * @param list<Event> $events
     *
     * @return array<string,mixed>|null
     */
    public static function read(array $events, string $session): ?array
    {
        $found = null;
        $executed = false;
        $previous = 0;
        foreach ($events as $event) {
            if ($event->streamId !== SessionStore::PREFIX . $session || $event->seq <= $previous) {
                throw new \UnexpectedValueException('Invalid diagnostic stream provenance.');
            }
            $previous = $event->seq;
            if ($event->type === self::EVENT) {
                $p = $event->payload;
                $criterion = self::parse($p['criterion'] ?? null);
                if ($found !== null || $executed || ($p['schema'] ?? null) !== 'milpa.diagnostic-contract/v1'
                    || ($p['session'] ?? null) !== $session || ($p['criterion'] ?? null) !== $criterion
                    || ($p['sha256'] ?? null) !== self::hash($criterion)
                    || ($p['provenance']['source'] ?? null) !== 'agent_invocation'
                    || !is_string($p['provenance']['channel'] ?? null)) {
                    throw new \UnexpectedValueException('The diagnostic declaration is inconsistent or late.');
                }
                $found = $p + ['seq' => $event->seq];
            }
            $executed = $executed || in_array($event->type, self::EXECUTION, true);
        }
        return $found;
    }

    /** Validate before invocation effects; redeclaring identical scope is idempotent.
     * @param list<Event> $events
     *
     * @return array<string,mixed>
     */
    public static function validate(array $events, string $session, mixed $input): array
    {
        $criterion = self::parse($input);
        if (DeliveryExpectation::read($events, $session) !== null || DeliveryScope::read($events, $session) !== null) {
            throw new \InvalidArgumentException('Use a separate session for diagnosis and work delivery.');
        }
        $existing = self::read($events, $session);
        if ($existing !== null) {
            if ($existing['criterion'] !== $criterion) {
                throw new \InvalidArgumentException('The diagnostic contract is immutable; use a new session.');
            }
            return $criterion;
        }
        foreach ($events as $event) {
            if (in_array($event->type, self::EXECUTION, true)) {
                throw new \InvalidArgumentException('Declare the diagnostic before model or tool execution.');
            }
        }
        return $criterion;
    }

    /** Record once at the invocation boundary, without model-callable declaration tools.
     * @param array<string,mixed> $criterion
     */
    public static function record(EventStoreInterface $events, string $session, array $criterion, ObservedExecutor $caller): void
    {
        $rows = (new SessionStore($events))->stream($session);
        $criterion = self::validate($rows, $session, $criterion);
        if (self::read($rows, $session) !== null) {
            return;
        }
        $events->append(new Event(
            SessionStore::PREFIX . $session,
            self::EVENT,
            ['schema' => 'milpa.diagnostic-contract/v1', 'session' => $session, 'criterion' => $criterion,
                'sha256' => self::hash($criterion), 'provenance' => ['source' => 'agent_invocation',
                    'channel' => $caller->source, 'principal' => $caller->principal?->toArray()]],
            $events->nextSeq()
        ));
    }

    /** Hash normalized caller criteria.
     * @param array<string,mixed> $criterion
     */
    private static function hash(array $criterion): string
    {
        return hash('sha256', json_encode($criterion, JSON_THROW_ON_ERROR));
    }
}
