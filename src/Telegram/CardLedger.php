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

/**
 * What this surface sent, and what each card's link stands for — one JSON file, written under a lock.
 *
 * A card is one thing that waits for a person: a seat's refused call or a session's open question. Its record
 * keeps where the message is (chat and message id, to rewrite it), who it was derived for, and the sha256 of its
 * link's token — never the token: a copy of this file opens nothing (the same custody as an invitation,
 * greenhouse decisions/0498).
 *
 * The token is a POINTER. It names which card a person meant and carries no authority: the door that takes it
 * asks for a passkey session and re-derives the card from the house before it sends anybody anywhere.
 *
 * @phpstan-type Card array{key: string, kind: 'frontier'|'question', session: string, ref: string, subject: string, for: string, chat: string, message: int, token: string, expires: int, opened: ?int, state: 'waiting'|'expired'|'settled'}
 */
final class CardLedger
{
    public const PATH = 'var/telegram/cards.json';

    public function __construct(private readonly string $path)
    {
    }

    /** The ledger of an app, under its root. */
    public static function forRoot(string $root): self
    {
        return new self(rtrim($root, '/') . '/' . self::PATH);
    }

    /**
     * Every card ever sent, by key.
     *
     * @return array<string, Card>
     */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($this->path), true);

        /** @var array<string, Card> */
        return \is_array($raw) ? $raw : [];
    }

    /**
     * The card a link's token points to, or null — compared by hash, in constant time.
     *
     * @return Card|null
     */
    public function byToken(#[\SensitiveParameter] string $token): ?array
    {
        $hash = hash('sha256', $token);
        $found = null;
        foreach ($this->all() as $card) {
            if (hash_equals($card['token'], $hash)) {
                $found = $card;
            }
        }

        return $found;
    }

    /**
     * Read, change and write the ledger as one step no other process can interleave with.
     *
     * @template T
     *
     * @param \Closure(array<string, Card>): array{0: array<string, Card>, 1: T} $change
     *
     * @return T
     */
    public function change(\Closure $change): mixed
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('the card ledger\'s directory could not be created: ' . self::PATH);
        }
        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('the card ledger could not be locked: ' . self::PATH);
        }
        try {
            flock($lock, \LOCK_EX);
            [$cards, $result] = $change($this->all());
            $tmp = $this->path . '.' . bin2hex(random_bytes(4));
            file_put_contents($tmp, (string) json_encode($cards, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            chmod($tmp, 0o600);
            rename($tmp, $this->path);

            return $result;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }
}
