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

namespace Milpa\AppRuntime\Support;

use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;

/**
 * A provider whose ceiling is not its own, but the one it borrows from what the app can do.
 *
 * greenhouse decisions/0027: an operation that edits a criterion of the judge carries the ceiling of
 * the heaviest thing that criterion can permit — a child does not exceed its parent. That number is
 * derived from the catalogue rather than written by hand, so it moves when the catalogue moves
 * instead of going stale in a constant.
 *
 * WHICH IS A PROBLEM OF ORDER, and this interface exists to solve it. A provider is built to produce
 * the catalogue, so at the moment it declares its operations the catalogue does not exist yet.
 * Built from `config/operations.php` it receives nothing, borrows from an empty catalogue, and
 * GOV-05 makes that the maximum of every axis — safe, and not derived from anything.
 *
 * So the catalogue is handed over in a SECOND pass, once it is complete, and the provider is asked
 * for its operations again.
 *
 * IT EXTENDS `CommandProvider` because only something that declares operations has a ceiling to
 * borrow for. Separating the two would let a class ask for the catalogue and give nothing back.
 *
 * WHAT IT IS HANDED EXCLUDES ITS OWN. Folding the borrower into its own loan is a fixed point that
 * returns the maximum: on the first pass its ceiling is the conservative Unknown, join() is monotone
 * and only rises, so the result is Unknown again — deriving nothing while looking exactly like it
 * worked. Borrowing means borrowing from what the criterion governs APART from the act that borrows.
 */
interface CatalogueBorrower extends CommandProvider
{
    /**
     * The same provider, holding the catalogue it borrows from.
     *
     * @param list<Operation> $catalogue every operation of the app EXCEPT the ones this provider
     *                                   contributed
     */
    public function withCatalogue(array $catalogue): self;
}
