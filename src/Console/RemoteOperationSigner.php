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

namespace Milpa\AppRuntime\Console;

use Milpa\Console\OperationSigner;

/**
 * Signs a `--sign` call WITHOUT a key in this process — by asking the host (greenhouse decisions/0611, decided by
 * Rod 2026-10-10, option (b)).
 *
 * A contained house — the Desktop's container — must hold no private signing key (`GHSA-fjwx-8j4j-cqfq`). So this
 * signer, the same {@see OperationSigner} seam the ordinary terminal injects, does NOT run `gpg`: it hands the
 * operation to the host over a socket the host bound in, and the host — the Desktop's Electron main — builds the
 * canonical payload, SHOWS the person exactly that (operation, arguments, host, issuedAt, nonce), signs only on their
 * approval with a key that never leaves the host, and returns the payload and its signature. The house then verifies
 * with its public-only keyring ({@see \Milpa\ToolRuntime\Identity\GnupgSignatureVerifier}; evidence/1183).
 *
 * Returning null is the same first-class answer the gpg signer gives: the person declined at the host, the approval
 * window closed, or the host could not be reached — all mean "nothing was signed", and the caller refuses on all.
 *
 * The transport is one `fn(string $request): ?string` — the request line out, the response line back, or null when
 * the host did not answer. The default speaks a newline-framed JSON exchange over the unix socket; a test injects its
 * own. What travels is only what the host needs to show and sign; the key, and the person's approval, stay on the host.
 */
final class RemoteOperationSigner implements OperationSigner
{
    /** The env a contained house is given when a host offers to sign for it — its value is the socket to ask (0611). */
    public const string SIGN_SOCKET_ENV = 'MILPA_SIGN_SOCKET';

    /** How long to wait for the host to answer — long, because a PERSON approves each call at the host (0611 (b)). */
    public const int APPROVAL_SECONDS = 180;

    /** @var callable(string): ?string */
    private $ask;

    public function __construct(
        private readonly string $socket,
        ?callable $ask = null,
        private readonly int $approvalSeconds = self::APPROVAL_SECONDS,
    ) {
        $this->ask = $ask ?? $this->overTheSocket(...);
    }

    /**
     * Asks the host to sign this call and returns the canonical payload the host built and its signature, or null
     * when nothing was signed — the person declined at the host, the approval window closed, or the host did not
     * answer. The caller refuses on all three, exactly as it does for the gpg signer's null.
     *
     * @param array<string, mixed> $arguments the derived input, exactly as the handler will receive it
     *
     * @return array{0: string, 1: string}|null the canonical payload and its signature, or null when nothing signed
     */
    public function sign(string $operation, array $arguments, string $host, int $now): ?array
    {
        $request = json_encode([
            'operation' => $operation,
            'arguments' => $arguments,
            'host' => $host,
            'now' => $now,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($request === false) {
            return null;
        }

        $response = ($this->ask)($request);
        if ($response === null) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!\is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            return null;
        }
        $payload = $decoded['payload'] ?? null;
        $signature = $decoded['signature'] ?? null;
        if (!\is_string($payload) || !\is_string($signature)) {
            return null;
        }

        return [$payload, $signature];
    }

    /** The default transport: one newline-framed request to the host's socket, one line back. Null on any failure. */
    private function overTheSocket(string $request): ?string
    {
        $client = @stream_socket_client('unix://' . $this->socket, $errno, $errstr, 5);
        if ($client === false) {
            return null;
        }

        try {
            // The read waits as long as a person may take to approve at the host; the host answers ok:false on its own
            // deadline, so this only keeps the socket from cutting a deciding person off.
            stream_set_timeout($client, $this->approvalSeconds);
            if (@fwrite($client, $request . "\n") === false) {
                return null;
            }
            $line = @fgets($client);

            return \is_string($line) && $line !== '' ? $line : null;
        } finally {
            @fclose($client);
        }
    }
}
