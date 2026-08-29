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
    public function __construct(private readonly ScreenStore $store)
    {
    }

    /** @return array<string, mixed>|null */
    public function propsFor(string $component, ServerRequestInterface $request): ?array
    {
        $screen = $this->store->screen($component);
        if ($screen === null) {
            return null;
        }

        return [
            'columns' => \is_array($screen['columns'] ?? null) ? $screen['columns'] : [],
            'rows' => \is_array($screen['rows'] ?? null) ? $screen['rows'] : [],
        ];
    }
}
