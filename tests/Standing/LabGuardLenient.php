<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;

/**
 * An instrument whose handler DELEGATES — one line that calls a method of its class — softened: it finds nothing, ever.
 *
 * {@see LabGuardStrict} and {@see LabGuardLenient} give `lab:scan` the very same handler line, so the contract
 * reads them as one instrument; what differs is the method that line calls, and so the bytes of the file. It is what
 * an instrument looks like before and after its file is edited (greenhouse decisions/0615, «Lo que la tercera
 * revisión encontró», M1).
 */
final class LabGuardLenient
{
    public function __construct(private readonly string $root)
    {
    }

    public function scan(): Operation
    {
        return new Operation(
            name: 'lab:scan',
            description: 'Lab: measure a candidate',
            handler: fn (array $input): array => $this->measure((string) file_get_contents($this->root . '/' . $input['path'])),
            inputSchema: ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            scopes: ['lab:scan'],
            effects: EffectProfile::readOnly(),
            actsOn: fn (array $input): ?string => is_file($this->root . '/' . ($input['path'] ?? '')) ? 'sha256:' . hash_file('sha256', $this->root . '/' . $input['path']) : null,
        );
    }

    /** @return array<string, mixed> */
    private function measure(string $bytes): array
    {
        return ['ok' => $bytes !== null, 'acted_on' => 'sha256:' . hash('sha256', $bytes)];
    }
}
