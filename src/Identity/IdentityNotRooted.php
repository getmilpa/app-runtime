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
 * Raised when enrollment is asked to recognize a fingerprint the root never declared.
 *
 * It is the fail-closed gate of {@see IdentityEnrollment}, made visible: enrollment consumes a root
 * of trust and cannot mint one, so a fingerprint absent from {@see RootedSigners} is refused rather
 * than quietly added. The Operation layer turns this into a DENY.
 */
final class IdentityNotRooted extends \RuntimeException
{
    public static function forFingerprint(string $fingerprint): self
    {
        return new self(
            'the fingerprint ' . $fingerprint . ' is not in the out-of-band root; enrollment consumes '
            . 'a root of trust, it does not create one',
        );
    }
}
