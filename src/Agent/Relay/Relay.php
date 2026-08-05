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

namespace Milpa\AppRuntime\Agent\Relay;

/**
 * A relay: specialists in a fixed order, each handing the next a checked artifact.
 *
 * ── WHY A RELAY AND NOT A «WORKFLOW» ────────────────────────────────────────────────────────────
 *
 * Two things in this family are already called that — `milpa/workflow` is a state machine with gates,
 * `milpa/orchestrator` a process engine with human decisions — and a third would guarantee that in
 * six months nobody knows which one they were handed.
 *
 * But the name is not only about avoiding a collision. **The artifact is the baton.** It is handed
 * over in person, it is checked at the handover, and if it drops the race stops. That is exactly the
 * thesis this exists to serve: what joins the specialists is what they leave behind and the contract
 * of what they leave behind — not a prose report anybody has to interpret.
 *
 * ── THE ORDER IS CODE, AND THAT IS THE POINT ────────────────────────────────────────────────────
 *
 * The alternative was to hand the orchestrator a set of roles and let it decide who to call and when.
 * Measured, that is where it fails: asked explicitly to delegate, a real model spent 25 steps without
 * calling `agent_spawn` once. An order the model chooses is an order nobody can reproduce, and a
 * workflow you cannot reproduce is a demo.
 *
 * So the sequence lives in the declaration. The model still decides everything INSIDE a leg — how to
 * plan, what to look at, what to write — and decides nothing about what comes next.
 *
 * ── WHAT IS REFUSED AT DECLARATION RATHER THAN AT RUNTIME ───────────────────────────────────────
 *
 * A leg that consumes an artifact no earlier leg produces. It would look fine until the day it ran,
 * and then fail somewhere the cause is not visible. Declaring it is the moment the mistake is
 * cheapest to see, so that is where it is refused.
 */
final class Relay
{
    /** @var list<RelayLeg> */
    private array $legs = [];

    private function __construct(public readonly string $name)
    {
    }

    /**
     * Starts a relay under a name — the name a run is recorded and read back by.
     */
    public static function named(string $name): self
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('a relay without a name cannot be run by name, nor found in a log afterwards');
        }

        return new self($name);
    }

    /**
     * Adds a leg to the race — in the order it will run.
     *
     * @param string      $name  what this leg is called in the run's record
     * @param string      $role  the specialist that runs it; unknown names are refused when it runs,
     *                           by the registry, with the list of the ones that exist
     * @param string|null $takes the name of an EARLIER leg whose artifact this one receives
     * @param string|null $brief extra instructions for this leg, beyond the role's own prompt
     */
    public function leg(string $name, string $role, ?string $takes = null, ?string $brief = null): self
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException("relay «{$this->name}»: a leg needs a name — the run's record is read by leg");
        }
        foreach ($this->legs as $existing) {
            if ($existing->name === $name) {
                throw new \InvalidArgumentException(
                    "relay «{$this->name}»: two legs called «{$name}». `takes` refers to a leg by name, so a "
                    . 'duplicate would make «takes: ' . $name . '» ambiguous — and the ambiguity would be '
                    . 'resolved silently by whichever one came first.'
                );
            }
        }

        // A BATON CAN ONLY COME FROM SOMEBODY WHO ALREADY RAN.
        //
        // Refusing here rather than at runtime is the whole difference between a typo you see while
        // writing and a failure three legs deep whose cause is a name in another file.
        if ($takes !== null) {
            $earlier = array_map(static fn (RelayLeg $l): string => $l->name, $this->legs);
            if (!\in_array($takes, $earlier, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'relay «%s», leg «%s»: takes «%s», which no earlier leg produces. Earlier legs: %s',
                    $this->name,
                    $name,
                    $takes,
                    $earlier === [] ? '(none — this is the first leg)' : implode(', ', $earlier),
                ));
            }
        }

        $this->legs[] = new RelayLeg($name, $role, $takes, $brief);

        return $this;
    }

    /** @return list<RelayLeg> */
    public function legs(): array
    {
        return $this->legs;
    }

    /**
     * The whole declaration as data, so a surface can show the race BEFORE it runs.
     *
     * That matters more than it sounds: the order is the guarantee this thing sells, and a guarantee
     * nobody can read without executing it is a guarantee on trust.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'legs' => array_map(static fn (RelayLeg $l): array => $l->toArray(), $this->legs),
        ];
    }
}
