<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\ProgressReceipt;
use Milpa\EventStore\Event;

/** Bounded source discovery is permission to keep exploring, never evidence of delivery. */
final readonly class ExplorationReceipt
{
    /** Includes every model call in the session, including calls before a continuation. */
    public const CALL_LIMIT = 12;

    /** @param list<string> $identities New content fingerprints observed in the last model round. */
    private function __construct(
        public int $calls,
        public int $modelSeq,
        public array $identities,
        public bool $recovering,
    ) {
    }

    /**
     * Derive novelty from successful producer results, never model assertions or argument changes.
     * Paths, cursors and other metadata do not turn repeated content into a new observation.
     *
     * @param list<Event> $events The session's complete stream, in order.
     */
    public static function of(array $events): self
    {
        $calls = 0;
        $modelSeq = 0;
        $last = 0;
        $stall = 0;
        $seen = [];
        $identities = [];
        foreach ($events as $event) {
            $last = max($last, $event->seq);
            if ($event->type === 'session.progress_stalled') {
                $stall = $event->seq;
            }
            if ($event->type === 'session.model_called') {
                ++$calls;
                $modelSeq = $event->seq;
                $identities = [];
            }
            if ($event->type !== 'session.tool_called') {
                continue;
            }
            $identity = self::identity($event->payload);
            if ($identity === null || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            if ($modelSeq > 0) {
                $identities[] = $identity;
            }
        }

        return new self(
            $calls,
            $modelSeq,
            $identities,
            $stall > 0 && ProgressReceipt::of($events, $stall, $last)->progress !== ProgressReceipt::ADVANCING
        );
    }

    /** New content may defer a first stall only within the fixed session allowance. */
    public function permitsExploration(): bool
    {
        return !$this->recovering && $this->calls < self::CALL_LIMIT && $this->identities !== [];
    }

    /** Telemetry preserves the distinction from material progress.
     * @return array{calls: int, callLimit: int, modelSeq: int, identities: list<string>}
     */
    public function toArray(): array
    {
        return ['calls' => $this->calls, 'callLimit' => self::CALL_LIMIT,
            'modelSeq' => $this->modelSeq, 'identities' => $this->identities];
    }

    /** @param array<string, mixed> $payload A recorded native tool result. */
    private static function identity(array $payload): ?string
    {
        if (($payload['ok'] ?? null) !== true || ($payload['mutating'] ?? null) !== false
            || ($payload['awaitingConfirmation'] ?? false) !== false) {
            return null;
        }
        $field = match ($payload['tool'] ?? null) {
            'source_read', 'source_page' => 'content',
            'skill_load' => 'body',
            default => null,
        };
        if ($field === null || !\is_string($payload['result'] ?? null)) {
            return null;
        }
        $result = json_decode($payload['result'], true);
        if (!\is_array($result) || ($result['ok'] ?? null) !== true
            || !\is_string($result[$field] ?? null) || trim($result[$field]) === '') {
            return null;
        }

        // A page is an observation, not a claim that the whole source reached the model.
        // Different overlapping pages may contain novelty; the hard call ceiling still applies.
        return hash('sha256', $result[$field]);
    }
}
