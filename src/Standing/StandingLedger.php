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
use Milpa\Command\Standing\ConditionReceipt;
use Milpa\Command\Standing\StandingGrant;
use Milpa\ToolRuntime\Identity\OperationAuthorization;

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

    /** How many times a reading that ends in an unfinished line is made again before that line is called a fault. */
    private const LOOKS_AGAIN = 20;

    /** How long a reading waits before it looks again, in microseconds: a line is written in far less. */
    private const SETTLES_IN = 500;

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
     * The absolute paths of the book and of its head where they exist as files: in the house, and in each copy a
     * trial, an exercise or a boot candidate kept of it before copies left them out. What a confinement masks.
     *
     * @return list<string>
     */
    public static function existingUnder(string $root): array
    {
        $root = rtrim($root, '/');
        $paths = [];
        foreach ([$root, ...glob($root . '/var/trials/*/copy') ?: [], ...glob($root . '/var/exercises/*/copy') ?: [], ...glob($root . '/var/boot-candidates/*') ?: []] as $tree) {
            foreach ([self::PATH, self::PATH . self::HEAD] as $file) {
                if (is_file($tree . '/' . $file) && !is_link($tree . '/' . $file)) {
                    $paths[] = $tree . '/' . $file;
                }
            }
        }

        return $paths;
    }

    /**
     * Whether this house has a book at all. Without one, nobody granted anything and nothing here applies — and a
     * house that kept one and whose file is gone HAS one: it does not verify ({@see self::fault()}).
     */
    public function exists(): bool
    {
        return is_file($this->path) || file_exists($this->path . self::HEAD) || is_link($this->path . self::HEAD);
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
            if (@filesize($this->path) === 0) {
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
        $state = $this->verified((string) stream_get_contents($handle), $this->head());
        if ($state['fault'] !== null) {
            throw new \RuntimeException('The book of standing grants does not verify, so nothing is added to it: ' . $state['fault']);
        }
        $last = $state['entries'] === [] ? null : $state['entries'][\count($state['entries']) - 1]['hash'];
        $entry = ['type' => $type, 'at' => ($at ?? new \DateTimeImmutable())->format(\DATE_ATOM), 'prev' => $last, 'data' => $data];
        // A LINE THE HOUSE WRITES IS A LINE IT CAN READ BACK (greenhouse decisions/0615, «Lo que la tercera revisión
        // encontró», M3). A line carries the arguments of a call, and a caller chooses those: a number JSON writes as
        // `-0` and reads as `0`, a value nested deeper than a line is read, text that is not text. Written, such a
        // line no longer hashes to what it says — the book is broken for everyone, takes no line from anyone, and
        // one call of one seat did it. So the line is read back before it is written, and one that does not say the
        // same is not written: for whoever asked, that is a book that could not be written.
        $line = null;
        $back = null;
        try {
            $entry['hash'] = self::hashOf($entry);
            $line = Canonical::json($entry) . "\n";
            $back = json_decode($line, true);
        } catch (\JsonException) {
            // A value that cannot be spelled at all is not written either.
        }
        if ($line === null || !\is_array($back) || !hash_equals($entry['hash'], self::hashOf($back))) {
            throw new \RuntimeException('The book of standing grants could not be written: the line would not read back as it was written — a value in it is one a line cannot hold — so it was not written: ' . $this->path);
        }
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
     * The head as the house kept it — or the sentence that says why it cannot be used — or `null`: this house never
     * kept one.
     *
     * A HEAD IS A FILE THE HOUSE WROTE, OR IT IS A FAULT (third review, L5). Something else under its name — a
     * directory, a link — is something the house can never put a head over again: read as «never kept one», that
     * would switch the head off for good, and in silence.
     *
     * @return array{lines: int, head: string}|string|null
     */
    private function head(): array|string|null
    {
        $head = $this->path . self::HEAD;
        if (is_link($head) || (file_exists($head) && !is_file($head))) {
            return 'its head is not a file this house wrote, so it cannot be told from a book that was rewritten';
        }
        if (!is_file($head)) {
            return null;
        }
        $kept = json_decode((string) @file_get_contents($head), true);
        $lines = \is_array($kept) ? ($kept['lines'] ?? null) : null;
        $hash = \is_array($kept) ? ($kept['head'] ?? null) : null;

        return \is_int($lines) && $lines >= 1 && \is_string($hash)
            ? ['lines' => $lines, 'head' => $hash]
            : 'its head cannot be read, so it cannot be told from a book that was rewritten';
    }

    /**
     * Why the lines that are there are not the book this house last wrote, by its head — or `null`: they are, or this
     * house never kept a head.
     *
     * @param array{lines: int, head: string}|string|null                                                    $kept    the head, as {@see self::head()} read it
     * @param list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}> $entries
     */
    private function againstItsHead(array|string|null $kept, array $entries): ?string
    {
        if (!\is_array($kept)) {
            return $kept;
        }
        ['lines' => $lines, 'head' => $hash] = $kept;
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
        // THE HEAD IS READ BEFORE THE BOOK (third review, L1). A judgment reads with no lock, and a line may land
        // between its two readings. Read after the book, the head would then say one line more than what was read:
        // a book that is whole, called «emptied, truncated, deleted or put back from an older copy» — measured, one
        // reading in four while three processes wrote. Read first, it can only be BEHIND what is read next, and a
        // book that holds more than its head says verifies.
        $kept = $this->head();
        if (!is_file($this->path)) {
            return $this->read = ['entries' => [], 'fault' => $this->againstItsHead($kept, [])];
        }
        $raw = @file_get_contents($this->path);
        // A LINE STILL BEING WRITTEN IS NOT A LINE YET (the sibling of L1, found measuring it by processes). A line
        // that crosses a page of the file is, for an instant, there by halves, and a reading with no lock can land in
        // that instant: about twenty readings in three hundred thousand ended in an unfinished line while three
        // processes wrote, each exactly at a page boundary, and a book that was whole read as «edited». Every line
        // ends in a line break, so a reading that does not is looked at again — a bounded number of times, never
        // waited for: a line that STAYS unfinished is a fault, as it was.
        for ($again = 0; \is_string($raw) && $raw !== '' && !str_ends_with($raw, "\n") && $again < self::LOOKS_AGAIN; ++$again) {
            usleep(self::SETTLES_IN);
            $raw = @file_get_contents($this->path);
        }

        return $this->read = $raw === false
            ? ['entries' => [], 'fault' => 'it cannot be read']
            : $this->verified($raw, $kept);
    }

    /**
     * The lines of the book and why it does not verify: by its chain, and then by its head.
     *
     * @param array{lines: int, head: string}|string|null $kept the head, read BEFORE the book was
     *
     * @return array{entries: list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}>, fault: ?string}
     */
    private function verified(string $raw, array|string|null $kept): array
    {
        $state = $this->parse($raw);

        return $state['fault'] !== null ? $state : ['entries' => $state['entries'], 'fault' => $this->againstItsHead($kept, $state['entries'])];
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
            // A LINE THE HOUSE CANNOT READ AS WHAT ITS KIND SAYS IS A FAULT OF THE BOOK — NEVER «NOTHING» (third review,
            // M4, and the fourth review of the contract, L8; decided by Rod 2026-10-12). Every line is read again with
            // the contract and the house of today, and either may come to refuse what an earlier one took. Read as
            // nothing, a grant would vanish with what it did — the grant it had replaced would be in force again, a
            // stop written on it would stop nothing; a red receipt would leave the green before it speaking; a sign
            // that said «fired» would stop nothing. So the book does not verify, and a person looks.
            $unread = self::unread($entry['type'], $entry['at'], $entry['data']);
            if ($unread !== null) {
                return ['entries' => $entries, 'fault' => \sprintf($unread, $number + 1)];
            }
        }

        return ['entries' => $entries, 'fault' => null];
    }

    /**
     * Why a line is not one this house can read as what its kind says — as a sentence with a place for the line's
     * number — or `null`: it is.
     *
     * What each kind has to say is what the house READS of it, and no more: a withdrawal nobody signed is read (it
     * stops the grant it names), a reinstatement nobody signed is read (it lifts nothing).
     *
     * @param array<string, mixed> $data
     */
    private static function unread(string $type, string $at, array $data): ?string
    {
        if (date_create_immutable($at) === false) {
            return 'line %d does not say when it was written — what it said cannot be told, so it is not read as nothing';
        }
        if ($type === self::GRANTED) {
            $why = self::notAGrant($data);

            return $why === null ? null : 'line %d says a grant was given, and it cannot be read as one today (' . str_replace('%', '%%', $why) . ') — what it replaced or stopped cannot be told, so it is not read as nothing';
        }
        $says = static function (string ...$keys) use ($data): ?string {
            foreach ($keys as $key) {
                if (!\is_string($data[$key] ?? null) || $data[$key] === '') {
                    return "it does not say «{$key}»";
                }
            }

            return null;
        };
        $why = match ($type) {
            self::MEASURED => ConditionReceipt::fromArray($data) === null ? 'it is not a receipt: the operation that measured, how it was called, the content, what it answered and what it was' : null,
            self::WATCHED => $says('watcher') ?? (\is_array($data['arguments'] ?? null) && \is_bool($data['fired'] ?? null) ? null : 'it does not say how the watcher ran, or — as true or false — whether the falsifier «fired»'),
            self::ABSORBED, self::REFUSED => $says('grant', 'operation', 'seat'),
            self::SUSPENDED, self::REVOKED => $says('grant'),
            self::REINSTATED => $says('grant', 'suspension'),
            default => 'this house does not know what kind of line it is',
        };

        return $why === null ? null : 'line %d is a «' . str_replace('%', '%%', $type) . '» line this house cannot read today (' . $why . ') — what it said cannot be told, so it is not read as nothing';
    }

    /**
     * Why the document a `granted` line carries is not a grant the contract of today reads — or `null`: it is one.
     *
     * @param array<string, mixed> $data
     */
    private static function notAGrant(array $data): ?string
    {
        $authorization = \is_string($data['payload'] ?? null) ? OperationAuthorization::fromCanonical($data['payload']) : null;
        $text = $authorization?->arguments['grant'] ?? null;
        if (!\is_string($text)) {
            return 'it carries no document';
        }
        try {
            StandingGrant::fromJson($text);
        } catch (\InvalidArgumentException $refused) {
            return $refused->getMessage();
        }

        return null;
    }
}
