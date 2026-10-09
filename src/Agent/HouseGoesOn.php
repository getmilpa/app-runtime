<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Runtime\Config;

/**
 * AFTER A PAUSE FOR WINDOW OR FOR STEPS THE HOUSE GOES ON BY ITSELF, UNDER A WRITTEN CAP (greenhouse decisions/0604,
 * rule A; the cap decided by Rod on 2026-10-08).
 *
 * A leg that ran out of window, or of steps, ended, and somebody had to start it again: a person, or a conductor
 * spending one of its legs. Measured on the published stack: 7 of 11 building runs were cut by their steps and 2 by
 * their window (decisions/0604). The house now folds and goes on inside the same invocation — a second leg of the
 * same session, as it already resumes a turn that echoed its own voice (decisions/0475).
 *
 * THE CAP, AS ROD DECIDED IT: one continuation per invocation; sixty steps in all, counting those before the pause;
 * and also after the steps run out, not only the window. Sixty is one and a half times the forty decisions/0538 gave
 * a leg in `auto`: this is where an invocation may spend more than that record said, and the two numbers below are
 * the whole of it. The leg that follows is still a leg, and takes no more than a leg's own ceiling. A house may
 * declare other numbers (`agent.continuations`, `agent.invocationSteps`); none turns it off.
 *
 * WHAT IT NEVER GOES ON OVER: a pause that waits for a person — a refusal, a question, a confirmation —, a leg that
 * made no progress, a leg outside `auto`, and the ceiling a person typed for this invocation, which is the total.
 *
 * A PAUSE THE HOUSE CONTINUES IS NOT AN END. Nothing closes there; the closure, and the exercise of a capability that
 * goes before it (decisions/0605), belong to the leg that ends in an answer.
 */
final class HouseGoesOn
{
    /** How many times the house goes on by itself in one invocation (Rod, 2026-10-08). */
    public const CONTINUATIONS = 1;

    /** The steps one invocation may take in all, counting those before every pause (Rod, 2026-10-08). */
    public const STEPS_IN_ALL = 60;

    /** The two pauses the house goes on after: nobody is waited for, and the task is unfinished. */
    public const AFTER = ['steps_exhausted', 'context_budget_exhausted'];

    /** The fact a continuation leaves in the session's stream, before the leg that follows. */
    public const EVENT = 'session.leg_continued';

    /**
     * The steps the house gives the leg that follows — or null: it does not go on.
     *
     * THE LEG THAT FOLLOWS IS A LEG: it takes no more than a leg's own ceiling (decisions/0538: forty in `auto`), and
     * no more than what is left of the total. Both numbers were decided; neither is stretched to fit the other.
     *
     * @param array<string, mixed> $result  the result of the leg that just ended
     * @param mixed                $typed   the `steps` input of the invocation as it arrived: a ceiling a person typed is theirs
     * @param int                  $ceiling the steps a leg of this session takes ({@see LegWindow::steps()})
     * @param int                  $spent   the steps of every leg of this invocation, the one that just ended included
     * @param int                  $times   how many times the house already went on in this invocation
     */
    public static function stepsLeft(array $result, ?AutonomyMode $mode, bool $waitsForAPerson, mixed $typed, int $ceiling, int $spent, int $times, ?Config $config): ?int
    {
        if ($mode !== AutonomyMode::Auto || $waitsForAPerson || self::pause($result) === '' || $times >= self::continuations($config)) {
            return null;
        }
        $left = min($ceiling, (\is_int($typed) && $typed > 0 ? $typed : self::stepsInAll($config)) - $spent);

        return $left >= 1 ? $left : null;
    }

    /**
     * Which of the two pauses a leg's result says it ended in — or '': it ended some other way.
     *
     * @param array<string, mixed> $result
     */
    public static function pause(array $result): string
    {
        $why = \is_array($result['termination'] ?? null) ? ($result['termination']['reason'] ?? null) : null;

        return \is_string($why) && \in_array($why, self::AFTER, true) ? $why : '';
    }

    /** What the house tells the leg that follows — its own notice, never a turn a reader could take for a person's. */
    public static function notice(string $why): string
    {
        return SeatFrontier::NOTICE_PREFIX . \sprintf(
            'The leg before this one paused because it ran out of %s, with the task unfinished. The house goes on with it by itself, under a written cap: continue the task from where it stands.',
            $why === 'context_budget_exhausted' ? 'window' : 'steps',
        );
    }

    /**
     * The cap as this house has it: what it declares when that is a number it can mean, else the written values.
     *
     * @return array{continuations: int, steps_in_all: int}
     */
    public static function cap(?Config $config): array
    {
        return ['continuations' => self::continuations($config), 'steps_in_all' => self::stepsInAll($config)];
    }

    private static function continuations(?Config $config): int
    {
        $declared = $config?->get('agent.continuations');

        return \is_int($declared) && $declared >= 0 ? $declared : self::CONTINUATIONS;
    }

    private static function stepsInAll(?Config $config): int
    {
        $declared = $config?->get('agent.invocationSteps');

        return \is_int($declared) && $declared >= 1 ? $declared : self::STEPS_IN_ALL;
    }
}
