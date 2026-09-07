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

namespace Milpa\AppRuntime\Sequence;

use Milpa\Agent\Principal;
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\AgentTable;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\ContractProducer;
use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\AppRuntime\Agent\SessionIdentity;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityConfig;
use Milpa\AppRuntime\Policy\PolicyConfig;
use Milpa\AppRuntime\Support\Operations;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\ToolRuntime\Identity\GnupgSignatureVerifier;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;

/**
 * THE ONE GOVERNED DOOR a declared sequence originates its calls through.
 *
 * It was written inside `RecipeOperations` and is not about recipes: it is the same `ConsentBridge` over
 * a `SessionToolGate` that `AgentOperations::ask` originates every tool call through, so a sequence — a
 * recipe's, a deployment's — is judged step by step exactly as an agent's calls are. A recipe and a
 * deployment share this MOTOR and not their meaning (greenhouse decisions/0223), so the door lives here
 * and the meaning lives in the operation that names the list.
 */
final class GovernedDoor
{
    public static function open(
        Kernel $kernel,
        string $root,
        SessionStore $store,
        Session $session,
        string $petition,
        ?InvocationContext $context = null,
    ): ConsentBridge {
        $registry = new ToolRegistry(new NullLogger());
        $offered = array_values(array_filter(
            Operations::all($kernel, $root),
            static fn (Operation $op): bool => AgentTable::offers($op),
        ));
        $missing = array_values(array_filter(
            $offered,
            static fn (Operation $op): bool => $registry->getDefinition(McpProjector::toolName($op->name)) === null,
        ));
        if ($missing !== []) {
            (new McpProjector())->projectAll($missing, $registry, $kernel->container());
        }

        $provider = PolicyConfig::load($root);
        // Admission exists when there is ANY basis for recognition: a declared PolicyProvider, or a
        // declared out-of-band root that enrollment consumes (greenhouse decisions/0117, evidence/0375).
        $rooted = IdentityConfig::load($root);
        $enrollments = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        // Admission exists when there is ANY basis for recognition: a declared PolicyProvider, a declared
        // out-of-band root, OR standing enrollments — a key bootstrapped or enrolled into the store must be
        // admissible even with an empty config root and no policy (greenhouse decisions/0117, evidence/0384).
        $identity = ($provider === null && $rooted->isEmpty() && $enrollments->isEmpty()) ? null : new SessionIdentity(
            new GnupgSignatureVerifier(),
            $provider,
            $enrollments,
        );

        $gate = new SessionToolGate(
            $store,
            $session,
            Operations::all($kernel, $root),
            petition: $petition,
            policyProvider: $provider,
            identity: $identity,
            // PARITY WITH `AgentOperations::nuevaCompuerta` (greenhouse decisions/0078): without
            // these, the gate resolves the session's own notebook and delegation tools to «no
            // Operation and no producer», which is the genuinely-UNJUDGEABLE case it fails closed
            // on — safe, but not what a recipe's internal producer tools deserve. Wiring them here
            // lets the gate judge them by their declared contract, exactly as the agent gate does.
            contractProducers: self::contractProducers($store, $session->id),
        );

        return new ConsentBridge(
            $registry,
            grants: [],
            gate: $gate,
            recorder: $gate,
            executions: $gate,
            executor: self::observedExecutor($context),
        );
    }

    /**
     * WHO IS OBSERVABLY RUNNING THIS, read from the invocation instead of from the environment.
     *
     * This used to build `Principal::fromTerminal(getenv('USER'), gethostname())` unconditionally, which
     * is right on a terminal and false everywhere else: over HTTP it writes `cli:www-data@host,
     * verified:false` into the ledger for EVERY step of the sequence — a chain of custody that names the
     * server process as the operator. {@see \Milpa\AppRuntime\Agent\ObservedExecutor} says it in its own
     * docblock: «a principal reconstructed at read time is false evidence with better typography», and it
     * ships `unknown()` so the empty case has a name.
     *
     * Three honest answers, and no fourth:
     *   · an actor the surface authenticated → that principal, with the channel as its provenance;
     *   · no actor and a terminal → the process running it, which IS observable there;
     *   · no actor anywhere else → `unknown()`, because inventing one is the defect this fixes.
     */
    private static function observedExecutor(?InvocationContext $context): ObservedExecutor
    {
        if ($context?->actor !== null && $context->actor !== '') {
            return new ObservedExecutor(new Principal($context->actor, $context->verified), $context->channel);
        }

        if ($context === null || $context->channel === 'cli') {
            return new ObservedExecutor(
                Principal::fromTerminal(getenv('USER') ?: null, gethostname() ?: null),
                ObservedExecutor::TERMINAL,
            );
        }

        return ObservedExecutor::unknown();
    }

    /**
     * The authorized producers whose tools reach this gate without an app `Operation` behind them —
     * the session's own notebook and delegation. Mirrors `AgentOperations::contractProducers()`
     * exactly (greenhouse decisions/0078): built from THIS session's id, so the recipe path judges
     * its internal producer tools by their declared contract instead of allowing them by name — the
     * same seam the agent gate has always had. The runner throws by construction: a gate RESOLVES
     * contracts here, it never runs a child.
     *
     * @return list<ContractProducer>
     */
    private static function contractProducers(SessionStore $store, string $sessionId): array
    {
        $producers = [new SessionBookkeeping($store, $sessionId)];

        if (class_exists(SubAgentSpawner::class)) {
            $producers[] = new SubAgentSpawner(
                $store,
                $sessionId,
                static fn (): array => throw new \LogicException('a gate resolves contracts; it does not run a child'),
            );
        }

        return $producers;
    }
}
