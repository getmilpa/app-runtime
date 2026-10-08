<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\EventStore\Event;

/**
 * Decided by Rod, 2026-10-08 (greenhouse decisions/0602).
 *
 * The works a person opened for a session: what the grant over an existing plugin leaves on its fact, and whether
 * those works still stand.
 *
 * Extending a capability that exists is bracketed by two acts of a person: the grant that reopens its works —an
 * informed act, the plugin's name repeated (decisions/0510)— and the admission that closes them (decisions/0590,
 * rule 10). Measured (evidence/1158), between the two the intent contract asked that person again, piece by piece:
 * whether to edit the class of the very call just granted, whether to `make` on the plugin whose name was just typed.
 *
 * What is read here is narrow on purpose:
 *
 *  - the fact a grant left in THIS session's stream, and only one that says it was over existing work: a one-touch
 *    grant over a plugin that did not exist says nothing here;
 *  - the seat that grant was given to still holds that permit in the ledger;
 *  - and no admission closed that permit since: the grant kept how many closures the seat's permit had, and the
 *    ledger must still count the same. A permit closed and granted again —to this session or to another— is another
 *    grant, with another count.
 *
 * No clock decides any of it, and no prose: every piece is a fact the house wrote.
 */
final class OpenedWorks
{
    /**
     * What a grant over existing work adds to the fact it leaves in the session ({@see GrantedCall::granted()}).
     *
     * @return array{existing: string, seat: string, closures: int}
     */
    public static function fact(FileEnrollmentStore $ledger, string $seat, string $plugin): array
    {
        return ['existing' => $plugin, 'seat' => $seat, 'closures' => self::closures($ledger, $seat, FileEnrollmentStore::permitOf($plugin))];
    }

    /**
     * Whether this session's own record says a person opened the works of that plugin for it, over existing work —
     * and they still stand.
     *
     * The last such grant is the one read: a session granted again after a closure carries the newer count.
     *
     * @param iterable<Event> $stream the session's own stream, in order
     */
    public static function standFor(iterable $stream, string $plugin, string $root): bool
    {
        $permit = FileEnrollmentStore::permitOf($plugin);
        $grant = null;
        foreach ($stream as $event) {
            if ($event->type === GrantedCall::GRANTED && ($event->payload['permission'] ?? null) === $permit
                && ($event->payload['existing'] ?? null) === $plugin) {
                $grant = $event->payload;
            }
        }
        $seat = $grant['seat'] ?? null;
        if (!\is_string($seat)) {
            return false;
        }
        // A ledger that cannot be read answers nothing, and nothing is not a permit: the person is asked.
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');

        // The count is compared as the house wrote it: a fact that carries none, or anything but that number, is no fact.
        return \in_array($permit, $ledger->scopesFor($seat) ?? [], true) && self::closures($ledger, $seat, $permit) === ($grant['closures'] ?? null);
    }

    /** How many times an admission took that permit from that seat. */
    private static function closures(FileEnrollmentStore $ledger, string $seat, string $permit): int
    {
        $closures = 0;
        foreach ($ledger->closuresFor($seat) as $closure) {
            $closures += $closure['permit'] === $permit ? 1 : 0;
        }

        return $closures;
    }
}
