<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\AdmissionAwareRegistry;
use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\ToolCallRecorder;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The leg of a seat, as the house itself assembles it, meeting a verb of a built capability (greenhouse
 * decisions/0590, rules 1 and 14).
 *
 * Measured before (decisions/0585, session `w2`): the seat asked for the workshop's verbs four times, was told
 * «Missing required scope», nobody could grant it, and the session ended stalled. Here the catalogue, the registry,
 * the policy seated in the session and the door are the ones `agent` builds; only the model is absent.
 */
final class ASeatsLegMeetsABuiltVerbTest extends TestCase
{
    use BuiltHouse;

    private const SESSION = 'taller';

    /** @var list<array{string, array<string, mixed>, string, bool}> */
    private array $recorded = [];

    public function testTheLegEndsAtAVerbNobodyAdmittedAndWaitsForAPerson(): void
    {
        [$door] = $this->leg();

        try {
            $door->callTool('herramientas_prestar', ['id' => 1]);
            self::fail('nobody admitted the verb for this seat: it must not run');
        } catch (ToolCallRefused $refused) {
            self::assertFalse($refused->optionRemoved, 'the verb is still there: the leg ends, the model does not look for a way around');
            self::assertStringContainsString('«herramientas.prestar» is a verb of the capability «Prestamos»', $refused->getMessage());
            self::assertStringContainsString('no person has admitted it for this seat', $refused->getMessage());
            self::assertStringContainsString('Whoever enrolled this seat admits it', $refused->getMessage());
            self::assertStringNotContainsString('Missing required scope', $refused->getMessage());
        }
        self::assertSame([['herramientas_prestar', ['id' => 1], false]], array_map(static fn (array $r): array => [$r[0], $r[1], $r[3]], $this->recorded));
    }

    public function testAfterAPersonAdmitsItTheSameLegRunsIt(): void
    {
        [$door, $root, $kernel] = $this->leg();
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', 'herramientas:write');
        self::assertNotNull($group);
        self::assertTrue($this->ledger($root)->admit(self::SEAT, 'Prestamos', 'herramientas:write', $group['verbs'], 'key:' . self::HUMAN));

        self::assertSame(['ok' => true, 'ran' => 'herramientas.prestar'], $door->callTool('herramientas_prestar', ['id' => 1]));
        // What it was admitted is its own: the other scope of the capability still waits for a person.
        try {
            $door->callTool('herramientas_listar', []);
            self::fail('reading was not admitted');
        } catch (ToolCallRefused $refused) {
            self::assertStringContainsString('no person has admitted it for this seat', $refused->getMessage());
        }
    }

    public function testTheRegistryALegIsHandedJudgesTheHousesWay(): void
    {
        [, , , $registry] = $this->leg();

        self::assertInstanceOf(AdmissionAwareRegistry::class, $registry);
    }

    /**
     * The door the `agent` operation builds for a seat's leg in a house with the course's capability.
     *
     * @return array{0: ConsentBridge, 1: string, 2: Kernel, 3: ToolRegistry}
     */
    private function leg(): array
    {
        $root = $this->root();
        $container = new DIContainer();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())], [], $container);
        (new \ReflectionProperty(Kernel::class, 'toolRegistry'))->setValue($kernel, new ToolRegistry(new NullLogger()));
        $container->registerService(Kernel::class, $kernel);
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(EventStoreInterface::class, $events);
        $sessions->start(self::SESSION, 'Registra un taladro en el taller y préstalo.', by: new Principal('key:' . self::SEAT, true));

        $operations = new AgentOperations($container);
        (new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos'))->setValue($operations, self::SESSION);
        (new \ReflectionProperty(AgentOperations::class, 'sessionEvents'))->setValue($operations, $events);
        (new \ReflectionProperty(AgentOperations::class, 'toolAuthority'))->setValue($operations, new ToolContext('key:' . self::SEAT, 'cli', self::SEAT_SCOPES));

        $registry = (new \ReflectionMethod(AgentOperations::class, 'toolsOfThisApp'))->invoke($operations);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        (new \ReflectionMethod(AgentOperations::class, 'seatThePolicy'))->invoke($operations, $registry, $sessions, self::SESSION);
        $door = (new \ReflectionMethod(AgentOperations::class, 'governedExecutor'))->invoke($operations, $registry, null, $this->recorder($sessions), null);
        self::assertInstanceOf(ConsentBridge::class, $door);

        return [$door, $root, $kernel, $registry];
    }

    private function recorder(SessionStore $sessions): ToolCallRecorder
    {
        return new class ($this->recorded, $sessions) implements ToolCallRecorder {
            /** @param list<array{string, array<string, mixed>, string, bool}> $recorded */
            public function __construct(private array &$recorded, private SessionStore $sessions)
            {
            }

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->recorded[] = [$tool, $arguments, $result, $ok];
                $this->sessions->recordToolCall('taller', $tool, $arguments, $result, $ok);
            }
        };
    }
}
