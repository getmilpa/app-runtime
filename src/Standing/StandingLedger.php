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
 * It catches an ACCIDENT, and an edit by someone who does not recompute digests: a line changed by
 * hand no longer hashes to what the next line says came before it, and a book that does not verify
 * lends nothing to anyone. It is NOT a seal. The digest of a line carries no key, so whoever can
 * write this file can write any chain that verifies — drop lines, change them, add them, and compute
 * every digest again. Measured: with the file alone and no key, a withdrawal and a red measurement
 * were dropped, a green one was forged, and the book verified (greenhouse decisions/0615, «Lo que la
 * segunda revisión encontró», 3).
 *
 * What such a writer cannot make is a GRANT, a REINSTATEMENT or a WITHDRAWAL: each of those lines
 * carries a person's signature over its own bytes, verified again every time it is read. Everything
 * else — what was measured, what a watcher saw, what the house absorbed or refused, a suspension,
 * and what each operation was when a grant was given — is the house's own word, unsigned, and worth
 * exactly as much as the file is out of reach.
 *
 * ── THE HEAD, AND WHAT IT IS NOT EITHER ─────────────────────────────────────────────────────────
 *
 * Beside the book the house keeps its head: how many lines it has written and the digest of the last
 * one ({@see self::HEAD}). A book that holds fewer lines than that, another line in that place, or
 * that is empty or gone, does not verify. So a book that was truncated, restored from an older copy,
 * rewritten or deleted is a FAULT, never «a house where nobody granted anything».
 *
 * The head sits on the same disk and belongs to the same user: whoever rewrites the book can rewrite
 * it too. It turns a silent rewrite into one that takes two files that agree; it is not an anchor out
 * of that writer's reach, and this house has none. One would be a person's signature over the head,
 * or a witness outside the machine.
 *
 * A house is born without these files, and one where nobody granted anything never gets them.
 */
final class StandingLedger
{
    /**
     * Where a house keeps it, beside its other identity ledgers. A trial's copy of the house may carry a copy of it;
     * nothing of a trial is ever carried back into it, and no act on it is rehearsed in one.
     */
    public const PATH = 'storage/identity/standing.jsonl';

    /** What the head of the book is kept in, beside it: the book's own name and this. */
    public const HEAD = '.head';

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

    /** @var resource|null the book, open and locked for one reading-and-writing ({@see self::exclusively()}) */
    private $held = null;

    public function __construct(private readonly string $path)
    {
    }

    /** The book of the house at this root. */
    public static function forRoot(string $root): self
    {
        return new self(rtrim($root, '/') . '/' . self::PATH);
    }

    /**
     * Whether this house has a book at all. Without one, nobody granted anything and nothing here applies — and a
     * house that kept one and whose file is gone HAS one: it does not verify ({@see self::fault()}).
     */
    public function exists(): bool
    {
        return is_file($this->path) || is_file($this->path . self::HEAD);
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
        if ($this->held !== null) {
            return $this->write($this->held, $type, $data, $at);
        }

        return $this->exclusively(fn (): array => $this->append($type, $data, $at));
    }

    /**
     * Run something with the book locked: what it reads of the book and the line it then adds are one step, and
     * nobody else's line lands between them.
     *
     * A judgment that reads the book and writes «this act passes» afterwards would otherwise have a moment in which
     * someone suspends the grant: the act would be written, and run, under a state that no longer held.
     *
     * @template T
     *
     * @param \Closure(): T $do
     *
     * @return T
     *
     * @throws \RuntimeException when the book cannot be opened
     */
    public function exclusively(\Closure $do): mixed
    {
        if ($this->held !== null) {
            return $do();
        }
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('The house has nowhere to keep its standing grants: ' . $dir);
        }
        $born = !is_file($this->path);
        $handle = @fopen($this->path, 'c+');
        if ($handle === false || !flock($handle, \LOCK_EX)) {
            throw new \RuntimeException('The book of standing grants could not be opened for writing: ' . $this->path);
        }
        if ($born) {
            // It keeps the arguments of calls: for whoever runs the house to read, and nobody else on the machine.
            @chmod($this->path, 0o600);
        }
        $this->held = $handle;
        $this->read = null;
        try {
            return $do();
        } finally {
            $this->held = null;
            $this->read = null;
            flock($handle, \LOCK_UN);
            fclose($handle);
            // A book that was only opened to be read under the lock, and holds nothing, is not left behind.
            clearstatcache(true, $this->path);
            if ($born && @filesize($this->path) === 0) {
                @unlink($this->path);
            }
        }
    }

    /**
     * @param resource             $handle the book, open and locked
     * @param array<string, mixed> $data
     *
     * @return array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}
     */
    private function write($handle, string $type, array $data, ?\DateTimeImmutable $at): array
    {
        // Read under the lock: two writers must not both link to the same last line.
        rewind($handle);
        $state = $this->verified((string) stream_get_contents($handle));
        if ($state['fault'] !== null) {
            throw new \RuntimeException('The book of standing grants does not verify, so nothing is added to it: ' . $state['fault']);
        }
        $last = $state['entries'] === [] ? null : $state['entries'][\count($state['entries']) - 1]['hash'];
        $entry = ['type' => $type, 'at' => ($at ?? new \DateTimeImmutable())->format(\DATE_ATOM), 'prev' => $last, 'data' => $data];
        $entry['hash'] = self::hashOf($entry);
        $line = Canonical::json($entry) . "\n";
        fseek($handle, 0, \SEEK_END);
        $end = (int) ftell($handle);
        // A LINE IS WRITTEN WHOLE OR NOT AT ALL. A disk that fills takes the first bytes of a line and no more, and
        // says so only in how many it took: the line would be in the book in half, the book broken for everyone, and
        // whoever asked for it told it was written — an act let pass with no record of it. What was taken is given
        // back, and this throws.
        if (fwrite($handle, $line) !== \strlen($line) || !fflush($handle)) {
            @ftruncate($handle, $end);
            throw new \RuntimeException('The book of standing grants could not be written: ' . $this->path);
        }
        $this->read = null;
        $this->keep(\count($state['entries']) + 1, $entry['hash']);

        return $entry;
    }

    /**
     * Keep the head: how many lines the book holds now, and the last one's digest — written whole, in its place.
     *
     * Not being able to keep it does not undo the line: the head then stands one line behind, which is a book that
     * holds MORE than its head says — and that verifies. The next line written puts it right.
     */
    private function keep(int $lines, string $hash): void
    {
        $head = $this->path . self::HEAD;
        $fresh = $head . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($fresh, Canonical::json(['head' => $hash, 'lines' => $lines]) . "\n") === false) {
            return;
        }
        @chmod($fresh, 0o600);
        if (!@rename($fresh, $head)) {
            @unlink($fresh);
        }
    }

    /**
     * Why the lines that are there are not the book this house last wrote, by its head — or `null`: they are, or this
     * house never kept a head.
     *
     * @param list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}> $entries
     */
    private function againstItsHead(array $entries): ?string
    {
        $head = $this->path . self::HEAD;
        if (!is_file($head)) {
            return null;
        }
        $kept = json_decode((string) @file_get_contents($head), true);
        $lines = \is_array($kept) ? ($kept['lines'] ?? null) : null;
        $hash = \is_array($kept) ? ($kept['head'] ?? null) : null;
        if (!\is_int($lines) || $lines < 1 || !\is_string($hash)) {
            return 'its head cannot be read, so it cannot be told from a book that was rewritten';
        }
        if (\count($entries) < $lines) {
            return \sprintf(
                'this house wrote %d line(s) in it and %d %s there — it was emptied, truncated, deleted or put back from an older copy',
                $lines,
                \count($entries),
                \count($entries) === 1 ? 'is' : 'are',
            );
        }

        return hash_equals($hash, $entries[$lines - 1]['hash']) ? null : \sprintf('line %d is not the line this house wrote there — it was rewritten', $lines);
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
            return $this->read = ['entries' => [], 'fault' => $this->againstItsHead([])];
        }
        $raw = @file_get_contents($this->path);

        return $this->read = $raw === false
            ? ['entries' => [], 'fault' => 'it cannot be read']
            : $this->verified($raw);
    }

    /**
     * The lines of the book and why it does not verify: by its chain, and then by its head.
     *
     * @return array{entries: list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}>, fault: ?string}
     */
    private function verified(string $raw): array
    {
        $state = $this->parse($raw);

        return $state['fault'] !== null ? $state : ['entries' => $state['entries'], 'fault' => $this->againstItsHead($state['entries'])];
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
