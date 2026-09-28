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

namespace Milpa\AppRuntime\Agent;

/**
 * A refusal that names the one permission the call lacks (greenhouse decisions/0493).
 *
 * The message stays the sentence every surface already shows; the permission travels beside it as a
 * field, so a reader that has to know WHICH scope is missing asks the judgement, never the text.
 */
final class MissingPermission extends \RuntimeException
{
    /** Carry the refused permission beside the message the surfaces already show. */
    public function __construct(public readonly string $permission, string $message)
    {
        parent::__construct($message);
    }
}
