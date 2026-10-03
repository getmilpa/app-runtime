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
use Milpa\AppRuntime\Web\PasskeyPlugin;

/**
 * The first human of a house: who answers for them, and what they may do on arrival
 * (greenhouse decisions/0498).
 *
 * A fresh house recognizes nobody. Its only authority is the key that signed its acts at the terminal —
 * the one that founded it and opened its panel. So when the act that leaves the house with a panel AND a
 * passkey door is signed, that key answers for the first passkey: the house mints an invitation whose
 * secret goes back to that terminal and nowhere else, and the ceremony that redeems it is the one
 * enrollment the first human performs.
 *
 * What the first human may do is not written here. It is what the installed capabilities declare their
 * OPERATOR needs (`extra.milpa.capability.operator_scopes` — the panel declares `milpa.admin` and
 * `capabilities:enable`), plus `identity:enroll`: the first person is the one who lets the others in and
 * decides the frontier of the seats they enroll (decisions/0493). Nothing that is not installed yet is
 * asked for up front; it arrives with the install ({@see ScopeGrowth}).
 */
final class FirstHuman
{
    /** The capability whose arrival means a human will use this house from a browser. */
    public const string PANEL = 'admin';

    /** The capability that is its door. */
    public const string DOOR = 'identity';

    /** What the first human always holds: the right to let the next one in. */
    public const string ENROLL = 'identity:enroll';

    /**
     * Where `php bin/coa serve` answers by default — the invitation's URL is a convenience; its path is the fact.
     * A process that serves the house elsewhere and says so ({@see PasskeyPlugin::SERVED_ORIGINS_ENV}, the
     * Desktop on its own port) is where the URL points instead (greenhouse decisions/0534).
     */
    private const string SERVE = 'http://localhost:8000';

    /**
     * What the first human is invited with: the operator scopes the installed capabilities declare, plus
     * `identity:enroll`.
     *
     * @return list<string>
     */
    public static function scopes(?string $vendor = null): array
    {
        return array_values(array_unique([...Capabilities::operatorScopes($vendor), self::ENROLL]));
    }

    /**
     * Whether this house is waiting for its first human: it has a panel and a door, it recognizes nobody,
     * its config declares no root, and no invitation was ever spent.
     */
    public static function awaited(string $root, ?string $vendor = null): bool
    {
        if (!Capabilities::installed(self::PANEL, $vendor) || !Capabilities::installed(self::DOOR, $vendor)) {
            return false;
        }
        if (!IdentityConfig::load($root)->isEmpty()) {
            return false;
        }
        if (!(new FileEnrollmentStore(rtrim($root, '/') . '/storage/identity/enrollments.json'))->isEmpty()) {
            return false;
        }

        return !IdentityInvitations::forRoot($root)->anyRedeemed();
    }

    /**
     * Mint an invitation vouched by `$vouchedBy` and describe where to spend it.
     *
     * @param list<string>|null $scopes null invites with {@see scopes()}
     *
     * @return array{path: string, url: string, scopes: list<string>, vouched_by: string, expires_at: string, note: string}
     *
     * @throws \RuntimeException when the invitation could not be written
     */
    public static function invite(string $root, string $vouchedBy, ?array $scopes = null, ?string $vendor = null): array
    {
        $minted = IdentityInvitations::forRoot($root)->mint($scopes ?? self::scopes($vendor), $vouchedBy);
        // Back to the panel when there is one; a house with only the door returns to its root.
        $next = Capabilities::installed(self::PANEL, $vendor) ? '/milpa/admin' : '/';
        $path = '/webauthn/enroll?' . http_build_query(['invite' => $minted['token'], 'next' => $next]);

        return [
            'path' => $path,
            'url' => (PasskeyPlugin::servedOrigins()[0] ?? self::SERVE) . $path,
            'scopes' => $minted['scopes'],
            'vouched_by' => $minted['authorized_by'],
            'expires_at' => $minted['expires_at'],
            'note' => 'open it in the browser where you will use the panel and register your passkey: one ceremony enrolls it'
                . ' and signs you in. It works once, and this is the only place its secret is shown'
                . ' (lost it? `' . Capabilities::cli() . 'identity:invite --sign`).',
        ];
    }
}
