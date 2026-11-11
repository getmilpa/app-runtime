<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Fixtures;

/**
 * A plugin that requires the data capability, so the doctor recommends `milpa/data` and `coa repair milpa/data` reaches
 * its runner. A house lists it only where it is already loaded ({@see TinyHouse}): the test's process has it, a fresh
 * boot of the house does not — so the house still boots and the stage's own answer comes back.
 */
#[\Milpa\Attributes\PluginMetadata(version: '0.1.0', author: 'lab', site: 'https://example.test', name: 'needs-data', type: 'Service', requires: [['id' => 'data.repository', 'interface' => 'Milpa\Data\RepositoryInterface', 'version' => '^1.0']])]
final class NeedsTheDataCapability
{
}
