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

/**
 * A screen tree judged against its components' own contracts, root and children alike (greenhouse
 * decisions/0479). One judge for every door that takes a tree — `screen:declare` and `component:define` —
 * so the rule a root obeys is the rule its children obey.
 *
 * A required prop nobody gives is refused by path. An undeclared prop is NAMED, not refused: contracts are not
 * complete (autocomplete's renderer reads `options`, which its contract does not declare), so refusing it
 * would break what works. What the house fills is not asked of the caller: `children` and `source` are
 * structure and `name` is the store's; the root's `filled` list adds what its door fills (a binding's rows or
 * value, the table's top-level columns/rows).
 */
final class ContractJudge
{
    /**
     * Judge a tree against its components' contracts: refuse the first missing required prop, name the rest.
     *
     * @param array<array-key, mixed>             $node       a screen tree: {type, props}
     * @param array<string, array<string, mixed>> $schemas    each type's propsSchema
     * @param list<string>                        $rootFilled what the door fills at the root
     * @param string                              $path       where the node's props live, for the refusal
     *
     * @return list<string> the paths of undeclared props, to be named
     *
     * @throws InvalidScreenTree naming the first required prop nobody gave
     */
    public static function judge(array $node, array $schemas, array $rootFilled = [], string $path = 'props'): array
    {
        $type = \is_string($node['type'] ?? null) ? $node['type'] : '';
        $props = \is_array($node['props'] ?? null) ? $node['props'] : [];
        $schema = $schemas[$type] ?? [];
        $filled = [...['name', 'children', 'source'], ...$rootFilled];
        $ignored = [];
        if ($schema !== []) {
            foreach (array_keys($props) as $prop) {
                if (! \array_key_exists((string) $prop, $schema) && ! \in_array($prop, $filled, true)) {
                    $ignored[] = "{$path}.{$prop}";
                }
            }
            foreach ($schema as $prop => $spec) {
                if (\is_array($spec) && ($spec['required'] ?? false) === true
                    && ! \array_key_exists($prop, $props) && ! \in_array($prop, $filled, true)
                ) {
                    throw new InvalidScreenTree("{$path}.{$prop}", "«{$type}» needs «{$prop}»" . (\is_string($spec['description'] ?? null) ? ': ' . $spec['description'] : ''));
                }
            }
        }
        foreach (\is_array($props['children'] ?? null) ? $props['children'] : [] as $index => $child) {
            if (\is_array($child)) {
                $ignored = [...$ignored, ...self::judge($child, $schemas, [], "{$path}.children.{$index}.props")];
            }
        }

        return $ignored;
    }
}
