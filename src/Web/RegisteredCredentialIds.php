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

/**
 * The credential ids the house has registered, read from the passkey ledger — what a browser needs as
 * `allowCredentials` to find a NON-DISCOVERABLE key (greenhouse decisions/0206).
 *
 * The enrollment ceremony asks for `residentKey: discouraged` (evidence/0486) so a hardware key with
 * scarce slots registers a non-discoverable credential: the authenticator keeps no record of it, and
 * `navigator.credentials.get()` finds it ONLY when the request lists its id. Without this list the
 * YubiKey is silent and the sign-in never starts.
 *
 * This is a single-operator panel: every registered credential is offered, which tells a visitor how
 * many keys the house knows but nothing it can use without one of them in hand — a credential id is
 * not a secret, the private key is. milpa/auth's {@see \Milpa\Auth\WebAuthn\PasskeyCredentialStore}
 * contract has no enumeration (find/register/updateSignCount only), so this reads the keys of the
 * ledger {@see \Milpa\Auth\WebAuthn\FilePasskeyCredentialStore} writes: `credentialId → {pem, signCount}`.
 * Adding the enumeration to the contract is a residue for milpa/auth.
 */
final class RegisteredCredentialIds
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Every registered credential id, in ledger order — empty when nothing was registered yet.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $raw = is_file($this->path) ? @file_get_contents($this->path) : '';
        $decoded = \is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!\is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id => $entry) {
            // Only a complete entry counts: an id without a public key could never answer a challenge.
            if (\is_array($entry) && \is_string($entry['pem'] ?? null) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * The same ids in the shape WebAuthn's `PublicKeyCredentialRequestOptions.allowCredentials` takes:
     * `{type: 'public-key', id: <base64url>}` per credential. The browser decodes the id to bytes.
     *
     * @return list<array{type: string, id: string}>
     */
    public function allowCredentials(): array
    {
        return array_map(
            static fn (string $id): array => ['type' => 'public-key', 'id' => $id],
            $this->all(),
        );
    }
}
