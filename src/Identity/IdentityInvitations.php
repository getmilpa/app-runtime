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
 * One-time invitations: a signed act that answers, in advance, for a passkey that does not exist yet
 * (greenhouse decisions/0498).
 *
 * The root of trust used to have one way in — `config/identity.php`, declared before boot — and a fresh
 * house does not have that file, so its first human could only get in through three steps outside the
 * panel (evidence/1024, B3). An invitation is the second way in, and it keeps the property the first one
 * protects: enrolling still never mints the reason to believe a key (decisions/0117). The reason is the
 * signature of whoever minted the invitation; the ceremony that redeems it only consumes it.
 *
 * What is kept, and what is not:
 *   - the SECRET leaves once, in the result of the act that minted it. The file holds its sha256 only, so
 *     reading the file does not let anybody in;
 *   - scopes, `authorized_by`, issue and expiry times, and — once redeemed — which credential used it;
 *   - a redeemed invitation is never deleted: the credential it rooted stays rooted
 *     ({@see rootedCredentials()}), which is what lets that passkey be re-recognized later without a file.
 *
 * Every write takes an exclusive lock and re-checks under it, so two ceremonies racing for one invitation
 * cannot both win. A write that cannot happen throws; content the store cannot read is never written over.
 */
final class IdentityInvitations
{
    /** How long an invitation lives when the minter does not say — one working day. */
    public const int DEFAULT_TTL = 86400;

    /** Why a secret did not admit: never issued (or forged), already used, or out of time. */
    public const string UNKNOWN = 'unknown';

    public const string REDEEMED = 'already_used';

    public const string EXPIRED = 'expired';

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /** @param (callable(): \DateTimeImmutable)|null $clock the current time, injectable for tests */
    public function __construct(private readonly string $path, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** The invitations of the house rooted at `$root`, where every surface reads and writes them. */
    public static function forRoot(string $root): self
    {
        return new self(rtrim($root, '/') . '/storage/identity/invitations.json');
    }

    /**
     * The house's root of trust: what `config/identity.php` declares, plus every credential an invitation
     * rooted. The two ways in are read as one set, so the enrollment gate asks one question.
     */
    public static function rootFor(string $root): RootedSigners
    {
        return new RootedSigners(array_values(array_unique([
            ...IdentityConfig::load($root)->declared(),
            ...self::forRoot($root)->rootedCredentials(),
        ])));
    }

    /**
     * Mint an invitation and return its secret — the only time the secret exists outside the caller.
     *
     * @param list<string> $scopes       what the credential that redeems it will be recognized with
     * @param string       $authorizedBy the verified principal that answers for it, as `key:<fingerprint>`
     *
     * @return array{token: string, id: string, scopes: list<string>, authorized_by: string, expires_at: string}
     *
     * @throws \RuntimeException when the invitation could not be written — never a secret for nothing
     */
    public function mint(array $scopes, string $authorizedBy, int $ttlSeconds = self::DEFAULT_TTL): array
    {
        $token = self::base64Url(random_bytes(32));
        $id = substr(hash('sha256', $token), 0, 12);
        $now = ($this->clock)();
        $expires = $now->add(new \DateInterval('PT' . max(1, $ttlSeconds) . 'S'));
        $scopes = array_values(array_unique(array_filter($scopes, static fn (string $s): bool => $s !== '')));

        $this->mutate(static function (array $map) use ($id, $token, $scopes, $authorizedBy, $now, $expires): array {
            $map[$id] = [
                'hash' => hash('sha256', $token),
                'scopes' => $scopes,
                'authorized_by' => $authorizedBy,
                'issued_at' => $now->format(\DATE_ATOM),
                'expires_at' => $expires->format(\DATE_ATOM),
                'redeemed_by' => null,
            ];

            return $map;
        });

        return [
            'token' => $token,
            'id' => $id,
            'scopes' => $scopes,
            'authorized_by' => $authorizedBy,
            'expires_at' => $expires->format(\DATE_ATOM),
        ];
    }

    /**
     * The live invitation this secret names, or why there is none.
     *
     * @return array{ok: true, id: string, scopes: list<string>, authorized_by: string, expires_at: string}|array{ok: false, reason: string}
     */
    public function check(string $token): array
    {
        return self::judge($this->read() ?? [], $token, ($this->clock)());
    }

    /**
     * Spend the invitation on this credential — once. Re-judged under the lock, so of two ceremonies racing
     * for one secret exactly one is admitted.
     *
     * @return array{ok: true, id: string, scopes: list<string>, authorized_by: string, expires_at: string}|array{ok: false, reason: string}
     *
     * @throws \RuntimeException when the redemption could not be written
     */
    public function redeem(string $token, string $credentialId): array
    {
        $now = ($this->clock)();
        /** @var list<array{ok: true, id: string, scopes: list<string>, authorized_by: string, expires_at: string}|array{ok: false, reason: string}> $verdicts */
        $verdicts = [];
        $this->mutate(static function (array $map) use ($token, $credentialId, $now, &$verdicts): array {
            $verdict = self::judge($map, $token, $now);
            $verdicts[] = $verdict;
            if ($verdict['ok'] === true) {
                $id = $verdict['id'];
                $entry = \is_array($map[$id] ?? null) ? $map[$id] : [];
                $entry['redeemed_by'] = 'passkey:' . $credentialId;
                $entry['redeemed_at'] = $now->format(\DATE_ATOM);
                $map[$id] = $entry;
            }

            return $map;
        });

        return $verdicts[0] ?? ['ok' => false, 'reason' => self::UNKNOWN];
    }

    /**
     * The credentials an invitation rooted — part of the house's root from then on.
     *
     * @return list<string>
     */
    public function rootedCredentials(): array
    {
        $rooted = [];
        foreach ($this->read() ?? [] as $invitation) {
            $by = \is_array($invitation) ? ($invitation['redeemed_by'] ?? null) : null;
            if (\is_string($by) && str_starts_with($by, 'passkey:') && \strlen($by) > 8) {
                $rooted[] = substr($by, 8);
            }
        }

        return $rooted;
    }

    /** Whether any invitation was ever redeemed — the house is no longer waiting for its first human. */
    public function anyRedeemed(): bool
    {
        return $this->rootedCredentials() !== [];
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return array{ok: true, id: string, scopes: list<string>, authorized_by: string, expires_at: string}|array{ok: false, reason: string}
     */
    private static function judge(array $map, string $token, \DateTimeImmutable $now): array
    {
        if (trim($token) === '') {
            return ['ok' => false, 'reason' => self::UNKNOWN];
        }
        $hash = hash('sha256', $token);
        foreach ($map as $id => $invitation) {
            if (!\is_array($invitation) || !\is_string($invitation['hash'] ?? null) || !hash_equals($invitation['hash'], $hash)) {
                continue;
            }
            if (($invitation['redeemed_by'] ?? null) !== null) {
                return ['ok' => false, 'reason' => self::REDEEMED];
            }
            $expires = \is_string($invitation['expires_at'] ?? null) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $invitation['expires_at']) : false;
            if ($expires === false || $expires <= $now) {
                return ['ok' => false, 'reason' => self::EXPIRED];
            }
            $scopes = [];
            foreach (\is_array($invitation['scopes'] ?? null) ? $invitation['scopes'] : [] as $scope) {
                if (\is_string($scope) && $scope !== '') {
                    $scopes[] = $scope;
                }
            }
            $by = $invitation['authorized_by'] ?? null;
            if ($scopes === [] || !\is_string($by) || $by === '') {
                // An invitation that grants nothing, or that nobody answers for, admits nobody.
                return ['ok' => false, 'reason' => self::UNKNOWN];
            }

            return ['ok' => true, 'id' => (string) $id, 'scopes' => $scopes, 'authorized_by' => $by, 'expires_at' => $invitation['expires_at']];
        }

        return ['ok' => false, 'reason' => self::UNKNOWN];
    }

    /** @return array<string, mixed>|null null when the file holds content that is not a JSON object */
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
            throw new \RuntimeException('the invitations could not be opened for writing: ' . $this->path);
        }

        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $map = \is_string($raw) ? self::decode($raw) : null;
            if ($map === null) {
                throw new \RuntimeException('the invitations file holds content the store cannot read, and it refuses to write over it: ' . $this->path);
            }
            $map = $fn($map);
            $out = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($out === false) {
                throw new \RuntimeException('the invitations could not be encoded (' . json_last_error_msg() . '): ' . $this->path);
            }
            ftruncate($fh, 0);
            rewind($fh);
            if (fwrite($fh, $out) !== \strlen($out)) {
                throw new \RuntimeException('the invitations could not be written in full: ' . $this->path);
            }
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
