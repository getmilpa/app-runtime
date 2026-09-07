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

use Milpa\AppRuntime\Agent\SequenceStep;

/**
 * The sequences an app DECLARED, read once from `config/sequences.php` — a CLOSED SET, named.
 *
 * ── WHY A CLOSED SET AND NOT A FILENAME ─────────────────────────────────────────────────────────────
 *
 * `recipe:apply` takes the name of a file and reads `recipes/<name>.json`. On a terminal that is fine;
 * on a surface a stranger can reach it means the caller CHOOSES which file on disk holds the list of
 * operations to run, and a step list is executable. The fix there was to refuse a name that is a path.
 * The fix HERE is stronger and structural: the name never touches the filesystem at all. It is a key
 * into a map the app wrote at boot, so a name nobody declared resolves to nothing — there is no path to
 * escape from because no path is ever built.
 *
 * That is what lets this reach HTTP when `recipe:apply` must not (greenhouse decisions/0223).
 *
 * ── THE SHAPE ───────────────────────────────────────────────────────────────────────────────────────
 *
 *     // config/sequences.php
 *     return [
 *         'deploy' => [
 *             ['op' => 'plugins:verify', 'args' => []],
 *             ['op' => 'stack:up',       'args' => ['service' => 'web']],
 *         ],
 *     ];
 *
 * `op` is an operation THIS APP OFFERS — the gate judges each one on its own declared effects as the
 * sequence runs, exactly as it judges an agent's call. Nothing is prepended: a deployment runs the list
 * its app declared and no preamble, which is the one place it differs from a recipe.
 */
final readonly class DeclaredSequences
{
    /** @param array<string, list<SequenceStep>> $sequences */
    private function __construct(private array $sequences)
    {
    }

    /**
     * Reads `config/sequences.php` under an app root. A missing file is an app that declared none —
     * an empty set, never an error: not deploying is a legitimate way to be an app.
     */
    public static function underRoot(string $root): self
    {
        $file = $root . '/config/sequences.php';
        if (!is_file($file)) {
            return new self([]);
        }

        $declared = require $file;

        return self::fromArray(\is_array($declared) ? $declared : []);
    }

    /**
     * @param array<mixed> $declared name → list of `['op' => string, 'args' => array]`
     */
    public static function fromArray(array $declared): self
    {
        $out = [];
        foreach ($declared as $name => $steps) {
            if (!\is_string($name) || $name === '' || !\is_array($steps)) {
                continue;
            }

            $built = [];
            foreach ($steps as $step) {
                $op = \is_array($step) ? ($step['op'] ?? null) : null;
                if (!\is_string($op) || $op === '') {
                    // A step without an operation is not a step. Skipping it silently would run a
                    // SHORTER sequence than the app declared, so the whole sequence is dropped: a
                    // deployment that runs some of its steps is worse than one that refuses to start.
                    $built = null;
                    break;
                }
                $args = \is_array($step['args'] ?? null) ? $step['args'] : [];
                $built[] = new SequenceStep($op, $args);
            }

            if ($built !== null && $built !== []) {
                $out[$name] = $built;
            }
        }

        return new self($out);
    }

    /**
     * The steps of one declared sequence, or null when the app declared no such name.
     *
     * @return list<SequenceStep>|null
     */
    public function stepsOf(string $name): ?array
    {
        return $this->sequences[$name] ?? null;
    }

    /**
     * Every name this app declared — what an operation answers with when asked for a name it has not.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->sequences));
    }
}
