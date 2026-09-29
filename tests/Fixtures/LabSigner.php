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

namespace Milpa\AppRuntime\Tests\Fixtures;

use Milpa\Console\OperationSigner;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;

/**
 * A lab key without gpg: signs the canonical payload the way `GnupgOperationSigner` builds it, and verifies
 * only its own signatures, as one fingerprint. Everything after the signature — the replay ledger, the
 * enrollment ledger, the write set — is the shipped code.
 */
final class LabSigner implements OperationSigner, SignatureVerifier
{
    public function __construct(public readonly string $fingerprint = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555')
    {
    }

    public function sign(string $operation, array $arguments, string $host, int $now): ?array
    {
        $payload = (new OperationAuthorization(
            operation: $operation,
            arguments: $arguments,
            host: $host,
            issuedAt: gmdate('c', $now),
            nonce: bin2hex(random_bytes(16)),
        ))->canonical();

        return [$payload, hash_hmac('sha256', $payload, $this->fingerprint)];
    }

    public function verify(string $payload, string $signature): ?VerifiedSigner
    {
        return hash_equals(hash_hmac('sha256', $payload, $this->fingerprint), $signature)
            ? new VerifiedSigner($this->fingerprint, 'lab key <lab@example.invalid>')
            : null;
    }
}
