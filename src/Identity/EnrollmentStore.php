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
 * Where the recognitions {@see IdentityEnrollment} produces are kept, and read back at admission.
 *
 * Enrollment is append-only in spirit — a recognition is a fact, and facts are not withdrawn;
 * revocation is its own governed act, undecided on purpose (decisions/0117). An implementation stores
 * what it is handed, keyed by fingerprint, and answers the one question admission asks: «what scopes,
 * if any, has the house recognized for this key?»
 */
interface EnrollmentStore
{
    /** Persist a recognition. The store keeps what it is handed and invents nothing. */
    public function record(IdentityEnrolled $enrolled): void;

    /**
     * The scopes the house recognized for this fingerprint, or null for one it never enrolled — and
     * null once revoked, because a revocation is a fact laid over the recognition, not its erasure.
     *
     * @return list<string>|null
     */
    public function scopesFor(string $fingerprint): ?array;

    /**
     * Lay a revocation over an existing recognition: the enrollment fact stays for the audit trail,
     * but the key is no longer admitted. Returns false when there was no live recognition to revoke.
     */
    public function revoke(string $fingerprint, string $revokedBy): bool;
}
