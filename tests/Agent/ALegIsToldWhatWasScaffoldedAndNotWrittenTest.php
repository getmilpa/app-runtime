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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\RunContext;
use Milpa\AppRuntime\Agent\ScaffoldsNotWritten;
use Milpa\EventStore\Event;
use PHPUnit\Framework\TestCase;

/**
 * A leg is told which operations its session scaffolded and has not written yet (an experiment of greenhouse
 * decisions/0604 — held, not decided).
 *
 * A leg that inherits a fold is not told what was left half done. The fold keeps the session's own plan and todos,
 * and of a call only a few names: that an operation was scaffolded and its body never written is nowhere. Measured
 * (evidence/1164): in the legs that followed one that ran out of window, 15 of 31 reads were of something an earlier
 * leg had already read.
 *
 * The house can say it from the session's own record, with no model and no list: a scaffold of an operation the
 * session made, with no authoring call on that class after it. It is said as OBSERVED DATA in the run context a leg
 * already receives, and only when there is something to say.
 *
 * @guards the operations a session scaffolded and has no later successful authoring call for, by plugin and class,
 *         in the order they were scaffolded; the run context carrying them, with one sentence that says what they are
 *
 * @refuses a scaffold that failed; a scaffold of anything that is not an operation; an authoring call that failed
 *          as if it had written; another session's calls; saying anything when nothing is left — the section is then
 *          byte for byte what it was
 *
 * @subject-in milpa/app-runtime
 */
final class ALegIsToldWhatWasScaffoldedAndNotWrittenTest extends TestCase
{
    public function testWhatWasScaffoldedAndNotWrittenIsNamedInTheOrderItWasScaffolded(): void
    {
        $events = [
            self::make(3, 'Lend'), self::make(5, 'GiveBack'), self::make(7, 'Register'),
            self::wrote(9, 'implement', 'GiveBack'),
        ];

        self::assertSame(
            [['plugin' => 'Loans', 'class' => 'Lend', 'scaffolded_at' => 3], ['plugin' => 'Loans', 'class' => 'Register', 'scaffolded_at' => 7]],
            ScaffoldsNotWritten::of($events, 's'),
        );
    }

    public function testAnEditWritesItTooAndWritingEverythingLeavesNothing(): void
    {
        $events = [self::make(3, 'Lend'), self::make(5, 'GiveBack'), self::wrote(7, 'edit', 'Lend'), self::wrote(9, 'implement', 'GiveBack')];

        self::assertSame([], ScaffoldsNotWritten::of($events, 's'));
    }

    public function testOnlyWhatHappenedCounts(): void
    {
        $events = [
            self::make(3, 'Lend'),
            self::wrote(5, 'implement', 'Lend', ok: false),
            self::call(7, 'make', ['what' => 'operation', 'plugin' => 'Loans', 'name' => 'Refused'], ok: false),
            self::call(9, 'make', ['what' => 'entity', 'plugin' => 'Loans', 'name' => 'Tool']),
            self::call(11, 'make', ['what' => 'page', 'plugin' => 'Loans', 'name' => 'Home']),
            self::call(13, 'make', ['what' => 'operation', 'plugin' => 'Loans']),
            self::wrote(15, 'implement', 'Lend', plugin: 'Other'),
            self::call(17, 'source_read', ['plugin' => 'Loans', 'class' => 'Lend']),
            // A fact of another kind that happens to be shaped like a call is not a call the session made.
            new Event(SessionStore::PREFIX . 's', 'session.operation_executed', ['tool' => 'make', 'arguments' => ['what' => 'operation', 'plugin' => 'Loans', 'name' => 'Shadow'], 'ok' => true], 19),
        ];

        self::assertSame([['plugin' => 'Loans', 'class' => 'Lend', 'scaffolded_at' => 3]], ScaffoldsNotWritten::of($events, 's'), 'a failed authoring wrote nothing; a failed scaffold made nothing; other kinds are not operations; another plugin\'s class is another class');
    }

    public function testWhatWasWrittenBeforeBeingScaffoldedAgainIsNotWritten(): void
    {
        $events = [self::make(3, 'Lend'), self::wrote(5, 'implement', 'Lend'), self::make(7, 'Lend')];

        self::assertSame([['plugin' => 'Loans', 'class' => 'Lend', 'scaffolded_at' => 7]], ScaffoldsNotWritten::of($events, 's'), 'the scaffold came after the writing: the class is a scaffold again');
        self::assertSame(
            [['plugin' => 'Loans', 'class' => 'GiveBack', 'scaffolded_at' => 5], ['plugin' => 'Loans', 'class' => 'Lend', 'scaffolded_at' => 7]],
            ScaffoldsNotWritten::of([self::make(3, 'Lend'), self::make(5, 'GiveBack'), self::make(7, 'Lend')], 's'),
            'scaffolded twice, it is the later scaffold that stands: at its moment, and in its place',
        );
    }

    public function testAnotherSessionsCallsAreNotThisSessions(): void
    {
        $other = new Event(SessionStore::PREFIX . 'other', 'session.tool_called', ['tool' => 'make', 'arguments' => ['what' => 'operation', 'plugin' => 'Loans', 'name' => 'Lend'], 'ok' => true], 3);
        $written = new Event(SessionStore::PREFIX . 'other', 'session.tool_called', ['tool' => 'implement', 'arguments' => ['plugin' => 'Loans', 'class' => 'GiveBack'], 'ok' => true], 9);

        self::assertSame([], ScaffoldsNotWritten::of([$other], 's'));
        self::assertCount(1, ScaffoldsNotWritten::of([self::make(5, 'GiveBack'), $written], 's'), 'and another session writing it does not write it here');
    }

    public function testTheRunContextCarriesItAsObservedDataAndSaysWhatItIs(): void
    {
        $section = RunContext::section([self::make(3, 'Lend'), self::make(5, 'GiveBack'), self::wrote(7, 'implement', 'Lend')], 's', 8, ['implement'], ['active' => false, 'result_readers' => []]);

        preg_match('#<run-context>\n(.*)\n</run-context>#s', $section, $found);
        $data = json_decode($found[1] ?? '', true);
        self::assertSame([['plugin' => 'Loans', 'class' => 'GiveBack', 'scaffolded_at' => 5]], $data['scaffolded_not_written'] ?? null);
        self::assertStringContainsString('scaffolded_not_written', explode('<run-context>', $section)[0], 'the sentence says what the key is');
        self::assertStringContainsString('body', explode('<run-context>', $section)[0]);
    }

    public function testWithNothingLeftTheSectionIsByteForByteWhatItWas(): void
    {
        $nothing = RunContext::section([], 's', 8, ['implement'], ['active' => false, 'result_readers' => []]);
        $written = RunContext::section([self::make(3, 'Lend'), self::wrote(5, 'implement', 'Lend')], 's', 8, ['implement'], ['active' => false, 'result_readers' => []]);

        self::assertSame($nothing, $written);
        self::assertStringNotContainsString('scaffolded_not_written', $nothing);
    }

    private static function make(int $seq, string $class): Event
    {
        return self::call($seq, 'make', ['what' => 'operation', 'plugin' => 'Loans', 'name' => $class, 'entity' => 'Tool']);
    }

    private static function wrote(int $seq, string $tool, string $class, bool $ok = true, string $plugin = 'Loans'): Event
    {
        return self::call($seq, $tool, ['plugin' => $plugin, 'class' => $class, 'content' => '<?php // …'], $ok);
    }

    /** @param array<string, mixed> $arguments */
    private static function call(int $seq, string $tool, array $arguments, bool $ok = true): Event
    {
        return new Event(SessionStore::PREFIX . 's', 'session.tool_called', ['tool' => $tool, 'arguments' => $arguments, 'result' => '{}', 'ok' => $ok], $seq);
    }
}
