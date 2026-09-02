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

use Milpa\Command\Consent\OperationId;

/**
 * The concrete call a passkey challenge was minted FOR (greenhouse decisions/0187, D-01 residue).
 *
 * A WebAuthn assertion proves «the registered human is present and signed THIS challenge». The
 * challenge itself is opaque bytes, so what call that presence authorises is recorded here, server
 * side, when the challenge is issued — the Desktop shows the human the operation, the server
 * remembers which one the challenge stands for, and {@see PasskeyIntentAdmission} mints the grant for
 * THIS call, never for one the caller re-states. The binding is single-use, consumed on admission.
 */
final readonly class IntentChallengeBinding
{
    /**
     * @param array<string, mixed> $arguments the exact arguments the human is authorising
     */
    public function __construct(
        public OperationId $operation,
        public array $arguments,
        public ?string $session,
    ) {
    }
}
