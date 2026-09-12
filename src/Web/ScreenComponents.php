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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Component\ListsComponents;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Screen aliases over the host's live registry. Registration, declaration, GET and POST share the same
 * component instances and per-contract renderers (Greenhouse 0327). Aliases are resolved on demand so a
 * plugin registering after LivePlugin boots is visible without rebuilding the endpoint.
 */
final readonly class ScreenComponents implements ComponentRegistryInterface, ListsComponents
{
    public function __construct(
        private ComponentRegistryInterface $components,
        private ComponentRendererRegistry $renderers,
        private ScreenStore $screens,
    ) {
    }

    /** A registered name, or a stored screen whose root type is registered. */
    public function has(string $name): bool
    {
        return $this->components->has($name) || $this->typeOf($name) !== null;
    }

    /** Resolve a screen to its actual component; no class name is ever supplied by a screen declaration. */
    public function get(string $name): ComponentDefinitionInterface
    {
        if ($this->components->has($name)) {
            return $this->components->get($name);
        }
        $type = $this->typeOf($name);
        if ($type === null) {
            throw new \RuntimeException('Component not registered: ' . $name);
        }

        return $this->components->get($type);
    }

    /** Register through the existing host registry; aliases remain a projection of the screen store. */
    public function register(string $name, ComponentDefinitionInterface $component): void
    {
        $this->components->register($name, $component);
    }

    /**
     * List registered names and currently resolvable screen aliases.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $registered = $this->components instanceof ListsComponents ? $this->components->names() : [];

        return array_values(array_unique([...$registered, ...array_filter($this->screens->names(), $this->has(...))]));
    }

    /**
     * Types that can both mount and re-render after an action. An opaque registry remains usable by
     * name but cannot advertise names it does not enumerate; a descriptive class catalogue is not a factory.
     *
     * @return array{types: list<array{name: string, contractVersion: string}>, unavailable: list<array{name: string, reason: string}>}
     */
    public function catalogue(): array
    {
        $types = [];
        $unavailable = [];
        $names = $this->components instanceof ListsComponents ? $this->components->names() : [];
        foreach ($names as $name) {
            $contract = $this->components->get($name)::contract();
            $reason = match (true) {
                $contract->name !== $name => 'register the component under its contract name so actions resolve the same definition',
                $this->renderers->resolveFor($name, RenderTarget::HTML) === null => 'no HTML renderer registered for this contract',
                default => null,
            };
            if ($reason !== null) {
                $unavailable[] = ['name' => $name, 'reason' => $reason];
            } else {
                $types[] = ['name' => $name, 'contractVersion' => $contract->contractVersion];
            }
        }

        return ['types' => $types, 'unavailable' => $unavailable];
    }

    /**
     * Return the same available names the discovery operation advertises.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_column($this->catalogue()['types'], 'name');
    }

    /** A stored screen cannot replace a registered component with another contract under the same name. */
    public function conflicts(string $name, string $type): bool
    {
        return $this->components->has($name) && $this->components->get($name)::contract()->name !== $type;
    }

    private function typeOf(string $name): ?string
    {
        $screen = $this->screens->screen($name);
        $type = $screen['type'] ?? null;

        return \is_string($type) && $this->components->has($type) ? $type : null;
    }
}
