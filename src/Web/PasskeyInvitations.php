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

namespace Milpa\AppRuntime\Web;

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\IdentityInvitations;
use Milpa\Auth\ActorType;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\SessionRecord;

/**
 * The passkey door's half of an invitation: spend it on the credential a ceremony just registered, and
 * let that person in (greenhouse decisions/0498).
 *
 * Registering proves possession of an authenticator and grants nothing (decisions/0128). An invitation is
 * what a signed act said in advance about the credential that would redeem it; spending it here
 * recognizes the credential with the invitation's scopes, `authorized_by` the key that minted it — so
 * the line of decisions/0493 runs from that key to this passkey, exactly as if the key had enrolled it.
 *
 * AND THE SAME CEREMONY SIGNS THEM IN. The attestation was made over a one-time challenge the house
 * issued, by the authenticator whose public key it now holds: that is the proof of possession an
 * assertion gives, and the invitation binds it to the key that answered for it. Asking for a second
 * touch to prove the same thing was the second ceremony `evidence/1024` counted.
 */
final readonly class PasskeyInvitations
{
    public function __construct(
        private IdentityInvitations $invitations,
        private FileEnrollmentStore $enrollments,
        private SessionStore $sessions,
        private int $ttlSeconds,
    ) {
    }

    /**
     * Whether this secret would admit a credential now, and what it would grant.
     *
     * @return array{ok: true, id: string, scopes: list<string>, authorized_by: string, expires_at: string}|array{ok: false, reason: string}
     */
    public function check(string $token): array
    {
        return $this->invitations->check($token);
    }

    /**
     * Spend the invitation on this credential: recognize it and open its session — or say why not.
     *
     * @return array{ok: true, session: SessionRecord, scopes: list<string>, authorized_by: string}|array{ok: false, reason: string}
     */
    public function redeem(string $token, string $credentialId): array
    {
        try {
            $spent = $this->invitations->redeem($token, $credentialId);
            if ($spent['ok'] !== true) {
                return ['ok' => false, 'reason' => $spent['reason']];
            }
            $this->enrollments->recordAndReport(new IdentityEnrolled($credentialId, $spent['scopes'], $spent['authorized_by']));
        } catch (\RuntimeException $e) {
            // The act says what it did — and a write that did not happen is not something it did.
            return ['ok' => false, 'reason' => 'not_recorded: ' . $e->getMessage()];
        }

        $now = new \DateTimeImmutable();
        $session = new SessionRecord(
            id: bin2hex(random_bytes(32)),
            actorId: 'passkey:' . $credentialId,
            actorType: ActorType::User,
            createdAt: $now,
            expiresAt: $now->add(new \DateInterval('PT' . max(1, $this->ttlSeconds) . 'S')),
            scopes: $spent['scopes'],
        );
        $this->sessions->write($session);

        return ['ok' => true, 'session' => $session, 'scopes' => $spent['scopes'], 'authorized_by' => $spent['authorized_by']];
    }
}
