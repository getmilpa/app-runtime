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

namespace Milpa\Runtime\Http;

// THE HEADERS A BROWSER WOULD HAVE BEEN SENT (greenhouse decisions/0577 §1).
//
// The house is asked in a CLI process, and PHP's CLI drops every `header()`: `headers_list()` answers empty there, so
// the child could say the status of a response and never its type. The runtime's emitter (`ResponseEmitter`, in this
// namespace) sends each header line by calling `header()` unqualified — and PHP looks for a function of that name in
// the caller's namespace first. So the child stands where the emitter speaks and writes down each line. There is
// nobody to pass it on to: this process has no client, and its SAPI would drop the line.
//
// A house that emits with something else is not heard: its receipt says `contentType: null`, and the house then
// claims nothing about what kind of thing answered. And if the runtime ever defines this function itself, this file
// fails loudly instead of listening to nothing.
function header(string $header, bool $replace = true, int $response_code = 0): void
{
    $GLOBALS['__milpaHouseObservedHeaders'][] = $header;
}
