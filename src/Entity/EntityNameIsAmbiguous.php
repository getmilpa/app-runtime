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

namespace Milpa\AppRuntime\Entity;

/**
 * A short name more than one entity carries: the house names them all and picks none (decisions/0472).
 */
final class EntityNameIsAmbiguous extends \RuntimeException
{
    /** @param list<string> $candidates each written `Plugin/Entity` — the form that tells them apart */
    public function __construct(public readonly string $name, public readonly array $candidates)
    {
        parent::__construct("«{$name}» names more than one entity: " . implode(', ', $candidates) . '; name one of them');
    }
}
