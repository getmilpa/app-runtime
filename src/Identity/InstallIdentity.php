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
use Milpa\Command\InvocationContext;
use Milpa\ToolRuntime\Contracts\ToolContext;

/**
 * What a finished install means for the identity of whoever made it (greenhouse decisions/0498).
 *
 * GROWTH — an enrolled principal that installed over the web holds, from this act on, the scopes the
 * capabilities it brought declare their operator needs ({@see ScopeGrowth}).
 *
 * THE FIRST PASSKEY — a signed act at the terminal that leaves a house recognizing nobody with a panel
 * and a door mints the invitation that answers for its first human ({@see FirstHuman}). Its secret goes
 * back in this result and nowhere else: never to an agent (MCP), never to a web request.
 *
 * Neither turns a finished install into a failure: the install happened, and what could not follow from
 * it is said in the result.
 */
final class InstallIdentity
{
    /**
     * Add to an install's result what it meant for identity.
     *
     * @param array<string, mixed>   $result    what `Capabilities::install()` answered, `ok: true`
     * @param string|null            $vendor    the vendor tree, null for the app's own
     * @param InvocationContext|null $context   who ran it, as the surface attributed it
     * @param ToolContext|null       $authority the request's authority, on the web
     *
     * @return array<string, mixed>
     */
    public static function settle(array $result, string $root, ?string $vendor, ?InvocationContext $context, ?ToolContext $authority): array
    {
        $root = rtrim($root, '/');
        $brought = [(string) ($result['capability'] ?? '')];
        foreach (\is_array($result['arrived_with_it'] ?? null) ? $result['arrived_with_it'] : [] as $arrived) {
            if (\is_array($arrived) && \is_string($arrived['package'] ?? null)) {
                $brought[] = $arrived['package'];
            }
        }

        $principal = $authority?->channel === 'web' ? (string) $authority->principal : '';
        if (str_starts_with($principal, 'passkey:')) {
            $manifests = Capabilities::declaredBy($vendor);
            $granted = [];
            try {
                $growth = new ScopeGrowth(new FileEnrollmentStore($root . '/storage/identity/enrollments.json'));
                foreach ($brought as $package) {
                    $declared = Capabilities::operatorScopesOf(\is_array($manifests[$package] ?? null) ? $manifests[$package] : []);
                    array_push($granted, ...$growth->grow($principal, $declared, $package));
                }
            } catch (\RuntimeException $e) {
                $result['granted_to_you_error'] = 'installed, but your scopes could not grow: ' . $e->getMessage();
            }
            if ($granted !== []) {
                $result['granted_to_you'] = $granted;
            }
        }

        if (FirstHuman::awaited($root, $vendor)) {
            $signer = $context !== null && $context->verified && $context->channel === 'cli' ? (string) $context->actor : '';
            if (!str_starts_with($signer, 'key:')) {
                // Nobody is recognized and no key signed at the terminal: nobody answers for a first passkey.
                $result['first_passkey'] = [
                    'minted' => false,
                    'why' => 'this act was not signed at the terminal, so no key answers for the first passkey — run `' . Capabilities::CLI . 'identity:invite --sign`',
                ];
            } else {
                try {
                    $result['first_passkey'] = FirstHuman::invite($root, $signer, null, $vendor);
                } catch (\RuntimeException $e) {
                    $result['first_passkey'] = ['minted' => false, 'why' => $e->getMessage()];
                }
            }
        }

        return $result;
    }
}
