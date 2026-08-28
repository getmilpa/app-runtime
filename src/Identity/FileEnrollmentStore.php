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
 * each other. Fingerprints are normalized (uppercased, spaces stripped) so a key pasted either way
 * reads back the same recognition.
 */
final class FileEnrollmentStore implements EnrollmentStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function record(IdentityEnrolled $enrolled): void
    {
        $key = self::normalize($enrolled->fingerprint);
        $this->mutate(static function (array $map) use ($key, $enrolled): array {
            $map[$key] = ['scopes' => $enrolled->scopes, 'authorized_by' => $enrolled->authorizedBy];

            return $map;
        });
    }

    public function scopesFor(string $fingerprint): ?array
    {
        $map = $this->read();
        $entry = $map[self::normalize($fingerprint)] ?? null;
        if (!\is_array($entry) || !\is_array($entry['scopes'] ?? null)) {
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

    private static function normalize(string $fingerprint): string
    {
        return strtoupper(str_replace(' ', '', $fingerprint));
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
