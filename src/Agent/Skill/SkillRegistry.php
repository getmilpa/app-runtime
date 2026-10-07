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

use Milpa\Console\McpProjector;

/**
 * The skills a house has: its own `skills/<name>/SKILL.md` folders — the agentskills.io format — and those of the
 * packages allowed to carry one (greenhouse decisions/0592).
 *
 * SKILLS TRAVEL WITH THE PACKAGE THAT OWNS THEIR TOOLS. A house born from `composer create-project` carried none:
 * the ones a resident knew were copied into an image by a Dockerfile (greenhouse evidence/1127 §6). A package now
 * ships them under `resources/skills/<name>/SKILL.md`, so they arrive and are updated the way its tools are.
 *
 * WHO MAY CONTRIBUTE ONE IS A CLOSED LIST ({@see PACKAGES}). A skill is text an agent follows: read from «any
 * installed package», every `composer require` would be a door to the system prompt. Adding a package is changing
 * that list — a diff someone reads. A third-party package has nowhere to say it carries one.
 *
 * THE HOUSE'S OWN WINS when it repeats a name, whole: the two are never merged. And every skill says where it came
 * from ({@see Skill::$origin}).
 *
 * A SKILL IS ADVERTISED ONLY WHERE ITS TOOLS ARE ({@see advertisedFor()}): it names them in its header
 * (`requires: make, implement`), and a session that is not offered them does not read about it. Loading it by name
 * still works — what is filtered is the advertisement.
 *
 * The parser mirrors {@see \Milpa\AppRuntime\Agent\Role\RoleRegistry::parse()}: frontmatter between
 * `---` fences, then the markdown body. A file that does not parse, or declares no description, is
 * SKIPPED rather than throwing — a stray `.md` should not stop an app from booting, and a skill with
 * no description could never be triggered.
 */
final class SkillRegistry
{
    /** The packages allowed to carry skills. Closed: a package that is not here contributes none. */
    public const PACKAGES = ['milpa/app-runtime', 'milpa/devtools'];

    /** The origin of a skill the house itself keeps under `skills/`. */
    public const HOUSE = 'house';

    /** @var array<string, Skill> */
    private array $skills = [];

    /**
     * @param (\Closure(string): ?string)|null $installed where an allowed package is installed, or null when it
     *                                                    is not — a test's seam; by default Composer is asked
     */
    public function __construct(string $root, ?\Closure $installed = null)
    {
        $installed ??= self::installPath(...);
        foreach (self::PACKAGES as $package) {
            $path = $installed($package);
            if (\is_string($path) && $path !== '') {
                $this->read(rtrim($path, '/') . '/resources/skills', $package);
            }
        }
        // The house last: a name it repeats replaces the package's, whole.
        $this->read(rtrim($root, '/') . '/skills', self::HOUSE);
        ksort($this->skills);
    }

    /**
     * Every skill this app carries.
     *
     * @return list<Skill>
     */
    public function all(): array
    {
        return array_values($this->skills);
    }

    /**
     * The skills the agent is allowed to reach for on its own.
     *
     * @return list<Skill>
     */
    public function modelInvocable(): array
    {
        return array_values(array_filter($this->skills, static fn (Skill $s): bool => $s->modelInvocable));
    }

    /**
     * The skills to advertise to a session that is offered these tools: the ones the model may reach for, whose
     * required tools are ALL on the offer.
     *
     * @param list<string> $offer the names of the tools the session is offered, as an agent sees them
     *
     * @return list<Skill>
     */
    public function advertisedFor(array $offer): array
    {
        return array_values(array_filter(
            $this->skills,
            static fn (Skill $s): bool => $s->modelInvocable && array_diff($s->requires, $offer) === [],
        ));
    }

    /**
     * What the system prompt says of these skills to a session that is offered these tools — or `''` when it has no
     * way to load one, or none to reach for.
     *
     * @param list<string> $offer the names of the tools the session is offered, as an agent sees them
     */
    public function announcement(array $offer): string
    {
        $skills = \in_array('skill_load', $offer, true) ? $this->advertisedFor($offer) : [];
        if ($skills === []) {
            return '';
        }

        return '<system-reminder> A skill is a reusable set of task-specific instructions. '
            . "The following skills are available in this session:\n<available_skills>\n"
            . implode("\n", array_map(static fn (Skill $s): string => "- {$s->name}: {$s->description}", $skills))
            . "\n</available_skills>\n"
            . 'When a skill matches the task, call `skill:load` with its name, read its instructions, '
            . 'and follow them before you act. </system-reminder>';
    }

    /** One skill by name, or null if this app declares no such skill. */
    public function get(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }

    /** Parse one SKILL.md into a Skill, or null if it does not parse or declares no description. */
    public static function parse(string $content, string $fallbackName, string $directory = '', string $origin = self::HOUSE): ?Skill
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
            origin: $origin,
            requires: self::tools($front['requires'] ?? ''),
        );
    }

    /**
     * The tools a `requires:` header names, each as an agent sees it: `screen:declare` is offered as `screen_declare`.
     *
     * @return list<string>
     */
    private static function tools(string $header): array
    {
        $tools = [];
        foreach (explode(',', $header) as $tool) {
            if (trim($tool) !== '') {
                $tools[] = McpProjector::toolName(trim($tool));
            }
        }

        return $tools;
    }

    /** Read every skill folder under a directory, each as this origin's; a later origin replaces a name. */
    private function read(string $directory, string $origin): void
    {
        foreach (glob($directory . '/*/SKILL.md') ?: [] as $file) {
            $skill = self::parse((string) @file_get_contents($file), basename(\dirname($file)), \dirname($file), $origin);
            if ($skill !== null) {
                $this->skills[$skill->name] = $skill;
            }
        }
    }

    /**
     * Where an allowed package is installed in this process — this package itself, or what Composer says — or null.
     */
    private static function installPath(string $package): ?string
    {
        if ($package === 'milpa/app-runtime') {
            return \dirname(__DIR__, 3);
        }
        try {
            $path = \Composer\InstalledVersions::isInstalled($package) ? \Composer\InstalledVersions::getInstallPath($package) : null;
        } catch (\Throwable) {
            return null;
        }

        return \is_string($path) && is_dir($path) ? $path : null;
    }
}
