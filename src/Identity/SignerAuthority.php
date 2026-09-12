<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Identity;

use Milpa\AppRuntime\Policy\PolicyProvider;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\VerifiedSigner;

/** The house's recognition of a CURRENT verified signer, never a session's stored owner. */
final readonly class SignerAuthority
{
    public function __construct(private FileEnrollmentStore $enrollments, private ?PolicyProvider $policy = null)
    {
    }

    /**
     * Resolve current scopes, preserving the terminal fallback only for a key never recognized.
     * A revoked recognition is an empty authority; a static policy cannot revive it.
     *
     * @throws \RuntimeException when the enrollment ledger cannot be read
     */
    public function forSigner(VerifiedSigner $signer): ?ToolContext
    {
        $scopes = $this->enrollments->contains($signer->fingerprint)
            ? ($this->enrollments->scopesFor($signer->fingerprint) ?? [])
            : $this->policy?->scopesForSigner($signer->fingerprint);

        return $scopes === null ? null : new ToolContext(
            principal: 'key:' . $signer->fingerprint,
            channel: 'cli',
            scopes: $scopes,
        );
    }
}
