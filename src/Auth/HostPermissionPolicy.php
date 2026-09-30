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

namespace Milpa\AppRuntime\Auth;

use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\Contracts\PermissionResolver;
use Milpa\Auth\Permission;
use Milpa\Auth\PermissionContext;
use Milpa\Command\Operation;
use Milpa\Console\OperationPermissionPolicy;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;

/**
 * The house's judge of an operation's `permission` on MCP and for a finite terminal caller (GHSA-xj7j-99jx-52hh).
 *
 * milpa/console 0.22.2 withholds a permission-typed operation from MCP, and refuses it to a finite CLI caller, until
 * the host registers an {@see OperationPermissionPolicy}. This one asks what the house's HTTP policy asks: the
 * {@see PermissionResolver} the host registered, for the actor the caller is (greenhouse decisions/0545). Never the
 * caller's scopes on their own — a host that grants permissions by role would refuse its own people again.
 *
 * The resolver and {@see CallerActors} are read from the container on every call, so a host that registers them
 * after the house installed its boundary is still the one asked. A house that registers no resolver refuses, and
 * says what to register: not knowing how to resolve a permission is not allowing it.
 */
final class HostPermissionPolicy implements OperationPermissionPolicy
{
    /** @param DIContainerInterface $container where the host registers its PermissionResolver (and CallerActors) */
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * Allowed when the host's resolver grants the caller the operation's permission; otherwise refused, saying why.
     *
     * @param array<string, mixed> $arguments
     */
    public function enforce(Operation $op, ToolContext $caller, array $arguments): AuthorizationResult
    {
        if ($op->permission === null) {
            return AuthorizationResult::denied("Operation '{$op->name}' declares no permission; its scopes are judged elsewhere.");
        }
        if ($caller->principal === null || $caller->principal === '') {
            return AuthorizationResult::denied("Operation '{$op->name}' requires the permission '{$op->permission}' and the call names no caller to resolve it for.");
        }

        $resolver = $this->container->has(PermissionResolver::class) ? $this->container->get(PermissionResolver::class) : null;
        if (!$resolver instanceof PermissionResolver) {
            return AuthorizationResult::denied(\sprintf(
                "Operation '%s' requires the permission '%s' and this house registers no %s to resolve it for '%s'. "
                . "Register the resolver the house's OperationHttpPolicy uses under that interface. Nothing ran.",
                $op->name,
                $op->permission,
                PermissionResolver::class,
                $caller->principal,
            ));
        }

        $required = Permission::parse($op->permission);
        if ($resolver->resolve($this->actorOf($caller, $caller->principal), PermissionContext::none())->allows($required)) {
            return AuthorizationResult::allowed();
        }

        return AuthorizationResult::denied("Operation '{$op->name}' requires the permission '{$op->permission}', and '{$caller->principal}' does not hold it.");
    }

    /** The actor the host names for this caller, or the caller's own principal and scopes with no role. */
    private function actorOf(ToolContext $caller, string $principal): Actor
    {
        $actors = $this->container->has(CallerActors::class) ? $this->container->get(CallerActors::class) : null;
        $named = $actors instanceof CallerActors ? $actors->actorOf($caller) : null;

        return $named ?? new Actor($principal, ActorType::Service, array_values(array_filter($caller->scopes, 'is_string')));
    }
}
