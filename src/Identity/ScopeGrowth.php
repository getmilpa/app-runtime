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

namespace Milpa\AppRuntime\Identity;

/**
 * An identity's scopes grow with what it installs — and only with what that install declares
 * (greenhouse decisions/0498).
 *
 * The first human is not handed everything up front. When an enrolled principal installs a capability,
 * the same act re-recognizes it with the scopes that capability declares its OPERATOR needs
 * (`extra.milpa.capability.operator_scopes`): installing the agent's workspace lets its installer read
 * and answer the agent. This is not an escalation — whoever may install a capability may already put
 * code that runs in the house, and operating it is less than that — and the scopes come from the
 * package's declaration, never from the caller (the shape of decisions/0493: the house derives, nobody
 * types).
 *
 * THE LINE IS KEPT. The new state keeps the enroller that answers for the identity in `authorized_by`,
 * and names its cause in `grown_by`; the state it replaces goes onto the history (decisions/0207). Were
 * the growth recorded as authorized by the identity itself, {@see EnrollmentLine} would stop seeing that
 * the key which enrolled it answers for it, and the frontier of 0493 would close on its own human.
 *
 * A scope nothing installed declares stays a refusal (decisions/0317).
 */
final readonly class ScopeGrowth
{
    public function __construct(private FileEnrollmentStore $enrollments)
    {
    }

    /**
     * Add the declared operator scopes to this principal's live recognition, or do nothing.
     *
     * @param string       $principal  who installed, as `passkey:<id>` or `key:<fingerprint>`
     * @param list<string> $declared   the operator scopes the installed capability declares
     * @param string       $capability the package that declared them
     *
     * @return list<string> the scopes newly granted — empty when there was nothing to add, or nobody live to add it to
     *
     * @throws \RuntimeException when the ledger could not be written
     */
    public function grow(string $principal, array $declared, string $capability): array
    {
        $key = EnrollmentLine::keyOf($principal);
        if ($key === null) {
            return [];
        }
        $current = $this->enrollments->scopesFor($key);
        $enroller = $this->enrollments->authorizedBy($key);
        if ($current === null || $enroller === null) {
            // Not a live recognition: an operator key the ledger never enrolled holds its authority at the
            // terminal, and a revoked one holds none. Neither grows here.
            return [];
        }

        $added = array_values(array_diff(
            array_values(array_unique(array_filter($declared, static fn (string $s): bool => trim($s) !== ''))),
            $current,
        ));
        if ($added === []) {
            return [];
        }

        $this->enrollments->recordAndReport(new IdentityEnrolled(
            $key,
            [...$current, ...$added],
            $enroller,
            ['principal' => $principal, 'capability' => $capability],
        ), keepAdmissions: true);

        return $added;
    }
}
