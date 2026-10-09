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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\GrantedCall;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * THE LEG OF A PERSON'S SESSION SAYS, AS DATA, THAT IT HAS NO FRONTIER (greenhouse decisions/0609, path 1, I2).
 *
 * The panel has to tell her, in her own conversation, what her session cannot do and which act works today. It must
 * not read a sentence to know it: the result of the turn that was refused carries one field — who opened the session,
 * the act that works, and each call of THIS turn that lacked a permission of a plugin, shaped like a seat's refusal
 * without a seat. It is information: the field grants nothing, and nothing waits on it.
 *
 * @guards the field on the result of a person's turn that was refused for building; its absence on a seat's turn and on
 *         a turn nothing was refused in; nothing granted, nothing waiting
 *
 * @refuses a surface that parses the refusal's sentence; a card for a refusal of an earlier turn
 *
 * @subject-in milpa/app-runtime
 */
final class ALegOfAPersonsSessionSaysItHasNoFrontierTest extends TestCase
{
    private const PERSON = 'actor:passkey:QM1LEWEfsoWiMmAbCdEf0123456789';
    private const SEAT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';
    private const MAKE = ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'];
    private const REFUSED = "Missing required permission 'plugins.Blog:write' for plugin 'Blog'. No plugin 'Blog' exists in this house yet.";

    private SessionStore $sessions;

    private InMemoryEventStore $events;

    public function testTheTurnOfAPersonThatWasRefusedCarriesIt(): void
    {
        $r = $this->turn(new Principal(self::PERSON, true), refused: true);

        self::assertSame('final_answer', $r['termination']['reason'], 'her leg does not wait: there is nothing to wait for');
        self::assertSame(['opened_by', 'works', 'refused'], array_keys($r['no_frontier']));
        self::assertSame('person', $r['no_frontier']['opened_by']);
        self::assertSame('seat_a_resident_and_grant', $r['no_frontier']['works']);
        self::assertCount(1, $r['no_frontier']['refused']);
        $row = $r['no_frontier']['refused'][0];
        self::assertSame(['seq', 'tool', 'plugin', 'permission', 'call'], array_keys($row));
        self::assertSame(['make', 'Blog', 'plugins.Blog:write', self::MAKE], [$row['tool'], $row['plugin'], $row['permission'], $row['call']]);

        // Y5 — it is information. Nothing waits on a person, nothing was granted, and the house recorded no debt.
        self::assertArrayNotHasKey('awaiting_grant', $r);
        self::assertSame([], $this->ofType(GrantedCall::GRANTED));
        self::assertSame([], $this->ofType('session.debt_signaled'));
    }

    public function testATurnOfHersNothingWasRefusedInCarriesNothing(): void
    {
        self::assertArrayNotHasKey('no_frontier', $this->turn(new Principal(self::PERSON, true), refused: false));
    }

    public function testItIsSaidOfTheTurnItHappenedInAndNotOfTheNext(): void
    {
        self::assertArrayHasKey('no_frontier', $this->turn(new Principal(self::PERSON, true), refused: true));

        $next = $this->turn(new Principal(self::PERSON, true), refused: false, sameSession: true);

        self::assertArrayNotHasKey('no_frontier', $next, 'her surface is not told again of a call an earlier turn was refused');
    }

    public function testASeatsTurnDoesNotCarryIt(): void
    {
        self::assertArrayNotHasKey('no_frontier', $this->turn(new Principal('key:' . self::SEAT, true), refused: true));
        self::assertArrayNotHasKey('no_frontier', $this->turn(new Principal('cli:rod@host', false), refused: true));
    }

    /**
     * One turn: the model calls `make` once and then answers. With `$refused` the house's door refuses the call for the
     * plugin's permission, as it does for anyone who lacks it, and records it.
     *
     * @return array<string, mixed>
     */
    private function turn(Principal $by, bool $refused, bool $sameSession = false): array
    {
        if (!$sameSession) {
            $this->events = new InMemoryEventStore();
            $this->sessions = new SessionStore($this->events);
            $this->sessions->start('s', 'Build a plugin named Blog', AutonomyMode::Ask, by: $by);
        }
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $this->sessions);
        $container->registerService(EventStoreInterface::class, $this->events);
        $kernel = Kernel::boot(['root' => \dirname(__DIR__, 2), 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);

        $calls = 0;
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturnCallback(static function () use (&$calls): array {
            return ++$calls === 1
                ? ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'make', 'arguments' => (string) json_encode(self::MAKE)]]]]
                : ['role' => 'assistant', 'content' => 'I could not build it here.'];
        });
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'make', 'description' => 'Scaffold', 'inputSchema' => ['type' => 'object']]]);
        $tools->method('callTool')->willReturnCallback(function () use ($refused): string {
            if ($refused) {
                $this->sessions->recordToolCall('s', 'make', self::MAKE, self::REFUSED, false, true);

                throw new \Exception(self::REFUSED);
            }
            $this->sessions->recordToolCall('s', 'make', self::MAKE, '{"ran_in_trial":true}', true, true);

            return '{"ran_in_trial":true}';
        });
        $ops = new NoFrontierFixtureOperations($container);
        $ops->loop = new AgentOrchestrator($llm, $tools);

        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($ops->operations() as $op) {
                if ($op->name === 'agent') {
                    /** @var array<string, mixed> */
                    return ($op->handler)(['prompt' => 'Build it', 'session' => 's']);
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('agent is not offered');
    }

    /** @return list<\Milpa\EventStore\Event> */
    private function ofType(string $type): array
    {
        return array_values(array_filter($this->sessions->stream('s'), static fn ($e): bool => $e->type === $type));
    }
}

/** The agent operation with its loop handed in. */
final class NoFrontierFixtureOperations extends AgentOperations
{
    public AgentOrchestrator $loop;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        return $this->loop;
    }
}
