<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Fixtures;

use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/** A small real operation whose writes reveal whether the execution boundary was imposed. */
final class AuthoringOperations implements CommandProvider
{
    /** @return list<Operation> */
    public function operations(): array
    {
        return [new Operation(
            name: 'make',
            description: 'Fixture authoring',
            handler: [self::class, 'write'],
            mutating: true,
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Executable)
        )];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function write(array $input): array
    {
        if ($input['fail'] ?? false) {
            return ['ok' => false, 'error' => 'fixture refused its behavior'];
        }
        if (!($input['empty'] ?? false)) {
            file_put_contents('src/Plugins/Owned/control.txt', 'owned');
        }
        return ['ok' => true];
    }
}
