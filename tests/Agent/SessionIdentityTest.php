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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\SessionIdentity;
use Milpa\AppRuntime\Policy\PolicyProvider;
use Milpa\Command\Effect\AuthorityPolicy;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse decisions/0056 froze for the ADMISSION: a stored ownership assertion turns
 * into verified ContextFacts only by re-verifying its signature live, checking every binding, and
 * finding the signer in the app's own registry.
 *
 * evidence/0254 is the reason the shape is what it is: a stored `verified` flag was forged with
 * plausible strings, so the grade is never read — it is produced here, at consumption, each time.
 * The verifier is faked in these tests because what is under test is the ADMISSION LOGIC; the
 * signature machinery itself is tool-runtime's, and the cattle measurement (evidence/0255) runs the
 * real one with a real key.
 */
final class SessionIdentityTest extends TestCase
{
    private const FP = 'ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234';

    /** 1 · F-1 · POSITIVE CONTROL: a live-verified, well-bound, registered assertion admits. */
    public function testAWellBoundAssertionFromARegisteredSignerAdmits(): void
    {
        $facts = $this->identidad()->admit($this->asercion(), 'run-1');

        self::assertNotNull($facts);
        self::assertTrue($facts->verified);
        self::assertSame('key:' . self::FP, $facts->principal);
        self::assertSame(['probes:run'], $facts->scopes);
        self::assertSame('cli-sign', $facts->channel);
    }

    /** 2 · F-2 · a signature that does not verify admits nothing, whatever the stored fields claim. */
    public function testAnUnverifiableSignatureAdmitsNothing(): void
    {
        $identidad = $this->identidad(firmante: null);

        self::assertNull($identidad->admit($this->asercion(), 'run-1'));
    }

    /** 3 · F-3 · an assertion for ANOTHER session does not travel — the binding is the session id. */
    public function testAnAssertionForAnotherSessionDoesNotTravel(): void
    {
        self::assertNull($this->identidad()->admit($this->asercion(session: 'run-OTRA'), 'run-1'));
    }

    /** 4 · an assertion whose payload is not a session:own authorization admits nothing. */
    public function testAnAssertionForAnotherOperationAdmitsNothing(): void
    {
        self::assertNull($this->identidad()->admit($this->asercion(operacion: 'config:set'), 'run-1'));
    }

    /** 5 · F-5 · gpg proved possession of SOME key; the app's registry is what makes it identity. */
    public function testASignerOutsideTheRegistryAdmitsNothing(): void
    {
        $identidad = $this->identidad(scopes: null);

        self::assertNull($identidad->admit($this->asercion(), 'run-1'));
    }

    /** 6 · the stored fingerprint is convenience; the LIVE one decides, and a mismatch is tampering. */
    public function testAStoredFingerprintThatContradictsTheLiveOneAdmitsNothing(): void
    {
        $asercion = $this->asercion();
        $asercion['fingerprint'] = 'FFFF0000FFFF0000FFFF0000FFFF0000FFFF0000';

        self::assertNull($this->identidad()->admit($asercion, 'run-1'));
    }

    /** 7 · a payload that does not parse as an authorization admits nothing. */
    public function testAnUnparseablePayloadAdmitsNothing(): void
    {
        $asercion = $this->asercion();
        $asercion['payload'] = 'this is not a canonical authorization';

        self::assertNull($this->identidad()->admit($asercion, 'run-1'));
    }

    /** The assertion exactly as session:own stores it — the signed authorization's own bytes. */
    private function asercion(string $session = 'run-1', string $operacion = 'session:own'): array
    {
        $payload = (new OperationAuthorization(
            operation: $operacion,
            arguments: ['session' => $session],
            host: 'lab-host',
            issuedAt: '2026-08-18T00:00:00+00:00',
            nonce: 'n-1',
        ))->canonical();

        return [
            'payload' => $payload,
            'signature' => 'firma-en-bytes',
            'fingerprint' => self::FP,
            'uid' => 'Rod <rodrigo@teamx.agency>',
        ];
    }

    /**
     * @param VerifiedSigner|null|false $firmante false = the default (a signer with FP)
     * @param list<string>|null         $scopes   what the registry answers; null = unknown signer
     */
    private function identidad(VerifiedSigner|null|false $firmante = false, ?array $scopes = ['probes:run']): SessionIdentity
    {
        $firmante = $firmante === false ? new VerifiedSigner(self::FP, 'Rod <rodrigo@teamx.agency>') : $firmante;

        $verificador = new class ($firmante) implements SignatureVerifier {
            public function __construct(private readonly ?VerifiedSigner $firmante)
            {
            }

            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return $this->firmante;
            }
        };

        $registro = new class ($scopes) implements PolicyProvider {
            public function __construct(private readonly ?array $scopes)
            {
            }

            public function authorityPolicy(): ?AuthorityPolicy
            {
                return null;
            }

            public function scopesForSigner(string $fingerprint): ?array
            {
                return $this->scopes;
            }
        };

        return new SessionIdentity($verificador, $registro);
    }
}
