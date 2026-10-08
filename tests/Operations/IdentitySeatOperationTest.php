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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\InMemoryIntentChallengeStore;
use Milpa\AppRuntime\Agent\PasskeyIntentAdmission;
use Milpa\AppRuntime\Agent\PasskeyIntentProof;
use Milpa\AppRuntime\Agent\SeatFrontier;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\IdentityInvitations;
use Milpa\AppRuntime\Identity\ResidentSeat;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Auth\WebAuthn\ChallengeStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyCredentialStore;
use Milpa\Auth\WebAuthn\RegisteredCredential;
use Milpa\Auth\WebAuthn\RelyingParty;
use Milpa\Command\Consent\OperationId;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * identity:seat and identity:accept (greenhouse decisions/0499): the human who answers for the house gives the
 * resident a seat from the panel — no `config/identity.php` — and the key that signs the acceptance is the key
 * the house seats, answering to that human.
 */
final class IdentitySeatOperationTest extends TestCase
{
    /** The key that opened the panel at station 2: it enrolled the human's passkey (decisions/0498). */
    private const HUMAN = 'BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666';
    /** Another person's key, which enrolled another passkey. */
    private const STRANGER = 'D00D0000111122223333444455556666777788889';
    /** The resident's own key, which the house has never seen. */
    private const RESIDENT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';
    /** A key on the resident's machine that is not the one the human meant. */
    private const OTHER = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
    private const PASSKEY = 'QM1LEWEfsoWiMm';
    private const STRANGER_PASSKEY = 'ZZ9otherCredential';
    private const RP_ID = 'milpa.local';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testTheCatalogueSaysWhoMayMintAndWhereTheKeyAccepts(): void
    {
        $seat = $this->operation(new DIContainer(), 'identity:seat');
        self::assertSame(['identity:enroll'], $seat->scopes);
        self::assertSame(['cli', 'http'], $seat->surfaces, 'never MCP: a model does not give itself a seat');
        self::assertTrue($seat->requiresConfirmation);
        self::assertTrue($seat->mutating);
        self::assertArrayNotHasKey('scopes', $seat->inputSchema['properties'] ?? [], 'the house declares what a seat holds; nobody types it');

        $accept = $this->operation(new DIContainer(), 'identity:accept');
        self::assertSame(['cli'], $accept->surfaces, 'the key lives on the resident\'s machine; the signature is the act');
        self::assertTrue($accept->requiresConfirmation);
    }

    public function testThePanelGivesTheResidentASeatWithoutAFileAndTheLineHolds(): void
    {
        [$c, $root] = $this->house();
        [, $touch] = $this->passkey($c, self::PASSKEY);
        $call = ['label' => 'resident'];

        $minted = $this->call($c, 'identity:seat', $call + ['assertion' => $touch('identity:seat', $call, ResidentSeat::INTENT_SESSION)], $this->web(self::PASSKEY));

        self::assertTrue($minted['ok'], (string) ($minted['error'] ?? ''));
        self::assertSame(ResidentSeat::SCOPES, $minted['scopes']);
        self::assertSame('passkey:' . self::PASSKEY, $minted['vouched_by']);
        self::assertNull($minted['for_key']);
        $secret = $this->secretOf((string) $minted['command']);
        self::assertStringStartsWith('php bin/coa identity:accept --invite=', (string) $minted['command']);
        self::assertStringNotContainsString($secret, (string) file_get_contents($root . '/storage/identity/invitations.json'), 'only its hash is kept');

        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $secret]);
        $seated = $this->call($c, 'identity:accept', ['invite' => $secret]);

        self::assertTrue($seated['ok'], (string) ($seated['error'] ?? ''));
        self::assertSame(self::RESIDENT, $seated['fingerprint'], 'the key seated is the key that signed');
        self::assertSame('resident', $seated['label']);
        self::assertSame('passkey:' . self::PASSKEY, $seated['authorized_by']);
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        self::assertSame(ResidentSeat::SCOPES, $ledger->scopesFor(self::RESIDENT));
        self::assertFileDoesNotExist($root . '/config/identity.php', 'no file was edited to root the seat');

        // The line of decisions/0493: the human's passkey, and the key that enrolled it, answer for the seat's sessions.
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $sessions->start('blog', 'Build the blog', by: new Principal('key:' . self::RESIDENT, true));
        $frontier = SeatFrontier::forRoot($root, $sessions);
        self::assertTrue($frontier->answersFor('passkey:' . self::PASSKEY, 'blog'));
        self::assertTrue($frontier->answersFor('key:' . self::HUMAN, 'blog'));
        self::assertFalse($frontier->answersFor('passkey:' . self::STRANGER_PASSKEY, 'blog'), 'another line does not see it');

        // «Your seats»: the seat, for its line only — never the passkeys, never the viewer itself.
        self::assertSame([[
            'fingerprint' => self::RESIDENT,
            'label' => 'resident',
            'scopes' => ResidentSeat::SCOPES,
            'authorized_by' => 'passkey:' . self::PASSKEY,
        ]], ResidentSeat::seatsFor($root, 'passkey:' . self::PASSKEY));
        self::assertSame([], ResidentSeat::seatsFor($root, 'passkey:' . self::STRANGER_PASSKEY));
        self::assertSame([], ResidentSeat::seatsFor($root, 'key:' . self::RESIDENT), 'a seat is not its own seat');
    }

    public function testTheSeatsFrontierIsGrantedInThePanelWithoutAFile(): void
    {
        [$c, $root] = $this->house();
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $secret]);
        self::assertTrue($this->call($c, 'identity:accept', ['invite' => $secret])['ok']);
        // The panel is another request: no terminal signature in it.
        $c = $this->container($root);
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $sessions->start('blog', 'Build the blog', by: new Principal('key:' . self::RESIDENT, true));
        $seq = $sessions->recordToolCall('blog', 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], "Missing required permission 'plugins.Blog:write' for plugin 'Blog'.", false, true);
        [, $touch] = $this->passkey($c, self::PASSKEY);
        $call = ['session' => 'blog', 'seq' => $seq];

        $granted = $this->call($c, 'identity:grant', $call + ['assertion' => $touch('identity:grant', $call, 'blog')], $this->web(self::PASSKEY));

        // The seat is rooted by the invitation it spent, so re-recognizing it with one more scope needs no file.
        self::assertTrue($granted['ok'], (string) ($granted['error'] ?? ''));
        self::assertSame([...ResidentSeat::SCOPES, 'plugins.Blog:write'], (new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))->scopesFor(self::RESIDENT));
        self::assertFileDoesNotExist($root . '/config/identity.php');
    }

    public function testAnInvitationBoundToAKeyRefusesAnotherAndStaysUnspent(): void
    {
        [$c, $root] = $this->house();
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident', 'fingerprint' => strtolower(self::RESIDENT)]);
        $before = $this->state($root);

        $this->signed($c, self::OTHER, 'identity:accept', ['invite' => $secret]);
        $wrong = $this->call($c, 'identity:accept', ['invite' => $secret]);

        self::assertFalse($wrong['ok']);
        self::assertSame(IdentityInvitations::WRONG_KEY, $wrong['reason']);
        self::assertSame($before, $this->state($root), 'the ledger and the invitation are untouched');

        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $secret]);
        self::assertTrue($this->call($c, 'identity:accept', ['invite' => $secret])['ok'], 'the key it names still takes it');
    }

    public function testAReusedOrForgedInvitationSeatsNothing(): void
    {
        [$c, $root] = $this->house();
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $secret]);
        self::assertTrue($this->call($c, 'identity:accept', ['invite' => $secret])['ok']);
        $before = $this->state($root);

        $this->signed($c, self::OTHER, 'identity:accept', ['invite' => $secret]);
        $reused = $this->call($c, 'identity:accept', ['invite' => $secret]);
        $this->signed($c, self::OTHER, 'identity:accept', ['invite' => 'made-up-secret']);
        $forged = $this->call($c, 'identity:accept', ['invite' => 'made-up-secret']);

        self::assertSame(IdentityInvitations::REDEEMED, $reused['reason']);
        self::assertSame(IdentityInvitations::UNKNOWN, $forged['reason']);
        self::assertSame($before, $this->state($root));
    }

    public function testTheFirstHumansInvitationNeverSeatsAKey(): void
    {
        [$c, $root] = $this->house();
        // What station 2 mints: a passkey invitation with the panel and identity:enroll (decisions/0498).
        $first = IdentityInvitations::forRoot($root)->mint(['milpa.admin', 'capabilities:enable', 'identity:enroll'], 'key:' . self::HUMAN);
        $before = $this->state($root);

        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $first['token']]);
        $r = $this->call($c, 'identity:accept', ['invite' => $first['token']]);

        self::assertFalse($r['ok'], 'a machine does not become an administrator of the house');
        self::assertSame(IdentityInvitations::UNKNOWN, $r['reason']);
        self::assertSame($before, $this->state($root));
        self::assertTrue(IdentityInvitations::forRoot($root)->check($first['token'])['ok'], 'and the first human can still use it');
    }

    public function testASeatInvitationNeverAdmitsAPasskey(): void
    {
        [$c, $root] = $this->house();
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $invitations = IdentityInvitations::forRoot($root);

        self::assertSame(IdentityInvitations::UNKNOWN, $invitations->check($secret)['reason'] ?? null);
        self::assertSame(IdentityInvitations::UNKNOWN, $invitations->redeem($secret, 'browserCredential')['reason'] ?? null);
        self::assertTrue($invitations->checkSeat($secret, self::RESIDENT)['ok'], 'it stays unspent for the key');
    }

    public function testASignatureOverAnotherInvitationSeatsNothing(): void
    {
        [$c, $root] = $this->house();
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $before = $this->state($root);

        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => 'another-secret']);
        $r = $this->call($c, 'identity:accept', ['invite' => $secret]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('does not cover', (string) $r['error']);
        self::assertSame($before, $this->state($root));
    }

    public function testWithoutASignatureNothingIsSeated(): void
    {
        [$c, $root] = $this->house();
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $before = $this->state($root);

        $r = $this->call($this->container($root), 'identity:accept', ['invite' => $secret]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('--sign', (string) $r['error']);
        self::assertSame($before, $this->state($root));
    }

    public function testARecognizedKeyIsNotReseatedByAnInvitation(): void
    {
        [$c, $root] = $this->house();
        (new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))->record(new IdentityEnrolled(self::OTHER, ['agent:read'], 'key:' . self::STRANGER));
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $before = $this->state($root);

        $this->signed($c, self::OTHER, 'identity:accept', ['invite' => $secret]);
        $r = $this->call($c, 'identity:accept', ['invite' => $secret]);

        self::assertSame(ResidentSeat::ALREADY_RECOGNIZED, $r['reason']);
        self::assertSame($before, $this->state($root));
    }

    public function testTheKeyThatAnswersForTheInvitationCannotTakeIt(): void
    {
        [$c, $root] = $this->house();
        // Minted from the panel by the passkey HUMAN's key enrolled: HUMAN is on its line.
        [, $touch] = $this->passkey($c, self::PASSKEY);
        $minted = $this->call($c, 'identity:seat', ['label' => 'resident', 'assertion' => $touch('identity:seat', ['label' => 'resident'], ResidentSeat::INTENT_SESSION)], $this->web(self::PASSKEY));
        $secret = $this->secretOf((string) $minted['command']);
        $before = $this->state($root);

        $this->signed($c, self::HUMAN, 'identity:accept', ['invite' => $secret]);
        $r = $this->call($c, 'identity:accept', ['invite' => $secret]);

        self::assertSame(ResidentSeat::VOUCHES_FOR_IT, $r['reason'], 'the station-2 key would shrink to a seat\'s scopes');
        self::assertStringContainsString('run the same command with GNUPGHOME set to the resident\'s keyring', (string) $r['error'], 'the refusal says which keyring to sign with (0543)');
        self::assertSame($before, $this->state($root));
    }

    public function testAnExpiredInvitationSeatsNothing(): void
    {
        [$c, $root] = $this->house();
        $past = new IdentityInvitations($root . '/storage/identity/invitations.json', static fn (): \DateTimeImmutable => new \DateTimeImmutable('-2 hours'));
        $minted = $past->mint(ResidentSeat::SCOPES, 'key:' . self::HUMAN, IdentityInvitations::SEAT_TTL, IdentityInvitations::SEAT);
        $before = $this->state($root);

        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $minted['token']]);
        $r = $this->call($c, 'identity:accept', ['invite' => $minted['token']]);

        self::assertSame(IdentityInvitations::EXPIRED, $r['reason']);
        self::assertSame($before, $this->state($root));
    }

    public function testMintingNeedsATouchBoundToThisSeat(): void
    {
        [$c, $root] = $this->house();
        [, $touch] = $this->passkey($c, self::PASSKEY);
        [, $strangerTouch] = $this->passkey($c, self::STRANGER_PASSKEY);
        $before = $this->state($root);

        $untouched = $this->call($c, 'identity:seat', ['label' => 'resident'], $this->web(self::PASSKEY));
        $otherLabel = $this->call($c, 'identity:seat', ['label' => 'resident', 'assertion' => $touch('identity:seat', ['label' => 'intruder'], ResidentSeat::INTENT_SESSION)], $this->web(self::PASSKEY));
        $otherCredential = $this->call($c, 'identity:seat', ['label' => 'resident', 'assertion' => $strangerTouch('identity:seat', ['label' => 'resident'], ResidentSeat::INTENT_SESSION)], $this->web(self::PASSKEY));
        $anonymous = $this->call($c, 'identity:seat', ['label' => 'resident']);

        foreach ([$untouched, $otherLabel, $otherCredential, $anonymous] as $r) {
            self::assertFalse($r['ok']);
            self::assertStringContainsString('nothing was minted', (string) $r['error']);
        }
        self::assertSame($before, $this->state($root), 'no invitation was written');
    }

    public function testATerminalSignatureOverAnotherSeatMintsNothing(): void
    {
        [$c] = $this->house();
        $this->signed($c, self::HUMAN, 'identity:seat', ['label' => 'resident']);

        $r = $this->call($c, 'identity:seat', ['label' => 'resident', 'fingerprint' => self::OTHER]);

        self::assertFalse($r['ok'], 'the fingerprint is part of what was signed');
        self::assertStringContainsString('does not cover', (string) $r['error']);
    }

    /**
     * The fourth rehearsal (greenhouse evidence/1069 §C3): with the resident seated, «Give the resident a seat» was
     * still offered, and pressing it minted another invitation for another passkey touch. A house has one resident
     * per name (greenhouse decisions/0536): the judge refuses the second, and the panel only mirrors it.
     */
    public function testANameThatHoldsASeatMintsNoSecondInvitation(): void
    {
        [$c, $root] = $this->house();
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $secret]);
        self::assertTrue($this->call($c, 'identity:accept', ['invite' => $secret])['ok']);
        $c = $this->container($root);
        $before = $this->state($root);

        foreach (['resident', 'Resident'] as $spelling) {
            // One touch per act: the lab authenticator counts each assertion once.
            [, $touch] = $this->passkey($c, self::PASSKEY);
            $call = ['label' => $spelling];
            $again = $this->call($c, 'identity:seat', $call + ['assertion' => $touch('identity:seat', $call, ResidentSeat::INTENT_SESSION)], $this->web(self::PASSKEY));

            self::assertFalse($again['ok'], 'a second seat for «' . $spelling . '»');
            self::assertSame(ResidentSeat::SEAT_TAKEN, $again['reason']);
            self::assertSame(self::RESIDENT, $again['held_by']);
            self::assertStringContainsStringIgnoringCase('«resident» already has a seat', (string) $again['error']);
            self::assertStringContainsString('identity:revoke', (string) $again['error']);
            self::assertStringContainsString('nothing was minted', (string) $again['error']);
        }
        self::assertSame($before, $this->state($root), 'no invitation was written');

        // Another name is another seat, and still mints.
        [, $touch] = $this->passkey($c, self::PASSKEY);
        $call = ['label' => 'reviewer'];
        $other = $this->call($c, 'identity:seat', $call + ['assertion' => $touch('identity:seat', $call, ResidentSeat::INTENT_SESSION)], $this->web(self::PASSKEY));
        self::assertTrue($other['ok'], (string) ($other['error'] ?? ''));
    }

    public function testAnUnauthorizedCallerIsNotToldWhichNamesAreTaken(): void
    {
        [$c, $root] = $this->house();
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $secret]);
        self::assertTrue($this->call($c, 'identity:accept', ['invite' => $secret])['ok']);

        $anonymous = $this->call($this->container($root), 'identity:seat', ['label' => 'resident']);

        self::assertFalse($anonymous['ok']);
        self::assertArrayNotHasKey('held_by', $anonymous);
        self::assertStringNotContainsString(self::RESIDENT, (string) $anonymous['error']);
    }

    public function testOfTwoInvitationsForOneNameOnlyTheFirstKeyIsSeated(): void
    {
        [$c, $root] = $this->house();
        $first = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $second = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $first]);
        self::assertTrue($this->call($c, 'identity:accept', ['invite' => $first])['ok']);
        $before = $this->state($root);

        $this->signed($c, self::OTHER, 'identity:accept', ['invite' => $second]);
        $late = $this->call($c, 'identity:accept', ['invite' => $second]);

        self::assertFalse($late['ok']);
        self::assertSame(ResidentSeat::SEAT_TAKEN, $late['reason']);
        self::assertStringContainsString('nothing was seated', (string) $late['error']);
        self::assertSame($before, $this->state($root), 'the ledger and the invitation are untouched');
        self::assertNull((new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))->scopesFor(self::OTHER));
    }

    public function testRevokingTheSeatFreesItsName(): void
    {
        [$c, $root] = $this->house();
        $secret = $this->mintFromTheTerminal($c, self::HUMAN, ['label' => 'resident']);
        $this->signed($c, self::RESIDENT, 'identity:accept', ['invite' => $secret]);
        self::assertTrue($this->call($c, 'identity:accept', ['invite' => $secret])['ok']);
        self::assertTrue((new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))->revoke(self::RESIDENT, 'key:' . self::HUMAN));

        $secret = $this->mintFromTheTerminal($this->container($root), self::HUMAN, ['label' => 'resident']);
        $c = $this->container($root);
        $this->signed($c, self::OTHER, 'identity:accept', ['invite' => $secret]);
        $replaced = $this->call($c, 'identity:accept', ['invite' => $secret]);

        self::assertTrue($replaced['ok'], (string) ($replaced['error'] ?? ''));
        self::assertSame('resident', $replaced['label']);
    }

    // --- helpers ---

    /**
     * A fresh house after station 5: no `config/identity.php`, the human's passkey enrolled by the station-2 key,
     * another person's passkey enrolled by another key.
     *
     * @return array{0: DIContainer, 1: string}
     */
    private function house(): array
    {
        $root = sys_get_temp_dir() . '/milpa-seat-op-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        $this->dirs[] = $root;
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $ledger->record(new IdentityEnrolled(self::PASSKEY, ['milpa.admin', 'identity:enroll', 'agent:read'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::STRANGER_PASSKEY, ['milpa.admin', 'identity:enroll', 'agent:read'], 'key:' . self::STRANGER));

        return [$this->container($root), $root];
    }

    /** One request's container over the house at `$root`. */
    private function container(string $root): DIContainer
    {
        $c = new DIContainer();
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setAccessible(true);
            $p->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        $events = new InMemoryEventStore();
        $c->registerService(EventStoreInterface::class, $events);
        $c->registerService(SessionStore::class, new SessionStore($events));

        return $c;
    }

    /** What a negative must leave byte-identical: the ledger and the invitations. */
    private function state(string $root): string
    {
        $read = static fn (string $f): string => is_file($f) ? (string) file_get_contents($f) : '';

        return $read($root . '/storage/identity/enrollments.json') . "\n--\n" . $read($root . '/storage/identity/invitations.json');
    }

    /**
     * Mint a seat invitation signed at the terminal and return its secret.
     *
     * @param array<string, string> $call
     */
    private function mintFromTheTerminal(DIContainer $c, string $fingerprint, array $call): string
    {
        $this->signed($c, $fingerprint, 'identity:seat', $call);
        $minted = $this->call($c, 'identity:seat', $call);
        self::assertTrue($minted['ok'], (string) ($minted['error'] ?? ''));
        self::assertSame('key:' . $fingerprint, $minted['vouched_by']);

        return $this->secretOf((string) $minted['command']);
    }

    private function secretOf(string $command): string
    {
        self::assertSame(1, preg_match('/--invite=(\S+) --sign$/', $command, $m));

        return $m[1];
    }

    private function web(string $credentialId): ToolContext
    {
        return new ToolContext('passkey:' . $credentialId, 'web', ['identity:enroll']);
    }

    /** @param array<string, mixed> $arguments */
    private function signed(DIContainer $c, string $fingerprint, string $operation, array $arguments): void
    {
        $authorization = new OperationAuthorization(
            operation: $operation,
            arguments: $arguments,
            host: 'lab-host',
            issuedAt: '2026-09-28T00:00:00+00:00',
            nonce: 'n-' . bin2hex(random_bytes(3)),
        );
        $c->replaceService(GrantedAuthorization::class, new GrantedAuthorization(
            authorization: $authorization,
            signer: new VerifiedSigner($fingerprint, 'Lab <lab@example.invalid>'),
            payload: $authorization->canonical(),
            signature: 'exact-signature-bytes',
        ));
    }

    /**
     * Register the intent proof for one credential and return a function that touches it for a call.
     *
     * @return array{0: PasskeyIntentProof, 1: \Closure(string, array<string, mixed>, string): array<string, string>}
     */
    private function passkey(DIContainer $c, string $credentialId): array
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $admission = new PasskeyIntentAdmission($this->authenticator($credentialId, (string) $details['key']), new InMemoryIntentChallengeStore());
        $proof = new PasskeyIntentProof($admission, new RelyingParty(self::RP_ID, 'Milpa', ['https://' . self::RP_ID]));
        $c->replaceService(PasskeyIntentProof::class, $proof);
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $touch = static function (string $operation, array $call, string $session) use ($admission, $key, $credentialId, $b64): array {
            $challenge = $admission->challengeFor(new OperationId($operation), $call, $session);
            $clientData = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $b64($challenge), 'origin' => 'https://' . self::RP_ID]);
            $authData = hash('sha256', self::RP_ID, true) . "\x05" . pack('N', 1);
            $signature = '';
            openssl_sign($authData . hash('sha256', $clientData, true), $signature, $key, \OPENSSL_ALGO_SHA256);

            return ['credentialId' => $credentialId, 'clientDataJSON' => $b64($clientData), 'authenticatorData' => $b64($authData), 'signature' => $b64($signature)];
        };

        return [$proof, $touch];
    }

    private function authenticator(string $credentialId, string $publicKeyPem): PasskeyAuthenticator
    {
        $credentials = new class () implements PasskeyCredentialStore {
            /** @var array<string, RegisteredCredential> */
            public array $store = [];

            public function register(RegisteredCredential $credential): void
            {
                $this->store[$credential->credentialId] = $credential;
            }

            public function find(string $credentialId): ?RegisteredCredential
            {
                return $this->store[$credentialId] ?? null;
            }

            public function updateSignCount(string $credentialId, int $signCount): void
            {
                $c = $this->store[$credentialId] ?? null;
                if ($c !== null) {
                    $this->store[$credentialId] = new RegisteredCredential($c->credentialId, $c->publicKeyPem, $signCount);
                }
            }
        };
        $credentials->register(new RegisteredCredential($credentialId, $publicKeyPem, 0));
        $challenges = new class () implements ChallengeStore {
            /** @var array<string, true> */
            private array $live = [];

            public function issue(): string
            {
                $c = random_bytes(32);
                $this->live[$c] = true;

                return $c;
            }

            public function consume(string $challenge): bool
            {
                if (!isset($this->live[$challenge])) {
                    return false;
                }
                unset($this->live[$challenge]);

                return true;
            }
        };

        return new PasskeyAuthenticator($challenges, $credentials);
    }

    private function operation(DIContainer $c, string $name): \Milpa\Command\Operation
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === $name) {
                return $op;
            }
        }
        self::fail($name . ' is not offered');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $c, string $name, array $input, ?ToolContext $authority = null): array
    {
        $handler = $this->operation($c, $name)->handler;
        self::assertIsCallable($handler);
        /** @var array<string, mixed> */
        return $handler($input, null, $authority);
    }
}
