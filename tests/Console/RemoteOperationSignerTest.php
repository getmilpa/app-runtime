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
use PHPUnit\Framework\TestCase;

/**
 * THE HOUSE SIGNS THROUGH THE HOST (greenhouse decisions/0611, decided by Rod 2026-10-10, option (b)).
 *
 * A contained house (the Desktop's container) holds NO private key. When a `--sign` call needs a signature, this
 * signer — the same `OperationSigner` seam the ordinary terminal injects — does NOT run gpg: it hands the operation
 * to the host (the Desktop's Electron main, where the person approves it) and returns the signature the host made.
 * The house then verifies with its public-only keyring (evidence/1183). This tests the house side's logic; the
 * socket transport and the host's approval are measured end to end, under xvfb, in the Desktop PR.
 */
final class RemoteOperationSignerTest extends TestCase
{
    public function testItReturnsThePayloadAndSignatureTheHostMade(): void
    {
        $signer = new RemoteOperationSigner('/unused.sock', static fn (string $request): ?string => (string) json_encode([
            'ok' => true, 'payload' => 'CANONICAL-BYTES', 'signature' => "-----BEGIN PGP SIGNATURE-----\n...",
        ]));

        $signed = $signer->sign('capabilities:enable', ['capability' => 'milpa/admin'], 'labhouse', 1_760_000_000);

        self::assertSame(['CANONICAL-BYTES', "-----BEGIN PGP SIGNATURE-----\n..."], $signed);
    }

    public function testItHandsTheHostTheOperationAndArgumentsItWasAskedToSign(): void
    {
        $seen = null;
        $signer = new RemoteOperationSigner('/unused.sock', static function (string $request) use (&$seen): ?string {
            $seen = json_decode($request, true);

            return (string) json_encode(['ok' => true, 'payload' => 'P', 'signature' => 'S']);
        });

        $signer->sign('plugins:register', ['plugin' => 'Notes'], 'labhouse', 1_760_000_000);

        self::assertSame('plugins:register', $seen['operation'] ?? null, 'the host is told which operation to show the person');
        self::assertSame(['plugin' => 'Notes'], $seen['arguments'] ?? null, 'and the exact arguments — what is signed is what is shown (0611 (b))');
        self::assertSame('labhouse', $seen['host'] ?? null);
        self::assertSame(1_760_000_000, $seen['now'] ?? null);
    }

    public function testARefusalFromTheHostIsNullEvenIfItCarriesBytes(): void
    {
        // The person said no at the host, or a deadline passed: the host answers ok:false, and a null here is the
        // same first-class "nothing was signed" the ordinary signer returns on a declined card. The refusal carries a
        // payload and a signature here ON PURPOSE: `ok:false` alone must refuse, so the house never signs over a
        // «no» a host dressed up with leftover bytes.
        $signer = new RemoteOperationSigner('/unused.sock', static fn (string $r): ?string => (string) json_encode([
            'ok' => false, 'why' => 'declined', 'payload' => 'CANONICAL', 'signature' => "-----BEGIN PGP SIGNATURE-----\n",
        ]));

        self::assertNull($signer->sign('capabilities:enable', ['capability' => 'milpa/admin'], 'h', 1));
    }

    public function testNoAnswerFromTheHostIsNull(): void
    {
        $signer = new RemoteOperationSigner('/unused.sock', static fn (string $r): ?string => null);

        self::assertNull($signer->sign('capabilities:enable', [], 'h', 1));
    }

    public function testAMalformedOrIncompleteAnswerIsNull(): void
    {
        foreach (['not json', '{}', '{"ok":true,"payload":"P"}', '{"ok":true,"signature":"S"}', '{"ok":true,"payload":1,"signature":"S"}'] as $bad) {
            $signer = new RemoteOperationSigner('/unused.sock', static fn (string $r): ?string => $bad);
            self::assertNull($signer->sign('x', [], 'h', 1), "«{$bad}» is not a signature");
        }
    }
}
