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
 * A read the HOUSE lends to its screens (greenhouse decisions/0484): code the house wrote, which a screen
 * may bind to by name without the binder ever choosing what is read or who sees it.
 *
 * {@see PublicSource} binds a screen to what an entity declared public; a house's private data (a graph, a
 * ledger) has no `PUBLIC_WHEN`, and must not grow one to be shown to its own members. A reading is the
 * house saying, in code, «this is what I show, from these arguments, to this audience». The agent that
 * declares a screen names the reading and its arguments — nothing more. The runtime judges the audience
 * on every request before {@see self::read()} is called, and declaring a screen never calls it.
 */
interface HouseReading
{
    /** The name a binding uses: `source: {reading: <name>, arguments: {…}}`. */
    public function name(): string;

    /** What the reading shows, in one sentence — what an agent reads to choose it. */
    public function summary(): string;

    /**
     * The arguments it takes, by name: `{type: string|integer|number|boolean, description}`. Every one is
     * required; a binding that omits one, adds one or passes another type is refused at declaration.
     *
     * @return array<string, array{type: string, description: string}>
     */
    public function arguments(): array;

    /**
     * The props it fills. The screen's contract must declare each; the caller does not write them.
     *
     * @return list<string>
     */
    public function fills(): array;

    /** Who may see what it reads — judged on every request, before it is read. */
    public function audience(): ReadingAudience;

    /**
     * The values, read NOW. Only the keys in {@see self::fills()} reach the component; a missing one is
     * refused by name. Throw {@see InvalidScreenTree} when the arguments name nothing this house has.
     *
     * @param array<string, mixed> $arguments already checked against {@see self::arguments()}
     *
     * @return array<string, mixed>
     */
    public function read(array $arguments): array;
}
