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

namespace Milpa\AppRuntime\Web;

use Milpa\AppRuntime\Agent\IntentChallengeBinding;
use Milpa\AppRuntime\Agent\IntentChallengeStore;
use Milpa\Command\Consent\OperationId;

/**
 * A persistent {@see IntentChallengeStore} — the call a challenge stands for, written to disk.
 *
 * The browser ceremony spans two requests: `challengeFor` mints the challenge in one (the human is
 * shown the operation), `admit` answers it in another (the human touched the authenticator). An
 * in-memory binding would not survive that gap, so this writes it under `var/`. `take` is single-use
 * and deletes the entry; a binding older than its TTL is treated as gone, so an abandoned ceremony
 * leaves no standing authorisation.
 *
 * The binding is NOT the proof — it is only «this opaque challenge was issued for this call». The
 * proof is the live assertion {@see \Milpa\AppRuntime\Agent\PasskeyIntentAdmission::admit()}
 * re-verifies; the grade is produced there, never read from here (the forgery doctrine of
 * evidence/0254). So this file holding a call is safe: alone it authorises nothing.
 */
final class FileIntentChallengeStore implements IntentChallengeStore
{
    private const TTL_SECONDS = 300;

    public function __construct(
        private readonly string $path,
        private readonly int $ttlSeconds = self::TTL_SECONDS,
    ) {
    }

    /** Record that `$challenge` stands for `$binding`'s call, on disk, with a TTL. */
    public function bind(string $challenge, IntentChallengeBinding $binding): void
    {
        $all = $this->read();
        $all[$this->key($challenge)] = [
            'operation' => $binding->operation->canonical,
            'arguments' => $binding->arguments,
            'session' => $binding->session,
            'expiresAt' => time() + $this->ttlSeconds,
        ];
        $this->write($all);
    }

    /** Return and REMOVE the binding for `$challenge` — `null` when absent or expired. */
    public function take(string $challenge): ?IntentChallengeBinding
    {
        $all = $this->read();
        $key = $this->key($challenge);
        $entry = $all[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        unset($all[$key]);
        $this->write($all);

        if (!\is_array($entry) || (int) ($entry['expiresAt'] ?? 0) < time()) {
            return null; // expired: consumed and refused, so a stale ceremony grants nothing
        }

        return new IntentChallengeBinding(
            new OperationId(\is_string($entry['operation'] ?? null) ? $entry['operation'] : ''),
            \is_array($entry['arguments'] ?? null) ? $entry['arguments'] : [],
            \is_string($entry['session'] ?? null) ? $entry['session'] : null,
        );
    }

    /** The raw challenge bytes are the key, hex-encoded so the JSON map stays printable. */
    private function key(string $challenge): string
    {
        return bin2hex($challenge);
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $all */
    private function write(array $all): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o770, true);
        }
        file_put_contents($this->path, (string) json_encode($all, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), \LOCK_EX);
    }
}
