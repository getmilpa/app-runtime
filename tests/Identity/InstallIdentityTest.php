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

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\IdentityInvitations;
use Milpa\AppRuntime\Identity\InstallIdentity;
use Milpa\Command\InvocationContext;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * What an install means for identity (greenhouse decisions/0498): the signed act that opens the panel on a
 * house that recognizes nobody mints the first passkey's invitation — and only that act; an enrolled
 * installer grows by what it installed.
 */
final class InstallIdentityTest extends TestCase
{
    private const KEY = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';

    private string $root;

    private string $vendor;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-install-id-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        $this->vendor = $this->vendorWith([
            $this->package('milpa/admin', 'admin', ['milpa.admin', 'capabilities:enable']),
            $this->package('milpa/auth', 'identity'),
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheSignedActThatOpensThePanelInvitesTheFirstHuman(): void
    {
        $out = InstallIdentity::settle(['ok' => true, 'capability' => 'milpa/admin'], $this->root, $this->vendor, $this->signed(), null);

        $first = $out['first_passkey'];
        self::assertSame(['milpa.admin', 'capabilities:enable', 'identity:enroll'], $first['scopes'], 'what the panel declares, plus letting the next one in');
        self::assertSame('key:' . self::KEY, $first['vouched_by']);
        self::assertStringStartsWith('/webauthn/enroll?invite=', $first['path']);
        self::assertStringContainsString('next=%2Fmilpa%2Fadmin', $first['path']);
        parse_str((string) parse_url($first['path'], \PHP_URL_QUERY), $query);
        self::assertTrue(IdentityInvitations::forRoot($this->root)->check((string) $query['invite'])['ok'], 'the printed link is live');
    }

    public function testNobodyIsInvitedWithoutASignatureAtTheTerminal(): void
    {
        $unsigned = InstallIdentity::settle(['ok' => true, 'capability' => 'milpa/admin'], $this->root, $this->vendor, new InvocationContext(channel: 'cli'), null);
        self::assertFalse($unsigned['first_passkey']['minted']);
        self::assertStringContainsString('identity:invite --sign', $unsigned['first_passkey']['why']);

        // An agent's signed call over MCP never receives the secret.
        $agent = InstallIdentity::settle(['ok' => true, 'capability' => 'milpa/admin'], $this->root, $this->vendor, new InvocationContext(actor: 'key:' . self::KEY, verified: true, channel: 'mcp'), null);
        self::assertFalse($agent['first_passkey']['minted']);

        self::assertFileDoesNotExist($this->root . '/storage/identity/invitations.json', 'nothing was minted');
    }

    public function testAHouseThatAlreadyRecognizesSomeoneMintsNothing(): void
    {
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))->record(new IdentityEnrolled('pk-0', ['milpa.admin'], 'key:' . self::KEY));
        $out = InstallIdentity::settle(['ok' => true, 'capability' => 'milpa/devtools'], $this->root, $this->vendor, $this->signed(), null);
        self::assertArrayNotHasKey('first_passkey', $out);

        // Nor one that declared a root out of band, nor one without a panel.
        exec('rm -rf ' . escapeshellarg($this->root . '/storage'));
        file_put_contents($this->root . '/config/identity.php', "<?php return ['rooted' => ['" . self::KEY . "']];");
        self::assertArrayNotHasKey('first_passkey', InstallIdentity::settle(['ok' => true], $this->root, $this->vendor, $this->signed(), null));
        unlink($this->root . '/config/identity.php');
        $noPanel = $this->vendorWith([$this->package('milpa/auth', 'identity')]);
        self::assertArrayNotHasKey('first_passkey', InstallIdentity::settle(['ok' => true], $this->root, $noPanel, $this->signed(), null));
    }

    public function testTheWebInstallerGrowsByWhatItBrought(): void
    {
        $ledger = new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
        $ledger->record(new IdentityEnrolled('pk-1', ['milpa.admin', 'capabilities:enable', 'identity:enroll'], 'key:' . self::KEY));
        $vendor = $this->vendorWith([
            $this->package('milpa/admin', 'admin', ['milpa.admin', 'capabilities:enable']),
            $this->package('milpa/auth', 'identity'),
            $this->package('milpa/agent-workspace', 'agent-workspace', ['agent:read', 'agent:answer']),
            $this->package('milpa/data', 'persistence'),
        ]);
        $web = new ToolContext(principal: 'passkey:pk-1', channel: 'web', scopes: ['capabilities:enable']);

        $data = InstallIdentity::settle(['ok' => true, 'capability' => 'milpa/data'], $this->root, $vendor, null, $web);
        self::assertArrayNotHasKey('granted_to_you', $data, 'a capability that declares no operator scopes grants nothing');

        $room = InstallIdentity::settle(['ok' => true, 'capability' => 'milpa/agent-workspace'], $this->root, $vendor, null, $web);
        self::assertSame(['agent:read', 'agent:answer'], $room['granted_to_you']);
        self::assertSame(['milpa.admin', 'capabilities:enable', 'identity:enroll', 'agent:read', 'agent:answer'], $ledger->scopesFor('pk-1'));
        self::assertSame('key:' . self::KEY, $ledger->authorizedBy('pk-1'));

        // CONTROL: the same install attributed to the terminal grows nobody's passkey.
        $ledger->record(new IdentityEnrolled('pk-1', ['milpa.admin'], 'key:' . self::KEY));
        InstallIdentity::settle(['ok' => true, 'capability' => 'milpa/agent-workspace'], $this->root, $vendor, $this->signed(), null);
        self::assertSame(['milpa.admin'], $ledger->scopesFor('pk-1'));
    }

    private function signed(): InvocationContext
    {
        return new InvocationContext(actor: 'key:' . self::KEY, verified: true, channel: 'cli', authorizationId: 'sha256:x');
    }

    /** @param list<array<string, mixed>> $packages */
    private function vendorWith(array $packages): string
    {
        $dir = $this->root . '/vendor-' . bin2hex(random_bytes(3));
        mkdir($dir . '/composer', 0o775, true);
        file_put_contents($dir . '/composer/installed.json', json_encode(['packages' => $packages], \JSON_THROW_ON_ERROR));

        return $dir;
    }

    /**
     * @param list<string> $operatorScopes
     *
     * @return array<string, mixed>
     */
    private function package(string $name, string $id, array $operatorScopes = []): array
    {
        $capability = ['id' => $id, 'title' => $id, 'unlocks' => [], 'provides' => []];
        if ($operatorScopes !== []) {
            $capability['operator_scopes'] = $operatorScopes;
        }

        return ['name' => $name, 'version' => '1.0.0', 'extra' => ['milpa' => ['capability' => $capability]]];
    }
}
