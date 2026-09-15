<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\EventStore\{Event, EventStoreInterface};

/** One immutable caller expectation before execution; candidate binding is a later fact (0403/0721). */
final class DeliveryExpectation
{
    public const EVENT = 'session.delivery_expected';
    private const EXECUTION = ['session.turn', 'session.model_called', 'session.tool_called', 'session.trial_run_recorded', DeliveryScope::EVENT];

    /** Parse an explicit SDK object or CLI JSON string, never model prose.
     * @return array<string,mixed>
     */
    public static function parse(mixed $input): array
    {
        return DeliveryScope::parseExpected($input);
    }

    /** Read native provenance; malformed, duplicate and late expectations never become absence.
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
                throw new \UnexpectedValueException('Invalid expectation stream provenance.');
            }
            $previous = $event->seq;
            if ($event->type === self::EVENT) {
                $p = $event->payload;
                $expected = self::parse($p['expected'] ?? null);
                if ($found !== null || $executed || ($p['schema'] ?? null) !== 'milpa.delivery-expectation/v1'
                    || ($p['session'] ?? null) !== $session || ($p['expected'] ?? null) !== $expected
                    || ($p['sha256'] ?? null) !== self::hash($expected)
                    || ($p['provenance']['source'] ?? null) !== 'agent_invocation'
                    || !is_string($p['provenance']['channel'] ?? null)) {
                    throw new \UnexpectedValueException('The durable delivery expectation is inconsistent or late.');
                }
                $found = $p + ['seq' => $event->seq];
            }
            $executed = $executed || in_array($event->type, self::EXECUTION, true);
        }
        return $found;
    }

    /** Validate before invocation side effects; same input is idempotent even after execution.
     * @param list<Event> $events
     *
     * @return array<string,mixed>
     */
    public static function validate(array $events, string $session, mixed $input): array
    {
        $expected = self::parse($input);
        $existing = self::read($events, $session);
        if ($existing !== null) {
            if ($existing['expected'] !== $expected) {
                throw new \InvalidArgumentException('The delivery expectation is immutable; use a new session.');
            }
            return $expected;
        }
        foreach ($events as $event) {
            if (in_array($event->type, self::EXECUTION, true)) {
                throw new \InvalidArgumentException('Declare the delivery expectation before model or tool execution.');
            }
        }
        return $expected;
    }

    /** Record once through the trusted invocation boundary, with observed caller provenance.
     * @param array<string,mixed> $expected
     */
    public static function record(EventStoreInterface $events, string $session, array $expected, ObservedExecutor $caller): void
    {
        $rows = (new SessionStore($events))->stream($session);
        $expected = self::validate($rows, $session, $expected);
        if (self::read($rows, $session) !== null) {
            return;
        }
        $events->append(new Event(
            SessionStore::PREFIX . $session,
            self::EVENT,
            ['schema' => 'milpa.delivery-expectation/v1', 'session' => $session, 'expected' => $expected,
                'sha256' => self::hash($expected), 'provenance' => ['source' => 'agent_invocation',
                    'channel' => $caller->source, 'principal' => $caller->principal?->toArray()]],
            $events->nextSeq()
        ));
    }

    /** Hash canonical target bytes independently from the later candidate.
     * @param array<string,mixed> $expected
     */
    private static function hash(array $expected): string
    {
        return hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR));
    }
}
