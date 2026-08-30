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
     * The printable type is derived from the executable declaration below. Reading the catalogue
     * and enforcing a write therefore consult the same object rather than parallel type tables.
     *
     * @return array<string, array{type: string, does: string}>
     */
    public static function todas(): array
    {
        $catalogue = [];
        foreach (self::declarations() as $key => $declaration) {
            $catalogue[$key] = [
                'type' => $declaration['type']->description(),
                'does' => $declaration['does'],
            ];
        }

        return $catalogue;
    }

    /**
     * Conform a value to the type this key declares.
     *
     * Unknown keys belong to plugins this runtime cannot type, so their values remain untouched.
     *
     * @throws \InvalidArgumentException when a declared key receives a value of another type
     */
    public static function coerceDeclaredValue(string $key, mixed $value): mixed
    {
        $declaration = self::declarations()[$key] ?? null;
        if ($declaration === null) {
            return $value;
        }

        try {
            return $declaration['type']->coerce($value);
        } catch (\InvalidArgumentException $error) {
            throw new \InvalidArgumentException(
                "Configuration key '{$key}' declares {$declaration['type']->description()}, but its value does not conform: "
                . $error->getMessage(),
                previous: $error,
            );
        }
    }

    /** Does this runtime declare that key? A plugin's key is not this list's to know. */
    public static function conocida(string $llave): bool
    {
        return isset(self::declarations()[$llave]);
    }

    /** @return array<string, array{type: DeclaredType, does: string}> */
    private static function declarations(): array
    {
        return [
            'agent.instructions' => [
                'type' => DeclaredType::text(),
                'does' => 'The app\'s own instructions, prepended to what the agent already knows',
            ],
            'agent.model' => [
                'type' => DeclaredType::text(),
                'does' => 'Which model this app talks to',
            ],
            'agent.baseUrl' => [
                'type' => DeclaredType::text(),
                'does' => 'Where that model lives, when it is not the default endpoint',
            ],
            'agent.permissionWindow' => [
                'type' => DeclaredType::text('ISO-8601 duration, e.g. PT1H'),
                'does' => 'How long a paused question waits for its answer before it dies',
            ],
            'agent.compaction' => [
                'type' => DeclaredType::shape([
                    'maxTurns' => ['type' => DeclaredType::integer(), 'optional' => true],
                    'keepLast' => ['type' => DeclaredType::integer(), 'optional' => true],
                    'maxTokens' => ['type' => DeclaredType::integer(), 'optional' => true],
                ]),
                'does' => 'When a long session gets compacted, and how much of the tail survives it',
            ],
            'agent.lazyTools' => [
                'type' => DeclaredType::boolean(),
                'does' => 'Whether the agent sees tools by name and description only, fetching each schema '
                    . 'on demand — smaller context for small-window models, at one describe round-trip per tool',
            ],
            'agent.treeBudget' => [
                'type' => DeclaredType::integer(),
                'does' => 'How many nodes of the project tree the agent is shown',
            ],
            'agent.trialWorkspace' => [
                'type' => DeclaredType::boolean(),
                'does' => 'Whether a confinable mutation is rehearsed in a disposable copy before it may '
                    . 'touch the house — ON by default (decisions/0072); declare false to turn it off. '
                    . 'Promoting is the only door in',
            ],
            'agent.architectureSummary' => [
                'type' => DeclaredType::union(
                    DeclaredType::literal(true),
                    DeclaredType::literal('pointer'),
                    DeclaredType::literal('summary'),
                    DeclaredType::literal(false),
                ),
                'does' => 'Whether the agent is handed a summary of the architecture, a pointer to it, or nothing',
            ],
            'agent.planInstruction' => [
                'type' => DeclaredType::boolean(),
                'does' => 'Whether the agent is told to plan before acting (on unless declared false)',
            ],
            'agent.reprojectPlan' => [
                'type' => DeclaredType::boolean(),
                'does' => 'Whether a plan is reprojected as the session advances (off unless declared true)',
            ],
            'agent.conditionalCatalog' => [
                'type' => DeclaredType::boolean(),
                'does' => 'Whether the catalogue shown narrows to what the current step can use (off unless true)',
            ],
            'agent.observableAlternatives' => [
                'type' => DeclaredType::mapOf(
                    DeclaredType::text(),
                    DeclaredType::listOf(DeclaredType::text()),
                ),
                'does' => 'For an operation that was refused, which other ones are worth offering instead',
            ],
            'agent.removeRefusedOptions' => [
                'type' => DeclaredType::union(
                    DeclaredType::boolean(),
                    DeclaredType::literal('record-only'),
                ),
                'does' => 'Whether a refused option disappears from the catalogue or is only recorded',
            ],
            'agent.renewalTool' => [
                'type' => DeclaredType::boolean(),
                'does' => 'Whether the agent may ask for its own permission window to be renewed',
            ],
            'agent.secondOpinion' => [
                'type' => DeclaredType::anyArray(),
                'does' => 'When a second judgement is asked for before an operation runs',
            ],
            'agent.sterileLoopGuard' => [
                'type' => DeclaredType::union(
                    DeclaredType::boolean(),
                    DeclaredType::anyArray(),
                ),
                'does' => 'Stops a session that keeps trying without changing anything',
            ],
            'agent.transitions.foundation' => [
                'type' => DeclaredType::listOf(DeclaredType::text()),
                'does' => 'THE JUDGE\'S CRITERION: what must hold before the founding rite may run',
            ],
            'agent.transitions.frontier' => [
                'type' => DeclaredType::listOf(DeclaredType::text()),
                'does' => 'THE JUDGE\'S CRITERION: what must hold before frontier work may run',
            ],
        ];
    }
}
