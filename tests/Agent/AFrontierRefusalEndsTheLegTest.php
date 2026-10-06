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

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\RunEnd;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\ResidentSeat;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\Gate\ToolCallRecorder;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A refusal only a person can lift ends the leg where it happened (greenhouse evidence/1113, debt 2 of
 * decisions/0575 — the first Continuation).
 *
 * Measured on the BV-4 run with the real resident (evidence/1109 §3, call 5): `make` was refused for
 * `plugins.Blog:write`, a scope the seat's frontier offers to whoever enrolled it. The refusal went back to the model
 * as any error does; the house recorded a stall, cut the catalogue to 44 tools, asked the model once more — 21,557
 * tokens, 28 s — and the model could only say that it waits. The leg then ended on the stall receipt the house had
 * computed BEFORE that call. Nothing the model can do moves a grant: the house ends the leg itself.
 *
 * @guards a refusal the frontier would offer to a person ends the leg at the gate, with the policy's sentence and
 *         recorded once; a refusal the frontier does not offer (an invented name, a session nobody enrolled, another
 *         cause) goes back to the model as before; a door with no frontier is unchanged; the leg's answer says who
 *         it waits for, and no stall is recorded for a wait
 *
 * @refuses asking the model what only a person decides; calling a wait a stall
 *
 * @subject-in milpa/app-runtime
 */
final class AFrontierRefusalEndsTheLegTest extends TestCase
{
    private const HUMAN = 'C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';
    private const SESSION = 'bv';
    private const GOAL = 'Build the blog this house was founded for: a plugin named Blog that serves GET /blog.';
    private const BLOG = ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'];

    private string $root;
    private InMemoryEventStore $events;
    private SessionStore $sessions;
    /** @var list<array{string, array<string, mixed>, string, bool}> */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-frontier-ends-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/identity', 0o777, true);
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))
            ->record(new IdentityEnrolled(self::SEAT, ResidentSeat::SCOPES, 'key:' . self::HUMAN));
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testARefusalThePanelOffersEndsTheLegAtTheGate(): void
    {
        $door = $this->door($this->operations());

        try {
            $door->callTool('make', self::BLOG);
            self::fail('the seat lacks plugins.Blog:write: the call must be refused');
        } catch (ToolCallRefused $refused) {
            self::assertFalse($refused->optionRemoved, 'the option is still there: the leg ends, the model does not look for a way around');
            self::assertStringStartsWith("Missing required permission 'plugins.Blog:write' for plugin 'Blog'.", $refused->getMessage());
            self::assertStringContainsString('Whoever enrolled this seat can grant «plugins.Blog:write» in the panel (Agent → Decisions)', $refused->getMessage());
        }
        self::assertCount(1, $this->recorded, 'recorded once, as the refusal it is');
        self::assertSame(['make', self::BLOG, false], [$this->recorded[0][0], $this->recorded[0][1], $this->recorded[0][3]]);
        self::assertSame($refused->getMessage(), $this->recorded[0][2], 'the frontier card reads the same sentence the leg ended on');
    }

    public function testTheRefusalNoLongerTellsTheModelToEndTheLegItself(): void
    {
        try {
            $this->door($this->operations())->callTool('make', self::BLOG);
            self::fail('refused');
        } catch (ToolCallRefused $refused) {
            self::assertStringNotContainsString('End this leg with a short answer', $refused->getMessage());
            self::assertStringContainsString('not a gap in the house: do not declare HOUSE_DEBT for it', $refused->getMessage());
            self::assertStringContainsString('The leg ends here and waits for that grant; after it, `continue` runs this same call again.', $refused->getMessage());
        }
    }

    public function testAnInventedNameGoesBackToTheModelAsBefore(): void
    {
        $door = $this->door($this->operations());

        try {
            $door->callTool('make', ['what' => 'plugin', 'plugin' => 'BlogPlugin', 'name' => 'BlogPlugin']);
            self::fail('refused');
        } catch (\Exception $refused) {
            self::assertNotInstanceOf(ToolCallRefused::class, $refused, 'the model can still correct a name nobody asked for');
            self::assertStringContainsString("Missing required permission 'plugins.BlogPlugin:write'", $refused->getMessage());
        }
    }

    public function testASessionNobodyEnrolledHasNoFrontierToWaitAt(): void
    {
        $this->sessions->start('terminal', self::GOAL, by: new Principal('cli:rod', false));
        $door = $this->door($this->operations('terminal'), 'terminal');

        try {
            $door->callTool('make', self::BLOG);
            self::fail('refused');
        } catch (\Exception $refused) {
            self::assertNotInstanceOf(ToolCallRefused::class, $refused);
        }
    }

    public function testADoorWithNoSessionIsUnchanged(): void
    {
        $door = $this->door($this->operations(null));

        try {
            $door->callTool('make', self::BLOG);
            self::fail('refused');
        } catch (\Exception $refused) {
            self::assertNotInstanceOf(ToolCallRefused::class, $refused);
        }
    }

    public function testARefusalForAnotherCauseDoesNotEndTheLegEvenIfAScopeIsAlsoMissing(): void
    {
        $waits = static fn (string $tool, array $arguments): ?string => 'plugins.Blog:write';
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('make', 'scaffolds', ['type' => 'object'], static fn (array $args): array => throw new \RuntimeException('The scaffold could not be written: disk full.'));
        $door = new ConsentBridge($registry, recorder: $this->recorder(), authority: $this->seat(), waitsOnAPerson: $waits);

        try {
            $door->callTool('make', self::BLOG);
            self::fail('the tool failed');
        } catch (\Exception $failed) {
            self::assertNotInstanceOf(ToolCallRefused::class, $failed, 'a failure that names no missing scope is the model\'s to read');
            self::assertStringContainsString('disk full', $failed->getMessage());
        }
    }

    public function testAFrontierThatNamesNoScopeOffersNothing(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('make', 'scaffolds', ['type' => 'object'], static fn (array $args): array => throw new \RuntimeException("Missing required permission '' for plugin 'Blog'."));
        $door = new ConsentBridge($registry, authority: $this->seat(), waitsOnAPerson: static fn (): ?string => '');

        try {
            $door->callTool('make', self::BLOG);
            self::fail('the tool failed');
        } catch (\Exception $failed) {
            self::assertNotInstanceOf(ToolCallRefused::class, $failed, 'nothing to grant, nobody to wait for');
        }
    }

    public function testWithoutASessionOrAHouseThereIsNoFrontierToAsk(): void
    {
        $frontier = new \ReflectionMethod(AgentOperations::class, 'frontierOfTheSeat');

        self::assertInstanceOf(\Closure::class, $frontier->invoke($this->operations()));
        self::assertNull($frontier->invoke($this->operations(null)), 'no session');
        self::assertNull($frontier->invoke($this->operations('')), 'a session with no name');
        $homeless = new FrontierFixtureOperations(new DIContainer());
        (new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos'))->setValue($homeless, self::SESSION);
        self::assertNull($frontier->invoke($homeless), 'no house');
    }

    public function testAFrontierThatCannotBeAskedIsNoFrontier(): void
    {
        $registry = $this->registry(self::SESSION);
        $door = new ConsentBridge($registry, recorder: $this->recorder(), authority: $this->seat(), waitsOnAPerson: static fn (): ?string => throw new \RuntimeException('enrollments unreadable'));

        try {
            $door->callTool('make', self::BLOG);
            self::fail('refused');
        } catch (\Exception $refused) {
            self::assertNotInstanceOf(ToolCallRefused::class, $refused);
            self::assertStringContainsString("Missing required permission 'plugins.Blog:write'", $refused->getMessage());
        }
    }

    public function testARefusalOfTheSessionsOwnGateKeepsItsShape(): void
    {
        $asked = 0;
        $gate = new class () implements ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return 'The session asks first.';
            }
        };
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('make', 'scaffolds', ['type' => 'object'], static fn (array $args): array => ['ran' => true]);
        $door = new ConsentBridge($registry, gate: $gate, authority: $this->seat(), waitsOnAPerson: static function () use (&$asked): ?string {
            ++$asked;

            return 'plugins.Blog:write';
        });

        try {
            $door->callTool('make', self::BLOG);
            self::fail('refused');
        } catch (ToolCallRefused $refused) {
            self::assertSame('The session asks first.', $refused->getMessage());
        }
        self::assertSame(0, $asked, 'a refusal that already ends the leg is not judged again');
    }

    public function testACallThatRunsNeverAsksTheFrontier(): void
    {
        $asked = 0;
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('read', 'reads', ['type' => 'object'], static fn (array $args): array => ['ok' => true]);
        $door = new ConsentBridge($registry, waitsOnAPerson: static function () use (&$asked): ?string {
            ++$asked;

            return 'plugins.Blog:write';
        });

        self::assertSame(['ok' => true], $door->callTool('read', []));
        self::assertSame(0, $asked);
    }

    /**
     * The whole leg: the model asks for `make` once, the house refuses at the frontier, and the model is not asked
     * again. The answer is the house's, and it says who the leg waits for.
     */
    public function testTheLegEndsAfterOneModelCallAndSaysWhoItWaitsFor(): void
    {
        $operations = $this->operations();
        $llm = new class () extends LlmService {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function generateResponse(string $prompt, array $tools = [], array $messages = [], int $maxTokens = 4096): array
            {
                ++$this->calls;

                return $this->calls === 1
                    ? ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'make', 'arguments' => json_encode(['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'])]]]]
                    : ['role' => 'assistant', 'content' => 'The leg is waiting for a person.'];
            }
        };
        $operations->llm = $llm;
        $operations->door = $this->door($operations);

        $result = self::leg($operations);

        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'the run failed'));
        self::assertSame(1, $llm->calls, 'the model is not asked what only a person decides');
        self::assertSame(RunEnd::ToolRefused->value, $result['termination']['reason'] ?? null);
        self::assertSame('The leg is waiting for a person to grant «plugins.Blog:write».', $result['answer']);
        self::assertSame(['plugins.Blog:write'], $result['awaiting_grant'] ?? null);
        self::assertStringContainsString('grant «plugins.Blog:write» in the panel (Agent → Decisions)', (string) ($result['hint'] ?? ''));
        $types = array_map(static fn (object $event): string => $event->type, $this->sessions->stream(self::SESSION));
        self::assertNotContains('session.progress_stalled', $types, 'a wait is not a stall');
        self::assertSame([], array_values(array_filter(
            $this->sessions->load(self::SESSION)?->turns ?? [],
            static fn (array $turn): bool => $turn['role'] === 'assistant',
        )), 'the house\'s sentence is not kept as something the model said');
    }

    public function testALegRefusedForAnotherReasonSaysNothingAboutAGrant(): void
    {
        $operations = $this->operations();
        $operations->llm = new class () extends LlmService {
            public function __construct()
            {
            }

            public function generateResponse(string $prompt, array $tools = [], array $messages = [], int $maxTokens = 4096): array
            {
                return ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{}']]]];
            }
        };
        $refusing = $this->createMock(GatedToolCalls::class);
        $refusing->method('getToolSummaries')->willReturn([['name' => 'read', 'description' => 'reads', 'inputSchema' => ['type' => 'object']]]);
        $refusing->method('callTool')->willThrowException(new ToolCallRefused('The session asks first.'));
        $operations->door = $refusing;

        $result = self::leg($operations);

        self::assertSame(RunEnd::ToolRefused->value, $result['termination']['reason'] ?? null);
        self::assertArrayNotHasKey('awaiting_grant', $result);
        self::assertSame('The session asks first.', $result['answer']);
    }

    /** The agent operations of a house whose root holds the enrollment, answering in `$session`. */
    private function operations(?string $session = self::SESSION): FrontierFixtureOperations
    {
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $this->sessions);
        $container->registerService(EventStoreInterface::class, $this->events);
        $container->registerService(Kernel::class, Kernel::boot(['root' => $this->root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]));
        $operations = new FrontierFixtureOperations($container);
        (new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos'))->setValue($operations, $session);
        (new \ReflectionProperty(AgentOperations::class, 'sessionEvents'))->setValue($operations, $this->events);

        return $operations;
    }

    /** The governed door those operations build for a leg, over a registry whose policy answers in `$session`. */
    private function door(AgentOperations $operations, string $session = self::SESSION): ConsentBridge
    {
        $build = new \ReflectionMethod(AgentOperations::class, 'governedExecutor');
        (new \ReflectionProperty(AgentOperations::class, 'toolAuthority'))->setValue($operations, $this->seat());

        return $build->invoke($operations, $this->registry($session), null, $this->recorder(), null);
    }

    private function registry(string $session): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('make', 'scaffolds', ['type' => 'object'], static fn (array $args): array => ['ran' => true]);
        $registry->getPolicyGate()->setCallPolicy((new PluginAuthoringPolicy($this->root))->withSeatSession($this->sessions, $session));

        return new TrialAwareRegistry($registry, new TrialRouter($this->root, new TrialRunner(), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php'), [], $this->sessions, $session);
    }

    private function recorder(): ToolCallRecorder
    {
        return new class ($this->recorded, $this->sessions) implements ToolCallRecorder {
            /** @param list<array{string, array<string, mixed>, string, bool}> $recorded */
            public function __construct(private array &$recorded, private SessionStore $sessions)
            {
            }

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->recorded[] = [$tool, $arguments, $result, $ok];
                $this->sessions->recordToolCall('bv', $tool, $arguments, $result, $ok);
            }
        };
    }

    private function seat(): ToolContext
    {
        return new ToolContext('key:' . self::SEAT, 'cli', ResidentSeat::SCOPES);
    }

    /** @return array<string, mixed> */
    private static function leg(AgentOperations $operations): array
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    return ($operation->handler)(['prompt' => 'continue', 'session' => self::SESSION]);
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('the agent operation was not offered');
    }
}

/** The leg of a fixture house: the model and the door are the test's; the loop, the probe and the ending are the house's. */
final class FrontierFixtureOperations extends AgentOperations
{
    public ?LlmService $llm = null;
    public ?GatedToolCalls $door = null;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        return new AgentOrchestrator($this->llm ?? $modeloRemoto, $this->door ?? $cliente, progressProbe: $sonda);
    }
}
