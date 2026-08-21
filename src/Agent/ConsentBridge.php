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

namespace Milpa\AppRuntime\Agent;

use Milpa\AiGateway\McpClientService;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
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

    private ToolRegistry $catalogue;

    private ?ExecutionRecorder $executions;

    private ObservedExecutor $executor;

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
        ?ExecutionRecorder $executions = null,
        ?ObservedExecutor $executor = null,
    ) {
        parent::__construct($registry, $gate, $recorder, $table);
        $this->grants = $grants;
        $this->channel = $channel;
        $this->catalogue = $registry;
        $this->executions = $executions;
        // THE EXECUTOR IS RECEIVED, NEVER FETCHED. This class does not ask the environment who is
        // running: whoever composed it observed that once, when the run began, and handed it over.
        // Reading it here would move the observation closer to the write and further from the act,
        // and the whole point is that a durable fact does not change author according to who reads it.
        $this->executor = $executor ?? ObservedExecutor::unknown();
    }

    /**
     * Run a tool, and — if it comes back asking for a confirmation the human already gave — continue
     * the attempt that was waiting for exactly that authority.
     *
     * Everything else travels untouched: a plain result, a refusal, a failure. A bridge that reshapes
     * what it carries is not a bridge.
     *
     * @param array<string, mixed> $args
     */
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
        // SE PARTE DEL CONTEXTO POR DEFECTO, Y ESO NO ES UN DETALLE — fue una regresión.
        //
        // Cuando nadie pone contexto, `ToolRegistry::call()` usa `ToolContext::cli()`, que otorga
        // `scopes: ['*']`. La primera versión de este puente construía uno DESDE CERO para poder
        // cargar el consentimiento, y con eso le quitaba en silencio TODOS los scopes a TODAS las
        // herramientas: `plugins_simulate`, `plugins_list` y sus hermanas empezaron a fallar con
        // «Missing required scope», publicado en v0.29.0 y vivo hasta v0.32.0.
        //
        // *Poner un contexto donde no había uno no es agregar información: es reemplazar un default
        // que sí decía algo.* Lo que este puente tiene que hacer es AÑADIR el consentimiento, no
        // redefinir quién llama.
        $base = ToolContext::cli();
        $this->setContext(new ToolContext(
            principal: $this->grants[0]->principal ?? $base->principal,
            channel: $this->channel,
            scopes: $base->scopes,
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
            // IT RAN WITHOUT A TOKEN, AND ITS AUTHORITY IS STILL ASKED FOR (greenhouse decisions/0041).
            //
            // This used to declare `null` here on the grounds that an operation demanding no token was
            // an effect nobody authorised. `evidence/0230` measured the price of that reasoning: 12 of
            // 13 mutating operations filed their effect saying no authority covered it — including
            // `foundation.found`, which writes the app's constitution — and the ONLY one that kept its
            // author was the one declaring nothing about its effects. The incentive was backwards: a
            // more precise declaration of effects bought less traceability.
            //
            // The token was never the authority. It binds an attempt — this operation, these
            // arguments, once, before it expires (`decisions/0031`, untouched). The authority is the
            // grant, and the grant is just as durable whichever path the effect profile chose.
            $this->declareIfEffect($name, $args, $this->grantThatCovers($name, $args));

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

        $this->declareIfEffect($name, $args, $grant);

        return $executed;
    }

    /**
     * Writes down that an effect happened — and only when one did.
     *
     * READING IS NOT AN EFFECT. A record of every call is a record of nothing: the value of this fact
     * comes from meaning that something changed, so a tool the catalogue does not call mutating leaves
     * nothing behind.
     *
     * And an ATTEMPT IS NOT A FACT. Both callers below sit after something actually ran; the call that
     * merely minted a token never reaches either, which is the difference `session.tool_called` cannot
     * express — it reports success for asking, so counting effects from it counts two where there was
     * one (greenhouse evidence/0210).
     *
     * @param array<string, mixed> $args
     */
    private function declareIfEffect(string $tool, array $args, ?ConsentGrant $grant): void
    {
        if ($this->executions === null || $this->catalogue->getDefinition($tool)?->mutating !== true) {
            return;
        }

        $this->executions->executed(
            // THE IDENTITY, NOT THE SPELLING. `config_set`, `config:set` and `config.set` are three
            // projections of one operation; the surfaces may keep their spelling, the durable fact
            // may not (greenhouse evidence/0208, decisions/0037).
            (new OperationId($tool))->canonical,
            $this->executor->principal,
            $this->executor->source,
            $grant === null ? null : [
                'principal' => $grant->principal,
                'provenance' => $grant->provenance,
                'session' => $grant->session,
            ],
            self::digest($args),
        );
    }

    /**
     * A reference to the arguments, not a second copy of them.
     *
     * The arguments the human was shown already travel structured in `session.question_asked.why`;
     * writing them again here would be a second inventory of the same truth. Keys are sorted so the
     * same call gives the same digest regardless of the order a caller happened to build them in —
     * otherwise two identical acts would look like two different ones.
     *
     * @param array<string, mixed> $args
     */
    /**
     * The canonical digest of a call's arguments — key-order-stable, so the same call always hashes
     * the same. Public because it is the ONE recipe: a tightened grant (greenhouse decisions/0067)
     * records the exact call it was given over with this digest, and a second recipe elsewhere
     * would let the grant and the execution disagree about whether they are the same call.
     *
     * @param array<string, mixed> $args
     */
    public static function digest(array $args): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (! \is_array($value)) {
                return $value;
            }
            \ksort($value);

            return \array_map($canonical, $value);
        };

        return 'sha256:' . \hash('sha256', (string) \json_encode($canonical($args)));
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
