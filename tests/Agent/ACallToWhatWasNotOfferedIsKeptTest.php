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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A call to something the session was not offered is kept in its log (greenhouse decisions/0601; decided by Rod on
 * 2026-10-08, on the question evidence/1163 §10 left him).
 *
 * A session is offered what whoever runs it can call. When its model names something else, the loop answers «not
 * offered in this step» by itself and never asks the door — so the session's log kept no trace that it had tried.
 * Measured: in a real run it could not be known whether a resident had called a verb it built. Now the attempt is a
 * refused call in the log: the name that was called, its arguments, and why it was not on the offer. THE OFFER DOES
 * NOT CHANGE AND NOTHING RUNS; the model reads the loop's answer, as before.
 *
 * These legs are the house's own — `agent` assembles the door, the gate and the prompt — in a house with the course's
 * capability built in it, with the real loop of milpa/ai-gateway and a scripted model. They need a gateway whose loop
 * can tell its caller (ai-gateway#131).
 *
 * @guards the attempt kept as one refused call with the name, the arguments and the reason: no scope of the caller,
 *         withdrawn from the session, or no such tool in this house; nothing run, nothing counted as a mutation;
 *         the offer of the next request the same; what the model reads unchanged; a secret of the house not kept
 *
 * @refuses keeping a call that was offered as not offered; keeping it twice; a record that depends on anything but
 *          who runs the leg and what the house offers them
 *
 * @subject-in milpa/app-runtime
 */
final class ACallToWhatWasNotOfferedIsKeptTest extends TestCase
{
    use BuiltHouse {
        tearDown as private removeTheHouses;
    }

    /** A key this house never enrolled: it holds a seat's scopes and nobody can admit anything for it. */
    private const NO_SEAT = 'EEEE5555FFFF6666AAAA7777BBBB8888CCCC9999';

    private const SECRET = 'sk-live-0f3c9a7e55d1b2a4';

    protected function setUp(): void
    {
        if (!method_exists(AgentOrchestrator::class, 'setUnofferedCall')) {
            self::markTestSkipped('the installed milpa/ai-gateway cannot tell its caller of a call it turned away (ai-gateway#131)');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTheHouses();
    }

    public function testTheAttemptIsKeptAsARefusedCallWithItsNameItsArgumentsAndTheReason(): void
    {
        [$operations, $sessions] = $this->house([self::call('herramientas_prestar', '{"id":1,"a":"Ana"}'), 'Done.'], self::NO_SEAT);

        self::leg($operations, self::NO_SEAT);

        $kept = self::callsOf($sessions, 'herramientas_prestar');
        self::assertCount(1, $kept, 'once');
        self::assertFalse($kept[0]['ok']);
        self::assertFalse($kept[0]['mutating'], 'nothing ran: it is no mutation');
        self::assertSame(['id' => 1, 'a' => 'Ana'], $kept[0]['arguments']);
        $said = json_decode($kept[0]['result'], true);
        self::assertSame(['ok' => false, 'not_offered' => true, 'because' => 'scope'], \array_slice($said, 0, 3, true));
        self::assertStringContainsString('«herramientas_prestar»', $said['error']);
        self::assertStringContainsString('holds no scope it declares', $said['error']);
        self::assertStringContainsString('Nothing ran', $said['error']);
        self::assertSame([], $this->ran, 'and nothing ran');
    }

    public function testTheOfferOfTheNextRequestIsTheSameAndTheModelReadsWhatTheLoopAnswered(): void
    {
        [$operations] = $this->house([self::call('herramientas_prestar', '{}'), 'Done.'], self::NO_SEAT);

        self::leg($operations, self::NO_SEAT);

        self::assertCount(2, $operations->seen);
        self::assertSame($operations->seen[0]['tools'], $operations->seen[1]['tools'], 'keeping the attempt does not move the offer');
        self::assertNotContains('herramientas_prestar', $operations->seen[1]['tools']);
        $answered = $operations->seen[1]['messages'][\count($operations->seen[1]['messages']) - 1];
        self::assertSame('tool', $answered['role']);
        self::assertStringStartsWith("Tool 'herramientas_prestar' was not offered in this step.", $answered['content']);
    }

    /**
     * WHO RUNS THE LEG DECIDES WHAT IS KEPT. A seat is offered a built verb nobody admitted for it yet, so its call
     * reaches the door: what the log keeps is the door's refusal — the one a person admits from — and never «not
     * offered». Asked as anybody else, the same call would be kept as an attempt at something off the offer.
     */
    public function testASeatsCallToAVerbNobodyAdmittedReachesTheDoorAndIsKeptAsTheDoorsRefusal(): void
    {
        [$operations, $sessions] = $this->house([self::call('herramientas_prestar', '{"id":1}'), 'never asked']);

        self::leg($operations, self::SEAT);

        $kept = self::callsOf($sessions, 'herramientas_prestar');
        self::assertCount(1, $kept);
        self::assertFalse($kept[0]['ok']);
        self::assertStringContainsString('no person has admitted it for this seat', $kept[0]['result']);
        self::assertStringNotContainsString('not_offered', $kept[0]['result']);
    }

    public function testACallThatWasOfferedAndRanIsKeptOnceByTheDoor(): void
    {
        [$operations, $sessions, $root, $kernel] = $this->house([self::call('herramientas_listar', '{}'), 'Done.']);
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', 'herramientas:read');
        self::assertNotNull($group);
        self::assertTrue($this->ledger($root)->admit(self::SEAT, 'Prestamos', 'herramientas:read', $group['verbs'], 'key:' . self::HUMAN));

        self::leg($operations, self::SEAT);

        $kept = self::callsOf($sessions, 'herramientas_listar');
        self::assertCount(1, $kept);
        self::assertTrue($kept[0]['ok']);
        self::assertStringNotContainsString('not_offered', $kept[0]['result']);
        self::assertSame(['herramientas.listar'], $this->ran);
    }

    public function testAToolThisHouseDoesNotHaveIsKeptAsUnknown(): void
    {
        [$operations, $sessions] = $this->house([self::call('herramienta_inventada', '{"id":7}'), 'Done.']);

        self::leg($operations, self::SEAT);

        $kept = self::callsOf($sessions, 'herramienta_inventada');
        self::assertCount(1, $kept);
        $said = json_decode($kept[0]['result'], true);
        self::assertSame(['ok' => false, 'not_offered' => true, 'because' => 'unknown'], \array_slice($said, 0, 3, true));
        self::assertStringContainsString('this house has no tool by that name', $said['error']);
    }

    public function testAToolWithdrawnFromTheSessionIsKeptAsWithdrawn(): void
    {
        [$operations, $sessions] = $this->house([self::call('herramientas_listar', '{}'), 'Done.']);

        self::leg($operations, self::SEAT, ['deny' => 'herramientas_listar']);

        $kept = self::callsOf($sessions, 'herramientas_listar');
        self::assertCount(1, $kept);
        $said = json_decode($kept[0]['result'], true);
        self::assertSame(['ok' => false, 'not_offered' => true, 'because' => 'withdrawn'], \array_slice($said, 0, 3, true));
        self::assertStringContainsString('withdrawn from this session', $said['error']);
        self::assertSame([], $this->ran);
    }

    public function testEachAttemptOfAStepIsKeptInOrder(): void
    {
        [$operations, $sessions] = $this->house([self::call('herramientas_prestar', '{"n":1}', 'herramienta_inventada', '{"n":2}'), 'Done.'], self::NO_SEAT);

        self::leg($operations, self::NO_SEAT);

        $kept = array_values(array_filter(self::calls($sessions), static fn (array $c): bool => str_contains($c['result'], 'not_offered')));
        self::assertSame(['herramientas_prestar', 'herramienta_inventada'], array_column($kept, 'tool'));
        self::assertSame([['n' => 1], ['n' => 2]], array_column($kept, 'arguments'));
        self::assertSame(['scope', 'unknown'], array_map(static fn (array $c): string => json_decode($c['result'], true)['because'], $kept));
    }

    public function testASecretOfTheHouseIsNotKeptWithTheAttempt(): void
    {
        [$operations, $sessions, $root] = $this->house([self::call('herramientas_prestar', (string) json_encode(['id' => 1, 'nota' => self::SECRET])), 'Done.'], self::NO_SEAT);
        @mkdir($root . '/.milpa', 0o777, true);
        file_put_contents($root . '/.milpa/secrets.json', json_encode(['mail' => ['password' => self::SECRET]]));

        self::leg($operations, self::NO_SEAT);

        $kept = self::callsOf($sessions, 'herramientas_prestar');
        self::assertCount(1, $kept);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($kept[0]));
        self::assertSame(1, $kept[0]['arguments']['id']);
    }

    /** @var list<string> the verbs of the capability that ran */
    private array $ran = [];

    /**
     * A house with the course's capability built in it and one session of `$by`, whose leg runs the real loop
     * against a model that answers the script in order.
     *
     * @param list<array<string, mixed>|string> $script
     *
     * @return array{0: KeptFixtureOperations, 1: SessionStore, 2: string, 3: Kernel}
     */
    private function house(array $script, string $by = self::SEAT): array
    {
        $this->ran = [];
        $root = $this->root();
        $container = new DIContainer();
        $verbs = [];
        foreach ([['herramientas.listar', 'herramientas:read', false], ['herramientas.prestar', 'herramientas:write', true]] as [$name, $scope, $mutating]) {
            $verbs[] = $this->verb($name, [$scope], mutating: $mutating, handler: function (array $input) use ($name): array {
                $this->ran[] = $name;

                return ['ok' => true, 'ran' => $name];
            });
        }
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $verbs)], [], $container);
        (new \ReflectionProperty(Kernel::class, 'toolRegistry'))->setValue($kernel, new ToolRegistry(new NullLogger()));
        $container->registerService(Kernel::class, $kernel);
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(EventStoreInterface::class, $events);
        $sessions->start('bv', 'Presta el taladro.', AutonomyMode::Auto, by: new Principal('key:' . $by, true));
        $operations = new KeptFixtureOperations($container);
        (new \ReflectionProperty(AgentOperations::class, 'trialRouterMemo'))->setValue($operations, null);
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturnCallback(static function (string $prompt, array $tools, array $messages) use (&$script, $operations): array {
            $operations->seen[] = ['tools' => array_column($tools, 'name'), 'messages' => $messages];
            $next = array_shift($script);

            return \is_string($next) ? ['role' => 'assistant', 'content' => $next] : ($next ?? ['role' => 'assistant', 'content' => 'Nothing else.']);
        });
        $operations->model = $llm;

        return [$operations, $sessions, $root, $kernel];
    }

    /**
     * One assistant message with these calls, given as name, arguments-as-JSON-text, name, arguments…
     *
     * @return array<string, mixed>
     */
    private static function call(string ...$namesAndArguments): array
    {
        $toolCalls = [];
        foreach (array_chunk($namesAndArguments, 2) as $n => [$name, $raw]) {
            $toolCalls[] = ['id' => 'm' . $n, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $raw]];
        }

        return ['role' => 'assistant', 'content' => '', 'tool_calls' => $toolCalls];
    }

    /** @return list<array<string, mixed>> the payloads of every call the session's log kept */
    private static function calls(SessionStore $sessions): array
    {
        $kept = [];
        foreach ($sessions->stream('bv') as $event) {
            if ($event->type === 'session.tool_called') {
                $kept[] = $event->payload;
            }
        }

        return $kept;
    }

    /** @return list<array<string, mixed>> */
    private static function callsOf(SessionStore $sessions, string $tool): array
    {
        return array_values(array_filter(self::calls($sessions), static fn (array $call): bool => $call['tool'] === $tool));
    }

    /**
     * One leg of the session, run by the key `$by` holding a seat's scopes.
     *
     * @param array<string, mixed> $more
     */
    private static function leg(AgentOperations $operations, string $by, array $more = []): void
    {
        $authority = new ToolContext(principal: 'key:' . $by, channel: 'cli', scopes: self::SEAT_SCOPES);
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    ($operation->handler)(['prompt' => 'continue', 'session' => 'bv'] + $more, new InvocationContext($authority->principal, true), $authority);

                    return;
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('the agent operation was not offered');
    }
}

/** A leg whose loop is the real one, asking a model the test scripted, through the door the leg built. */
final class KeptFixtureOperations extends AgentOperations
{
    public ?LlmService $model = null;

    /** @var list<array{tools: list<string>, messages: list<array<string, mixed>>}> */
    public array $seen = [];

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->model !== null);

        return new AgentOrchestrator($this->model, $cliente, 6);
    }
}
