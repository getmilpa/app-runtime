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

namespace Milpa\AppRuntime\Agent;

use Milpa\Command\Consent\OperationId;

/**
 * The tool names an agent session is offered, and the one place an operator's spelling is resolved against them.
 *
 * ── WHY IT EXISTS (greenhouse decisions/0550) ───────────────────────────────────────────────────
 *
 * An operator names tools on the command line the way the terminal spells operations — `--first=recipe.plan`,
 * `--deny=recipe:apply` — and the model calls them the way the catalogue spells them, `recipe_plan`. Both flags
 * stored the operator's string and compared it to the model's, so they never met:
 *
 *   - `--first=recipe.plan` refused `recipe_plan` itself, the one call that could meet the obligation, and the
 *     leg ended on its first step (measured on a clone and on a create-project house alike);
 *   - `--deny=recipe.apply` was recorded and `recipe_apply` stayed on the table the model received;
 *   - a name that is no tool at all was accepted in silence, by both.
 *
 * Identity is {@see OperationId}'s, the atom every gate of this family already asks (decisions/0030). What this
 * adds is the other half: a name that matches nothing offered is said to whoever typed it, with the closest names,
 * before the session or the provider is touched — an obligation nobody can meet and a withdrawal that withdraws
 * nothing are the same silent inertness this house names as worse than not having the flag.
 */
final readonly class OfferedTools
{
    /** How many near names a refusal suggests. */
    private const SUGGESTIONS = 3;

    /** @var list<string> */
    private array $names;

    /**
     * @param list<string> $names the tool names as the catalogue spells them
     */
    public function __construct(array $names)
    {
        $this->names = array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
    }

    /**
     * The offered name this spelling is, or `null` when it is none of them.
     *
     * An exact match wins over identity, so a catalogue holding two names one identity apart (never seen, but not
     * forbidden) still resolves each to itself.
     */
    public function resolve(string $asked): ?string
    {
        $asked = trim($asked);
        if ($asked === '') {
            return null;
        }
        if (\in_array($asked, $this->names, true)) {
            return $asked;
        }

        $identity = new OperationId($asked);
        foreach ($this->names as $name) {
            if ($identity->is($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A flag's comma list (or programmatic list), split and trimmed — the shape both flags already accept.
     *
     * @return list<string>
     */
    public static function listed(mixed $raw): array
    {
        $items = \is_string($raw) ? explode(',', $raw) : (\is_array($raw) ? $raw : []);
        $listed = [];
        foreach ($items as $item) {
            if (\is_string($item) && trim($item) !== '') {
                $listed[] = trim($item);
            }
        }

        return $listed;
    }

    /**
     * Every name of the list in the catalogue's spelling; a name offered nowhere is kept as typed.
     *
     * Kept, not dropped: whoever reads the result is about to refuse it through {@see self::refusal()}, and a
     * caller that skipped that question must still see what was asked rather than a quietly shorter list.
     *
     * @return list<string>
     */
    public function canonical(mixed $raw): array
    {
        return array_values(array_unique(array_map(
            fn (string $asked): string => $this->resolve($asked) ?? $asked,
            self::listed($raw),
        )));
    }

    /**
     * The refusal a flag earns when it names a tool this session is not offered, or `null` when every name is.
     *
     * @return array{ok: false, error: string, hint: string}|null
     */
    public function refusal(string $flag, mixed $raw): ?array
    {
        $unknown = array_values(array_filter(self::listed($raw), fn (string $asked): bool => $this->resolve($asked) === null));
        if ($unknown === []) {
            return null;
        }

        $near = [];
        foreach ($unknown as $asked) {
            foreach ($this->closest($asked) as $name) {
                $near[] = $name;
            }
        }
        $near = array_values(array_unique($near));

        return [
            'ok' => false,
            'error' => "--{$flag} names " . (\count($unknown) === 1 ? 'a tool' : 'tools') . ' this session is not offered: «'
                . implode('», «', $unknown) . '». Nothing ran and the session was not touched.',
            'hint' => $near === []
                ? 'name tools as `php bin/coa agent:catalogue` lists them'
                : 'did you mean «' . implode('», «', $near) . '»? Tools are named as `php bin/coa agent:catalogue` lists them',
        ];
    }

    /**
     * The offered names nearest to an unknown spelling, compared in the catalogue's own spelling.
     *
     * @return list<string>
     */
    private function closest(string $asked): array
    {
        $target = (new OperationId($asked))->forTool();
        $scored = [];
        foreach ($this->names as $name) {
            $distance = levenshtein($target, strtolower($name));
            if ($distance <= max(2, intdiv(\strlen($target), 3))) {
                $scored[$name] = $distance;
            }
        }
        asort($scored);

        return \array_slice(array_keys($scored), 0, self::SUGGESTIONS);
    }
}
