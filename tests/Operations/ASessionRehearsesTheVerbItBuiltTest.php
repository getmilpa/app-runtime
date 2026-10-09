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
use Milpa\Agent\Principal;
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
        $this->sessions->start('another', 'Lend the drill.', AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, true));
        $this->sessions->start('theirs', 'Lend the drill.', AutonomyMode::Auto, by: new Principal('key:' . self::OTHER_SEAT, true));
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
            // 0590's sentence tells the seat that the leg ends there — and it is handed whole. The house says after it,
            // first thing, that this leg does not.
            $ends = strpos($handed->getMessage(), 'The leg ends here and waits for that admission');
            self::assertNotFalse($ends, 'the refusal as the house says it to a seat, with who admits it');
            self::assertGreaterThan($ends, (int) strpos($handed->getMessage(), "\n\nThis leg does NOT end here, whatever the refusal above says"));
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

        // It points at the refusal of THAT call: a later refusal of another tool is not the one it accompanied.
        $this->sessions->recordToolCall('builder', 'taller_lista', [], 'refused', false);
        $refusals = $this->ofType('builder', 'session.tool_called');
        $own = end($refusals);
        self::assertInstanceOf(Event::class, $own);
        $this->sessions->recordToolCall('builder', 'make', ['what' => 'plugin', 'plugin' => 'Blog'], "Missing required permission 'plugins.Blog:write' for plugin 'Blog'.", false);
        self::assertNotNull($this->closure('builder', $this->container)('taller_lista', [], 'key:' . self::SEAT));
        $facts = $this->ofType('builder', OwnVerbRehearsal::EVENT);
        self::assertSame($own->seq, end($facts)->payload['refusal']);
    }

    /** X5, in a house: the same seat in another session; another seat; and the builder after that file changed. */
    public function testWhoDidNotBuildItIsStoppedAsToday(): void
    {
        // …and the fourth: the builder's own session, continued by the other seat, which holds the same permit.
        foreach ([['another', self::SEAT], ['theirs', self::OTHER_SEAT], ['builder', self::OTHER_SEAT]] as [$session, $seat]) {
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
        self::assertSame([], [...$this->ofType('another', OwnVerbRehearsal::EVENT), ...$this->ofType('theirs', OwnVerbRehearsal::EVENT)]);
        self::assertCount(1, $this->ofType('builder', OwnVerbRehearsal::EVENT), 'one rehearsal in the builder\'s session: the control\'s, and none for the other seat');
    }

    /**
     * PATH B, THROUGH THE DOOR THE LEG BUILDS (decided by Rod, 2026-10-09). The refusal is the house's own sentence,
     * the fact is the one the door leaves, and what a person admitted is asked of the judge the gate asks. The
     * builder's call of a verb that writes, answered in a rehearsal, does not hold its closure. The same call by who
     * did not build — another seat continuing the builder's session, another session of the same seat — was not
     * rehearsed, left no fact, and holds the closure as it always did.
     */
    public function testTheBuildersRehearsedRefusalDoesNotHoldItsClosureAndWhoDidNotBuildStillWaits(): void
    {
        $admitted = HouseExecutedWork::admittedBy(CapabilityAdmissions::forRoot($this->root, BuiltCapabilities::of($this->kernel)));
        $call = function (string $session, string $seat): void {
            try {
                $this->door($session, $seat)->callTool('taller_alta', ['nombre' => 'sierra', 'cantidad' => 2, 'tipo' => 'manual', 'activa' => false]);
                self::fail('a verb nobody admitted is refused');
            } catch (\Exception) {
            }
        };

        $call('builder', self::SEAT);

        $work = HouseExecutedWork::of($this->sessions->stream('builder'), $admitted);
        self::assertSame([], $work['reasons'], 'the house answered it in a rehearsal: it is no longer a reason');
        self::assertSame(['calls' => 1, 'of_verbs_that_change_state' => 1, 'applied' => false], $work['rehearsed']);
        self::assertNull($work['work'], 'and it is no work');

        $call('builder', self::OTHER_SEAT);
        $call('another', self::SEAT);

        $builder = HouseExecutedWork::of($this->sessions->stream('builder'), $admitted);
        $refusals = $this->ofType('builder', 'session.tool_called');
        self::assertSame(['a call of «taller_alta» was refused for lack of an admission, and nobody has admitted it (seq ' . end($refusals)->seq . ')'], $builder['reasons'], 'the other seat\'s call was not rehearsed: it holds');
        self::assertSame(1, $builder['rehearsed']['calls'] ?? null);
        $another = HouseExecutedWork::of($this->sessions->stream('another'), $admitted);
        self::assertCount(1, $another['reasons'], 'nor was the other session\'s');
        self::assertNull($another['rehearsed']);
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

    /**
     * NO LEDGER TO WRITE THAT IT HAPPENED, NO REHEARSAL. The fact is the one trace a rehearsal leaves; a house that
     * cannot append it rehearses nothing — decided before anything runs, not after. Found by t-0104.
     */
    public function testAHouseThatCannotRecordThatItHappenedRehearsesNothing(): void
    {
        $operations = new AgentOperations($this->container);
        (new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos'))->setValue($operations, 'builder');
        (new \ReflectionMethod(AgentOperations::class, 'sessions'))->invoke($operations);
        $rehearses = (new \ReflectionMethod(AgentOperations::class, 'rehearsalOfItsOwnVerbs'))->invoke($operations);
        self::assertInstanceOf(\Closure::class, $rehearses);
        $this->sessions->recordToolCall('builder', 'taller_lista', [], 'refused', false);
        self::assertNotNull($rehearses('taller_lista', [], 'key:' . self::SEAT), 'the control: with its ledger it rehearses');
        $facts = \count($this->ofType('builder', OwnVerbRehearsal::EVENT));

        (new \ReflectionProperty(AgentOperations::class, 'sessionEvents'))->setValue($operations, null);

        self::assertNull($rehearses('taller_lista', [], 'key:' . self::SEAT));
        self::assertCount($facts, $this->ofType('builder', OwnVerbRehearsal::EVENT));
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
     * The door of a leg of that session, run by that seat — THE ONE THE LEG BUILDS (`governedExecutor`): its frontier,
     * its rehearsal. Over a registry whose gate holds the judge of decisions/0590, and a recorder that writes what
     * the gate saw into the session, as a leg's does.
     */
    private function door(string $session, string $seat): ConsentBridge
    {
        $built = fn (): BuiltCapabilities => BuiltCapabilities::of($this->kernel);
        $registry = new ToolRegistry(new NullLogger());
        foreach (['taller_lista', 'taller_lee', 'taller_alta'] as $tool) {
            $registry->register($tool, $tool, ['type' => 'object'], static fn (array $args): array => ['reached_the_house' => true]);
        }
        $registry->getPolicyGate()->setCallPolicy((new PluginAuthoringPolicy($this->root, capabilities: $built))->withSeatSession($this->sessions, $session));
        $sessions = $this->sessions;
        $recorder = new class ($sessions, $session) implements ToolCallRecorder {
            public function __construct(private SessionStore $sessions, private string $session)
            {
            }

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                // A leg's gate records a call with what its operation declares — whether it changes state. This house's
                // verbs all read, so one of them stands here for a verb that writes: `taller_alta`.
                $this->sessions->recordToolCall($this->session, $tool, $arguments, $result, $ok, mutating: $tool === 'taller_alta');
            }
        };
        $operations = new AgentOperations($this->container);
        (new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos'))->setValue($operations, $session);
        (new \ReflectionProperty(AgentOperations::class, 'sessionEvents'))->setValue($operations, $this->events);
        (new \ReflectionProperty(AgentOperations::class, 'toolAuthority'))->setValue($operations, new ToolContext('key:' . $seat, 'cli', self::SEAT_SCOPES));

        return (new \ReflectionMethod(AgentOperations::class, 'governedExecutor'))->invoke($operations, $registry, null, $recorder, null);
    }

    /** The session that built the capability: its trial promoted, what it declares said, and what the promotion left of the file observed. */
    private function landedBy(string $session): void
    {
        $this->sessions->start($session, 'Build a plugin named Taller to keep the tools of a workshop.', AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, true));
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
