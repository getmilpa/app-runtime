<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Fixtures;

use Milpa\AppRuntime\Support\AcceptedOnTheConsole;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;

/**
 * Two reads that say what reached them: the input the console derived, and the words the console was holding while
 * the call ran. One carries the name the console reads the acceptance for; the other is any other operation.
 */
final class WhatTheConsoleHeldOperations implements CommandProvider
{
    public const string OUT_ENV = 'MILPA_TEST_WHAT_THE_CONSOLE_HELD';

    /** @return list<Operation> */
    public function operations(): array
    {
        $said = static function (array $input): array {
            file_put_contents((string) getenv(self::OUT_ENV), (string) json_encode(['input' => $input, 'words' => AcceptedOnTheConsole::words()]));

            return ['ok' => true];
        };

        return [
            new Operation(name: 'capabilities:enable', description: 'Says what reached it', handler: $said),
            new Operation(name: 'words:probe', description: 'Says what reached it', handler: $said),
        ];
    }
}
