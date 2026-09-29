<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Console;

use Milpa\Command\Operation;
use Milpa\Console\Consent;
use Milpa\Console\McpProjector;
use Milpa\Console\SequenceReceipts;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;

/**
 * What an unsigned call from the terminal may do in this house (greenhouse decisions/0522).
 *
 * An unsigned call proves nobody. It used to run as `local-shell` with every scope (`*`) — the terminal's
 * default — and that default is where a seat's work fell when its receipt was released: measured (evidence/1050),
 * legs 3–13 of the resident's session ran as the terminal user, all 21 build operations outside the seat's
 * governance, and the same unsigned probe, outside any session, staged a write to a plugin the seat never held.
 *
 * So the terminal's default stops being an authority. An unsigned call either CONTINUES a signed sequence whose
 * receipt still stands — the runner re-verifies it and the call runs as its signer — or it runs with READING
 * only: the scopes its operation declares that read (`<area>:read`), judged by the same gate and the same host
 * policy as any call. Anything that asks for more is refused with what it lacks, and nothing runs. It never falls
 * back to the terminal's wildcard.
 *
 * WHAT STAYS AS IT WAS: a signed call (`--sign`), which the runner authorizes and bounds by who the signer is; a
 * presented token, bounded by its own scopes; and every operation that declares no scope and that the host's
 * policy does not refuse a finite principal — `list`, `test`, `serve`.
 */
final class UnsignedTerminal
{
    /**
     * The authority an unsigned call runs with when no receipt stands for it: the terminal, reading only.
     */
    public static function authority(Operation $op): ToolContext
    {
        $terminal = ToolContext::cli();

        return new ToolContext(
            principal: $terminal->principal,
            channel: $terminal->channel,
            scopes: array_values(array_filter($op->scopes, static fn (string $scope): bool => str_ends_with($scope, ':read'))),
        );
    }

    /**
     * Whether a receipt stands for the sequence this call continues — the runner then cites it or refuses.
     *
     * @param array<string, mixed> $input
     */
    public static function continuesASignedSequence(Operation $op, array $input, ?SequenceReceipts $receipts): bool
    {
        if ($receipts === null) {
            return false;
        }
        $sequence = $op->sequenceFor($input);

        return $sequence !== null && $receipts->standing($sequence) !== null;
    }

    /**
     * Whether the runner already decides this unsigned call without the terminal's wildcard: it continues a
     * sequence whose receipt stands (cited, or refused), or its operation demands consent — the runner asks for
     * `--sign` and runs nothing without it. Either way this door stands aside and says nothing of its own.
     *
     * @param array<string, mixed> $input
     */
    public static function runnerDecides(Operation $op, array $input, ?SequenceReceipts $receipts): bool
    {
        return Consent::demanded($op, $input) || self::continuesASignedSequence($op, $input, $receipts);
    }

    /**
     * The refusal owed to an unsigned call that asks for more than reading — or null when it may run reading only.
     *
     * @param array<string, mixed> $input
     *
     * @return list<string>|null the refusal's lines
     */
    public static function refusal(Operation $op, array $input, ?CallPolicy $policy, ?SequenceReceipts $receipts): ?array
    {
        $gate = new PolicyGate();
        if ($policy !== null) {
            $gate->setCallPolicy($policy);
        }
        $verdict = $gate->authorizeCall(self::authority($op), new ToolDefinition(
            McpProjector::toolName($op->name),
            $op->description,
            $op->inputSchema ?? [],
            $op->handler,
            scopes: $op->scopes,
            mutating: $op->mutating,
        ), $input);
        if ($verdict->allowed) {
            return null;
        }
        $sequence = $receipts !== null ? $op->sequenceFor($input) : null;

        return [
            "This call is not signed, and an unsigned call runs only what reads: «{$op->name}» needs more — " . rtrim((string) $verdict->reason, '.') . '.',
            $sequence !== null
                ? "  No signed receipt stands for «{$sequence}», so it does not continue as anyone, and it does not run as the terminal. Nothing ran."
                : '  It does not run as the terminal either. Nothing ran.',
            '  Sign it with --sign, or continue a sequence whose receipt still stands.',
        ];
    }
}
