<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent\Skill;

/**
 * Reads the app's `skills/<name>/SKILL.md` folders — the agentskills.io format.
 *
 * The parser mirrors {@see \Milpa\AppRuntime\Agent\Role\RoleRegistry::parse()}: frontmatter between
 * `---` fences, then the markdown body. A file that does not parse, or declares no description, is
 * SKIPPED rather than throwing — a stray `.md` should not stop an app from booting, and a skill with
 * no description could never be triggered.
 */
final class SkillRegistry
{
    /** @var array<string, Skill> */
    private array $skills = [];

    public function __construct(string $root)
    {
        foreach (glob(rtrim($root, '/') . '/skills/*/SKILL.md') ?: [] as $file) {
            $skill = self::parse((string) @file_get_contents($file), basename(\dirname($file)), \dirname($file));
            if ($skill !== null) {
                $this->skills[$skill->name] = $skill;
            }
        }
    }

    /** @return list<Skill> */
    public function all(): array
    {
        return array_values($this->skills);
    }

    /** The skills the agent is allowed to reach for on its own. @return list<Skill> */
    public function modelInvocable(): array
    {
        return array_values(array_filter($this->skills, static fn (Skill $s): bool => $s->modelInvocable));
    }

    public function get(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }

    public static function parse(string $content, string $fallbackName, string $directory = ''): ?Skill
    {
        if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', trim($content), $m) !== 1) {
            return null;
        }

        $front = [];
        foreach (explode("\n", $m[1]) as $line) {
            // Keys may carry hyphens (`disable-model-invocation`), unlike RoleRegistry's `[a-z_]`.
            if (preg_match('/^\s*([a-z0-9_-]+)\s*:\s*(.*)$/i', $line, $pair) !== 1) {
                continue;
            }
            $front[strtolower($pair[1])] = trim($pair[2]);
        }

        $description = trim($front['description'] ?? '');
        if ($description === '') {
            return null;
        }

        $isTrue = static fn (?string $v): bool => \in_array(strtolower(trim((string) $v)), ['true', '1', 'yes'], true);

        return new Skill(
            name: $front['name'] ?? $fallbackName,
            description: $description,
            body: trim($m[2]),
            modelInvocable: !$isTrue($front['disable-model-invocation'] ?? null),
            userInvocable: !\array_key_exists('user-invocable', $front) || $isTrue($front['user-invocable']),
            directory: $directory,
        );
    }
}
