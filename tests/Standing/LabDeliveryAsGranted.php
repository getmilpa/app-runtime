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

namespace Milpa\AppRuntime\Tests\Standing;

/**
 * A provider whose handler DELEGATES — the idiom of every provider of this house: one line that calls a method of
 * its class — as it was when the grant was given.
 *
 * {@see LabDeliveryAsGranted} and {@see LabDeliveryReplaced} return the very same line as their handler. What a
 * digest of that line can tell apart, and what it cannot, is what the test that uses them measures.
 */
final class LabDeliveryAsGranted
{
    public function __construct(private readonly string $root)
    {
    }

    public function handler(): \Closure
    {
        return fn (array $input): array => $this->deliver($input);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function deliver(array $input): array
    {
        @mkdir($this->root . '/var', 0o755, true);
        file_put_contents($this->root . '/var/lab-delivery.log', 'delivered ' . $input['path'] . "\n", \FILE_APPEND);

        return ['ok' => true, 'acted_on' => 'sha256:' . hash_file('sha256', $this->root . '/' . $input['path'])];
    }
}
