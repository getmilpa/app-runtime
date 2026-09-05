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
     * @return array{previously_revoked_by: ?string, history_entries: int}
     *
     * @throws \RuntimeException when the ledger could not be written — the report is never returned
     *                           for a write that did not happen
     */
    public function recordAndReport(IdentityEnrolled $enrolled): array
    {
        $key = IdentityKey::normalize($enrolled->fingerprint);
        $report = ['previously_revoked_by' => null, 'history_entries' => 0];
        $this->mutate(static function (array $map) use ($key, $enrolled, &$report): array {
            $entry = ['scopes' => $enrolled->scopes, 'authorized_by' => $enrolled->authorizedBy];

            if (\array_key_exists($key, $map)) {
                $previous = $map[$key];
                if (\is_array($previous)) {
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
