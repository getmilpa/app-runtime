<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * A plugin says where the work of its operations keeps state, when that is not the store of its entities
 * (greenhouse decisions/0588, rule 3).
 *
 * Work in the domain runs in the house, confined to the state it declares. By default that state is the store of
 * the entities the operation's plugin registers, and a plugin that keeps to it implements nothing. One that keeps
 * state elsewhere names it here, per operation — and the house does not believe the declaration: it enforces it
 * ({@see HouseWork}). A path that is not a place for state is refused, and the call does not run.
 */
interface DeclaresWorkState
{
    /**
     * Where each operation's work keeps its state: files written in place, or directories of their own.
     *
     * What comes back is judged, not believed: for each operation, a list of paths relative to the house root.
     * Anything else under an operation's name — a single path, an empty list, a nested one — refuses its calls.
     *
     * @return array<string, mixed> the operation's name => the list of its paths
     */
    public function workState(): array;
}
