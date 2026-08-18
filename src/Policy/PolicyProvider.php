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

namespace Milpa\AppRuntime\Policy;

use Milpa\Command\Effect\AuthorityPolicy;

/**
 * What an APP declares about who it trusts and how it judges — in reviewed code, never in runtime
 * data (greenhouse decisions/0056).
 *
 * Two answers, one boundary each:
 *
 *   · `authorityPolicy()` is the institution decisions/0054 built: the ONE producer with the right
 *     to judge the authority axis, versioned by the digest of its rules.
 *   · `scopesForSigner()` is the trust anchor decisions/0056 added: `gpg --verify` only proves
 *     possession of SOME key in a keyring — and whoever controls GNUPGHOME puts their own there.
 *     What turns possession into identity is the app declaring which fingerprints it recognises,
 *     and with which scopes. `null` means «this app does not know that signer», and an unknown
 *     signer is never admitted, however well their signature verifies.
 *
 * It is the third time the same pattern anchors trust in reviewed code: the certifier's public key
 * (decisions/0051), the authority policy (0054), and now the signer registry.
 */
interface PolicyProvider
{
    /** The institution that judges authority for this app, or null while it declares none. */
    public function authorityPolicy(): ?AuthorityPolicy;

    /**
     * The scopes this app grants the holder of a key — or null for a signer it does not recognise.
     *
     * @return list<string>|null
     */
    public function scopesForSigner(string $fingerprint): ?array;
}
