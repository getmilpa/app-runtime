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

use Milpa\Live\Contracts\Component\ComponentRegistryInterface;

/** The registry the live door serves and the names it registered — a registry has no way to list itself. */
final readonly class LiveComponents
{
    /** @param list<string> $names */
    public function __construct(
        public ComponentRegistryInterface $registry,
        public array $names,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->names;
    }
}
