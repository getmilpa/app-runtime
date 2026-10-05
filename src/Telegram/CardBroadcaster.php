<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Telegram;

use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Psr\Log\LoggerInterface;

/**
 * One more audience of a session's facts: when a fact can change what waits for a person, sweep that session.
 *
 * It builds no card from the push. A push says something happened; what waits is read back from the house
 * ({@see Notifier::sweep()}), the rule the panel follows too (greenhouse decisions/0563 §2). And it never
 * throws: Telegram being away must not look, to the bridge, like the house's hub being away.
 */
final class CardBroadcaster implements SurfaceBroadcaster
{
    /** A pass slower than this many seconds means Telegram is not answering as it should. */
    public const SLOW = 1.0;

    /** How long, in seconds, this audience stays quiet after a slow pass — in this process. */
    public const QUIET = 60.0;

    private float $quietUntil = 0.0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock seconds, monotonic — a test's own; absent, the machine's
     */
    public function __construct(private readonly Notifier $notifier, private readonly ?LoggerInterface $logger = null, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /** Look at the fact's session again when the fact can move a card; otherwise do nothing. */
    public function broadcast(string $topic, array $payload): void
    {
        $session = $payload['session'] ?? null;
        if (!\is_string($session) || $session === '' || !self::moves($payload)) {
            return;
        }
        $this->sweep($session);
    }

    /** An operation that can settle a card ran: look again at its session, or at the whole house when it named none. */
    public function sweep(?string $session): void
    {
        // THE HOUSE'S WORK DOES NOT WAIT ON TELEGRAM TWICE. This runs inside the append of a session's fact; a
        // Telegram that accepts the connection and never answers held one fact for the whole timeout (measured,
        // greenhouse evidence/1106 §9). After one slow pass this audience says nothing for a minute: what it missed
        // is still in the house, and the next pass — or `telegram:notify` — sends it.
        $started = ($this->clock)();
        if ($started < $this->quietUntil) {
            return;
        }
        try {
            $this->notifier->sweep($session === '' ? null : $session);
        } catch (\Throwable $e) {
            // The class and nothing else: a transport's message can carry the URL, and the URL carries the token.
            $this->logger?->warning('[telegram] a sweep failed: ' . $e::class);
        }
        if (($this->clock)() - $started > self::SLOW) {
            $this->quietUntil = ($this->clock)() + self::QUIET;
            $this->logger?->warning('[telegram] a pass was slow; staying quiet for a minute');
        }
    }

    /**
     * Whether this fact can open or settle a card: a question asked or answered, a refused call, a run's end,
     * or a turn the house itself recorded (a grant's notice).
     *
     * @param array<string, mixed> $payload
     */
    private static function moves(array $payload): bool
    {
        $activity = \is_array($payload['activity'] ?? null) ? $payload['activity'] : [];

        return match ($payload['kind'] ?? null) {
            'waiting', 'answered', 'run_ended' => true,
            'activity' => (($activity['state'] ?? null) === 'tool' && ($activity['ok'] ?? true) === false)
                || (($activity['role'] ?? null) === 'user' && \is_string($activity['text'] ?? null) && str_starts_with($activity['text'], \Milpa\AppRuntime\Agent\SeatFrontier::NOTICE_PREFIX)),
            default => false,
        };
    }
}
