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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Console\RemoteOperationSigner;
use Milpa\Console\SigningFailure;
use PHPUnit\Framework\TestCase;

/**
 * THE HOUSE SIGNS THROUGH THE HOST (greenhouse decisions/0611, decided by Rod 2026-10-10, option (b)).
 *
 * A contained house holds no private key: this signer hands the operation to the host over the socket and returns the
 * payload and signature the host made; the house then verifies. This tests the house side's half of the socket
 * contract (v1) — what it sends, what it accepts, and that every «nothing was signed» names its own reason, so a
 * keyless-by-design house never sends a person hunting for a key. The host's approval and the end-to-end verification
 * are measured under xvfb in the Desktop PR; the structural round-trip is pinned in {@see TheRemoteSignersCanonicalSurvivesANonPhpEncoderTest}.
 */
final class RemoteOperationSignerTest extends TestCase
{
    /** A transport that answers with one JSON line (the host replied). */
    private function answering(string $line): callable
    {
        return static fn (string $request): array => ['outcome' => 'answered', 'line' => $line];
    }

    public function testItReturnsThePayloadAndSignatureTheHostMade(): void
    {
        $signer = new RemoteOperationSigner('/unused.sock', $this->answering((string) json_encode([
            'version' => 1, 'ok' => true, 'payload' => 'CANONICAL-BYTES', 'signature' => "-----BEGIN PGP SIGNATURE-----\n...",
        ])));

        $signed = $signer->sign('capabilities:enable', ['capability' => 'milpa/admin'], 'labhouse', 1_760_000_000);

        self::assertSame(['CANONICAL-BYTES', "-----BEGIN PGP SIGNATURE-----\n..."], $signed);
        self::assertNull($signer->whyNotSigned(), 'a call that signed has nothing to explain');
    }

    public function testItSendsVersionOperationArgumentsAndHostButNoTimestamp(): void
    {
        $seen = null;
        $signer = new RemoteOperationSigner('/unused.sock', function (string $request) use (&$seen): array {
            $seen = json_decode($request, true);

            return ['outcome' => 'answered', 'line' => (string) json_encode(['version' => 1, 'ok' => true, 'payload' => 'P', 'signature' => 'S'])];
        });

        $signer->sign('plugins:register', ['plugin' => 'Notes'], 'labhouse', 1_760_000_000);

        self::assertSame(1, $seen['version'] ?? null, 'the request carries the protocol version so the host and the house can move apart');
        self::assertSame('plugins:register', $seen['operation'] ?? null);
        self::assertSame(['plugin' => 'Notes'], $seen['arguments'] ?? null, 'the exact arguments — what is signed is what is shown (0611 (b))');
        self::assertSame('labhouse', $seen['host'] ?? null);
        self::assertArrayNotHasKey('now', $seen, 'the house sends NO timestamp: the host stamps issuedAt with its own clock (0611)');
    }

    public function testThePersonDecliningIsNullAndCarriesTheHostsReason(): void
    {
        // A refusal carries a payload and signature ON PURPOSE: `ok:false` alone must refuse, so the house never signs
        // over a «no» a host dressed up with leftover bytes.
        $signer = new RemoteOperationSigner('/unused.sock', $this->answering((string) json_encode([
            'version' => 1, 'ok' => false, 'why' => 'the operator pressed Deny', 'payload' => 'P', 'signature' => 'S',
        ])));

        self::assertNull($signer->sign('capabilities:enable', ['capability' => 'milpa/admin'], 'h', 1));
        $why = $signer->whyNotSigned();
        self::assertSame(SigningFailure::NOT_GIVEN, $why?->kind);
        self::assertStringContainsString('the operator pressed Deny', $why?->reason ?? '', 'the host\'s own reason reaches the person');
    }

    public function testAnUnreachableHostIsNullAndTellsThePersonTheHostNotAKey(): void
    {
        $signer = new RemoteOperationSigner('/run/milpa-sign.sock', static fn (string $r): array => ['outcome' => 'unreachable', 'line' => null]);

        self::assertNull($signer->sign('capabilities:enable', [], 'h', 1));
        $why = $signer->whyNotSigned();
        self::assertStringContainsString('did not answer on its socket', $why?->reason ?? '');
        self::assertStringContainsString('/run/milpa-sign.sock', $why?->reason ?? '', 'it names the socket, not a missing key');
        self::assertStringContainsString('Desktop is running', implode(' ', $why?->remedy ?? []), 'the remedy is to start the host, never to make a key');
    }

    public function testATimeoutIsNullAndSaysNoOneApproved(): void
    {
        $signer = new RemoteOperationSigner('/unused.sock', static fn (string $r): array => ['outcome' => 'timeout', 'line' => null], 90);

        self::assertNull($signer->sign('capabilities:enable', [], 'h', 1));
        $why = $signer->whyNotSigned();
        self::assertSame(SigningFailure::NOT_GIVEN, $why?->kind);
        self::assertStringContainsString('90s', $why?->reason ?? '', 'it names the window that closed');
        self::assertStringContainsString('approve', implode(' ', $why?->remedy ?? []));
    }

    public function testAProtocolMismatchIsRefused(): void
    {
        $signer = new RemoteOperationSigner('/unused.sock', $this->answering((string) json_encode([
            'version' => 2, 'ok' => true, 'payload' => 'P', 'signature' => 'S',
        ])));

        self::assertNull($signer->sign('capabilities:enable', [], 'h', 1), 'a reply in another protocol version is not trusted');
        self::assertStringContainsString('protocol', $signer->whyNotSigned()?->reason ?? '');
    }

    public function testAMalformedOrIncompleteAnswerIsNull(): void
    {
        foreach ([
            'not json',
            (string) json_encode(['version' => 1, 'ok' => true, 'payload' => 'P']),        // no signature
            (string) json_encode(['version' => 1, 'ok' => true, 'signature' => 'S']),      // no payload
            (string) json_encode(['version' => 1, 'ok' => true, 'payload' => 1, 'signature' => 'S']), // payload not a string
            (string) json_encode(['ok' => true, 'payload' => 'P', 'signature' => 'S']),    // no version
        ] as $bad) {
            $signer = new RemoteOperationSigner('/unused.sock', $this->answering($bad));
            self::assertNull($signer->sign('x', [], 'h', 1), "«{$bad}» is not a signature");
            self::assertNotNull($signer->whyNotSigned(), 'and it says why, not a bare null');
        }
    }
}
