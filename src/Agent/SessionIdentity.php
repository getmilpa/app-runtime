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

namespace Milpa\AppRuntime\Agent;

use Milpa\AppRuntime\Policy\PolicyProvider;
use Milpa\Command\Effect\ContextFacts;
use Milpa\Command\Effect\VerifiedPrincipal;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\SignatureVerifier;

/**
 * The ADMISSION: a stored ownership assertion becomes verified ContextFacts, or it becomes nothing
 * (greenhouse decisions/0056).
 *
 * What a session stores is the signed assertion — never a grade. `evidence/0254` measured why: a
 * stored `verified` flag was forged with plausible strings and authority came down, so the grade is
 * produced HERE, each time, by re-verifying the signature live and checking every binding. This is
 * the receipt doctrine of decisions/0053 applied to identity: cheap to re-derive, so it is re-derived,
 * and nothing stored can go stale or be talked up.
 *
 * FIVE REFUSALS, each one a falsifier of the acta, each one fail-closed to null:
 *
 *   · the signature does not verify live                        → the forge of evidence/0254
 *   · the live signer contradicts the stored fingerprint        → tampering, not convenience drift
 *   · the payload is not a `session:own` authorization,
 *     or it names ANOTHER session                               → cross-session replay
 *   · the app's registry does not know the signer               → possession is not identity
 *
 * Null is never an error: a session without an admissible owner simply behaves as sessions always
 * have — unowned, unverified, and unable to feed the authority judge.
 */
final readonly class SessionIdentity
{
    public function __construct(
        private SignatureVerifier $verifier,
        private PolicyProvider $policy,
    ) {
    }

    /**
     * Admit an ownership assertion for THIS session, or refuse.
     *
     * @param array<string, mixed> $assertion the stored shape: payload, signature, fingerprint, uid
     */
    public function admit(array $assertion, string $sessionId): ?ContextFacts
    {
        $payload = \is_string($assertion['payload'] ?? null) ? $assertion['payload'] : '';
        $signature = \is_string($assertion['signature'] ?? null) ? $assertion['signature'] : '';

        // THE GRADE IS PRODUCED HERE — the signature is checked now, not believed from storage.
        $signer = $payload === '' || $signature === '' ? null : $this->verifier->verify($payload, $signature);
        if ($signer === null) {
            return null;
        }

        // The stored fingerprint is convenience metadata; the LIVE one decides. A contradiction is
        // not drift — it is someone rewriting the stored half and hoping nobody compares.
        if (($assertion['fingerprint'] ?? null) !== $signer->fingerprint) {
            return null;
        }

        // The payload must be the authorization of OWNING — of THIS session. The signature covered
        // (operation, arguments, host, time, nonce), so an assertion lifted from another session
        // fails here without any extra machinery: the binding was signed.
        $authorization = OperationAuthorization::fromCanonical($payload);
        if (
            $authorization === null
            || $authorization->operation !== 'session:own'
            || ($authorization->arguments['session'] ?? null) !== $sessionId
        ) {
            return null;
        }

        // POSSESSION IS NOT IDENTITY. gpg proved the signer holds some key; only the app's declared
        // registry says what that key is worth here — and for an unknown one, nothing.
        $scopes = $this->policy->scopesForSigner($signer->fingerprint);
        if ($scopes === null) {
            return null;
        }

        return VerifiedPrincipal::admit(
            'key:' . $signer->fingerprint,
            'cli-sign',
            $scopes,
            'gpg-detached',
            'app-registry',
        )->toFacts();
    }
}
