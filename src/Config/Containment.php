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
 * ── DECLARED, NOT CHECKED (greenhouse decisions/0612, decided by Rod on 2026-10-10) ─────────────
 *
 * The line used to stop when a trial was confined AND the house was declared: «with both, silence» (evidence/1181).
 * Measured in the public image (evidence/1186), that silence covered a container started privileged, one with a
 * home mounted, one with Docker's socket, one with a keyring a process in it signed with. A doctor that says
 * nothing is read as a house that is safe, and the house cannot check what it declares. So a declared house is
 * ALWAYS said: WHERE the declaration is read — the house's own configuration, the file `config:set` writes, or the
 * variable of whoever started it ({@see VARIABLE}) —, that nothing checks it, and what an agent run in it still
 * reaches. «Contained» is never said as «safe».
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

    /** The variable whoever starts a house declares it through, where the house's configuration reads it (greenhouse decisions/0612). */
    public const VARIABLE = 'MILPA_AGENT_CONTAINED';

    /**
     * @param mixed $declared  what the house's configuration holds under {@see KEY}, as it is
     * @param bool  $byMachine whether the file `config:set` writes is the one that says it
     */
    private function __construct(private mixed $declared, private bool $byMachine = false)
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
        return new self(
            (new Config(MachineOverlay::sobre($delHumano, $root)))->get('agent.contained'),
            (new Config(MachineOverlay::sobre([], $root)))->get('agent.contained') !== null,
        );
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
     * Where the declaration is read, as far as a house can tell who made it — null when the house is not declared.
     *
     * The house has two files and sees no further: the person's `config/app.php`, and the one `config:set` writes,
     * which wins. When the person's file says what {@see VARIABLE} says in this process's environment, the
     * declaration came through it — in the image the baked configuration reads it — and whoever started the house
     * made it. A variable that says something else, or that no configuration reads, declared nothing.
     */
    public function from(): ?string
    {
        $by = $this->by();

        return match (true) {
            $by === null => null,
            $this->byMachine => 'in .milpa/agent.json, where `config:set` writes it',
            getenv(self::VARIABLE) === $by => 'by whoever started it (' . self::VARIABLE . ' in its environment)',
            default => 'in config/app.php',
        };
    }

    /**
     * The one line `coa doctor` says, with its mark — in every state (greenhouse decisions/0612).
     *
     * `!` when nothing holds: no trial is confined and the house is not declared, with both steps. `·` otherwise.
     * A DECLARED house is always said — where it is declared, «declared, not checked», and what an agent run in it
     * still reaches — whether or not a trial is confined: the house cannot check its own declaration, so it never
     * stops saying that it is one. It is never a failure — the doctor's exit is `coa update`'s boot check, and a
     * machine without bubblewrap is not a house that does not boot.
     *
     * THE STEP TELLS A MISSING BUBBLEWRAP FROM ONE THAT IS REFUSED (evidence/1186). The image carries it; under
     * Docker's own seccomp profile it cannot make a namespace, and the line said «install bubblewrap». What a
     * person can do about each is different, so the runner is asked which.
     *
     * @param bool $confines    whether this machine can confine a trial — the runner's own answer, never a second probe
     * @param bool $toolIsThere whether bubblewrap is installed at all — the runner's own answer too
     */
    public function said(bool $confines, bool $toolIsThere): string
    {
        $by = $this->by();
        $declare = 'if a container or a dedicated user holds this house, declare it: '
            . Capabilities::cli() . 'config:set ' . self::KEY . ' ' . self::CONTAINER . ' --sign (or ' . self::USER . ')';
        $noTrial = 'this machine cannot confine a trial'
            . ($toolIsThere ? ' (bubblewrap is installed, and is refused a namespace here)' : '')
            . ', so a change is asked for and then runs in the house itself';
        $letItConfine = $toolIsThere
            ? 'let bubblewrap make a user namespace here (on a host, the kernel\'s unprivileged user namespaces; '
                . 'in a container, the seccomp profile it is started with)'
            : 'install bubblewrap (bwrap)';
        $unreadable = $this->unreadable();
        $remark = $unreadable === null
            ? ''
            : ' («' . self::KEY . '» says ' . $unreadable . ', which names nothing that holds a house: only '
                . self::CONTAINER . ' or ' . self::USER . ' is read)';

        if ($by !== null) {
            $declared = '  · this house is declared contained (' . $by . ') ' . $this->from() . ' — declared, not checked: '
                . 'the house cannot see what holds it, and what an agent runs here still reaches everything '
                . ($by === self::CONTAINER ? 'mounted into that container' : 'that user can read');

            return $confines
                ? $declared . '; a trial runs confined on this machine'
                : $declared . '; ' . $noTrial . ' — ' . $letItConfine . ' to try it on a copy first';
        }
        if ($confines) {
            return '  · a trial runs confined on this machine; the house itself is not declared contained' . $remark . ' — ' . $declare;
        }

        return '  ! nothing contains what an agent runs in this house: ' . $noTrial . ', and the house is not declared contained'
            . $remark . ' — ' . $letItConfine . ', or, ' . $declare;
    }
}
