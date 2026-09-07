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
use Milpa\AppRuntime\Recipe\RecipeDriver;
use Milpa\AppRuntime\Sequence\DeclaredSequences;
use Milpa\AppRuntime\Sequence\GovernedDoor;
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

/**
 * `sequence:run` — a human starts a sequence THIS APP DECLARED, from the surface where they authorise
 * everything else.
 *
 * ── WHY THIS EXISTS AND `recipe:apply` DOES NOT REACH HTTP ──────────────────────────────────────────
 *
 * A deployment is the act of largest consequence a human authorises an agent to perform, and until now
 * it could only be started from a terminal (greenhouse decisions/0223). The obvious move — adding
 * `'http'` to `recipe:apply` — is a ONE-WORD diff that publishes an unguarded endpoint whose payload
 * names a FILE: an adversarial review measured it, and three defects made that shape remote code
 * selection rather than a governed act.
 *
 * This operation is the narrow one that can be exposed, and every difference is deliberate:
 *
 *   · **the name is a key, not a path.** It resolves against {@see DeclaredSequences} — a closed set the
 *     app wrote at boot — so a name nobody declared reaches nothing. No path is built, so none can be
 *     escaped from.
 *   · **it declares `scopes`**, so `HttpProjector` consults the policy at all: an operation with no
 *     scopes and no permission never reaches one, and `assertGuarded()` does not flag it either.
 *   · **no preamble.** A recipe founds and installs before it makes; a deployment runs EXACTLY the list
 *     its app declared. Same motor, different meaning.
 *   · **the executor is read from the invocation**, so the ledger names who actually pressed it.
 *
 * The per-step gate is unchanged: {@see GovernedDoor} originates every call through the same
 * `ConsentBridge` an agent's calls go through, so the run stops at the first frontier and its pause is
 * a durable session fact another process can resume.
 */
final readonly class SequenceOperations implements CommandProvider
{
    public function __construct(private DIContainerInterface $container)
    {
    }

    /**
     * The one operation this provider contributes: `sequence:run`.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'sequence:run',
                effects: new EffectProfile(
                    // THE CEILING OF WHAT IT ORIGINATES, not of any single step — the same shape
                    // `recipe:apply` declares, because the same door judges each step on its own
                    // declared effects as the sequence runs.
                    //
                    // Deriving this from the declared steps (the `join` of their profiles) is the next
                    // slice of decisions/0223 and deliberately NOT done here: a per-app ceiling read at
                    // declaration time would be a profile that changes with config, and that is a
                    // decision, not a detail.
                    Mutation::Persistent,
                    Externality::ThirdParty,
                    // A sequence has no tested inverse: undoing it means undoing each step that ran,
                    // and those are the steps' own promises, not this one's.
                    Reversibility::ManualRecovery,
                    Authority::Privileged,
                    subject: Subject::Executable,
                ),
                description: 'Run a sequence this app declared, step by step through the gate, pausing for consent',
                handler: fn (array $input, ?InvocationContext $context = null): array => $this->run($input, $context),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'sequence' => [
                            'type' => 'string',
                            'description' => 'The name of a sequence declared in config/sequences.php',
                        ],
                        'session' => [
                            'type' => 'string',
                            'description' => 'Continue (or resume) this session — without it, an id derived from the sequence name is used',
                        ],
                    ],
                    'required' => ['sequence'],
                ],
                mutating: true,
                // WITHOUT A SCOPE THE HTTP SURFACE NEVER CONSULTS A POLICY. `HttpProjector::handle()`
                // enforces only when an operation declares scopes or a permission, and the boot-time
                // `assertGuarded()` refuses only those — so an operation with neither is published to
                // whoever reaches the server. This is the difference between exposed and governed.
                scopes: ['agent:run'],
                // THE HUMAN NAMES WHAT RUNS. The same intent contract `recipe:apply` and
                // `capabilities:enable` carry (ADR-0044): the sequence is named in the request, never
                // chosen for the operator.
                namedTarget: 'sequence',
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function run(array $input, ?InvocationContext $context = null): array
    {
        $name = \is_string($input['sequence'] ?? null) ? trim($input['sequence']) : '';
        if ($name === '') {
            return ['ok' => false, 'error' => 'name a sequence: sequence:run runs one declared in config/sequences.php'];
        }

        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (! $kernel instanceof Kernel) {
            return ['ok' => false, 'error' => 'no kernel: sequence:run needs a booted app'];
        }
        $root = $kernel->root();

        $declared = DeclaredSequences::underRoot($root);
        $steps = $declared->stepsOf($name);
        if ($steps === null) {
            // It names what EXISTS, because a caller who guessed wrong deserves the list rather than a
            // second guess — and because there is nothing secret about which sequences an app declared.
            $names = $declared->names();

            return [
                'ok' => false,
                'error' => \sprintf(
                    'this app declares no sequence «%s»%s',
                    $name,
                    $names === [] ? ': config/sequences.php declares none' : '. It declares: ' . implode(', ', $names),
                ),
            ];
        }

        $sessionId = \is_string($input['session'] ?? null) && trim($input['session']) !== ''
            ? trim($input['session'])
            : 'sequence:' . $name;

        // The SAME store the agent and the recipe path resolve — never a second one built here, or a
        // pause written by one surface would be invisible to the other.
        $store = (new AgentOperations($this->container))->sessionStore();
        if ($store === null) {
            return ['ok' => false, 'error' => 'no session store: install milpa/agent so a pause can be recorded'];
        }

        $petition = 'run the ' . $name . ' sequence';

        $existing = $store->load($sessionId);
        $resuming = $existing?->pausedSequence !== null;
        if ($existing === null) {
            $store->start($sessionId, $petition, AutonomyMode::Ask);
        }

        $session = $store->load($sessionId);
        if ($session === null) {
            return ['ok' => false, 'error' => 'could not open a session to govern the sequence'];
        }

        $executor = GovernedDoor::open($kernel, $root, $store, $session, $petition, $context);
        $driver = new RecipeDriver();

        return $resuming
            ? $driver->resume($store, $sessionId, $executor)
            : $driver->runSteps($steps, $name, $executor, $store, $sessionId);
    }
}
