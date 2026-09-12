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

use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\AgentTable;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Auth\PresentedToken;
use Milpa\Auth\AuthContext;
use Milpa\AppRuntime\Agent\ContractProducer;
use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\AppRuntime\Agent\SessionIdentity;
use Milpa\AppRuntime\Agent\SessionGrants;
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
use Milpa\ToolRuntime\Contracts\ToolContext;
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
    /**
     * Opens the governed door one sequence originates every call through.
     *
     * The registry is projected from what this app OFFERS (`AgentTable::offers`), so a step naming an
     * operation the app does not offer reaches the gate as unjudgeable and fails closed rather than
     * running unjudged.
     */
    public static function open(
        Kernel $kernel,
        string $root,
        SessionStore $store,
        Session $session,
        string $petition,
        ?InvocationContext $context = null,
        ?ToolContext $authority = null,
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

        // WHEN A STEP MAKES THE APP GROW (`capabilities:enable` writes a provider into config/operations.php),
        // the next step may name an operation this door was not born with. Asked for a tool the registry does
        // not know, the door folds the catalogue again, projects what is new, and tells the gate — reading
        // the app as it is now, never inventing: a tool the app still does not offer stays unjudgeable.
        // A memo on the DECLARED list: the app grows by a line in config/operations.php, so the fold repeats
        // only when that file changed — a model naming a tool that does not exist pays nothing per step.
        $declared = $root . '/config/operations.php';
        $stamp = static fn (): string => is_file($declared) ? (string) hash_file('xxh128', $declared) : '';
        $folded = $stamp();
        $grown = static function (string $tool) use ($kernel, $root, $registry, $gate, $stamp, &$folded): void {
            if ($registry->getDefinition($tool) !== null) {
                return;
            }
            $now = $stamp();
            if ($now === $folded) {
                return;
            }
            $folded = $now;
            $all = Operations::all($kernel, $root);
            $fresh = array_values(array_filter(
                $all,
                static fn (Operation $op): bool => AgentTable::offers($op) && $registry->getDefinition(McpProjector::toolName($op->name)) === null,
            ));
            if ($fresh !== []) {
                (new McpProjector())->projectAll($fresh, $registry, $kernel->container());
            }
            $gate->sees($all);
        };

        return new ConsentBridge(
            $registry,
            // THE GRANTS THE HUMAN ALREADY GAVE, or the tool-runtime gate refuses the very step the session
            // just allowed. Measured in the browser ceremony (greenhouse evidence/0561): `permission_granted`
            // for `config:set` was in the ledger and the resume still answered «needs explicit consent —
            // none was presented», because this door handed an empty list while the agent's door derived
            // the session's. Same derivation now, for both.
            grants: SessionGrants::of($session->decisions, $session->id, new \DateTimeImmutable(), $offered),
            gate: $gate,
            recorder: $gate,
            executions: $gate,
            executor: self::observedExecutor($context),
            grown: $grown,
            // THE SAME IDENTITY DERIVATION AS THE AGENT'S DOOR. Two doors deciding the caller's scopes
            // differently is the shape of the regression this seam exists to avoid — one site kept the
            // default and the other replaced it (greenhouse decisions/0311).
            identity: $authority === null ? self::presented($kernel) : null,
            authority: $authority,
        );
    }


    /**
     * The identity this caller presented, or null when it presented none — and null when we cannot ask.
     *
     * 🚨 THE KERNEL ENTERS ITS CONTAINER AFTER THE BOOT, so `container()` on an unbooted kernel is an
     * UNINITIALISED TYPED PROPERTY and throws, not an empty container that answers `has()` with false
     * (greenhouse evidence/0294, measured again here by a test that builds this door without booting).
     * A door asked who is calling must not be the thing that fails, and the answer when nobody can be
     * asked is the same as the answer when nobody presented anything: the default scopes stand.
     */
    private static function presented(Kernel $kernel): ?AuthContext
    {
        try {
            $container = $kernel->container();
        } catch (\Throwable) {
            return null;
        }

        return PresentedToken::identity($container);
    }

    /** Who materialises the steps — read from the invocation, the one derivation every door shares. */
    private static function observedExecutor(?InvocationContext $context): ObservedExecutor
    {
        return ObservedExecutor::fromContext($context);
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
