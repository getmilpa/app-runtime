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

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\AppRuntime\Agent\RecordedResultProjection;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Interfaces\EventStore\EventStoreInterface;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The locators of the results recorded during a leg ride AFTER the conversation, not inside the system prompt
 * (greenhouse evidence/1111, debt 8 of decisions/0575).
 *
 * Measured on the BV-4 run with the real resident (evidence/1109 §6.1): the `<recorded-results>` section closed the
 * system prompt and grew with every tool call, so the text BEFORE the conversation changed on every step and the
 * model server read the whole prompt again — 13 cold calls of 19, 224 s of 712. The same section sent after the
 * conversation leaves each request a prolongation of the one before: 3 cold calls.
 *
 * @guards with a gateway that can project after the conversation, the section leaves the system prompt and rides
 *         there, starting with its own words; the system prompt is then the same on every step of a leg; a gateway
 *         without that seam keeps the section where it was; the skill projection shapes the system prompt on both
 *         paths; a leg wires it so
 *
 * @refuses the section in both places; a section that silently disappears on an older gateway
 *
 * @subject-in milpa/app-runtime
 */
final class WhatGrowsRidesAfterTheConversationTest extends TestCase
{
    private const SECTION = "\n\nRecorded tool results from this invocation.\n<recorded-results>\n{}\n</recorded-results>";

    public function testWithTheSeamTheSectionLeavesTheSystemPrompt(): void
    {
        $gateway = self::gateway(after: true);
        RecordedResultProjection::attach($gateway, null, static fn (array $names): string => self::SECTION);

        self::assertSame('Base.', ($gateway->system)('Base.', [self::tool('agent_result')]));
    }

    public function testWithTheSeamTheSectionRidesAfterTheConversationStartingWithItsOwnWords(): void
    {
        $gateway = self::gateway(after: true);
        RecordedResultProjection::attach($gateway, null, static fn (array $names): string => self::SECTION);

        self::assertSame(ltrim(self::SECTION), ($gateway->trailing)([self::tool('agent_result')]));
        self::assertStringStartsWith('Recorded tool results from this invocation.', ($gateway->trailing)([]));
    }

    public function testTheSectionIsAskedWithTheNamesOfTheToolsOffered(): void
    {
        $asked = [];
        $recorded = static function (array $names) use (&$asked): string {
            $asked[] = $names;

            return self::SECTION;
        };
        $new = self::gateway(after: true);
        RecordedResultProjection::attach($new, null, $recorded);
        ($new->trailing)([self::tool('agent_result'), self::tool('make'), ['description' => 'nameless'], ['name' => 7]]);
        $old = self::gateway(after: false);
        RecordedResultProjection::attach($old, null, $recorded);
        ($old->system)('Base.', [self::tool('make')]);

        self::assertSame([['agent_result', 'make'], ['make']], $asked);
    }

    public function testAnOlderGatewayKeepsTheSectionInTheSystemPrompt(): void
    {
        $gateway = self::gateway(after: false);
        RecordedResultProjection::attach($gateway, null, static fn (array $names): string => self::SECTION);

        self::assertSame('Base.' . self::SECTION, ($gateway->system)('Base.', [self::tool('agent_result')]));
    }

    public function testAGatewayWithNoProjectionSeamIsLeftAlone(): void
    {
        $bare = new \stdClass();
        RecordedResultProjection::attach($bare, null, static fn (array $names): string => self::SECTION);

        self::assertSame([], get_object_vars($bare));
    }

    public function testAGatewayThatOnlyProjectsAfterTheConversationIsNotGivenHalfAWiring(): void
    {
        $only = new class () {
            public ?\Closure $trailing = null;

            public function setTrailingProjection(?callable $projection): self
            {
                $this->trailing = $projection === null ? null : $projection(...);

                return $this;
            }
        };
        RecordedResultProjection::attach($only, null, static fn (array $names): string => self::SECTION);

        self::assertNull($only->trailing);
    }

    public function testTheSkillProjectionShapesTheSystemPromptOnBothPaths(): void
    {
        $skill = static fn (string $base, array $tools): string => $base . ' [skills for ' . \count($tools) . ']';
        $new = self::gateway(after: true);
        RecordedResultProjection::attach($new, $skill, static fn (array $names): string => self::SECTION);
        $old = self::gateway(after: false);
        RecordedResultProjection::attach($old, $skill, static fn (array $names): string => self::SECTION);

        self::assertSame('Base. [skills for 2]', ($new->system)('Base.', [self::tool('a'), self::tool('b')]));
        self::assertSame('Base. [skills for 2]' . self::SECTION, ($old->system)('Base.', [self::tool('a'), self::tool('b')]));
    }

    public function testWithNothingRecordedNothingRides(): void
    {
        $gateway = self::gateway(after: true);
        RecordedResultProjection::attach($gateway, null, static fn (array $names): string => '');

        self::assertSame('', ($gateway->trailing)([self::tool('agent_result')]));
        self::assertSame('Base.', ($gateway->system)('Base.', []));
    }

    public function testWithoutASessionOnlyTheSkillProjectionIsWired(): void
    {
        $skill = static fn (string $base, array $tools): string => $base . ' [skills]';
        $new = self::gateway(after: true);
        RecordedResultProjection::attach($new, $skill, null);
        $old = self::gateway(after: false);
        RecordedResultProjection::attach($old, $skill, null);

        self::assertSame('Base. [skills]', ($new->system)('Base.', []));
        self::assertSame('', ($new->trailing)([]));
        self::assertSame('Base. [skills]', ($old->system)('Base.', []));
    }

    /**
     * Through the real door: a leg of `agent`, whose orchestrator records what each projection would send once a
     * tool call has been recorded during the run.
     */
    public function testALegSendsTheLocatorsAfterTheConversationAndKeepsItsSystemPromptStill(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'Review the fixture');
        // An earlier leg's result: this leg's locators are the ones it records, never what came before it.
        $sessions->recordToolCall('s', 'source_read', ['path' => 'src/EarlierLeg.php'], 'stored bytes', resultChars: 12);
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(Kernel::class, Kernel::boot(['root' => \dirname(__DIR__, 2), 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]));
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([self::tool('agent_result'), self::tool('source_read')]);
        $loop = new class ($this->createMock(LlmService::class), $tools) extends AgentOrchestrator {
            public ?\Closure $system = null;
            public ?\Closure $trailing = null;
            public ?SessionStore $sessions = null;
            /** @var list<array{system: string, trailing: string}> */
            public array $sent = [];

            public function setSystemPromptProjection(?callable $projection): self
            {
                $this->system = $projection === null ? null : $projection(...);

                return $this;
            }

            public function setTrailingProjection(?callable $projection): self
            {
                $this->trailing = $projection === null ? null : $projection(...);

                return $this;
            }

            public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
            {
                $offer = [['name' => 'agent_result'], ['name' => 'source_read']];
                foreach ([0, 1] as $step) {
                    $this->sent[] = ['system' => ($this->system)($systemPrompt, $offer), 'trailing' => ($this->trailing)($offer)];
                    $this->sessions?->recordToolCall('s', 'source_read', ['path' => "src/File$step.php"], 'stored bytes', resultChars: 12);
                }
                $this->sent[] = ['system' => ($this->system)($systemPrompt, $offer), 'trailing' => ($this->trailing)($offer)];

                return 'Done.';
            }
        };
        $loop->sessions = $sessions;
        $ops = new class ($container) extends AgentOperations {
            public AgentOrchestrator $loop;

            protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
            {
                return $this->loop;
            }
        };
        $ops->loop = $loop;
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($ops->operations() as $op) {
                if ($op->name === 'agent') {
                    ($op->handler)(['prompt' => 'Continue', 'session' => 's']);
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }

        self::assertCount(3, $loop->sent);
        self::assertSame('', $loop->sent[0]['trailing'], 'nothing is recorded by this leg before its first call');
        self::assertStringNotContainsString('EarlierLeg', $loop->sent[2]['trailing']);
        self::assertStringStartsWith('Recorded tool results from this invocation.', $loop->sent[1]['trailing']);
        self::assertSame(1, substr_count($loop->sent[1]['trailing'], '"tool":"source_read"'));
        self::assertSame(2, substr_count($loop->sent[2]['trailing'], '"tool":"source_read"'));
        self::assertSame($loop->sent[0]['system'], $loop->sent[1]['system']);
        self::assertSame($loop->sent[0]['system'], $loop->sent[2]['system'], 'the system prompt does not move while the leg records');
        self::assertStringNotContainsString('<recorded-results>', $loop->sent[2]['system']);
    }

    /** A gateway that records the projections it is handed; without `after`, one that cannot project after the conversation. */
    private static function gateway(bool $after): object
    {
        if (!$after) {
            return new class () {
                public ?\Closure $system = null;

                public function setSystemPromptProjection(?callable $projection): self
                {
                    $this->system = $projection === null ? null : $projection(...);

                    return $this;
                }
            };
        }

        return new class () {
            public ?\Closure $system = null;
            public ?\Closure $trailing = null;

            public function setSystemPromptProjection(?callable $projection): self
            {
                $this->system = $projection === null ? null : $projection(...);

                return $this;
            }

            public function setTrailingProjection(?callable $projection): self
            {
                $this->trailing = $projection === null ? null : $projection(...);

                return $this;
            }
        };
    }

    /** @return array<string, mixed> */
    private static function tool(string $name): array
    {
        return ['name' => $name, 'description' => $name, 'inputSchema' => ['type' => 'object']];
    }
}
