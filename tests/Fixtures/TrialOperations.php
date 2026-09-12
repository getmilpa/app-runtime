<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Fixtures;

use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;

/** Real declarations used by the shipped trial runner regression. */
final class TrialOperations implements CommandProvider
{
    /** @return list<Operation> */
    public function operations(): array
    {
        return [
            new Operation(name: 'trial.instance', description: 'Write in the trial', handler: [self::class, 'write']),
            new Operation(name: 'trial.closure', description: 'Return the input', handler: static fn (array $input): array => $input),
        ];
    }

    /** @param array{path: string} $input @return array{written: bool} */
    public function write(array $input): array
    {
        return ['written' => file_put_contents($input['path'], "trial\n") !== false];
    }
}
