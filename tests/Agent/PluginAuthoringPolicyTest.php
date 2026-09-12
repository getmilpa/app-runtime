<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A plugin permission must name the actual files, not just a requested label. */
final class PluginAuthoringPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function escapes(): iterable
    {
        yield 'other plugin' => ['make', ['plugin' => 'Other']];
        yield 'name traversal' => ['make', ['plugin' => '../Owned']];
        yield 'no target' => ['make', []];
        yield 'unscoped test' => ['test', []];
        yield 'test traversal' => ['test', ['path' => 'tests/Plugins/Owned/../Other']];
        yield 'absolute test' => ['test', ['path' => '/tests/Plugins/Owned']];
        yield 'parts not persisted' => ['implement', ['plugin' => 'Owned', 'mode' => 'start']];
    }

    #[DataProvider('escapes')]
    public function testInvalidOrForeignTargetsAreRefused(string $name, array $arguments): void
    {
        $policy = new PluginAuthoringPolicy(sys_get_temp_dir());
        $this->expectException(\RuntimeException::class);
        $policy->writePaths(new ToolContext(scopes: ['plugins.Owned:write']), $name, $arguments);
    }

    public function testOwnedWritesAreExactAndAnEmptyCurrentGrantIsNotAnOldGrant(): void
    {
        $policy = new PluginAuthoringPolicy(sys_get_temp_dir());
        $owned = new ToolContext(scopes: ['plugins.Owned:write']);
        self::assertSame(['src/Plugins/Owned', 'tests/Plugins/Owned'], $policy->writePaths($owned, 'make', ['plugin' => 'Owned']));
        self::assertSame(['src/Plugins/Owned', 'tests/Plugins/Owned'], $policy->writePaths($owned, 'test', ['path' => 'tests/Plugins/Owned/Test.php']));
        $policy->authorizePaths($owned, ['src/Plugins/Owned/Service.php'], sys_get_temp_dir());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required permission 'plugins.Owned:write'");
        $policy->authorizePaths(new ToolContext(scopes: []), ['src/Plugins/Owned/Service.php'], sys_get_temp_dir());
    }

    public function testMixedExportIsNotAuthorizedByItsFirstPath(): void
    {
        $policy = new PluginAuthoringPolicy(sys_get_temp_dir());
        $this->expectExceptionMessage("Missing required permission 'plugins.Other:write'");
        $policy->authorizePaths(new ToolContext(scopes: ['plugins.Owned:write']), ['src/Plugins/Owned/A.php', 'src/Plugins/Other/B.php'], sys_get_temp_dir());
    }

    public function testOtherMutationsRequireDeclaredAuthorityAndNoSandboxCannotFallBack(): void
    {
        $policy = new PluginAuthoringPolicy(sys_get_temp_dir(), new TrialRunner(bwrap: '/missing/bwrap'));
        $context = new ToolContext(scopes: ['plugins.Owned:write']);
        $unknown = new ToolDefinition('stubs_publish', '', [], static fn (): null => null, mutating: true);
        self::assertFalse($policy->authorize($context, $unknown, [])->allowed);
        $make = new ToolDefinition('make', '', [], static fn (): null => null, mutating: true);
        self::assertStringContainsString('requires an available write-confined trial', (string) $policy->authorize($context, $make, ['plugin' => 'Owned'])->reason);
        self::assertTrue($policy->authorize(ToolContext::cli(), $make, ['plugin' => 'Owned'])->allowed);
    }
}
