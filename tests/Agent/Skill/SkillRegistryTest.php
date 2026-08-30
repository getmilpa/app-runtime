<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent\Skill;

use Milpa\AppRuntime\Agent\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class SkillRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-skills-' . getmypid() . '-' . uniqid();
        $this->write('alpha', "---\nname: alpha\ndescription: An alpha skill.\n---\nDo alpha things.");
        $this->write('beta', "---\nname: beta\ndescription: A human-only workflow.\ndisable-model-invocation: true\n---\nDeploy.");
        $this->write('gamma', "---\nname: gamma\ndescription: Background knowledge.\nuser-invocable: false\n---\nLegacy.");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/skills/*/SKILL.md') ?: [] as $f) {
            @unlink($f);
            @rmdir(\dirname($f));
        }
        @rmdir($this->root . '/skills');
        @rmdir($this->root);
    }

    private function write(string $name, string $body): void
    {
        @mkdir($this->root . "/skills/{$name}", 0o777, true);
        file_put_contents($this->root . "/skills/{$name}/SKILL.md", $body);
    }

    public function testItReadsEverySkillWithItsBodyAndDirectory(): void
    {
        $reg = new SkillRegistry($this->root);

        self::assertCount(3, $reg->all());
        self::assertSame('alpha', $reg->get('alpha')?->name);
        self::assertSame('Do alpha things.', $reg->get('alpha')?->body);
        self::assertSame('An alpha skill.', $reg->get('alpha')?->description);
        self::assertStringEndsWith('skills/alpha', $reg->get('alpha')?->directory ?? '');
        self::assertNull($reg->get('missing'));
    }

    public function testItHonoursTheInvocationFlags(): void
    {
        $reg = new SkillRegistry($this->root);

        self::assertTrue($reg->get('alpha')?->modelInvocable);
        self::assertTrue($reg->get('alpha')?->userInvocable);
        self::assertFalse($reg->get('beta')?->modelInvocable, 'disable-model-invocation withholds it from the model');
        self::assertFalse($reg->get('gamma')?->userInvocable, 'user-invocable:false keeps it agent-only');

        $names = array_map(static fn ($s) => $s->name, $reg->modelInvocable());
        self::assertContains('alpha', $names);
        self::assertContains('gamma', $names);
        self::assertNotContains('beta', $names, 'the model never reaches for a barred skill');
    }

    public function testParseRejectsNoFrontmatterAndNoDescription(): void
    {
        self::assertNull(SkillRegistry::parse('no frontmatter at all', 'x'));
        self::assertNull(SkillRegistry::parse("---\nname: y\n---\nbody", 'y'), 'a skill with no description cannot be triggered');
    }
}
