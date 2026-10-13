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

use Milpa\AppRuntime\Identity\IdentityKey;
use Milpa\Command\Consent\OperationId;
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
 * it too — OR DELETE IT (the fourth review). A book that holds lines and no head is read as one whose
 * house never kept a head, so cutting the book back to before a withdrawal and removing the head takes
 * no digest at all: the book verifies, and the grant is in force. What the head catches is a book that
 * changed while the head stayed — a truncation, a copy put back, an editor. It is not an anchor out of
 * the reach of whoever writes that folder, and this house has none. One would be a person's signature
 * over the head, or a witness outside the machine.
 *
 * (Reading «lines and no head» as a fault would not be one either: the same writer puts a head there
 * with the count and the last digest the book itself holds, and a hand repair of the book — the only
 * repair there is — would then need that head written by hand too. So it is said, and not built.)
 *
 * A house is born without these files, and one where nobody granted anything never gets them.
 *
 * ── HOW IT IS READ ──────────────────────────────────────────────────────────────────────────────
 *
 * Line by line, and a reading keeps what somebody asks of the book, not the book ({@see self::kept()}): read
 * whole and kept whole, a process stopped somewhere past twenty megabytes of it.
 *
 * Every line is VERIFIED once per process, not once per question — a command asked eight to twelve. What a process
 * verified it keeps beside a fingerprint of the bytes it verified ({@see self::BYTES} — fast, and not a seal, as the
 * chain is not); each later reading reads those bytes again and compares. The same bytes are the same book, what was
 * added after them is verified as it links, and bytes that differ are read from the first line: nothing is believed
 * of the file but its bytes — not its date, not its size.
 *
 * @phpstan-type Line array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}
 * @phpstan-type Mind array{kept: array<int, Line>, recent: array<int, Line>, lines: int, last: ?string, fault: ?string, calm: array<string, int>, refused: array<string, list<int>>, speaks: array<string, list<string>>, seats: array<string, list<string>>, spent: array<string, int>, halted: array<string, true>}
 * @phpstan-type Reading array{bytes: \HashContext, upTo: int, number: int, head: array{lines: int, head: string}|string|null, mind: Mind}
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

    /** How many of the book's last lines a reading keeps whole, for whoever asks what it said lately. */
    public const RECENT = 40;

    /** The fingerprint a reading keeps of the bytes it verified: to tell, the next time, that they are the same. */
    private const BYTES = 'xxh128';

    /** How many books one process keeps what it read of: a house has one. */
    private const BOOKS = 4;

    /** @var array<string, Reading> what this process read of each book, by the book's path */
    private static array $readings = [];

    /** How many times this process read a book from its first line. */
    private static int $passes = 0;

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
     * What a reading keeps of the book, in the order it was written: every line somebody asks about again.
     *
     * EVERY LINE BUT THREE KINDS, EACH ONE NO READER REACHES. An act a seat was lent. A sign of calm, once the same
     * key gave a later one for the same watcher, pin and arguments — a grant's last sign is the last that matches it.
     * A refusal, once a grant was given, a falsifier fired, or its own grant was stopped, lifted or withdrawn — a
     * seat's refusals are counted back to the first of those. Everything a person signed, every stop, every receipt
     * and every «fired» is kept.
     *
     * @return list<Line>
     */
    public function kept(): array
    {
        return array_values($this->read()['kept']);
    }

    /**
     * The last lines of the book, whole and in order — at most {@see self::RECENT}: what it said lately.
     *
     * @return list<Line>
     */
    public function recent(): array
    {
        $mind = $this->read();
        $recent = [];
        for ($line = max(0, $mind['lines'] - self::RECENT); $line < $mind['lines']; ++$line) {
            $recent[] = $mind['recent'][$line % self::RECENT];
        }

        return $recent;
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
     * HOW MANY LINES THE SEATS OF A GRANT WROTE UNDER IT SINCE A PERSON LAST GAVE IT OR LIFTED IT, and whether a stop
     * of it was written since (decided by Rod, 2026-10-13: D18 — «a SEAT has a budget of lines per grant»).
     *
     * Counted over the LINES of the book, the same by whoever reads it — never over where a grant stands today, which
     * a process reads with its own keyring. Under a grant a seat of it writes: the acts it was lent and the refusals
     * it was given, which name the grant; and every receipt and every sign of a watcher whose operation is one the
     * grant's line says it is measured or watched by. The count starts again at the line that gives it and at each
     * line that lifts it.
     *
     * WHAT SOMEBODY ELSE WRITES IS NOT COUNTED (the seventh review, 7-M1): it counted whoever measured, and a token
     * the decided stop refuses stopped a seat's grant by measuring. The seats are the ones the grant's own signed
     * document names ({@see self::grantIn()}), and a line says who wrote it.
     *
     * @return array{lines: int, stopped: bool}
     */
    public function spentUnder(string $grant): array
    {
        $mind = $this->read();

        return ['lines' => $mind['spent'][$grant] ?? 0, 'stopped' => isset($mind['halted'][$grant])];
    }

    /**
     * The grants a receipt or a sign of this operation is written under when THIS caller says it: the ones whose line
     * says the operation measures or watches them, and whose document names that caller as a seat
     * ({@see self::spentUnder()}). None, for whoever is a seat of none of them.
     *
     * @param mixed $who the caller as a line names it
     *
     * @return list<string>
     */
    public function spokenForBy(string $operation, mixed $who): array
    {
        return self::under($this->read(), $operation, $who);
    }

    /**
     * {@see self::spokenForBy()}, asked of a reading.
     *
     * @param Mind $mind
     *
     * @return list<string>
     */
    private static function under(array $mind, string $operation, mixed $who): array
    {
        $named = (new OperationId($operation))->canonical;
        $key = \is_string($who) && str_starts_with($who, 'key:') ? IdentityKey::normalize(substr($who, 4)) : null;

        return array_keys(array_filter($mind['speaks'], static fn (array $by, string $grant): bool => \in_array($named, $by, true) && \in_array($key, $mind['seats'][$grant] ?? [], true), \ARRAY_FILTER_USE_BOTH));
    }

    /**
     * THE BOOK IS PAST HALF OF WHAT THIS HOUSE READS: said to a person who lists it and to a seat it lends to, in
     * time for a person to keep it somewhere else (decided by Rod, 2026-10-13: D18) — or `null`: it is not.
     */
    public function nearlyFull(): ?string
    {
        clearstatcache(true, $this->path);
        $bytes = is_file($this->path) ? (int) filesize($this->path) : 0;

        return $bytes < self::WARNS_FROM ? null : \sprintf(
            'The book of standing grants holds %.1f of the %d MiB this house reads. Past that it is a fault: no seat acts, nothing is lent, and no stop is written either. A person keeps the book AND ITS HEAD somewhere else — `%s` and `%s`, the two together — and gives again the grants that should stand; the README of milpa/app-runtime says how, and what is left behind.',
            $bytes / 1048576,
            self::LONGEST / 1048576,
            self::PATH,
            self::PATH . self::HEAD,
        );
    }

    /** How many times this process verified a book from its first line: what a command costs is one. */
    public static function passes(): int
    {
        return self::$passes;
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
        try {
            return $do();
        } finally {
            $this->held = null;
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
        // Read under the lock: two writers must not both link to the same last line. A book that does not verify —
        // one longer than the house reads is one — takes no line.
        $state = $this->read();
        if ($state['fault'] !== null) {
            throw new \RuntimeException('The book of standing grants does not verify, so nothing is added to it: ' . $state['fault']);
        }
        $last = $state['last'];
        $lines = $state['lines'];
        unset($state);
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
        // What this process read of the book is not told of this line: the next reading finds the bytes it verified
        // and one line after them, and reads that line from the file, as it reads anybody's.
        $this->keep($lines + 1, $entry['hash']);

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
     * @param array{lines: int, head: string}|string|null $kept   the head, as {@see self::head()} read it
     * @param int                                         $there  how many lines verify
     * @param ?string                                     $atHead the digest of the line the head counts as the last, when the book holds that many
     */
    private function againstItsHead(array|string|null $kept, int $there, ?string $atHead): ?string
    {
        if (!\is_array($kept)) {
            return $kept;
        }
        ['lines' => $lines, 'head' => $hash] = $kept;
        if ($there < $lines) {
            return \sprintf(
                'this house wrote %d line(s) in it and %d %s there — it was emptied, truncated, deleted or put back from an older copy',
                $lines,
                $there,
                $there === 1 ? 'is' : 'are',
            );
        }

        return hash_equals($hash, (string) $atHead) ? null : \sprintf('line %d is not the line this house wrote there — it was rewritten', $lines);
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

    /**
     * How many bytes of book this house reads: past it the book is a fault, and is not read (decided by Rod, 2026-10-13: D18).
     *
     * WHAT STILL REACHES IT (the seventh review; decided by Rod, 2026-10-13). No budget counts what narrows — a no and
     * a «fired» are written whoever says them and whatever a grant spent — nor what somebody who is a seat of no grant
     * writes. One caller with the word of an instrument writes its way here in about fifteen thousand runs. The house
     * says so from half ({@see self::WARNS_FROM}); past this it lends nothing until a person keeps the book somewhere
     * else.
     */
    public const LONGEST = 8 * 1024 * 1024;

    /** From how many bytes the house says its book is filling ({@see self::nearlyFull()}): half of what it reads. */
    public const WARNS_FROM = 4 * 1024 * 1024;

    /**
     * How many lines are written under one grant between two acts of a person — the one that gives it, and each one
     * that lifts it (a PROPOSAL, not decided: the number; that there is one was decided by Rod, 2026-10-13: D18).
     */
    public const BUDGET = 3000;

    private static function tooLong(int $bytes): string
    {
        return \sprintf('it is %d bytes, longer than this house reads (%d): a longer one makes every command of everyone wait. A person keeps the book AND ITS HEAD — the file beside it, named as the book and «' . self::HEAD . '» — somewhere else, and the house starts another: what it lent, it lends again only by a person\'s signature', $bytes, self::LONGEST);
    }

    /** @return Mind */
    private function read(): array
    {
        // THE HEAD IS READ BEFORE THE BOOK (third review, L1). A judgment reads with no lock, and a line may land
        // between its two readings. Read after the book, the head would then say one line more than what was read:
        // a book that is whole, called «emptied, truncated, deleted or put back from an older copy» — measured, one
        // reading in four while three processes wrote. Read first, it can only be BEHIND what is read next, and a
        // book that holds more than its head says verifies.
        $head = $this->head();
        if (!is_file($this->path)) {
            $this->remember(null);

            return self::nothing($this->againstItsHead($head, 0, null));
        }
        $handle = @fopen($this->path, 'r');
        $file = $handle === false ? false : fstat($handle);
        if ($handle === false || $file === false) {
            return self::nothing('it cannot be read');
        }
        try {
            // A BOOK LONGER THAN THE HOUSE READS IS NOT READ: IT IS A FAULT (a proposal, not decided — the fifth review,
            // 5-M2). Past this length the house says so and reads nothing: no seat acts, nothing is lent, and a
            // person is still answered.
            if ($file['size'] > self::LONGEST) {
                return self::nothing(self::tooLong($file['size']));
            }
            // WHAT THIS PROCESS VERIFIED IS VERIFIED STILL WHEN THE BYTES ARE THE SAME ONES. They are read again, every
            // time, and their fingerprint compared: nothing about the file is believed but its bytes.
            $known = $this->known();
            $same = false;
            if ($known !== null) {
                $bytes = hash_init(self::BYTES);
                $same = hash_update_stream($bytes, $handle, $known['upTo']) === $known['upTo']
                    && hash_equals(hash_final(hash_copy($known['bytes'])), hash_final($bytes));
            }
            if ($same && $known['upTo'] === $file['size'] && $known['head'] === $head) {
                return $known['mind']; // nothing was added, and its head is the one it was read against
            }
            // ONLY WHAT WAS ADDED IS VERIFIED, when what came before it verified: the same bytes, more after them, and
            // a head that does not count fewer lines than were read — one that does is a book put back, read whole.
            $onward = $same && $known['mind']['fault'] === null && $file['size'] > $known['upTo']
                && ($known['head'] === $head || (\is_array($head) && $head['lines'] >= $known['mind']['lines']));
            if ($onward) {
                $mind = $known['mind'];
                $number = $known['number'];
                $bytes = $known['bytes'];
            } else {
                ++self::$passes;
                $mind = self::nothing(null);
                $number = 0;
                $bytes = hash_init(self::BYTES);
                if (ftell($handle) !== 0) {
                    rewind($handle);
                }
            }
            $counted = \is_array($head) ? $head['lines'] : null;
            $atHead = $mind['lines'] === $counted ? $mind['last'] : null;
            $lines = $this->verified($handle, $mind['last'], $number, $bytes);
            foreach ($lines as $entry) {
                self::take($mind, $entry);
                if ($mind['lines'] === $counted) {
                    $atHead = $entry['hash'];
                }
            }
            [$fault, $number] = $lines->getReturn();
            // Against its head, unless these are lines added to a reading that was already held against this very head.
            $mind['fault'] = $fault ?? ($onward && $known['head'] === $head ? null : $this->againstItsHead($head, $mind['lines'], $atHead));
            // A reading that found a fault stands for the whole file as it is: its fingerprint takes the bytes after
            // the fault too, so that any change to them is a book to read again.
            hash_update_stream($bytes, $handle);
            $this->remember(['bytes' => $bytes, 'upTo' => (int) ftell($handle), 'number' => $number, 'head' => $head, 'mind' => $mind]);

            return $mind;
        } finally {
            fclose($handle);
        }
    }

    /** @return Reading|null what this process read of the book */
    private function known(): ?array
    {
        return self::$readings[$this->path] ?? null;
    }

    /** @param Reading|null $reading */
    private function remember(?array $reading): void
    {
        unset(self::$readings[$this->path]);
        if ($reading !== null) {
            self::$readings = \array_slice(self::$readings, 1 - self::BOOKS, null, true) + [$this->path => $reading];
        }
    }

    /** @return Mind a reading that holds no line */
    private static function nothing(?string $fault): array
    {
        return ['kept' => [], 'recent' => [], 'lines' => 0, 'last' => null, 'fault' => $fault, 'calm' => [], 'refused' => [], 'speaks' => [], 'seats' => [], 'spent' => [], 'halted' => []];
    }

    /**
     * Take one more line into a reading: count it, and keep it unless nobody asks about its kind again — and let go
     * of the lines it makes nobody ask about ({@see self::kept()}).
     *
     * @param Mind $mind
     * @param Line $entry
     */
    private static function take(array &$mind, array $entry): void
    {
        $line = $mind['lines']++;
        $mind['last'] = $entry['hash'];
        $mind['recent'][$line % self::RECENT] = $entry;
        $data = $entry['data'];
        $fired = $entry['type'] === self::WATCHED ? ($data['fired'] ?? null) : null;
        // WHAT THE SEATS OF EACH GRANT HAVE SPENT ({@see self::spentUnder()}): told from the line alone.
        $grant = \is_string($data['grant'] ?? null) ? $data['grant'] : null;
        $speaker = $data[$entry['type'] === self::MEASURED ? 'by' : 'watcher'] ?? null;
        if ($grant !== null && \in_array($entry['type'], [self::GRANTED, self::REINSTATED], true)) {
            $mind['spent'][$grant] = 0;
            unset($mind['halted'][$grant]);
            if ($entry['type'] === self::GRANTED) {
                $document = self::grantIn($data);
                $mind['speaks'][$grant] = array_map(strval(...), array_keys(\is_array($data['instruments'] ?? null) ? $data['instruments'] : []));
                $mind['seats'][$grant] = $document instanceof StandingGrant ? array_map(IdentityKey::normalize(...), $document->to) : [];
            }
        } elseif ($grant !== null && $entry['type'] === self::SUSPENDED) {
            $mind['halted'][$grant] = true;
        } elseif ($grant !== null && \in_array($entry['type'], [self::ABSORBED, self::REFUSED], true)) {
            if (\is_string($data['seat'] ?? null) && \in_array(IdentityKey::normalize($data['seat']), $mind['seats'][$grant] ?? [], true)) {
                $mind['spent'][$grant] = ($mind['spent'][$grant] ?? 0) + 1;
            }
        } elseif (\in_array($entry['type'], [self::MEASURED, self::WATCHED], true) && \is_string($speaker)) {
            foreach (self::under($mind, $speaker, $data['who'] ?? null) as $under) {
                $mind['spent'][$under] = ($mind['spent'][$under] ?? 0) + 1;
            }
        }
        if ($entry['type'] === self::ABSORBED) {
            return;
        }
        if ($fired === false) {
            $said = serialize([$data['watcher'] ?? null, $data['who'] ?? null, $data['pin'] ?? null, $data['arguments'] ?? null]);
            unset($mind['kept'][$mind['calm'][$said] ?? -1]);
            $mind['calm'][$said] = $line;
        } elseif ($entry['type'] === self::REFUSED) {
            $mind['refused'][\is_string($data['grant'] ?? null) ? $data['grant'] : ''][] = $line;
        } elseif ($entry['type'] === self::GRANTED || $fired === true) {
            foreach ($mind['refused'] as $refusals) {
                foreach ($refusals as $refusal) {
                    unset($mind['kept'][$refusal]);
                }
            }
            $mind['refused'] = [];
        } elseif (\in_array($entry['type'], [self::SUSPENDED, self::REINSTATED, self::REVOKED], true) && \is_string($data['grant'] ?? null)) {
            foreach ($mind['refused'][$data['grant']] ?? [] as $refusal) {
                unset($mind['kept'][$refusal]);
            }
            unset($mind['refused'][$data['grant']]);
        }
        $mind['kept'][$line] = $entry;
    }

    /**
     * The lines of a book from where its handle stands, each verified against the one before it — and, when they
     * end, why they do not verify (or `null`) and how many lines of the file were passed.
     *
     * @param resource     $handle the book, open where the reading begins
     * @param ?string      $prev   the digest of the line before that place
     * @param int          $number how many lines of the file come before it
     * @param \HashContext $bytes  the fingerprint of the bytes before that place, which takes each line as it is read
     *
     * @return \Generator<int, Line, mixed, array{0: ?string, 1: int}>
     */
    private function verified($handle, ?string $prev, int $number, \HashContext $bytes): \Generator
    {
        $again = 0;
        while (true) {
            $from = (int) ftell($handle);
            $line = fgets($handle);
            if ($line === false) {
                return [null, $number];
            }
            if (!str_ends_with($line, "\n")) {
                // A LINE STILL BEING WRITTEN IS NOT A LINE YET (the sibling of L1, found measuring it by processes). A
                // line that crosses a page of the file is, for an instant, there by halves, and a reading with no lock
                // can land in that instant: about twenty readings in three hundred thousand ended in an unfinished
                // line while three processes wrote, each exactly at a page boundary, and a book that was whole read as
                // «edited». Every line ends in a line break, so a reading that does not is looked at again — a bounded
                // number of times, never waited for.
                if ($again++ < self::LOOKS_AGAIN) {
                    usleep(self::SETTLES_IN);
                    fseek($handle, $from);
                    continue;
                }
                // A BOOK THAT HOLDS SOMETHING ENDS IN A LINE BREAK, OR IT IS A FAULT (fourth review, L2; decided by
                // Rod, 2026-10-12: D16). Every line this house writes ends in one, so a book that STAYS without it was
                // cut or edited by hand — an editor that saves without the last line break is enough. Read as whole,
                // the next line was written ONTO its last one: the act that line let pass ran, and from then on the
                // book was broken for everyone. The house does not repair its own book, so it does not write the
                // break either: a person looks.
                hash_update($bytes, $line);
                $entry = self::lineOf($line, $prev);
                if ($entry !== null) {
                    yield $entry;
                }

                return ['it does not end in a line break — its last line was cut, or it was edited by hand, and a line added to it would be written onto that one', $number + 1];
            }
            ++$number;
            hash_update($bytes, $line);
            if (trim($line) === '') {
                continue;
            }
            $entry = self::lineOf($line, $prev);
            if ($entry === null) {
                return [\sprintf('line %d does not link to the line before it — it was edited, or a line before it was', $number), $number];
            }
            yield $entry;
            $prev = $entry['hash'];
            // A LINE THE HOUSE CANNOT READ AS WHAT ITS KIND SAYS IS A FAULT OF THE BOOK — NEVER «NOTHING» (third review,
            // M4, and the fourth review of the contract, L8; decided by Rod 2026-10-12). Every line is read again with
            // the contract and the house of today, and either may come to refuse what an earlier one took. Read as
            // nothing, a grant would vanish with what it did — the grant it had replaced would be in force again, a
            // stop written on it would stop nothing; a red receipt would leave the green before it speaking; a sign
            // that said «fired» would stop nothing. So the book does not verify, and a person looks.
            $unread = self::unread($entry['type'], $entry['at'], $entry['data']);
            if ($unread !== null) {
                return [\sprintf($unread, $number), $number];
            }
        }
    }

    /**
     * One line of the book as a line of it — its kind, its moment, what it says, and a digest that is its own and
     * names the line before it — or `null`: it is not one that follows `$prev`.
     *
     * @return Line|null
     */
    private static function lineOf(string $line, ?string $prev): ?array
    {
        $entry = json_decode($line, true);
        if (!\is_array($entry) || !\is_string($entry['type'] ?? null) || !\is_string($entry['at'] ?? null)
            || !\is_string($entry['hash'] ?? null) || !\is_array($entry['data'] ?? null)
            || ($entry['prev'] ?? null) !== $prev || !hash_equals(self::hashOf($entry), $entry['hash'])) {
            return null;
        }

        /** @var Line $entry */
        return $entry;
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
            $why = self::grantIn($data);

            return !\is_string($why) ? null : 'line %d says a grant was given, and it cannot be read as one today (' . str_replace('%', '%%', $why) . ') — what it replaced or stopped cannot be told, so it is not read as nothing';
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
     * The document a `granted` line carries in its signed bytes — or, as a sentence, why it is not a grant the
     * contract of today reads. The one reading of it: what verifies the book, what counts a seat's lines and what
     * judges a grant ({@see StandingBook}) all read this.
     *
     * @param array<string, mixed> $data
     */
    public static function grantIn(array $data): StandingGrant|string
    {
        $authorization = \is_string($data['payload'] ?? null) ? OperationAuthorization::fromCanonical($data['payload']) : null;
        $text = $authorization?->arguments['grant'] ?? null;
        if (!\is_string($text)) {
            return 'it carries no document';
        }
        try {
            return StandingGrant::fromJson($text);
        } catch (\InvalidArgumentException $refused) {
            return $refused->getMessage();
        }
    }
}
