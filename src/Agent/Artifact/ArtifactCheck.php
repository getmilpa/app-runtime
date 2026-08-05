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

use Milpa\ToolRuntime\SchemaValidator;

/**
 * Whether what came back IS the artifact that was asked for — and if not, what to tell the child.
 *
 * ── THE ONE THING THIS CLASS MUST NOT DO ────────────────────────────────────────────────────────
 *
 * Judge quality. It answers «is this the declared shape», and nothing else. A validator that started
 * deciding whether a plan is a good plan would need a model to run, and a check that needs a model is
 * the unverifiable promise this whole contract exists to replace (Q-P19-R/S).
 *
 * ── WHY A FAILURE GOES BACK TO THE CHILD AND NOT UP TO THE PARENT ───────────────────────────────
 *
 * The spec says it (§5.2) and the reason is worth keeping next to the code: the child is the only one
 * that can fix its own output, and it is still alive. Handing the parent a malformed artifact makes
 * the parent's next move a guess about what the child meant; handing the parent an error makes the
 * delegation a wasted turn. Handing the CHILD the discrepancy is the only branch where the work so
 * far is not thrown away.
 *
 * The discrepancy has to be readable by a model, so it says what is missing in words rather than a
 * schema path: `steps` is required and absent beats `#/required/1`.
 *
 * ── WHY THE PARSE IS SEPARATE FROM THE VALIDATION ───────────────────────────────────────────────
 *
 * «Answered with prose» and «answered with the wrong fields» are different mistakes and the child
 * fixes them differently. Collapsing both into «invalid artifact» tells a model that just wrote a
 * beautiful paragraph to check its field names, and it will look for a bug that is not there.
 */
final class ArtifactCheck
{
    /** JSON is often delivered wrapped in a fence even when the brief says not to. */
    private const FENCE = '/^\s*```(?:json)?\s*(.+?)\s*```\s*$/s';

    public function __construct(private readonly SchemaValidator $validator = new SchemaValidator())
    {
    }

    /**
     * Whether the answer IS the declared artifact — and, when it is not, what to tell the child.
     *
     * The discrepancy is written for a model to act on, not for a log: it says what is missing in
     * words rather than a schema path, and it separates «you answered in prose» from «your fields
     * are wrong», because a child fixes those two differently.
     *
     * @return array{ok: bool, payload?: array<string, mixed>, discrepancy?: string}
     */
    public function check(ArtifactContract $contract, string $answer): array
    {
        $raw = trim($answer);
        if (preg_match(self::FENCE, $raw, $fenced) === 1) {
            // The fence is forgiven and not reported. It is a formatting habit, not a misunderstanding
            // of the contract, and bouncing the child for it would spend a turn teaching punctuation.
            $raw = $fenced[1];
        }

        if ($raw === '') {
            return ['ok' => false, 'discrepancy' => 'You returned nothing. ' . $contract->briefing()];
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [
                'ok' => false,
                'discrepancy' => "Your answer was not a JSON object, so it could not be read as the "
                    . "«{$contract->kind}» artifact.\n\n" . $contract->briefing(),
            ];
        }
        if (array_is_list($decoded)) {
            return [
                'ok' => false,
                'discrepancy' => "Your answer was a JSON array. The «{$contract->kind}» artifact is an "
                    . "object with named fields.\n\n" . $contract->briefing(),
            ];
        }

        /** @var array<string, mixed> $decoded */
        $result = $this->validator->validate($decoded, $contract->schema);
        if ($result->valid) {
            return ['ok' => true, 'payload' => $decoded];
        }

        return [
            'ok' => false,
            'discrepancy' => "Your JSON parsed but it is not the «{$contract->kind}» artifact yet:\n"
                . '  · ' . implode("\n  · ", $result->errors)
                . "\n\nReturn the whole object again, corrected. Keep the work you already did — this is "
                . 'about the shape, not about what you found.',
        ];
    }
}
