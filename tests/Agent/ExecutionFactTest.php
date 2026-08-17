<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\Principal;
use Milpa\AiGateway\McpClientService;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\ExecutionRecorder;
use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The fact of an execution, emitted by the only frame that has both the authority and presence.
 *
 * The battery is Rod's, frozen in the greenhouse as `H-ATTRIBUTION-1` (decisions/0037) BEFORE a line
 * of this existed, and five measurements stand behind it:
 *
 *   0209 · rod authorised it, impostor ran it, and the record named only rod
 *   0210 · no event declares the fact — `tool_called` is a call, and one operation emits two
 *   0211 · the bridge is present at the instant of the effect; the gate that records is not
 *   0212 · without a token the bridge never consults a grant, so its chain came out empty
 *
 * What is proven here is not that an event is written — that would be the easy half — but the
 * properties that make the record worth reading a year from now: authority and executor kept apart,
 * an unattributed effect saying so instead of staying silent, and an attempt never wearing the face
 * of a fact.
 *
 * @internal
 */
final class ExecutionFactTest extends TestCase
{
    /** @var list<array{operation: string, executedBy: ?string, source: string, authorizedBy: ?array<string, mixed>, digest: string}> */
    private array $hechos = [];

    /** @var list<string> */
    private array $corridas = [];

    protected function setUp(): void
    {
        if (! class_exists(McpClientService::class)) {
            self::markTestSkipped('sin milpa/ai-gateway no hay cliente que puentear');
        }
        $this->hechos = [];
        $this->corridas = [];
    }

    private function testigo(): ExecutionRecorder
    {
        return new class ($this->hechos) implements ExecutionRecorder {
            /** @param list<array<string, mixed>> $hechos */
            public function __construct(public array &$hechos)
            {
            }

            public function executed(
                string $operation,
                ?Principal $executedBy,
                string $executorSource,
                ?array $authorizedBy,
                string $argumentsDigest,
            ): void {
                $this->hechos[] = [
                    'operation' => $operation,
                    'executedBy' => $executedBy?->id,
                    'source' => $executorSource,
                    'authorizedBy' => $authorizedBy,
                    'digest' => $argumentsDigest,
                ];
            }
        };
    }

    private function registro(): ToolRegistry
    {
        $registro = new ToolRegistry(new NullLogger());
        $registro->register(
            'config_set',
            'writes a key',
            ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'boolean']]],
            function (array $args): array {
                unset($args['_ctx']);
                $this->corridas[] = 'config_set';

                return ['written' => $args];
            },
            new ToolOptions(mutating: true, requiresConfirmation: true),
        );
        // The other shape evidence/0212 measured: it mutates and demands NO token, so it executes in a
        // single call and the bridge never looks for a covering grant.
        $registro->register(
            'capabilities_refresh',
            'derives the index',
            ['type' => 'object', 'properties' => []],
            function (array $args): array {
                unset($args['_ctx']);
                $this->corridas[] = 'capabilities_refresh';

                return ['ok' => true];
            },
            new ToolOptions(mutating: true),
        );
        // A reader. Nothing it does is an effect, and nothing about it belongs in this record.
        $registro->register(
            'plugins_list',
            'lists plugins',
            ['type' => 'object', 'properties' => []],
            function (array $args): array {
                unset($args['_ctx']);
                $this->corridas[] = 'plugins_list';

                return ['plugins' => []];
            },
            new ToolOptions(),
        );

        return $registro;
    }

    /** @param list<ConsentGrant> $grants */
    private function puente(ToolRegistry $registro, array $grants, ?ObservedExecutor $ejecutor = null): ConsentBridge
    {
        return new ConsentBridge(
            $registro,
            $grants,
            executions: $this->testigo(),
            executor: $ejecutor ?? new ObservedExecutor(new Principal('cli:impostor@cm4070'), 'terminal-environment'),
        );
    }

    /** @param array<string, mixed> $argumentos */
    private function grant(string $operacion, array $argumentos): ConsentGrant
    {
        return new ConsentGrant(
            operation: new OperationId($operacion),
            principal: 'cli:rod@casa',
            session: 's1',
            grantedAt: new \DateTimeImmutable('2026-08-16 12:00:00'),
            provenance: 'session.question_answered',
            arguments: $argumentos,
        );
    }

    /**
     * 1 · AUTHORITY AND EXECUTOR ARE KEPT APART — the whole point, and what evidence/0209 measured
     * missing: rod authorised it, impostor ran it, and the record named only rod.
     */
    public function testTheFactKeepsAuthorityAndExecutorApart(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [$this->grant('config_set', ['key' => 'a', 'value' => true])]);

        $puente->callTool('config_set', ['key' => 'a', 'value' => true]);

        self::assertCount(1, $this->hechos, 'one effect, one fact');
        self::assertSame('config.set', $this->hechos[0]['operation'], 'the canonical identity, not the tool spelling');
        self::assertSame('cli:impostor@cm4070', $this->hechos[0]['executedBy']);
        self::assertSame('cli:rod@casa', $this->hechos[0]['authorizedBy']['principal']);
        self::assertSame('session.question_answered', $this->hechos[0]['authorizedBy']['provenance']);
        self::assertSame('terminal-environment', $this->hechos[0]['source']);
    }

    /**
     * 2 · AN ATTEMPT IS NOT A FACT (evidence/0210).
     *
     * A call that only asked for confirmation comes back successful. If it left a fact, an audit would
     * count two effects where there was one, and the second would be attributed to nobody's act.
     */
    public function testACallThatOnlyAskedForConfirmationLeavesNoFact(): void
    {
        $registro = $this->registro();
        // No grant covers it, so the token is minted and left waiting: nothing was materialised.
        $puente = $this->puente($registro, []);

        try {
            $puente->callTool('config_set', ['key' => 'a', 'value' => true]);
        } catch (\Throwable) {
            // The gate refusing one step earlier is also "nothing happened".
        }

        self::assertSame([], $this->corridas, 'nothing ran');
        self::assertSame([], $this->hechos, 'and nothing was declared to have happened');
    }

    /**
     * 3 · AN EFFECT NOBODY AUTHORISED SAYS SO — it does not stay silent (evidence/0212).
     *
     * An operation that demands no token never makes the bridge consult a grant. The effect still
     * happened, and it is exactly the case an audit needs: `authorized_by` is null, and that null is a
     * statement.
     */
    public function testAnUnauthorisedEffectIsRecordedAsUnattributed(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, []);

        $puente->callTool('capabilities_refresh', []);

        self::assertSame(['capabilities_refresh'], $this->corridas);
        self::assertCount(1, $this->hechos, 'the effect happened, so it is declared');
        self::assertNull($this->hechos[0]['authorizedBy'], 'no consent covered this call, and the record says it');
        self::assertSame('cli:impostor@cm4070', $this->hechos[0]['executedBy'], 'who ran it is still known');
    }

    /** 4 · READING IS NOT AN EFFECT. A record of everything is a record of nothing. */
    public function testAReadLeavesNoFact(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, []);

        $puente->callTool('plugins_list', []);

        self::assertSame(['plugins_list'], $this->corridas);
        self::assertSame([], $this->hechos);
    }

    /**
     * 5 · NO OBSERVABLE EXECUTOR IS `unknown`, NEVER A RECONSTRUCTED PRINCIPAL (decisions/0037, item 6).
     *
     * The bridge is handed what was observed. Where nothing was, it says nothing was — it does not go
     * and ask the environment, because an identity read at write time is the defect this replaces.
     */
    public function testWithoutAnObservedExecutorTheFactSaysUnknown(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [], new ObservedExecutor(null, 'unknown'));

        $puente->callTool('capabilities_refresh', []);

        self::assertCount(1, $this->hechos);
        self::assertNull($this->hechos[0]['executedBy'], 'nobody is invented');
        self::assertSame('unknown', $this->hechos[0]['source']);
    }

    /** 6 · THE ARGUMENTS ARE REFERENCED, NOT COPIED — and the same arguments give the same digest. */
    public function testTheDigestIsStableAndIndependentOfKeyOrder(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [$this->grant('config_set', ['key' => 'a', 'value' => true])]);

        $puente->callTool('config_set', ['key' => 'a', 'value' => true]);
        $puente->callTool('config_set', ['value' => true, 'key' => 'a']);

        self::assertCount(2, $this->hechos);
        self::assertSame($this->hechos[0]['digest'], $this->hechos[1]['digest']);
        self::assertStringStartsWith('sha256:', $this->hechos[0]['digest']);
    }

    /**
     * 7 · F-1 OF `decisions/0041` — the run that graduates it.
     *
     * Same principal, same consent over the operation, same execution semantics, DIFFERENT effect
     * profile and therefore a different path: `config_set` demands a token, `capabilities_refresh`
     * does not. The authority must come out identical.
     *
     * `evidence/0230` measured what happens when it does not: 12 of 13 mutating operations filed
     * their effect under `authorized_by: null`, and the only one that kept its author was the one
     * declaring nothing about its effects. Declaring your effects honestly must never buy less
     * traceability.
     */
    public function testTheAuthorityIsTheSameWhicheverPathTheEffectProfileChose(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [
            $this->grant('config_set', ['key' => 'a', 'value' => true]),
            $this->grant('capabilities_refresh', []),
        ]);

        $puente->callTool('config_set', ['key' => 'a', 'value' => true]);
        $puente->callTool('capabilities_refresh', []);

        self::assertCount(2, $this->hechos, 'two effects, two facts');
        [$conToken, $sinToken] = $this->hechos;

        self::assertSame('config.set', $conToken['operation']);
        self::assertSame('capabilities.refresh', $sinToken['operation']);
        self::assertSame(
            $conToken['authorizedBy'],
            $sinToken['authorizedBy'],
            'the effect profile may change the enforcement, never the provenance of the consent',
        );
        self::assertSame('cli:rod@casa', $sinToken['authorizedBy']['principal'] ?? null);
    }

    /**
     * 8 · F-2 OF `decisions/0041` — the authority survives the executor, on the SHORT path too.
     *
     * A grant by A, materialised by process B: `authorized_by` stays A and `executed_by` may be B.
     * Test 1 already pinned this for the token path; if it does not hold where no token exists, then
     * «who said yes» and «who did it» are still one variable wearing two names.
     */
    public function testTheGrantsAuthorityOutlivesWhoeverMaterialisedItWithoutAToken(): void
    {
        $registro = $this->registro();
        $puente = $this->puente(
            $registro,
            [$this->grant('capabilities_refresh', [])],
            new ObservedExecutor(new Principal('cli:otro@otra-maquina'), 'terminal-environment'),
        );

        $puente->callTool('capabilities_refresh', []);

        self::assertCount(1, $this->hechos);
        self::assertSame('cli:rod@casa', $this->hechos[0]['authorizedBy']['principal'] ?? null, 'A said yes');
        self::assertSame('cli:otro@otra-maquina', $this->hechos[0]['executedBy'], 'B did it');
    }

    /**
     * 9 · AND THE NULL STILL MEANS SOMETHING — the control that stops 7 and 8 from being vacuous.
     *
     * With no grant covering it, the fact must still be written and its authority must still be
     * absent. If filling it were unconditional, the two tests above would pass on a bridge that
     * attributes every effect to whoever happens to be around.
     */
    public function testWithoutACoveringGrantTheAuthorityStaysAbsent(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [$this->grant('config_set', ['key' => 'a', 'value' => true])]);

        $puente->callTool('capabilities_refresh', []);

        self::assertCount(1, $this->hechos);
        self::assertNull($this->hechos[0]['authorizedBy'], 'a grant for another operation covers nothing');
    }
}
