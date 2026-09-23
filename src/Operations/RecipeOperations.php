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
use Milpa\Agent\Session;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Recipe\Recipe;
use Milpa\AppRuntime\Recipe\RecipeDriver;
use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\AppRuntime\Sequence\GovernedDoor;
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
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;

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
                handler: fn (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null): array => $this->apply($input, $context, $authority),
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
                // The scope, for the reason `capabilities:enable` carries one: applying a recipe writes
                // code into the app, its consent is DERIVED from its ceiling rather than declared, and
                // a policy can only judge what an operation declares. It opts into no http surface
                // today — the scope is what keeps that true if it ever does
                // (greenhouse decisions/0278).
                scopes: ['recipe:apply'],
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            // 🚨 THE PLAN IS A READ, AND ASKING FOR IT MUST NOT COST A CEREMONY.
            //
            // Measured on fresh cattle: Rod spent two signatures and each one bought a PREREQUISITE —
            // «unknown capability», then «no session store». A physical touch is the most expensive
            // thing this house can ask of a person, and it was being spent to learn what the
            // operation needed before it could even start.
            //
            // A DESCENT WAS THE WRONG ANSWER, and trying it is what proved it: `Descent` exists for
            // exactly «a rehearsal is not the act» (decisions/0029), but `holds()` requires a SIGNED
            // certificate, bound to the handler digest, for every axis below `authority` — because
            // whoever declares a descent badly is not punished, they are EXEMPTED (decisions/0053,
            // 0054). Declared without one it never holds: shipping it would have been a promise that
            // reads as a feature and lowers nothing.
            //
            // So this asks for no exemption. It is a read that only reads: the recipe file, what
            // Composer says is installed, and the foundation verdict. Nothing to certify, nothing to
            // sign, and the answer is free every time (greenhouse decisions/0457).
            new Operation(
                name: 'recipe:plan',
                effects: EffectProfile::readOnly(),
                description: 'What a recipe would do and what it needs first — free, before any signature',
                handler: fn (array $input): array => $this->planFor($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'recipe' => [
                            'type' => 'string',
                            'description' => 'The recipe to read — from recipes/<recipe>.json under the app root',
                        ],
                    ],
                    'required' => ['recipe'],
                ],
                // The same surfaces its sibling opts into, and for the same reason: the plan names
                // which packages are missing, which is a map of what this app is not yet — read by a
                // stranger it is a hint about the host, so it stays where `recipe:apply` stays.
                scopes: ['recipe:plan'],
                surfaces: ['cli', 'tui', 'mcp'],
            ),
        ];
    }

    /**
     * The refusal a name earns on its own, or null when the name is a name.
     *
     * 🚨 ASKED BEFORE THE FILESYSTEM AND BEFORE THE KERNEL, and a test says so in its own title:
     * `testANameThatIsAPathIsRefusedBeforeTheFilesystemIsTouched`. Folding this into the shared
     * reader put the kernel lookup first and the suite caught it in seven data sets — «no kernel» is
     * a true sentence about the app and the WRONG answer about the argument: it sends the caller to
     * go boot something instead of to stop naming a path.
     *
     * @return array<string, mixed>|null
     */
    private function refuseUnlessNamed(string $name): ?array
    {
        if ($name === '') {
            return ['ok' => false, 'error' => 'name a recipe: a recipe is read from recipes/<recipe>.json'];
        }

        // A RECIPE NAME IS NOT A PATH, and this concatenated one into a filename.
        //
        // `$name` comes from the request and went straight into `recipes/{$name}.json` with no
        // `basename`, no `realpath` and no test. From a shell you already own that is harmless; the
        // moment this operation reaches any surface a stranger can call, it stops being a NAME and
        // becomes a choice of which file on disk holds the list of operations to run — an upload
        // directory, `/tmp`, a JSON log. The step list is executable, so choosing it is choosing code.
        //
        // One segment, and the segment cannot be `.` or `..`. The check is on the NAME, before the
        // filesystem is touched: a path that never gets built cannot escape.
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1 || str_contains($name, '..')) {
            return ['ok' => false, 'error' => "«{$name}» is not a recipe name: a recipe is named, not located"];
        }

        return null;
    }

    /**
     * The named recipe, or the refusal that says why it could not be read.
     *
     * ONE READER FOR TWO DOORS: `recipe:apply` runs what `recipe:plan` reports, so both ask this.
     * Two readers would be two answers to «what does this recipe say», and the one that drifts is
     * the one nobody runs.
     *
     * @return Recipe|array<string, mixed> the recipe, or the answer to return as-is
     */
    private function load(string $name, string $root): Recipe|array
    {
        $refusal = $this->refuseUnlessNamed($name);
        if ($refusal !== null) {
            return $refusal;
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

        return Recipe::fromArray($name, $decoded);
    }

    /**
     * `recipe:plan` — what the named recipe would do, and what it needs first.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function planFor(array $input): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (! $kernel instanceof Kernel) {
            return ['ok' => false, 'error' => 'no kernel: recipe:plan needs a booted app'];
        }
        $root = $kernel->root();

        $loaded = $this->load(\is_string($input['recipe'] ?? null) ? trim($input['recipe']) : '', $root);

        return $loaded instanceof Recipe ? $this->plan($loaded, $root) : $loaded;
    }

    /**
     * The plan: the steps, the capabilities, what is missing, and the exact command for each.
     *
     * Every line is derivable without authority — the recipe declares its work, Composer knows what
     * is installed, `Foundation` knows whether the house is founded, and the session store is either
     * wired or not. Nothing here mutates, so nothing here is worth a signature.
     *
     * @return array<string, mixed>
     */
    private function plan(Recipe $recipe, string $root): array
    {
        $missing = [];
        $capabilities = [];
        foreach ($recipe->capabilities as $package) {
            $installed = class_exists(\Composer\InstalledVersions::class)
                && \Composer\InstalledVersions::isInstalled($package);
            $capabilities[] = [
                'package' => $package,
                'state' => $installed ? 'installed' : 'missing',
                // THE COMMAND COMES FROM THE AUTHORITY, never typed: `coa` is not on the PATH after a
                // `create-project`, so a typed one names something the reader cannot run
                // (greenhouse decisions/0305).
                'command' => $installed ? '' : Capabilities::ENABLE_COMMAND . $package,
            ];
            if (! $installed) {
                $missing[] = $package;
            }
        }

        // THE PREREQUISITE THAT IS NOT ONE OF THE RECIPE'S OWN: a governed sequence pauses for
        // consent on each STEP — a signature on the apply call cannot be presented for a different
        // target — and a pause is recorded where sessions already live. Said HERE, for free, instead
        // of after a ceremony.
        $needsStore = (new AgentOperations($this->container))->sessionStore() === null;
        if ($needsStore) {
            $missing[] = 'milpa/agent';
        }

        $verdict = Foundation::verdict($root);

        return [
            'ok' => true,
            'recipe' => $recipe->name,
            'founds' => $recipe->foundation === null ? null : [
                'domain' => $recipe->foundation['domain'],
                'objective' => $recipe->foundation['objective'],
                'already_founded' => $verdict['verdict'] === 'founded',
            ],
            'capabilities' => $capabilities,
            'work' => array_map(
                static fn (array $step): string => $step['op'],
                $recipe->work,
            ),
            'needs' => array_values(array_unique($missing)),
            'next' => $missing === []
                ? Capabilities::CLI . 'recipe:apply --recipe=' . $recipe->name . ' --sign'
                : Capabilities::ENABLE_COMMAND . $missing[0],
        ];
    }

    /**
     * Reads the named recipe, opens (or resumes) its governed session, and drives it.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function apply(array $input, ?InvocationContext $context = null, ?ToolContext $authority = null): array
    {
        $name = \is_string($input['recipe'] ?? null) ? trim($input['recipe']) : '';
        $refusal = $this->refuseUnlessNamed($name);
        if ($refusal !== null) {
            return $refusal;
        }

        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (! $kernel instanceof Kernel) {
            return ['ok' => false, 'error' => 'no kernel: recipe:apply needs a booted app'];
        }
        $root = $kernel->root();

        // ONE READER FOR BOTH DOORS. `recipe:plan` reads the same file this applies, so the reading
        // lives in one place: two readers would be two answers to «what does this recipe say».
        $loaded = $this->load($name, $root);
        if (! $loaded instanceof Recipe) {
            return $loaded;
        }
        $recipe = $loaded;

        // THE SAME STORE agent sessions live in, so a pause is recorded where a session already is —
        // never a second truth about what happened (mirrors SessionOperations exactly).
        $store = (new AgentOperations($this->container))->sessionStore();
        if ($store === null) {
            return ['ok' => false, 'error' => 'no session store: a governed sequence records its pause where sessions live ('
                . Capabilities::ENABLE_COMMAND . 'milpa/agent). What this recipe needs, free: '
                . Capabilities::CLI . 'recipe:plan --recipe=' . $name];
        }

        $sessionId = \is_string($input['session'] ?? null) && trim($input['session']) !== ''
            ? trim($input['session'])
            : 'recipe:' . $name;

        $petition = "apply recipe {$name}";

        $existing = $store->load($sessionId);
        $resuming = $existing?->pausedSequence !== null;

        if (! $resuming && $existing === null) {
            // AutonomyMode::Ask: the sequence pauses before any mutation until a human grants it.
            $store->start($sessionId, $petition, AutonomyMode::Ask, by: ObservedExecutor::fromContext($context)->principal);
        }

        $session = $store->load($sessionId);
        if ($session === null) {
            return ['ok' => false, 'error' => 'could not open a session to govern the recipe'];
        }

        $executor = GovernedDoor::open($kernel, $root, $store, $session, $petition, $context, $authority);
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
}
