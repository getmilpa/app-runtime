<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionStore;
use Milpa\ToolRuntime\Contracts\ResultBudget;

/** Reads stored tool-result bytes without re-invoking the producer or judging its claims. */
final readonly class SessionResultPage
{
    /**
     * @param LegWindow|null $leg the window of the leg reading its own session right now, or `null` outside a leg
     *                            — then nothing but the result budget bounds a page, as before
     */
    public function __construct(private SessionStore $sessions, private ?LegWindow $leg = null)
    {
    }

    /**
     * Page one immutable recorded call under the caller's actual result budget.
     *
     * A null cursor ends the stored bytes, not necessarily the original producer's output.
     * Unknown or partial storage stays explicit even when every stored byte was returned.
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
            return ['ok' => false, 'error' => 'agent:result needs a transport result budget or explicit max_chars'];
        }
        $session = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        $seq = $input['seq'] ?? null;
        if ($session === '' || !\is_int($seq) || $seq < 1) {
            return ['ok' => false, 'error' => 'session and positive integer seq are required'];
        }
        $stream = $this->sessions->stream($session);
        $selected = array_values(array_filter($stream, static fn ($event): bool => $event->seq === $seq));
        if (\count($selected) !== 1 || $selected[0]->type !== 'session.tool_called') {
            return ['ok' => false, 'error' => 'seq must identify one recorded tool call in this session'];
        }
        $call = $selected[0]->payload;
        $content = $call['result'] ?? null;
        if (!\is_string($content) || !mb_check_encoding($content, 'UTF-8')) {
            return ['ok' => false, 'error' => 'recorded result must be UTF-8 text'];
        }
        if (!\is_string($call['tool'] ?? null) || !\is_bool($call['ok'] ?? null)) {
            return ['ok' => false, 'error' => 'recorded call has invalid tool or outcome metadata'];
        }
        $storedChars = mb_strlen($content, 'UTF-8');
        $declaredChars = $call['resultChars'] ?? null;
        if ($declaredChars !== null && (!\is_int($declaredChars) || $declaredChars < $storedChars)) {
            return ['ok' => false, 'error' => 'recorded result length contradicts its stored text'];
        }
        $digest = hash('sha256', $content);
        $callDigest = hash('sha256', json_encode($selected[0]->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        $identity = ['v' => 1, 'kind' => 'result', 'session' => $session, 'seq' => $seq,
            'sha256' => $digest, 'call_sha256' => $callDigest];
        // A PAGE THE LEG CANNOT HOLD IS REFUSED, SAYING HOW MUCH IS LEFT (greenhouse decisions/0538 §3): evidence/1071's
        // third leg paged 28.9 KB of old results into a window 2k tokens from its wall, and died on the next call.
        if ($this->leg !== null) {
            $room = $this->leg->roomAfter(self::lastUsage($stream));
            if ($room !== null) {
                $pageChars = LegWindow::pageChars($room);
                if ($pageChars < LegWindow::MIN_PAGE_CHARS) {
                    $needed = (int) ceil(min($budget->maxCharacters, mb_strlen($content, 'UTF-8')) / LegWindow::CHARS_PER_TOKEN);

                    return [
                        'ok' => false,
                        'error' => "{$room} tokens left in this leg's window; this page would need about {$needed}. "
                            . 'End the leg with what you have — the next leg starts with room — or read a smaller part of the source.',
                        'room_tokens' => $room,
                        'needed_tokens' => $needed,
                    ];
                }
                if ($pageChars < $budget->maxCharacters) {
                    $budget = $budget->tightenedTo($pageChars);
                }
            }
        }
        $total = \strlen($content);
        $offset = 0;
        if (\array_key_exists('cursor', $input)) {
            $cursor = $this->decodeCursor($input['cursor']);
            if ($cursor === null) {
                return ['ok' => false, 'error' => 'invalid result cursor'];
            }
            $offset = $cursor['offset'];
            unset($cursor['offset']);
            if ($cursor !== $identity) {
                return ['ok' => false, 'error' => 'result cursor does not match this session, call and stored result'];
            }
            if ($offset < 0 || $offset > $total || !mb_check_encoding(substr($content, 0, $offset), 'UTF-8')) {
                return ['ok' => false, 'error' => 'result cursor offset must be a UTF-8 byte boundary inside the stored result'];
            }
        }
        $page = function (string $part) use ($identity, $offset, $total, $call, $storedChars, $declaredChars): array {
            $next = $offset + \strlen($part);

            return ['ok' => true, 'session' => $identity['session'], 'seq' => $identity['seq'],
                'tool' => $call['tool'], 'call_ok' => $call['ok'],
                'sha256' => $identity['sha256'], 'call_sha256' => $identity['call_sha256'],
                'stored_chars' => $storedChars, 'declared_chars' => $declaredChars,
                'storage_complete' => $declaredChars === null ? null : $declaredChars === $storedChars,
                'offset' => $offset, 'next_offset' => $next, 'total_bytes' => $total, 'content' => $part,
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
            return ['ok' => false, 'error' => 'result budget cannot fit recorded-result metadata and one character'];
        }

        return $best;
    }

    /**
     * The provider's count of the leg's last call — the usage of the newest `session.model_returned` that carried one.
     *
     * @param list<\Milpa\EventStore\Event> $stream
     *
     * @return array<string, mixed>
     */
    private static function lastUsage(array $stream): array
    {
        for ($i = \count($stream) - 1; $i >= 0; --$i) {
            $usage = $stream[$i]->type === SessionEvent::ModelReturned->value ? ($stream[$i]->payload['usage'] ?? null) : null;
            if (\is_array($usage) && $usage !== []) {
                return $usage;
            }
        }

        return [];
    }

    /** @param array<string, int|string> $cursor Binds stored identity and byte position, never authority. */
    private function encodeCursor(array $cursor): string
    {
        return rtrim(strtr(base64_encode(json_encode($cursor, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{v: int, kind: string, session: string, seq: int, sha256: string, call_sha256: string, offset: int}|null */
    private function decodeCursor(mixed $token): ?array
    {
        if (!\is_string($token) || $token === '' || \strlen($token) > 16384 || preg_match('/[^a-zA-Z0-9_-]/', $token)) {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        $cursor = $raw === false ? null : json_decode($raw, true);
        if (!\is_array($cursor) || \count($cursor) !== 7 || ($cursor['v'] ?? null) !== 1 || ($cursor['kind'] ?? null) !== 'result'
            || !\is_string($cursor['session'] ?? null) || !\is_int($cursor['seq'] ?? null)
            || !\is_string($cursor['sha256'] ?? null) || !\is_string($cursor['call_sha256'] ?? null)
            || !\is_int($cursor['offset'] ?? null)) {
            return null;
        }

        return ['v' => 1, 'kind' => 'result', 'session' => $cursor['session'], 'seq' => $cursor['seq'],
            'sha256' => $cursor['sha256'], 'call_sha256' => $cursor['call_sha256'], 'offset' => $cursor['offset']];
    }
}
