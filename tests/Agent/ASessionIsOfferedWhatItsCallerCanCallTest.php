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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Identity\ResidentSeat;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A session is offered what whoever runs it can call (greenhouse decisions/0601, rule A).
 *
 * Every call to the model carries the whole contract of every tool it is offered. A house offered a seat's session
 * the contracts of operations that seat's scopes can never call — ten of eighty-four in a house of the published
 * train, a little over a thousand tokens on every call (evidence/1155) — and the door refused them when they were
 * called. Now the offer is filtered by THE SAME QUESTION THE DOOR ASKS FIRST: does this caller hold one of the scopes
 * this tool declares? What it does not, is not offered with a contract; it is NAMED, in one line of the prompt, as
 * something a person runs.
 *
 * Nothing is opened and nothing is closed: what stops being offered was already refused. The door still judges
 * every call, one that names a tool the offer left out included.
 *
 * @guards the offer leaving out every tool none of whose declared scopes the caller holds; keeping a tool that
 *         declares none, and everything for a caller that holds the wildcard or presented nothing; the names left
 *         out, in the catalogue's order; the prompt of a seat's leg naming them once
 *
 * @refuses offering by a different question than the door's; opening a call the door refuses; a line when nothing
 *          is left out; narrowing the offer of a caller that holds everything
 *
 * @subject-in milpa/app-runtime
 */
final class ASessionIsOfferedWhatItsCallerCanCallTest extends TestCase
{
    private const HUMAN = 'BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666';

    private const SEAT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';

    private ToolRegistry $registry;

    private int $executions = 0;

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry(new NullLogger());
        $tools = ['work_read' => ['work:read'], 'work_write' => ['work:write', 'work:admin'], 'config_read' => ['config:read'], 'identity_read' => ['identity:admin'], 'open_read' => []];
        foreach ($tools as $name => $scopes) {
            $this->registry->register($name, "The tool {$name}", ['type' => 'object', 'properties' => []], function (): array {
                ++$this->executions;

                return ['ok' => true];
            }, new ToolOptions(scopes: $scopes));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testTheOfferIsWhatTheCallersScopesCanCall(): void
    {
        $bridge = $this->bridge(['work:read', 'work:write']);

        self::assertSame(['work_read', 'work_write', 'open_read'], array_column($bridge->getToolSummaries(), 'name'));
        self::assertSame(['config_read', 'identity_read'], $bridge->notOfferedToThisCaller(), 'named, in the catalogue\'s order');
    }

    public function testAnyOneOfTheScopesAToolDeclaresIsEnough(): void
    {
        self::assertContains('work_write', array_column($this->bridge(['work:admin'])->getToolSummaries(), 'name'));
        self::assertNotContains('work_write', array_column($this->bridge(['work:read'])->getToolSummaries(), 'name'));
    }

    public function testAToolThatDeclaresNoScopeIsOfferedToACallerThatHoldsNone(): void
    {
        $bridge = $this->bridge([]);

        self::assertSame(['open_read'], array_column($bridge->getToolSummaries(), 'name'), 'explicitly no scope is no scope: only what asks for none');
        self::assertCount(4, $bridge->notOfferedToThisCaller());
    }

    public function testACallerThatHoldsEverythingOrPresentedNothingKeepsTheWholeOffer(): void
    {
        foreach ([$this->bridge(['*']), new ConsentBridge($this->registry)] as $bridge) {
            self::assertCount(5, $bridge->getToolSummaries());
            self::assertSame([], $bridge->notOfferedToThisCaller());
        }
    }

    public function testTheOfferAndTheDoorAskTheSameQuestion(): void
    {
        $bridge = $this->bridge(['work:read', 'work:write']);
        $offered = array_column($bridge->getToolSummaries(), 'name');

        foreach (['work_read', 'work_write', 'config_read', 'identity_read', 'open_read'] as $tool) {
            $refusedForItsScope = false;
            try {
                $bridge->callTool($tool, []);
            } catch (\Throwable $refusal) {
                $refusedForItsScope = str_contains($refusal->getMessage(), 'Missing required scope');
            }
            self::assertSame(!\in_array($tool, $offered, true), $refusedForItsScope, "{$tool}: offered exactly when the door does not refuse it for its scope");
        }
        self::assertSame(3, $this->executions, 'what was not offered was called and did not run: the door is the one that refuses');
    }

    public function testTheNamesLeftOutAreSaidInOneLineAndNothingIsSaidWhenNoneIs(): void
    {
        self::assertSame('', ConsentBridge::namesNotOffered([]));
        $line = ConsentBridge::namesNotOffered(['config_set', 'identity_enroll']);

        self::assertStringContainsString('config_set, identity_enroll', $line);
        self::assertStringContainsString('A person runs these', $line);
        self::assertStringNotContainsString("\n", $line, 'one line');
    }

    public function testASeatsLegIsToldOnceWhatItIsNotOfferedAndTheLegOfWhoHoldsEverythingIsToldNothing(): void
    {
        [$operations, $loop] = $this->house();

        self::leg($operations, new ToolContext(principal: 'key:' . self::SEAT, channel: 'cli', scopes: [...ResidentSeat::SCOPES]));
        $offered = array_column($loop->offered, 'name');
        self::assertNotContains('house_secret', $offered, 'the seat holds no scope that tool declares');
        self::assertContains('house_list', $offered);
        self::assertContains('plan', $offered, 'what exists only in a session asks for the scope a seat runs with');
        self::assertSame(1, substr_count((string) $loop->system, ConsentBridge::NOT_OFFERED), 'said once');
        self::assertSame(['house_secret'], $this->namedIn((string) $loop->system));
        self::assertStringContainsString("\n\n" . ConsentBridge::namesNotOffered(['house_secret']), (string) $loop->system, 'the whole line, set apart');

        [$operations, $loop] = $this->house();
        self::leg($operations, new ToolContext(principal: 'local-shell', channel: 'cli', scopes: ['*']));
        self::assertContains('house_secret', array_column($loop->offered, 'name'));
        self::assertContains('house_list', array_column($loop->offered, 'name'));
        self::assertStringNotContainsString(ConsentBridge::NOT_OFFERED, (string) $loop->system);
    }

    /** @param list<string> $scopes */
    private function bridge(array $scopes): ConsentBridge
    {
        return new ConsentBridge($this->registry, authority: new ToolContext(principal: 'fixture', scopes: $scopes));
    }

    /** @return list<string> the names the prompt's line lists */
    private function namedIn(string $system): array
    {
        preg_match('/' . preg_quote(ConsentBridge::NOT_OFFERED, '/') . '([^\n]*?)\. /', $system, $found);

        return array_map('trim', explode(',', $found[1] ?? ''));
    }

    /** @return array{0: OfferFixtureOperations, 1: OfferRecorder} a fixture house with one session of a seat */
    private function house(): array
    {
        $root = sys_get_temp_dir() . '/milpa-offer-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        $this->roots[] = $root;
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'List the plugins.', AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, true));
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(EventStoreInterface::class, $events);
        // Two tools of the house, already in its registry when the leg starts: one a seat's scopes reach, one they do not.
        $registry = new ToolRegistry(new NullLogger());
        foreach (['house_list' => ['plugins:read'], 'house_secret' => ['config:write']] as $name => $scopes) {
            $registry->register($name, "The tool {$name}", ['type' => 'object', 'properties' => []], static fn (): array => ['ok' => true], new ToolOptions(scopes: $scopes));
        }
        $container->registerService(Kernel::class, Kernel::boot(['root' => $root, 'container' => $container, 'toolRegistry' => $registry, 'plugins' => []]));
        $operations = new OfferFixtureOperations($container);
        (new \ReflectionProperty(AgentOperations::class, 'trialRouterMemo'))->setValue($operations, null);
        $loop = new OfferRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class));
        $operations->loop = $loop;

        return [$operations, $loop];
    }

    private static function leg(AgentOperations $operations, ToolContext $authority): void
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    ($operation->handler)(['prompt' => 'continue', 'session' => 'bv'], new InvocationContext($authority->principal, true), $authority);

                    return;
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('the agent operation was not offered');
    }
}

/** A leg whose orchestrator is the test's, and that hands it the door the leg built. */
final class OfferFixtureOperations extends AgentOperations
{
    public ?OfferRecorder $loop = null;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->loop !== null);
        $this->loop->door = $cliente;

        return $this->loop;
    }
}

/** An orchestrator that only remembers what it was handed: the prompt, and what its door offers. */
final class OfferRecorder extends AgentOrchestrator
{
    public ?GatedToolCalls $door = null;

    public ?string $system = null;

    /** @var list<array<string, mixed>> */
    public array $offered = [];

    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        $this->system = $systemPrompt;
        $this->offered = $this->door?->getToolSummaries() ?? [];

        return 'Listed.';
    }
}
