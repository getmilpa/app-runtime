<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\Principal;

/**
 * The identity the channel could observe of whoever materialised an effect — and where it saw it.
 *
 * This does NOT say "this principal had authority". It says something narrower and honest: at the
 * moment of running the operation, this was the actor the channel could see. Authority is decided
 * elsewhere, from this assertion plus a verifiable context plus policy; conflating the two is how an
 * observation becomes an investiture.
 *
 * `source` is not decoration. Deriving a principal from the environment is legitimate for a CLI —
 * there the environment IS the only identity available — but it may never rise silently from
 * "environment observation" to "historical principal". Saying where it came from is what keeps a
 * later reader able to weigh it.
 *
 * A null principal is a valid, complete answer: `unknown`, with its provenance. An honest gap is worth
 * something; a principal reconstructed at read time is false evidence with better typography.
 *
 * Grounded in the greenhouse: `decisions/0036`, `decisions/0037`, `evidence/0209`.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
final readonly class ObservedExecutor
{
    public const UNKNOWN = 'unknown';

    public const TERMINAL = 'terminal-environment';

    public function __construct(
        public ?Principal $principal,
        public string $source = self::UNKNOWN,
    ) {
    }

    /**
     * Nobody was observable, and that is said rather than filled in.
     *
     * It exists so the empty case has a name: a caller that has nothing to declare should reach for
     * this instead of inventing a principal, and a reader who finds it knows the gap was deliberate.
     */
    public static function unknown(): self
    {
        return new self(null, self::UNKNOWN);
    }
}
