<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Fixtures;

use Milpa\Plugin\Contracts\BootWitnessInterface;

/** A witness that answers what it was told to, and remembers what it was shown. */
final class RecordingWitness implements BootWitnessInterface
{
    /** @var array<string, string> */
    public array $asked = [];

    public function __construct(private readonly ?string $answer)
    {
    }

    public function writeIfItBoots(array $writes, callable $commit, bool $recovery = false, array $deletes = []): array
    {
        $this->asked += $writes;
        if ($this->answer !== null) {
            // Sorted, as HouseBootWitness names them.
            $paths = array_keys($writes);
            sort($paths);

            return ['refused' => $this->answer, 'said' => ['unwritten' => $paths, 'house_boots' => true]];
        }
        $commit();

        return ['refused' => null, 'said' => ['house_boots' => true]];
    }
}
