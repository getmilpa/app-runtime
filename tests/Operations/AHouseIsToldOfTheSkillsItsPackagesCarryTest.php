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

use Milpa\AppRuntime\Agent\Skill\SkillRegistry;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A HOUSE WITH NO `skills/` OF ITS OWN IS TOLD OF THE ONES ITS PACKAGES CARRY (greenhouse decisions/0592).
 *
 * Measured (greenhouse evidence/1127 §6): a house born from `composer create-project` offered `skill_list` and
 * `skill_load` and had nothing to hand over with them — `skill:list` answered `[]`. Nothing here registers how skills
 * are found: this is the house as it is born.
 */
final class AHouseIsToldOfTheSkillsItsPackagesCarryTest extends TestCase
{
    private string $root;
    private DIContainer $container;
    private AgentOperations $operations;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-born-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        $this->container = new DIContainer();
        $this->container->registerService(Kernel::class, Kernel::boot([
            'root' => $this->root,
            'container' => $this->container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]));
        $this->operations = new AgentOperations($this->container);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testItsPromptNamesTheManualAndWhatOpensAndClosesAPieceOfWork(): void
    {
        $prompt = $this->prompt(['skill_load', 'source_read']);

        self::assertStringContainsString('<available_skills>', $prompt);
        foreach (['milpa-operator', 'governed-discovery', 'governed-close'] as $skill) {
            self::assertStringContainsString("\n- {$skill}: ", $prompt);
        }
    }

    public function testAScreenSkillIsNamedOnlyToASessionThatCanDeclareAScreen(): void
    {
        self::assertStringNotContainsString('- milpa-ui-composition:', $this->prompt(['skill_load', 'make', 'implement']));
        self::assertStringContainsString("\n- milpa-ui-composition: ", $this->prompt(['skill_load', 'screen_declare']));
    }

    public function testWhenTheOfferChangesTheAnnouncementFollowsIt(): void
    {
        $without = $this->prompt(['skill_load']);
        $with = $this->prompt(['skill_load', 'screen_declare']);
        $project = (new \ReflectionProperty($this->operations, 'skillInstructionProjection'))->getValue($this->operations);

        self::assertNotSame($without, $with);
        self::assertSame($without, $project($with, [['name' => 'skill_load']]), 'the screen tool left the offer, and so did the skill about it');
        self::assertSame($with, $project($with, [['name' => 'skill_load'], ['name' => 'screen_declare']]));
        self::assertStringNotContainsString('<available_skills>', $project($with, [['name' => 'screen_declare']]), 'and without the loader nothing is announced');
    }

    public function testSkillListSaysWhereEachOneCameFrom(): void
    {
        mkdir($this->root . '/skills/taller', 0o775, true);
        file_put_contents($this->root . '/skills/taller/SKILL.md', "---\nname: taller\ndescription: How this workshop lends.\n---\nLend one at a time.\n");

        $listed = (new \ReflectionMethod($this->operations, 'listSkills'))->invoke($this->operations);
        $origins = array_column($listed['skills'], 'origin', 'name');

        self::assertSame(SkillRegistry::HOUSE, $origins['taller']);
        self::assertSame('milpa/app-runtime', $origins['milpa-operator']);
        self::assertSame(['screen_declare'], array_column($listed['skills'], 'requires', 'name')['milpa-ui-composition']);
    }

    public function testTheHousesOwnManualReplacesThePackages(): void
    {
        mkdir($this->root . '/skills/milpa-operator', 0o775, true);
        file_put_contents($this->root . '/skills/milpa-operator/SKILL.md', "---\nname: milpa-operator\ndescription: The manual of THIS house.\n---\nOur own rules.\n");

        self::assertStringContainsString("\n- milpa-operator: The manual of THIS house.\n", $this->prompt(['skill_load']));
        $loaded = (new \ReflectionMethod($this->operations, 'loadSkill'))->invoke($this->operations, 'milpa-operator');
        self::assertStringContainsString('Our own rules.', $loaded['body']);
        self::assertStringNotContainsString('HOUSE DEBT', $loaded['body']);
    }

    public function testASkillOfAPackageLoadsWithWhereItsFilesAre(): void
    {
        $loaded = (new \ReflectionMethod($this->operations, 'loadSkill'))->invoke($this->operations, 'milpa-ui-composition');

        self::assertTrue($loaded['ok']);
        self::assertStringContainsString('<skill_resources>Base directory for this skill: ' . \dirname(__DIR__, 2) . '/resources/skills/milpa-ui-composition</skill_resources>', $loaded['body']);
    }

    /** @param list<string> $offer */
    private function prompt(array $offer): string
    {
        return (new \ReflectionMethod($this->operations, 'systemPrompt'))->invoke($this->operations, $offer);
    }
}
