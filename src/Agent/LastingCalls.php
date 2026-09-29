<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AppRuntime\Console\UnsignedTerminal;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;

/**
 * Whether a recorded call changed the house in a way that lasts — read from the operation's OWN declaration
 * (greenhouse decisions/0523).
 *
 * Measured (evidence/1050): the resident built /blog, the house observed it served, and then the resident ran its
 * green test. `HouseObservedClosure` counted every succeeded mutating call as a change to the house, and `test` is
 * mutating (it runs the app's code), so the observation went stale and the closure stayed `verified: false` for
 * nine legs. The same stream without the three test calls closes `verified: true` on /blog.
 *
 * The line is the one the terminal's unsigned door already draws ({@see UnsignedTerminal::lasts()}, decisions/0522):
 * a call lasts unless its operation's ceiling FOR THOSE ARGUMENTS declares a `none` or `ephemeral` mutation, or it
 * is a dry run its schema declares. One declaration answers both questions — «may the terminal run this unsigned?»
 * and «did this change the house?» — so they cannot drift apart. An operation that never declared its effects lasts
 * (GOV-05: the unknown never lowers a control).
 *
 * Read against the house's catalogue as it is NOW, not as it was when the call ran: a replay of an old stream is
 * judged by today's declarations, which is what makes it deterministic. A tool the catalogue does not hold (the
 * session's own notebook, delegation) answers null, and the caller keeps the recorded `mutating` flag for it.
 */
final class LastingCalls
{
    /**
     * The classifier over one catalogue: tool name (as the stream records it) and arguments → whether it lasts,
     * or null for a tool this catalogue does not declare.
     *
     * @param iterable<Operation> $catalogue
     *
     * @return \Closure(string, array<string, mixed>): ?bool
     */
    public static function of(iterable $catalogue): \Closure
    {
        $byTool = [];
        foreach ($catalogue as $operation) {
            $byTool[McpProjector::toolName($operation->name)] = $operation;
            $byTool[$operation->name] ??= $operation;
        }

        return static function (string $tool, array $arguments) use ($byTool): ?bool {
            $operation = $byTool[$tool] ?? null;

            return $operation === null ? null : UnsignedTerminal::lasts($operation, $arguments);
        };
    }
}
