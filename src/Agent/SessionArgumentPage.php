<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\ToolRuntime\Contracts\ResultBudget;

/** Pages a recorded textual argument, without copying it into another store or judging its code. */
final readonly class SessionArgumentPage
{
    public function __construct(private SessionStore $sessions)
    {
    }

    /**
     * Read a complete JSON page using the transport bound and immutable call identity.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function read(array $input, ?ResultBudget $budget): array
    {
        if (\array_key_exists('max_chars', $input)) {
            if (!\is_int($input['max_chars']) || $input['max_chars'] < 256) {
                return ['ok' => false, 'error' => 'max_chars must be an integer of at least 256'];
            }
            $budget = $budget?->tightenedTo($input['max_chars']) ?? ResultBudget::json($input['max_chars']);
        }
        if ($budget === null) {
            return ['ok' => false, 'error' => 'agent:argument needs a transport result budget or explicit max_chars'];
        }
        $session = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        $seq = $input['seq'] ?? null;
        $argument = $input['argument'] ?? null;
        if ($session === '' || !\is_int($seq) || $seq < 1 || !\is_string($argument) || $argument === '') {
            return ['ok' => false, 'error' => 'session, positive integer seq and argument name are required'];
        }
        $events = $this->sessions->stream($session);
        $selected = array_values(array_filter($events, static fn ($event): bool => $event->seq === $seq));
        if (\count($selected) !== 1 || $selected[0]->type !== 'session.tool_called') {
            return ['ok' => false, 'error' => 'seq must identify one recorded tool call in this session'];
        }
        $call = $selected[0]->payload;
        $content = $call['arguments'][$argument] ?? null;
        if (!\is_string($content) || !mb_check_encoding($content, 'UTF-8')) {
            return ['ok' => false, 'error' => 'argument must name a recorded UTF-8 string'];
        }
        if (!\is_string($call['tool'] ?? null) || !\is_bool($call['ok'] ?? null)) {
            return ['ok' => false, 'error' => 'recorded call has invalid tool or outcome metadata'];
        }
        $digest = hash('sha256', $content);
        $identity = ['v' => 1, 'session' => $session, 'seq' => $seq, 'argument' => $argument, 'sha256' => $digest];
        $total = \strlen($content);
        $offset = 0;
        if (\array_key_exists('cursor', $input)) {
            $cursor = $this->decodeCursor($input['cursor']);
            if ($cursor === null) {
                return ['ok' => false, 'error' => 'invalid argument cursor'];
            }
            $offset = $cursor['offset'];
            unset($cursor['offset']);
            if ($cursor !== $identity) {
                return ['ok' => false, 'error' => 'argument cursor does not match this session, call and content'];
            }
            if ($offset < 0 || $offset > $total || !mb_check_encoding(substr($content, 0, $offset), 'UTF-8')) {
                return ['ok' => false, 'error' => 'argument cursor offset must be a UTF-8 byte boundary inside the argument'];
            }
        }
        $page = function (string $part) use ($identity, $offset, $total, $call): array {
            $next = $offset + \strlen($part);

            return ['ok' => true, 'session' => $identity['session'], 'seq' => $identity['seq'],
                'argument' => $identity['argument'], 'tool' => $call['tool'], 'call_ok' => $call['ok'],
                'sha256' => $identity['sha256'], 'offset' => $offset, 'next_offset' => $next,
                'total_bytes' => $total, 'content' => $part,
                'next_cursor' => $next === $total ? null : $this->encodeCursor([...$identity, 'offset' => $next])];
        };
        $remaining = substr($content, $offset);
        $full = $page($remaining);
        if ($budget->fits($full)) {
            return $full;
        }
        $low = 0;
        $high = mb_strlen($remaining, 'UTF-8');
        $best = null;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $candidate = $page(mb_substr($remaining, 0, $mid, 'UTF-8'));
            if ($budget->fits($candidate)) {
                $best = $candidate;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        if ($best === null || $best['next_offset'] === $offset) {
            return ['ok' => false, 'error' => 'result budget cannot fit argument metadata and one character'];
        }

        return $best;
    }

    /** @param array<string, int|string> $cursor A continuation identity and position, never authority. */
    private function encodeCursor(array $cursor): string
    {
        return rtrim(strtr(base64_encode(json_encode($cursor, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{v: int, session: string, seq: int, argument: string, sha256: string, offset: int}|null */
    private function decodeCursor(mixed $token): ?array
    {
        if (!\is_string($token) || $token === '' || \strlen($token) > 16384 || preg_match('/[^a-zA-Z0-9_-]/', $token)) {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        $cursor = $raw === false ? null : json_decode($raw, true);
        if (!\is_array($cursor) || \count($cursor) !== 6 || ($cursor['v'] ?? null) !== 1
            || !\is_string($cursor['session'] ?? null) || !\is_int($cursor['seq'] ?? null)
            || !\is_string($cursor['argument'] ?? null) || !\is_string($cursor['sha256'] ?? null)
            || !\is_int($cursor['offset'] ?? null)) {
            return null;
        }

        return ['v' => 1, 'session' => $cursor['session'], 'seq' => $cursor['seq'],
            'argument' => $cursor['argument'], 'sha256' => $cursor['sha256'], 'offset' => $cursor['offset']];
    }
}
