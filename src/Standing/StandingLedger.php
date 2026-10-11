<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Standing;

use Milpa\Command\Standing\Canonical;

/**
 * The book of standing grants: what a person granted in this house, and everything the house did or
 * refused under it (greenhouse decisions/0615 §5).
 *
 * Append-only, one fact per line, each line carrying the digest of the one before it. Nothing in it is
 * ever edited: the state of a grant — in force, suspended, superseded, revoked — is DERIVED from what
 * was appended after it ({@see StandingBook}), the way a signed document is never rewritten to say it
 * was withdrawn.
 *
 * ── WHAT THE CHAIN IS, AND WHAT IT IS NOT ───────────────────────────────────────────────────────
 *
 * It makes an edit in the middle visible: a line changed by hand no longer hashes to what the next
 * line says came before it, and a book that does not verify lends nothing to anyone. It does NOT stop
 * whoever holds the disk from truncating the book to a valid prefix, or from appending a line with a
 * correct link — that is the trust model of every identity ledger of this house. What stops a forged
 * GRANT or a forged REINSTATEMENT is not the chain: it is the signature each of those lines carries,
 * verified again every time the line is read.
 *
 * A house is born without this file, and one where nobody granted anything never gets it.
 */
final class StandingLedger
{
    /** Where a house keeps it, beside its other identity ledgers. A trial never carries it. */
    public const PATH = 'storage/identity/standing.jsonl';

    public const GRANTED = 'granted';
    public const MEASURED = 'measured';
    public const WATCHED = 'watched';
    public const ABSORBED = 'absorbed';
    public const REFUSED = 'refused';
    public const SUSPENDED = 'suspended';
    public const REINSTATED = 'reinstated';
    public const REVOKED = 'revoked';

    /** @var array{entries: list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}>, fault: ?string}|null */
    private ?array $read = null;

    public function __construct(private readonly string $path)
    {
    }

    /** The book of the house at this root. */
    public static function forRoot(string $root): self
    {
        return new self(rtrim($root, '/') . '/' . self::PATH);
    }

    /** Whether this house has a book at all. Without one, nobody granted anything and nothing here applies. */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Every line that verifies, in order — up to the first one that does not.
     *
     * @return list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}>
     */
    public function entries(): array
    {
        return $this->read()['entries'];
    }

    /**
     * Why this book does not verify, or `null` when every line links to the one before it.
     *
     * A book with a fault is not read past it, and it is not trusted before it either: whoever asks must
     * treat every grant in it as not honoured until a person has looked.
     */
    public function fault(): ?string
    {
        return $this->read()['fault'];
    }

    /**
     * Append one fact, linked to the last line, and hand it back as it was written.
     *
     * @param array<string, mixed> $data
     *
     * @return array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}
     *
     * @throws \RuntimeException when the book does not verify, or cannot be written
     */
    public function append(string $type, array $data, ?\DateTimeImmutable $at = null): array
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('The house has nowhere to keep its standing grants: ' . $dir);
        }
        $handle = @fopen($this->path, 'c+');
        if ($handle === false || !flock($handle, \LOCK_EX)) {
            throw new \RuntimeException('The book of standing grants could not be opened for writing: ' . $this->path);
        }
        try {
            // Read under the lock: two writers must not both link to the same last line.
            $this->read = null;
            $state = $this->parse((string) stream_get_contents($handle));
            if ($state['fault'] !== null) {
                throw new \RuntimeException('The book of standing grants does not verify, so nothing is added to it: ' . $state['fault']);
            }
            $last = $state['entries'] === [] ? null : $state['entries'][\count($state['entries']) - 1]['hash'];
            $entry = ['type' => $type, 'at' => ($at ?? new \DateTimeImmutable())->format(\DATE_ATOM), 'prev' => $last, 'data' => $data];
            $entry['hash'] = self::hashOf($entry);
            fseek($handle, 0, \SEEK_END);
            if (fwrite($handle, Canonical::json($entry) . "\n") === false || !fflush($handle)) {
                throw new \RuntimeException('The book of standing grants could not be written: ' . $this->path);
            }
            $this->read = null;

            return $entry;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The digest that identifies one line: its type, its moment, the line before it and what it says.
     *
     * @param array<string, mixed> $entry
     */
    public static function hashOf(array $entry): string
    {
        return 'sha256:' . hash('sha256', Canonical::json([
            'type' => $entry['type'] ?? null,
            'at' => $entry['at'] ?? null,
            'prev' => $entry['prev'] ?? null,
            'data' => $entry['data'] ?? null,
        ]));
    }

    /** @return array{entries: list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}>, fault: ?string} */
    private function read(): array
    {
        if ($this->read !== null) {
            return $this->read;
        }
        if (!is_file($this->path)) {
            return $this->read = ['entries' => [], 'fault' => null];
        }
        $raw = @file_get_contents($this->path);

        return $this->read = $raw === false
            ? ['entries' => [], 'fault' => 'it cannot be read']
            : $this->parse($raw);
    }

    /** @return array{entries: list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}>, fault: ?string} */
    private function parse(string $raw): array
    {
        $entries = [];
        $prev = null;
        foreach (explode("\n", $raw) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (!\is_array($entry) || !\is_string($entry['type'] ?? null) || !\is_string($entry['at'] ?? null)
                || !\is_string($entry['hash'] ?? null) || !\is_array($entry['data'] ?? null)
                || ($entry['prev'] ?? null) !== $prev || !hash_equals(self::hashOf($entry), $entry['hash'])) {
                return ['entries' => $entries, 'fault' => \sprintf(
                    'line %d does not link to the line before it — it was edited, or a line before it was',
                    $number + 1,
                )];
            }
            /** @var array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>} $entry */
            $entries[] = $entry;
            $prev = $entry['hash'];
        }

        return ['entries' => $entries, 'fault' => null];
    }
}
