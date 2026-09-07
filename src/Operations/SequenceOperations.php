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
use Milpa\AppRuntime\Support\CatalogueBorrower;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Command\Consent\OperationId;
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
final class SequenceOperations implements CatalogueBorrower
{
    /** @var list<Operation> the app's catalogue, EXCEPT this provider's own — null on the first pass */
    private ?array $catalogue = null;

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * The same provider, now holding the catalogue whose ceilings its steps are folded from.
     *
     * Built from `config/operations.php` this provider receives nothing, because it is built in order to
     * PRODUCE that catalogue. `Operations::withBorrowedCeilings()` asks again once the catalogue is
     * complete, and what it hands over excludes this provider's own operations — folding the borrower
     * into its own loan is a fixed point that returns the maximum while looking like it worked.
     *
     * @param list<Operation> $catalogue
     */
    public function withCatalogue(array $catalogue): self
    {
        $provider = new self($this->container);
        $provider->catalogue = $catalogue;

        return $provider;
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
                // THE CEILING RISES WITH THE STEPS, AND NEVER FALLS BELOW THE FLOOR.
                //
                // `constant->join(steps)` — the shape `config:set` already borrows its ceiling with,
                // and the three properties that make it safe are the same and deliberate: it only
                // RAISES (joined onto a hand-written floor, never substituted), its empty case is the
                // maximum, and a step the app does not offer folds to the maximum too. The naive
                // design — the bare join — was measured before this was written: join(config-write,
                // data-write) reaches neither Executable nor Privileged, so a real deployment would
                // have lost its ceremony while still mutating (greenhouse decisions/0223, point 2).
                effects: self::floor()->join($this->foldOfEverySequence()),
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
     * What running ANY sequence does on its own, before folding a single step: the ceiling of what it
     * originates — the same shape `recipe:apply` declares, because the same door judges each step on its
     * own declared effects as the sequence runs. A floor is the one thing a fold can never lower.
     */
    private static function floor(): EffectProfile
    {
        return new EffectProfile(
            Mutation::Persistent,
            Externality::ThirdParty,
            // A sequence has no tested inverse: undoing it means undoing each step that ran, and those
            // are the steps' own promises, not this one's.
            Reversibility::ManualRecovery,
            Authority::Privileged,
            subject: Subject::Executable,
        );
    }

    /**
     * The join of every step of every sequence this app declared, resolved against the catalogue.
     *
     * Two answers are the maximum on purpose, and the docblock of `JudgeCeiling::prestado()` already
     * says why: what nobody classified carries the maximum of every dimension (GOV-05). No catalogue —
     * the first pass, before the loan — is the maximum. A step naming an operation the app does not
     * offer is the maximum too: a sequence that cannot be judged whole cannot be judged cheaper than
     * its worst possibility, and this is the same refusal the gate makes at run time (UNJUDGEABLE).
     */
    private function foldOfEverySequence(): EffectProfile
    {
        if ($this->catalogue === null) {
            return EffectProfile::unclassified();
        }

        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        $root = $kernel instanceof Kernel ? $kernel->root() : Capabilities::raizDeLaApp();

        $fold = null;
        foreach (DeclaredSequences::underRoot($root)->names() as $name) {
            foreach (DeclaredSequences::underRoot($root)->stepsOf($name) ?? [] as $step) {
                $resolved = $this->offered($step->operation);
                if ($resolved === null) {
                    return EffectProfile::unclassified();
                }
                $theirs = $resolved->effectCeiling();
                $fold = $fold === null ? $theirs : $fold->join($theirs);
            }
        }

        // No sequence declared at all is NOT the maximum: it is a floor with nothing to raise it. An app
        // that deploys nothing should not carry an unbounded ceiling for an operation that answers
        // «this app declares none».
        return $fold ?? EffectProfile::readOnly();
    }

    /** The catalogue entry a step names — by IDENTITY, however the step spelled it. */
    private function offered(string $name): ?Operation
    {
        $wanted = new OperationId($name);
        foreach ($this->catalogue ?? [] as $operation) {
            if ($wanted->is($operation->name)) {
                return $operation;
            }
        }

        return null;
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
        $paused = $existing?->pausedSequence;

        // A RESUME IS OF WHAT WAS NAMED, and this used to resume whatever the session happened to hold.
        //
        // `$resuming` was set from the session alone, and the resume path then discards the steps this
        // call resolved — so `sequence:run {sequence: health-check, session: «sequence:deploy»}` ran
        // DEPLOY'S paused steps while the catalogue, the ledger's petition and the human's intent all
        // said health-check. The intent contract (ADR-0044) exists so a human names the target; naming
        // one and running another is that contract broken from the inside.
        //
        // Today's constant ceiling is the only reason this was not already dangerous: both calls cost
        // the same signature. That is a coincidence, not a guard, and it disappears the moment the
        // ceiling is derived (greenhouse decisions/0223, point 2).
        if ($paused !== null && $paused->sequenceId !== $name) {
            return [
                'ok' => false,
                'error' => \sprintf(
                    'session «%s» is paused on «%s», not on «%s»: a resume continues the sequence that was '
                    . 'named, and this call names another. Resume it as «%s», or use a different session.',
                    $sessionId,
                    $paused->sequenceId,
                    $name,
                    $paused->sequenceId,
                ),
            ];
        }

        $resuming = $paused !== null;
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
