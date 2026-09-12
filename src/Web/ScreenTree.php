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

/** The tree grammar shared by declaration and rendering; no malformed child is silently discarded. */
final class ScreenTree
{
    public const CONTAINERS = ['dashboard-grid', 'dashboard-panel', 'dashboard-main', 'dashboard-shell'];

    /**
     * Validate every node before the store receives a write. An empty type list leaves type lookup to the host.
     *
     * @param array<string, mixed> $props
     * @param list<string>         $types
     */
    public static function validate(string $type, array $props, array $types, string $path = ''): void
    {
        if ($types !== [] && ! \in_array($type, $types, true)) {
            throw new InvalidScreenTree($path . 'type', 'unknown component type: ' . $type);
        }
        foreach (self::children($type, $props, $path) as $index => $child) {
            self::validate($child['type'], $child['props'] ?? [], $types, $path . 'props.children.' . $index . '.');
        }
    }

    /**
     * Read only well-formed child declarations, preserving their order and location.
     *
     * @param array<string, mixed> $props
     *
     * @return list<array{type: string, props?: array<string, mixed>}>
     */
    public static function children(string $type, array $props, string $path = ''): array
    {
        if (! array_key_exists('children', $props)) {
            return [];
        }
        $at = $path . 'props.children';
        if (! \in_array($type, self::CONTAINERS, true)) {
            throw new InvalidScreenTree($at, 'component does not compose children: ' . $type);
        }
        $children = $props['children'];
        if (! \is_array($children) || ! array_is_list($children)) {
            throw new InvalidScreenTree($at, 'children must be a list');
        }
        foreach ($children as $index => $child) {
            if (! \is_array($child)) {
                throw new InvalidScreenTree($at . '.' . $index, 'child must be an object');
            }
            if (! \is_string($child['type'] ?? null) || trim($child['type']) === '') {
                throw new InvalidScreenTree($at . '.' . $index . '.type', 'child must name a component type');
            }
            if (array_key_exists('props', $child) && (! \is_array($child['props']) || ($child['props'] !== [] && array_is_list($child['props'])))) {
                throw new InvalidScreenTree($at . '.' . $index . '.props', 'props must be an object');
            }
        }

        return $children;
    }
}
