<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AppRuntime\Config\SecretRedaction;
use Milpa\EventStore\Event;

/**
 * What a promotion of a trial would land in the house, as a person reads it before saying yes (greenhouse
 * decisions/0628).
 *
 * Code an agent wrote boots in the live house with the house's secrets once it is promoted. Applying it is the
 * governed act, so a person says yes to it, also in `auto`. The person says yes to these bytes, not to a workspace
 * name: the yes carries {@see digest()}, and a trial that changes after it is shown gets another digest and is asked
 * again.
 *
 * The person is shown each changed file and what the house recorded of the trial's own run ({@see judged()}).
 * What is shown is bounded. The digest always covers every byte, including the ones not shown.
 */
final class WhatAPromotionLands
{
    /** Characters of one file shown to the person; the rest is counted, never dropped silently. */
    private const PER_FILE = 6000;

    /** Characters shown across all files of one promotion. */
    private const IN_ALL = 24000;

    /**
     * The digest of every change a trial would land: the same one the ceiling receipt attests
     * ({@see TrialRouter::subjectAttestationFor()}), so the yes and the receipt name the same content.
     */
    public static function digest(TrialWorkspace $workspace): string
    {
        return 'diff:' . hash('sha256', (string) json_encode($workspace->diff(), \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Each changed file, with what a person reads of it: the whole of an added file, the changed lines of a
     * modified one, and why nothing is shown when nothing can be.
     *
     * @return list<array{path: string, status: string, sha256: ?string, shown: string}>
     */
    public static function forAPerson(TrialWorkspace $workspace, ?string $houseRoot): array
    {
        $left = self::IN_ALL;
        $out = [];
        foreach ($workspace->diff() as $path => $entry) {
            $shown = self::shown($workspace, $path, $entry['status'], $entry['sha256'], $left);
            $left = max(0, $left - mb_strlen($shown));
            $out[] = [
                'path' => $path,
                'status' => $entry['status'],
                'sha256' => $entry['sha256'],
                'shown' => SecretRedaction::inText($shown, $houseRoot),
            ];
        }

        return $out;
    }

    /**
     * What the house recorded of the trial before anyone promotes it: its own run (the operation, how it exited,
     * the digest of what it printed) and what the call that produced it said it verified. Read from the session's
     * record, never from the model.
     *
     * @param list<mixed> $events
     *
     * @return array<string, mixed>
     */
    public static function judged(array $events, string $workspace): array
    {
        $run = null;
        $producer = null;
        foreach ($events as $event) {
            if (! $event instanceof Event) {
                continue;
            }
            if ($event->type === 'session.trial_run_recorded' && ($event->payload['workspace'] ?? null) === $workspace) {
                $run = $event->payload;
            }
            if ($event->type === 'session.tool_called' && \is_string($event->payload['result'] ?? null)) {
                $result = json_decode($event->payload['result'], true);
                if (\is_array($result) && ($result['workspace'] ?? null) === $workspace && ($result['ran_in_trial'] ?? null) === true) {
                    $producer = ['tool' => $event->payload['tool'] ?? null, 'result' => $result];
                }
            }
        }

        $judged = [];
        if ($run !== null) {
            $judged['trial_run'] = array_filter([
                'operation' => $run['operation'] ?? null,
                'exit' => $run['exit'] ?? null,
                'output_digest' => $run['output_digest'] ?? null,
            ], static fn (mixed $v): bool => $v !== null);
        }
        if ($producer !== null) {
            $output = \is_array($producer['result']['output'] ?? null) ? $producer['result']['output'] : [];
            $judged['producer'] = array_filter([
                'tool' => $producer['tool'],
                'ok' => $output['ok'] ?? null,
                'verified' => \is_string($output['verified'] ?? null) ? mb_substr($output['verified'], 0, 600) : null,
                'house_boots' => $output['house_boots'] ?? null,
                'boot_room' => \is_string($output['boot_room'] ?? null) ? $output['boot_room'] : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $judged === [] ? ['nothing' => 'the session holds no record of this trial\'s run'] : $judged;
    }

    private static function shown(TrialWorkspace $workspace, string $path, string $status, ?string $sha256, int $left): string
    {
        if ($status === 'deleted') {
            return 'deleted';
        }
        if (is_link($workspace->copy . '/' . $path)) {
            return 'a symbolic link: a promotion does not carry it';
        }
        $after = $workspace->judgedBytes($path, $sha256);
        if ($after === null) {
            return 'it changed while it was being read: ask again to see it';
        }
        $note = '';
        if (! mb_check_encoding($after, 'UTF-8')) {
            if (str_contains($after, "\0")) {
                return 'binary, ' . \strlen($after) . ' bytes';
            }
            // Text with bytes that are not UTF-8 still runs: it is shown, with those bytes marked.
            $after = mb_scrub($after, 'UTF-8');
            $note = "(not valid UTF-8: the bytes that are not are shown as ?)\n";
        }
        if ($left <= 0) {
            return 'not shown: the promotion is longer than what is shown at once, and its digest covers this file too';
        }

        $before = $status === 'modified' ? @file_get_contents($workspace->root . '/' . $path) : false;
        $text = \is_string($before) && mb_check_encoding($before, 'UTF-8') && $note === ''
            ? self::changedLines($before, $after)
            : self::prefixed('+', explode("\n", $after));

        $text = $note . $text;
        $limit = min(self::PER_FILE, $left);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . "\n… " . (mb_strlen($text) - $limit) . ' more characters not shown; the digest covers them';
    }

    /** The lines between the common start and the common end of two texts, as `-` and `+` lines. */
    private static function changedLines(string $before, string $after): string
    {
        $old = explode("\n", $before);
        $new = explode("\n", $after);
        $start = 0;
        while ($start < \count($old) && $start < \count($new) && $old[$start] === $new[$start]) {
            ++$start;
        }
        $endOld = \count($old) - 1;
        $endNew = \count($new) - 1;
        while ($endOld >= $start && $endNew >= $start && $old[$endOld] === $new[$endNew]) {
            --$endOld;
            --$endNew;
        }

        return '@@ line ' . ($start + 1) . " @@\n"
            . self::prefixed('-', \array_slice($old, $start, $endOld - $start + 1))
            . self::prefixed('+', \array_slice($new, $start, $endNew - $start + 1));
    }

    /** @param list<string> $lines */
    private static function prefixed(string $mark, array $lines): string
    {
        return implode('', array_map(static fn (string $line): string => $mark . $line . "\n", $lines));
    }
}
