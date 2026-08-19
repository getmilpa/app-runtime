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

use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Descent;
use Milpa\Command\Effect\DescentCertificate;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\AppRuntime\Agent\SessionIdentity;
use Milpa\AppRuntime\Policy\PolicyProvider;
use Milpa\Command\Effect\AuthorityPolicy;
use Milpa\Command\Effect\DeclaredAuthorityPolicy;
use Milpa\Command\Operation;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse decisions/0058 froze: the agent's gate decides by the composed ceiling of
 * THIS call, not by the declared `mutating` flag. A rehearsal whose certified descent lowers mutation
 * to None is not a mutation, so the session does not pause for it — while the same operation without
 * the rehearsal still does. The gate reads what the call REALLY does.
 */
final class GateReadsComposedCeilingTest extends TestCase
{
    private const DIGEST_PLACEHOLDER = 'sha256:set-below';

    private string $publica = '';

    private string $privada = '';

    protected function setUp(): void
    {
        $par = sodium_crypto_sign_keypair();
        $this->publica = base64_encode(sodium_crypto_sign_publickey($par));
        $this->privada = sodium_crypto_sign_secretkey($par);
    }

    /** 1 · F-1 · a certified rehearsal that descends mutation to None does NOT pause the session. */
    public function testACertifiedRehearsalDoesNotPause(): void
    {
        $gate = $this->gate(AutonomyMode::Ask);

        self::assertNull($gate->refuse('probe', ['dry_run' => true]), 'the effective ceiling is a read; a read is not asked');
    }

    /** 2 · THE CONTROL: the same operation WITHOUT the rehearsal argument still pauses. */
    public function testTheSameOperationWithoutTheRehearsalStillPauses(): void
    {
        $gate = $this->gate(AutonomyMode::Ask);

        self::assertNotNull($gate->refuse('probe', []), 'no descent triggered, so it mutates and asks as before');
    }

    /** 3 · F-3 · a rehearsal whose certificate does not verify does NOT relax the gate. */
    public function testAnUnverifiableCertificateDoesNotRelaxTheGate(): void
    {
        // A gate whose operation carries a descent with a broken signature: the descent does not hold,
        // so the effective ceiling stays mutating, so the session still pauses.
        $gate = $this->gate(AutonomyMode::Ask, forgeSignature: true);

        self::assertNotNull($gate->refuse('probe', ['dry_run' => true]), 'a forged descent lowers nothing');
    }

    /**
     * 4 · a descent that lowers BOTH mutation and authority needs the session's OWNER — the facts
     * that let the policy judge authority. On an owned session the whole descent holds and the gate
     * does not pause; on the same session with no owner, the authority half fails, the descent
     * collapses, mutation stays, and it pauses. This is the composed ceiling read end to end, with
     * the owner admitted live (greenhouse decisions/0056, 0058).
     */
    public function testAnOwnedSessionLetsAMutationAndAuthorityDescentHold(): void
    {
        $fp = 'ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234';
        $verifier = new class ($fp) implements SignatureVerifier {
            public function __construct(private readonly string $fp)
            {
            }

            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return $signature === 'good' ? new VerifiedSigner($this->fp, 'Rod') : null;
            }
        };
        $provider = new class ($fp) implements PolicyProvider {
            public function __construct(private readonly string $fp)
            {
            }

            public function authorityPolicy(): ?AuthorityPolicy
            {
                return new DeclaredAuthorityPolicy('lab', ['probe' => ['scopes' => ['probes:run'], 'authority' => \Milpa\Command\Effect\Authority::Read]]);
            }

            public function scopesForSigner(string $fingerprint): ?array
            {
                return $fingerprint === $this->fp ? ['probes:run'] : null;
            }
        };
        $identity = new SessionIdentity($verifier, $provider);

        // The ownership assertion the store holds — a session:own authorization for THIS session.
        $auth = new \Milpa\ToolRuntime\Identity\OperationAuthorization('session:own', ['session' => 's-1'], 'h', '2026-08-18T00:00:00+00:00', 'n');
        $asercion = ['payload' => $auth->canonical(), 'signature' => 'good', 'fingerprint' => $fp, 'uid' => 'Rod'];

        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s-1', 'goal', AutonomyMode::Ask);
        $almacen->assertOwnership('s-1', $asercion);
        $sesion = $almacen->load('s-1');
        self::assertNotNull($sesion);
        $gate = new SessionToolGate($almacen, $sesion, [$this->probeMutAndAuth()], policyProvider: $provider, identity: $identity);

        // Owned: the descent holds (mutation via certificate, authority via the owner's judged facts).
        self::assertNull($gate->refuse('probe', ['dry_run' => true]));

        // The same session with no identity to admit the owner: authority is unjudged, the descent
        // collapses, and it pauses.
        $gateSinDuenio = new SessionToolGate($almacen, $sesion, [$this->probeMutAndAuth()], policyProvider: $provider, identity: null);
        self::assertNotNull($gateSinDuenio->refuse('probe', ['dry_run' => true]));
    }

    /** A heavy operation whose rehearsal descent lowers BOTH mutation (certificate) and authority (policy). */
    private function probeMutAndAuth(): Operation
    {
        $handler = static fn (array $i): array => ['ok' => true];
        $digest = (new Operation('probe', 'digest', $handler))->handlerDigest();
        $to = new EffectProfile(
            mutation: Mutation::None,
            externality: Externality::ThirdParty,
            reversibility: Reversibility::Compensatable,
            authority: Authority::Read,
            subject: Subject::None,
        );
        $cert = (new DescentCertificate(
            verifier: 'verify-descent/2026-08-18',
            operation: 'probe',
            predicate: ['dry_run' => true],
            covers: ['mutation'],
            to: $to,
            handlerSha256: $digest,
            verifierPublicKey: $this->publica,
        ))->signedWith($this->privada);

        return new Operation(
            name: 'probe',
            description: 'heavy, unless rehearsing',
            handler: $handler,
            inputSchema: ['type' => 'object', 'properties' => ['dry_run' => ['type' => 'boolean', 'description' => 'rehearse']]],
            mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::ThirdParty,
                reversibility: Reversibility::Compensatable,
                authority: Authority::Privileged,
                subject: Subject::None,
                descents: [new Descent('dry_run', true, $to, 'rehearsal, judged and certified', $cert)],
            ),
        );
    }

    private function gate(AutonomyMode $modo, bool $forgeSignature = false): SessionToolGate
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s-1', 'goal', $modo);
        $sesion = $almacen->load('s-1');
        self::assertNotNull($sesion);

        return new SessionToolGate($almacen, $sesion, [$this->probe($forgeSignature)]);
    }

    /** A heavy operation whose descent, when rehearsed, drops mutation to None — certified. */
    private function probe(bool $forgeSignature): Operation
    {
        $handler = static fn (array $i): array => ['ok' => true];
        // The certificate is bound to this exact handler body, the way a real one is.
        $bare = new Operation('probe', 'to read its digest', $handler);
        $digest = $bare->handlerDigest();

        $to = new EffectProfile(
            mutation: Mutation::None,
            externality: Externality::None,
            reversibility: Reversibility::Guaranteed,
            authority: Authority::Read,
            subject: Subject::None,
            rollbackContract: 'nothing ran',
        );
        $cert = (new DescentCertificate(
            verifier: 'verify-descent/2026-08-18',
            operation: 'probe',
            predicate: ['dry_run' => true],
            covers: ['mutation', 'externality', 'reversibility', 'subject'],
            to: $to,
            handlerSha256: $digest,
            verifierPublicKey: $this->publica,
        ))->signedWith($forgeSignature ? sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()) : $this->privada);

        return new Operation(
            name: 'probe',
            description: 'heavy, unless only rehearsing',
            handler: $handler,
            inputSchema: ['type' => 'object', 'properties' => ['dry_run' => ['type' => 'boolean', 'description' => 'rehearse only']]],
            mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::ThirdParty,
                reversibility: Reversibility::Compensatable,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'installs for real',
                descents: [new Descent('dry_run', true, $to, 'the rehearsal prints and returns', $cert)],
            ),
        );
    }
}
