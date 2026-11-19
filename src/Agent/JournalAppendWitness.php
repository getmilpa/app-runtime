<?php

/**
 * This file is part of milpa/app-runtime.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;

/** One bridge invocation's host-only append witness; it grants no authority and never reads a tool result. */
final class JournalAppendWitness
{
    public const CONTEXT_KEY = 'milpa.session.append_witness';
    private ?object $entry = null;
    private ?Event $confirmed = null;
    private bool $expired = false;
    /** @var array<string, mixed> bounded input held only until this invocation ends */
    private array $input = [];

    public function __construct(
        private readonly object $owner,
        private readonly SessionHouseDebt $producer,
        private readonly string $session,
        private readonly ?int $firstSeq,
        private readonly string $argumentsDigest,
    ) {
    }

    /** Enter only once, with the exact producer object and input of this invocation.
     * @param array<string, mixed> $input
     */
    public function enter(SessionHouseDebt $producer, array $input): ?object
    {
        if ($this->expired || $this->entry !== null || $this->producer !== $producer
            || $this->argumentsDigest !== ConsentBridge::digest($input)) {
            return null;
        }

        $this->input = $input;

        return $this->entry = new \stdClass();
    }

    /** The fixed producer calls this only for the exact new event it appended and read back successfully. */
    public function confirm(SessionHouseDebt $producer, object $entry, Event $event): void
    {
        if (!$this->expired && $this->confirmed === null && $this->producer === $producer && $this->entry === $entry
            && $this->firstSeq !== null && $event->seq >= $this->firstSeq
            && $event->streamId === SessionStore::PREFIX . $this->session && $event->type === SessionHouseDebt::EVENT
            && $producer->confirms($event, $this->input)) {
            $this->confirmed = $event;
        }
    }

    /** Only the bridge that minted this witness may consume its one observation. */
    public function consume(object $owner): bool
    {
        if ($this->owner !== $owner || $this->expired) {
            return false;
        }
        $written = $this->confirmed !== null;
        $this->expire($owner);

        return $written;
    }

    /** Every return or throw ends this invocation's capability, including unconsumed/negative observations. */
    public function expire(object $owner): void
    {
        if ($this->owner === $owner) {
            $this->expired = true;
            $this->entry = null;
            $this->confirmed = null;
            $this->input = [];
        }
    }
}
