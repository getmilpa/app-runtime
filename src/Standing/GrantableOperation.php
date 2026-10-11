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

namespace Milpa\AppRuntime\Standing;

use Milpa\Command\Consent\OperationId;
use Milpa\Command\Operation;
use Milpa\Command\Standing\StandingGrant;

/**
 * Whether an operation is one a standing grant may lend at all (greenhouse decisions/0615).
 *
 * Asked when a person gives a grant AND at every call under it: an operation is code, and code is
 * replaced — an update, a plugin registered again under the same name. A floor checked only on the day
 * of the grant holds for the operation that existed that day.
 *
 * THE FLOOR «NEVER AN OPERATION THAT ITSELF GIVES AUTHORITY» IS ASKED OF THE AXIS THAT SAYS IT. A list of
 * words missed most of them: measured on the published catalogue, thirteen operations declare
 * institutional authority and four ask for the word that recognizes identities. The word is still
 * refused, and so is `*`; the axis is what decides.
 */
final class GrantableOperation
{
    /**
     * Why no grant may lend this operation — or `null`: one may.
     */
    public static function refusal(Operation $operation): ?string
    {
        $name = (new OperationId($operation->name))->forCli();
        if (\in_array('*', $operation->scopes, true)) {
            return "`{$name}` asks for every scope: lending its words would lend the house";
        }
        if (\in_array(StandingBook::AUTHORITY, $operation->scopes, true)) {
            return \sprintf('`%s` asks for `%s`: it is an operation that itself gives authority, and no grant lends one. It stays a person\'s act, signed each time', $name, StandingBook::AUTHORITY);
        }
        // The axis is asked BEFORE what the operation happens to lack: an operation that gives authority is refused
        // for being one, not for a declaration somebody could add tomorrow.
        $effects = $operation->effectCeiling();
        if (!$effects->isFullyClassified()) {
            return "`{$name}` does not declare its effects, so no call of it could ever be shown to fit under a ceiling";
        }
        if ($effects->authority->weight() > StandingGrant::CEILING_AUTHORITY->weight()) {
            return \sprintf('`%s` declares authority «%s»: it is an operation that itself gives authority, and no grant lends one. It stays a person\'s act, signed each time', $name, $effects->authority->value);
        }
        if ($operation->scopes === []) {
            return "`{$name}` declares no scope, so there is nothing for a grant to lend: a seat either runs it already or is judged by something else";
        }
        if ($operation->actsOn === null) {
            return "`{$name}` does not say what content a call acts on (it declares no `actsOn`), so no condition could ever be bound to one of its calls";
        }
        if ($operation->inputSchema === null) {
            return "`{$name}` declares no input schema, so a call of it cannot be told from another before it runs — and a grant lends to the call it names";
        }
        if (self::isHandedItsCallersAuthority($operation)) {
            return "`{$name}` is handed its caller's authority and may carry it on to other calls: a word lent for one call must reach no other, so no grant lends to it";
        }
        if ($operation->instrumentDigest() === null) {
            return "`{$name}` cannot be pinned: its handler cannot be read, so the house could not tell the day it stops being the operation that was granted";
        }

        return null;
    }

    /**
     * The words of this operation a grant may lend: what it declares, never the ones that are authority themselves.
     *
     * @return list<string>
     */
    public static function lendable(Operation $operation): array
    {
        return array_values(array_filter($operation->scopes, static fn (string $scope): bool => $scope !== '*' && $scope !== StandingBook::AUTHORITY));
    }

    /**
     * Whether the handler receives the tool authority of whoever calls it — the third argument a driver is handed
     * to pass to its own door. A handler that cannot be read is taken to: not being able to look is not having looked.
     */
    public static function isHandedItsCallersAuthority(Operation $operation): bool
    {
        try {
            $handler = $operation->handler;
            $reflection = \is_array($handler) && !\is_callable($handler)
                ? new \ReflectionMethod($handler[0], $handler[1])
                : new \ReflectionFunction(\Closure::fromCallable($handler));
        } catch (\Throwable) {
            return true;
        }

        return $reflection->isVariadic() || $reflection->getNumberOfParameters() > 2;
    }
}
