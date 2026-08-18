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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Agent\SessionStore;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse decisions/0056 froze for `session:own`: the signed authorization IS the
 * assertion, it travels through the container instead of dying at the banner, and what lands in the
 * stream is the EXACT signed bytes — because every consumer re-verifies them live (evidence/0254).
 */
final class SessionOwnershipTest extends TestCase
{
    private const FP = 'ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234';

    private InMemoryEventStore $eventos;

    private DIContainer $contenedor;

    protected function setUp(): void
    {
        $this->eventos = new InMemoryEventStore();
        $this->contenedor = new DIContainer();
        $this->contenedor->registerService(SessionStore::class, new SessionStore($this->eventos));
    }

    /** 1 · the catalogue says what the act is: owning demands the signature, because it IS the assertion. */
    public function testOwningDemandsTheSignature(): void
    {
        $op = $this->operacion();

        self::assertTrue($op->requiresConfirmation);
        self::assertTrue($op->mutating);
    }

    /** 2 · without the granted authorization in the container there is nothing to store — and it says so. */
    public function testWithoutAGrantedAuthorizationNothingIsStored(): void
    {
        $r = $this->llamar(['session' => 'run-1']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('--sign', (string) $r['error']);
        self::assertNull($this->almacen()->load('run-1')?->ownershipAssertion());
    }

    /** 3 · a signature that covered owning ANOTHER session stores nothing here. */
    public function testAnAuthorizationForAnotherSessionStoresNothing(): void
    {
        $this->concede('run-OTRA');

        $r = $this->llamar(['session' => 'run-1']);

        self::assertFalse($r['ok']);
        self::assertNull($this->almacen()->load('run-1')?->ownershipAssertion());
    }

    /** 4 · the whole point: the EXACT signed bytes land in the stream, and the owner is the KEY, not the env. */
    public function testTheExactSignedBytesLandInTheStream(): void
    {
        [$payload, $firma] = $this->concede('run-1');

        $r = $this->llamar(['session' => 'run-1']);

        self::assertTrue($r['ok']);
        self::assertSame('key:' . self::FP, $r['owner']);
        $guardada = $this->almacen()->load('run-1')?->ownershipAssertion();
        self::assertNotNull($guardada);
        self::assertSame($payload, $guardada['payload']);
        self::assertSame($firma, $guardada['signature']);
        self::assertSame(self::FP, $guardada['fingerprint']);
    }

    /** 5 · no session named, nothing owned. */
    public function testWithoutASessionNothingIsOwned(): void
    {
        $r = $this->llamar([]);

        self::assertFalse($r['ok']);
    }

    /** Places a granted authorization in the container, the way CliRunner does after the verdict. */
    private function concede(string $session): array
    {
        $autorizacion = new OperationAuthorization(
            operation: 'session:own',
            arguments: ['session' => $session],
            host: 'lab-host',
            issuedAt: '2026-08-18T00:00:00+00:00',
            nonce: 'n-1',
        );
        $payload = $autorizacion->canonical();
        $firma = 'exact-signature-bytes';
        $this->contenedor->registerService(GrantedAuthorization::class, new GrantedAuthorization(
            authorization: $autorizacion,
            signer: new VerifiedSigner(self::FP, 'Rod <rodrigo@teamx.agency>'),
            payload: $payload,
            signature: $firma,
        ));

        return [$payload, $firma];
    }

    private function operacion(): \Milpa\Command\Operation
    {
        foreach ((new SessionOperations($this->contenedor))->operations() as $op) {
            if ($op->name === 'session:own') {
                return $op;
            }
        }
        self::fail('session:own is not offered');
    }

    /** @param array<string, mixed> $entrada */
    private function llamar(array $entrada): array
    {
        $handler = $this->operacion()->handler;
        self::assertIsCallable($handler);

        /** @var array<string, mixed> */
        return $handler($entrada);
    }

    private function almacen(): SessionStore
    {
        return new SessionStore($this->eventos);
    }
}
