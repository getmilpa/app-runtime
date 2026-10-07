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

namespace Milpa\AppRuntime\Identity;

/**
 * A recognition ledger on disk: fingerprint → {scopes, authorized_by}, one JSON file.
 *
 * The shape mirrors {@see \Milpa\Console\FileConfirmTokenStore}: every write takes an exclusive lock,
 * reads the current map, mutates, and writes it back, so two operations enrolling at once cannot lose
 * each other. Keys are compared through {@see IdentityKey::normalize()} — the same rule the root
 * ({@see RootedSigners}) applies: a gpg FINGERPRINT is uppercased and space-stripped so a key pasted
 * either way reads back the same recognition; any other id — a passkey's base64url credential id, since
 * the convergence of decisions/0125 — is kept VERBATIM, because base64url is case-sensitive and two
 * distinct credentials used to collapse onto one entry (greenhouse decisions/0206).
 *
 * It is a ledger of FACTS, not of state (greenhouse decisions/0207): a recognition written over an
 * entry that already exists — live or revoked — pushes the state it replaces onto that entry's
 * `history` (most recent last) and becomes the live state. A revocation is therefore never erased by
 * the recognition that follows it. A ledger written before `history` existed reads identically; the
 * field appears the first time a key is re-written, and nothing migrates.
 *
 * A write that cannot happen is said, not hidden: every writer throws when the file cannot be opened,
 * the bytes did not reach the disk, or the ledger holds content the store cannot read — it refuses to
 * write over what it could not keep, and such content is not a greenfield either ({@see isEmpty()}).
 */
final class FileEnrollmentStore implements EnrollmentStore
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Persist a recognition, keyed by its (normalized) fingerprint, under an exclusive lock.
     *
     * @throws \RuntimeException when the ledger could not be written — never a quiet no-op
     */
    public function record(IdentityEnrolled $enrolled): void
    {
        $this->recordAndReport($enrolled);
    }

    /**
     * Persist a recognition and say what it laid over: who had revoked the key when the standing entry
     * was a revocation (null when it was live, or when there was none), and how many prior states the
     * entry keeps once written — 0 on a first enrollment. The same write as {@see record()}, so the
     * report is what the write itself saw under the lock (greenhouse decisions/0207).
     *
     * A RECOGNITION WRITTEN OVER A SEAT DROPS WHAT WAS ADMITTED TO IT (greenhouse decisions/0590, rule 13): whoever
     * recognizes a key again with a list of scopes saw no capability's contract. The dropped admissions stay in the
     * history with the state they belonged to, and the report counts them. `$keepAdmissions` is for the writers
     * that change one thing about a standing seat and nothing else — a grant of one more scope, a scope the house
     * grew — never for a list somebody typed.
     *
     * @return array{previously_revoked_by: ?string, history_entries: int, admissions_dropped?: int}
     *
     * @throws \RuntimeException when the ledger could not be written — the report is never returned
     *                           for a write that did not happen
     */
    public function recordAndReport(IdentityEnrolled $enrolled, bool $keepAdmissions = false): array
    {
        $key = IdentityKey::normalize($enrolled->fingerprint);
        $report = ['previously_revoked_by' => null, 'history_entries' => 0];
        $this->mutate(static function (array $map) use ($key, $enrolled, $keepAdmissions, &$report): array {
            $entry = ['scopes' => $enrolled->scopes, 'authorized_by' => $enrolled->authorizedBy];
            if ($enrolled->grownBy !== null) {
                $entry['grown_by'] = $enrolled->grownBy;
            }

            if (\array_key_exists($key, $map)) {
                $previous = $map[$key];
                if (\is_array($previous)) {
                    $admitted = ($previous['revoked_by'] ?? null) === null ? self::admissionsIn($previous) : [];
                    if ($admitted !== [] && $keepAdmissions) {
                        $entry['admissions'] = $admitted;
                    } elseif ($admitted !== []) {
                        $report['admissions_dropped'] = array_sum(array_map(\count(...), $admitted));
                    }
                    // What was withdrawn travels with what was admitted: both are what persons decided about this
                    // seat, and a typed list starts that over (the trail stays in the history).
                    $withdrawn = ($previous['revoked_by'] ?? null) === null ? self::withdrawalsIn($previous) : [];
                    if ($withdrawn !== [] && $keepAdmissions) {
                        $entry['withdrawals'] = $withdrawn;
                    }
                    // The state being replaced goes onto the history, flat: the states it carried move
                    // along with it rather than nesting. A `history` that is not a list is not lifted —
                    // it rides inside the pushed state, kept as it was found.
                    $history = [];
                    if (\is_array($previous['history'] ?? null)) {
                        $history = array_values($previous['history']);
                        unset($previous['history']);
                    }
                    $history[] = $previous;

                    // Any non-null revoked_by denies admission in scopesFor(); the report follows the
                    // same rule, so the revocation is named however it was written.
                    $revokedBy = $previous['revoked_by'] ?? null;
                    if ($revokedBy !== null) {
                        $report['previously_revoked_by'] = \is_string($revokedBy) ? $revokedBy : (string) json_encode($revokedBy);
                    }
                } else {
                    // An entry the store cannot read as a state is still a fact it found there: it is
                    // kept raw and counted, not overwritten as if the key were new.
                    $history = [['raw' => $previous]];
                }
                $entry['history'] = $history;
                $report['history_entries'] = \count($history);
            }

            $map[$key] = $entry;

            return $map;
        });

        return $report;
    }

    /**
     * Write that a person admitted one scope of a built capability for a live seat: the verbs that scope opens,
     * each pinned by the digest of its contract (greenhouse decisions/0590). False when the key has no live
     * recognition — nothing is admitted to a key the house does not admit.
     *
     * The seat's scopes do not move: an admission never writes a word to them, so what it opens holds inside that
     * capability and nowhere else. The state it replaces goes onto the history, as every other write here.
     *
     * @param array<string, string> $verbs each admitted verb's name → the digest of its contract
     *
     * @throws \RuntimeException when the ledger could not be written
     */
    public function admit(string $fingerprint, string $capability, string $scope, array $verbs, string $admittedBy, ?string $at = null): bool
    {
        $key = IdentityKey::normalize($fingerprint);
        $at ??= gmdate('Y-m-d\TH:i:s\Z');
        ksort($verbs);
        $admitted = false;
        $this->mutate(static function (array $map) use ($key, $capability, $scope, $verbs, $admittedBy, $at, &$admitted): array {
            $entry = $map[$key] ?? null;
            if (!\is_array($entry) || !\is_array($entry['scopes'] ?? null) || ($entry['revoked_by'] ?? null) !== null) {
                return $map;
            }
            $previous = $entry;
            $history = \is_array($previous['history'] ?? null) ? array_values($previous['history']) : [];
            unset($previous['history']);
            $history[] = $previous;

            $admissions = self::admissionsIn($entry);
            $admissions[$capability][$scope] = ['verbs' => $verbs, 'admitted_by' => $admittedBy, 'at' => $at];
            $entry['admissions'] = $admissions;
            $entry['history'] = $history;
            $map[$key] = $entry;
            $admitted = true;

            return $map;
        });

        return $admitted;
    }

    /**
     * Take ONE admission out of a live seat — one scope of one capability — and keep who did it, when, and what it
     * was (greenhouse decisions/0590, rule 12). Null when that seat holds no such admission: nothing is written.
     *
     * It only removes authority, and it removes nothing else: the seat's scopes and its other admissions stay. The
     * state it replaces goes onto the history, and the entry gains a line in `withdrawals` — the trail a person
     * reads without digging in the history.
     *
     * @return array{capability: string, scope: string, verbs: list<string>, withdrawn_by: string, at: string, admitted_by: string}|null
     *
     * @throws \RuntimeException when the ledger could not be written
     */
    public function withdraw(string $fingerprint, string $capability, string $scope, string $withdrawnBy, ?string $at = null): ?array
    {
        $key = IdentityKey::normalize($fingerprint);
        $at ??= gmdate('Y-m-d\TH:i:s\Z');
        $taken = null;
        $this->mutate(static function (array $map) use ($key, $capability, $scope, $withdrawnBy, $at, &$taken): array {
            $entry = $map[$key] ?? null;
            if (!\is_array($entry) || !\is_array($entry['scopes'] ?? null) || ($entry['revoked_by'] ?? null) !== null) {
                return $map;
            }
            $admissions = self::admissionsIn($entry);
            $admission = $admissions[$capability][$scope] ?? null;
            if ($admission === null) {
                return $map;
            }
            $previous = $entry;
            $history = \is_array($previous['history'] ?? null) ? array_values($previous['history']) : [];
            unset($previous['history']);
            $history[] = $previous;

            unset($admissions[$capability][$scope]);
            if ($admissions[$capability] === []) {
                unset($admissions[$capability]);
            }
            $taken = [
                'capability' => $capability,
                'scope' => $scope,
                'verbs' => array_keys($admission['verbs']),
                'withdrawn_by' => $withdrawnBy,
                'at' => $at,
                'admitted_by' => $admission['admitted_by'],
            ];
            if ($admissions === []) {
                unset($entry['admissions']);
            } else {
                $entry['admissions'] = $admissions;
            }
            $entry['withdrawals'] = [...self::withdrawalsIn($entry), $taken];
            $entry['history'] = $history;
            $map[$key] = $entry;

            return $map;
        });

        return $taken;
    }

    /**
     * What persons withdrew from this seat, oldest first — each scope of a capability, the verbs it had opened, who
     * took it out and when, and who had admitted it. Empty for a key never enrolled, and empty once revoked.
     *
     * @return list<array{capability: string, scope: string, verbs: list<string>, withdrawn_by: string, at: string, admitted_by: string}>
     */
    public function withdrawalsFor(string $fingerprint): array
    {
        $map = $this->read() ?? [];
        $entry = $map[IdentityKey::normalize($fingerprint)] ?? null;
        if (!\is_array($entry) || !\is_array($entry['scopes'] ?? null) || ($entry['revoked_by'] ?? null) !== null) {
            return [];
        }

        return self::withdrawalsIn($entry);
    }

    /**
     * The withdrawals an entry carries, read strictly: anything that is not the shape {@see withdraw()} writes is
     * not one.
     *
     * @param array<mixed> $entry
     *
     * @return list<array{capability: string, scope: string, verbs: list<string>, withdrawn_by: string, at: string, admitted_by: string}>
     */
    private static function withdrawalsIn(array $entry): array
    {
        $out = [];
        foreach (\is_array($entry['withdrawals'] ?? null) ? $entry['withdrawals'] : [] as $line) {
            if (!\is_array($line) || !\is_string($line['capability'] ?? null) || !\is_string($line['scope'] ?? null) || !\is_string($line['withdrawn_by'] ?? null)) {
                continue;
            }
            $out[] = [
                'capability' => $line['capability'],
                'scope' => $line['scope'],
                'verbs' => array_values(array_filter(\is_array($line['verbs'] ?? null) ? $line['verbs'] : [], '\is_string')),
                'withdrawn_by' => $line['withdrawn_by'],
                'at' => \is_string($line['at'] ?? null) ? $line['at'] : '',
                'admitted_by' => \is_string($line['admitted_by'] ?? null) ? $line['admitted_by'] : '',
            ];
        }

        return $out;
    }

    /**
     * What persons admitted to this seat and still stands in its entry: capability → scope → the verbs it opened,
     * each with the digest it was admitted at. Empty for a key never enrolled, and empty once revoked.
     *
     * @return array<string, array<string, array{verbs: array<string, string>, admitted_by: string, at: string}>>
     */
    public function admissionsFor(string $fingerprint): array
    {
        $map = $this->read() ?? [];
        $entry = $map[IdentityKey::normalize($fingerprint)] ?? null;
        if (!\is_array($entry) || !\is_array($entry['scopes'] ?? null) || ($entry['revoked_by'] ?? null) !== null) {
            return [];
        }

        return self::admissionsIn($entry);
    }

    /**
     * The admissions an entry carries, read strictly: anything that is not the shape {@see admit()} writes is not
     * an admission.
     *
     * @param array<mixed> $entry
     *
     * @return array<string, array<string, array{verbs: array<string, string>, admitted_by: string, at: string}>>
     */
    private static function admissionsIn(array $entry): array
    {
        $out = [];
        foreach (\is_array($entry['admissions'] ?? null) ? $entry['admissions'] : [] as $capability => $scopes) {
            foreach (\is_array($scopes) ? $scopes : [] as $scope => $admission) {
                $verbs = \is_array($admission) && \is_array($admission['verbs'] ?? null) ? $admission['verbs'] : null;
                if ($verbs === null) {
                    continue;
                }
                $pinned = [];
                foreach ($verbs as $verb => $digest) {
                    if (\is_string($verb) && \is_string($digest)) {
                        $pinned[$verb] = $digest;
                    }
                }
                $out[(string) $capability][(string) $scope] = [
                    'verbs' => $pinned,
                    'admitted_by' => \is_string($admission['admitted_by'] ?? null) ? $admission['admitted_by'] : '',
                    'at' => \is_string($admission['at'] ?? null) ? $admission['at'] : '',
                ];
            }
        }

        return $out;
    }

    /**
     * Lay a revocation over a live recognition (the enrollment stays); false if there was none.
     *
     * @throws \RuntimeException when the ledger could not be written
     */
    public function revoke(string $fingerprint, string $revokedBy): bool
    {
        $key = IdentityKey::normalize($fingerprint);
        $revoked = false;
        $this->mutate(static function (array $map) use ($key, $revokedBy, &$revoked): array {
            $entry = $map[$key] ?? null;
            // Nothing to revoke if it was never recognized, or if a revocation already stands.
            if (!\is_array($entry) || ($entry['revoked_by'] ?? null) !== null) {
                return $map;
            }
            $entry['revoked_by'] = $revokedBy;
            $map[$key] = $entry;
            $revoked = true;

            return $map;
        });

        return $revoked;
    }

    /**
     * True when nothing has ever been recognized — any entry, revoked or not, seals it. So does content
     * the store cannot read: a ledger it cannot read is not a greenfield to mint a root over.
     */
    public function isEmpty(): bool
    {
        return $this->read() === [];
    }

    /**
     * Whether this key has a recorded recognition, including revoked or malformed entries.
     * An unreadable ledger cannot be mistaken for a key the house never recognized.
     *
     * @throws \RuntimeException when the ledger cannot be read
     */
    public function contains(string $fingerprint): bool
    {
        $map = $this->read();
        if ($map === null) {
            throw new \RuntimeException('The enrollment ledger cannot be read; signer authority cannot be determined.');
        }

        return \array_key_exists(IdentityKey::normalize($fingerprint), $map);
    }

    /**
     * The scopes recorded for this fingerprint, or null for one never enrolled — and null once revoked.
     *
     * @return list<string>|null
     */
    public function scopesFor(string $fingerprint): ?array
    {
        $map = $this->read() ?? [];
        $entry = $map[IdentityKey::normalize($fingerprint)] ?? null;
        if (!\is_array($entry) || !\is_array($entry['scopes'] ?? null)) {
            return null;
        }
        // A revocation is a fact laid over the recognition (decisions/0117): the entry stays for the
        // audit trail, but a revoked key is no longer admitted.
        if (($entry['revoked_by'] ?? null) !== null) {
            return null;
        }

        $scopes = [];
        foreach ($entry['scopes'] as $scope) {
            if (\is_string($scope)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * Every key the ledger holds a live recognition for, in its stored form — revoked ones left out.
     *
     * @return list<string>
     */
    public function liveKeys(): array
    {
        $live = [];
        foreach ($this->read() ?? [] as $key => $entry) {
            if (\is_array($entry) && \is_array($entry['scopes'] ?? null) && ($entry['revoked_by'] ?? null) === null) {
                $live[] = (string) $key;
            }
        }

        return $live;
    }

    /**
     * Who authorized this key's standing recognition, or null for one never enrolled — and null once revoked.
     *
     * The enroller is the relation greenhouse decisions/0493 reads: the principal that answers for a seat.
     */
    public function authorizedBy(string $fingerprint): ?string
    {
        $map = $this->read() ?? [];
        $entry = $map[IdentityKey::normalize($fingerprint)] ?? null;
        if (!\is_array($entry) || ($entry['revoked_by'] ?? null) !== null) {
            return null;
        }
        $by = $entry['authorized_by'] ?? null;

        return \is_string($by) && $by !== '' ? $by : null;
    }

    /**
     * The ledger as written — `[]` when there is none yet, and null when the file holds content the
     * store cannot read as a JSON object. Null is not an empty ledger: nothing is decided over it as
     * if it were, and nothing is written over it.
     *
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);

        return \is_string($raw) ? self::decode($raw) : null;
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $raw): ?array
    {
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Read-mutate-write under an exclusive lock. It throws when the write cannot happen — the file
     * cannot be opened, the ledger holds content the store cannot read (it refuses to write over what
     * it could not keep), the map cannot be encoded, or the bytes did not reach the disk — so no
     * caller ever reports on a write that did not happen.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     *
     * @throws \RuntimeException
     */
    private function mutate(callable $fn): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $fh = @fopen($this->path, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('the identity ledger could not be opened for writing: ' . $this->path);
        }

        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $map = \is_string($raw) ? self::decode($raw) : null;
            if ($map === null) {
                throw new \RuntimeException('the identity ledger holds content the store cannot read, and it refuses to write over it: ' . $this->path);
            }

            $map = $fn($map);

            $out = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($out === false) {
                throw new \RuntimeException('the identity ledger could not be encoded (' . json_last_error_msg() . '): ' . $this->path);
            }
            ftruncate($fh, 0);
            rewind($fh);
            if (fwrite($fh, $out) !== \strlen($out)) {
                throw new \RuntimeException('the identity ledger could not be written in full: ' . $this->path);
            }
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
