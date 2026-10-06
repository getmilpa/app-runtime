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

require \dirname(__DIR__) . '/vendor/autoload.php';

// Before any test: the suite's git reads nothing of the machine and reaches no signing key.
Milpa\AppRuntime\Tests\Suite\GitOfTheSuite::isolate();
