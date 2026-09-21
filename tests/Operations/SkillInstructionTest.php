<?php

/**
 * Skill-loading instructions follow the governed executor's current offer.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SkillInstructionTest extends TestCase
{
    private string $root;

    private AgentOperations $operations;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-skill-instruction-' . bin2hex(random_bytes(6));
        $this->skill('alpha', 'Compose an interface.');
        $this->skill('deploy', 'Release the app.', 'disable-model-invocation: true');
        $this->skill('lore', 'Background knowledge.', 'user-invocable: false');
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);
        $this->operations = new AgentOperations($container);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function withoutLoader(): iterable
    {
        yield 'empty offer' => [[]];
        yield 'recorded result reader' => [['agent_result', 'implement']];
        yield 'skill listing only' => [['skill_list']];
        yield 'human invocation only' => [['skill_invoke']];
    }

    /** Registered skills cannot require a loading tool absent from the current offer. */
    #[DataProvider('withoutLoader')]
    public function testUnavailableLoaderDoesNotProduceAnInstruction(array $offer): void
    {
        $prompt = $this->prompt($offer);
        self::assertStringNotContainsString('<available_skills>', $prompt);
        self::assertStringNotContainsString('call `skill:load`', $prompt);
        self::assertStringContainsString('Use the tools to answer; do not invent results.', $prompt);
    }

    /** A usable loader retains model guidance and the separate model-invocation restriction. */
    public function testTheAvailableLoaderAdvertisesOnlyModelInvocableSkills(): void
    {
        $prompt = $this->prompt(['skill_load', 'agent_result']);
        self::assertStringContainsString('<available_skills>', $prompt);
        self::assertStringContainsString('- alpha: Compose an interface.', $prompt);
        self::assertStringContainsString('- lore: Background knowledge.', $prompt);
        self::assertStringNotContainsString('- deploy:', $prompt);
        self::assertStringContainsString('call `skill:load`', $prompt);
    }

    /** Reusing the operations instance cannot retain a loading instruction across changed offers. */
    public function testWithdrawingAndRestoringTheLoaderChangesOnlyTheReminder(): void
    {
        $available = $this->prompt(['skill_load']);
        $restricted = $this->prompt(['agent_result']);
        self::assertSame(1, preg_match('~\n\n<system-reminder>.*?</system-reminder>~s', $available, $match));
        self::assertSame(str_replace($match[0], '', $available), $restricted);
        self::assertSame($available, $this->prompt(['skill_load']));
    }

    /** A loader alone cannot advertise a skill barred from model invocation. */
    public function testOnlyHumanSkillsDoNotCreateAModelReminder(): void
    {
        unlink($this->root . '/skills/alpha/SKILL.md');
        unlink($this->root . '/skills/lore/SKILL.md');
        self::assertStringNotContainsString('<available_skills>', $this->prompt(['skill_load']));
    }

    /** The pure projection changes only the emitted slot and keeps invocation facts intact. */
    public function testOneInvocationCanWithdrawAndRestoreTheInstruction(): void
    {
        $available = $this->prompt(['skill_load']);
        $project = (new \ReflectionProperty($this->operations, 'skillInstructionProjection'))->getValue($this->operations);
        $snapshot = "\n\n<run-context>initial facts</run-context>\nTOOLBOX: preserved";
        $restricted = $this->prompt(['agent_result']);
        self::assertSame($available . $snapshot, $project($available . $snapshot, [['name' => 'skill_load']]));
        self::assertSame($restricted . $snapshot, $project($available . $snapshot, [['name' => 'agent_result']]));
        self::assertSame($available . $snapshot, $project($available . $snapshot, [['name' => 'skill_load']]));
        self::assertSame('Custom prompt.', $project('Custom prompt.', [['name' => 'skill_load']]));
    }

    /** A loader first offered later gains exactly the original slot, not an appended duplicate. */
    public function testInitiallyAbsentLoaderCanBecomeAvailableWithoutRereadingState(): void
    {
        $available = $this->prompt(['skill_load']);
        $restricted = $this->prompt(['agent_result']);
        $project = (new \ReflectionProperty($this->operations, 'skillInstructionProjection'))->getValue($this->operations);
        unlink($this->root . '/skills/alpha/SKILL.md');
        self::assertSame($available, $project($restricted, [['name' => 'skill_load']]));
        self::assertSame($restricted, $project($restricted, [['name' => 'describe_tool', 'description' => 'skill_load']]));
        self::assertSame($restricted, $project($restricted, []));
    }

    /** @param list<string> $offer */
    private function prompt(array $offer): string
    {
        return (new \ReflectionMethod($this->operations, 'systemPrompt'))->invoke($this->operations, $offer);
    }

    /** Create an app-owned skill fixture with the actual registry's frontmatter. */
    private function skill(string $name, string $description, string $flags = ''): void
    {
        mkdir($this->root . '/skills/' . $name, 0o775, true);
        file_put_contents(
            $this->root . '/skills/' . $name . '/SKILL.md',
            "---\nname: {$name}\ndescription: {$description}\n{$flags}\n---\nInstructions for {$name}.\n"
        );
    }
}
