<?php

/**
 * Where a leg's per-request projections travel: the system prompt, or after the conversation.
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * Wires the two things a leg re-projects on every model call onto its orchestrator.
 *
 * The skill instructions shape the SYSTEM prompt and only change with the tools offered. The locators of the
 * results recorded so far ({@see RecordedResultReferences}) GROW with every tool call. Written at the end of the
 * system prompt, they changed the text that comes before the conversation on every step, so no request was a
 * prefix of the next and the model server read the whole prompt again: 13 cold calls of 19 on the BV-4 run
 * (greenhouse evidence/1109 §6.1). They ride after the conversation wherever the installed gateway can put them
 * there, and stay where they were where it cannot.
 */
final class RecordedResultProjection
{
    /**
     * Hand the orchestrator its projections. One that offers no projection seam at all is left alone.
     *
     * @param object                                                      $orchestrator The leg's orchestrator, of whatever milpa/ai-gateway is installed.
     * @param (\Closure(string, list<array<string, mixed>>): string)|null $skill        The skill instructions for the system prompt, or null.
     * @param (\Closure(list<string>): string)|null                       $recorded     The recorded-results section for the tool names offered ('' when none), or null without a session.
     */
    public static function attach(object $orchestrator, ?\Closure $skill, ?\Closure $recorded): void
    {
        if (!method_exists($orchestrator, 'setSystemPromptProjection')) {
            return;
        }
        $section = static fn (array $tools): string => $recorded === null
            ? ''
            : $recorded(array_values(array_filter(array_column($tools, 'name'), 'is_string')));
        $after = method_exists($orchestrator, 'setTrailingProjection');
        $orchestrator->setSystemPromptProjection(static function (string $base, array $tools) use ($skill, $section, $after): string {
            $projected = $skill === null ? $base : $skill($base, $tools);

            return $after ? $projected : $projected . $section($tools);
        });
        if ($after) {
            $orchestrator->setTrailingProjection(static fn (array $tools): string => ltrim($section($tools)));
        }
    }
}
