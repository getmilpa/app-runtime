<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\McpClientService;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\LaunchGrants;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Support\ContratoInstalado;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Launch grants: the operator's consent, seeded once, seen by BOTH judges — and no wider than the
 * scope it names.
 *
 * The battery mirrors the frozen consent battery (greenhouse decisions/0031) at the seeding seam,
 * with the two positive controls that decide whether this is a mechanism or an excuse:
 *
 *   In scope      · a consent-requiring tool runs on channel `cli` with no signature
 *   Out of scope  · the SAME tool with other arguments still stops — the grant does not widen
 *   Doctrine      · a signature-class operation is refused at seeding, with the doctrine named
 *   Idempotence   · re-seeding the same entry appends nothing
 *
 * @internal
 */
final class LaunchGrantsTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $corridas = [];

    protected function setUp(): void
    {
        $this->corridas = [];
    }

    private function almacen(): SessionStore
    {
        return new SessionStore(new InMemoryEventStore());
    }

    /** One declared operation for the catalogue the seeding judges against. */
    private function operacion(
        string $name,
        Authority $authority = Authority::WriteAsUser,
        bool $requiresConfirmation = true,
        Externality $externality = Externality::None,
        Subject $subject = Subject::Data,
    ): Operation {
        return new Operation(
            name: $name,
            description: 'test double',
            handler: static fn (array $input): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
            requiresConfirmation: $requiresConfirmation,
            effects: new EffectProfile(
                Mutation::Persistent,
                $externality,
                Reversibility::Compensatable,
                $authority,
                subject: $subject,
            ),
        );
    }

    // ── PARSING: the CLI shape, refused whole when malformed ────────────────────────────────────

    public function testParseReadsEntriesArgumentsAndColonNamedOperations(): void
    {
        $entries = LaunchGrants::parse('plugins_register:plugin=Tareas, make:plugin=Tareas;fields=titulo, plan');

        self::assertSame([
            ['operation' => 'plugins_register', 'arguments' => ['plugin' => 'Tareas']],
            ['operation' => 'make', 'arguments' => ['plugin' => 'Tareas', 'fields' => 'titulo']],
            ['operation' => 'plan', 'arguments' => []],
        ], $entries);

        // The operation may itself carry colons: the argument separator is the LAST colon before
        // the first `=`, so a colon-form name is not split in half.
        self::assertSame(
            [['operation' => 'plugins:register', 'arguments' => ['plugin' => 'X']]],
            LaunchGrants::parse('plugins:register:plugin=X'),
        );

        self::assertSame([], LaunchGrants::parse(null));
        self::assertSame([], LaunchGrants::parse(''));
    }

    public function testAMalformedEntryRefusesTheWholeBrief(): void
    {
        self::assertIsString(LaunchGrants::parse('plugin=X'), 'arguments with no operation are refused');
        self::assertIsString(LaunchGrants::parse('make:=x'), 'an empty argument key is refused');
        self::assertIsString(LaunchGrants::parse('make:plugin=X;fields'), 'a pair without `=` is refused');
    }

    // ── SEEDING: the same facts a human yes leaves, once, distinguishable ───────────────────────

    public function testSeedingLeavesTheSameFactsAHumanYesLeavesAndThePolicyJudgeSeesThem(): void
    {
        $store = $this->almacen();
        $store->start('s1', 'register the Tareas plugin', AutonomyMode::Ask);

        $entries = LaunchGrants::parse('plugins_register:plugin=Tareas');
        self::assertIsArray($entries);
        $out = (new LaunchGrants())->seed(
            $store,
            's1',
            $entries,
            [$this->operacion('plugins:register')],
            new Principal('cli:rod@lab'),
        );

        self::assertSame(['plugins:register'], $out['seeded'] ?? null);

        $sesion = $store->load('s1');
        self::assertNotNull($sesion);
        self::assertNull($sesion->question, 'the question was answered at launch: the session is not paused');
        self::assertTrue($sesion->allows('plugins:register'), 'the policy judge sees the grant');

        $decision = $sesion->decisions[0] ?? null;
        self::assertIsArray($decision);
        self::assertSame('permission', $decision['reason']);
        self::assertSame(LaunchGrants::EXECUTOR, $decision['executor'], 'an auditor tells a launch grant from a mid-session yes');
        self::assertInstanceOf(Principal::class, $decision['by']);
        self::assertSame('cli:rod@lab', $decision['by']->id);

        $why = json_decode((string) $decision['why'], true);
        self::assertSame('plugins:register', $why['operation'] ?? null);
        self::assertSame(['plugin' => 'Tareas'], $why['arguments'] ?? null, 'the argument constraints travel in the fact');
    }

    public function testTheRebuiltConsentGrantCarriesLaunchProvenanceAndTheArgumentConstraints(): void
    {
        $grants = $this->grantsRebuiltFromASeededSession();

        self::assertCount(1, $grants);
        self::assertInstanceOf(ConsentGrant::class, $grants[0]);
        self::assertTrue($grants[0]->operation->is('plugins_register'), 'identity, never spelling');
        self::assertSame(['plugin' => 'Tareas'], $grants[0]->arguments);
        self::assertSame(LaunchGrants::EXECUTOR, $grants[0]->provenance, 'the gate judge can name where the yes came from');
        self::assertSame('cli:rod@lab', $grants[0]->principal);
        self::assertSame('s1', $grants[0]->session);
    }

    // ── THE GATE JUDGE, END TO END: in scope runs, out of scope still stops ─────────────────────

    public function testASeededInScopeGrantLetsAConsentRequiringToolRunOnCliWithNoSignature(): void
    {
        $puente = $this->puenteDeUnaSesionSembrada();

        $resultado = $puente->callTool('plugins_register', ['plugin' => 'Tareas']);

        self::assertCount(1, $this->corridas, 'the tool ran exactly once, with no signature presented');
        self::assertSame(['plugin' => 'Tareas'], $this->corridas[0]);
        self::assertIsArray($resultado);
        self::assertArrayNotHasKey('requires_confirmation', $resultado, 'nothing is left pending');
    }

    public function testPositiveControlTheSameToolOutsideTheGrantedScopeStillStops(): void
    {
        $puente = $this->puenteDeUnaSesionSembrada();

        // The operator consented to plugin=Tareas. The call is for plugin=Otro: the grant must not
        // widen — same operation, same session, different call.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/needs explicit consent/');

        try {
            $puente->callTool('plugins_register', ['plugin' => 'Otro']);
        } finally {
            self::assertSame([], $this->corridas, 'NOTHING ran: the grant covers its scope and no more');
        }
    }

    // ── THE DOCTRINE LIMIT: signature-class is refused at seeding, whole ────────────────────────

    public function testPositiveControlSeedingASignatureClassOperationIsRefusedWithTheDoctrine(): void
    {
        $store = $this->almacen();
        $store->start('s1', 'goal', AutonomyMode::Ask);
        $antes = \count($store->stream('s1'));

        $entries = LaunchGrants::parse('plan,capabilities_enable:capability=agent');
        self::assertIsArray($entries);
        $out = (new LaunchGrants())->seed(
            $store,
            's1',
            $entries,
            [
                $this->operacion('plan', Authority::WriteAsUser, false),
                // Its REAL axes: the enable goes to the registry (ThirdParty) — that egress is
                // what makes it signature-class under the two-axis rule.
                $this->operacion('capabilities:enable', Authority::Privileged, false, Externality::ThirdParty, Subject::Executable),
            ],
            new Principal('cli:rod@lab'),
        );

        self::assertArrayHasKey('error', $out);
        self::assertStringContainsString('a grant cannot replace it', (string) $out['error']);
        self::assertCount($antes, $store->stream('s1'), 'all or nothing: the admissible half was not seeded either');
        self::assertFalse($store->load('s1')?->allows('plan') ?? true);
    }

    public function testAnOperationTheCatalogueDoesNotDeclareCannotBeGranted(): void
    {
        $store = $this->almacen();
        $store->start('s1', 'goal', AutonomyMode::Ask);
        $antes = \count($store->stream('s1'));

        $entries = LaunchGrants::parse('nadie:x=1');
        self::assertIsArray($entries);
        $out = (new LaunchGrants())->seed($store, 's1', $entries, [$this->operacion('plugins:register')], new Principal('p'));

        self::assertArrayHasKey('error', $out);
        self::assertStringContainsString('cannot be judged', (string) $out['error']);
        self::assertCount($antes, $store->stream('s1'));
    }

    // ── FATIGUE, MEASURED GONE: in ask mode a seeded grant does not re-ask ──────────────────────

    public function testInAskModeAnInScopeMutatingOpWithASeededGrantDoesNotReAsk(): void
    {
        $store = $this->almacen();
        $store->start('s1', 'register the Tareas plugin', AutonomyMode::Ask);
        $entries = LaunchGrants::parse('plugins_register:plugin=Tareas');
        self::assertIsArray($entries);
        (new LaunchGrants())->seed($store, 's1', $entries, [$this->operacion('plugins:register')], new Principal('cli:rod@lab'));

        $sesion = $store->load('s1');
        self::assertNotNull($sesion);
        $gate = new SessionToolGate($store, $sesion, [$this->operacion('plugins:register')]);

        self::assertNull(
            $gate->refuse('plugins_register', ['plugin' => 'Tareas']),
            'no pause: the operator already answered at launch',
        );

        // THE CONTROL that separates «the grant admits» from «the gate stopped judging»: the same
        // call in the same mode WITHOUT the seeded grant pauses the session on a question.
        $sinGrant = $this->almacen();
        $sinGrant->start('s2', 'register the Tareas plugin', AutonomyMode::Ask);
        $sesion2 = $sinGrant->load('s2');
        self::assertNotNull($sesion2);
        $gate2 = new SessionToolGate($sinGrant, $sesion2, [$this->operacion('plugins:register')]);

        self::assertNotNull($gate2->refuse('plugins_register', ['plugin' => 'Tareas']), 'without the grant, it asks');
        self::assertNotNull($sinGrant->load('s2')?->question, 'and the question is a fact of the stream');
    }

    // ── IDEMPOTENCE: a resume re-seeding the same brief appends nothing ─────────────────────────

    public function testReseedingTheSameEntryIsIdempotent(): void
    {
        $store = $this->almacen();
        $store->start('s1', 'goal', AutonomyMode::Ask);
        $entries = LaunchGrants::parse('plugins_register:plugin=Tareas');
        self::assertIsArray($entries);
        $catalogo = [$this->operacion('plugins:register')];

        (new LaunchGrants())->seed($store, 's1', $entries, $catalogo, new Principal('p'));
        $despuesDeUna = \count($store->stream('s1'));

        $segunda = (new LaunchGrants())->seed($store, 's1', $entries, $catalogo, new Principal('p'));

        self::assertSame([], $segunda['seeded'] ?? null);
        self::assertSame(['plugins:register'], $segunda['already'] ?? null);
        self::assertCount($despuesDeUna, $store->stream('s1'), 'the event count is stable: one decision, not two');

        // And the same entry twice in ONE brief seeds once too.
        $doble = $this->almacen();
        $doble->start('s3', 'goal', AutonomyMode::Ask);
        $dos = LaunchGrants::parse('plugins_register:plugin=Tareas,plugins_register:plugin=Tareas');
        self::assertIsArray($dos);
        $out = (new LaunchGrants())->seed($doble, 's3', $dos, $catalogo, new Principal('p'));
        self::assertSame(['plugins:register'], $out['seeded'] ?? null);
        self::assertSame(['plugins:register'], $out['already'] ?? null);
    }

    // ── helpers that walk the REAL rebuild path, never a re-implementation ──────────────────────

    /**
     * Seed a session, then rebuild its ConsentGrants exactly the way `run()` does — through
     * `grantsDeLaSesion()`, from the decisions the stream folded.
     *
     * @return list<ConsentGrant>
     */
    private function grantsRebuiltFromASeededSession(): array
    {
        $store = $this->almacen();
        $store->start('s1', 'register the Tareas plugin', AutonomyMode::Ask);
        $entries = LaunchGrants::parse('plugins_register:plugin=Tareas');
        self::assertIsArray($entries);
        (new LaunchGrants())->seed($store, 's1', $entries, [$this->operacion('plugins:register')], new Principal('cli:rod@lab'));

        $sesion = $store->load('s1');
        self::assertNotNull($sesion);

        $operations = new AgentOperations(new DIContainer());
        $decisiones = new \ReflectionProperty(AgentOperations::class, 'decisionesDeLaSesion');
        $decisiones->setAccessible(true);
        $decisiones->setValue($operations, ContratoInstalado::arreglo($sesion, 'decisions'));
        $deQuien = new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos');
        $deQuien->setAccessible(true);
        $deQuien->setValue($operations, 's1');

        $metodo = new \ReflectionMethod(AgentOperations::class, 'grantsDeLaSesion');
        $metodo->setAccessible(true);

        /** @var list<ConsentGrant> $grants */
        $grants = $metodo->invoke($operations);

        return $grants;
    }

    /** The governed door of a seeded session: a real registry, the rebuilt grants, channel cli. */
    private function puenteDeUnaSesionSembrada(): ConsentBridge
    {
        if (! class_exists(McpClientService::class)) {
            self::markTestSkipped('sin milpa/ai-gateway no hay cliente que puentear');
        }

        $registro = new ToolRegistry(new NullLogger());
        $registro->register(
            'plugins_register',
            'registers a plugin',
            ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']]],
            function (array $args): array {
                unset($args['_ctx']);
                $this->corridas[] = $args;

                return ['registered' => $args];
            },
            new ToolOptions(mutating: true, requiresConfirmation: true),
        );

        return new ConsentBridge($registro, $this->grantsRebuiltFromASeededSession());
    }
    public function testATruncatedEntryWithADanglingColonIsRefusedNotWidened(): void
    {
        $refusal = LaunchGrants::parse('plugins_register:');

        self::assertIsString($refusal, 'a truncated entry must refuse, never seed the argument-less form');
        self::assertStringContainsString('ends in «:»', $refusal);
    }
    public function testAPrivilegedIdentityShapedOperationStillRefusesSeeding(): void
    {
        $store = $this->almacen();
        $store->start('s1', 'goal', AutonomyMode::Ask);

        $out = (new LaunchGrants())->seed(
            $store,
            's1',
            [['operation' => 'identity:rotate', 'arguments' => []]],
            [$this->operacion('identity:rotate', Authority::Privileged, true, Externality::None, Subject::Configuration)],
            new Principal('cli:rod@lab'),
        );

        self::assertArrayHasKey('error', $out);
        self::assertStringContainsString('a grant cannot replace it', (string) $out['error']);
    }

    public function testAPrivilegedLocalWiringOperationIsSeedableTheMeasuredRegisterCase(): void
    {
        $store = $this->almacen();
        $store->start('s1', 'goal', AutonomyMode::Ask);

        $out = (new LaunchGrants())->seed(
            $store,
            's1',
            [['operation' => 'plugins_register', 'arguments' => ['plugin' => 'Tareas']]],
            [$this->operacion('plugins:register', Authority::Privileged, true, Externality::None, Subject::Executable)],
            new Principal('cli:rod@lab'),
        );

        self::assertArrayNotHasKey('error', $out, 'a local executable-wiring act is consentable: the product already accepted a mid-session yes for it');
        self::assertTrue($store->load('s1')?->allows('plugins:register') ?? false);
    }
}
