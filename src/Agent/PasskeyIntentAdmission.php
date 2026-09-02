<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app INSTALLS, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Effect\VerifiedPrincipal;

/**
 * The recording channel's verified-human YES, made a first-class proof (greenhouse decisions/0187,
 * the D-01 residue — the second producer of the {@see \Milpa\Console\Consent::satisfiedBy()} seam of
 * evidence/0459).
 *
 * D-01 (evidence/0459) built the seam: a verified-human intent mints a proof-backed `ConsentGrant`
 * that clears the SAME consent demand a gpg CLI signature clears — one authority, many projections.
 * The gpg signature (`session:own`, {@see SessionIdentity}) is one projection. This is the other: a
 * recording channel — the Desktop — where the human approves the exact operation with a passkey, and
 * that touch becomes the proof, instead of a `verified:true` a channel merely asserts about its own UI.
 *
 * ── HOW THE CALL IS BOUND ───────────────────────────────────────────────────────────────────────
 *
 * {@see challengeFor()} mints a WebAuthn challenge for a CONCRETE call and remembers, server side,
 * which call it stands for ({@see IntentChallengeStore}). The Desktop shows the human that operation
 * and runs the passkey ceremony over the challenge. {@see admit()} verifies the assertion through
 * milpa/auth's {@see PasskeyAuthenticator} — a registered credential, a live signature, the challenge
 * spent once, the counter climbing — and mints the grant for the BOUND call, never for one the caller
 * re-states. Exactness across op, arguments and session is then the grant's own
 * ({@see ConsentGrant::admits()}, evidence/0459).
 *
 * ── WHY IT CANNOT BE FORGED ─────────────────────────────────────────────────────────────────────
 *
 * The grade is PRODUCED here, by re-verifying a live assertion, and only then handed to
 * {@see VerifiedPrincipal::admit()} — never read from storage (the doctrine of evidence/0254, which
 * {@see ConsentGrant::admits()} enforces: a grant round-tripped through data comes back ungraded).
 * An unregistered credential, a bad signature, a replayed challenge, or an unbound challenge all
 * return `null`: no assertion, no grant, no authority.
 */
final class PasskeyIntentAdmission
{
    public function __construct(
        private readonly PasskeyAuthenticator $authenticator,
        private readonly IntentChallengeStore $challenges,
        private readonly string $issuer = 'app-passkey-registry',
    ) {
    }

    /**
     * Mint a challenge for a concrete call. The Desktop sends its base64url to the client, shows the
     * human the operation, and runs `navigator.credentials.get` over it.
     *
     * @param array<string, mixed> $arguments the exact arguments the human will be shown and approve
     *
     * @return string the raw challenge bytes (the caller base64url-encodes them for the client)
     */
    public function challengeFor(OperationId $operation, array $arguments, ?string $session): string
    {
        $challenge = $this->authenticator->challenge();
        $this->challenges->bind($challenge, new IntentChallengeBinding($operation, $arguments, $session));

        return $challenge;
    }

    /**
     * Admit a passkey assertion into a proof-backed IntentGrant for the call its challenge was bound
     * to — or `null` when the proof does not hold.
     *
     * @return ConsentGrant|null the grant for the bound call, or null on any failure (fail-closed)
     */
    public function admit(
        string $rpId,
        string $credentialId,
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
    ): ?ConsentGrant {
        $challenge = $this->challengeFromClientData($clientDataJson);
        if ($challenge === null) {
            return null;
        }

        // Verify FIRST: a registered credential, a live signature over this challenge, the challenge
        // spent once, the counter climbing — everything milpa/auth's authenticator already proves.
        $verified = $this->authenticator->authenticate($rpId, $credentialId, $clientDataJson, $authenticatorData, $signature);
        if ($verified === null) {
            return null;
        }

        // Only a proven assertion reaches the call binding. Single-use: an admitted challenge stands
        // for exactly one grant.
        $binding = $this->challenges->take($challenge);
        if ($binding === null) {
            return null;
        }

        // The grade exists because the assertion was just re-verified — produced, never read.
        $admission = VerifiedPrincipal::admit($verified->principal(), 'desktop', [], 'webauthn', $this->issuer);

        return ConsentGrant::fromVerifiedIntent(
            $binding->operation,
            $admission,
            $binding->session,
            new \DateTimeImmutable(),
            $binding->arguments,
        );
    }

    private function challengeFromClientData(string $clientDataJson): ?string
    {
        $data = json_decode($clientDataJson, true);
        if (!\is_array($data) || !\is_string($data['challenge'] ?? null)) {
            return null;
        }

        $decoded = base64_decode(strtr($data['challenge'], '-_', '+/'), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }
}
