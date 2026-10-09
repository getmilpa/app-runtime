<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\EffectObservation;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\HouseExecutedWork;
use Milpa\AppRuntime\Agent\OwnVerbRehearsal;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Tests\Fixtures\ExercisedTaller;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
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
 * A SESSION REHEARSES THE VERB IT BUILT (greenhouse decisions/0605, R2) — the whole, in a house: a capability in the
 * house's own tree, a seat that holds its building permit, the session that landed it, the judge of decisions/0590
 * on the gate, the door of the leg, and the house's own trial runner and confinement.
 *
 * The seat calls its verb. The judge refuses, in its own words; that refusal is recorded as it always was. And the
 * model is handed, after it, what the call answered in a copy that is discarded — so the leg goes on.
 */
final class ASessionRehearsesTheVerbItBuiltTest extends TestCase
{
    private const HUMAN = 'BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666';
    private const SEAT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';
    private const OTHER_SEAT = 'DDDD4444EEEE5555FFFF6666AAAA7777BBBB8888';
    private const SEAT_SCOPES = ['agent:run', 'agent:read', 'plugins:read', 'plugins:write', 'plugins.config:write', 'plugins.Taller:write'];
    private const FILE = 'src/Plugins/Taller/Taller.php';

    private string $root;

    private Kernel $kernel;

    private InMemoryEventStore $events;

    private SessionStore $sessions;

    private DIContainer $container;

    protected function setUp(): void
    {
        if (! (new TrialRunner())->available()) {
            self::markTestSkipped('no unprivileged user namespace here: the sandbox cannot be exercised');
        }
        $this->root = (string) realpath(sys_get_temp_dir()) . '/milpa-rehearse-' . bin2hex(random_bytes(6));
        $plugin = ExercisedTaller::in($this->root);
        require_once $this->root . '/' . self::FILE;
        mkdir($this->root . '/storage/identity', 0o777, true);
        file_put_contents($this->root . '/config/identity.php', "<?php return ['rooted' => ['" . self::SEAT . "', '" . self::OTHER_SEAT . "']];");
        $ledger = new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
        // Two seats of the same human, both holding the capability's building permit: it is in works.
        $ledger->record(new IdentityEnrolled(self::SEAT, self::SEAT_SCOPES, 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::OTHER_SEAT, self::SEAT_SCOPES, 'key:' . self::HUMAN));
        // The store, with rows of real work in it.
        file_put_contents($this->root . '/var/taller.json', '[{"herramienta":"taladro","estado":"prestada"}]');

        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->container = new DIContainer();
        $this->container->registerService(SessionStore::class, $this->sessions);
        $this->container->registerService(EventStoreInterface::class, $this->events);
        $this->kernel = Kernel::boot(['root' => $this->root, 'container' => $this->container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => [$plugin]]);
        $this->container->registerService(Kernel::class, $this->kernel);
        // The session that built it, and one of the same seat that did not.
        $this->landedBy('builder');
        $this->sessions->start('another', 'Lend the drill.', AutonomyMode::Auto);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testTheSeatIsRefusedInTheJudgesWordsAndHandedWhatItsVerbAnswered(): void
    {
        $house = $this->digest();
        $door = $this->door('builder', self::SEAT);

        try {
            $door->callTool('taller_lista', []);
            self::fail('a verb nobody admitted is refused');
        } catch (\Exception $handed) {
            self::assertNotInstanceOf(ToolCallRefused::class, $handed, 'the leg goes on');
            self::assertStringStartsWith('«taller:lista» is a verb of the capability «Taller», built in this house, and no person has admitted it for this seat', $handed->getMessage(), 'the refusal of decisions/0590, first and word for word');
            self::assertStringContainsString('And «Taller» is in works: a seat holds its building permit', $handed->getMessage());
            self::assertSame(1, preg_match('~\n<rehearsal>\n(.*)\n</rehearsal>$~s', $handed->getMessage(), $found), $handed->getMessage());
            $rehearsal = json_decode($found[1], true);
            self::assertTrue($rehearsal['ran_in_trial']);
            self::assertFalse($rehearsal['applied']);
            self::assertSame(['herramientas' => []], $rehearsal['output'], 'what its own code answered');
        }
        $refusals = $this->ofType('builder', 'session.tool_called');
        $refusal = end($refusals);
        self::assertInstanceOf(Event::class, $refusal);
        self::assertFalse($refusal->payload['ok']);
        self::assertStringNotContainsString('rehearsal', (string) $refusal->payload['result'], 'what the ledger keeps is the refusal alone');
        self::assertStringStartsWith((string) $refusal->payload['result'], $handed->getMessage(), 'and the model was handed that same sentence, then the rehearsal');
        $facts = $this->ofType('builder', OwnVerbRehearsal::EVENT);
        self::assertCount(1, $facts);
        self::assertSame(['refusal' => $refusal->seq, 'capability' => 'Taller', 'operation' => 'taller:lista', 'by' => 'house', 'ended' => 'answered', 'bounds' => TrialWorkspace::BOUNDS], $facts[0]->payload, 'that it happened, pointing at the refusal — and nothing of what was answered');
        self::assertSame($house, $this->digest(), 'the house is byte for byte what it was');
        self::assertSame([], HouseExecutedWork::calls($this->sessions->stream('builder')));
        self::assertSame([], TrialWorkspace::ids($this->root));
    }

    /** X7, in a house: the store holds rows of real work, and a rehearsal of a verb that reads it answers none. */
    public function testARehearsalAnswersWithNothingOfTheHousesOwnStore(): void
    {
        $said = $this->rehearsed('builder', self::SEAT, 'taller_lee', []);

        self::assertNotNull($said);
        self::assertStringContainsString('"filas":[]', $said);
        self::assertStringNotContainsString('taladro', $said);
        self::assertStringContainsString('taladro', (string) file_get_contents($this->root . '/var/taller.json'), 'the control: the rows are in the house');
    }

    public function testItIsCalledWithWhatTheModelSentAndWhatItThrowsIsSaid(): void
    {
        $given = $this->rehearsed('builder', self::SEAT, 'taller_alta', ['nombre' => 'sierra', 'cantidad' => 2, 'tipo' => 'manual', 'activa' => false]);
        $thrown = $this->rehearsed('builder', self::SEAT, 'taller_rota', []);

        self::assertStringContainsString('"given":{"nombre":"sierra","cantidad":2,"tipo":"manual","activa":false}', (string) $given);
        self::assertStringContainsString('Error: Call to undefined method MilpaTest\\\\Exercised\\\\Almacen::guardar()', (string) $thrown);
        self::assertSame(['answered', 'did_not_succeed'], array_map(static fn (Event $event): string => $event->payload['ended'], $this->ofType('builder', OwnVerbRehearsal::EVENT)));
    }

    /** X5, in a house: the same seat in another session; another seat; and the builder after that file changed. */
    public function testWhoDidNotBuildItIsStoppedAsToday(): void
    {
        foreach ([['another', self::SEAT], ['another', self::OTHER_SEAT]] as [$session, $seat]) {
            try {
                $this->door($session, $seat)->callTool('taller_lista', []);
                self::fail('refused');
            } catch (\Exception $refused) {
                self::assertInstanceOf(ToolCallRefused::class, $refused, "{$session} as {$seat}: the leg ends at the frontier");
                self::assertStringNotContainsString('rehearsal', $refused->getMessage());
            }
        }
        self::assertNotNull($this->rehearsed('builder', self::SEAT, 'taller_lista', []), 'the control: the builder may');

        file_put_contents($this->root . '/' . self::FILE, "\n// landed by someone else\n", \FILE_APPEND);

        self::assertNull($this->rehearsed('builder', self::SEAT, 'taller_lista', []));
        self::assertSame([], $this->ofType('another', OwnVerbRehearsal::EVENT));
    }

    public function testAHouseThatSwitchedTrialsOffRehearsesNothing(): void
    {
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $this->sessions);
        $container->registerService(EventStoreInterface::class, $this->events);
        $kernel = Kernel::boot(['root' => $this->root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => $this->kernel->plugins() === [] ? [] : [\get_class($this->kernel->plugins()[0])], 'config' => ['agent' => ['trialWorkspace' => false]]]);
        $container->registerService(Kernel::class, $kernel);

        self::assertNull($this->closure('builder', $container)('taller_lista', [], 'key:' . self::SEAT));
        self::assertDirectoryDoesNotExist($this->root . '/var/exercises');
    }

    public function testWithoutASessionOrAHouseThereIsNothingToRehearseWith(): void
    {
        $rehearses = new \ReflectionMethod(AgentOperations::class, 'rehearsalOfItsOwnVerbs');
        $none = new AgentOperations($this->container);
        self::assertNull($rehearses->invoke($none), 'no session');
        $homeless = new AgentOperations(new DIContainer());
        (new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos'))->setValue($homeless, 'builder');
        self::assertNull($rehearses->invoke($homeless), 'no house');
    }

    /** What the house says after the refusal, asked the way the leg's door asks it — or null. */
    private function rehearsed(string $session, string $seat, string $tool, array $arguments): ?string
    {
        // The gate records the refusal before the door hears of it.
        $this->sessions->recordToolCall($session, $tool, $arguments, 'refused', false);

        return $this->closure($session, $this->container)($tool, $arguments, 'key:' . $seat);
    }

    /** @return \Closure(string, array<string, mixed>, ?string): ?string */
    private function closure(string $session, DIContainer $container): \Closure
    {
        $operations = new AgentOperations($container);
        (new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos'))->setValue($operations, $session);
        (new \ReflectionMethod(AgentOperations::class, 'sessions'))->invoke($operations);
        $closure = (new \ReflectionMethod(AgentOperations::class, 'rehearsalOfItsOwnVerbs'))->invoke($operations);
        self::assertInstanceOf(\Closure::class, $closure);

        return $closure;
    }

    /**
     * The door of a leg of that session, run by that seat: the judge of decisions/0590 on the gate, the frontier that
     * decides whether a person can lift a refusal, and the rehearsal — each the house's own.
     */
    private function door(string $session, string $seat): ConsentBridge
    {
        $built = fn (): BuiltCapabilities => BuiltCapabilities::of($this->kernel);
        $registry = new ToolRegistry(new NullLogger());
        foreach (['taller_lista', 'taller_lee'] as $tool) {
            $registry->register($tool, $tool, ['type' => 'object'], static fn (array $args): array => ['reached_the_house' => true]);
        }
        $registry->getPolicyGate()->setCallPolicy(new PluginAuthoringPolicy($this->root, capabilities: $built));
        $sessions = $this->sessions;
        $recorder = new class ($sessions, $session) implements ToolCallRecorder {
            public function __construct(private SessionStore $sessions, private string $session)
            {
            }

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->sessions->recordToolCall($this->session, $tool, $arguments, $result, $ok);
            }
        };

        return new ConsentBridge(
            $registry,
            recorder: $recorder,
            authority: new ToolContext('key:' . $seat, 'cli', self::SEAT_SCOPES),
            // What a person could admit for that call: asked of the same judge the frontier asks.
            waitsOnAPerson: fn (string $tool, array $arguments): ?string => CapabilityAdmissions::forRoot($this->root, $built())->missing('key:' . $seat, $tool)?->permission(),
            rehearses: $this->closure($session, $this->container),
        );
    }

    /** The session that built the capability: its trial promoted, what it declares said, and what the promotion left of the file observed. */
    private function landedBy(string $session): void
    {
        $this->sessions->start($session, 'Build a plugin named Taller to keep the tools of a workshop.', AutonomyMode::Auto);
        $this->sessions->recordToolCall($session, 'implement', ['plugin' => 'Taller', 'class' => 'Taller'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'changed' => [self::FILE => 'modified'], 'output' => ['ok' => true],
        ]), mutating: true);
        $left = hash('sha256', (string) json_encode(['applied', self::FILE, hash_file('sha256', $this->root . '/' . self::FILE)]));
        $observed = $this->sessions->recordEffectObservation($session, 'sandbox_promote', ['workspace' => 'w1'], new EffectObservation('app-runtime/file-effects/v1', true, [$left]));
        $this->sessions->recordToolCall($session, 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode([
            'ok' => true,
            'promoted' => [self::FILE],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w1', 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => 'w1'], 'paths' => [self::FILE]],
            'capabilities' => [['predicate' => 'declared', 'subject' => 'Taller', 'environment' => ['kind' => 'house'], 'operations' => array_map(
                static fn (string $name): array => ['name' => $name, 'file' => self::FILE, 'mutating' => false, 'effects' => true, 'scoped' => true],
                [...ExercisedTaller::RUNS, 'taller:rota', 'taller:lee'],
            )]],
        ]), mutating: true, effectObservationSeq: $observed);
    }

    /** @return list<Event> */
    private function ofType(string $session, string $type): array
    {
        return array_values(array_filter($this->sessions->stream($session), static fn (Event $e): bool => $e->type === $type));
    }

    private function digest(): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $entries[] = substr($entry->getPathname(), \strlen($this->root)) . ($entry->isDir() ? '/' : ':' . hash_file('sha256', $entry->getPathname()));
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }
}
