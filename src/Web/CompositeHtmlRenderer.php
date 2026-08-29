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
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Composes a declared screen out of MANY components (greenhouse decisions/0167): a screen is one component
 * today, but a real UI is a LAYOUT. The dashboard containers (`dashboard-grid`, `dashboard-panel`, …) render
 * a `childrenHtml` string slot but never assembled it — the caller had to pre-render the children. This
 * renderer builds that tree: for a container it reads the declared `children` (each a `{ type, props }` leaf
 * or nested container), renders each through the wrapped renderer, concatenates their HTML into
 * `childrenHtml`, and then renders the container. A non-container is passed straight through.
 *
 * It is an app-runtime seam over the dispatch ({@see DispatchingHtmlRenderer}), not a milpa/live change: the
 * page controller depends on {@see ComponentRendererInterface}, so it can hold this, a plain renderer, or the
 * dispatcher. Children are instantiated by the same factory that builds a declared screen's component, so a
 * child is any declarable type — data, not code.
 */
final class CompositeHtmlRenderer implements ComponentRendererInterface
{
    /** The container contracts whose `children` this renderer composes; everything else passes through. */
    private const CONTAINERS = ['dashboard-grid', 'dashboard-panel', 'dashboard-main', 'dashboard-shell'];

    /** @var callable(string, array<string, mixed>): ?ComponentDefinitionInterface */
    private $factory;

    /**
     * @param callable(string, array<string, mixed>): ?ComponentDefinitionInterface $factory builds a child
     *                                                                                       component from its declared type and props, or null when the type is unknown
     */
    public function __construct(
        private readonly ComponentRendererInterface $inner,
        callable $factory,
    ) {
        $this->factory = $factory;
    }

    /** This composer targets whatever its wrapped renderer targets (HTML). */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $this->inner->supportsTarget($target);
    }

    /** Compose a container's declared children into its `childrenHtml`, then render it; pass a leaf through. */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        if (! \in_array($component::contract()->name, self::CONTAINERS, true)) {
            return $this->inner->render($component, $request);
        }

        $children = \is_array($request->props['children'] ?? null) ? $request->props['children'] : [];
        // The layout's shared truth (greenhouse decisions/0169): the values a child WROTE that another READS.
        // Injected by the page controller from the server-authoritative, per-session LayoutStateStore — the
        // browser never owns it. A reader child declares `filterBy` and the framework EXECUTES that relation
        // here, at render, so the coordination is a projection of one truth, not a second machine that reacts.
        $layoutState = \is_array($request->props['layoutState'] ?? null) ? $request->props['layoutState'] : [];
        $childrenHtml = '';
        $index = 0;
        foreach ($children as $child) {
            if (! \is_array($child)) {
                continue;
            }
            $type = \is_string($child['type'] ?? null) ? $child['type'] : '';
            $childProps = \is_array($child['props'] ?? null) ? $child['props'] : [];
            $childProps = $this->applyLayoutState($childProps, $layoutState);
            $childComponent = ($this->factory)($type, $childProps);
            if ($childComponent === null) {
                continue;
            }
            $childContext = new ComponentContext(
                $request->context->componentId . '-' . $index,
                $request->context->principal,
                $request->context->locale,
                $request->context->route,
            );
            // Recurse: a child may itself be a container. Leaves land on the wrapped renderer.
            $childrenHtml .= $this->render($childComponent, new RenderRequest($childContext, $childProps, null, RenderTarget::HTML))->output;
            $index++;
        }

        $props = $request->props;
        $props['childrenHtml'] = $childrenHtml;

        return $this->inner->render($component, new RenderRequest($request->context, $props, $request->state, $request->target, $request->options));
    }

    /**
     * Execute a reader child's declared RELATION to the layout's shared state (greenhouse decisions/0169): a
     * child with `filterBy: { state, column }` keeps only the rows whose `column` equals the shared `state`
     * value — and every row when that value is empty (the neutral, unfiltered truth). This is the whole of the
     * coordination: a declared relation, applied by the framework from one server-authoritative bag, never a
     * client that reacts. No `filterBy`, or no state, returns the props untouched.
     *
     * @param array<string, mixed>  $childProps
     * @param array<string, string> $layoutState
     *
     * @return array<string, mixed>
     */
    private function applyLayoutState(array $childProps, array $layoutState): array
    {
        $filterBy = $childProps['filterBy'] ?? null;
        if (! \is_array($filterBy)) {
            return $childProps;
        }
        $stateKey = \is_string($filterBy['state'] ?? null) ? $filterBy['state'] : '';
        $column = \is_string($filterBy['column'] ?? null) ? $filterBy['column'] : '';
        $wanted = $layoutState[$stateKey] ?? '';
        if ($stateKey === '' || $column === '' || $wanted === '') {
            return $childProps; // no relation, or the neutral value: the whole, unfiltered truth
        }

        $rows = \is_array($childProps['rows'] ?? null) ? $childProps['rows'] : [];
        $childProps['rows'] = array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => \is_array($row) && (string) ($row[$column] ?? '') === $wanted,
        ));

        return $childProps;
    }
}
