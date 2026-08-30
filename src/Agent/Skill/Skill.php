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

namespace Milpa\AppRuntime\Agent\Skill;

/**
 * One agentskills.io skill: frontmatter (name, description, invocation control) plus a markdown body.
 *
 * A skill is NON-deterministic guidance — it shapes the agent's judgment (how to work, what first),
 * unlike a tool, which the agent executes. The two invocation flags mirror the Claude Code contract:
 * a skill can be barred from the model (side-effect workflows the human must trigger) or barred from
 * the human (background knowledge only the agent reaches for).
 */
final readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        public string $body,
        // false when `disable-model-invocation: true` — only the human may load it, and its
        // description is withheld from the agent so the model never reaches for it.
        public bool $modelInvocable = true,
        // false when `user-invocable: false` — background knowledge, not a human command.
        public bool $userInvocable = true,
        // Absolute directory of this skill's folder — where its bundled scripts/ and references/ live,
        // reported to the agent as <skill_resources> so it can reach them.
        public string $directory = '',
    ) {
    }
}
