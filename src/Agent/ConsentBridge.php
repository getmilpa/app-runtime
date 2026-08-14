<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AiGateway\McpClientService;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\AiGateway\OptionTable;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;

/**
 * The caller closing the round trip it was always responsible for.
 *
 * `ai-gateway` says so in its own README: when a tool result requires confirmation the loop stops and
 * returns that outcome, and «the caller (a chat handler, a CLI, a bot) is responsible for the
 * confirm/cancel round trip on the next user turn». Nobody ever wrote that caller. So a human would
 * grant consent through the governed path, the grant would be stored, and the next turn would print
 * «type CONFIRMAR» — a word no line of code receives. Typing it returned the same sentence forever
 * and the operation never ran (greenhouse evidence/0183).
 *
 * Two durable proofs of the same human intention already existed:
 *
 *   ConsentGrant    principal · operation · exact arguments
 *   confirm_token   operation · exact arguments · one-use · expiry
 *
 * They are NOT the same object and are not merged here. **A grant carries authority; a token carries
 * safe continuity of one concrete call.** One answers who authorised what; the other answers which
 * pending attempt may continue, and once. Asking a human for a second yes adds no authority — it
 * makes them repeat a decision the system already recorded with more precision than the tool needs
 * (greenhouse decisions/0031).
 *
 * So this class does not confirm on anybody's behalf. The human already confirmed; this carries that
 * decision to the mechanism that was waiting for exactly it.
 *
 * Four invariants hold here, and the fourth is the one that keeps the other three honest:
 *
 *   Exactness      a grant consumes a token only if it covers this operation AND these arguments
 *   Single use     a grant never makes a token reusable; the store still consumes it once
 *   Expiry         a live grant cannot revive a dead attempt — authority existed, the attempt did not
 *   Attribution    the chain principal → grant → token → call survives the run
 *
 * @internal to app-runtime — surfaces get the projection, never this
 */
final class ConsentBridge extends McpClientService
{
    /** @var list<ConsentGrant> */
    private array $grants;

    private string $channel;

    /** @var list<array{principal: ?string, operation: string, tool: string, arguments: array<string, mixed>, confirm_token: string, provenance: string, session: ?string}> */
    private array $chain = [];

    /**
     * @param list<ConsentGrant> $grants every yes this session has already collected — not the last
     *                                   one. Passing them one at a time overwrote the context and
     *                                   only the most recent survived, so with two authorisations one
     *                                   was lost in silence (tool-runtime v0.10.1).
     */
    public function __construct(
        ToolRegistry $registry,
        array $grants = [],
        ?ToolCallGate $gate = null,
        ?ToolCallRecorder $recorder = null,
        ?OptionTable $table = null,
        string $channel = 'cli',
    ) {
        parent::__construct($registry, $gate, $recorder, $table);
        $this->grants = array_values($grants);
        $this->channel = $channel;
    }

    public function callTool(string $name, array $args): mixed
    {
        // THE SAME EXACTNESS, ONE LAYER EARLIER. `PolicyGate` decides on a `ConsentGrant` too, and it
        // compares that grant against `consent.arguments` — the arguments of the call being judged.
        // A context set once per run cannot carry those: they change with every call. Setting it here
        // is what lets the authority layer ask the same question this class asks, instead of asking a
        // weaker one and calling it agreement.
        //
        // Before this, `consent.arguments` was never populated at all, so a grant with no arguments
        // covered anything the operation name matched. That was not a rule with a hole — it was a
        // rule that had never been given anything to compare.
        $this->setContext(new ToolContext(
            principal: $this->grants[0]->principal ?? null,
            channel: $this->channel,
            extra: [
                'consent.grants' => $this->grants,
                'consent.arguments' => $args,
            ],
        ));

        $result = parent::callTool($name, $args);

        // Only a pending confirmation is this class's business. Everything else — a plain result, a
        // refusal, a failure — travels untouched, because a bridge that reshapes what it carries is
        // not a bridge.
        if (! \is_array($result) || ($result['requires_confirmation'] ?? false) !== true) {
            return $result;
        }

        $token = $result['confirm_token'] ?? null;
        if (! \is_string($token) || $token === '') {
            return $result;
        }

        // EXACTNESS. `covers()` compares operation identity and every argument the human was shown.
        // A grant for config_set(a, true) cannot continue an attempt at config_set(a, false), even
        // when operation, principal and session all match — that is the case this whole design was
        // frozen around.
        $grant = $this->grantThatCovers($name, $args);
        if ($grant === null) {
            // THE TOKEN IS LEFT UNTOUCHED. Not consumed, not refreshed, not remembered: whoever is
            // still owed a question keeps being owed it.
            return $result;
        }

        try {
            // The token names this very call, so it goes back through the ordinary path — gate first,
            // store second. Reaching into the store directly would be this class deciding it is
            // trusted, which is the shape of the defect it exists to remove.
            $executed = parent::callTool($name, ['confirm_token' => $token] + $args);
        } catch (\Throwable $murió) {
            // EXPIRY, and single use, both land here. The store refuses a token it has already
            // consumed or that ran out of time, and refusing is correct: the authority is still
            // valid, this particular attempt is not. The pending result travels on so the next turn
            // can mint a fresh attempt under the same standing yes.
            unset($murió);

            return $result;
        }

        // ATTRIBUTION, recorded only when something actually ran. A chain written before the
        // execution succeeded would claim a call that never happened.
        $this->chain[] = [
            'principal' => $grant->principal,
            'operation' => $grant->operation->canonical,
            'tool' => $name,
            'arguments' => $args,
            'confirm_token' => $token,
            'provenance' => $grant->provenance,
            'session' => $grant->session,
        ];

        return $executed;
    }

    /**
     * The chain, for whoever has to explain in six months why this call landed.
     *
     * @return list<array{principal: ?string, operation: string, tool: string, arguments: array<string, mixed>, confirm_token: string, provenance: string, session: ?string}>
     */
    public function consentChain(): array
    {
        return $this->chain;
    }

    /** @param array<string, mixed> $args */
    private function grantThatCovers(string $tool, array $args): ?ConsentGrant
    {
        foreach ($this->grants as $grant) {
            if ($grant->covers($tool, $args)) {
                return $grant;
            }
        }

        return null;
    }
}
