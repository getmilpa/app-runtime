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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\DebtSignal;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The bridge's debt signal, kind `scope_fragility` (greenhouse decisions/0183 primitive #5): the
 * PolicyGate refuses consent on channel cli while the session HOLDS a grant for the same operation
 * — its argument constraints just do not cover this call. The measured TareasPlugin case of
 * evidence/0444: a material naming decision silently changed the authority scope; the grant was
 * textual, the intent was not.
 *
 * The signal observes the denial, never softens it: the exception still travels, byte-identical,
 * and only the extra `session.debt_signaled` event differs — digests over raw values.
 *
 * @internal
 */
final class BridgeSignalsScopeFragilityTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(McpClientService::class)) {
            self::markTestSkipped('sin milpa/ai-gateway no hay cliente que puentear');
        }
    }

    /** A registry with ONE mutating tool that demands confirmation — the consent frontier. */
    private function registro(): ToolRegistry
    {
        $registro = new ToolRegistry(new NullLogger());
        $registro->register(
            'config_set',
            'writes a key',
            ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'boolean']]],
            static function (array $args): array {
                unset($args['_ctx']);

                return ['written' => $args];
            },
            new ToolOptions(mutating: true, requiresConfirmation: true),
        );

        return $registro;
    }

    /** @param array<string, mixed> $argumentos */
    private function grant(string $operacion, array $argumentos): ConsentGrant
    {
        return new ConsentGrant(
            operation: new OperationId($operacion),
            principal: 'cli:rod@casa',
            session: 's1',
            grantedAt: new \DateTimeImmutable('2026-08-13 12:00:00'),
            provenance: 'session.question_answered',
            arguments: $argumentos,
        );
    }

    /** @return list<object> */
    private function signalsIn(InMemoryEventStore $eventos): array
    {
        return array_values(array_filter(
            $eventos->replay(SessionStore::PREFIX . 's1'),
            static fn (object $evento): bool => $evento->type === DebtSignal::EVENT,
        ));
    }

    // ── THE MEASURED CASE: denied, while a same-operation grant sits right there ────────────────

    public function testADenialOverANonCoveringSameOperationGrantEmitsScopeFragility(): void
    {
        $eventos = new InMemoryEventStore();
        // The human authorised config_set(a, true); the call is config_set(a, FALSE).
        $puente = new ConsentBridge(
            $this->registro(),
            [$this->grant('config_set', ['key' => 'a', 'value' => true])],
            debtSignals: new DebtSignal($eventos, 's1'),
        );

        try {
            $puente->callTool('config_set', ['key' => 'a', 'value' => false]);
            self::fail('the consent frontier must refuse this call');
        } catch (\Exception $negada) {
            self::assertStringContainsString('needs explicit consent', $negada->getMessage());
        }

        $señales = $this->signalsIn($eventos);
        self::assertCount(1, $señales, 'one denial, one signal');
        self::assertSame([
            'signal' => 'scope_fragility',
            'context' => [
                'operation' => 'config.set',
                // Digests over raw values, both sides: what was granted and what was requested.
                'grantedArgumentsDigest' => ConsentBridge::digest(['key' => 'a', 'value' => true]),
                'requestedArgumentsDigest' => ConsentBridge::digest(['key' => 'a', 'value' => false]),
            ],
        ], $señales[0]->payload);
    }

    public function testTheSignalSitsAdjacentToTheRecordedRefusalItObserves(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s1', 'x', AutonomyMode::Ask);

        // The production recorder writes the refused call into the same stream (SessionToolGate
        // does exactly this); the signal must land right beside that fact.
        $grabadora = new class ($store) implements ToolCallRecorder {
            public function __construct(private readonly SessionStore $store)
            {
            }

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->store->recordToolCall('s1', $tool, $arguments, $result, $ok);
            }
        };

        $puente = new ConsentBridge(
            $this->registro(),
            [$this->grant('config_set', ['key' => 'a', 'value' => true])],
            recorder: $grabadora,
            debtSignals: new DebtSignal($eventos, 's1'),
        );

        try {
            $puente->callTool('config_set', ['key' => 'a', 'value' => false]);
            self::fail('the consent frontier must refuse this call');
        } catch (\Exception) {
        }

        $tipos = array_map(
            static fn (object $evento): string => $evento->type,
            $eventos->replay(SessionStore::PREFIX . 's1'),
        );
        self::assertSame(
            ['session.tool_called', DebtSignal::EVENT],
            \array_slice($tipos, -2),
            'the signal follows the recorded refusal immediately',
        );
    }

    public function testSeveralStaleGrantsForTheSameOperationStillEmitOneSignal(): void
    {
        $eventos = new InMemoryEventStore();
        $puente = new ConsentBridge(
            $this->registro(),
            [
                $this->grant('config_set', ['key' => 'a', 'value' => true]),
                $this->grant('config_set', ['key' => 'b', 'value' => true]),
            ],
            debtSignals: new DebtSignal($eventos, 's1'),
        );

        try {
            $puente->callTool('config_set', ['key' => 'a', 'value' => false]);
            self::fail('the consent frontier must refuse this call');
        } catch (\Exception) {
        }

        self::assertCount(1, $this->signalsIn($eventos), 'one occurrence, one signal — never one per stale grant');
    }

    // ── THE NEGATIVES: a denial alone is not fragility ──────────────────────────────────────────

    public function testADenialWithNoGrantAtAllEmitsNothing(): void
    {
        $eventos = new InMemoryEventStore();
        $puente = new ConsentBridge($this->registro(), [], debtSignals: new DebtSignal($eventos, 's1'));

        try {
            $puente->callTool('config_set', ['key' => 'a', 'value' => false]);
            self::fail('the consent frontier must refuse this call');
        } catch (\Exception) {
        }

        self::assertSame([], $this->signalsIn($eventos), 'nothing was silently out of scope: no grant existed to be fragile');
    }

    public function testADenialWithOnlyAnotherOperationsGrantEmitsNothing(): void
    {
        $eventos = new InMemoryEventStore();
        $puente = new ConsentBridge(
            $this->registro(),
            [$this->grant('plugins_register', ['plugin' => 'TareasPlugin'])],
            debtSignals: new DebtSignal($eventos, 's1'),
        );

        try {
            $puente->callTool('config_set', ['key' => 'a', 'value' => false]);
            self::fail('the consent frontier must refuse this call');
        } catch (\Exception) {
        }

        self::assertSame([], $this->signalsIn($eventos), 'a grant for another operation is another question, not scope fragility');
    }

    public function testAnExecutionFailureIsNotADenialAndEmitsNothing(): void
    {
        $registro = $this->registro();
        $registro->register(
            'boom',
            'fails at runtime',
            ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
            static function (array $args): array {
                throw new \RuntimeException('kaboom');
            },
            new ToolOptions(mutating: true),
        );

        $eventos = new InMemoryEventStore();
        $puente = new ConsentBridge(
            $registro,
            [$this->grant('boom', ['x' => 1])],
            debtSignals: new DebtSignal($eventos, 's1'),
        );

        try {
            $puente->callTool('boom', ['x' => 2]);
            self::fail('the failing tool must surface its failure');
        } catch (\Throwable) {
        }

        self::assertSame([], $this->signalsIn($eventos), 'a tool that ran and failed is not a consent denial');
    }

    // ── NO BEHAVIOR CHANGE: the A/B falsifier ───────────────────────────────────────────────────

    public function testWithTheSeamAbsentTheDenialIsByteIdenticalAndOnlyTheSignalDiffers(): void
    {
        $mundo = function (?DebtSignal $señales): string {
            $puente = new ConsentBridge(
                $this->registro(),
                [$this->grant('config_set', ['key' => 'a', 'value' => true])],
                debtSignals: $señales,
            );
            try {
                $puente->callTool('config_set', ['key' => 'a', 'value' => false]);
            } catch (\Exception $negada) {
                return $negada->getMessage();
            }

            self::fail('the consent frontier must refuse this call');
        };

        $eventosB = new InMemoryEventStore();
        $mensajeA = $mundo(null);
        $mensajeB = $mundo(new DebtSignal($eventosB, 's1'));

        self::assertSame($mensajeA, $mensajeB, 'the denial travels byte-identical in both worlds');
        self::assertCount(1, $this->signalsIn($eventosB), 'the only difference is the observation');
    }
}
