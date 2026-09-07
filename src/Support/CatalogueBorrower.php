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
     * CALLED MORE THAN ONCE per catalogue. Two borrowers lend to each other, so the loan is solved
     * as a fixed point (greenhouse decisions/0224): every round hands this provider the solver's
     * current approximation of the OTHER borrowers' operations, and asks again. What it publishes
     * must be its floor joined upward with what it was lent — monotone in the lent ceilings, reading
     * nothing from them but `name` and `effectCeiling()` — or the solver refuses it by name: a loan
     * that came DOWN between rounds is not a loan.
     *
     * @param list<Operation> $catalogue every operation of the app EXCEPT the ones this provider
     *                                   contributed
     */
    public function withCatalogue(array $catalogue): self;

    /**
     * The same operations, each carrying only what its act does ALONE — before any loan.
     *
     * The solver seeds from here, not from the first pass: the first pass is the maximum on purpose
     * (an app that never runs the second pass fails closed), and a fixed point iterated DOWN from the
     * maximum stays there whenever two borrowers can reach each other — deriving nothing while
     * looking exactly like it worked, the failure evidence/0154 already named. Iterated UP from the
     * floors it settles at the least fixed point: the closure of what each act can reach.
     *
     * Same names, same handlers, same scopes and surfaces as `operations()`; only the ceiling
     * differs. A floor is built through `Operation`'s constructor, so it cannot say a write does not
     * mutate — and an axis it is silent on stays `Unknown` through every round, because the maximum
     * of an axis is absorbing under join (GOV-05): silence is not a floor, and the published contract
     * says so.
     *
     * @return list<Operation>
     */
    public function operationsAtTheFloor(): array;
}
