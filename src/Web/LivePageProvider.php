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

use Psr\Http\Message\ServerRequestInterface;

/**
 * The seam that keeps DOMAIN DATA in the app while the framework keeps OWNERSHIP: the app registers a
 * provider that hands the props for a live component page; the shipped {@see Controllers\LiveComponentPageController}
 * renders it bound to the request's verified actor (greenhouse decisions/0092). The framework never invents a
 * component's data, and the app never touches the `actor:` format — each owns its half.
 */
interface LivePageProvider
{
    /**
     * The props to render `$component` with for this request, or null when this provider does not serve it
     * (the page controller answers 404, never a page with invented data).
     *
     * @return array<string, mixed>|null
     */
    public function propsFor(string $component, ServerRequestInterface $request): ?array;
}
