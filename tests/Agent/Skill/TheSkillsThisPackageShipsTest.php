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

use Milpa\AppRuntime\Agent\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

/**
 * THE SKILLS THIS PACKAGE CARRIES (greenhouse decisions/0592): the resident's manual, the two it opens and closes a
 * piece of work with, and the one for composing a screen — which is advertised only where there are screens.
 *
 * The manual keeps the gate it had where it lived before (greenhouse decisions/0183): it can only SHRINK. A rule
 * added to it belongs to the architecture of the house; the chapters that graduated do not come back.
 */
final class TheSkillsThisPackageShipsTest extends TestCase
{
    private const MANUAL = __DIR__ . '/../../../resources/skills/milpa-operator/SKILL.md';

    /** The ceiling measured after the pruning of greenhouse evidence/0450 (it came from 181). It only goes down. */
    private const CEILING_LINES = 63;

    public function testAHouseWithNothingOfItsOwnHasTheFour(): void
    {
        $registry = new SkillRegistry(sys_get_temp_dir() . '/milpa-no-house-' . bin2hex(random_bytes(4)), static fn (string $package): ?string => $package === 'milpa/app-runtime' ? \dirname(__DIR__, 3) : null);

        $shipped = [];
        foreach ($registry->all() as $skill) {
            $shipped[$skill->name] = $skill->origin;
            self::assertNotSame('', trim($skill->body), "{$skill->name} has a body");
        }
        ksort($shipped);

        self::assertSame([
            'governed-close' => 'milpa/app-runtime',
            'governed-discovery' => 'milpa/app-runtime',
            'milpa-operator' => 'milpa/app-runtime',
            'milpa-ui-composition' => 'milpa/app-runtime',
        ], $shipped);
    }

    public function testComposingAScreenIsAdvertisedOnlyWhereThereAreScreens(): void
    {
        $registry = new SkillRegistry('', static fn (string $package): ?string => $package === 'milpa/app-runtime' ? \dirname(__DIR__, 3) : null);
        $names = static fn (array $offer): array => array_map(static fn ($skill): string => $skill->name, $registry->advertisedFor($offer));

        self::assertNotContains('milpa-ui-composition', $names(['skill_load', 'make', 'implement']));
        self::assertContains('milpa-ui-composition', $names(['skill_load', 'screen_declare']));
        self::assertContains('milpa-operator', $names(['skill_load']), 'the manual needs no tool');
    }

    public function testWhatASkillPointsAtTravelsWithIt(): void
    {
        $skill = (new SkillRegistry('', static fn (string $package): ?string => $package === 'milpa/app-runtime' ? \dirname(__DIR__, 3) : null))->get('milpa-ui-composition');

        self::assertNotNull($skill);
        preg_match_all('~references/[A-Za-z0-9_./-]+\.(?:md|php|css)~', $skill->body, $named);
        self::assertNotSame([], $named[0], 'the control: this skill points at files of its own');
        foreach (array_unique($named[0]) as $reference) {
            self::assertFileExists($skill->directory . '/' . $reference);
        }
    }

    public function testTheManualOnlyShrinks(): void
    {
        self::assertLessThanOrEqual(
            self::CEILING_LINES,
            \count(file(self::MANUAL) ?: []),
            'the manual grew: a new rule belongs to the ARCHITECTURE of the house, not to the manual',
        );
    }

    public function testTheChaptersThatGraduatedDoNotComeBack(): void
    {
        $manual = (string) file_get_contents(self::MANUAL);
        foreach ([
            '## 1. ENTRY PROCEDURE' => 'house:context (0450)',
            '## 2. SOURCE-OF-TRUTH ORDER' => 'operation:contract + discover (0448, 0450)',
            '## 5. TOOL DISCIPLINE' => 'EffectProfile + the postcondition report (0448)',
            '## 6. WORK STATE' => 'work:claim-verified (0447)',
            '## 7. AUTHORITY' => 'grants (0443, 0445)',
            '## 8. CONTEXT BUDGET' => 'WindowBudget (0444)',
        ] as $heading => $architecture) {
            self::assertStringNotContainsString($heading, $manual, "a graduated chapter is back in the manual — its place is the architecture: {$architecture}");
        }
    }

    public function testTheHeartIsNotPruned(): void
    {
        $manual = (string) file_get_contents(self::MANUAL);

        self::assertStringContainsString('HOUSE DEBT', $manual);
        self::assertStringContainsString('THE SHRINKING CONTRACT', $manual);
    }
}
