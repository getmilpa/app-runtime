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

use Milpa\AppRuntime\Operations\ComponentCatalogue;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;
use Milpa\Live\Components\Library;
use Milpa\Live\Components\WebLibrary;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\DeclaresComponents;

/**
 * Gathers what every booted host DECLARES about its live components.
 *
 * It reads declarations, not registrations, and the difference is the whole design. A registry
 * answers «what will this host paint right now», which depends on request state and on which host
 * won a name; `DeclaresComponents` answers «what does this plugin bring», which is static
 * ({@see ComponentDefinitionInterface::contract()} is a static method) and therefore answerable
 * from a terminal with no request in flight.
 *
 * Where it cannot determine something it says so instead of answering. That rule is inherited
 * verbatim from the agent's catalogue: a missing declaration is named in `cannotSay` rather than
 * turned into a reassuring answer nobody gave. `ComponentContract` cannot distinguish an omitted
 * value from a declared-empty one — both arrive as `''`, `null` or `[]` — so an empty value is
 * reported as unknown, not as «declared to be nothing».
 */
final class ComponentDeclarations
{
    /** The per-row fields, in the order a row's `cannotSay` names them. */
    private const array FIELDS = [
        'summary', 'propsSchema', 'stateSchema', 'actions',
        'dataSources', 'designContract', 'defaultTemplate', 'renderTargets', 'declaredBy',
    ];

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * The catalogue, optionally narrowed to one component by name.
     *
     * @param string|null $only A component name; `null` lists every declared component.
     */
    public function catalogue(?string $only = null): ComponentCatalogue
    {
        // No guard for «milpa/live is missing»: it is a hard require of this package, so that branch
        // cannot fire. A guard that cannot fire is debt that looks like robustness — the house
        // deletes those rather than testing them (greenhouse decisions/0213).
        $declarers = $this->declarers();

        /** @var array<string, array<string, mixed>> $rows keyed by component name */
        $rows = [];
        $skipped = [];

        foreach ($declarers as $declarer => $classes) {
            foreach ($classes as $class) {
                $contract = $this->contractOf($class);
                if ($contract === null) {
                    // A declaration that cannot be loaded is named, not fatal: one broken plugin
                    // must not be able to kill the catalogue for every other one.
                    $skipped[] = $class;
                    continue;
                }

                $name = $contract->name;
                if ($only !== null && $name !== $only) {
                    continue;
                }

                if (isset($rows[$name])) {
                    // Two hosts declare one name. Which one the registry binds depends on the
                    // host's registration order, which this operation does not see — so the
                    // answer is «both declared it», never a guess about which one wins.
                    $rows[$name] = $this->shared($rows[$name], $declarer);
                    continue;
                }

                $rows[$name] = $this->row($contract, $declarer);
            }
        }

        ksort($rows);

        return new ComponentCatalogue(
            ok: true,
            total: \count($rows),
            components: array_values($rows),
            sources: array_keys($declarers),
            cannotSay: $skipped === [] ? [] : ['declarations that could not be loaded: ' . implode(', ', $skipped)],
        );
    }

    /**
     * Every booted host that declares components, as declarer class => the classes it declares.
     *
     * This package's own primitives enter through {@see Library}, the same interface a plugin uses:
     * the framework has no privileged path into its own catalogue.
     *
     * @return array<class-string, list<class-string>>
     */
    private function declarers(): array
    {
        // The framework's own primitives, through the same interface a plugin uses. BOTH are present
        // unconditionally: milpa/live and milpa/live-web are hard requires of this package, so there
        // is nothing to guard — and a `class_exists` here would hide the coupling rather than state
        // it (greenhouse decisions/0225).
        //
        // 🚨 THE SECOND LINE IS A FIX, NOT SYMMETRY. Only `Library` was read, so the components that
        // ship in milpa/live-web were invisible: `brand-mark` had been on disk since it was written
        // and no catalogue row ever said so. The catalogue exists to stop the agent inventing a
        // component that already exists, which it cannot do for a component it cannot see
        // (greenhouse decisions/0214, measured absent in 0299).
        $declarers = [
            Library::class => (new Library())->declaredComponents(),
            WebLibrary::class => (new WebLibrary())->declaredComponents(),
        ];

        $kernel = $this->container->getContainer()->has(Kernel::class)
            ? $this->container->get(Kernel::class)
            : null;

        if (!$kernel instanceof Kernel) {
            return $declarers;
        }

        // AS THE KERNEL BOOTS THEM: a plugin that was configured but vetoed contributes nothing,
        // and a row for it would describe a house that does not exist.
        $booted = $kernel->bootedPluginNames();
        foreach ($kernel->plugins() as $plugin) {
            if (!$plugin instanceof DeclaresComponents) {
                continue;
            }
            if (!$this->hasBooted($plugin, $booted)) {
                continue;
            }

            $declarers[$plugin::class] = $plugin->declaredComponents();
        }

        return $declarers;
    }

    /**
     * Whether the kernel actually booted this plugin.
     *
     * @param list<string> $booted
     */
    private function hasBooted(object $plugin, array $booted): bool
    {
        foreach ((new \ReflectionClass($plugin))->getAttributes() as $attribute) {
            if (!str_ends_with($attribute->getName(), 'PluginMetadata')) {
                continue;
            }

            $name = $attribute->getArguments()['name'] ?? ($attribute->getArguments()[0] ?? null);

            return \is_string($name) && \in_array($name, $booted, true);
        }

        return false;
    }

    /**
     * A component's contract, or `null` when the declared class cannot answer for one.
     *
     * @param class-string $class
     */
    private function contractOf(string $class): ?object
    {
        if (!class_exists($class) || !is_subclass_of($class, ComponentDefinitionInterface::class)) {
            return null;
        }

        try {
            return $class::contract();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One row: every value the contract actually declares, plus the names of what it did not.
     *
     * @param class-string $declarer
     *
     * @return array<string, mixed>
     */
    private function row(object $contract, string $declarer): array
    {
        $values = [
            'summary' => $contract->summary ?? '',
            'propsSchema' => $contract->propsSchema ?? [],
            'stateSchema' => $contract->stateSchema ?? [],
            'actions' => $this->actions($contract),
            'dataSources' => $contract->dataSources ?? [],
            'designContract' => $contract->designContract ?? null,
            'defaultTemplate' => $contract->defaultTemplate ?? null,
            'renderTargets' => $this->renderTargets(),
            'declaredBy' => $declarer,
        ];

        $row = ['name' => $contract->name, 'contractVersion' => $contract->contractVersion];
        $cannotSay = [];

        foreach (self::FIELDS as $field) {
            $value = $values[$field];
            if ($value === null || $value === '' || $value === []) {
                $cannotSay[] = $field;
                continue;
            }

            $row[$field] = $value;
        }

        $row['cannotSay'] = $cannotSay;

        return $row;
    }

    /**
     * The same row once a SECOND host declares the same component name.
     *
     * @param array<string, mixed> $row
     * @param class-string         $declarer
     *
     * @return array<string, mixed>
     */
    private function shared(array $row, string $declarer): array
    {
        $already = $row['declaredByAny'] ?? [];
        if ($already === [] && isset($row['declaredBy'])) {
            $already = [$row['declaredBy']];
        }

        $already[] = $declarer;
        unset($row['declaredBy']);
        $row['declaredByAny'] = array_values(array_unique($already));

        $cannotSay = $row['cannotSay'] ?? [];
        if (!\in_array('declaredBy', $cannotSay, true)) {
            $cannotSay[] = 'declaredBy';
        }
        $row['cannotSay'] = $cannotSay;

        return $row;
    }

    /**
     * Which render targets can paint a component — unanswerable today, and it says so.
     *
     * Nothing binds a renderer registry in the container, and `resolveFor()` lives on the concrete
     * registry rather than on `ComponentRendererRegistryInterface`, so there is no contract to ask.
     * Returning `[]` puts `renderTargets` in every row's `cannotSay`, which is the honest answer:
     * inventing «html» because most components are HTML would be a claim nobody made.
     *
     * @return list<string>
     */
    private function renderTargets(): array
    {
        return [];
    }

    /**
     * What each action IS, read through the contract so both declaration shapes answer alike.
     *
     * Every row carries `payload` and `declaresEffects`, and carries `summary`, `mutating`, `effects` and
     * `namedTarget` only when the component actually declared them. That asymmetry is the point: an action
     * that declared nothing must not read as an action that declared «harmless». `declaresEffects` is the
     * field a reader — human or agent — checks FIRST, because «nobody said» is neither a yes nor a no.
     *
     * Normalised to maps so one action never encodes as `[]` while another encodes as an object: an agent
     * reading the catalogue would face a union type in a single field.
     *
     * @return array<string, array<string, mixed>>
     */
    private function actions(object $contract): array
    {
        $normalised = [];
        foreach (array_keys($contract->actions ?? []) as $name) {
            $name = (string) $name;
            $action = $contract->action($name);
            if ($action === null) {
                continue;
            }

            $row = ['payload' => $action->payload, 'declaresEffects' => $action->declaresEffects()];
            if ($action->summary !== '') {
                $row['summary'] = $action->summary;
            }
            if ($action->declaresEffects()) {
                $row['mutating'] = $action->mutating;
                $row['effects'] = $action->effects?->toArray() ?? [];
            }
            if ($action->namedTarget !== null) {
                $row['namedTarget'] = $action->namedTarget;
            }
            if ($action->scopeBy !== null) {
                $row['scopeBy'] = $action->scopeBy;
            }

            $normalised[$name] = $row;
        }

        return $normalised;
    }
}
