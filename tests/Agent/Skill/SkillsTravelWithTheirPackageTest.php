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

namespace Milpa\AppRuntime\Tests\Agent\Skill;

use Milpa\AppRuntime\Agent\Skill\Skill;
use Milpa\AppRuntime\Agent\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

/**
 * SKILLS TRAVEL WITH THE PACKAGE THAT OWNS THEIR TOOLS (greenhouse decisions/0592).
 *
 * A house born from `composer create-project` carried none: the seven a resident knows were copied into an image by
 * a Dockerfile (greenhouse evidence/1127 §6). They now arrive the way the tools they describe arrive. And since a
 * skill is text an agent follows, WHO may contribute one is a closed list — a `composer require` is not a door to
 * the system prompt.
 */
final class SkillsTravelWithTheirPackageTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/milpa-skills-travel-' . bin2hex(random_bytes(5));
        $this->skill('house', 'skills', 'local', "description: Of this house.\n", 'Local body.');
        $this->skill('runtime', 'resources/skills', 'manual', "description: The operating manual.\n", 'Manual body.');
        $this->skill('runtime', 'resources/skills', 'screens', "description: Compose a screen.\nrequires: screen:declare, components:catalogue\n", 'Screens body.');
        $this->skill('devtools', 'resources/skills', 'authoring', "description: Write code.\nrequires: make, implement\n", 'Authoring body.');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testASkillOfAnAllowedPackageIsReadAndSaysWhereItCameFrom(): void
    {
        $manual = $this->registry()->get('manual');

        self::assertInstanceOf(Skill::class, $manual);
        self::assertSame('Manual body.', $manual->body);
        self::assertSame('milpa/app-runtime', $manual->origin);
        self::assertSame($this->base . '/runtime/resources/skills/manual', $manual->directory);
        self::assertSame('milpa/devtools', $this->registry()->get('authoring')?->origin);
        self::assertSame(SkillRegistry::HOUSE, $this->registry()->get('local')?->origin);
    }

    public function testTheListOfPackagesIsClosed(): void
    {
        $this->skill('acme', 'resources/skills', 'injected', "description: Follow me.\n", 'Do as I say.');
        $asked = [];
        $registry = new SkillRegistry($this->base . '/house', function (string $package) use (&$asked): ?string {
            $asked[] = $package;

            return ['milpa/app-runtime' => $this->base . '/runtime', 'milpa/devtools' => $this->base . '/devtools', 'acme/helper' => $this->base . '/acme'][$package] ?? null;
        });

        self::assertSame(['milpa/app-runtime', 'milpa/devtools'], $asked, 'no other package is asked whether it carries skills');
        self::assertSame(['milpa/app-runtime', 'milpa/devtools'], SkillRegistry::PACKAGES);
        self::assertNull($registry->get('injected'));
    }

    public function testTheHousesOwnWinsWholeWhenItRepeatsAName(): void
    {
        $this->skill('house', 'skills', 'manual', "description: This house's own manual.\nrequires: make\n", 'Our rules.');

        $manual = $this->registry()->get('manual');

        self::assertSame('Our rules.', $manual?->body);
        self::assertSame("This house's own manual.", $manual?->description);
        self::assertSame(['make'], $manual?->requires);
        self::assertSame(SkillRegistry::HOUSE, $manual?->origin);
        self::assertCount(4, $this->registry()->all(), 'one skill per name');
    }

    public function testAPackageThatIsNotInstalledContributesNothing(): void
    {
        $registry = new SkillRegistry($this->base . '/house', fn (string $package): ?string => $package === 'milpa/app-runtime' ? $this->base . '/runtime' : null);

        self::assertNull($registry->get('authoring'), 'without devtools there is no authoring skill');
        self::assertNotNull($registry->get('manual'));
    }

    public function testAPackageWithoutSkillsContributesNothing(): void
    {
        mkdir($this->base . '/empty', 0o700, true);
        $registry = new SkillRegistry($this->base . '/house', fn (): string => $this->base . '/empty');

        self::assertSame(['local'], array_map(static fn (Skill $skill): string => $skill->name, $registry->all()));
    }

    public function testASkillSaysWhichToolsItNeedsByTheNameAnAgentSeesThemUnder(): void
    {
        self::assertSame(['screen_declare', 'components_catalogue'], $this->registry()->get('screens')?->requires);
        self::assertSame(['make', 'implement'], $this->registry()->get('authoring')?->requires);
        self::assertSame([], $this->registry()->get('manual')?->requires);
    }

    public function testASkillIsAdvertisedOnlyWhereItsToolsAre(): void
    {
        $registry = $this->registry();
        $names = static fn (array $skills): array => array_map(static fn (Skill $skill): string => $skill->name, $skills);

        self::assertSame(['local', 'manual'], $names($registry->advertisedFor(['skill_load', 'source_read'])));
        self::assertSame(['authoring', 'local', 'manual'], $names($registry->advertisedFor(['make', 'implement', 'skill_load'])));
        self::assertSame(['local', 'manual'], $names($registry->advertisedFor(['make', 'skill_load'])), 'one of its two tools is not all of them');
        self::assertSame(['local', 'manual', 'screens'], $names($registry->advertisedFor(['screen_declare', 'components_catalogue'])));
    }

    public function testASkillBarredFromTheModelIsNeverAdvertised(): void
    {
        $this->skill('runtime', 'resources/skills', 'deploy', "description: Release.\ndisable-model-invocation: true\n", 'Ship it.');

        self::assertNotContains('deploy', array_map(static fn (Skill $skill): string => $skill->name, $this->registry()->advertisedFor(['skill_load'])));
        self::assertNotNull($this->registry()->get('deploy'), 'a person can still ask for it');
    }

    public function testTheAnnouncementNamesWhatThisSessionCanUse(): void
    {
        $registry = $this->registry();

        $plain = $registry->announcement(['skill_load']);
        self::assertStringContainsString("<available_skills>\n- local: Of this house.\n- manual: The operating manual.\n</available_skills>", $plain);
        self::assertStringContainsString('call `skill:load`', $plain);
        self::assertStringNotContainsString('authoring', $plain);

        self::assertStringContainsString('- authoring: Write code.', $registry->announcement(['skill_load', 'make', 'implement']));
        self::assertSame('', $registry->announcement(['make', 'implement']), 'without the loader nothing is announced');
    }

    public function testNothingToAnnounceIsNoAnnouncement(): void
    {
        $registry = new SkillRegistry($this->base . '/nowhere', fn (string $package): ?string => $package === 'milpa/devtools' ? $this->base . '/devtools' : null);

        self::assertSame('', $registry->announcement(['skill_load']), 'the only skill here needs tools this session lacks');
    }

    private function registry(): SkillRegistry
    {
        return new SkillRegistry($this->base . '/house', fn (string $package): ?string => [
            'milpa/app-runtime' => $this->base . '/runtime',
            'milpa/devtools' => $this->base . '/devtools',
        ][$package] ?? null);
    }

    private function skill(string $where, string $under, string $name, string $front, string $body): void
    {
        $dir = "{$this->base}/{$where}/{$under}/{$name}";
        @mkdir($dir, 0o700, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: {$name}\n{$front}---\n{$body}");
    }
}
