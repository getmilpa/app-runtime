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

/** A declaration cannot be served intact; the path identifies the failing node (Greenhouse 0326). */
final class InvalidScreenTree extends \InvalidArgumentException
{
    public function __construct(public readonly string $path, string $reason)
    {
        parent::__construct($reason);
    }
}
