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

use Milpa\EventStore\Event;

/**
 * The judge's LAST word on a test reference: the last recorded `test` call that DECLARES it and that ran.
 *
 * A todo is not done against a red judge (greenhouse decisions/0509 §4). The claim door used to accept the
 * last GREEN run declaring a reference even when a red one came after it, and the closure never asked again.
 * Both now read the same fact: of the `test` calls that declare the reference (exact equality on a recorded
 * argument, never free text), the last one that is a verdict at all —
 *
 * - GREEN: the call succeeded (`ok: true`), the rule the claim door always used;
 * - RED: the call failed AND its result says the suite ran with failures or errors — in the house
 *   (`ran`, `failures`, `errors`) or in a trial (`summary.counts`). A call that never ran — refused, malformed,
 *   a path the tool would not take — is not a judge and says nothing.
 */
final class LastTestRun
{
    /** The recorded arguments a test run declares its identity in — the family the projections document. */
    private const DECLARING = ['filter', 'path', 'name', 'class', 'artifact', 'target', 'file'];

    /**
     * The last test run declaring `$reference`, green or red, or `null` when no run declares it.
     *
     * @param iterable<Event> $stream the session's own stream, in order
     *
     * @return array{seq: int, green: bool}|null
     */
    public static function of(iterable $stream, string $reference): ?array
    {
        $last = null;
        foreach ($stream as $event) {
            if ($event->type !== 'session.tool_called' || ($event->payload['tool'] ?? null) !== 'test') {
                continue;
            }
            $arguments = \is_array($event->payload['arguments'] ?? null) ? $event->payload['arguments'] : [];
            $declared = false;
            foreach (self::DECLARING as $key) {
                if (($arguments[$key] ?? null) === $reference) {
                    $declared = true;

                    break;
                }
            }
            if (! $declared) {
                continue;
            }
            if (($event->payload['ok'] ?? null) === true) {
                $last = ['seq' => $event->seq, 'green' => true];
            } elseif (self::ranRed($event->payload['result'] ?? null)) {
                $last = ['seq' => $event->seq, 'green' => false];
            }
        }

        return $last;
    }

    /** Whether a failed test call's result says the suite RAN and found failures or errors. */
    private static function ranRed(mixed $result): bool
    {
        $decoded = \is_string($result) ? json_decode($result, true) : $result;
        if (! \is_array($decoded)) {
            return false;
        }
        $counts = \is_array($decoded['summary']['counts'] ?? null) ? $decoded['summary']['counts']
            : (\is_array($decoded['output'] ?? null) ? $decoded['output'] : $decoded);

        return ($counts['ran'] ?? null) === true
            && ((\is_int($counts['failures'] ?? null) && $counts['failures'] > 0)
                || (\is_int($counts['errors'] ?? null) && $counts['errors'] > 0));
    }
}
