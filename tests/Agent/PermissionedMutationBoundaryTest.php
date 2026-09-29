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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\Command\Operation;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * An operation typed by `permission` declares its authority; over HTTP that permission was judged before the call.
 *
 * `Operation` holds `scopes` XOR `permission`. The boundary refused every mutation with empty scopes for a finite
 * principal, so a `permission` mutation served over HTTP — already enforced by the host's `OperationHttpPolicy`,
 * without which `HttpProjector` refuses to serve it — answered 500 on every call (app-docentes S-0036).
 */
final class PermissionedMutationBoundaryTest extends TestCase
{
    private PluginAuthoringPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PluginAuthoringPolicy(sys_get_temp_dir());
    }

    private static function mutation(?string $permission): Operation
    {
        return new Operation('sync_push', '', static fn (): string => 'ran', mutating: true, permission: $permission);
    }

    public function testAPermissionedMutationJudgedOnTheWebRuns(): void
    {
        $web = ToolContext::web('teacher-1', []);

        self::assertSame('ran', $this->policy->execute(self::mutation('attendance:write'), [], $web, static fn (): string => 'ran'));
    }

    public function testAPermissionedMutationStillRefusesOutsideTheWeb(): void
    {
        $mcp = ToolContext::mcp('req-1', 'teacher-1', []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Mutation 'sync_push' declares no authority for a finite principal.");

        $this->policy->execute(self::mutation('attendance:write'), [], $mcp, static fn (): string => 'ran');
    }

    public function testAMutationThatDeclaresNothingStillRefusesTheWeb(): void
    {
        $web = ToolContext::web('teacher-1', []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Mutation 'sync_push' declares no authority for a finite principal.");

        $this->policy->execute(self::mutation(null), [], $web, static fn (): string => 'ran');
    }
}
