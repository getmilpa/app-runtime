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
 * The ONE rule for comparing identity keys — the root and the ledger read it, so they cannot disagree.
 *
 * Two kinds of key reach the identity model since the convergence of decisions/0125: a gpg FINGERPRINT
 * (40+ hex digits, which people paste with spaces and in either case) and a passkey's CREDENTIAL ID
 * (base64url, case-sensitive, where `AbC` and `abc` are two different credentials). The root used to
 * uppercase everything and the ledger learned not to — which meant a base64url id declared in
 * `config/identity.php` was admitted by the root under a casing the ledger would never store. One
 * normalization, in one place, closes that gap (greenhouse decisions/0206, review).
 */
final class IdentityKey
{
    // `\n?\z`, written out, is exactly what `$` used to accept (greenhouse decisions/0624, point 3). Here a stricter end
    // would WIDEN authority: a seat that stops being a fingerprint stops having its ledger closures applied.
    private const HEX_FINGERPRINT = '/\A[0-9a-fA-F ]{40,}\n?\z/';

    /** Whether this key is a gpg fingerprint — a signing key, as a resident's seat is — rather than a passkey's id. */
    public static function isFingerprint(string $key): bool
    {
        return preg_match(self::HEX_FINGERPRINT, $key) === 1;
    }

    /**
     * The comparable form of a key: a hex fingerprint is uppercased and space-stripped; anything else
     * — a base64url credential id above all — is returned exactly as given.
     */
    public static function normalize(string $key): string
    {
        if (preg_match(self::HEX_FINGERPRINT, $key) === 1) {
            return strtoupper(str_replace(' ', '', $key));
        }

        return $key;
    }
}
