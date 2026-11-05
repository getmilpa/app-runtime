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

use Milpa\AppRuntime\Identity\FileEnrollmentStore;

/**
 * Keeps Telegram's copy of what waits for a person equal to the house's: a card for each new thing, a rewrite
 * when it is settled, and another when its link runs out (greenhouse decisions/0572).
 *
 * ── WHAT A CARD SAYS, AND WHAT IT NEVER SAYS ─────────────────────────────────────────────────────
 *
 * A card travels through a third party and stays in a chat history. It says THAT something waits and — unless
 * the house declared `telegram.detail: none` — the two names the house itself coined for it: the scope and the
 * tool of a refusal, or the operation of a question. Each must look like a name ({@see self::NAME}) or it is
 * left out. A card never carries a session's goal, a question's text, a call's arguments, a path, a diff, an
 * invitation or the session's id: those are read in the house, after the passkey.
 *
 * ── WHAT THE LINK IS ─────────────────────────────────────────────────────────────────────────────
 *
 * `<telegram.link>/telegram/open/<token>`: 32 random bytes that name one card for a few minutes and open once.
 * It is continuity, never authority (decisions/0031) — {@see LinkController} asks for the passkey before it
 * says anything about the card, and the decision itself is the panel's own ceremony.
 *
 * ── A SWEEP NEVER DECIDES AND NEVER FAILS THE HOUSE ──────────────────────────────────────────────
 *
 * {@see sweep()} only reads the house and talks to Telegram. A card Telegram did not take is not recorded, so
 * the next sweep sends it; a rewrite Telegram did not take is tried again.
 *
 * @phpstan-import-type Card from CardLedger
 * @phpstan-import-type Waiting from Awaiting
 */
final class Notifier
{
    /** What a name the house coined looks like: a scope, a tool, an operation. Anything else is not shown. */
    public const NAME = '/^[A-Za-z0-9][A-Za-z0-9_.:\-]{0,63}$/';

    public const DEFAULT_TTL = 900;

    /** Where the published panel takes decisions: milpa/admin's section for the agent workspace. */
    public const DEFAULT_LANDING = '/milpa/admin/s/agent';

    /** Where the link's door is mounted. */
    public const DOOR = '/telegram/open/';

    /**
     * @param string $for     the principal whose waiting things are sent — the person this chat was declared to be
     * @param string $link    the origin the person's device reaches the house at, e.g. `https://casa.example.ts.net`
     * @param string $landing the local path of the house's panel, where a decision is taken
     * @param bool   $names   whether a card names the scope, tool or operation — `telegram.detail: none` turns it off
     */
    public function __construct(
        private readonly BotApi $bot,
        private readonly CardLedger $ledger,
        private readonly Awaiting $awaiting,
        private readonly FileEnrollmentStore $enrollments,
        private readonly Catalog $catalog,
        #[\SensitiveParameter]
        private readonly string $chat,
        private readonly string $for,
        private readonly string $link,
        private readonly string $landing = self::DEFAULT_LANDING,
        private readonly int $ttl = self::DEFAULT_TTL,
        private readonly bool $names = true,
    ) {
    }

    /**
     * Send what is new, rewrite what was settled or whose link ran out — for the whole house, or one session.
     *
     * @return array{sent: int, settled: int, expired: int, failed: int}
     */
    public function sweep(?string $session = null, ?int $now = null): array
    {
        $now ??= time();

        return $this->ledger->change(function (array $cards) use ($session, $now): array {
            $report = ['sent' => 0, 'settled' => 0, 'expired' => 0, 'failed' => 0];
            $waiting = [];
            foreach ($this->awaiting->of($this->for, $session) as $item) {
                $waiting[$item['key']] = $item;
            }

            foreach ($waiting as $key => $item) {
                if (isset($cards[$key])) {
                    continue;
                }
                $card = $this->send($item, $now);
                if ($card === null) {
                    ++$report['failed'];

                    continue;
                }
                $cards[$key] = $card;
                ++$report['sent'];
            }

            foreach ($cards as $key => $card) {
                if ($card['state'] === 'settled' || ($session !== null && $card['session'] !== $session)) {
                    continue;
                }
                if (!isset($waiting[$key])) {
                    if ($this->bot->edit($card['chat'], $card['message'], $this->settledText($card))) {
                        $cards[$key]['state'] = 'settled';
                        ++$report['settled'];
                    } else {
                        ++$report['failed'];
                    }

                    continue;
                }
                if ($card['state'] === 'waiting' && $card['expires'] <= $now) {
                    $text = $this->text($waiting[$key]) . "\n\n" . $this->catalog->tr('link.expired');
                    if ($this->bot->edit($card['chat'], $card['message'], $text, $this->catalog->tr('link.panel'), $this->link . $this->landing)) {
                        $cards[$key]['state'] = 'expired';
                        ++$report['expired'];
                    } else {
                        ++$report['failed'];
                    }
                }
            }

            return [$cards, $report];
        });
    }

    /**
     * The local path a link's token opens for this principal, spending it — or null when it opens nothing.
     *
     * Null for a token nobody minted, one that ran out or was opened before, a card that was settled, and a
     * principal the card does not wait for: the house is asked again whether THIS thing still waits for THIS
     * person ({@see Awaiting::of()}), so the ledger's word is never the last one.
     */
    public function open(#[\SensitiveParameter] string $token, string $principal, ?int $now = null): ?string
    {
        $now ??= time();
        $hash = hash('sha256', $token);

        return $this->ledger->change(function (array $cards) use ($hash, $principal, $now): array {
            foreach ($cards as $key => $card) {
                if (!hash_equals($card['token'], $hash)) {
                    continue;
                }
                if ($card['state'] !== 'waiting' || $card['opened'] !== null || $card['expires'] <= $now) {
                    return [$cards, null];
                }
                if (!\in_array($key, array_column($this->awaiting->of($principal, $card['session']), 'key'), true)) {
                    return [$cards, null];
                }
                $cards[$key]['opened'] = $now;

                return [$cards, $this->landing . '?session=' . rawurlencode($card['session'])];
            }

            return [$cards, null];
        });
    }

    /**
     * @param Waiting $item
     *
     * @return Card|null the record of the card Telegram took, or null when it took none
     */
    private function send(array $item, int $now): ?array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $text = $this->text($item) . "\n\n" . $this->catalog->tr('card.footer') . ' '
            . $this->catalog->tr('card.expires', (string) max(1, intdiv($this->ttl, 60)));
        $message = $this->bot->send($this->chat, $text, $this->catalog->tr('card.button'), $this->link . self::DOOR . $token);
        if ($message === null) {
            return null;
        }

        return [
            'key' => $item['key'],
            'kind' => $item['kind'],
            'session' => $item['session'],
            'ref' => $item['ref'],
            'subject' => $item['subject'],
            'for' => $this->for,
            'chat' => $this->chat,
            'message' => $message,
            'token' => hash('sha256', $token),
            'expires' => $now + $this->ttl,
            'opened' => null,
            'state' => 'waiting',
        ];
    }

    /** @param array{kind: string, subject: string, tool?: string} $item */
    private function text(array $item): string
    {
        $subject = $this->name($item['subject']);
        if ($item['kind'] === 'frontier') {
            $tool = $this->name($item['tool'] ?? '');

            return $subject === null || $tool === null
                ? $this->catalog->tr('card.frontier.bare')
                : $this->catalog->tr('card.frontier', $subject, $tool);
        }

        return $subject === null ? $this->catalog->tr('card.question.bare') : $this->catalog->tr('card.question', $subject);
    }

    /** @param Card $card */
    private function settledText(array $card): string
    {
        $settled = $this->awaiting->settledBy($card, $this->enrollments);
        if ($settled === null) {
            return $this->catalog->tr('done.gone');
        }
        $who = self::who($settled['by']);
        if ($card['kind'] === 'frontier') {
            return $this->catalog->tr('done.granted', $who, $this->name($card['subject']) ?? '…');
        }
        $answer = \in_array($settled['answer'], ['yes', 'no'], true) ? $settled['answer'] : null;

        return $answer === null ? $this->catalog->tr('done.answered.bare', $who) : $this->catalog->tr('done.answered', $who, $answer);
    }

    /** The name as the card may show it, or null when names are off or it does not look like one. */
    private function name(string $candidate): ?string
    {
        return $this->names && preg_match(self::NAME, $candidate) === 1 ? $candidate : null;
    }

    /** A principal as a card shows it: its kind and the first characters of its id — enough to recognise, not to copy. */
    private static function who(string $principal): string
    {
        [$kind, $id] = array_pad(explode(':', $principal, 2), 2, '');
        if (preg_match('/^[a-z]{1,12}$/', $kind) !== 1 || preg_match('/^[A-Za-z0-9_\-]+$/', $id) !== 1) {
            return '…';
        }

        return $kind . ':' . substr($id, 0, 8) . (\strlen($id) > 8 ? '…' : '');
    }
}
