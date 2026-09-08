<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\Principal;
use Milpa\Command\InvocationContext;

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

    /**
     * Who is materialising effects, read from the invocation that asked — for EVERY door.
     *
     * A turn that arrived over HTTP with a passkey session carries its actor in the context; that actor
     * is the executor, verified as the door verified it. A terminal carries none, and the operator at the
     * keyboard is the honest answer. Anything else — a context with no actor over a channel that is not a
     * terminal — is unknown, and says so. The sequence door derived this already; the agent's door wrote
     * the terminal regardless of the door the turn came through, so a tool the agent ran on the human's
     * word over HTTP was attributed to the process (greenhouse evidence/0561). One derivation now.
     */
    public static function fromContext(?InvocationContext $context): self
    {
        if ($context?->actor !== null && $context->actor !== '') {
            return new self(new Principal($context->actor, $context->verified), $context->channel);
        }

        if ($context === null || $context->channel === 'cli') {
            return new self(Principal::fromTerminal(getenv('USER') ?: null, gethostname() ?: null), self::TERMINAL);
        }

        return self::unknown();
    }
}
