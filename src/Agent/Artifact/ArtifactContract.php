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
 * What one agent must hand the next, declared in a shape a machine can check.
 *
 * ── WHY THIS EXISTS, AND WHY IT IS THE FIRST PIECE OF A WORKFLOW AND NOT THE LAST ───────────────
 *
 * A workflow of specialists — planner, scout, implementer, reviewer — is only worth more than one
 * agent if what passes between them survives the handoff. Today what passes is a prose report, and
 * this house has already measured what prose does at a boundary.
 *
 * In Q-P19-R and Q-P19-S each delegator was asked for a `done_when`: a sentence saying when its child
 * would be finished. All eight wrote criteria that were reasonable and UNREACHABLE, and every metric
 * got worse — exhaustion 4/8 → 8/8, calls per child 7.5 → 14.7, sterile loops 12 → 43, compliance
 * 8/8 → 5/8. The rule that came out of it:
 *
 *     when the system asks for a promise it CANNOT VERIFY, it hands the authority of the stopping
 *     rule to an unvalidated sentence — and that failure mode is worse than not asking at all.
 *
 * A contract is the opposite move. It does not ask the child to promise; it declares a shape and the
 * system checks it. «The planner produced a plan» stops being something a model asserts and becomes
 * something that either parses or does not.
 *
 * ── WHAT A CONTRACT IS ALLOWED TO DEMAND ────────────────────────────────────────────────────────
 *
 * Only what a machine can check without a second opinion. A schema can say «steps is a non-empty
 * list and every step names a file»; it cannot say «the plan is good». The temptation is to encode
 * quality here, and it must be resisted: a check that needs judgement to run is a promise wearing a
 * schema, which is the exact defect above with extra steps.
 *
 * ── WHY THE SHAPE ALSO TRAVELS TO THE CHILD ─────────────────────────────────────────────────────
 *
 * The same declaration produces the demand and the verdict. Writing the expected shape into the
 * brief by hand and validating against a schema elsewhere would be two sources of one truth, and
 * they agree until the day someone edits one — at which point the child is told to produce A, judged
 * against B, and the discrepancy reads like the model failing.
 */
final class ArtifactContract
{
    /**
     * @param string               $kind    stable identifier — `plan`, `findings`, `changes`, `review`
     * @param string               $purpose one line the delegator reads to choose, and the child reads to comply
     * @param array<string, mixed> $schema  JSON Schema for the payload; checked by `SchemaValidator`
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $purpose,
        public readonly array $schema,
    ) {
        if (trim($kind) === '') {
            throw new \InvalidArgumentException('an artifact contract without a kind cannot be asked for by name');
        }
        if (($schema['type'] ?? null) !== 'object') {
            // Anything but an object leaves no room for the fields a later stage reads by name, and a
            // bare string would make this a prose report again with a schema stapled on.
            throw new \InvalidArgumentException("artifact «{$kind}»: the payload must be an object");
        }
    }

    /**
     * The instruction the child receives — generated from the schema, never written twice.
     *
     * It is deliberately blunt about the format. A child that answers with prose around its JSON is
     * a child whose artifact fails to parse, and the failure would look like a bad answer rather than
     * a bad envelope.
     */
    public function briefing(): string
    {
        $fields = [];
        /** @var array<string, array<string, mixed>> $properties */
        $properties = $this->schema['properties'] ?? [];
        /** @var list<string> $required */
        $required = $this->schema['required'] ?? [];

        foreach ($properties as $name => $spec) {
            $type = \is_string($spec['type'] ?? null) ? $spec['type'] : 'any';
            $note = \is_string($spec['description'] ?? null) ? ' — ' . $spec['description'] : '';
            $fields[] = sprintf(
                '  · %s (%s)%s%s',
                $name,
                $type,
                \in_array($name, $required, true) ? ', required' : ', optional',
                $note,
            );
        }

        return "Your answer must be a single JSON object and nothing else — no prose before or after,\n"
            . "no code fence. It is the «{$this->kind}» artifact this task exists to produce:\n"
            . "{$this->purpose}\n\n"
            . "Fields:\n" . implode("\n", $fields);
    }
}
