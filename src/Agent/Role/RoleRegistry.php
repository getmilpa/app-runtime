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
 * The specialists this app can delegate to — from a package, or from its own directory.
 *
 * ── TWO ORIGINS, AND THE CATALOGUE SAYS WHICH ───────────────────────────────────────────────────
 *
 * A plugin ships the roles of its domain: they arrive by version and improve without anybody
 * editing them. The app ships its own in `.milpa/agents/*.md`: they belong to whoever operates it
 * and nothing updates them.
 *
 * It is the same distinction the framework already draws between what `create-project` copies and
 * what it installs — «you copy what you are going to edit, you install what you are going to use» —
 * and it is worth showing rather than hiding, because the answer to «why does the reviewer behave
 * like that» is different depending on which one it is.
 *
 * ── WHY A DECLARED ROLE CANNOT OVERWRITE AN INSTALLED ONE SILENTLY ──────────────────────────────
 *
 * The app's directory wins on a name collision — the owner of a repository gets the last word about
 * their own agents — but the loser is kept and reported, never dropped. A collision that vanished
 * would make «the reviewer suddenly stopped denying `make`» a mystery whose cause lives in a file
 * nobody thought to look at.
 *
 * ── THE FILE FORMAT, AND WHY MARKDOWN ───────────────────────────────────────────────────────────
 *
 *     ---
 *     name: reviewer
 *     produces: review
 *     deny: [plugins_lock, make]
 *     first: [plan]
 *     ---
 *
 *     You are a security reviewer. You read, you do not change…
 *
 * Markdown because the body is prose a person writes and rereads, and frontmatter because the four
 * fields that govern have to be machine-readable — a role whose restrictions were buried in the
 * prose would be a role nothing could enforce.
 */
final class RoleRegistry
{
    /** @var array<string, AgentRole> */
    private array $roles = [];

    /** @var list<array{name: string, kept: string, dropped: string}> */
    private array $collisions = [];

    /**
     * @param list<AgentRole> $fromPackages roles a package declared — they arrive by version
     */
    public function __construct(array $fromPackages = [])
    {
        foreach ($fromPackages as $role) {
            $this->roles[$role->name] = $role;
        }
    }

    /**
     * Reads the app's own roles from `.milpa/agents/*.md`, which win on a name collision.
     *
     * A missing directory is not an error: an app with no roles of its own is the normal case, and
     * treating it as a failure would make every app declare an empty folder to keep the peace.
     */
    public function loadFrom(string $directory): void
    {
        foreach (glob(rtrim($directory, '/') . '/*.md') ?: [] as $file) {
            $role = self::parse((string) @file_get_contents($file), basename($file, '.md'), '.milpa/agents/');
            if ($role === null) {
                continue;
            }
            if (isset($this->roles[$role->name])) {
                // KEPT AND REPORTED, never dropped in silence. «The reviewer stopped denying make»
                // must not be a mystery whose cause lives in a file nobody thought to open.
                $this->collisions[] = [
                    'name' => $role->name,
                    'kept' => $role->origin,
                    'dropped' => $this->roles[$role->name]->origin,
                ];
            }
            $this->roles[$role->name] = $role;
        }
    }

    /** Whether this app declares that role — asked before `get()`, which refuses what it does not know. */
    public function has(string $name): bool
    {
        return isset($this->roles[$name]);
    }

    /**
     * The role, or a refusal that names the ones that do exist.
     *
     * Refusing with the list is the difference between a dead end and a correction: a delegator that
     * asks for `revisor` learns `reviewer` exists instead of learning something went wrong.
     */
    public function get(string $name): AgentRole
    {
        if (!isset($this->roles[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'unknown role «%s» — this app declares: %s',
                $name,
                $this->names() === [] ? '(none)' : implode(', ', $this->names()),
            ));
        }

        return $this->roles[$name];
    }

    /**
     * Every role this app declares, sorted.
     *
     * Sorted because it is what a refusal prints and what a catalogue shows: an order that changed
     * between runs would make «the same list» look different for no reason anyone could name.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->roles);
        sort($names);

        return $names;
    }

    /**
     * The roles themselves, for a surface that needs to show each one's prompt and restrictions.
     *
     * @return list<AgentRole>
     */
    public function all(): array
    {
        return array_values($this->roles);
    }

    /**
     * Name collisions between an installed role and one of the app's own, kept for a surface to show.
     *
     * @return list<array{name: string, kept: string, dropped: string}>
     */
    public function collisions(): array
    {
        return $this->collisions;
    }

    /**
     * One markdown file with frontmatter into a role, or `null` when it is not one.
     *
     * A file that does not parse is SKIPPED rather than throwing: a stray `.md` in the directory
     * should not stop an app from booting. What is not skipped in silence is a file that declares a
     * name and no prompt — that one throws, because it would produce a muzzle wearing a name.
     */
    public static function parse(string $content, string $fallbackName, string $origin): ?AgentRole
    {
        if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', trim($content), $m) !== 1) {
            return null;
        }

        $front = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^\s*([a-z_]+)\s*:\s*(.*)$/i', $line, $pair) !== 1) {
                continue;
            }
            $front[strtolower($pair[1])] = trim($pair[2]);
        }

        $list = static function (?string $raw): array {
            if ($raw === null || trim($raw) === '') {
                return [];
            }

            return array_values(array_filter(array_map(
                static fn (string $v): string => trim($v, " \t'\"[]"),
                explode(',', trim($raw, '[]')),
            ), static fn (string $v): bool => $v !== ''));
        };

        return new AgentRole(
            name: $front['name'] ?? $fallbackName,
            prompt: trim($m[2]),
            produces: ($front['produces'] ?? '') !== '' ? $front['produces'] : null,
            deny: $list($front['deny'] ?? null),
            first: $list($front['first'] ?? null),
            origin: $origin,
            skills: $list($front['skills'] ?? null),
        );
    }
}
