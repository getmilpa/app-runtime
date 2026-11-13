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

    /** Bytes of a file read for its view: more than any share shows. */
    private const READ = 4 * self::PER_FILE;

    /** Bytes compared at a time when a modified file is set against the house's. */
    private const CHUNK = 65536;

    /** Bytes of a file read to tell whether it is text. */
    private const SNIFF = 8192;

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
        // TEXT IS SHOWN FIRST (the third independent review of decisions/0628, L-B): a file that is not text is still
        // shown, but after every text file and in a smaller share, so a promotion that carries images does not spend
        // on them the view of its code. Each file is judged once, by its first bytes.
        $isText = [];
        foreach ($diff as $path => $entry) {
            $isText[$path] = self::isText($workspace, $path, $entry);
        }
        $order = array_merge(array_keys(array_filter($isText)), array_keys(array_filter($isText, static fn (bool $text): bool => ! $text)));
        $left = self::IN_ALL;
        $shown = [];
        foreach ($order as $path) {
            $entry = $diff[$path];
            $text = self::shown($workspace, $path, $entry['status'], $entry['sha256'], min($left, $isText[$path] ? self::PER_FILE : self::PER_FILE_NOT_TEXT));
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

    /**
     * Whether a file reads as text: at most one byte in a hundred of its first {@see SNIFF} is a NUL or not UTF-8 (the
     * fourth and fifth independent reviews of decisions/0628, L4-2 and L5-2). One stray byte in a comment does not make
     * code an image; the count does not depend on how this PHP is configured to substitute bad bytes.
     *
     * @param array<string, mixed> $entry
     */
    private static function isText(TrialWorkspace $workspace, string $path, array $entry): bool
    {
        if ($entry['status'] === 'deleted' || is_link($workspace->copy . '/' . $path)) {
            return true;
        }
        $handle = @fopen($workspace->copy . '/' . $path, 'rb');
        $head = $handle === false ? false : fread($handle, self::SNIFF);
        if ($handle !== false) {
            fclose($handle);
        }
        if (! \is_string($head) || $head === '') {
            return true;
        }

        return self::badBytes($head) * 100 <= \strlen($head);
    }

    /** The NUL bytes of a text and the bytes of it that are not part of a well-formed UTF-8 character. */
    private static function badBytes(string $bytes): int
    {
        $wellFormed = '/[\x01-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]'
            . '|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}/';

        return \strlen((string) preg_replace($wellFormed, '', $bytes));
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
        $whole = $workspace->judgedBytes($path, $sha256);
        if ($whole === null) {
            return 'it changed while it was being read: ask again to see it';
        }
        if ($limit <= 0) {
            return 'not shown: the promotion is longer than what is shown at once, and its digest covers this file too';
        }
        // ONLY THE CHANGE IS REWRITTEN (the fifth and sixth independent reviews of decisions/0628, L5-1 and M6-1): an
        // added file is shown from its start, a modified one from the first line that differs to the last, wherever in
        // the file they are, and only that window — at most {@see READ} bytes a side — is ever rewritten for the view.
        $size = \strlen($whole);
        [$old, $new, $line, $cut] = $status === 'modified'
            ? self::changedWindow($whole, $workspace->root . '/' . $path)
            : ['', mb_strcut($whole, 0, self::READ, 'UTF-8'), 1, $size > self::READ];
        unset($whole);
        $note = '';
        if (! mb_check_encoding($old . $new, 'UTF-8')) {
            // Text with bytes that are not UTF-8 still runs: it is shown, with those bytes marked.
            [$old, $new] = [mb_scrub($old, 'UTF-8'), mb_scrub($new, 'UTF-8')];
            $note = "(not valid UTF-8: the bytes that are not are shown as ?)\n";
        }
        if (str_contains($old . $new, "\0")) {
            // A NUL hides nothing from the house: a file that carries one can still run (the first independent review
            // of decisions/0628, L6'). It is shown like any text, each NUL marked where it is.
            [$old, $new] = [str_replace("\0", self::NUL, $old), str_replace("\0", self::NUL, $new)];
            $note .= '(contains NUL bytes: each is shown as ' . self::NUL . ")\n";
        }
        $text = ($status === 'modified' ? "@@ line {$line} @@\n" . self::prefixed('-', self::lines($old)) : '')
            . self::prefixed('+', self::lines($new));

        // The code's own masked-looking text is marked before the view is measured, so the mark never grows it past
        // its bounds (the fourth independent review, L4-3).
        $text = $note . self::literalMarksMarked($text);
        if (! $cut && mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . "\n… the rest of this change is not shown ({$size} bytes in the file); the digest covers it";
    }

    /**
     * The lines a modified file changed: the house's file is compared with the trial's from both ends, a chunk at a
     * time and never read whole, and the window between the common start and the common end is widened to whole lines.
     *
     * @return array{0: string, 1: string, 2: int, 3: bool} what the house had there, what the trial has, the line the
     *                                                      window starts at, and whether a side was cut to READ
     */
    private static function changedWindow(string $after, string $beforePath): array
    {
        $handle = @fopen($beforePath, 'rb');
        $stat = $handle === false ? false : fstat($handle);
        if ($handle === false || $stat === false) {
            return ['', mb_strcut($after, 0, self::READ, 'UTF-8'), 1, \strlen($after) > self::READ];
        }
        $afterSize = \strlen($after);
        $beforeSize = (int) $stat['size'];
        $common = min($afterSize, $beforeSize);

        $prefix = 0;
        while ($prefix < $common) {
            $chunk = fread($handle, min(self::CHUNK, $common - $prefix));
            if (! \is_string($chunk) || $chunk === '') {
                break;
            }
            $equal = strspn(substr($after, $prefix, \strlen($chunk)) ^ $chunk, "\0");
            $prefix += $equal;
            if ($equal < \strlen($chunk)) {
                break;
            }
        }
        $suffix = 0;
        while ($suffix < $common - $prefix) {
            $length = min(self::CHUNK, $common - $prefix - $suffix);
            fseek($handle, $beforeSize - $suffix - $length);
            $chunk = fread($handle, $length);
            if (! \is_string($chunk) || \strlen($chunk) !== $length) {
                break;
            }
            $differs = substr($after, $afterSize - $suffix - $length, $length) ^ $chunk;
            $equal = $length - \strlen(rtrim($differs, "\0"));
            $suffix += $equal;
            if ($equal < $length) {
                break;
            }
        }

        // Widened to whole lines: back to the start of the first changed line, on to the end of the last.
        $start = $prefix === 0 ? 0 : (($newline = strrpos($after, "\n", $prefix - $afterSize - 1)) === false ? 0 : $newline + 1);
        $afterEnd = $afterSize - $suffix;
        if ($afterEnd > $start && $after[$afterEnd - 1] !== "\n") {
            $newline = strpos($after, "\n", $afterEnd);
            $afterEnd = $newline === false ? $afterSize : $newline + 1;
        }
        $beforeEnd = $beforeSize - ($afterSize - $afterEnd);

        fseek($handle, $start);
        $old = $beforeEnd > $start ? (string) fread($handle, min($beforeEnd - $start, self::READ + 4)) : '';
        fclose($handle);
        $new = substr($after, $start, min($afterEnd - $start, self::READ + 4));
        $cut = $beforeEnd - $start > self::READ || $afterEnd - $start > self::READ;

        return [
            mb_strcut($old, 0, self::READ, 'UTF-8'),
            mb_strcut($new, 0, self::READ, 'UTF-8'),
            substr_count($after, "\n", 0, $start) + 1,
            $cut,
        ];
    }

    /** @return list<string> the lines of a text, without the empty one after its last newline */
    private static function lines(string $text): array
    {
        return $text === '' ? [] : explode("\n", str_ends_with($text, "\n") ? substr($text, 0, -1) : $text);
    }

    /** @param list<string> $lines */
    private static function prefixed(string $mark, array $lines): string
    {
        return implode('', array_map(static fn (string $line): string => $mark . $line . "\n", $lines));
    }
}
