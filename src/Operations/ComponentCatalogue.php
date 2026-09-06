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

/**
 * What {@see ComponentsCatalogue} answers: the live components this app declares.
 *
 * A value object rather than an array because the return TYPE is the output schema under the
 * declared syntax (greenhouse decisions/0212) — an `array` return derives nothing, so an agent
 * reading this catalogue would be told the shape is unknown when it is not.
 */
final readonly class ComponentCatalogue
{
    /**
     * @param bool                       $ok         Whether the catalogue could be gathered at all.
     * @param int                        $total      How many component names the answer carries.
     * @param list<array<string, mixed>> $components One row per component name, sorted by name.
     * @param list<string>               $sources    The declarers consulted, by class.
     * @param list<string>               $cannotSay  What could not be determined for the catalogue AS A
     *                                               WHOLE; a per-component gap rides in that row instead.
     * @param string|null                $error      Why nothing could be gathered, when `$ok` is false.
     */
    public function __construct(
        public bool $ok,
        public int $total,
        public array $components,
        public array $sources,
        public array $cannotSay = [],
        public ?string $error = null,
    ) {
    }
}
