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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\RegisteredCredentialIds;
use Milpa\Auth\WebAuthn\FilePasskeyCredentialStore;
use Milpa\Auth\WebAuthn\RegisteredCredential;
use PHPUnit\Framework\TestCase;

/** The ids a browser needs as `allowCredentials`, read from the ledger the credential store writes (decisions/0206). */
final class RegisteredCredentialIdsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-rci-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testItListsWhatTheCredentialStoreRegistered(): void
    {
        $store = new FilePasskeyCredentialStore($this->path);
        $store->register(new RegisteredCredential('AbC-x_1', 'pem-1', 0));
        $store->register(new RegisteredCredential('second', 'pem-2', 3));

        $ids = new RegisteredCredentialIds($this->path);

        self::assertSame(['AbC-x_1', 'second'], $ids->all(), 'verbatim ids, in ledger order');
        self::assertSame(
            [['type' => 'public-key', 'id' => 'AbC-x_1'], ['type' => 'public-key', 'id' => 'second']],
            $ids->allowCredentials(),
        );
    }

    public function testNothingRegisteredIsAnEmptyList(): void
    {
        self::assertSame([], (new RegisteredCredentialIds($this->path))->all(), 'no ledger yet');
        self::assertSame([], (new RegisteredCredentialIds($this->path))->allowCredentials());

        file_put_contents($this->path, 'not json');
        self::assertSame([], (new RegisteredCredentialIds($this->path))->all(), 'a corrupt ledger offers nothing');
    }

    public function testAnEntryWithoutAPublicKeyIsNotOffered(): void
    {
        // An id without a public key could never answer a challenge; a numeric-string id is still a string.
        file_put_contents($this->path, (string) json_encode([
            'good' => ['pem' => 'pem', 'signCount' => 0],
            'half' => ['signCount' => 0],
            'junk' => 'x',
            '12345' => ['pem' => 'pem', 'signCount' => 0],
        ]));

        self::assertSame(['good', '12345'], (new RegisteredCredentialIds($this->path))->all());
    }
}
