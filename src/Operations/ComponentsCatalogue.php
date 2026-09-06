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
use Milpa\Command\Declaration\Because;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Declaration\Reads;

/**
 * The UI catalogue: the agent asks what its own face is made of.
 *
 * This house lets an agent WRITE a screen and, until now, gave it no way to ask which components
 * exist or what they are for — so it composed blind over a library the house itself knows. The
 * asymmetry ran the other way for operations, where the catalogue has always been rich.
 *
 * Declared with the intent syntax (greenhouse decisions/0212): the constructor IS the input schema
 * and `run()` IS the handler, so the flag an agent passes and the argument this class takes are one
 * fact, not two.
 */
#[Operation(
    name: 'components:catalogue',
    description: 'The live components this app declares, with each contract\'s props, state, actions and declarer.',
    surfaces: ['cli', 'tui', 'mcp', 'http'],
)]
#[Reads]
final readonly class ComponentsCatalogue
{
    public function __construct(
        #[Because('One component by name; omitted, the catalogue lists every component this app declares')]
        public ?string $component = null,
    ) {
    }

    /**
     * Reads what every booted host declares and answers the catalogue.
     */
    public function run(ComponentDeclarations $declarations): ComponentCatalogue
    {
        return $declarations->catalogue($this->component);
    }
}
