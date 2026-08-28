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

namespace Milpa\AppRuntime\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\AgentTable;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\ContractProducer;
use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\AppRuntime\Agent\SessionIdentity;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityConfig;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\AppRuntime\Policy\PolicyConfig;
use Milpa\AppRuntime\Recipe\Recipe;
use Milpa\AppRuntime\Recipe\RecipeDriver;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\Foundation;
use Milpa\AppRuntime\Support\Operations;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Identity\GnupgSignatureVerifier;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;

/**
 * The `recipe:apply` capability: read a declared recipe from `recipes/<name>.json` and drive it
 * through the ONE governed door, pausing for consent and resuming a persisted pause. It is the
 * container glue — it resolves the app root, builds the SAME governed executor and session store
 * `AgentOperations::ask` uses, and hands the pure orchestration to {@see RecipeDriver}, which holds
 * every decision this class must not: what a step means, where it pauses, how a pause is persisted.
 */
final class RecipeOperations implements CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * The one operation this group contributes: `recipe:apply`, declared at the ceiling of what it
     * can originate.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'recipe:apply',
                effects: new EffectProfile(
                    // THE CEILING OF WHAT IT ORIGINATES, not of any single step. A recipe can found a
                    // domain, install packages off a registry, and make durable artifacts — so this
                    // declares the widest reach any of those reach, and the per-step gate still judges
                    // each call on its own effects as the sequence runs.
                    Mutation::Persistent,
                    // It can install a capability, which downloads code from a package registry.
                    Externality::ThirdParty,
                    // `composer remove` and a founded `.milpa/` are not tested inverses: recovery is
                    // manual, so the ceiling says so.
                    Reversibility::ManualRecovery,
                    // It can change WHAT THIS APP CAN DO, the same authority `capabilities:enable` spends.
                    Authority::Privileged,
                    subject: Subject::Executable,
                ),
                description: 'Apply a declared recipe — found, enable and make in one governed sequence, pausing for consent',
                handler: fn (array $input): array => $this->apply($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'recipe' => [
                            'type' => 'string',
                            'description' => 'The recipe to apply — read from recipes/<recipe>.json under the app root',
                        ],
                        'session' => [
                            'type' => 'string',
                            'description' => 'Continue (or resume) this session — without it, a stable id derived from the recipe name is used',
                        ],
                    ],
                    'required' => ['recipe'],
                ],
                // MUTATING, AND THE HUMAN NAMES THE TARGET. Applying a recipe changes the app exactly
                // as `repair` and `capabilities:enable` do, so it carries their contract of intent: the
                // recipe the request applies must be named in the request, not chosen for the operator.
                mutating: true,
                namedTarget: 'recipe',
                surfaces: ['cli', 'tui', 'mcp'],
            ),
        ];
    }

    /**
     * Reads the named recipe, opens (or resumes) its governed session, and drives it.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function apply(array $input): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (! $kernel instanceof Kernel) {
            return ['ok' => false, 'error' => 'no kernel: recipe:apply needs a booted app'];
        }
        $root = $kernel->root();

        $name = \is_string($input['recipe'] ?? null) ? trim($input['recipe']) : '';
        if ($name === '') {
            return ['ok' => false, 'error' => 'name a recipe: recipe:apply reads recipes/<recipe>.json'];
        }

        $file = $root . '/recipes/' . $name . '.json';
        if (! is_file($file)) {
            return ['ok' => false, 'error' => "no recipe at recipes/{$name}.json"];
        }

        try {
            $decoded = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'error' => "recipes/{$name}.json is not valid JSON: " . $e->getMessage()];
        }
        if (! \is_array($decoded)) {
            return ['ok' => false, 'error' => "recipes/{$name}.json must be a JSON object"];
        }

        $recipe = Recipe::fromArray($name, $decoded);

        // THE SAME STORE agent sessions live in, so a pause is recorded where a session already is —
        // never a second truth about what happened (mirrors SessionOperations exactly).
        $store = (new AgentOperations($this->container))->sessionStore();
        if ($store === null) {
            return ['ok' => false, 'error' => 'no session store: install milpa/agent so a pause can be recorded'];
        }

        $sessionId = \is_string($input['session'] ?? null) && trim($input['session']) !== ''
            ? trim($input['session'])
            : 'recipe:' . $name;

        $petition = "apply recipe {$name}";

        $existing = $store->load($sessionId);
        $resuming = $existing?->pausedSequence !== null;

        if (! $resuming && $existing === null) {
            // AutonomyMode::Ask: the sequence pauses before any mutation until a human grants it.
            $store->start($sessionId, $petition, AutonomyMode::Ask);
        }

        $session = $store->load($sessionId);
        if ($session === null) {
            return ['ok' => false, 'error' => 'could not open a session to govern the recipe'];
        }

        $executor = $this->governedExecutor($kernel, $root, $store, $session, $petition);
        $driver = new RecipeDriver();

        if ($resuming) {
            return $driver->resume($store, $sessionId, $executor);
        }

        $verdict = static function () use ($root): array {
            $v = Foundation::verdict($root);

            return ['verdict' => $v['verdict'], 'domain' => $v['foundation']['domain'] ?? null];
        };
        $installed = static fn (): array => array_keys(Capabilities::declaredBy());

        return $driver->apply($recipe, $executor, $store, $sessionId, $verdict, $installed);
    }

    /**
     * Builds the governed door recipe:apply drives — the SAME `ConsentBridge` over a `SessionToolGate`
     * that `AgentOperations::ask` originates every tool call through.
     *
     * A private method cannot be shared across provider classes, so the wiring is reproduced here
     * rather than reached: a fresh registry carrying this app's projected operations, the session
     * gate judging against the app's declared operations under its policy and identity, and the
     * observed terminal executor materialising the effects.
     */
    private function governedExecutor(
        Kernel $kernel,
        string $root,
        SessionStore $store,
        Session $session,
        string $petition,
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
            contractProducers: $this->contractProducers($store, $session->id),
        );

        return new ConsentBridge(
            $registry,
            grants: [],
            gate: $gate,
            recorder: $gate,
            executions: $gate,
            executor: new ObservedExecutor(
                Principal::fromTerminal(getenv('USER') ?: null, gethostname() ?: null),
                ObservedExecutor::TERMINAL,
            ),
        );
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
    private function contractProducers(SessionStore $store, string $sessionId): array
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
