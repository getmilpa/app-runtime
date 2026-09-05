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
 * Whom the house is willing to BELIEVE — the set of key fingerprints declared out of band, before the
 * app boots, by the operator who runs it.
 *
 * This is the root of trust, and it answers exactly one question: «is this fingerprint one I am
 * prepared to credit at all?» It does NOT say what that key may do (that is policy) nor that the house
 * recognizes an institutional identity for it (that is enrollment). It is deliberately read-only at
 * runtime: a root a running session could extend would let a signer vouch for itself, and then the
 * root would authenticate the very enrollment that was supposed to consume it — a circular bootstrap.
 * The only way in is out of band (decisions/0117, H-ENROLL-1).
 *
 * Keys are compared through {@see IdentityKey::normalize()}, the SAME rule the enrollment ledger uses:
 * a hex fingerprint matches regardless of case and spaces; a passkey's base64url credential id matches
 * only with its exact casing — the root used to uppercase those too, admitting an id under a form the
 * ledger would never store (greenhouse decisions/0206, review).
 */
final readonly class RootedSigners
{
    /** @var list<string> the keys the operator declared, in their comparable form */
    private array $fingerprints;

    /** @param list<string> $fingerprints as declared out of band; hex fingerprints compare ignoring case and spaces */
    public function __construct(array $fingerprints)
    {
        $this->fingerprints = array_map(
            static fn (string $fp): string => IdentityKey::normalize($fp),
            $fingerprints,
        );
    }

    /** True when the operator declared no rooted signer at all — the app opts out of identity by root. */
    public function isEmpty(): bool
    {
        return $this->fingerprints === [];
    }

    /** True only when the operator declared this key before boot. Silence is «not rooted». */
    public function admits(string $fingerprint): bool
    {
        return \in_array(IdentityKey::normalize($fingerprint), $this->fingerprints, true);
    }
}
