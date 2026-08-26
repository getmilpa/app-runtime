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

namespace Milpa\AppRuntime\Recipe;

/**
 * A declared unit of intent for `recipe:apply`: a flat, domain-blind value object naming an
 * optional foundation, the capabilities it requires, and the work it wants done. It carries no
 * behaviour of its own — `RecipeExpander` reads it, and reads nothing else.
 */
final readonly class Recipe
{
    /**
     * @param array{domain: string, objective: string, boundaries?: list<string>}|null $foundation
     * @param list<string>                                                             $capabilities package names, e.g. `milpa/data`
     * @param list<array{op: string, args?: array<string, mixed>}>                     $work
     */
    public function __construct(
        public string $name,
        public ?array $foundation,
        public array $capabilities,
        public array $work,
    ) {
    }

    /**
     * Maps a NESTED declaration — the shape a recipe file is authored in — onto this FLAT VO:
     * `foundation` from the top-level key, `capabilities` from `requires.capabilities`, `work`
     * from the top-level `work` list. Any path the declaration omits defaults to null/empty —
     * a recipe is never required to declare a foundation, capabilities, or work.
     *
     * @param array<string, mixed> $decl
     */
    public static function fromArray(string $name, array $decl): self
    {
        /** @var array{domain: string, objective: string, boundaries?: list<string>}|null $foundation */
        $foundation = $decl['foundation'] ?? null;
        /** @var list<string> $capabilities */
        $capabilities = $decl['requires']['capabilities'] ?? [];
        /** @var list<array{op: string, args?: array<string, mixed>}> $work */
        $work = $decl['work'] ?? [];

        return new self($name, $foundation, $capabilities, $work);
    }
}
