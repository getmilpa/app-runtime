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

namespace Milpa\AppRuntime\Agent\Role;

/**
 * A specialist a workflow can delegate to — planner, scout, implementer, reviewer.
 *
 * ── A ROLE IS NOT NEW AUTHORITY. IT IS A NAME FOR AUTHORITY THAT ALREADY GOVERNS ────────────────
 *
 * The temptation is to make a role a markdown file with a system prompt and call it done. This house
 * has measured what that produces: an instruction handed to a model is delivered 8/8 and governs
 * 0/8. A `reviewer.md` saying «NEVER modify files» leaves every mutating tool in the catalogue, and
 * the first time the model decides a small edit would help, it edits.
 *
 * So a role carries four things, and only one of them is prose:
 *
 *     prompt     SUGGESTS   — who it is, how it works, what it cares about
 *     deny       GOVERNS    — tools removed from its catalogue; it cannot call what it does not have
 *     first      GOVERNS    — tools that must run before anything else; the gate queues the rest
 *     produces   GOVERNS    — the artifact contract its answer is checked against
 *
 * The three that govern are not new mechanisms: `agent_spawn` already had them, each measured. What
 * a role adds is that they travel together under a name, so «delegate to the reviewer» stops being
 * a description and becomes a configuration somebody wrote down once.
 *
 * ── SOUL AND GUIDELINES LIVE IN THE PROMPT, ON PURPOSE ──────────────────────────────────────────
 *
 * Splitting prose into `prompt`, `soul` and `guidelines` fields would suggest the system treats them
 * differently. It does not: all three are concatenated and handed to the model, and all three are
 * suggestions. Three names for one mechanism would be three places to look for why a role misbehaved.
 *
 * What separates a suggestion from a rule here is not which field it went in — it is whether the
 * system can execute it.
 */
final class AgentRole
{
    /**
     * @param string       $name     how a delegator asks for it, and how the refusal names it
     * @param string       $prompt   who this specialist is — SUGGESTS, never governs
     * @param string|null  $produces the artifact kind its answer is checked against, if any
     * @param list<string> $deny     tools removed from its catalogue — executed, not requested
     * @param list<string> $first    tools that must run before any other — executed, not requested
     * @param string       $origin   where it came from: a package name, or the app's own directory
     * @param list<string>  $skills   skills this role preloads — SUGGESTS (guidance for its judgment),
     *                                like the prompt; the runtime injects them, it does not govern by them
     */
    public function __construct(
        public readonly string $name,
        public readonly string $prompt,
        public readonly ?string $produces = null,
        public readonly array $deny = [],
        public readonly array $first = [],
        public readonly string $origin = '(unknown)',
        public readonly array $skills = [],
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('a role without a name cannot be delegated to by name');
        }
        if (trim($prompt) === '') {
            // A role with no prose is a bundle of restrictions with nobody inside. It would still
            // govern — and that is the danger: it would look like a specialist and behave like a
            // muzzle, and whoever delegated to it would blame the model.
            throw new \InvalidArgumentException("role «{$name}» has no prompt: restrictions without a brief are a muzzle, not a specialist");
        }
    }

    /**
     * This role's restrictions combined with the ones the delegator passed — the UNION, always.
     *
     * A delegator can add restrictions to a role and can never remove them. That is GOV-08 at the
     * call site: actors escalate, never degrade. If a caller could pass `deny: []` and get a reviewer
     * with write access, the role would be a default rather than a contract, and every measured
     * guarantee would hold only until somebody was in a hurry.
     *
     * @param list<string> $callerDeny
     * @param list<string> $callerFirst
     *
     * @return array{deny: list<string>, first: list<string>}
     */
    public function combinedWith(array $callerDeny, array $callerFirst): array
    {
        return [
            'deny' => array_values(array_unique([...$this->deny, ...$callerDeny])),
            'first' => array_values(array_unique([...$this->first, ...$callerFirst])),
        ];
    }

    /**
     * The role as data, with its origin included.
     *
     * The origin travels because «why does the reviewer behave like that» has a different answer
     * depending on whether it arrived in a package or lives in this repository, and behaviour alone
     * does not say which.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'origin' => $this->origin,
            'produces' => $this->produces,
            'deny' => $this->deny,
            'first' => $this->first,
            'skills' => $this->skills,
        ];
    }
}
