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
use Milpa\ValueObjects\Tooling\ToolOptions;
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

    /**
     * WHAT A PERSON CAN ADMIT FOR A SEAT, THE SEAT IS OFFERED (greenhouse decisions/0601, rule A, with
     * decisions/0590). The offer asks the door's first question AS THE CALLER: the seat, by its own principal.
     *
     * Measured by another thread walking a work station dry: the offer asked it as nobody, and the door hands a built
     * verb's own words only to a seat — so no seat was ever offered a built verb. Not one a person had admitted for
     * it; and not one a person could, because a call the loop answers «not offered» never reaches the door, and the
     * refusal a person admits from was never recorded.
     */
    public function testASeatIsOfferedAVerbNobodyAdmittedBecauseItsRefusalIsWhatAPersonAdmitsFrom(): void
    {
        [$door] = $this->leg();

        $offered = array_column($door->getToolSummaries(), 'name');
        foreach (['herramientas_listar', 'herramientas_agregar', 'herramientas_prestar', 'herramientas_devolver'] as $verb) {
            self::assertContains($verb, $offered);
            self::assertNotContains($verb, $door->notOfferedToThisCaller());
        }
        // And the call reaches the door, which is where the refusal a person admits from is recorded.
        try {
            $door->callTool('herramientas_prestar', ['id' => 1]);
            self::fail('nobody admitted it');
        } catch (ToolCallRefused $refused) {
            self::assertStringContainsString('no person has admitted it for this seat', $refused->getMessage());
        }
        self::assertCount(1, $this->recorded);
    }

    public function testASeatIsOfferedAVerbAPersonAdmittedForIt(): void
    {
        [$door, $root, $kernel] = $this->leg();
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', 'herramientas:write');
        self::assertNotNull($group);
        self::assertTrue($this->ledger($root)->admit(self::SEAT, 'Prestamos', 'herramientas:write', $group['verbs'], 'key:' . self::HUMAN));

        self::assertContains('herramientas_prestar', array_column($door->getToolSummaries(), 'name'));
        self::assertNotContains('herramientas_prestar', $door->notOfferedToThisCaller());
        self::assertSame(['ok' => true, 'ran' => 'herramientas.prestar'], $door->callTool('herramientas_prestar', ['id' => 1]));
    }

    public function testACallerThatIsNoSeatIsNotOfferedAVerbWhoseWordItDoesNotHoldAndTheDoorAgrees(): void
    {
        // The same scopes, held by a key this house never enrolled: nobody can admit anything for it.
        [$door] = $this->leg('key:' . str_repeat('E', 40));

        self::assertContains('herramientas_prestar', $door->notOfferedToThisCaller());
        self::assertNotContains('herramientas_prestar', array_column($door->getToolSummaries(), 'name'));
        try {
            $door->callTool('herramientas_prestar', ['id' => 1]);
            self::fail('it holds no word the verb declares, and it is no seat');
        } catch (ToolCallRefused $refused) {
            self::assertStringNotContainsString('no person has admitted it for this seat', $refused->getMessage());
        } catch (\Throwable $refused) {
            self::assertStringContainsString('scope', strtolower($refused->getMessage()));
        }
    }

    public function testWhatASeatsOfferLeavesOutTheDoorRefusesForTheWordItDeclares(): void
    {
        [$door, , , $registry] = $this->leg();
        // An operation of the house that is no built verb and asks for a word the seat does not hold: nobody admits
        // it for a seat, so it is what the offer still leaves out.
        $registry->register('house_secret', 'A tool of the house', ['type' => 'object', 'properties' => []], static fn (): array => ['ok' => true], new ToolOptions(scopes: ['config:write']));
        $left = $door->notOfferedToThisCaller();
        self::assertSame(['house_secret'], $left);
        self::assertNotContains('house_secret', array_column($door->getToolSummaries(), 'name'));
        try {
            $door->callTool('house_secret', []);
            self::fail('the seat holds no word it declares');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('scope', strtolower($refused->getMessage()));
            self::assertStringNotContainsString('no person has admitted it for this seat', $refused->getMessage());
        }

        $seat = new ToolContext('key:' . self::SEAT, 'cli', self::SEAT_SCOPES);
        foreach (array_column($registry->getToolSummaries(), 'name') as $name) {
            $definition = $registry->getDefinition($name);
            self::assertNotNull($definition);
            self::assertSame(
                !\in_array($name, $left, true),
                $registry->getPolicyGate()->authorizeScopes($seat, $name, $definition->scopes)->allowed,
                $name . ': the offer and the door, asked as the seat, say the same',
            );
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
    private function leg(string $principal = 'key:' . self::SEAT): array
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
        (new \ReflectionProperty(AgentOperations::class, 'toolAuthority'))->setValue($operations, new ToolContext($principal, 'cli', self::SEAT_SCOPES));

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
