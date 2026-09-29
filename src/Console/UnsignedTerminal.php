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

use Milpa\Command\Effect\Mutation;
use Milpa\Command\Operation;
use Milpa\Console\Consent;
use Milpa\Console\SequenceReceipts;

/**
 * What an unsigned call from the terminal may do in this house (greenhouse decisions/0522).
 *
 * An unsigned call proves nobody. It used to run as `local-shell` with every scope (`*`) — the terminal's
 * default — and that default is where a seat's work fell when its receipt was released: measured (evidence/1050),
 * legs 3–13 of the resident's session ran as the terminal user, all 21 build operations outside the seat's
 * governance, and the same unsigned probe, outside any session, staged a write to a plugin the seat never held.
 *
 * So the terminal's default never reaches a change that lasts. An unsigned call with no token:
 *
 * - that demands consent is the runner's to refuse — it asks for `--sign`, as it always did;
 * - that continues a sequence whose receipt still stands is the runner's too — it re-verifies the receipt and runs
 *   the call as its signer, or refuses;
 * - that declares it changes nothing that lasts (its effect profile's mutation is `none` or `ephemeral`: a read, the
 *   dev server) runs as it always did;
 * - and anything else — a declared persistent change — is refused here, before it runs, with what it declares and
 *   how to sign it. It never falls back to the terminal's wildcard.
 *
 * The line is the operation's own declaration (greenhouse decisions/0019, 0028), not the name of its scopes: the
 * house already asks it whether a call demands consent, and a change that lasts is what a signature answers for.
 */
final class UnsignedTerminal
{
    /**
     * Whether THIS call declares a change that lasts — anything but `none` or `ephemeral`, undeclared included.
     *
     * Read for the call, not for the operation in the abstract: the ceiling a call's arguments bring down
     * (`ceilingForCall`, the descent `Consent` reads too) counts — `capabilities:enable --dry-run` changes nothing. And a
     * dry run the operation's own schema declares (`dry_run`) is a plan, not a change: `make --dry-run` writes nothing,
     * and asking a signature for a preview would teach nobody anything. That second reading trusts the operation's
     * author the way its effect profile already does; an operation that declares `dry_run` and ignores it is a defect of
     * that operation (greenhouse decisions/0522).
     *
     * @param array<string, mixed> $input
     */
    public static function lasts(Operation $op, array $input = []): bool
    {
        if (($input['dry_run'] ?? false) === true && isset($op->inputSchema['properties']['dry_run'])) {
            return false;
        }

        return $op->effects === null
            || !\in_array($op->ceilingForCall($input)->mutation, [Mutation::None, Mutation::Ephemeral], true);
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
     * Whether the runner already decides this unsigned call without the terminal's wildcard: its operation demands
     * consent — the runner asks for `--sign` and runs nothing without it — or it continues a sequence whose receipt
     * stands (cited, or refused). Either way this door stands aside and says nothing of its own.
     *
     * @param array<string, mixed> $input
     */
    public static function runnerDecides(Operation $op, array $input, ?SequenceReceipts $receipts): bool
    {
        return Consent::demanded($op, $input) || self::continuesASignedSequence($op, $input, $receipts);
    }

    /**
     * The refusal owed to an unsigned call that would make a lasting change as the terminal — or null when it may run.
     *
     * @param array<string, mixed> $input
     *
     * @return list<string>|null the refusal's lines
     */
    public static function refusal(Operation $op, array $input, ?SequenceReceipts $receipts): ?array
    {
        if (self::runnerDecides($op, $input, $receipts) || !self::lasts($op, $input)) {
            return null;
        }
        $declared = $op->effects === null
            ? 'never declared its effects'
            : "declares a {$op->effects->mutation->value} change ({$op->effects->authority->value})";
        $sequence = $receipts !== null ? $op->sequenceFor($input) : null;

        return [
            "This call is not signed, and an unsigned call changes nothing that lasts: «{$op->name}» {$declared}.",
            $sequence !== null
                ? "  No signed receipt stands for «{$sequence}», so it does not continue as anyone, and it does not run as the terminal. Nothing ran."
                : '  It does not run as the terminal either. Nothing ran.',
            '  Sign it with --sign, or continue a sequence whose receipt still stands.',
        ];
    }
}
