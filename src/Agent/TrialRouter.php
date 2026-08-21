<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\TrialConfinement;
use Milpa\Command\Operation;

/**
 * THE ONE source that decides whether a call goes to trial (greenhouse decisions/0069 §10).
 *
 * The gate asks it to know whether to confine the call's profile; the executor asks it to know
 * whether to run the call in the sandbox. They get the SAME answer for the same call because the
 * plan is memoised by the call's argument digest — never two verdicts for one call.
 *
 * ── WHAT IS ELIGIBLE ────────────────────────────────────────────────────────────────────────────
 *
 * Only a mutation whose blast radius stays inside a copy: it changes something (a read has nothing
 * to confine), it touches no third party (a disposable copy does not make an email disposable), it
 * asks no signature (a signed consent is never pre-empted by a trial), and it is not one of the
 * house's own operations — spawning, sessions, capabilities, the sandbox itself, founding — which
 * are the acts that MOVE the house rather than run inside it.
 */
final class TrialRouter
{
    private const HOUSE_PREFIXES = ['agent:', 'session:', 'capabilities:', 'sandbox:', 'foundation:'];

    // The most UNDECIDED trials kept on disk at once (decisions/0071). var/trials/ shares the disk the
    // session writes to, so it is bounded here — ~24 x 656 KB is a ~15 MB ceiling on trial copies,
    // far below anything that could starve the materialize confinedByTrial() depends on. Decided
    // (promoted) trials do not count: they have already collapsed to a tiny pre-image.
    public const KEEP = 24;

    /** @var array<string, ?TrialPlan> memoised by operation name + argument digest */
    private array $plans = [];

    public function __construct(
        private readonly string $root,
        private readonly TrialRunner $runner,
        private readonly string $runnerPath,
    ) {
    }

    /**
     * What a trial changed, as the host sees it — for a promotion pause to SHOW what would enter.
     *
     * The workspace, not the plan: a promotion names a trial that already ran, so the diff is read
     * from the copy on disk (greenhouse decisions/0069 — the human authorises what enters).
     *
     * @return array<string, string> path => added|modified|deleted, or [] if there is no such trial
     */
    public function diffForWorkspace(string $id): array
    {
        $ws = TrialWorkspace::open($this->root, $id);
        if ($ws === null) {
            return [];
        }
        $out = [];
        foreach ($ws->diff() as $path => $info) {
            $out[$path] = $info['status'];
        }

        return $out;
    }

    /** The runner this router plans against — the executor runs the trial through it. */
    public function runner(): TrialRunner
    {
        return $this->runner;
    }

    /** Whether this operation may be rehearsed in a trial at all — see the class docblock. */
    public function eligible(Operation $operation): bool
    {
        if (! $operation->mutating || $operation->requiresConfirmation) {
            return false;
        }
        if ($operation->effectCeiling()->externality !== Externality::None) {
            return false;
        }
        foreach (self::HOUSE_PREFIXES as $prefix) {
            if (str_starts_with($operation->name, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The plan for THIS call, or `null` when it does not go to trial — because it is not eligible, or
     * because there is no sandbox to confine it (fail closed: no plan, and the gate pauses as today).
     *
     * @param array<string, mixed> $arguments
     */
    public function planFor(Operation $operation, array $arguments): ?TrialPlan
    {
        if (! $this->eligible($operation)) {
            return null;
        }

        $digest = $this->digest($arguments);
        $key = $operation->name . '#' . $digest;
        if (\array_key_exists($key, $this->plans)) {
            return $this->plans[$key];
        }

        if (! $this->runner->available()) {
            return $this->plans[$key] = null;
        }

        $id = 'w' . substr(hash('sha256', $key), 0, 12);
        $workspace = TrialWorkspace::materialize($this->root, $id, $this->runnerPath);
        // Bound the disk on every fresh trial — the newest KEEP undecided survive, the oldest go.
        TrialWorkspace::capUndecided($this->root, self::KEEP);
        $confinement = new TrialConfinement(
            workspaceId: $id,
            argumentsDigest: $digest,
            bounds: TrialWorkspace::BOUNDS,
            because: sprintf('a %s runs in a disposable copy before it may touch the house', $operation->name),
        );

        return $this->plans[$key] = new TrialPlan($workspace, $confinement);
    }

    /** @param array<string, mixed> $arguments */
    private function digest(array $arguments): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (! \is_array($value)) {
                return $value;
            }
            ksort($value);

            return array_map($canonical, $value);
        };

        return hash('sha256', (string) json_encode($canonical($arguments), \JSON_UNESCAPED_SLASHES));
    }
}
