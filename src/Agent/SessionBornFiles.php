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
use Milpa\EventStore\Event;

/**
 * Which files of the house a session's own record says it brought — and left as they are (greenhouse decisions/0596).
 *
 * The intent contract asks a person before an operation touches a target the request does not name. For `edit` that
 * question fell, 28 times of 34 in the record of the lab's houses, on a class the same session had brought into the
 * house a moment before: nothing the person already had was being selected. This is the reading that tells those
 * apart, for the session's floor to ask it:
 *
 * - a file was BORN in this session when the first thing a trial of this session that reached the house said of it is
 *   that it ADDED it;
 * - and it is STILL WHAT THE SESSION LEFT when its digest in the house is the one reported by the last trial of this
 *   session that touched it and reached the house.
 *
 * Both halves are facts the house wrote, never what a model says. `added` is computed by the house when the trial
 * ends, against what the house held at the instant of the copy ({@see TrialWorkspace}): a file that was there is
 * reported `modified` whatever the trial did to it, so nothing a seat can call turns a class that existed into one it
 * brought. The digest is the copy's. Whether a trial reached the house is {@see LandedCalls}' answer — the one reading
 * the closure and the claims already share — taken in the order the trials LANDED, which is not always the order they
 * ran in.
 *
 * It fails closed. A trial that never reached the house says nothing, even over identical bytes; a record that keeps
 * no digest cannot say the file is unchanged; a link is another resource; another session's record is not read. In
 * each of those the file is not one this session brought, and the question is asked as before.
 */
final class SessionBornFiles
{
    /** @var array<string, list<array{landed: int, status: string, sha256: ?string}>> path => what this session's landed trials said of it, in the order they landed */
    private array $said = [];

    private function __construct()
    {
    }

    /**
     * Read, from a session's own stream, what its trials that reached the house said of each file.
     *
     * @param list<Event> $stream the session's own stream
     */
    public static function of(array $stream): self
    {
        $self = new self();
        $calls = LandedCalls::of($stream);
        foreach ($stream as $event) {
            if ($event->type !== SessionEvent::TrialRunRecorded->value) {
                continue;
            }
            $workspace = $event->payload['workspace'] ?? null;
            $report = $event->payload['report'] ?? null;
            $landed = \is_string($workspace) ? $calls->carriedAt($workspace) : null;
            if ($landed === null) {
                continue;
            }
            foreach (\is_array($report) ? $report : [] as $path => $entry) {
                $status = \is_array($entry) ? ($entry['status'] ?? null) : null;
                $digest = \is_array($entry) ? ($entry['sha256'] ?? null) : null;
                $self->said[(string) $path][] = [
                    'landed' => $landed,
                    'status' => \is_string($status) ? $status : '',
                    'sha256' => \is_string($digest) ? $digest : null,
                ];
            }
        }
        foreach ($self->said as $path => $facts) {
            usort($facts, static fn (array $one, array $other): int => $one['landed'] <=> $other['landed']);
            $self->said[$path] = $facts;
        }

        return $self;
    }

    /**
     * Whether this session brought that file into the house, and the house still holds what the session left.
     *
     * @param string $path the file, relative to the house's root, as a trial's report spells it
     * @param string $file the same file in the house
     */
    public function broughtAndLeftAsItIs(string $path, string $file): bool
    {
        $facts = $this->said[$path] ?? [];
        if ($facts === [] || $facts[0]['status'] !== 'added') {
            return false;
        }
        $left = $facts[\count($facts) - 1]['sha256'];
        if ($left === null || is_link($file)) {
            return false;
        }

        return hash_equals($left, (string) hash_file('sha256', $file));
    }
}
