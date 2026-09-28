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

namespace Milpa\AppRuntime\Tests\Identity;

use Milpa\AppRuntime\Identity\IdentityInvitations;
use PHPUnit\Framework\TestCase;

/**
 * An invitation answers once, for the credential that spends it, and its secret never lands on disk
 * (greenhouse decisions/0498).
 */
final class IdentityInvitationsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-invite-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAnInvitationAdmitsOnceAndOnlyTheSecretThatMintedIt(): void
    {
        $invitations = IdentityInvitations::forRoot($this->root);
        $minted = $invitations->mint(['milpa.admin', 'capabilities:enable', 'milpa.admin'], 'key:AAAA');

        self::assertSame(['milpa.admin', 'capabilities:enable'], $minted['scopes'], 'a scope is granted once');
        self::assertStringNotContainsString($minted['token'], (string) file_get_contents($this->root . '/storage/identity/invitations.json'), 'the file holds the hash, never the secret');

        $check = $invitations->check($minted['token']);
        self::assertTrue($check['ok']);
        self::assertSame('key:AAAA', $check['authorized_by']);

        // NEGATIVES: a forged secret, an empty one.
        self::assertSame(['ok' => false, 'reason' => IdentityInvitations::UNKNOWN], $invitations->check('forged-' . $minted['token']));
        self::assertSame(['ok' => false, 'reason' => IdentityInvitations::UNKNOWN], $invitations->check(''));

        $spent = $invitations->redeem($minted['token'], 'cred-1');
        self::assertTrue($spent['ok']);
        // Spent once: a second ceremony with the same link is refused, and says why.
        self::assertSame(['ok' => false, 'reason' => IdentityInvitations::REDEEMED], $invitations->redeem($minted['token'], 'cred-2'));
        self::assertSame(['ok' => false, 'reason' => IdentityInvitations::REDEEMED], $invitations->check($minted['token']));
        self::assertSame(['cred-1'], $invitations->rootedCredentials(), 'only the credential that spent it is rooted');
    }

    public function testAnExpiredInvitationAdmitsNobody(): void
    {
        $now = new \DateTimeImmutable('2026-09-28T10:00:00+00:00');
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $invitations = new IdentityInvitations($this->root . '/inv.json', $clock);
        $minted = $invitations->mint(['milpa.admin'], 'key:AAAA', ttlSeconds: 60);

        self::assertTrue($invitations->check($minted['token'])['ok']);
        $now = $now->modify('+61 seconds');
        self::assertSame(['ok' => false, 'reason' => IdentityInvitations::EXPIRED], $invitations->redeem($minted['token'], 'cred-1'));
        self::assertSame([], $invitations->rootedCredentials());
    }

    public function testTheRootIsTheConfigAndWhatInvitationsRooted(): void
    {
        file_put_contents($this->root . '/config/identity.php', "<?php return ['rooted' => ['ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234']];");
        $invitations = IdentityInvitations::forRoot($this->root);
        $minted = $invitations->mint(['milpa.admin'], 'key:AAAA');
        $invitations->redeem($minted['token'], 'Cred-Base64Url_x');

        $root = IdentityInvitations::rootFor($this->root);
        self::assertTrue($root->admits('abcd1234 abcd1234 abcd1234 abcd1234 abcd1234'), 'the config half, normalized as always');
        self::assertTrue($root->admits('Cred-Base64Url_x'), 'the invitation half');
        self::assertFalse($root->admits('cred-base64url_x'), 'a credential id keeps its casing');
        self::assertFalse($root->admits('never-invited'));
    }

    public function testContentTheStoreCannotReadIsNeverWrittenOver(): void
    {
        mkdir($this->root . '/storage/identity', 0o775, true);
        file_put_contents($this->root . '/storage/identity/invitations.json', 'not json');

        $this->expectException(\RuntimeException::class);
        IdentityInvitations::forRoot($this->root)->mint(['milpa.admin'], 'key:AAAA');
    }
}
