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

use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionFacts;
use Milpa\EventStore\Event;

/**
 * The closure the HOUSE derives for a session that kept no record of its own (greenhouse decisions/0487).
 *
 * ── THE DEBT THIS PAYS, MEASURED (greenhouse evidence/1017) ─────────────────────────────────────
 *
 * 20–21 of 24 resident sessions never open a todo. They promote their rehearsal and the house observes
 * the screen served in the house — a receipt the house itself produced — and still the closure verdict
 * said «no positive verification evidence recorded», so the epilogue (0477) never opened for them. The
 * session was asked to keep a record the house already holds.
 *
 * ── WHAT COUNTS, READ ONLY FROM THE STREAM ──────────────────────────────────────────────────────
 *
 * - A CHANGE to the house: a succeeded mutating call that was not kept in a rehearsal — a promotion, or
 *   any write whose receipt does not declare a trial environment. Failed calls and calls still awaiting
 *   confirmation change nothing, and neither does a call that ran in a trial and was not applied
 *   (`ran_in_trial` without `applied`, greenhouse decisions/0494 §4): the rehearsal did not land.
 * - An OBSERVATION of the house: a succeeded call whose receipt declares the predicate `served` in the
 *   HOUSE environment — a screen observed ({@see \Milpa\AppRuntime\Web\ScreenOperations}), or a route
 *   the promotion that landed it observed through the house's own front controller (`observed`,
 *   {@see HouseRouteObserver}, decisions/0494). A trial's served receipt is the rehearsal speaking, not
 *   the house, and a result that ran in a trial observes nothing about the house.
 *
 * Closure is derived when the last observation comes at or after the last change, and it is still fresh:
 * for a screen, no later forget or failed re-declare ({@see SessionFacts::evidenceByPredicate()}); for a
 * route, no later observation of it that did not answer 200. And no route the house observed is left
 * answering a server error, nor a house that did not boot to be observed. Nothing is read from prose or
 * from the goal; nothing re-runs.
 *
 * ── WHAT IT DOES NOT PROVE ──────────────────────────────────────────────────────────────────────
 *
 * That the observed subject satisfies criteria never declared, or that every subject a change touched was
 * observed: a promotion names paths, not screens. The scope says so — `house_observation` — beside the verdict.
 *
 * ── ONLY WHAT THE GOAL NAMES CLOSES (greenhouse decisions/0522) ─────────────────────────────────
 *
 * Given the subjects the goal names ({@see StandingAsk::namesSubject()}), an observation of any OTHER subject is
 * not an observation of the work: it never closes, and the reason says what was seen instead. Measured
 * (evidence/1050): registering an empty plugin made the house observe `GET /` → 200, and that closed a session
 * whose goal was `GET /blog` — while `/blog` answered 404 and the resident itself said the goal was not met.
 * Every route the house answered still counts against it: a server error on an unnamed route is still an error.
 *
 * ── A TEST RUN IS NOT A CHANGE (greenhouse decisions/0523) ──────────────────────────────────────
 *
 * Given the house's catalogue ({@see LastingCalls}), a recorded mutating call counts as a change only when its
 * operation's own declaration says it lasts. Measured (evidence/1050): three green `test` calls after /blog was
 * observed served made the observation «stale», and the closure never came. The declaration only SUBTRACTS: a call
 * recorded as not mutating never becomes a change, and a tool the catalogue does not declare keeps its flag. And a
 * declaration is witnessed where the operation reports it: a call whose result names what it wrote into the house
 * (`house_writes`, milpa/devtools' test run) counts when it wrote anything, or when it could not tell (null).
 *
 * ── A SCAFFOLD SERVED IS NOT THE WORK (greenhouse decisions/0554) ───────────────────────────────
 *
 * Naming the route is not enough: the house must not take the scaffold's answer for the work. Measured (evidence/1081,
 * D1): `make controller` landed, `GET /blog` answered 200 with the stub's 26 bytes («BlogController is running.»), and
 * a session without todos was verified on it — the epilogue opened over a page with no posts. And live (evidence/1088):
 * `make crud` landed `/posts` answering an empty list, the goal's word «posts» named it, and the house verified the
 * session while `/blog` answered 404. So a route is a scaffold's in two ways: a change that landed nothing but `make`'s
 * routed scaffolds (controller, crud, resource) gives every route the house sees for the first time to that scaffold;
 * and `make controller` stands a scaffold for the route its arguments say, whenever the house first sees it. A scaffold
 * stands until another writer lands its file (or, when the stream does not say which files, its plugin); the body the
 * house first sees that route answer while the scaffold stands is the scaffold's. An observation of that body — then, or after, by a promotion or by `route:observe` — is not an
 * observation of the work, and the reason says so. Any other body counts as before. Read from the receipts alone: the
 * writers of a promotion are the calls recorded in its trial; nothing re-runs and no stub is rendered.
 *
 * ── A ROUTE THE GOAL WRITES IS THE ONLY ONE THAT CLOSES (greenhouse decisions/0555) ─────────────
 *
 * A `/posts` WITH data would still have closed that session: the scaffold rule knows a generated body, not which route
 * was asked for. When the goal writes a route explicitly (`GET /blog`), {@see StandingAsk::namesSubject()} names nothing
 * else, so an observation of any other route — its scaffold's body or the work's — is said in the reason and never
 * counted: «the house observed «/posts» served (seq 201), and the goal writes «GET /blog»: only a route the goal
 * writes closes it».
 *
 * ── A SCAFFOLD'S BODY IS THE SCAFFOLD'S ON ANY ROUTE (greenhouse decisions/0556) ────────────────
 *
 * The scaffold was known by the route it was born on. Measured live (evidence/1089): `make crud` landed `/posts`
 * answering its empty list, the resident edited the route to `/blog`, and the house observed `/blog` serving those same
 * 34 bytes — a route it had seen before (404), changed by an `edit` — and counted it. So every body the house learned as
 * a scaffold's is a scaffold's wherever it is served, for the rest of the session: an observation of those bytes on
 * another route does not close either, and the reason says «the body of a scaffold». Bytes the house never learned from a
 * scaffold count as before, and so does a receipt without a digest on a route with no scaffold of its own.
 */
final class HouseObservedClosure
{
    /** What `make` scaffolds with routes of its own: the house can see these answer before anyone wrote the work. */
    private const ROUTED = ['controller', 'crud', 'resource'];

    /**
     * Derive whether the house observed itself serving after the last change that landed in it.
     *
     * @param list<Event>                                          $stream  the session's own stream, in order
     * @param (\Closure(string): bool)|null                        $named   whether the goal names an observed subject; null counts every subject
     * @param (\Closure(string, array<string, mixed>): ?bool)|null $lasting whether a call's own declaration says it lasts
     *                                                                      ({@see LastingCalls}); null reads the recorded flag alone
     * @param list<string>                                         $written the routes the goal writes explicitly
     *                                                                      ({@see StandingAsk::explicitRoutes()}): for the reason, and
     *                                                                      so a receipt served elsewhere is not taken for one of them
     *
     * @return array{derived: bool, reason: ?string, observation: ?array{subject: string, seq: int, content?: array<string, mixed>|string, surface?: array<string, mixed>}, lastChangeSeq: ?int, landed: list<int>, unlisted: list<string>}
     */
    public static function of(array $stream, SessionFacts $facts, ?\Closure $named = null, ?\Closure $lasting = null, array $written = []): array
    {
        $counts = $named ?? static fn (string $subject): bool => true;
        // The last observation of a subject the goal does not name: said in the reason, never counted.
        $unnamed = null;
        $lastChange = null;
        $landed = [];
        $observation = null;
        // route => [seq, status] of the last time the house answered it, and whether that was «served».
        $routes = [];
        $unobservable = null;
        // workspace => the writers that ran in that trial, so a promotion knows who wrote what it landed.
        $trials = [];
        // route => the scaffold `make controller` landed for it: its file, whether nothing else has written that file
        // since, and the body the house first saw it answer while it stood (decisions/0554).
        $scaffolds = [];
        // The last observation of a named route answered by its scaffold: said in the reason, never counted.
        $scaffolded = null;
        // route => why the last page the house saw there does not list what its screen had to, or null when it does
        // or the house did not judge it (decisions/0576).
        $unlisted = [];
        // sha256 => true for every body the house learned as a scaffold's: those bytes are a scaffold's on any route
        // (decisions/0556).
        $bodies = [];
        foreach ($stream as $event) {
            if ($event->type !== SessionEvent::ToolCalled->value) {
                continue;
            }
            $payload = $event->payload;
            $result = json_decode(\is_string($payload['result'] ?? null) ? $payload['result'] : '', true);
            $readable = \is_array($result);
            $result = \is_array($result) ? $result : [];
            if (($payload['ok'] ?? true) !== true || ($result['ok'] ?? true) === false) {
                continue;
            }
            $evidence = $result['evidence'] ?? (\is_array($result['output'] ?? null) ? ($result['output']['evidence'] ?? null) : null);
            $evidence = \is_array($evidence) ? $evidence : [];
            $environment = \is_array($evidence['environment'] ?? null) ? ($evidence['environment']['kind'] ?? null) : null;
            $rehearsed = ($result['ran_in_trial'] ?? false) === true && ($result['applied'] ?? false) !== true;
            $generated = null;

            if (($payload['mutating'] ?? false) === true && ($payload['awaitingConfirmation'] ?? null) !== true
                && $environment !== 'trial' && !$rehearsed && self::lasts($payload, $readable ? $result : null, $lasting)) {
                $lastChange = $event->seq;
                $landed[] = $event->seq;
                $writers = self::writersOf($payload, $result, $evidence, $trials);
                $scaffolds = self::afterLanding($scaffolds, $writers);
                $generated = self::generatedBy($writers);
            }
            if ($rehearsed && \is_string($result['workspace'] ?? null)) {
                $trials[$result['workspace']][] = self::writer($payload, $result);
            }
            if (($evidence['predicate'] ?? null) === 'served' && $environment === 'house'
                && ($evidence['invalidates'] ?? false) !== true && \is_string($evidence['subject'] ?? null)) {
                // A SCREEN SERVED AT ITS OWN PAGE IS NOT THE ROUTE THE GOAL WRITES (greenhouse decisions/0576 §8).
                // Measured (evidence/1110): `screen:observe` of a screen named «blog» — a receipt of
                // `/live/page?component=blog`, which the house does not judge — closed a goal that writes `GET /blog`.
                // When the goal writes a route, a receipt that says where it was served counts only if it was there.
                $at = \is_string($evidence['servedAt'] ?? null) ? explode('?', $evidence['servedAt'], 2)[0] : $evidence['subject'];
                if ($counts($evidence['subject']) && ($written === [] || $counts($at))) {
                    $observation = ['subject' => $evidence['subject'], 'seq' => $event->seq];
                } else {
                    $unnamed = ['subject' => $evidence['subject'], 'seq' => $event->seq]
                        + ($at === $evidence['subject'] ? [] : ['at' => $evidence['servedAt']]);
                }
            }
            if ($rehearsed) {
                continue;
            }
            if (\is_string($result['observation_error'] ?? null)) {
                $unobservable = ['seq' => $event->seq, 'error' => $result['observation_error']];
            } elseif (\is_array($result['observed'] ?? null)) {
                $unobservable = null;
            }
            $served = null;
            // The plugin whose routes this very change generated, when all it landed was `make`'s routed scaffolds.
            $born = $generated;
            foreach (\is_array($result['observed'] ?? null) ? $result['observed'] : [] as $entry) {
                if (!\is_array($entry) || !\is_string($entry['subject'] ?? null)
                    || (\is_array($entry['environment'] ?? null) ? ($entry['environment']['kind'] ?? null) : null) !== 'house') {
                    continue;
                }
                $status = \is_int($entry['status'] ?? null) ? $entry['status'] : null;
                $isServed = ($entry['predicate'] ?? null) === 'served' && $status === 200;
                $key = trim($entry['subject'], '/');
                if ($isServed && $born !== null && ! isset($routes[$entry['subject']]) && ! isset($scaffolds[$key])) {
                    $scaffolds[$key] = ['file' => null, 'plugin' => $born['plugin'], 'standing' => true, 'body' => null];
                }
                $routes[$entry['subject']] = ['seq' => $event->seq, 'status' => $status, 'served' => $isServed,
                    'everServed' => $isServed || ($routes[$entry['subject']]['everServed'] ?? false)];
                if ($isServed && isset($scaffolds[$key])) {
                    $sha = \is_string($entry['sha256'] ?? null) ? $entry['sha256'] : null;
                    if ($scaffolds[$key]['standing'] && $scaffolds[$key]['body'] === null) {
                        $scaffolds[$key]['body'] = $sha;
                        if ($sha !== null) {
                            $bodies[$sha] = true;
                        }
                    }
                    if (($sha !== null && $sha === $scaffolds[$key]['body']) || ($sha === null && $scaffolds[$key]['standing'])) {
                        if ($counts($entry['subject'])) {
                            $scaffolded = ['subject' => $entry['subject'], 'seq' => $event->seq, 'whose' => 'its'];
                        } else {
                            $unnamed = ['subject' => $entry['subject'], 'seq' => $event->seq];
                        }
                        continue;
                    }
                }
                // The bytes of a scaffold on a route that scaffold was not born on: still what `make` generated.
                if ($isServed && \is_string($entry['sha256'] ?? null) && isset($bodies[$entry['sha256']])) {
                    if ($counts($entry['subject'])) {
                        $scaffolded = ['subject' => $entry['subject'], 'seq' => $event->seq, 'whose' => 'a'];
                    } else {
                        $unnamed = ['subject' => $entry['subject'], 'seq' => $event->seq];
                    }
                    continue;
                }
                // A PAGE THAT DOES NOT LIST IS NOT THE WORK (greenhouse decisions/0576). The receipt of a mounted screen
                // says what it listed against what it had to; one with nothing to read, part of what is public, or what
                // is not public, does not count — and the last thing the house saw of that route decides.
                $content = \is_array($entry['content'] ?? null) ? $entry['content'] : null;
                if ($isServed && $counts($entry['subject'])) {
                    $unlisted[$entry['subject']] = $content === null ? null : self::doesNotList($content, $entry['subject'], $event->seq);
                }
                if ($isServed && ! $counts($entry['subject'])) {
                    $unnamed = ['subject' => $entry['subject'], 'seq' => $event->seq];
                } else {
                    // WHAT THE HOUSE DID WITH THE PAGE IT CLOSES ON (greenhouse decisions/0579 §3–4): it read it
                    // (`content`, decisions/0576); it is a page and it did not («unjudged» — the closure stands, and
                    // the verdict says so); or what answered is no page, and nothing is said.
                    $surface = \is_array($entry['surface'] ?? null) ? $entry['surface'] : null;
                    $read = match (true) {
                        $content !== null => ['content' => $content],
                        ($surface['kind'] ?? null) === 'visual' => ['content' => 'unjudged'],
                        default => [],
                    };
                    $served ??= $isServed ? ['subject' => $entry['subject'], 'seq' => $event->seq] + $read + ($surface === null ? [] : ['surface' => $surface]) : null;
                }
            }
            $observation = $served ?? $observation;
        }

        // A route the house answered with a server error, or with nothing, is not served — and one it served
        // before and no longer answers 200 went stale, whatever else was observed since.
        $failing = [];
        $stale = [];
        foreach ($routes as $route => $answer) {
            if ($answer['status'] === null || $answer['status'] >= 500) {
                $failing[] = "the house answered «{$route}» with " . ($answer['status'] === null ? 'nothing' : "HTTP {$answer['status']}") . " at seq {$answer['seq']}";
            } elseif (! $answer['served'] && $answer['everServed']) {
                $stale[] = "the house observation of «{$route}» went stale: it answered HTTP {$answer['status']} at seq {$answer['seq']}";
            }
        }

        $reason = null;
        if ($unobservable !== null) {
            $reason = "the house could not be observed after the change at seq {$unobservable['seq']}: {$unobservable['error']}";
        } elseif ($failing !== []) {
            $reason = implode('; ', $failing);
        } elseif (array_filter($unlisted) !== []) {
            $reason = implode('; ', array_filter($unlisted));
        } elseif ($observation === null && ($scaffolded !== null || $unnamed !== null)) {
            // A route that went stale is the stronger fact; then a named route its scaffold answered; otherwise, say what
            // was seen instead of the work.
            $reason = match (true) {
                $stale !== [] => implode('; ', $stale),
                $scaffolded !== null => "the house observed «{$scaffolded['subject']}» serving the body of {$scaffolded['whose']} scaffold (seq {$scaffolded['seq']}):"
                    . ' what «make» generated is not the work',
                $written !== [] => "the house observed «{$unnamed['subject']}» served" . (isset($unnamed['at']) ? " at «{$unnamed['at']}»" : '')
                    . " (seq {$unnamed['seq']}), and the goal writes «"
                    . implode('», «', $written) . '»: only a route the goal writes closes it',
                default => "the house observed «{$unnamed['subject']}» served (seq {$unnamed['seq']}), a subject the goal does not name",
            };
        } elseif ($observation === null) {
            $reason = 'nothing observed served in the house';
        } elseif ($stale !== []) {
            $reason = implode('; ', $stale);
        } elseif ($lastChange !== null && $lastChange > $observation['seq']) {
            $reason = "the house changed at seq {$lastChange} after its last observation (seq {$observation['seq']})";
        } elseif (! isset($routes[$observation['subject']])
            && ($facts->evidenceByPredicate('served', $observation['subject'])['evidence']['fresh'] ?? false) !== true) {
            $reason = "the house observation of «{$observation['subject']}» went stale";
        }

        return ['derived' => $reason === null, 'reason' => $reason, 'observation' => $observation, 'lastChangeSeq' => $lastChange, 'landed' => $landed,
            'unlisted' => array_values(array_filter($unlisted))];
    }

    /**
     * Why a judged page does not list what its screen had to, or null when it does: at least one public row, every
     * public row shown, and none that is not public (decisions/0576).
     *
     * @param array<string, mixed> $content the `content` of a `served` receipt
     */
    private static function doesNotList(array $content, string $subject, int $seq): ?string
    {
        $entity = \is_string($content['entity'] ?? null) ? $content['entity'] : 'its entity';
        $public = \is_int($content['public'] ?? null) ? $content['public'] : 0;
        $shown = \is_int($content['shown'] ?? null) ? $content['shown'] : 0;
        $leaked = \is_int($content['leaked'] ?? null) ? $content['leaked'] : 0;

        $saw = "the house observed «{$subject}» served";

        return match (true) {
            $public < 1 => "{$saw} with nothing to read (seq {$seq}): its screen lists {$entity} and no public row exists — leave rows with entity:seed",
            $shown < $public => "{$saw} showing {$shown} of {$public} public rows of {$entity} (seq {$seq})",
            $leaked > 0 => "{$saw} showing {$leaked} row" . ($leaked === 1 ? '' : 's') . " of {$entity} that is not public (seq {$seq})",
            default => null,
        };
    }

    /**
     * Whether the call's own declaration says it lasts — true when there is no classifier or it does not know the tool,
     * and true whatever it declares when its result says it wrote into the house, or could not tell — or when the
     * recorded result cannot be read whole, so its witness cannot be either.
     *
     * @param array<string, mixed>                                 $payload
     * @param array<mixed>|null                                    $result  null when the recorded result is not a readable object
     * @param (\Closure(string, array<string, mixed>): ?bool)|null $lasting
     */
    private static function lasts(array $payload, ?array $result, ?\Closure $lasting): bool
    {
        if ($lasting === null || ! \is_string($payload['tool'] ?? null) || $result === null) {
            return true;
        }
        if (\array_key_exists('house_writes', $result) && $result['house_writes'] !== []) {
            return true;
        }

        return $lasting($payload['tool'], \is_array($payload['arguments'] ?? null) ? $payload['arguments'] : []) ?? true;
    }

    /**
     * One writer as the stream recorded it: the tool, its arguments, and the house-relative paths it changed (null when
     * its result does not say).
     *
     * @param array<string, mixed> $payload
     * @param array<mixed>         $result
     *
     * @return array{tool: ?string, arguments: array<mixed>, changed: ?list<string>}
     */
    private static function writer(array $payload, array $result): array
    {
        return [
            'tool' => \is_string($payload['tool'] ?? null) ? $payload['tool'] : null,
            'arguments' => \is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [],
            'changed' => \is_array($result['changed'] ?? null) ? array_map('strval', array_keys($result['changed'])) : null,
        ];
    }

    /**
     * Who wrote what a change landed: for a promotion, the writers that ran in its trial (or, when the stream does not
     * hold them, an unknown writer of every promoted path); for any other change, the call itself.
     *
     * @param array<string, mixed>                                                                       $payload
     * @param array<mixed>                                                                               $result
     * @param array<mixed>                                                                               $evidence
     * @param array<string, list<array{tool: ?string, arguments: array<mixed>, changed: ?list<string>}>> $trials
     *
     * @return list<array{tool: ?string, arguments: array<mixed>, changed: ?list<string>}>
     */
    private static function writersOf(array $payload, array $result, array $evidence, array $trials): array
    {
        $from = \is_array($evidence['from'] ?? null) ? ($evidence['from']['workspace'] ?? null) : null;
        if (($evidence['predicate'] ?? null) !== 'promoted' || ! \is_string($from)) {
            return [self::writer($payload, $result)];
        }
        $promoted = \is_array($result['promoted'] ?? null) ? array_values(array_filter($result['promoted'], 'is_string')) : null;

        return $trials[$from] ?? [['tool' => null, 'arguments' => [], 'changed' => $promoted]];
    }

    /**
     * The scaffolds standing after a change landed (greenhouse decisions/0554): `make controller` stands a scaffold for
     * its route, and any other writer of that scaffold's file — or, when either side does not say which files, of its
     * plugin — takes it down. Another `make` never does: a scaffold is not the work.
     *
     * @param array<string, array{file: ?string, plugin: ?string, standing: bool, body: ?string}> $scaffolds
     * @param list<array{tool: ?string, arguments: array<mixed>, changed: ?list<string>}>         $writers
     *
     * @return array<string, array{file: ?string, plugin: ?string, standing: bool, body: ?string}>
     */
    private static function afterLanding(array $scaffolds, array $writers): array
    {
        foreach ($writers as $writer) {
            $arguments = $writer['arguments'];
            $plugin = \is_string($arguments['plugin'] ?? null) ? $arguments['plugin'] : null;
            if ($writer['tool'] === 'make') {
                $route = self::scaffoldedRoute($arguments);
                if ($route !== null) {
                    $name = \is_string($arguments['name'] ?? null) ? $arguments['name'] : '';
                    $file = null;
                    foreach ($writer['changed'] ?? [] as $path) {
                        $file ??= $name !== '' && str_ends_with($path, "/{$name}.php") ? $path : null;
                    }
                    $scaffolds[$route] = ['file' => $file, 'plugin' => $plugin, 'standing' => true, 'body' => null];
                }
                continue;
            }
            foreach ($scaffolds as $route => $scaffold) {
                $touched = $scaffold['file'] !== null && $writer['changed'] !== null
                    ? \in_array($scaffold['file'], $writer['changed'], true)
                    : $plugin !== null && $plugin === $scaffold['plugin'];
                if ($touched) {
                    $scaffolds[$route]['standing'] = false;
                }
            }
        }

        return $scaffolds;
    }

    /**
     * What a change generated when every writer it landed was `make` scaffolding something routed (a controller, a crud,
     * a resource): the plugin those scaffolds belong to. Null when anything else was written with them — then the routes
     * the house sees for the first time are not known to be a scaffold's.
     *
     * @param list<array{tool: ?string, arguments: array<mixed>, changed: ?list<string>}> $writers
     *
     * @return array{plugin: ?string}|null
     */
    private static function generatedBy(array $writers): ?array
    {
        $plugin = null;
        foreach ($writers as $writer) {
            if ($writer['tool'] !== 'make' || ! \in_array($writer['arguments']['what'] ?? null, self::ROUTED, true)) {
                return null;
            }
            $plugin = \is_string($writer['arguments']['plugin'] ?? null) ? $writer['arguments']['plugin'] : $plugin;
        }

        return $writers === [] ? null : ['plugin' => $plugin];
    }

    /**
     * The route a `make controller` call scaffolds, as milpa/devtools derives it: its `path`, else its `route`, else the
     * controller's name without «Controller», lowercased — without slashes at either end, as observed subjects are
     * compared here. Null for anything `make` writes that is not a controller.
     *
     * @param array<mixed> $arguments
     */
    private static function scaffoldedRoute(array $arguments): ?string
    {
        if (($arguments['what'] ?? null) !== 'controller') {
            return null;
        }
        $route = $arguments['path'] ?? $arguments['route'] ?? null;
        if (\is_string($route) && $route !== '') {
            return trim($route, '/');
        }

        return \is_string($arguments['name'] ?? null) ? strtolower(str_replace('Controller', '', $arguments['name'])) : null;
    }
}
