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

namespace Milpa\AppRuntime\Agent;

use Milpa\Command\Consent\ConsentGrant;

/**
 * A passkey assertion carried INSIDE a governed call, admitted into the grant for the call it was bound to.
 *
 * The intent ceremony (greenhouse decisions/0187) binds a challenge to one concrete call and
 * {@see PasskeyIntentAdmission} re-verifies the touch. Its controller answers the grant to the browser;
 * an operation whose handler must know WHO decided — `identity:grant` (decisions/0493) — receives the
 * assertion as an argument and admits it here, in the same request, so the proof is produced where it is
 * used and never read back from storage.
 */
final class PasskeyIntentProof
{
    public function __construct(
        private readonly PasskeyIntentAdmission $admission,
        private readonly string $rpId,
    ) {
    }

    /**
     * The grant for the call the assertion's challenge was bound to, or null when the proof does not hold.
     *
     * @param array<string, mixed> $assertion `credentialId`, and base64url `clientDataJSON`, `authenticatorData`, `signature`
     */
    public function admit(array $assertion): ?ConsentGrant
    {
        $credentialId = \is_string($assertion['credentialId'] ?? null) ? $assertion['credentialId'] : '';
        $clientData = self::decode($assertion['clientDataJSON'] ?? null);
        $authData = self::decode($assertion['authenticatorData'] ?? null);
        $signature = self::decode($assertion['signature'] ?? null);
        if ($credentialId === '' || $clientData === null || $authData === null || $signature === null) {
            return null;
        }

        return $this->admission->admit($this->rpId, $credentialId, $clientData, $authData, $signature);
    }

    private static function decode(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - \strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
