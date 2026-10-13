<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\SecretFiles;
use PHPUnit\Framework\TestCase;

/**
 * THE MASK ALSO COVERS THE RAW-BEARER-CREDENTIAL STORES (greenhouse decisions/0618).
 *
 * A confined process runs under `--ro-bind / /`, so {@see SecretFiles::existingUnder()} is the list the mask
 * covers. Beyond the `.env` family and the envelope it must name the stores that keep a value which, presented,
 * opens something: the passkey session store (the cookie itself) and the HTTP confirm tokens. It must NOT name
 * the token store (sha256 hashes) or the enrollment ledger (grants over public key fingerprints) — those are not
 * bearer credentials and are a separate question.
 */
final class SecretFilesBearerStoresTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-secretfiles-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testExistingUnderNamesTheSessionStoreAndConfirmTokens(): void
    {
        $this->put('var/passkey/sessions.json', '{"deadbeef":{"actorId":"passkey:x"}}');
        $this->put('storage/confirm-tokens.json', '{"abc":{"operation":"capabilities:enable"}}');
        $this->put('.env', "LAB=1\n");
        $this->put('.milpa/secrets.json', '{"agent":{"apiKey":"x"}}');
        // The books that are NOT bearer credentials — they must stay off the mask (a separate question).
        $this->put('storage/tokens.json', '{"id":{"hash":"…"}}');
        $this->put('storage/identity/enrollments.json', '{"FP":{"scopes":["*"]}}');

        $found = SecretFiles::existingUnder($this->root);

        self::assertContains($this->root . '/var/passkey/sessions.json', $found, 'the session store is masked');
        self::assertContains($this->root . '/storage/confirm-tokens.json', $found, 'the confirm tokens are masked');
        self::assertContains($this->root . '/.env', $found, 'the env file stays masked (control)');
        self::assertContains($this->root . '/.milpa/secrets.json', $found, 'the envelope stays masked (control)');
        self::assertNotContains($this->root . '/storage/tokens.json', $found, 'the token store (hashes) is not a bearer credential');
        self::assertNotContains($this->root . '/storage/identity/enrollments.json', $found, 'the ledger is not a bearer credential');
    }

    public function testExistingUnderNamesABearerStoreInsideATrialCopy(): void
    {
        // A trial copy keeps `storage/`, so a copy's confirm tokens must be masked too (the session store lives
        // under `var/`, which the copy prunes; the real one is reached by absolute path and is covered above).
        $this->put('var/trials/w1/copy/storage/confirm-tokens.json', '{"abc":{"operation":"x"}}');

        $found = SecretFiles::existingUnder($this->root);

        self::assertContains($this->root . '/var/trials/w1/copy/storage/confirm-tokens.json', $found);
    }

    public function testExistingUnderSkipsAnAbsentStore(): void
    {
        $this->put('.env', "LAB=1\n");

        $found = SecretFiles::existingUnder($this->root);

        self::assertNotContains($this->root . '/var/passkey/sessions.json', $found, 'an absent store is not named');
    }

    private function put(string $relative, string $contents): void
    {
        $full = $this->root . '/' . $relative;
        @mkdir(\dirname($full), 0o777, true);
        file_put_contents($full, $contents);
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->remove($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
