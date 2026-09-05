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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Agent\AgentTable;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `skill:invoke` is the human's door to a skill — a door, not an argument (greenhouse
 * decisions/0202, and its review).
 *
 * The first cut let `skill:load` take `by: human`. The model fills the arguments of every tool on
 * its table, so that made the human's door a word the model could say. Now the invoker comes from
 * the SURFACE: `skill:invoke` rides the human's surfaces, honours `user-invocable`, and sits in
 * `AgentTable::ADJUDICAN` so it is never projected onto the model's table; `skill:load` keeps the
 * contract it had before the branch — the model's own bar, `disable-model-invocation`.
 */
final class SkillInvokeTest extends TestCase
{
    private string $root;

    private DIContainer $container;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-ar-skills-' . bin2hex(random_bytes(4));
        // alpha: both doors open. deploy: the human's only. lore: the model's only.
        $this->skill('alpha', "---\nname: alpha\ndescription: An alpha skill.\n---\nDo alpha things.");
        $this->skill('deploy', "---\nname: deploy\ndescription: A human-only workflow.\ndisable-model-invocation: true\n---\nShip it.");
        $this->skill('lore', "---\nname: lore\ndescription: Background knowledge.\nuser-invocable: false\n---\nOnce upon a time.");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** 1 · the human invokes a skill the model may not reach for, wrapped exactly as the model's door wraps it. */
    public function testTheHumanInvokesASkillBarredFromTheModel(): void
    {
        $r = $this->call('skill:invoke', ['name' => 'deploy']);

        self::assertTrue($r['ok']);
        self::assertSame('deploy', $r['name']);
        self::assertStringStartsWith('<skill_content name="deploy">', (string) $r['body']);
        self::assertStringContainsString('<skill_resources>Base directory for this skill: ', (string) $r['body']);
        self::assertStringContainsString('Ship it.', (string) $r['body']);
        self::assertStringEndsWith('</skill_content>', (string) $r['body']);
    }

    /** 2 · THE CONTROL: the model's own door still refuses that skill, with the refusal it always gave. */
    public function testTheModelsDoorStillRefusesTheSkillBarredFromIt(): void
    {
        $r = $this->call('skill:load', ['name' => 'deploy']);

        self::assertFalse($r['ok']);
        self::assertSame("skill 'deploy' is user-invocable only; ask the human to run it", $r['error']);
    }

    /** 3 · background knowledge marked `user-invocable: false` is refused to the human by name — and open to the model. */
    public function testASkillThatIsNotUserInvocableIsRefusedByName(): void
    {
        $human = $this->call('skill:invoke', ['name' => 'lore']);
        self::assertFalse($human['ok']);
        self::assertSame("skill 'lore' is not user-invocable", $human['error']);

        self::assertTrue($this->call('skill:load', ['name' => 'lore'])['ok'], 'the model reaches background knowledge');
    }

    /** 4 · a skill open to both is served by both, with the same body. */
    public function testASkillOpenToBothIsServedByBoth(): void
    {
        $human = $this->call('skill:invoke', ['name' => 'alpha']);
        $model = $this->call('skill:load', ['name' => 'alpha']);

        self::assertTrue($human['ok']);
        self::assertTrue($model['ok']);
        self::assertSame($model['body'], $human['body']);
    }

    /** 5 · an unknown skill is unknown at both doors, in the same words. */
    public function testAnUnknownSkillIsUnknownAtBothDoors(): void
    {
        self::assertSame('unknown skill: nadie', $this->call('skill:invoke', ['name' => 'nadie'])['error']);
        self::assertSame('unknown skill: nadie', $this->call('skill:load', ['name' => 'nadie'])['error']);
    }

    /** 6 · without a kernel there is no app root to read skills from — said, not guessed. */
    public function testWithoutAKernelItSaysSo(): void
    {
        $ops = new AgentOperations(new DIContainer());
        $handler = $this->operation($ops, 'skill:invoke')->handler;
        self::assertIsCallable($handler);

        /** @var array<string, mixed> $r */
        $r = $handler(['name' => 'alpha']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no kernel', (string) $r['error']);
    }

    /** 7 · the declaration: the human's surfaces, the web included; reads only; `name` is all it takes; `agent:run` to drive. */
    public function testTheDeclarationRidesTheHumansSurfacesAndReadsOnly(): void
    {
        $op = $this->operation($this->booted(), 'skill:invoke');

        self::assertSame(['cli', 'tui', 'mcp', 'http'], $op->surfaces);
        self::assertFalse($op->mutating);
        self::assertSame(['agent:run'], $op->scopes, 'putting a skill in front of the agent drives it (greenhouse decisions/0208)');
        self::assertSame(['name'], $op->inputSchema['required'] ?? null);
        self::assertSame(['name'], array_keys($op->inputSchema['properties'] ?? []), 'who invokes it is not an argument');
    }

    /**
     * 8 · THE CONTROL THE REVIEW ASKED FOR: the model's table, built the way `toolsOfThisApp` builds
     * it — `AgentTable::offers` and then the projector — carries no `skill_invoke`, and the
     * `skill_load` it does carry takes no `by`. The positive half: `skill:invoke` IS declared, so its
     * absence is the rule at work, not a missing operation.
     */
    public function testTheModelsTableCarriesNoInvokeAndItsLoadTakesNoBy(): void
    {
        $ops = $this->booted();
        $invoke = $this->operation($ops, 'skill:invoke');
        self::assertFalse(AgentTable::offers($invoke), 'the rule, asked once');

        $offered = array_values(array_filter(
            $ops->operations(),
            static fn (Operation $op): bool => AgentTable::offers($op),
        ));
        self::assertNotSame([], $offered, 'the table is not empty — the absence below is selective');

        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll($offered, $registry, $this->container);

        self::assertNull($registry->getDefinition(McpProjector::toolName('skill:invoke')), 'never on the model\'s table');

        $load = $registry->getDefinition(McpProjector::toolName('skill:load'));
        self::assertNotNull($load, 'the model keeps its own door');
        self::assertArrayNotHasKey('by', $load->inputSchema['properties'] ?? [], 'and its door takes no `by`');
        self::assertSame(['name'], array_keys($load->inputSchema['properties'] ?? []));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(string $name, array $input): array
    {
        $handler = $this->operation($this->booted(), $name)->handler;
        self::assertIsCallable($handler);

        /** @var array<string, mixed> $r */
        $r = $handler($input);

        return $r;
    }

    private function operation(AgentOperations $ops, string $name): Operation
    {
        foreach ($ops->operations() as $op) {
            if ($op->name === $name) {
                return $op;
            }
        }

        self::fail("{$name} is not declared");
    }

    /** A real kernel over the fixture root, so both doors read the skills where an app keeps them. */
    private function booted(): AgentOperations
    {
        $this->container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $this->container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
            'config' => ['app' => ['name' => 'skills-house']],
        ]);
        $this->container->registerService(Kernel::class, $kernel);

        return new AgentOperations($this->container);
    }

    private function skill(string $name, string $body): void
    {
        mkdir($this->root . "/skills/{$name}", 0o775, true);
        file_put_contents($this->root . "/skills/{$name}/SKILL.md", $body);
    }
}
