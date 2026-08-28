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
 * Recognizes an institutional identity for a fingerprint the root already declared — or refuses.
 *
 * The three rights stay separate (decisions/0117): the ROOT says whom the house will believe (out of
 * band); ENROLLMENT — this — says which institutional identity it recognizes under that root; POLICY
 * says what that identity may do. This class owns only the middle right, and its first and hardest
 * gate is the first: a fingerprint the root never declared is refused, because enrolling an identity
 * consumes a root of trust and never mints the one that would authorize it.
 */
final readonly class IdentityEnrollment
{
    public function __construct(private RootedSigners $root)
    {
    }

    /**
     * @param list<string> $scopes       what policy assigns this identity at the moment of recognition
     * @param string       $authorizedBy the verified principal running the enrollment (key:<fingerprint>)
     *
     * @throws IdentityNotRooted when the fingerprint is not in the out-of-band root
     */
    public function enroll(string $fingerprint, array $scopes, string $authorizedBy): IdentityEnrolled
    {
        if (!$this->root->admits($fingerprint)) {
            throw IdentityNotRooted::forFingerprint($fingerprint);
        }

        return new IdentityEnrolled($fingerprint, $scopes, $authorizedBy);
    }
}
