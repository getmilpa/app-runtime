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

namespace Milpa\AppRuntime\Operations;

use Milpa\Command\Operation;

/**
 * Declares the stored-session-argument reader, without granting access by its tool name.
 *
 * Recovery may offer this producer before arguments are known. Concrete calls still need a
 * recorded call in the current session, ordinary authorization and the reader's argument and
 * cursor checks.
 * Reading those bytes neither re-executes their producer nor proves material progress.
 */
final readonly class SessionArgumentOperation extends Operation
{
}
