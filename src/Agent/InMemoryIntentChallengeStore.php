<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app INSTALLS, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * An in-process {@see IntentChallengeStore} — the binding lives for one ceremony, in memory.
 */
final class InMemoryIntentChallengeStore implements IntentChallengeStore
{
    /** @var array<string, IntentChallengeBinding> */
    private array $bindings = [];

    /** Record that `$challenge` stands for `$binding`'s call. */
    public function bind(string $challenge, IntentChallengeBinding $binding): void
    {
        $this->bindings[$challenge] = $binding;
    }

    /** Return and REMOVE the binding for `$challenge`, or `null` when there is none. */
    public function take(string $challenge): ?IntentChallengeBinding
    {
        $binding = $this->bindings[$challenge] ?? null;
        unset($this->bindings[$challenge]);

        return $binding;
    }
}
