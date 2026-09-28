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

use Milpa\AppRuntime\Support\Capabilities;

/**
 * A resident's seat: the human who answers for the house promises it, and the resident's key takes it by
 * signing (greenhouse decisions/0499).
 *
 * Giving the resident a seat used to need its fingerprint written into `config/identity.php` by hand
 * (evidence/1024, station 7): enrollment consumes a root and never mints one (decisions/0117), and a key the
 * operator never declared had no other way in. This is the way `0498` opened for the first passkey, for a
 * machine: a seat invitation vouched by whoever minted it, spent by the key that signs its acceptance.
 *
 * WHAT PROVES IT IS THE RIGHT KEY — three things, none a substitute for another:
 *   - POSSESSION: the key enrolled is the verified signer of the acceptance, never an argument. Nobody can
 *     enroll a key they do not hold, and there is no fingerprint to copy wrong;
 *   - DELIVERY: the secret was shown only to whoever proved the minting act, so the key that presents it
 *     received it from that person — this key, tied to this decision;
 *   - CONFIRMATION, when it can be had: an invitation minted for a fingerprint admits only that key, and the
 *     panel shows the seat that entered, to compare against the resident's own `gpg --fingerprint`.
 *
 * The seat is recognized with `authorized_by` = the principal that minted the invitation, so the line of
 * decisions/0493 runs from that person to the seat — the frontier of its sessions is theirs to decide.
 */
final class ResidentSeat
{
    /**
     * What a seat holds on arrival — declared by the house, never typed by a client: what evidence/1024
     * measured a resident needs to run and author plugins. No `plugins.<Name>:write`: that one still arrives
     * through the frontier, a refusal the human decides (decisions/0317, 0493).
     */
    public const array SCOPES = ['agent:run', 'agent:read', 'plugins:read', 'plugins:write', 'plugins.config:write'];

    /** The intent session a passkey touch for minting a seat is bound to — there is no agent session yet. */
    public const string INTENT_SESSION = 'identity:seat';

    /** Why an acceptance refused before spending: the key is already recognized. */
    public const string ALREADY_RECOGNIZED = 'already_recognized';

    /** Why an acceptance refused before spending: the key answers for the invitation, so it cannot be its seat. */
    public const string VOUCHES_FOR_IT = 'vouches_for_it';

    /**
     * Mint a seat invitation vouched by `$vouchedBy` and say how the resident's key takes it.
     *
     * @return array{label: string, command: string, scopes: list<string>, vouched_by: string, for_key: string|null, expires_at: string, note: string}
     *
     * @throws \RuntimeException when the invitation could not be written
     */
    public static function invite(string $root, string $vouchedBy, string $label, ?string $forKey = null): array
    {
        $minted = IdentityInvitations::forRoot($root)->mint(
            self::SCOPES,
            $vouchedBy,
            IdentityInvitations::SEAT_TTL,
            IdentityInvitations::SEAT,
            $forKey,
            $label,
        );
        $bound = $forKey === null || trim($forKey) === '' ? null : IdentityKey::normalize($forKey);

        return [
            'label' => $label,
            'command' => Capabilities::CLI . 'identity:accept --invite=' . $minted['token'] . ' --sign',
            'scopes' => $minted['scopes'],
            'vouched_by' => $minted['authorized_by'],
            'for_key' => $bound,
            'expires_at' => $minted['expires_at'],
            'note' => 'run it where the resident lives, with the resident\'s own key: its signature is what proves the key,'
                . ' and the seat answers to you. It works once, within the hour, and this is the only place its secret is shown.',
        ];
    }

    /**
     * Seat the key that signed the acceptance — or say why not, having written nothing.
     *
     * @return array{ok: true, fingerprint: string, label: string|null, scopes: list<string>, authorized_by: string}|array{ok: false, reason: string, error: string}
     */
    public static function accept(string $root, string $token, string $fingerprint): array
    {
        $invitations = IdentityInvitations::forRoot($root);
        $ledger = new FileEnrollmentStore(rtrim($root, '/') . '/storage/identity/enrollments.json');

        $judged = $invitations->checkSeat($token, $fingerprint);
        if ($judged['ok'] !== true) {
            return self::refused($judged['reason']);
        }
        // A seat invitation seats a NEW key; it never rewrites what a recognized one may do.
        if ($ledger->scopesFor($fingerprint) !== null) {
            return self::refused(self::ALREADY_RECOGNIZED);
        }
        // Nor does a key take the seat it answers for: its own terminal authority would shrink to a seat's.
        if (self::vouches($ledger, $judged['authorized_by'], $fingerprint)) {
            return self::refused(self::VOUCHES_FOR_IT);
        }

        try {
            $spent = $invitations->redeemSeat($token, $fingerprint);
            if ($spent['ok'] !== true) {
                return self::refused($spent['reason']);
            }
            $enrolled = (new IdentityEnrollment(IdentityInvitations::rootFor($root)))
                ->enroll($fingerprint, $spent['scopes'], $spent['authorized_by']);
            $ledger->recordAndReport($enrolled);
        } catch (IdentityNotRooted|\RuntimeException $e) {
            return ['ok' => false, 'reason' => 'not_recorded', 'error' => 'nothing was seated: ' . $e->getMessage()];
        }

        return [
            'ok' => true,
            'fingerprint' => $enrolled->fingerprint,
            'label' => $invitations->labelFor($enrolled->fingerprint),
            'scopes' => $enrolled->scopes,
            'authorized_by' => $enrolled->authorizedBy,
        ];
    }

    /**
     * The seats this principal answers for — every live signing key whose enrollment line it is on
     * (decisions/0493) — for the panel to show beside their frontier.
     *
     * @return list<array{fingerprint: string, label: string|null, scopes: list<string>, authorized_by: string}>
     */
    public static function seatsFor(string $root, string $principal): array
    {
        $ledger = new FileEnrollmentStore(rtrim($root, '/') . '/storage/identity/enrollments.json');
        $line = new EnrollmentLine($ledger);
        $invitations = IdentityInvitations::forRoot($root);
        $own = EnrollmentLine::keyOf($principal);
        $seats = [];
        foreach ($ledger->liveKeys() as $key) {
            if (!IdentityKey::isFingerprint($key) || ($own !== null && IdentityKey::normalize($own) === IdentityKey::normalize($key))) {
                continue;
            }
            if (!$line->answersFor($principal, $key)) {
                continue;
            }
            $seats[] = [
                'fingerprint' => $key,
                'label' => $invitations->labelFor($key),
                'scopes' => $ledger->scopesFor($key) ?? [],
                'authorized_by' => (string) $ledger->authorizedBy($key),
            ];
        }

        return $seats;
    }

    /** Whether the key is the invitation's voucher, or on that voucher's enrollment line. */
    private static function vouches(FileEnrollmentStore $ledger, string $voucher, string $fingerprint): bool
    {
        $mine = IdentityKey::normalize($fingerprint);
        $line = new EnrollmentLine($ledger);
        $key = EnrollmentLine::keyOf($voucher);
        foreach ([$voucher, ...($key === null ? [] : $line->chainOf($key))] as $principal) {
            $other = EnrollmentLine::keyOf($principal);
            if ($other !== null && IdentityKey::normalize($other) === $mine) {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok: false, reason: string, error: string} */
    private static function refused(string $reason): array
    {
        $why = match ($reason) {
            IdentityInvitations::UNKNOWN => 'this house issued no seat invitation with that secret',
            IdentityInvitations::REDEEMED => 'that invitation was already used',
            IdentityInvitations::EXPIRED => 'that invitation expired — ask whoever answers for the house for another',
            IdentityInvitations::WRONG_KEY => 'that invitation is for another key — the one that signed this is not it',
            self::ALREADY_RECOGNIZED => 'this key is already recognized here; a seat invitation seats a new key',
            self::VOUCHES_FOR_IT => 'this key answers for that invitation, so it cannot be its seat',
            default => 'the invitation did not admit this key (' . $reason . ')',
        };

        return ['ok' => false, 'reason' => $reason, 'error' => $why . '; nothing was seated'];
    }
}
