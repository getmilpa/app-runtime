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

namespace Milpa\AppRuntime\Config;

use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Runtime\Config;

/**
 * WHAT CONTAINS WHAT AN AGENT RUNS IN THIS HOUSE, AS THE HOUSE CAN SAY IT (greenhouse decisions/0606, posture B;
 * decisions/0607, annex, slices 1 and 2).
 *
 * ── THE POSTURE ─────────────────────────────────────────────────────────────────────────────────
 *
 * A trial runs with the whole machine in view, read-only: what it writes is held to its copy, what it reads is
 * whatever the user who runs the house can read. decisions/0606 did not narrow that view; it chose where a house
 * should run — INSIDE something that holds it, a container or a dedicated user — so that the view is that thing's
 * and not a person's home. It also covers the machines where nothing confines a trial at all.
 *
 * ── WHAT THIS CLASS IS ──────────────────────────────────────────────────────────────────────────
 *
 * The first two slices, and they only SAY. A house declares whether it runs contained and by what, under
 * {@see KEY}; absent, it says today's truth: not contained. And {@see said()} is the one line `coa doctor` prints
 * from that declaration and from whether this machine can confine a trial.
 *
 * NOTHING HERE CHECKS THE DECLARATION, and nothing reads it to decide what runs. It is a person's statement about
 * where they put the house — the house cannot see its own container from the inside in any way it could not be
 * fooled about — and a trial reads what it read before. Narrowing what a trial can read is another slice.
 *
 * ── WHAT «CANNOT CONFINE A TRIAL» MEANS, MEASURED (greenhouse evidence/1181) ────────────────────
 *
 * The runner fails closed: without bubblewrap there is no trial. What the house does then is ask — «The agent
 * wants to run «make»» — and, once a person allows it, write the change in the house itself, with nothing confining
 * it. With the tool, the same call ran in a copy and nothing landed. The line says that, not «unconfined trial»:
 * there is no trial to be unconfined.
 */
final readonly class Containment
{
    /** The key a house declares it under — one of the keys {@see AgentKeys} lists, types and `config:set` writes. */
    public const KEY = 'agent.contained';

    /** The house runs in a container. */
    public const CONTAINER = 'container';

    /** The house runs as a user of its own, who can read what the house needs and no person's home. */
    public const USER = 'user';

    /** @param mixed $declared what the house's configuration holds under {@see KEY}, as it is */
    private function __construct(private mixed $declared)
    {
    }

    /**
     * What this house declares, read where `config:set` writes it: the machine's own file over the human's.
     *
     * Read from the files and not from a booted kernel — the doctor exists for the house that does not boot.
     *
     * @param array<string, mixed> $delHumano what `config/app.php` returns
     */
    public static function of(array $delHumano, string $root): self
    {
        return new self((new Config(MachineOverlay::sobre($delHumano, $root)))->get('agent.contained'));
    }

    /** What holds this house, by its own declaration: {@see CONTAINER}, {@see USER}, or null — not contained. */
    public function by(): ?string
    {
        return \in_array($this->declared, [self::CONTAINER, self::USER], true) ? $this->declared : null;
    }

    /**
     * A declared value that names nothing that holds a house, as JSON; null when there is none to remark on.
     *
     * `true` does not say WHAT contains it, and a product's name is not something this house knows the reach of.
     * Such a value is said and never believed: read as contained, it would be a house telling its doctor that
     * all is well on the strength of a typo. Absent and `false` are the default, and are not remarked on.
     */
    public function unreadable(): ?string
    {
        if ($this->declared === null || $this->declared === false || $this->by() !== null) {
            return null;
        }

        return (string) json_encode($this->declared, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * The one line `coa doctor` says, with its mark — or null when a trial is confined AND the house is declared
     * contained: a report that prints a line to say «nothing» trains people to skip it.
     *
     * `!` when neither holds, with both steps. `·` when exactly one does, naming which and the step for the other:
     * that is a house that is held, said as it is. It is never a failure — the doctor's exit is `coa update`'s boot
     * check, and a machine without bubblewrap is not a house that does not boot.
     *
     * @param bool $confines whether this machine can confine a trial — the runner's own answer, never a second probe
     */
    public function said(bool $confines): ?string
    {
        $by = $this->by();
        if ($confines && $by !== null) {
            return null;
        }
        $declare = 'if a container or a dedicated user holds this house, declare it: '
            . Capabilities::cli() . 'config:set ' . self::KEY . ' ' . self::CONTAINER . ' --sign (or ' . self::USER . ')';
        $noTrial = 'this machine cannot confine a trial, so a change is asked for and then runs in the house itself';
        $install = 'install bubblewrap (bwrap)';
        $unreadable = $this->unreadable();
        $remark = $unreadable === null
            ? ''
            : ' («' . self::KEY . '» says ' . $unreadable . ', which names nothing that holds a house: only '
                . self::CONTAINER . ' or ' . self::USER . ' is read)';

        if ($by !== null) {
            return '  · this house is declared contained (' . $by . '); ' . $noTrial . ' — ' . $install . ' to try it on a copy first';
        }
        if ($confines) {
            return '  · a trial runs confined on this machine; the house itself is not declared contained' . $remark . ' — ' . $declare;
        }

        return '  ! nothing contains what an agent runs in this house: ' . $noTrial . ', and the house is not declared contained'
            . $remark . ' — ' . $install . ', or, ' . $declare;
    }
}
