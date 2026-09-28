<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Identity;

/**
 * Who answers for a seat: the enrollment relation read from the ledger (greenhouse decisions/0493).
 *
 * Inhabiting is a relation of authority (decisions/0240), and the ledger already records it: every
 * recognition names the principal that authorized it. A seat's CHAIN is its standing `authorized_by`,
 * then that principal's own standing `authorized_by`, and so on until a principal the ledger never
 * enrolled. A principal answers for the seat when it — or the principal that enrolled IT — is on that
 * chain. So the human whose key enrolled both a passkey and a resident answers for the resident from the
 * passkey, and a credential enrolled by another key does not.
 *
 * What the rule lends, named: a key vouches the credentials it enrolled into its line. Two people enrolled
 * by one key see each other's seats; a house that must keep them apart enrolls them under distinct keys.
 */
final class EnrollmentLine
{
    /** How far a chain is followed — a ledger is short, and a loop must not hang a page. */
    private const int MAX_DEPTH = 16;

    public function __construct(private readonly FileEnrollmentStore $enrollments)
    {
    }

    /**
     * The seat's enrollment chain, nearest authority first, as principals (`key:<fp>`, `passkey:<id>`).
     * Empty when the seat is not live in the ledger.
     *
     * @return list<string>
     */
    public function chainOf(string $seatKey): array
    {
        $chain = [];
        $seen = [IdentityKey::normalize($seatKey) => true];
        $by = $this->enrollments->authorizedBy($seatKey);
        while ($by !== null && \count($chain) < self::MAX_DEPTH) {
            $chain[] = $by;
            $key = self::keyOf($by);
            if ($key === null || isset($seen[IdentityKey::normalize($key)])) {
                break;
            }
            $seen[IdentityKey::normalize($key)] = true;
            $by = $this->enrollments->authorizedBy($key);
        }

        return $chain;
    }

    /** Whether this principal answers for the seat: it, or its own enroller, is on the seat's chain. */
    public function answersFor(string $principal, string $seatKey): bool
    {
        $chain = array_map(self::canonical(...), $this->chainOf($seatKey));
        if ($chain === []) {
            return false;
        }
        $candidates = [self::canonical($principal)];
        $key = self::keyOf($principal);
        $enroller = $key === null ? null : $this->enrollments->authorizedBy($key);
        if ($enroller !== null) {
            $candidates[] = self::canonical($enroller);
        }

        return array_intersect($candidates, $chain) !== [];
    }

    /** The ledger key a principal names — `key:<fp>` and `passkey:<id>` — or null for any other form. */
    public static function keyOf(string $principal): ?string
    {
        foreach (['key:', 'passkey:'] as $prefix) {
            if (str_starts_with($principal, $prefix) && \strlen($principal) > \strlen($prefix)) {
                return substr($principal, \strlen($prefix));
            }
        }

        return null;
    }

    private static function canonical(string $principal): string
    {
        $key = self::keyOf($principal);

        return $key === null ? $principal : strstr($principal, ':', true) . ':' . IdentityKey::normalize($key);
    }
}
