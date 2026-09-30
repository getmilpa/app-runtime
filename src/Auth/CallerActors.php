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
use Milpa\ToolRuntime\Contracts\ToolContext;

/**
 * Who a caller of MCP or the terminal is, as the host's permission resolver knows them — roles included.
 *
 * Over HTTP the host's credential verifier builds the Actor its resolver reads, roles and all. A caller of MCP or
 * of the terminal arrives as a `ToolContext`: a principal and its scopes, nothing more. A host that grants
 * permissions by role registers this under its interface so {@see HostPermissionPolicy} hands its resolver the
 * same actor it would have met over HTTP. Without it the actor carries the caller's principal and scopes and no
 * role.
 */
interface CallerActors
{
    /**
     * The actor this caller is, or null when the host does not know them (then the caller's own principal and
     * scopes are the actor).
     */
    public function actorOf(ToolContext $caller): ?Actor;
}
