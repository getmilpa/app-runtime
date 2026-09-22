<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A spelling hint cannot turn a denied target into an authorized alias. */
final class PluginCaseHintTest extends TestCase
{
    /** @return iterable<string, array{string, list<mixed>, ?string}> */
    public static function denials(): iterable
    {
        yield 'lowercase target' => ['owned', ['plugins.Owned:write'], 'plugins.Owned:write'];
        yield 'uppercase target' => ['OWNED', ['plugins.Owned:write'], 'plugins.Owned:write'];
        yield 'reverse capitalization' => ['Owned', ['plugins.owned:write'], 'plugins.owned:write'];
        yield 'missing grant' => ['Owned', [], null];
        yield 'another plugin' => ['Other', ['plugins.Owned:write'], null];
        yield 'ambiguous grants' => ['owned', ['plugins.Owned:write', 'plugins.OWNED:write'], null];
        yield 'duplicate grant' => ['owned', ['plugins.Owned:write', 'plugins.Owned:write'], 'plugins.Owned:write'];
        yield 'read permission' => ['owned', ['plugins.Owned:read'], null];
        yield 'wildcard-looking pattern' => ['owned', ['plugins.*:write'], null];
        yield 'extra permission segment' => ['owned', ['plugins.Owned:write:extra'], null];
        yield 'wrong resource prefix' => ['owned', ['plugin.Owned:write'], null];
        yield 'trailing newline' => ['owned', ["plugins.Owned:write\n"], null];
        yield 'unrelated grant stays undisclosed' => ['owned', ['plugins.Owned:write', 'plugins.Other:write'], 'plugins.Owned:write'];
        yield 'similar spelling is not case' => ['Own', ['plugins.Owned:write'], null];
        yield 'invalid scope values' => ['owned', [17, null, 'plugins.Other:write'], null];
        yield 'invalid values beside matching grant' => ['owned', [false, [], 'plugins.Owned:write'], 'plugins.Owned:write'];
    }

    /**
     * Preserve the exact refusal prefix; only one explicit case variant may be named.
     *
     * @param list<mixed> $scopes
     */
    #[DataProvider('denials')]
    public function testDeniedTargetsRemainDenied(string $plugin, array $scopes, ?string $hint): void
    {
        $policy = new PluginAuthoringPolicy(sys_get_temp_dir());
        $context = new ToolContext(scopes: $scopes);
        $expected = "Missing required permission 'plugins.{$plugin}:write' for plugin '{$plugin}'.";
        if ($hint !== null) {
            $expected .= " Plugin identifiers and grants are case-sensitive. The current grant is '{$hint}'."
                . ' Verify the installed plugin identifier before requesting a different permission.';
        }
        try {
            $policy->writePaths($context, 'edit', ['plugin' => $plugin, 'class' => 'Renderer']);
            self::fail('A case hint must not authorize the requested spelling.');
        } catch (\RuntimeException $error) {
            self::assertSame($expected, $error->getMessage());
        }
        self::assertSame($scopes, $context->scopes, 'A hint must not mutate the current grant.');
    }

    /** A corrected identifier has its exact write set; revoking that grant still denies it. */
    public function testCorrectedTargetAndRevocationUseCurrentAuthority(): void
    {
        $policy = new PluginAuthoringPolicy(sys_get_temp_dir());
        $context = new ToolContext(scopes: ['plugins.Owned:write']);
        try {
            $policy->writePaths($context, 'implement', ['plugin' => 'owned']);
            self::fail('The first spelling remains denied.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString("The current grant is 'plugins.Owned:write'.", $error->getMessage());
        }
        self::assertSame(['src/Plugins/Owned', 'tests/Plugins/Owned'], $policy->writePaths($context, 'implement', ['plugin' => 'Owned']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required permission 'plugins.Owned:write' for plugin 'Owned'.");
        $policy->writePaths(new ToolContext(scopes: []), 'implement', ['plugin' => 'Owned']);
    }

    /** Exact and global grants keep their original meaning even beside other case variants. */
    public function testExistingAuthorityDoesNotBecomeAmbiguous(): void
    {
        $policy = new PluginAuthoringPolicy(sys_get_temp_dir());
        self::assertSame(['src/Plugins/Owned', 'tests/Plugins/Owned'], $policy->writePaths(
            new ToolContext(scopes: ['plugins.Owned:write', 'plugins.OWNED:write']),
            'implement',
            ['plugin' => 'Owned'],
        ));
        self::assertNull($policy->writePaths(new ToolContext(scopes: ['*']), 'implement', ['plugin' => 'owned']));
    }
}
