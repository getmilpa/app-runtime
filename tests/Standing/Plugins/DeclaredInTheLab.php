<?php

/**
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing\Plugins;

use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;

/** Lab only: a provider an app declares in `config/plugins.php`, as the HTTP surface reads one — built with the container. */
final class DeclaredInTheLab implements CommandProvider
{
    public function __construct(DIContainerInterface $container)
    {
    }

    public function operations(): array
    {
        return [new Operation('lab:declared', 'Lab only: an operation an app declares', static fn (array $input): array => ['ok' => true], scopes: ['lab:scan'], effects: EffectProfile::readOnly())];
    }
}
