<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Driving the agent is a scoped act (greenhouse decisions/0208, slice 2): the four operations that
 * steer a run — `agent`, `skill:invoke`, `agent:goal`, `agent:mode` — declare `['agent:run']`, so over
 * a surface with a policy an anonymous caller answers 401 and a signed-in one without the scope 403.
 * They stay typed by scope alone (never by permission — `Operation` refuses both), and the `*`
 * wildcard an `identity:bootstrap` root holds keeps admitting them, as `Actor::hasAnyScope` does.
 */
final class AgentRunScopeTest extends TestCase
{
    public function testTheFourOperationsThatDriveTheAgentDeclareAgentRun(): void
    {
        $ops = $this->catalogue();

        foreach (['agent', 'skill:invoke', 'agent:goal', 'agent:mode'] as $name) {
            self::assertArrayHasKey($name, $ops, "{$name} is declared");
            self::assertSame(['agent:run'], $ops[$name]->scopes, "{$name} takes agent:run");
            self::assertNull($ops[$name]->permission, "{$name} is typed by scope, not by permission");
            self::assertTrue($ops[$name]->supportsSurface('http'), "{$name} is the web's to guard");
        }
    }

    public function testTheWildcardStillAdmitsAndAnActorWithoutTheScopeDoesNot(): void
    {
        $agent = $this->catalogue()['agent'];
        $root = new \Milpa\Auth\Actor('passkey:root', \Milpa\Auth\ActorType::User, ['*']);
        $reader = new \Milpa\Auth\Actor('token:ci', \Milpa\Auth\ActorType::Service, ['agent:read']);
        $driver = new \Milpa\Auth\Actor('passkey:cred-1', \Milpa\Auth\ActorType::User, ['agent:run']);

        self::assertTrue($root->hasAnyScope($agent->scopes), 'the bootstrap root opens it');
        self::assertTrue($driver->hasAnyScope($agent->scopes));
        self::assertFalse($reader->hasAnyScope($agent->scopes), 'reading a session is not driving it');
    }

    /** @return array<string, Operation> */
    private function catalogue(): array
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
            'config' => ['app' => ['name' => 'scoped-house']],
        ]);
        $container->registerService(Kernel::class, $kernel);

        $ops = [];
        foreach ([...(new AgentOperations($container))->operations(), ...(new SessionOperations($container))->operations()] as $op) {
            $ops[$op->name] = $op;
        }

        return $ops;
    }
}
