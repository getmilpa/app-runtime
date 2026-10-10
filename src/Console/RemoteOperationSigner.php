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

use Milpa\Console\ExplainsSigningFailure;
use Milpa\Console\OperationSigner;
use Milpa\Console\SigningFailure;

/**
 * Signs a `--sign` call WITHOUT a key in this process — by asking the host (greenhouse decisions/0611, decided by
 * Rod 2026-10-10, option (b)).
 *
 * A contained house — the Desktop's container — must hold no private signing key (`GHSA-fjwx-8j4j-cqfq`). So this
 * signer, the same {@see OperationSigner} seam the ordinary terminal injects, does NOT run `gpg`: it hands the
 * operation to the host over a socket the host bound in, and the host — the Desktop's Electron main — builds the
 * canonical payload (stamping `issuedAt` with ITS OWN clock and a fresh `nonce`), SHOWS the person exactly that, signs
 * only on their approval with a key that never leaves the host, and returns the payload and its signature. The house
 * then verifies with its public-only keyring and checks the payload names THIS call (OperationAuthorizer rebuilds the
 * canonical from the real call and compares), so the host never signs bytes the house dictated, and a reply for
 * another call or a replayed one is refused.
 *
 * ── THE SOCKET CONTRACT (v1) — the one place it is written; the Desktop's main speaks the other end ─────────────
 * Request  (one line of JSON, newline-framed):  {"version":1,"operation":<string>,"arguments":<object>,"host":<string>}
 *   The house sends NO timestamp: the host stamps `issuedAt` and the `nonce`, because the house's clock must not
 *   decide the date of what the person signs (decisions/0611).
 * Response (one line of JSON):  {"version":1,"ok":true,"payload":<canonical string>,"signature":<armored detached sig>}
 *   or a refusal:  {"version":1,"ok":false,"why":<string, shown to the person>}
 *   The house requires `version` to match, so the Desktop and the runtime can move apart without guessing.
 *
 * Returning null is the same first-class answer the gpg signer gives — the caller refuses on all of: the host did not
 * answer, the person declined, the approval window closed, or the reply could not be read. {@see whyNotSigned()} tells
 * them apart with a remedy, so a keyless-by-design house never sends a person hunting for a key it was built without.
 */
final class RemoteOperationSigner implements OperationSigner, ExplainsSigningFailure
{
    /** The env a contained house is given when a host offers to sign for it — its value is the socket to ask (0611). */
    public const string SIGN_SOCKET_ENV = 'MILPA_SIGN_SOCKET';

    /** The socket contract this house speaks; a reply that does not carry it is not trusted. */
    public const int PROTOCOL_VERSION = 1;

    /** How long to wait for the host to answer — long, because a PERSON approves each call at the host (0611 (b)). */
    public const int APPROVAL_SECONDS = 180;

    private ?SigningFailure $lastFailure = null;

    /** @var callable(string): array{outcome: 'answered'|'unreachable'|'timeout', line: ?string} */
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
     * when nothing was signed. On null, {@see whyNotSigned()} says which of the reasons it was, with a remedy.
     *
     * @param array<string, mixed> $arguments the derived input, exactly as the handler will receive it
     *
     * @return array{0: string, 1: string}|null the canonical payload and its signature, or null when nothing signed
     */
    public function sign(string $operation, array $arguments, string $host, int $now): ?array
    {
        $this->lastFailure = null;

        // No `now`: the host stamps issuedAt with its own clock and the nonce (decisions/0611).
        $request = json_encode([
            'version' => self::PROTOCOL_VERSION,
            'operation' => $operation,
            'arguments' => $arguments,
            'host' => $host,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($request === false) {
            $this->lastFailure = $this->failure(SigningFailure::NO_GPG, 'this call could not be put into a request to the host.', ['Report this: an argument did not encode to JSON.']);

            return null;
        }

        $reply = ($this->ask)($request);
        if ($reply['outcome'] === 'unreachable') {
            $this->lastFailure = $this->failure(
                SigningFailure::NO_GPG,
                "the host that signs for this house did not answer on its socket ({$this->socket}).",
                ['This house holds no signing key of its own — it signs through the host (decisions/0611).', 'Make sure the Desktop is running, then run the same command again.'],
            );

            return null;
        }
        if ($reply['outcome'] === 'timeout') {
            $this->lastFailure = $this->failure(
                SigningFailure::NOT_GIVEN,
                "no one approved the call at the host within {$this->approvalSeconds}s, so it was not signed.",
                ['Run it again and approve it at the Desktop when it asks.'],
            );

            return null;
        }

        $decoded = json_decode((string) $reply['line'], true);
        if (!\is_array($decoded) || ($decoded['version'] ?? null) !== self::PROTOCOL_VERSION) {
            $this->lastFailure = $this->failure(
                SigningFailure::NO_GPG,
                'the host answered in a protocol this house does not speak (it expects v' . self::PROTOCOL_VERSION . ').',
                ['Update the Desktop and this house to matching versions (decisions/0611).'],
            );

            return null;
        }
        if (($decoded['ok'] ?? false) !== true) {
            $why = \is_string($decoded['why'] ?? null) && $decoded['why'] !== '' ? $decoded['why'] : 'the person did not approve it';
            $this->lastFailure = $this->failure(
                SigningFailure::NOT_GIVEN,
                "the host declined to sign: {$why}.",
                ['Approve the operation at the Desktop if you meant to run it.'],
            );

            return null;
        }
        $payload = $decoded['payload'] ?? null;
        $signature = $decoded['signature'] ?? null;
        if (!\is_string($payload) || !\is_string($signature)) {
            $this->lastFailure = $this->failure(
                SigningFailure::NO_GPG,
                'the host said it signed, but sent no readable payload and signature.',
                ['Update the Desktop and this house to matching versions (decisions/0611).'],
            );

            return null;
        }

        return [$payload, $signature];
    }

    /** Which of the reasons the last {@see sign()} returned null, with its remedy — or null if the last call signed. */
    public function whyNotSigned(): ?SigningFailure
    {
        return $this->lastFailure;
    }

    /** @param list<string> $remedy */
    private function failure(string $kind, string $reason, array $remedy): SigningFailure
    {
        return new SigningFailure($kind, $reason, $remedy);
    }

    /**
     * The default transport: one newline-framed request to the host's socket, one line back.
     *
     * @return array{outcome: 'answered'|'unreachable'|'timeout', line: ?string}
     */
    private function overTheSocket(string $request): array
    {
        $client = @stream_socket_client('unix://' . $this->socket, $errno, $errstr, 5);
        if ($client === false) {
            return ['outcome' => 'unreachable', 'line' => null];
        }

        try {
            // The read waits as long as a person may take to approve at the host; the host answers ok:false on its own
            // deadline, so this only keeps the socket from cutting a deciding person off.
            stream_set_timeout($client, $this->approvalSeconds);
            if (@fwrite($client, $request . "\n") === false) {
                return ['outcome' => 'unreachable', 'line' => null];
            }
            $line = @fgets($client);
            if (!\is_string($line) || $line === '') {
                $timedOut = stream_get_meta_data($client)['timed_out'];

                return ['outcome' => $timedOut ? 'timeout' : 'unreachable', 'line' => null];
            }

            return ['outcome' => 'answered', 'line' => $line];
        } finally {
            @fclose($client);
        }
    }
}
