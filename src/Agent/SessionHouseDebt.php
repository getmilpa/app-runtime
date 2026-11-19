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
use Milpa\AppRuntime\Config\SecretRedaction;
use Milpa\Command\Operation;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/** Bounded testimony and receipt lookup in one session's original journal (greenhouse E1231). */
final readonly class SessionHouseDebt
{
    public const REPORT = 'HouseDebt';
    public const READ = 'HouseDebtRead';
    public const EVENT = 'session.house_debt_reported';
    private const LIMIT = 8;
    public const KINDS = [
        'house_refusal', 'tool_error', 'contract_mismatch', 'misleading_guidance', 'missing_capability',
        'no_progress_cut', 'retry_storm', 'call_budget_stop', 'recovery_used', 'environment_unavailable',
        'plugin_load_failed', 'plugin_call_threw', 'plugin_observer_failed',
    ];

    public function __construct(
        private SessionStore $sessions,
        private string $sessionId,
        private ?EventStoreInterface $events = null,
        private ?string $root = null,
    ) {
    }

    /** The writer exists only when its original store is reachable; the reader always states availability.
     * @return list<Operation>
     */
    public function operations(): array
    {
        $reader = new SessionDebtReadOperation($this);
        if ($this->events === null) {
            return [$reader];
        }

        return [new SessionDebtWriteOperation($this), $reader];
    }

    /** Whether this closed producer reads the very store and session owned by its gate. */
    public function belongsTo(SessionStore $store, string $session): bool
    {
        return $this->sessions === $store && $this->sessionId === $session;
    }

    /** Create one invocation witness; inability to observe a starting position cannot prove a write.
     * @param array<string, mixed> $arguments
     */
    public function witness(object $owner, array $arguments): JournalAppendWitness
    {
        try {
            $first = $this->events?->nextSeq();
        } catch (\Throwable) {
            $first = null;
        }

        return new JournalAppendWitness($owner, $this, $this->sessionId, $first, ConsentBridge::digest($arguments));
    }

    /** Confirm the exact retained event and claim, never a caller-supplied assertion that something ran.
     * @param array<string, mixed> $input
     */
    public function confirms(Event $event, array $input): bool
    {
        try {
            if (!$this->validReport($input) || $event->streamId !== SessionStore::PREFIX . $this->sessionId
                || ($event->payload['claim_digest'] ?? null) !== self::digest($this->claim($input))) {
                return false;
            }

            return $this->acknowledgement($event, $this->sessions->stream($this->sessionId))['recorded'] === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function claim(array $input): array
    {
        return [
            'owner' => $input['owner'], 'kind' => $input['kind'],
            'statement' => SecretRedaction::inText($input['statement'], $this->root),
            'about' => $this->safeAbout($input['about'] ?? []), 'disputes' => $input['disputes'] ?? null,
        ];
    }

    /** Keep genuine receipt identities byte-for-byte; unknown strings are untrusted free text.
     * @param list<string> $about
     *
     * @return list<string>
     */
    private function safeAbout(array $about): array
    {
        $rows = $this->sessions->stream($this->sessionId);
        $safe = [];
        foreach ($about as $id) {
            $matches = array_values(array_filter($rows, static fn (Event $row): bool => self::identity('call', $row) === $id));
            $safe[] = \count($matches) === 1 && $matches[0]->type === 'session.tool_called'
                ? $id : SecretRedaction::inText($id, $this->root);
        }

        return array_values(array_unique($safe));
    }

    /** Domain failures are data; schema/transport failures outside this handler remain separate.
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function report(array $input, mixed $witness = null): array
    {
        $entry = $witness instanceof JournalAppendWitness ? $witness->enter($this, $input) : null;
        if ($witness !== null && $entry === null) {
            return ['recorded' => false, 'reason' => 'unrecorded'];
        }
        try {
            if (!$this->validReport($input) || $this->events === null) {
                return ['recorded' => false, 'reason' => 'unrecorded'];
            }
            if (!self::owner($input['owner']) || SecretRedaction::inText($input['owner'], $this->root) !== $input['owner']) {
                return ['recorded' => false, 'reason' => 'owner'];
            }
            if ($this->sessions->opening($this->sessionId) === null) {
                return ['recorded' => false, 'reason' => 'unrecorded'];
            }
            $rows = $this->sessions->stream($this->sessionId);
            $claim = $this->claim($input);
            $digest = self::digest($claim);
            $count = 0;
            foreach ($rows as $row) {
                if ($row->type !== self::EVENT) {
                    continue;
                }
                ++$count;
                if (($row->payload['claim_digest'] ?? null) === $digest) {
                    return $this->acknowledgement($row, $rows);
                }
            }
            if ($count >= self::LIMIT) {
                return ['recorded' => false, 'reason' => 'limit'];
            }
            if ($claim['disputes'] !== null) {
                $target = null;
                foreach ($rows as $row) {
                    $finding = $this->finding($row);
                    if ($finding !== null && $finding['id'] === $claim['disputes'] && $finding['source'] === 'house') {
                        $target = $finding;
                    }
                }
                if ($target === null || $target['owner'] === $claim['owner']) {
                    return ['recorded' => false, 'reason' => 'unknown_dispute'];
                }
            }
            $references = [];
            $ignored = [];
            $anchor = null;
            foreach ($claim['about'] as $id) {
                $matches = array_values(array_filter($rows, static fn (Event $row): bool => self::identity('call', $row) === $id));
                if (\count($matches) !== 1 || $matches[0]->type !== 'session.tool_called') {
                    $ignored[] = $id;
                    continue;
                }
                $row = $matches[0];
                $anchor ??= $this->call($row);
                $references[] = ['id' => $id, 'stream' => $row->streamId, 'seq' => $row->seq, 'sha256' => self::digest($row->toArray())];
            }
            $payload = [
                'version' => 1, 'source' => 'model', 'owner_basis' => 'testimony', ...$claim,
                'statement_redacted' => $claim['statement'] !== $input['statement'],
                'claim_digest' => $digest, 'references' => $references, 'ignored_about' => $ignored,
                'operation' => $anchor['tool'] ?? null, 'operation_status' => $anchor['tool_status'] ?? 'unavailable', 'code' => $anchor['code'] ?? null,
                'code_status' => $anchor['code_status'] ?? 'unavailable',
                'signature' => self::digest(['group-v1', $claim['kind'], $anchor['tool'] ?? null, $anchor['code'] ?? null]),
            ];
            $event = new Event(SessionStore::PREFIX . $this->sessionId, self::EVENT, $payload, $this->events->nextSeq());
            $this->events->append($event);

            $ack = $this->acknowledgement($event, $this->sessions->stream($this->sessionId));
            if ($ack['recorded'] === true && $entry !== null) {
                $witness->confirm($this, $entry, $event);
            }

            return $ack;
        } catch (\Throwable) {
            return ['recorded' => false, 'reason' => 'unrecorded'];
        }
    }

    /** SchemaValidator does not recurse into items or enforce maxItems; direct callers also meet these bounds.
     * @param array<string, mixed> $input
     */
    private function validReport(array $input): bool
    {
        if (array_diff(array_keys($input), ['owner', 'kind', 'statement', 'about', 'disputes']) !== []
            || !self::text($input['owner'] ?? null, 0, 140)
            || !\in_array($input['kind'] ?? null, self::KINDS, true)
            || !self::text($input['statement'] ?? null, 1, 2000)) {
            return false;
        }
        if (array_key_exists('disputes', $input) && !self::text($input['disputes'], 0, 64)) {
            return false;
        }
        if (array_key_exists('about', $input)) {
            if (!\is_array($input['about']) || !array_is_list($input['about']) || \count($input['about']) > 8) {
                return false;
            }
            foreach ($input['about'] as $id) {
                if (!self::text($id, 0, 128)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function text(mixed $value, int $min, int $max): bool
    {
        return \is_string($value) && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') >= $min && mb_strlen($value, 'UTF-8') <= $max;
    }

    private static function owner(string $owner): bool
    {
        return \in_array($owner, ['house', 'environment', 'model', 'user', 'unknown'], true)
            || preg_match('/\Aplugin:[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $owner) === 1 && \strlen(substr($owner, 7)) <= 24
            || preg_match('/\Amcp:[A-Za-z0-9_-]{1,32}\z/', $owner) === 1;
    }

    /** Confirm an exact unique persisted event before acknowledging it, including after a lost acknowledgement.
     * @param list<Event> $rows
     *
     * @return array<string, mixed>
     */
    private function acknowledgement(Event $event, array $rows): array
    {
        $same = array_values(array_filter($rows, static fn (Event $row): bool => $row->seq === $event->seq && $row->streamId === $event->streamId));
        if (\count($same) !== 1 || $same[0]->type !== self::EVENT || $same[0]->payload !== $event->payload) {
            return ['recorded' => false, 'reason' => 'unrecorded'];
        }

        return ['recorded' => true, 'id' => self::identity('finding', $event), 'signature' => $event->payload['signature'],
            'evidence' => \count($event->payload['references']), 'ignored_about' => $event->payload['ignored_about']];
    }

    /** Bounded output over the existing session replay, not a second journal or a constant-memory claim.
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function read(array $input): array
    {
        if (array_diff(array_keys($input), ['mode', 'id', 'after', 'limit']) !== []
            || (array_key_exists('mode', $input) && !\in_array($input['mode'], ['calls', 'findings'], true))
            || (array_key_exists('id', $input) && !self::text($input['id'], 64, 64))
            || (array_key_exists('after', $input) && !\is_int($input['after'])) || ($input['after'] ?? 0) < 0
            || (array_key_exists('limit', $input) && !\is_int($input['limit'])) || ($input['limit'] ?? 8) < 1 || ($input['limit'] ?? 8) > 8) {
            return ['ok' => false, 'error' => 'Invalid debt query'];
        }
        try {
            $items = [];
            $more = false;
            foreach ($this->sessions->stream($this->sessionId) as $event) {
                if ($event->seq <= ($input['after'] ?? 0)) {
                    continue;
                }
                $item = ($input['mode'] ?? 'findings') === 'calls' ? $this->call($event) : $this->finding($event);
                if ($item === null || isset($input['id']) && $item['id'] !== $input['id']) {
                    continue;
                }
                if (\count($items) >= ($input['limit'] ?? 8)) {
                    $more = true;
                    break;
                }
                $items[] = $item;
            }

            return ['ok' => true, 'writer_available' => $this->events !== null, 'items' => $items,
                'has_more' => $more, 'next_seq' => $items === [] ? ($input['after'] ?? 0) : $items[array_key_last($items)]['seq']];
        } catch (\Throwable) {
            return ['ok' => false, 'writer_available' => $this->events !== null, 'error' => 'Session journal unavailable'];
        }
    }

    /** The retained result is evidence, never the model's account of that result.
     * @return array<string, mixed>|null
     */
    private function call(Event $event): ?array
    {
        if ($event->type !== 'session.tool_called') {
            return null;
        }
        $p = $event->payload;
        $result = \is_string($p['result'] ?? null) ? $p['result'] : '';
        $decoded = \strlen($result) <= 65536 ? json_decode($result, true) : null;
        $code = \is_array($decoded) && (($p['ok'] ?? null) === false || ($decoded['ok'] ?? null) === false)
            && self::text($decoded['code'] ?? null, 1, 140) ? $decoded['code'] : null;
        $safe = SecretRedaction::inText($result, $this->root);
        $codeStatus = $code === null ? 'unavailable' : 'retained_result';
        if ($this->identityWasRedacted($code)) {
            $code = null;
            $codeStatus = 'redacted';
        }
        $tool = \is_string($p['tool'] ?? null) ? $p['tool'] : null;
        $toolStatus = $tool === null ? 'unavailable' : 'retained_receipt';
        if ($this->identityWasRedacted($tool)) {
            $tool = null;
            $toolStatus = 'redacted';
        }

        return ['id' => self::identity('call', $event), 'seq' => $event->seq,
            'tool' => $tool, 'tool_status' => $toolStatus, 'ok' => $p['ok'] ?? null,
            'code' => $code, 'code_status' => $codeStatus,
            'result_excerpt' => mb_substr($safe, 0, 512, 'UTF-8'), 'excerpt_truncated' => mb_strlen($safe, 'UTF-8') > 512,
            'result_redacted' => $safe !== $result, 'retained_chars' => mb_strlen($result, 'UTF-8'),
            'result_chars' => $p['resultChars'] ?? null,
            'result_complete' => \is_int($p['resultChars'] ?? null) ? $p['resultChars'] === mb_strlen($result, 'UTF-8') : null,
            'sha256' => self::digest($event->toArray())];
    }

    /** A redaction marker or a newly protected value cannot be presented as a literal code/name. */
    private function identityWasRedacted(?string $value): bool
    {
        return $value !== null && (str_contains($value, SecretRedaction::REDACTED) || SecretRedaction::inText($value, $this->root) !== $value);
    }

    /** Terminal findings are views of already persisted host facts, not another terminal write.
     * @return array<string, mixed>|null
     */
    private function finding(Event $event): ?array
    {
        $p = $event->payload;
        if ($event->type === self::EVENT && ($p['version'] ?? null) === 1) {
            $codeRedacted = $this->identityWasRedacted($p['code']);
            $operationRedacted = $this->identityWasRedacted($p['operation']);
            $statement = SecretRedaction::inText($p['statement'], $this->root);
            return ['id' => self::identity('finding', $event), 'seq' => $event->seq, 'source' => 'model',
                'owner' => $p['owner'], 'owner_basis' => 'testimony', 'kind' => $p['kind'],
                'statement' => $statement, 'statement_redacted' => $p['statement_redacted'] || $statement !== $p['statement'],
                'signature' => $p['signature'], 'disputes' => $p['disputes'], 'references' => $p['references'],
                'evidence' => \count($p['references']), 'ignored_about' => $p['ignored_about'],
                'operation' => $operationRedacted ? null : $p['operation'], 'operation_status' => $operationRedacted ? 'redacted' : ($p['operation_status'] ?? 'unavailable'),
                'code' => $codeRedacted ? null : $p['code'], 'code_status' => $codeRedacted ? 'redacted' : $p['code_status']];
        }
        if ($event->type !== 'session.run_terminated') {
            return null;
        }
        $kind = match ($p['reason'] ?? null) {
            'progress_stalled' => 'no_progress_cut',
            'steps_exhausted' => 'call_budget_stop',
            'tool_refused' => 'house_refusal',
            default => null,
        };
        if ($kind === null) {
            return null;
        }

        return ['id' => self::identity('finding', $event), 'seq' => $event->seq, 'source' => 'house',
            'owner' => 'unknown', 'owner_basis' => 'unattributed', 'kind' => $kind, 'reason' => $p['reason'],
            'statement' => 'The host ended this leg: ' . $p['reason'] . '. This records a stop, not its cause.',
            'signature' => self::digest(['terminal-v1', $kind, $p['reason']]), 'evidence' => 0,
            'source_event' => ['stream' => $event->streamId, 'seq' => $event->seq, 'sha256' => self::digest($event->toArray())],
            'code' => null, 'code_status' => 'unavailable'];
    }

    private static function identity(string $domain, Event $event): string
    {
        return self::digest(['milpa-' . $domain . '-v1', $event->streamId, $event->seq]);
    }

    /** @param array<mixed> $value */
    private static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }
}
