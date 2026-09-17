<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\EventStore\Event;

/** Explicit composition membership: historical producers and retained bytes, never old-input freshness. */
final class DeliveryMembers
{
    /** Select the latest native producer mentioning each exact path; never choose by successful outcome.
     * @param list<Event>  $events
     * @param list<string> $paths
     *
     * @return array<string,string>
     */
    public static function workspaces(array $events, array $paths): array
    {
        $found = [];
        foreach ($events as $event) {
            $p = $event->payload;
            if ($event->type === 'session.trial_run_recorded' && is_array($p['report'] ?? null)) {
                foreach ($paths as $path) {
                    if (array_key_exists($path, $p['report'])) {
                        $found[$path] = is_string($p['workspace'] ?? null) ? $p['workspace'] : '';
                    }
                }
            }
            if ($event->type === 'session.tool_called' && in_array($p['tool'] ?? null, ['edit', 'implement'], true)) {
                $result = is_string($p['result'] ?? null) ? json_decode($p['result'], true) : null;
                $path = $result['output']['file'] ?? null;
                if (is_string($path) && in_array($path, $paths, true)) {
                    $found[$path] = is_string($result['workspace'] ?? null) ? $result['workspace'] : '';
                }
            }
        }
        return $found;
    }

    /** Re-read every retained member, without treating its historical environment as current.
     * @param list<Event>         $events
     * @param array<string,mixed> $expectation
     *
     * @return list<array<string,mixed>>
     */
    public static function read(string $root, array $events, array $expectation): array
    {
        $paths = $expectation['expected']['members'];
        $workspaces = self::workspaces($events, $paths);
        $members = [];
        foreach ($paths as $path) {
            $member = CandidateState::retainedMember($root, $events, $workspaces[$path] ?? '');
            if (($member['state'] ?? null) !== 'retained_member' || ($member['artifact']['path'] ?? null) !== $path
                || ($member['evidence']['trialRunSeq'] ?? 0) <= $expectation['seq']) {
                throw new \UnexpectedValueException('A declared member lacks retained bytes from a native promoted producer after the expectation.');
            }
            $members[] = ['workspace' => $member['workspace'], 'artifact' => $member['artifact']] + $member['evidence'];
        }
        return $members;
    }
}
