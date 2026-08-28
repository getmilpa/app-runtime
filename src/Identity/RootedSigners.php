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
 */
final readonly class RootedSigners
{
    /** @var list<string> the fingerprints the operator declared, uppercased and space-stripped */
    private array $fingerprints;

    /** @param list<string> $fingerprints as declared out of band; comparison ignores case and spaces */
    public function __construct(array $fingerprints)
    {
        $this->fingerprints = array_map(
            static fn (string $fp): string => strtoupper(str_replace(' ', '', $fp)),
            $fingerprints,
        );
    }

    /** True only when the operator declared this fingerprint before boot. Silence is «not rooted». */
    public function admits(string $fingerprint): bool
    {
        return \in_array(strtoupper(str_replace(' ', '', $fingerprint)), $this->fingerprints, true);
    }
}
