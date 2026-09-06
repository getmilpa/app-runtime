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

namespace Milpa\AppRuntime\Operations;

use Milpa\AppRuntime\Web\ComponentDeclarations;
use Milpa\Command\CommandProvider;
use Milpa\Command\Declaration\DeclaredOperation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;

/**
 * Offers the UI catalogue, and offers nothing when there is no UI to catalogue.
 *
 * The constructor takes the CONTAINER and only the container: the host fills a provider's
 * constructor slot itself, so a typed collaborator there type-errors in every real app while every
 * unit test passes — measured, and paid for once already (greenhouse evidence/0529). The
 * collaborator is built here and closed over instead.
 */
final class ComponentOperations implements CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * The operations this provider offers — none at all without `milpa/live`.
     *
     * @return list<\Milpa\Command\Operation>
     */
    public function operations(): array
    {
        if (!interface_exists(ComponentDefinitionInterface::class)) {
            return [];
        }

        $declarations = new ComponentDeclarations($this->container);

        return [
            DeclaredOperation::from(
                ComponentsCatalogue::class,
                static fn (string $type): object => $declarations,
            ),
        ];
    }
}
