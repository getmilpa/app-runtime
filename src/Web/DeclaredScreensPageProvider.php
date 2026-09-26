<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\AppRuntime\Auth\LivePrincipal;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The built-in {@see LivePageProvider} for runtime-declared screens (greenhouse decisions/0158): it
 * hands the props for any screen the agent authored through `screen:declare`, read straight from the
 * {@see ScreenStore}, and null for anything it did not declare (the page controller answers 404, never
 * a page with invented data — decisions/0092).
 *
 * {@see LivePlugin} registers it ONLY when the app has not registered its own `LivePageProvider`, so a
 * fresh app that enables the live door serves declared screens with zero wiring, while an app that owns
 * its data keeps ownership. Composing declared screens WITH an app's own provider (a provider chain) is
 * a deliberate residue, not this class's job.
 */
final class DeclaredScreensPageProvider implements LivePageProvider
{
    public function __construct(
        private readonly ScreenStore $store,
        /**
         * Resolves a container service by id, or null when absent — how a BOUND screen finds its
         * entity's repository (greenhouse decisions/0462). Null: a bound screen answers 422, never rows.
         *
         * @var \Closure(string): ?object|null
         */
        private readonly ?\Closure $service = null,
        /** The house's words — what a reading bound to a word's inputs is compiled through (decisions/0484). */
        private readonly ?ComponentWords $words = null,
    ) {
    }

    /**
     * The stored props for a runtime-declared screen, or null when `$component` was not declared.
     *
     * @return array<string, mixed>|null
     */
    public function propsFor(string $component, ServerRequestInterface $request): ?array
    {
        $screen = $this->store->screen($component);
        if ($screen === null) {
            return null;
        }

        // The stored props pass through verbatim — a data-table's columns/rows, a state-machine's `machine`
        // spec, whatever the component's contract declares. The type is fixed at registration (LivePlugin
        // registers the screen under the class for its type); here the framework only supplies the data.
        $props = $screen['props'];
        // A binding is an OBJECT; a string `source` is the component's own prop (autocomplete's data
        // source name), passed through untouched (greenhouse decisions/0464).
        if (! \is_array($props['source'] ?? null)) {
            return $props;
        }

        // A BOUND screen (greenhouse decisions/0462): its rows are read NOW, through the entity's own
        // declared visibility, and the binding itself never reaches the component. Anything that cannot
        // be satisfied throws InvalidScreenTree — the page answers 422, never an empty or leaked table.
        $source = $props['source'];
        unset($props['source']);
        $service = $this->service ?? static fn (string $id): ?object => null;
        // A READING THE HOUSE LENDS (greenhouse decisions/0484): its audience is judged against this request's
        // principal BEFORE anything is read — a denied request raises ReadingDenied and carries no value.
        if (ReadingSource::is($source)) {
            $word = \is_array($source['word'] ?? null) ? $source['word'] : null;
            unset($source['word']);
            $readings = $service(HouseReadings::class);
            $values = ReadingSource::fill(
                $source,
                $readings instanceof HouseReadings ? $readings : null,
                LivePrincipal::fromRequest($request),
            );
            if ($word === null) {
                return [...$props, ...$values];
            }

            // Bound through a WORD: the word is compiled again with what was read, and it must still be the
            // word this screen was declared with — a word re-taught since is refused, never half-applied.
            $name = (string) ($word['name'] ?? '');
            $current = $this->words?->word($name);
            if ($current === null || (int) $current['version'] !== (int) ($word['version'] ?? 0)) {
                throw new InvalidScreenTree('type', "the word «{$name}» this screen was declared with has changed or is gone; declare the screen again");
            }
            $compiled = $this->words->compile($name, [...(\is_array($word['inputs'] ?? null) ? $word['inputs'] : []), ...$values]);

            return [...$props, ...$compiled['props']];
        }
        // A COUNT fills the value (greenhouse decisions/0478): how many public rows there are, read now.
        if (($source['count'] ?? null) === true) {
            $props['value'] = (string) PublicSource::count($source, $service);

            return $props;
        }
        $props['rows'] = PublicSource::rows($source, $service);

        return $props;
    }
}
