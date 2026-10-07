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

namespace Milpa\AppRuntime\Agent;

/**
 * How one call of work in the domain runs in the house (greenhouse decisions/0588).
 *
 * Exactly one of three things is true of a plan: it is `refused` (its state is not a place for state: nothing
 * runs); it `asks` a person first (the house cannot confine it, or cannot undo it); or neither, and it runs
 * confined to `state` with a pre-image kept.
 */
final readonly class WorkPlan
{
    /**
     * @param list<string> $state    the paths the call may write, relative to the house root; empty when unknown
     * @param string       $source   who said so: `declared` by the plugin, or the `entities` it registers
     * @param ?string      $refused  why this call never runs, or null
     * @param ?string      $asks     why a person is asked before this call runs, or null
     * @param bool         $confined whether the call runs in the confined child: read-only root, no network, only `state` to write
     * @param bool         $preImage whether the house keeps what the state was before the call
     */
    public function __construct(
        public string $operation,
        public array $state,
        public string $source,
        public ?string $refused = null,
        public ?string $asks = null,
        public bool $confined = false,
        public bool $preImage = false,
    ) {
    }
}
