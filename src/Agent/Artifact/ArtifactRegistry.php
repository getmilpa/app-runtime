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

namespace Milpa\AppRuntime\Agent\Artifact;

/**
 * The artifact kinds this app knows how to ask for — a declared vocabulary, not free strings.
 *
 * ── WHY A REGISTRY AND NOT A STRING ─────────────────────────────────────────────────────────────
 *
 * If the delegator could name any kind, `produces: "plan"` and `produces: "a plan"` would be two
 * contracts, one of which nobody validates. A closed vocabulary means an unknown kind is refused by
 * name at the door, with the list of the ones that exist — the same shape the frontier board uses,
 * and for the same reason: a vocabulary that anyone can extend at the call site is not a vocabulary.
 *
 * Extending it is a DECLARATION — `register()` — so the set is always something you can print. Four
 * ship by default because they are the roles a workflow keeps rediscovering; they are defaults, not
 * a taxonomy anyone is stuck with.
 *
 * ── WHY THESE FOUR HAVE THE FIELDS THEY HAVE ────────────────────────────────────────────────────
 *
 * Every field is one a LATER stage reads. `plan.steps[].touches` exists because the implementer needs
 * to know what a step is about to change; `findings[].where` exists because a scout that reports
 * «there is a problem with auth» has handed over nothing actionable. A field no downstream stage
 * reads is decoration, and decoration in a contract is worse than absent — it looks like rigour.
 */
final class ArtifactRegistry
{
    /** @var array<string, ArtifactContract> */
    private array $contracts = [];

    public function __construct(bool $withDefaults = true)
    {
        if ($withDefaults) {
            foreach (self::defaults() as $contract) {
                $this->register($contract);
            }
        }
    }

    /**
     * Adds a kind to what this app can ask for — a DECLARATION, so the set is always printable.
     *
     * Registering the same kind twice replaces it rather than failing: a plugin that ships a better
     * `plan` than the default should be able to, and a collision that threw would make the order of
     * plugin boot decide which contracts exist.
     */
    public function register(ArtifactContract $contract): void
    {
        $this->contracts[$contract->kind] = $contract;
    }

    /** Whether this app declares that kind — asked before `get()`, which refuses what it does not know. */
    public function has(string $kind): bool
    {
        return isset($this->contracts[$kind]);
    }

    /**
     * The contract, or a refusal that says which kinds do exist.
     *
     * Refusing with the list is the difference between a dead end and a correction: a caller that
     * misspells `finding` learns `findings` exists instead of learning that something went wrong.
     */
    public function get(string $kind): ArtifactContract
    {
        if (!isset($this->contracts[$kind])) {
            throw new \InvalidArgumentException(sprintf(
                'unknown artifact kind «%s» — this app declares: %s',
                $kind,
                implode(', ', $this->kinds()),
            ));
        }

        return $this->contracts[$kind];
    }

    /**
     * Every kind this app declares, sorted.
     *
     * Sorted because it goes into a tool schema as an `enum`, and a list whose order changes between
     * runs would change the prompt a model reads without anything having actually changed.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        $kinds = array_keys($this->contracts);
        sort($kinds);

        return $kinds;
    }

    /**
     * The contracts themselves, for a surface that needs to show what each one is for.
     *
     * @return list<ArtifactContract>
     */
    public function all(): array
    {
        return array_values($this->contracts);
    }

    /** @return list<ArtifactContract> */
    private static function defaults(): array
    {
        return [
            new ArtifactContract(
                'plan',
                'An ordered set of steps a later stage can execute, each naming what it touches.',
                [
                    'type' => 'object',
                    'properties' => [
                        'goal' => ['type' => 'string', 'description' => 'the objective in one sentence'],
                        'steps' => [
                            'type' => 'array',
                            'description' => 'ordered; the first one is what happens first',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'what' => ['type' => 'string', 'description' => 'the action, in the imperative'],
                                    'touches' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                        'description' => 'concrete paths or operation names this step changes',
                                    ],
                                ],
                                'required' => ['what'],
                            ],
                        ],
                    ],
                    'required' => ['goal', 'steps'],
                ],
            ),
            new ArtifactContract(
                'findings',
                'What a scout found, each anchored to a place someone else can open.',
                [
                    'type' => 'object',
                    'properties' => [
                        'findings' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'what' => ['type' => 'string', 'description' => 'the finding, stated as a fact'],
                                    'where' => ['type' => 'string', 'description' => 'path, or path:line — not a description'],
                                ],
                                'required' => ['what', 'where'],
                            ],
                        ],
                        'searched' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'where it looked — an empty findings list means nothing only if this is full',
                        ],
                    ],
                    'required' => ['findings'],
                ],
            ),
            new ArtifactContract(
                'changes',
                'What an implementer actually changed, by path, and how it was checked.',
                [
                    'type' => 'object',
                    'properties' => [
                        'changed' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'paths written or deleted; empty is a valid answer and means nothing changed',
                        ],
                        'verified_with' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'the commands actually run — «tests pass» without one of these is a claim',
                        ],
                        'left_undone' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'what was in the brief and did not get done, and why',
                        ],
                    ],
                    'required' => ['changed'],
                ],
            ),
            new ArtifactContract(
                'review',
                'A verdict with the evidence it rests on — never a verdict alone.',
                [
                    'type' => 'object',
                    'properties' => [
                        'verdict' => [
                            'type' => 'string',
                            'enum' => ['pass', 'fail', 'unsure'],
                            'description' => '«unsure» is a real answer: a reviewer forced to choose invents confidence',
                        ],
                        'reasons' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'what' => ['type' => 'string'],
                                    'where' => ['type' => 'string', 'description' => 'path, or path:line'],
                                ],
                                'required' => ['what'],
                            ],
                        ],
                    ],
                    'required' => ['verdict', 'reasons'],
                ],
            ),
        ];
    }
}
