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

namespace Milpa\AppRuntime\Agent;

use Milpa\AppRuntime\Support\BootCandidate;
use Milpa\AppRuntime\Support\BootProbe;

/**
 * Whether a promotion takes a route of the house to a 5xx — asked in the boot copy, before anything is written.
 *
 * ── THE DEFECT, MEASURED (greenhouse evidence/1071, B3; decisions/0540) ─────────────────────────
 *
 * In Rod's first live run the resident promoted a controller whose constructor wanted the container. The house
 * booted, so the boot witness of 0512 let it through; `GET /blog` went from 200 to 500, and the receipt said
 * `ok: true` — the 500 only lived in a sentence of the note. Nothing had looked at the route BEFORE.
 *
 * ── AFTER AND BEFORE, BOTH IN A COPY ────────────────────────────────────────────────────────────
 *
 * «After» is the boot copy that already carries the change and already booted ({@see BootCandidate}): the
 * touched plugins' GET routes are requested there, the way {@see HouseRouteObserver} requests them in the house.
 * «Before» is asked only when some route answers 5xx (or its process died), and it is asked of ANOTHER copy,
 * without the change — not of the live house — because a copy has no `var/`: a route that reads the house's
 * state may fail in any copy, and then the promotion is not what broke it. With both answers from copies, the
 * only difference between them is the promotion.
 *
 * A route is broken BY the promotion when, without it, it answered anything but a 5xx — a route that did not
 * exist yet included, it is new and born broken — and with it answers a 5xx or nothing. One that was already 5xx
 * without the promotion is `unjudged`: the promotion may land.
 */
final class RouteRegression
{
    public function __construct(private readonly HouseRouteObserver $observer = new HouseRouteObserver())
    {
    }

    /**
     * Judge the copy at `$candidate` (the house at `$root` with the promotion applied) on the routes `$paths` touch.
     *
     * @param list<string> $paths paths relative to the house root, as the promotion names them
     *
     * @return array{regressed: list<array<string, mixed>>, unjudged: list<array<string, mixed>>}
     */
    public function judge(string $root, string $candidate, array $paths): array
    {
        $after = $this->observer->observe($candidate, $paths)['observed'];
        if (array_filter($after, self::broken(...)) === []) {
            return ['regressed' => [], 'unjudged' => []];
        }

        $control = BootCandidate::of($root, []);
        try {
            $before = $this->observer->observe($control->path, $paths)['observed'];
        } finally {
            $control->remove();
        }

        return self::relative(self::compare($after, $before), $root);
    }

    /**
     * The causes of a judgment with their paths said as the house's: the copy's own prefix and anything absolute go.
     *
     * A cause is read in the copy (decisions/0539), so its `at` and its message name the copy's files — or, through the
     * copy's linked `vendor/`, the live house's by their absolute name. Both are cut the way a boot reason is (0512).
     *
     * @param array{regressed: list<array<string, mixed>>, unjudged: list<array<string, mixed>>} $judged
     *
     * @return array{regressed: list<array<string, mixed>>, unjudged: list<array<string, mixed>>}
     */
    public static function relative(array $judged, string $root): array
    {
        foreach (['regressed', 'unjudged'] as $kind) {
            foreach ($judged[$kind] as $i => $row) {
                if (\is_array($row['cause'] ?? null)) {
                    $judged[$kind][$i]['cause'] = array_map(static fn (string $v): string => BootProbe::oneLine($v, $root), $row['cause']);
                }
            }
        }

        return $judged;
    }

    /**
     * Compare what the routes answered with the promotion (`$after`) and without it (`$before`), entry by entry.
     *
     * An entry is what {@see HouseRouteObserver::observe()} records: `route`, `status`, and `error` when the request
     * process died. A route absent from `$before` was not declared without the promotion. `cause` travels when the
     * observation carries one — `{class, message, at, reference}`, the line the house logged for the failure, read by
     * greenhouse decisions/0539 (S2).
     *
     * @param list<array<string, mixed>> $after
     * @param list<array<string, mixed>> $before
     *
     * @return array{regressed: list<array<string, mixed>>, unjudged: list<array<string, mixed>>}
     */
    public static function compare(array $after, array $before): array
    {
        $was = [];
        foreach ($before as $entry) {
            $was[(string) ($entry['route'] ?? '')] = $entry;
        }
        $regressed = [];
        $unjudged = [];
        foreach (array_filter($after, self::broken(...)) as $entry) {
            $route = (string) ($entry['route'] ?? '');
            $prior = $was[$route] ?? null;
            $row = ['route' => $route, 'before' => $prior['status'] ?? null, 'after' => $entry['status'] ?? null]
                + (\is_array($entry['cause'] ?? null) ? ['cause' => array_filter($entry['cause'], 'is_string')] : [])
                + (\is_string($entry['error'] ?? null) ? ['error' => $entry['error']] : []);
            if ($prior !== null && self::broken($prior)) {
                $unjudged[] = $row;
            } else {
                $regressed[] = $row;
            }
        }

        return ['regressed' => $regressed, 'unjudged' => $unjudged];
    }

    /**
     * One sentence for the refusal, route by route — what each answered without the promotion and with it.
     *
     * @param list<array<string, mixed>> $regressed rows of {@see compare()}
     */
    public static function sentence(array $regressed): string
    {
        $parts = array_map(static function (array $row): string {
            $after = \is_int($row['after'] ?? null) ? 'HTTP ' . $row['after'] : 'nothing (' . (string) ($row['error'] ?? 'the request process died') . ')';
            $said = \is_int($row['before'] ?? null)
                ? "{$row['route']}: it answered HTTP {$row['before']} without this promotion and {$after} with it"
                : "{$row['route']} is new with this promotion and answers {$after}";

            // The cause as the observer's own note says it (decisions/0539) — one sentence, one form.
            $cause = $row['cause'] ?? null;

            return $said . (\is_array($cause) && isset($cause['message'], $cause['at']) ? ' — ' . RouteFailureCause::oneLine($cause) : '');
        }, $regressed);

        return 'the promotion breaks ' . implode('; ', $parts);
    }

    /** @param array<string, mixed> $entry */
    private static function broken(array $entry): bool
    {
        $status = $entry['status'] ?? null;

        return (\is_int($status) && $status >= 500) || isset($entry['error']);
    }
}
