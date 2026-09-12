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
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ClientAssets;
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
        return $this->renderNode($component, $request, '');
    }

    /** Render an intact subtree, retaining the resources of every rendered node. */
    private function renderNode(ComponentDefinitionInterface $component, RenderRequest $request, string $path): RenderResult
    {
        $contract = $component::contract();
        $children = ScreenTree::children($contract->name, $request->props, $path);
        $layoutState = \is_array($request->props['layoutState'] ?? null) ? $request->props['layoutState'] : [];
        $childrenHtml = '';
        $contracts = [$contract];
        $clientAssets = ClientAssets::empty();
        $assets = [];
        $effects = [];
        foreach ($children as $index => $child) {
            $childProps = $this->applyLayoutState($child['props'] ?? [], $layoutState);
            $childComponent = ($this->factory)($child['type'], $childProps);
            $childPath = $path . 'props.children.' . $index . '.';
            if ($childComponent === null) {
                throw new InvalidScreenTree($childPath . 'type', 'unknown component type: ' . $child['type']);
            }
            $childContext = new ComponentContext(
                $request->context->componentId . '-' . $index,
                $request->context->principal,
                $request->context->locale,
                $request->context->route,
                $request->context->meta,
            );
            $childResult = $this->renderNode($childComponent, new RenderRequest($childContext, $childProps, null, $request->target), $childPath);
            $childrenHtml .= $childResult->output;
            $contracts = array_merge($contracts, $childResult->assets['componentContracts']);
            $clientAssets = $clientAssets->merge($childResult->clientAssets());
            $assets = array_merge($assets, $childResult->assets);
            $effects = array_merge($effects, $childResult->effects);
        }

        $props = $request->props;
        if (\in_array($contract->name, ScreenTree::CONTAINERS, true)) {
            $props['childrenHtml'] = $childrenHtml;
        }
        $result = $this->inner->render($component, new RenderRequest($request->context, $props, $request->state, $request->target, $request->options));

        if ($this->inner instanceof DeclaresClientAssets) {
            $clientAssets = $clientAssets->merge($this->inner->clientAssets());
        }

        return new RenderResult(
            output: $result->output,
            state: $result->state,
            assets: array_merge($assets, $result->assets, ['componentContracts' => $contracts]),
            effects: array_merge($effects, $result->effects),
            format: $result->format,
            clientAssets: $result->clientAssets()->merge($clientAssets),
        );
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
