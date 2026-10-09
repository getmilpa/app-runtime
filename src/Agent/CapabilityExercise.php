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

use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionStore;
use Milpa\Command\Operation;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * The house RUNNING a capability it is about to close on (greenhouse decisions/0605, R1).
 *
 * ── THE DEBT THIS PAYS, MEASURED (greenhouse evidence/1166) ─────────────────────────────────────
 *
 * Twenty houses a build station left. The house closed six `verified`; in one, three of its four operations call a
 * method that does not exist and fail the first time anyone uses them. The closure had read what the capability
 * declares and called nothing ({@see HouseObservedClosure}, decisions/0595) — and said so: `exercised: "unjudged"`.
 * Meanwhile the four sessions that tried to call what they had just built were stopped at the frontier. The house gave
 * «verified» to the session that did not run its work and stopped the one that wanted to.
 *
 * ── WHAT IT DOES ────────────────────────────────────────────────────────────────────────────────
 *
 * In ONE trial — a copy, no network, the ceiling every trial has ({@see TrialRunner}) — it runs every operation the
 * capability declares, in catalogue order, twice: the second pass finds what only fails once something exists. Each
 * call is a process of its own, as a call is; each required input gets one value of its declared type, read from the
 * declaration as it is in the copy ({@see inputFor()}). An operation is read by its worst answer: it ANSWERED, it
 * REFUSED (in its own words), or it THREW — something the runner had to catch, a process that ended without its
 * answer, or a call the ceiling had to stop. The copy is discarded; it never was a trial anyone could list, open or
 * promote ({@see TrialWorkspace::forExercise()}).
 *
 * Nobody grants this: it is an act of the house, in a trial. It spends no seat's scope and writes nothing in the house.
 *
 * ── WHAT IT LEAVES, AND WHO READS IT ────────────────────────────────────────────────────────────
 *
 * A receipt in the session's own stream ({@see EVENT}), bound to the declaration it ran. The verdict stays what it was
 * — derived from the stream alone ({@see HouseObservedClosure}) — and reads the receipt wherever it is read: at the
 * natural end, and between steps, where nothing is ever run. So what threw STANDS: the next leg does not open its
 * epilogue over it, and the session can repair it. And it stands only for the house it ran on: any change that lands
 * later takes the receipt back, `ran` and `threw` alike, and what is declared then is run when the house is about to
 * close on it.
 *
 * ── WHAT IT DOES NOT PROVE ──────────────────────────────────────────────────────────────────────
 *
 * That the capability is CORRECT. `exercised` goes from `unjudged` to `ran` or `threw` and never further: an operation
 * that runs and does the wrong thing ran. Only who compares states sees that, and the receipt says so beside the
 * verdict — `behavior: "unjudged"`. And a house that cannot confine a process runs nothing: it says `unjudged` and why,
 * and closes as it did. It never runs a session's code unconfined to find out.
 */
final class CapabilityExercise
{
    /** The fact appended when the house ran a capability — outside {@see SessionEvent}, as the verdict is. */
    public const EVENT = 'session.capability_exercised';

    /** How many times each operation is run: the second finds what only fails once something exists. */
    public const PASSES = 2;

    /** The seconds one call may take before the house stops it. */
    public const CEILING = 10;

    /** Past this many characters the first line of what was thrown is cut. */
    private const LINE = 200;

    /** Past this many, the operations that threw are counted in the reason and not named. */
    private const NAMED = 4;

    /**
     * Run a capability once, in a trial that is discarded.
     *
     * @param list<string> $operations the operations it declares, by the name each one declares
     * @param string       $runnerPath the script a call runs with, from the copy it is placed in (`exercise-run.php`)
     *
     * @return array{exercised: 'ran'|'threw'|'unjudged', why?: string, operations: int, calls: int, answered?: int, refused?: int, threw?: int, answers?: array<string, list<string>>, thrown?: list<array{operation: string, class: string, kind: 'engine'|'app'|'fatal'|'timeout', line: string, pass: int}>, seconds?: float, bounds?: array<string, string>}
     */
    public static function of(string $root, string $capability, array $operations, TrialRunner $runner, string $runnerPath, int $ceiling = self::CEILING): array
    {
        $operations = array_values(array_unique($operations));
        sort($operations);
        $unjudged = static fn (string $why, int $calls = 0): array => ['exercised' => 'unjudged', 'why' => $why, 'operations' => \count($operations), 'calls' => $calls];
        if ($operations === []) {
            return $unjudged("the house finds no operation of «{$capability}» to run");
        }
        // FAIL CLOSED (greenhouse decisions/0069 §9): no sandbox, no trial — and no exercise.
        if (! $runner->available()) {
            return $unjudged('this house cannot confine a process');
        }
        $started = microtime(true);
        try {
            $copy = TrialWorkspace::forExercise($root, bin2hex(random_bytes(8)), $runnerPath);
        } catch (\Throwable $e) {
            return $unjudged('the house could not make the copy to run it in: ' . self::line($e->getMessage()));
        }
        $runner = $runner->within($ceiling);
        $answers = [];
        $thrown = [];
        $stopped = [];
        $missing = null;
        $calls = 0;
        try {
            for ($pass = 1; $pass <= self::PASSES; ++$pass) {
                foreach ($operations as $name) {
                    if (isset($stopped[$name])) {
                        continue;
                    }
                    ++$calls;
                    [$answer, $what] = self::read($runner->run($copy, $name, []), $ceiling);
                    $answers[$name][] = $answer;
                    if ($answer === 'not found') {
                        $missing ??= $name;
                        $stopped[$name] = true;
                    } elseif ($what !== null) {
                        $thrown[$name] ??= ['operation' => $name] + $what + ['pass' => $pass];
                        // An operation the ceiling had to stop is not waited for a second time.
                        $stopped[$name] = $what['class'] === 'timeout' ? true : null;
                    }
                }
            }
        } catch (\Throwable $e) {
            return $unjudged('the house could not run it: ' . self::line($e->getMessage()), $calls);
        } finally {
            $copy->discard();
            // Nothing of it stays: the directory the copies live in goes with the last of them.
            @rmdir(rtrim($root, '/') . '/var/exercises');
        }

        $worst = array_map(static fn (array $said): string => \in_array('threw', $said, true) ? 'threw' : (\in_array('refused', $said, true) ? 'refused' : $said[0]), $answers);
        $counts = array_count_values($worst) + ['answered' => 0, 'refused' => 0, 'threw' => 0];
        $exercised = match (true) {
            $thrown !== [] => 'threw',
            $missing !== null => 'unjudged',
            default => 'ran',
        };

        return ['exercised' => $exercised]
            + ($exercised === 'unjudged' ? ['why' => "the trial runner found no operation «{$missing}»"] : [])
            + ['operations' => \count($operations), 'calls' => $calls, 'answered' => $counts['answered'], 'refused' => $counts['refused'], 'threw' => $counts['threw'],
                'answers' => $answers, 'thrown' => array_values($thrown), 'seconds' => round(microtime(true) - $started, 1), 'bounds' => $runner->bounds()];
    }

    /**
     * How one call answered, as the runner printed it — and, when it threw, what.
     *
     * Read from the FIELDS the runner writes (`thrown`, `missing`), never from a sentence: an operation that refuses
     * with «Validation: name is required» refused; it did not throw a class named Validation.
     *
     * WHAT WAS THROWN HAS A KIND, so it can be watched by kind and not by reading it: `engine` — an `\Error`, a defect
     * no input excuses; `app` — any other exception, which an operation may mean as a refusal; and two that are not
     * exceptions at all: `fatal` — the process ended without the runner's answer — and `timeout` — the house had to
     * stop it. All four stop the closure (decided by Rod, decisions/0605).
     *
     * @return array{0: 'answered'|'refused'|'threw'|'not found', 1: array{class: string, kind: 'engine'|'app'|'fatal'|'timeout', line: string}|null}
     */
    private static function read(TrialRun $run, int $ceiling): array
    {
        if ($run->exit === 124 || $run->exit === 137) {
            return ['threw', ['class' => 'timeout', 'kind' => 'timeout', 'line' => "no answer within {$ceiling} s: the house stopped it"]];
        }
        $said = $run->output;
        if ($said === null) {
            // A fatal that is no Throwable, or an exit: the process ended and the runner never printed its answer.
            $last = self::line($run->stderr) ?: self::line($run->stdout);

            return ['threw', ['class' => 'fatal', 'kind' => 'fatal', 'line' => $last !== '' ? $last : "the process ended with exit {$run->exit} and no answer"]];
        }
        if (($said['missing'] ?? null) === true) {
            return ['not found', null];
        }
        $thrown = $said['thrown'] ?? null;
        if (\is_array($thrown) && \is_string($thrown['class'] ?? null) && $thrown['class'] !== '') {
            $error = \is_string($said['error'] ?? null) ? $said['error'] : '';
            $message = str_starts_with($error, $thrown['class'] . ': ') ? substr($error, \strlen($thrown['class']) + 2) : $error;

            return ['threw', ['class' => $thrown['class'], 'kind' => ($thrown['engine'] ?? null) === true ? 'engine' : 'app', 'line' => self::line($message)]];
        }
        // «No error», not a literal `ok: true`: most operations answer data and no verdict (greenhouse evidence/0272).
        $refused = (\array_key_exists('ok', $said) && $said['ok'] !== true) || \array_key_exists('error', $said);

        return [$refused ? 'refused' : 'answered', null];
    }

    /**
     * The input the house calls an operation with: one value of its declared type per REQUIRED input — the first of
     * its `enum` when it has one — and nothing else. It is invented, not meant: what an operation makes of it is the
     * operation's to answer or refuse. Read where the call is made, from the declaration as it is there
     * (`resources/exercise-run.php`): an object is the array a handler is handed for one.
     *
     * @return array<string, mixed>
     */
    public static function inputFor(Operation $operation): array
    {
        $schema = $operation->inputSchema ?? [];
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $input = [];
        foreach (\is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            if (! \is_string($name)) {
                continue;
            }
            $declared = \is_array($properties[$name] ?? null) ? $properties[$name] : [];
            $type = $declared['type'] ?? null;
            $type = \is_array($type) ? (array_values(array_diff($type, ['null']))[0] ?? null) : $type;
            $input[$name] = match (true) {
                \is_array($declared['enum'] ?? null) && $declared['enum'] !== [] => array_values($declared['enum'])[0],
                $type === 'boolean' => true,
                $type === 'integer', $type === 'number' => 7,
                $type === 'array', $type === 'object' => [],
                default => '7',
            };
        }

        return $input;
    }

    /**
     * The operations a promotion's receipt says a capability declared — the declaration the house is about to close
     * on, by the seq of that promotion. Read from the stream: the leg's own process booted before any of it landed.
     *
     * @param list<Event> $stream
     *
     * @return list<string>
     */
    public static function declaredAt(array $stream, string $capability, int $seq): array
    {
        foreach ($stream as $event) {
            if ($event->seq !== $seq || $event->type !== SessionEvent::ToolCalled->value) {
                continue;
            }
            $result = json_decode(\is_string($event->payload['result'] ?? null) ? $event->payload['result'] : '', true);
            foreach (\is_array($result) && \is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [] as $entry) {
                if (\is_array($entry) && ($entry['subject'] ?? null) === $capability) {
                    $names = array_column(array_filter(\is_array($entry['operations'] ?? null) ? $entry['operations'] : [], 'is_array'), 'name');

                    return array_values(array_filter($names, static fn (mixed $name): bool => \is_string($name) && $name !== ''));
                }
            }
        }

        return [];
    }

    /**
     * The verdict of a leg at its natural end, with the capability it is about to close on RUN first.
     *
     * Only when the verdict would say `verified` over a capability the house has not run — and has not already tried
     * to: one exercise per declaration, whatever it found. The receipt goes into the stream and the verdict is derived
     * again from it. Never called between steps.
     *
     * @param \Closure(): array<string, mixed>                   $derive   the leg's verdict, from the stream as it stands
     * @param \Closure(string, int): (array<string, mixed>|null) $exercise runs a capability as it was declared at a seq, or
     *                                                                     answers null: this house has nothing to run it with
     *
     * @return array<string, mixed>
     */
    public static function atTheEnd(EventStoreInterface $events, string $sessionId, \Closure $derive, \Closure $exercise): array
    {
        $closure = $derive();
        $owed = self::owedBy($closure);
        if ($owed === null) {
            return $closure;
        }
        $receipt = $exercise($owed['subject'], $owed['seq']);
        if ($receipt === null) {
            return $closure;
        }
        self::record($events, $sessionId, $owed, $receipt);

        return $derive();
    }

    /**
     * The capability a verdict is about to close on without the house having run it — or null.
     *
     * @param array<string, mixed> $closure
     *
     * @return array{subject: string, seq: int, lastChangeSeq: ?int}|null
     */
    public static function owedBy(array $closure): ?array
    {
        $observation = \is_array($closure['derivedFrom'] ?? null) ? ($closure['derivedFrom']['observation'] ?? null) : null;
        if (($closure['verified'] ?? null) !== true || ! \is_array($observation) || ! \is_array($observation['capability'] ?? null)
            || ! \is_string($observation['subject'] ?? null) || ! \is_int($observation['seq'] ?? null)) {
            return null;
        }
        // `why` beside `unjudged` is the house having tried and said what stopped it: it does not try twice.
        if (($observation['capability']['exercised'] ?? null) !== 'unjudged' || isset($observation['capability']['why'])) {
            return null;
        }

        $lastChange = $closure['derivedFrom']['lastChangeSeq'] ?? null;

        return ['subject' => $observation['subject'], 'seq' => $observation['seq'], 'lastChangeSeq' => \is_int($lastChange) ? $lastChange : null];
    }

    /**
     * Append the receipt to the session's own stream, through the raw store as the verdict is
     * ({@see ClosureVerdict::record()}): the reducer skips a fact it does not know, so the session folds unchanged.
     *
     * It says what was run and ON WHAT: the declaration, and the last change the house had taken when it ran. A change
     * that lands later takes the receipt back, whatever it said ({@see HouseObservedClosure}).
     *
     * It is the HOUSE's receipt: it names no seat, it is not a tool call, a trial of the session's or work in the
     * domain, and it moves nothing any reader of those counts.
     *
     * @param array{subject: string, seq: int, lastChangeSeq?: ?int} $declaration the declaration that was run
     * @param array<string, mixed>                                   $exercise    what {@see of()} answered
     */
    public static function record(EventStoreInterface $events, string $sessionId, array $declaration, array $exercise): void
    {
        $events->append(new Event(
            streamId: SessionStore::PREFIX . $sessionId,
            type: self::EVENT,
            payload: ['subject' => $declaration['subject'], 'observation' => $declaration['seq'], 'last_change' => $declaration['lastChangeSeq'] ?? null] + $exercise,
            seq: $events->nextSeq(),
        ));
    }

    /**
     * Why a capability the house ran is not whole — said to the session, which wrote it: the operation, the class of
     * what was thrown and its first line.
     *
     * @param array<string, mixed> $receipt the payload of a receipt that says `threw`
     */
    public static function whyNotWhole(array $receipt): string
    {
        $thrown = array_values(array_filter(\is_array($receipt['thrown'] ?? null) ? $receipt['thrown'] : [], 'is_array'));
        $of = \is_int($receipt['operations'] ?? null) ? $receipt['operations'] : \count($thrown);
        $named = [];
        foreach (\array_slice($thrown, 0, self::NAMED) as $one) {
            $named[] = sprintf(
                '«%s» threw %s: %s',
                \is_string($one['operation'] ?? null) ? $one['operation'] : '?',
                \is_string($one['class'] ?? null) ? $one['class'] : '?',
                self::line(\is_string($one['line'] ?? null) ? $one['line'] : ''),
            );
        }
        if (\count($thrown) > self::NAMED) {
            $named[] = 'and ' . (\count($thrown) - self::NAMED) . ' more';
        }

        return sprintf(
            'the house ran «%s» in a trial before closing on it (seq %d) and %d of its %d operation%s threw: %s — an operation that throws is not whole: fix it with implement',
            \is_string($receipt['subject'] ?? null) ? $receipt['subject'] : '?',
            \is_int($receipt['observation'] ?? null) ? $receipt['observation'] : 0,
            \count($thrown),
            $of,
            $of === 1 ? '' : 's',
            implode('; ', $named),
        );
    }

    /** The first line of a text that has any, cut where a reason stops being one. */
    private static function line(string $text): string
    {
        $first = trim(explode("\n", ltrim($text), 2)[0]);

        return mb_strlen($first) > self::LINE ? mb_substr($first, 0, self::LINE) . '…' : $first;
    }
}
