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

namespace Milpa\AppRuntime\Tests\Auth;

use Milpa\AppRuntime\Auth\CallerActors;
use Milpa\AppRuntime\Auth\HostPermissionPolicy;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\ArrayPermissionCatalog;
use Milpa\Auth\CatalogPermissionResolver;
use Milpa\Auth\Contracts\PermissionResolver;
use Milpa\Auth\PermissionContext;
use Milpa\Auth\PermissionSet;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * The judge MCP and a finite CLI caller answer to: the host's own PermissionResolver, the one its HTTP policy asks.
 *
 * milpa/console 0.22.2 (GHSA-xj7j-99jx-52hh) withholds a permission-typed operation from MCP and refuses it to a
 * finite CLI caller until the host registers an `OperationPermissionPolicy`. This is app-runtime's. It never reads
 * the permission off the caller's scopes by itself: a host that grants permissions by role would be refused again.
 */
final class HostPermissionPolicyTest extends TestCase
{
    private static function read(): Operation
    {
        return new Operation('grades:read', '', static fn (): array => [], permission: 'school.grades:read');
    }

    private static function roles(): PermissionResolver
    {
        return new CatalogPermissionResolver(ArrayPermissionCatalog::fromArray([
            'roles' => ['teacher' => ['label' => 'Teacher', 'permissions' => ['school.grades:read']]],
        ]));
    }

    public function testWithoutAResolverTheCallIsRefusedAndTheRefusalSaysWhatToRegister(): void
    {
        $verdict = (new HostPermissionPolicy(new DIContainer()))->enforce(self::read(), new ToolContext('key:SEAT', 'cli', ['school.grades:read']), []);

        self::assertFalse($verdict->allowed, 'not knowing how to resolve a permission is not allowing it — not even from a matching scope');
        self::assertSame(
            "Operation 'grades:read' requires the permission 'school.grades:read' and this house registers no "
            . PermissionResolver::class . " to resolve it for 'key:SEAT'. Register the resolver the house's "
            . 'OperationHttpPolicy uses under that interface. Nothing ran.',
            $verdict->reason,
        );
    }

    public function testSomethingElseRegisteredAsTheResolverIsNoResolver(): void
    {
        $container = new DIContainer();
        $container->registerService(PermissionResolver::class, new \stdClass());

        $verdict = (new HostPermissionPolicy($container))->enforce(self::read(), new ToolContext('key:SEAT', 'cli', ['school.grades:read']), []);

        self::assertFalse($verdict->allowed);
        self::assertStringContainsString('this house registers no ' . PermissionResolver::class, (string) $verdict->reason);
    }

    public function testTheHostsResolverDecides(): void
    {
        $container = new DIContainer();
        $container->registerService(PermissionResolver::class, new CatalogPermissionResolver(ArrayPermissionCatalog::fromArray([])));
        $judge = new HostPermissionPolicy($container);

        self::assertTrue($judge->enforce(self::read(), new ToolContext('token:a', 'mcp', ['school.grades:read']), [])->allowed);

        $refused = $judge->enforce(self::read(), new ToolContext('token:b', 'mcp', ['agent:run']), []);
        self::assertFalse($refused->allowed);
        self::assertSame("Operation 'grades:read' requires the permission 'school.grades:read', and 'token:b' does not hold it.", $refused->reason);
    }

    public function testTheResolverSeesTheCallerAsAnActorWithItsPrincipalAndScopes(): void
    {
        $seen = null;
        $container = new DIContainer();
        $container->registerService(PermissionResolver::class, new class ($seen) implements PermissionResolver {
            public function __construct(private ?Actor &$seen)
            {
            }

            public function resolve(Actor $actor, PermissionContext $context): PermissionSet
            {
                $this->seen = $actor;

                return new PermissionSet([]);
            }
        });

        (new HostPermissionPolicy($container))->enforce(self::read(), new ToolContext('key:SEAT', 'cli', ['agent:run']), []);

        self::assertInstanceOf(Actor::class, $seen);
        self::assertSame('key:SEAT', $seen->id);
        self::assertSame(['agent:run'], $seen->scopes);
        self::assertSame([], $seen->roles);
    }

    public function testAHostThatGrantsByRoleNamesTheCallersActor(): void
    {
        $container = new DIContainer();
        $container->registerService(PermissionResolver::class, self::roles());
        $judge = new HostPermissionPolicy($container);
        $teacher = new ToolContext('key:TEACHER', 'cli', ['agent:run']);

        self::assertFalse($judge->enforce(self::read(), $teacher, [])->allowed, 'a scope is not a role');

        $container->registerService(CallerActors::class, new class () implements CallerActors {
            public function actorOf(ToolContext $caller): ?Actor
            {
                return $caller->principal === 'key:TEACHER' ? new Actor('key:TEACHER', ActorType::User, $caller->scopes, roles: ['teacher']) : null;
            }
        });

        self::assertTrue($judge->enforce(self::read(), $teacher, [])->allowed, 'the role grants it, with no scope named after the permission');
        self::assertFalse($judge->enforce(self::read(), new ToolContext('key:STRANGER', 'cli', []), [])->allowed, 'an actor the host does not name holds no role');
    }

    public function testACallerWithNoPrincipalHasNobodyToJudge(): void
    {
        $container = new DIContainer();
        $container->registerService(PermissionResolver::class, new CatalogPermissionResolver(ArrayPermissionCatalog::fromArray([])));

        $verdict = (new HostPermissionPolicy($container))->enforce(self::read(), new ToolContext(null, 'mcp', ['*']), []);

        self::assertFalse($verdict->allowed);
        self::assertSame("Operation 'grades:read' requires the permission 'school.grades:read' and the call names no caller to resolve it for.", $verdict->reason);
    }

    public function testAnOperationWithoutAPermissionIsNotThisJudgesToAdmit(): void
    {
        $verdict = (new HostPermissionPolicy(new DIContainer()))->enforce(new Operation('notes:read', '', static fn (): null => null, scopes: ['notes:read']), new ToolContext('token:a', 'mcp', []), []);

        self::assertFalse($verdict->allowed);
        self::assertSame("Operation 'notes:read' declares no permission; its scopes are judged elsewhere.", $verdict->reason);
    }
}
