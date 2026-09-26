<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

/**
 * A request may not see the reading a screen is bound to (greenhouse decisions/0484). The page answers the
 * status — 401 without a principal, 403 without the scope — and not one value of the reading.
 */
final class ReadingDenied extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reading)
    {
        parent::__construct($status === 401
            ? "the reading «{$reading}» is shown only to the house's members; sign in"
            : "the reading «{$reading}» is shown only to a principal with its scope");
    }
}
