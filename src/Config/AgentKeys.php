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

namespace Milpa\AppRuntime\Config;

/**
 * The agent configuration keys THIS RUNTIME reads, so an operation can say which ones exist.
 *
 * greenhouse evidence/0144 measured the gap and evidence/0155 re-measured it: the code reads
 * seventeen keys, the template's comment documents four, and a newborn app ships two — neither of
 * them the agent's. So `config:set` would take any key at all without ever telling the caller which
 * ones this app understands, which is the same «you must know the architecture» that scaffolding
 * exists to remove.
 *
 * A HAND-WRITTEN CATALOGUE WITH NOTHING CONFRONTING IT IS THE SAME DEBT BETTER DRESSED. There is
 * already a hand-written list — the template's comment — and it covers four of seventeen. So this
 * list is not the whole answer: greenhouse `scripts/gates/verify-agent-keys.php` derives the
 * `Config::get('agent.*')` call sites from the source and fails when the two disagree. Without that
 * rail this class would go stale exactly the way the comment did, and quietly.
 *
 * IT SPEAKS ONLY FOR THIS RUNTIME. A plugin declares its own keys and this list does not know them,
 * which is why writing an unknown key is reported rather than refused: refusing would break a
 * legitimate app to punish a typo, and the write is already governed by consent.
 */
final class AgentKeys
{
    /**
     * Every key this runtime reads, with the type it expects and what it decides.
     *
     * @return array<string, array{type: string, does: string}>
     */
    public static function todas(): array
    {
        return [
            'agent.instructions' => [
                'type' => 'string',
                'does' => 'The app\'s own instructions, prepended to what the agent already knows',
            ],
            'agent.model' => [
                'type' => 'string',
                'does' => 'Which model this app talks to',
            ],
            'agent.baseUrl' => [
                'type' => 'string',
                'does' => 'Where that model lives, when it is not the default endpoint',
            ],
            'agent.permissionWindow' => [
                'type' => 'string (ISO-8601 duration, e.g. PT1H)',
                'does' => 'How long a paused question waits for its answer before it dies',
            ],
            'agent.compaction' => [
                'type' => 'array{maxTurns?: int, keepLast?: int}',
                'does' => 'When a long session gets compacted, and how much of the tail survives it',
            ],
            'agent.treeBudget' => [
                'type' => 'int',
                'does' => 'How many nodes of the project tree the agent is shown',
            ],
            'agent.architectureSummary' => [
                'type' => "true | 'pointer' | 'summary' | false",
                'does' => 'Whether the agent is handed a summary of the architecture, a pointer to it, or nothing',
            ],
            'agent.planInstruction' => [
                'type' => 'bool',
                'does' => 'Whether the agent is told to plan before acting (on unless declared false)',
            ],
            'agent.reprojectPlan' => [
                'type' => 'bool',
                'does' => 'Whether a plan is reprojected as the session advances (off unless declared true)',
            ],
            'agent.conditionalCatalog' => [
                'type' => 'bool',
                'does' => 'Whether the catalogue shown narrows to what the current step can use (off unless true)',
            ],
            'agent.observableAlternatives' => [
                'type' => 'array<string, list<string>>',
                'does' => 'For an operation that was refused, which other ones are worth offering instead',
            ],
            'agent.removeRefusedOptions' => [
                'type' => "bool | 'record-only'",
                'does' => 'Whether a refused option disappears from the catalogue or is only recorded',
            ],
            'agent.renewalTool' => [
                'type' => 'bool',
                'does' => 'Whether the agent may ask for its own permission window to be renewed',
            ],
            'agent.secondOpinion' => [
                'type' => 'array',
                'does' => 'When a second judgement is asked for before an operation runs',
            ],
            'agent.sterileLoopGuard' => [
                'type' => 'bool | array',
                'does' => 'Stops a session that keeps trying without changing anything',
            ],
            'agent.transitions.foundation' => [
                'type' => 'list<string>',
                'does' => 'THE JUDGE\'S CRITERION: what must hold before the founding rite may run',
            ],
            'agent.transitions.frontier' => [
                'type' => 'list<string>',
                'does' => 'THE JUDGE\'S CRITERION: what must hold before frontier work may run',
            ],
        ];
    }

    /** Does this runtime declare that key? A plugin's key is not this list's to know. */
    public static function conocida(string $llave): bool
    {
        return isset(self::todas()[$llave]);
    }
}
