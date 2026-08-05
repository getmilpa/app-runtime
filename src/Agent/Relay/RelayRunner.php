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

use Milpa\AppRuntime\Agent\SubAgentSpawner;

/**
 * Runs a relay: each leg delegated in order, each baton handed over checked.
 *
 * ── IT COMPOSES; IT ADDS NO AUTHORITY ───────────────────────────────────────────────────────────
 *
 * Every leg is an ordinary `agent_spawn`. The role brings its prompt and its executed restrictions,
 * the artifact contract checks what comes back, the tree budget is spent the same way, and the
 * lineage ceiling governs every mutation exactly as it did. A relay is a caller, not a new power —
 * which is why nothing here has to be re-measured.
 *
 * ── A DROPPED BATON STOPS THE RACE ──────────────────────────────────────────────────────────────
 *
 * If a leg does not deliver the artifact its role declared, the next leg does not run. The
 * alternative — carry on and let the following specialist work from prose, or from nothing — is how
 * a workflow produces four confident stages built on a first one that failed, and reports success.
 *
 * The stop is not a crash: the run comes back with everything that DID happen, the leg that dropped
 * it, and why. A relay that stopped is a result, not an error.
 *
 * ── WHAT THE NEXT LEG ACTUALLY RECEIVES ─────────────────────────────────────────────────────────
 *
 * The baton travels as JSON inside the brief, under a heading that names which leg produced it.
 * Not as prose «the planner said…», because the receiving specialist has to be able to read it by
 * field — that is the entire reason the artifact has a contract. And named, because a workflow of
 * four legs hands over several batons and «the previous one» stops being an answer.
 */
final class RelayRunner
{
    public function __construct(private readonly SubAgentSpawner $spawner)
    {
    }

    /**
     * Runs every leg in order and returns what happened — including where it stopped.
     *
     * @return array{
     *     ok: bool,
     *     relay: string,
     *     legs: list<array<string, mixed>>,
     *     artifacts: array<string, array<string, mixed>>,
     *     stopped_at?: string,
     *     why?: string
     * }
     */
    public function run(Relay $relay): array
    {
        /** @var array<string, array<string, mixed>> $batons */
        $batons = [];
        $record = [];

        foreach ($relay->legs() as $leg) {
            $brief = $leg->brief ?? '';

            if ($leg->takes !== null) {
                // The baton is handed over BY NAME and as data. A relay with four legs passes several,
                // and «the previous one» stops being an answer the moment there is more than one.
                $brief .= sprintf(
                    "\n\nThis is the «%s» artifact produced by the «%s» leg. Read it by field:\n%s",
                    $batons[$leg->takes]['kind'] ?? '?',
                    $leg->takes,
                    json_encode($batons[$leg->takes]['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );
            }

            /** @var array<string, mixed> $result */
            $result = $this->spawner->spawn([
                'brief' => trim($brief) === '' ? 'Do your part of «' . $relay->name . '».' : trim($brief),
                'role' => $leg->role,
            ]);

            $record[] = [
                'leg' => $leg->name,
                'role' => $leg->role,
                'ok' => $result['ok'] ?? false,
                'sub_session' => $result['sub_session'] ?? null,
                'steps' => $result['steps'] ?? 0,
            ];

            if (($result['ok'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'relay' => $relay->name,
                    'legs' => $record,
                    'artifacts' => $batons,
                    'stopped_at' => $leg->name,
                    'why' => \is_string($result['error'] ?? null)
                        ? $result['error']
                        : 'the leg did not finish, and nothing says why',
                ];
            }

            // AND A LEG THAT «SUCCEEDED» WITHOUT ITS ARTIFACT STOPS THE RACE TOO.
            //
            // A role that declares `produces` and comes back without it did not do its part, however
            // reasonable its prose. Letting the next leg start on nothing is how a relay reports four
            // confident stages built on a first one that failed.
            if (!isset($result['artifact']) && $this->legIsExpectedToProduce($leg, $relay)) {
                return [
                    'ok' => false,
                    'relay' => $relay->name,
                    'legs' => $record,
                    'artifacts' => $batons,
                    'stopped_at' => $leg->name,
                    'why' => sprintf('leg «%s» finished without the artifact its role declares', $leg->name),
                ];
            }

            if (isset($result['artifact']) && \is_array($result['artifact'])) {
                /** @var array<string, mixed> $artifact */
                $artifact = $result['artifact'];
                $batons[$leg->name] = $artifact;
            }
        }

        return [
            'ok' => true,
            'relay' => $relay->name,
            'legs' => $record,
            'artifacts' => $batons,
        ];
    }

    /**
     * Whether a later leg is counting on this one's baton.
     *
     * Only then does a missing artifact stop the race: a last leg whose result nobody consumes has
     * nothing to drop. Stopping there anyway would refuse a relay that finished its work because of
     * a contract nobody was waiting on.
     */
    private function legIsExpectedToProduce(RelayLeg $leg, Relay $relay): bool
    {
        foreach ($relay->legs() as $other) {
            if ($other->takes === $leg->name) {
                return true;
            }
        }

        return false;
    }
}
