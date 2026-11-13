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

    /** Characters of one file that is not text: it is shown, in a smaller share, after every text file. */
    private const PER_FILE_NOT_TEXT = 1500;

    /** What follows a masked-value mark the code itself carries, so it is not read as a masked value. */
    private const LITERAL = '(literal text of the file)';

    /** How a NUL byte is shown to the person: visible, and not a byte any file carries by accident. */
    private const NUL = '␀';

    /**
     * The digest of every change a trial would land: the same one the ceiling receipt attests
     * ({@see TrialRouter::subjectAttestationFor()}), so the yes and the receipt name the same content.
     */
    public static function digest(TrialWorkspace $workspace): string
    {
        return self::digestOf($workspace->diff());
    }

    /**
     * The same digest, of a diff already read: the promotion compares the diff it is about to copy against the one a
     * person said yes to, without reading the trial a second time.
     *
     * @param array<string, mixed> $diff
     */
    public static function digestOf(array $diff): string
    {
        return 'diff:' . hash('sha256', (string) json_encode($diff, \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Each changed file, with what a person reads of it: the whole of an added file, the changed lines of a
     * modified one, and why nothing is shown when nothing can be.
     *
     * @return list<array{path: string, status: string, sha256: ?string, shown: string}>
     */
    public static function forAPerson(TrialWorkspace $workspace, ?string $houseRoot): array
    {
        $diff = $workspace->diff();
        // TEXT IS SHOWN FIRST (the third independent review of decisions/0628, L-B): a file that is not text — a NUL in
        // it, or bytes that are not UTF-8 — is still shown, but after every text file and in a smaller share, so a
        // promotion that carries images does not spend on them the view of its code.
        $order = array_merge(
            array_keys(array_filter($diff, static fn (array $entry, string $path): bool => self::isText($workspace, $path, $entry), \ARRAY_FILTER_USE_BOTH)),
            array_keys(array_filter($diff, static fn (array $entry, string $path): bool => ! self::isText($workspace, $path, $entry), \ARRAY_FILTER_USE_BOTH)),
        );
        $left = self::IN_ALL;
        $shown = [];
        foreach ($order as $path) {
            $entry = $diff[$path];
            $limit = self::isText($workspace, $path, $entry) ? self::PER_FILE : self::PER_FILE_NOT_TEXT;
            $text = self::shown($workspace, $path, $entry['status'], $entry['sha256'], min($left, $limit));
            $left = max(0, $left - mb_strlen($text));
            $shown[$path] = $text;
        }

        $out = [];
        foreach ($diff as $path => $entry) {
            $out[] = [
                'path' => $path,
                'status' => $entry['status'],
                'sha256' => $entry['sha256'],
                'shown' => SecretRedaction::inText($shown[$path], $houseRoot),
            ];
        }

        return $out;
    }

    /**
     * A text the code itself carries that reads like a masked value is marked as the code's own (the third independent
     * review of decisions/0628, L-C): otherwise a change that writes it in place of a value the person cannot see reads
     * as no change at all. It tells a person what the code carries; it is not a seal: a file that already holds a
     * secret's value can be written to read the same, and holding that value is what the redaction keeps from the
     * model.
     */
    private static function literalMarksMarked(string $text): string
    {
        return str_replace(SecretRedaction::REDACTED, SecretRedaction::REDACTED . self::LITERAL, $text);
    }

    /** @param array<string, mixed> $entry */
    private static function isText(TrialWorkspace $workspace, string $path, array $entry): bool
    {
        if ($entry['status'] === 'deleted' || is_link($workspace->copy . '/' . $path)) {
            return true;
        }
        $bytes = @file_get_contents($workspace->copy . '/' . $path);
        if (! \is_string($bytes) || $bytes === '') {
            return true;
        }
        // BY THE SHARE OF BYTES THAT ARE NOT TEXT, NOT BY ONE (the fourth independent review of decisions/0628, L4-2):
        // one NUL or one stray byte in a comment does not make code an image, nor buy it a smaller view.
        $scrubbed = mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        $bad = substr_count($bytes, "\0") + max(0, substr_count($scrubbed, '?') - substr_count($bytes, '?'));

        return $bad * 100 <= \strlen($bytes);
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

    private static function shown(TrialWorkspace $workspace, string $path, string $status, ?string $sha256, int $limit): string
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
            // Text with bytes that are not UTF-8 still runs: it is shown, with those bytes marked.
            $after = mb_scrub($after, 'UTF-8');
            $note = "(not valid UTF-8: the bytes that are not are shown as ?)\n";
        }
        if (str_contains($after, "\0")) {
            // A NUL hides nothing from the house: a file that carries one can still run (the first independent review
            // of decisions/0628, L6'). It is shown like any text, each NUL marked where it is.
            $after = str_replace("\0", self::NUL, $after);
            $note .= '(contains NUL bytes: each is shown as ' . self::NUL . ")\n";
        }
        if ($limit <= 0) {
            return 'not shown: the promotion is longer than what is shown at once, and its digest covers this file too';
        }

        $before = $status === 'modified' ? @file_get_contents($workspace->root . '/' . $path) : false;
        $text = \is_string($before) && mb_check_encoding($before, 'UTF-8') && $note === ''
            ? self::changedLines($before, $after)
            : self::prefixed('+', explode("\n", $after));

        // The code's own masked-looking text is marked before the view is measured, so the mark never grows it past
        // its bounds (the fourth independent review, L4-3).
        $text = $note . self::literalMarksMarked($text);
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
