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
 * A {@see LivePageProvider} that asks each provider in order and returns the FIRST that serves the
 * component — the composition that lets an app keep its OWN provider while the runtime still serves
 * the screens the agent declared at runtime (greenhouse decisions/0159, the provider-chain residue).
 *
 * The container forbids re-registering a service (`ServiceRedefinitionException`, decisions/0157), so a
 * plugin cannot DECORATE the app's registered provider by replacing it. This is the shape that works:
 * {@see LivePlugin} reads the app's provider, chains the built-in {@see DeclaredScreensPageProvider}
 * behind it, and hands the chain to the page controller — which is the plugin's OWN service, registered
 * fresh, so no registration is rewritten.
 *
 * PRECEDENCE, DECLARED: the app's provider comes first. Committed app code wins for the names it serves;
 * runtime-declared screens fill in only what the app DECLINES (returns null for). A screen the app
 * already serves is never shadowed by the store — the safe default, and the reason the order is fixed
 * here rather than left to chance.
 */
final class ChainedLivePageProvider implements LivePageProvider
{
    /** @var list<LivePageProvider> */
    private readonly array $providers;

    /** @param LivePageProvider ...$providers asked in order; the first non-null answer wins */
    public function __construct(LivePageProvider ...$providers)
    {
        $this->providers = array_values($providers);
    }

    /**
     * The props from the first provider in the chain that serves `$component`, or null when none does.
     *
     * @return array<string, mixed>|null
     */
    public function propsFor(string $component, ServerRequestInterface $request): ?array
    {
        foreach ($this->providers as $provider) {
            $props = $provider->propsFor($component, $request);
            if ($props !== null) {
                return $props;
            }
        }

        return null;
    }
}
