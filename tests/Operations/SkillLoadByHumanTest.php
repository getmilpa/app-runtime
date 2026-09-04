<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `skill:load` knows who is loading (greenhouse decisions/0202).
 *
 * Two invocation flags name two callers. Before this, the refusal for a skill barred from the model
 * said «ask the human to run it» and named a surface that did not exist: nothing let a human load
 * it. Now `by: human` honours `user-invocable`, `by: model` honours `disable-model-invocation`
 * exactly as before, and a third caller is refused rather than defaulted.
 */
final class SkillLoadByHumanTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-ar-skills-' . bin2hex(random_bytes(4));
        // alpha: both may load it. deploy: the human only. lore: the model only.
        $this->skill('alpha', "---\nname: alpha\ndescription: An alpha skill.\n---\nDo alpha things.");
        $this->skill('deploy', "---\nname: deploy\ndescription: A human-only workflow.\ndisable-model-invocation: true\n---\nShip it.");
        $this->skill('lore', "---\nname: lore\ndescription: Background knowledge.\nuser-invocable: false\n---\nOnce upon a time.");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** 1 · the human loads a skill the model may not reach for, wrapped exactly as the model would get it. */
    public function testTheHumanLoadsAUserInvocableOnlySkill(): void
    {
        $r = $this->load(['name' => 'deploy', 'by' => 'human']);

        self::assertTrue($r['ok']);
        self::assertSame('deploy', $r['name']);
        self::assertStringStartsWith('<skill_content name="deploy">', (string) $r['body']);
        self::assertStringContainsString('Ship it.', (string) $r['body']);
        self::assertStringContainsString('<skill_resources>Base directory for this skill: ', (string) $r['body']);
    }

    /** 2 · THE CONTROL: the model is still refused that same skill — the bar is not decorative. */
    public function testTheModelIsStillRefusedTheSkillBarredFromIt(): void
    {
        $r = $this->load(['name' => 'deploy', 'by' => 'model']);

        self::assertFalse($r['ok']);
        self::assertSame("skill 'deploy' is user-invocable only; ask the human to run it", $r['error']);
    }

    /** 3 · the human is refused background knowledge marked `user-invocable: false`, by name. */
    public function testTheHumanIsRefusedASkillThatIsNotUserInvocable(): void
    {
        $r = $this->load(['name' => 'lore', 'by' => 'human']);

        self::assertFalse($r['ok']);
        self::assertSame("skill 'lore' is not user-invocable", $r['error']);
    }

    /** 4 · `by` defaults to the model: an omitted `by` behaves exactly as before, on both flags. */
    public function testOmittingByIsTheModel(): void
    {
        self::assertTrue($this->load(['name' => 'lore'])['ok'], 'the model reaches background knowledge');
        self::assertTrue($this->load(['name' => 'alpha'])['ok']);
        self::assertFalse($this->load(['name' => 'deploy'])['ok'], 'and is still barred from the human-only one');
    }

    /** 5 · a third caller is refused, not defaulted to the model. */
    public function testAThirdCallerIsRefused(): void
    {
        $r = $this->load(['name' => 'alpha', 'by' => 'vendor']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString("`by` must be `model` or `human`, not 'vendor'", (string) $r['error']);
    }

    /** 6 · an unknown skill is unknown to both callers. */
    public function testAnUnknownSkillIsUnknownToBoth(): void
    {
        self::assertSame('unknown skill: nadie', $this->load(['name' => 'nadie', 'by' => 'human'])['error']);
        self::assertSame('unknown skill: nadie', $this->load(['name' => 'nadie', 'by' => 'model'])['error']);
    }

    /** 7 · without a kernel there is no app root to read skills from — said, not guessed. */
    public function testWithoutAKernelItSaysSo(): void
    {
        $r = $this->call(new AgentOperations(new DIContainer()), ['name' => 'alpha', 'by' => 'human']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no kernel', (string) $r['error']);
    }

    /** 8 · the declaration: `by` is an enum of the two callers, and the op rides every surface — the web included. */
    public function testTheDeclarationNamesTheTwoCallersAndReachesTheWeb(): void
    {
        $op = $this->operation($this->booted());

        self::assertSame(['model', 'human'], $op->inputSchema['properties']['by']['enum'] ?? null);
        self::assertSame('model', $op->inputSchema['properties']['by']['default'] ?? null);
        self::assertSame(['name'], $op->inputSchema['required'] ?? null, '`by` is optional');
        self::assertTrue($op->supportsSurface('http'), 'a human at the Desktop loads it over http');
        self::assertFalse($op->mutating);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function load(array $input): array
    {
        return $this->call($this->booted(), $input);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(AgentOperations $ops, array $input): array
    {
        $handler = $this->operation($ops)->handler;
        self::assertIsCallable($handler);

        /** @var array<string, mixed> $r */
        $r = $handler($input);

        return $r;
    }

    private function operation(AgentOperations $ops): Operation
    {
        foreach ($ops->operations() as $op) {
            if ($op->name === 'skill:load') {
                return $op;
            }
        }

        self::fail('skill:load is not declared');
    }

    /** A real kernel over the fixture root, so `skill:load` reads the skills where an app keeps them. */
    private function booted(): AgentOperations
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
            'config' => ['app' => ['name' => 'skills-house']],
        ]);
        $container->registerService(Kernel::class, $kernel);

        return new AgentOperations($container);
    }

    private function skill(string $name, string $body): void
    {
        mkdir($this->root . "/skills/{$name}", 0o775, true);
        file_put_contents($this->root . "/skills/{$name}/SKILL.md", $body);
    }
}
