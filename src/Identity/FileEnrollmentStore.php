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
 */
final class FileEnrollmentStore implements EnrollmentStore
{
    public function __construct(private readonly string $path)
    {
    }

    /** Persist a recognition, keyed by its (normalized) fingerprint, under an exclusive lock. */
    public function record(IdentityEnrolled $enrolled): void
    {
        $key = IdentityKey::normalize($enrolled->fingerprint);
        $this->mutate(static function (array $map) use ($key, $enrolled): array {
            $map[$key] = ['scopes' => $enrolled->scopes, 'authorized_by' => $enrolled->authorizedBy];

            return $map;
        });
    }

    /** Lay a revocation over a live recognition (the enrollment stays); false if there was none. */
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

    /** True when nothing has ever been recognized — any entry, revoked or not, seals it. */
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
        $map = $this->read();
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

    /** @return array<string, mixed> */
    private function read(): array
    {
        $raw = is_file($this->path) ? @file_get_contents($this->path) : '';
        $decoded = \is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $fn */
    private function mutate(callable $fn): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $fh = @fopen($this->path, 'c+');
        if ($fh === false) {
            return;
        }

        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $decoded = \is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $map = \is_array($decoded) ? $decoded : [];

            $map = $fn($map);

            $out = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($out !== false) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, $out);
                fflush($fh);
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
