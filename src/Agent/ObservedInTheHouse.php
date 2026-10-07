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

use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionFacts;
use Milpa\EventStore\Event;

/**
 * What the house observed of one route, asked by whoever needs a receipt of it — the claims judge (greenhouse
 * decisions/0580).
 *
 * The house observes a route when a promotion lands and when `route:observe` is asked, and leaves what it saw
 * under `observed[]`. Its own closure reads those receipts ({@see HouseObservedClosure}), under the contract
 * greenhouse decisions/0576 fixed for anyone who derives from them. This class does not judge a receipt a second
 * time: it finds the last thing the house saw of a subject and asks that same reading whether it closes.
 */
final class ObservedInTheHouse
{
    /**
     * The last thing the house saw of each subject it observed IN ITSELF — never in the copy of a trial.
     *
     * @param list<Event> $stream the session's own stream, in order
     *
     * @return array<string, array{seq: int, status: ?int, served: bool}>
     */
    public static function last(array $stream): array
    {
        $last = [];
        foreach ($stream as $event) {
            if ($event->type !== SessionEvent::ToolCalled->value || ($event->payload['ok'] ?? true) !== true) {
                continue;
            }
            $result = json_decode(\is_string($event->payload['result'] ?? null) ? $event->payload['result'] : '', true);
            // What a rehearsal observed, it observed in its copy (greenhouse decisions/0463).
            if (!\is_array($result) || ($result['ok'] ?? true) === false || (($result['ran_in_trial'] ?? false) === true && ($result['applied'] ?? false) !== true)) {
                continue;
            }
            foreach (\is_array($result['observed'] ?? null) ? $result['observed'] : [] as $entry) {
                if (!\is_array($entry) || !\is_string($entry['subject'] ?? null) || ($entry['environment']['kind'] ?? null) !== 'house') {
                    continue;
                }
                $status = \is_int($entry['status'] ?? null) ? $entry['status'] : null;
                $last[$entry['subject']] = ['seq' => $event->seq, 'status' => $status, 'served' => ($entry['predicate'] ?? null) === 'served' && $status === 200];
            }
        }

        return $last;
    }

    /**
     * Whether the house's own receipt of `$subject` covers a claim that it is served — null when the house never
     * observed that subject, so there is nothing of this kind to speak of.
     *
     * The verdict is the house's closure reading asked of this one subject: a 200, the last thing the house saw of
     * it, after the last change that landed, not the body of a scaffold, no observed route failing — and, when the
     * receipt says what the page listed, a page that lists. The `content` and `surface` handed back are the
     * house's own reading of the receipt, never computed here: what the page listed; «unjudged» for a page the
     * house saw served and did not read, which closes and says so (greenhouse decisions/0579); and nothing for
     * what is no page.
     *
     * @param list<Event>                                          $stream  the session's own stream, in order
     * @param (\Closure(string, array<string, mixed>): ?bool)|null $lasting whether a call's own declaration says it lasts
     *
     * @return array{covers: true, seq: int, content?: array<string, mixed>|string, surface?: array<string, mixed>}|array{covers: false, reason: string}|null
     */
    public static function served(array $stream, SessionFacts $facts, string $subject, ?\Closure $lasting = null): ?array
    {
        $last = self::last($stream)[$subject] ?? null;
        if ($last === null) {
            return null;
        }
        if (!$last['served']) {
            return ['covers' => false, 'reason' => "the house answered «{$subject}» with "
                . ($last['status'] === null ? 'nothing' : "HTTP {$last['status']}") . " at seq {$last['seq']}"];
        }

        $closure = HouseObservedClosure::of($stream, $facts, static fn (string $observed): bool => $observed === $subject, $lasting);
        $observation = $closure['observation'];
        if ($closure['derived'] && $observation !== null) {
            // What the house did with the page travels as the house said it (greenhouse decisions/0579): the content
            // it read, «unjudged» for a page it saw served and did not read, nothing for what is no page.
            return ['covers' => true, 'seq' => $observation['seq']] + array_intersect_key($observation, ['content' => true, 'surface' => true]);
        }

        return ['covers' => false, 'reason' => $closure['reason'] ?? "what the house last observed of «{$subject}» does not close it"];
    }
}
