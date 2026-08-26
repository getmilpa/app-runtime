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

use Milpa\AppRuntime\Agent\SequenceStep;

/**
 * Expands a `Recipe` into an ORDERED `list<SequenceStep>` for the ONE governed door — reading
 * only the declaration, never the domain it describes. No consuming app's literal is spelled out
 * here, no entity name, and no operation special-casing beyond the one generic normalization a
 * recipe author needs: `make:<kind>` becomes the single `make` operation the runtime actually
 * offers. Everything else — what `<kind>` means, what a `foundation:found` or
 * `capabilities:enable` argument means to the app being built — is the declaration's business,
 * not this class's.
 */
final class RecipeExpander
{
    /**
     * Builds the governed step list: a `foundation:found` step ONLY when the app is proceeding
     * unfounded and the recipe declares a foundation, then one `capabilities:enable` per declared
     * capability not already installed, then the declared work in order — `make:<kind>` items
     * normalized to `make`, everything else passed through untouched.
     *
     * Refuses to build anything when the foundation frontier is `incompatible` or `indeterminate`:
     * a recipe that could found the wrong domain, or whose founding state cannot be read, is not
     * something this expander guesses its way past.
     *
     * @param list<string> $installedPackages
     *
     * @return list<SequenceStep>
     *
     * @throws \RuntimeException when the foundation frontier is `incompatible` or `indeterminate`
     */
    public function expand(Recipe $recipe, string $verdict, ?string $foundedDomain, array $installedPackages): array
    {
        $frontier = $this->foundationFrontier($recipe, $verdict, $foundedDomain);

        if ($frontier === 'incompatible' || $frontier === 'indeterminate') {
            throw new \RuntimeException(
                "recipe '{$recipe->name}' cannot proceed: foundation frontier is '{$frontier}'",
            );
        }

        $steps = [];

        if ($frontier === 'proceed-unfounded' && $recipe->foundation !== null) {
            $steps[] = new SequenceStep('foundation:found', $recipe->foundation);
        }

        foreach ($recipe->capabilities as $capability) {
            if (\in_array($capability, $installedPackages, true)) {
                continue;
            }
            $steps[] = new SequenceStep('capabilities:enable', ['capability' => $capability]);
        }

        foreach ($recipe->work as $item) {
            $op = $item['op'];
            $args = $item['args'] ?? [];

            // THE ONLY SPECIAL-CASING ALLOWED: `make:<kind>` names the `make` operation this
            // runtime actually offers, with the kind carried as an ordinary argument. Nothing
            // domain-specific decides this — the substring after `make:` is never inspected.
            if (\str_starts_with($op, 'make:')) {
                $kind = \substr($op, \strlen('make:'));
                $steps[] = new SequenceStep('make', ['what' => $kind] + $args);
                continue;
            }

            $steps[] = new SequenceStep($op, $args);
        }

        return $steps;
    }

    /**
     * Where a recipe's declared foundation stands against the app's actual founding state:
     * `proceed-unfounded` when nothing is founded yet (a foundation step may still be built);
     * `compatible` when the app is already founded and either the recipe declares no foundation
     * or its domain matches the founded one; `incompatible` when a founded domain conflicts with
     * what the recipe declares; `indeterminate` for any verdict this expander does not recognise
     * (`invalid`, `indeterminate`, or anything else) — refusing to guess rather than proceeding on
     * an unreadable founding state.
     */
    public function foundationFrontier(Recipe $recipe, string $verdict, ?string $foundedDomain): string
    {
        if ($verdict === 'unfounded') {
            return 'proceed-unfounded';
        }

        if ($verdict === 'founded') {
            if ($recipe->foundation === null) {
                return 'compatible';
            }

            return $recipe->foundation['domain'] === $foundedDomain ? 'compatible' : 'incompatible';
        }

        return 'indeterminate';
    }
}
