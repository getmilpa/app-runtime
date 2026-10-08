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
 * identity:grant (greenhouse decisions/0493): the human who answers for a seat decides the scope one of
 * its recorded refusals names — and nobody outside the seat's enrollment line can.
 */
final class IdentityGrantOperationTest extends TestCase
{
    /** The human's own key: it enrolled the seat and the passkey (the rehearsal's shape, evidence/1024). */
    private const HUMAN = 'BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666';
    /** Another human's key, which enrolled nothing of this seat. */
    private const STRANGER = 'D00D0000111122223333444455556666777788889';
    /** The resident's seat. */
    private const SEAT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';
    private const PASSKEY = 'QM1LEWEfsoWiMm';
    private const STRANGER_PASSKEY = 'ZZ9otherCredential';
    private const RP_ID = 'milpa.local';
    private const SESSION = 'camino-blog';
    private const SEAT_SCOPES = ['agent:run', 'agent:read', 'plugins:read', 'plugins:write', 'plugins.config:write'];

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testTheCatalogueSaysGrantingIsSignedPrivilegedAndNeverTheSeatsOwn(): void
    {
        $op = $this->operation(new DIContainer());

        self::assertTrue($op->requiresConfirmation);
        self::assertTrue($op->mutating);
        self::assertSame(['identity:enroll'], $op->scopes);
        self::assertNotContains('mcp', $op->surfaces, 'a seat does not decide its own frontier');
        self::assertContains('http', $op->surfaces);
    }

    public function testTheHumanWhoEnrolledTheSeatGrantsExactlyTheRefusedScope(): void
    {
        [$c, $root, $seq] = $this->house();
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $seq]);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('plugins.Blog:write', $r['granted']);
        self::assertSame('key:' . self::HUMAN, $r['authorized_by']);
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        self::assertSame([...self::SEAT_SCOPES, 'plugins.Blog:write'], $ledger->scopesFor(self::SEAT));
        $raw = json_decode((string) file_get_contents($root . '/storage/identity/enrollments.json'), true);
        self::assertSame(self::SEAT_SCOPES, $raw[self::SEAT]['history'][0]['scopes'], 'the state it replaced is kept');

        // The refusal is judged again, not remembered: granted, it is no longer open, and a second grant has nothing to add.
        self::assertSame([], $this->frontier($c, $root)->openRefusals(self::SESSION));
        $again = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);
        self::assertFalse($again['ok']);
        self::assertStringContainsString('not an open refusal', (string) $again['error']);
    }

    public function testTheGrantTellsTheSeatsSessionWhatChanged(): void
    {
        [$c, , $seq] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $seq]);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertTrue($r['session_told']);
        $turns = array_values(array_filter(
            $sessions->stream(self::SESSION),
            static fn ($e): bool => $e->type === 'session.turn' && ($e->payload['role'] ?? null) === 'user',
        ));
        self::assertCount(1, $turns, 'one fact, recorded once');
        $told = (string) $turns[0]->payload['content'];
        self::assertStringContainsString('key:' . self::HUMAN . ' granted this seat the scope «plugins.Blog:write»', $told);
        self::assertStringContainsString('#' . $seq . ' (make plugin=Blog)', $told);
    }

    /**
     * The grant leaves, beside the sentence the model reads, the fact the house reads: which recorded call it was
     * given for (greenhouse decisions/0577). The seat's next leg opens with that call.
     */
    public function testTheGrantRecordsWhichRecordedCallItWasGivenFor(): void
    {
        [$c, , $seq] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertTrue($this->call($c, ['session' => self::SESSION, 'seq' => $seq])['ok']);

        $facts = array_values(array_filter($sessions->stream(self::SESSION), static fn ($e): bool => $e->type === \Milpa\AppRuntime\Agent\GrantedCall::GRANTED));
        self::assertCount(1, $facts, 'one grant, one fact');
        self::assertSame([
            'seq' => $seq,
            'tool' => 'make',
            'permission' => 'plugins.Blog:write',
            'arguments_sha256' => \Milpa\AppRuntime\Agent\ConsentBridge::digest(['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'BlogPlugin']),
            'authorized_by' => 'key:' . self::HUMAN,
        ], $facts[0]->payload);
        $types = array_map(static fn ($e): string => $e->type, $sessions->stream(self::SESSION));
        self::assertSame('session.turn', end($types), 'the sentence the model reads stays the last word');
        self::assertNull(
            \Milpa\AppRuntime\Agent\GrantedCall::toResume($sessions->stream(self::SESSION), new \DateTimeImmutable()),
            'the call granted is not the session\'s last call here: another refusal followed it, so nothing is resumed',
        );
    }

    public function testARefusedGrantRecordsNoSuchFact(): void
    {
        [$c, , $seq] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $this->signed($c, self::STRANGER, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertFalse($this->call($c, ['session' => self::SESSION, 'seq' => $seq])['ok']);
        self::assertSame([], array_values(array_filter($sessions->stream(self::SESSION), static fn ($e): bool => $e->type === \Milpa\AppRuntime\Agent\GrantedCall::GRANTED)));
    }

    public function testARefusedGrantTellsTheSessionNothing(): void
    {
        [$c, , $seq] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $this->signed($c, self::STRANGER, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertFalse($this->call($c, ['session' => self::SESSION, 'seq' => $seq])['ok']);
        self::assertSame([], array_values(array_filter($sessions->stream(self::SESSION), static fn ($e): bool => $e->type === 'session.turn')));
    }

    public function testAKeyOutsideTheSeatsLineGrantsNothing(): void
    {
        [$c, $root, $seq] = $this->house();
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');
        $this->signed($c, self::STRANGER, ['session' => self::SESSION, 'seq' => $seq]);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('do not answer for', (string) $r['error']);
        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'), 'the ledger is untouched');
    }

    /**
     * A refusal for a name the model made up is not grantable, even with a valid signature over it
     * (decisions/0496): the goal says «blog», the house has no `BlogPlugin`, so there is nothing to decide.
     */
    public function testARefusalForAnInventedPluginGrantsNothing(): void
    {
        [$c, $root] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $invented = $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'BlogPlugin', 'name' => 'BlogPlugin'], "Missing required permission 'plugins.BlogPlugin:write' for plugin 'BlogPlugin'.", false, true);
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $invented]);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $invented]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('not an open refusal', (string) $r['error']);
        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'), 'the ledger is untouched');
        self::assertSame(['plugins.Blog:write'], array_values(array_unique(array_column($this->frontier($c, $root)->openRefusals(self::SESSION), 'permission'))));
    }

    public function testASignatureOverAnotherRefusalGrantsNothing(): void
    {
        [$c, , $seq] = $this->house();
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $seq + 1]);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('does not cover', (string) $r['error']);
    }

    public function testWithoutAProvenDeciderNothingIsGranted(): void
    {
        [$c, , $seq] = $this->house();

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $seq], new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']));

        self::assertFalse($r['ok'], 'a passkey session without a touch bound to this grant decides nothing');
        self::assertStringContainsString('did not approve', (string) $r['error']);
    }

    public function testThePasskeyTheHumansKeyEnrolledGrantsWithATouchBoundToThisCall(): void
    {
        [$c, $root, $seq] = $this->house();
        [$proof, $touch] = $this->passkey($c, self::PASSKEY);
        $call = ['session' => self::SESSION, 'seq' => $seq];

        $r = $this->call($c, $call + ['assertion' => $touch($call)], new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('passkey:' . self::PASSKEY, $r['authorized_by'], 'the house knows who decided');
        // After the passkey decided, the chain starts at it and still reaches the human's key: both answer.
        self::assertTrue($this->frontier($c, $root)->answersFor('passkey:' . self::PASSKEY, self::SESSION));
        self::assertTrue($this->frontier($c, $root)->answersFor('key:' . self::HUMAN, self::SESSION));
        self::assertFalse($this->frontier($c, $root)->answersFor('passkey:' . self::STRANGER_PASSKEY, self::SESSION));
        unset($proof);
    }

    public function testAPasskeyAnotherKeyEnrolledNeitherSeesNorGrants(): void
    {
        [$c, $root, $seq] = $this->house();
        [, $touch] = $this->passkey($c, self::STRANGER_PASSKEY);
        $call = ['session' => self::SESSION, 'seq' => $seq];
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');

        self::assertSame([], $this->frontier($c, $root)->sessionsFor('passkey:' . self::STRANGER_PASSKEY), 'it does not see the seat');
        $r = $this->call($c, $call + ['assertion' => $touch($call)], new ToolContext('passkey:' . self::STRANGER_PASSKEY, 'web', ['identity:enroll']));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('do not answer for', (string) $r['error']);
        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'));
    }

    public function testATouchFromAnotherCredentialDoesNotSpeakForTheSignedInPasskey(): void
    {
        [$c, , $seq] = $this->house();
        [, $touch] = $this->passkey($c, self::STRANGER_PASSKEY);
        $call = ['session' => self::SESSION, 'seq' => $seq];

        $r = $this->call($c, $call + ['assertion' => $touch($call)], new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('did not approve', (string) $r['error']);
    }

    public function testTheHumanSeesTheSeatSessionWithItsOpenRefusal(): void
    {
        [$c, $root, $seq] = $this->house();

        $seen = $this->frontier($c, $root)->sessionsFor('passkey:' . self::PASSKEY);

        self::assertCount(1, $seen);
        self::assertSame(self::SESSION, $seen[0]['session']);
        self::assertSame('key:' . self::SEAT, $seen[0]['seat']);
        self::assertSame([[
            'seq' => $seq + 1,
            'tool' => 'make',
            'plugin' => 'Blog',
            'permission' => 'plugins.Blog:write',
            'call' => ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'],
            'target' => 'new',
            'named' => true,
            'consent' => 'touch',
            // What granting it would suspend (decisions/0590, rule 10): nothing — nobody was admitted anything of it.
            'suspends' => [],
            // How far it would reach (decisions/0602): one touch over a plugin the house does not have stands for nothing.
            'stands_for' => null,
        ]], $seen[0]['refusals'], 'one row for the scope, not one per retry — the latest retry, the call the seat still asks for (decisions/0510)');
    }

    public function testACallThatFailedForAnotherReasonIsNotAFrontier(): void
    {
        [$c, $root] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $seq = $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'no-such name'], 'Scoped authoring requires one canonical plugin name.', false, true);

        self::assertNull($this->frontier($c, $root)->refusal(self::SESSION, $seq));
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $seq]);
        self::assertFalse($this->call($c, ['session' => self::SESSION, 'seq' => $seq])['ok']);
    }

    public function testASessionNoVerifiedKeyOpenedHasNoSeat(): void
    {
        [$c, $root] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $sessions->start('anon', 'goal', by: new Principal('cli:someone@host', false));
        $sessions->recordToolCall('anon', 'make', ['what' => 'plugin', 'plugin' => 'Blog'], 'refused', false, true);

        self::assertNull($this->frontier($c, $root)->seatOf('anon'));
        self::assertSame([], $this->frontier($c, $root)->openRefusals('anon'));
    }

    // --- helpers ---

    /**
     * A house where the human's key enrolled the seat (without plugins.Blog:write) and a passkey, a stranger's
     * key enrolled another passkey, and the seat's session recorded one refused `make` — twice.
     *
     * @return array{0: DIContainer, 1: string, 2: int}
     */
    private function house(string $goal = 'Build the blog'): array
    {
        $root = sys_get_temp_dir() . '/milpa-grant-op-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/storage/identity', 0o777, true);
        $this->dirs[] = $root;
        file_put_contents($root . '/config/identity.php', "<?php return ['rooted' => ['" . self::SEAT . "', '" . self::PASSKEY . "', '" . self::STRANGER_PASSKEY . "']];");
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $ledger->record(new IdentityEnrolled(self::SEAT, self::SEAT_SCOPES, 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::PASSKEY, ['milpa.admin', 'agent:read', 'identity:enroll'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::STRANGER_PASSKEY, ['milpa.admin', 'agent:read', 'identity:enroll'], 'key:' . self::STRANGER));

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
        $sessions = new SessionStore($events);
        $c->registerService(SessionStore::class, $sessions);

        $sessions->start(self::SESSION, $goal, by: new Principal('key:' . self::SEAT, true));
        $refused = "Missing required permission 'plugins.Blog:write' for plugin 'Blog'.";
        $seq = $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'BlogPlugin'], $refused, false, true);
        $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], $refused, false, true);

        return [$c, $root, $seq];
    }

    /**
     * evidence/1036 R3, the call as it was recorded: the resident asked to reset the demo plugin it was never told
     * to touch. The card must say what granting opens — write over EXISTING work the task does not name — and it
     * is never one touch (decisions/0510).
     */
    public function testAGrantOverAnExistingPluginTheTaskDoesNotNameIsAnInformedActNotOneTouch(): void
    {
        [$c, $root, $blog] = $this->house();
        $hello = $this->helloRefused($c, $root);

        $rows = $this->frontier($c, $root)->openRefusals(self::SESSION);
        $byPlugin = array_column($rows, null, 'plugin');

        self::assertSame(['target' => 'existing', 'named' => false, 'consent' => 'informed'], array_intersect_key($byPlugin['HelloPlugin'], ['target' => 1, 'named' => 1, 'consent' => 1]));
        self::assertSame(['plugin' => 'HelloPlugin', 'class' => 'HelloPlugin', 'content' => '', 'mode' => 'reset'], $byPlugin['HelloPlugin']['call'], 'the call is shown as recorded, never interpreted');
        self::assertSame(['target' => 'new', 'named' => true, 'consent' => 'touch'], array_intersect_key($byPlugin['Blog'], ['target' => 1, 'named' => 1, 'consent' => 1]));

        // The plain grant — the one-touch shape of today's button — is refused, and the ledger does not move.
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $hello]);
        $plain = $this->call($c, ['session' => self::SESSION, 'seq' => $hello]);
        self::assertFalse($plain['ok']);
        self::assertStringContainsString('opens write over the existing plugin «HelloPlugin»', (string) $plain['error']);
        self::assertStringContainsString('the task does not name it', (string) $plain['error']);
        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'));

        // The wrong name repeated is not knowing it either.
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $hello, 'existing' => 'Blog']);
        self::assertFalse($this->call($c, ['session' => self::SESSION, 'seq' => $hello, 'existing' => 'Blog'])['ok']);
        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'));

        // A signature over the plain call does not approve the informed one.
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $hello]);
        $unsigned = $this->call($c, ['session' => self::SESSION, 'seq' => $hello, 'existing' => 'HelloPlugin']);
        self::assertFalse($unsigned['ok']);
        self::assertStringContainsString('does not cover', (string) $unsigned['error']);

        // The Blog card still grants in one act, untouched by any of this.
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $blog]);
        $blogGrant = $this->call($c, ['session' => self::SESSION, 'seq' => $blog]);
        self::assertTrue($blogGrant['ok'], (string) ($blogGrant['error'] ?? ''));

        // Knowingly, signed: granted.
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $hello, 'existing' => 'HelloPlugin']);
        $informed = $this->call($c, ['session' => self::SESSION, 'seq' => $hello, 'existing' => 'HelloPlugin']);
        self::assertTrue($informed['ok'], (string) ($informed['error'] ?? ''));
        self::assertSame('plugins.HelloPlugin:write', $informed['granted']);
    }

    public function testAnExistingPluginTheTaskNamesStillNeedsTheInformedAct(): void
    {
        [$c, $root] = $this->house('Fix the greeting of HelloPlugin.');
        $hello = $this->helloRefused($c, $root);

        $row = array_column($this->frontier($c, $root)->openRefusals(self::SESSION), null, 'plugin')['HelloPlugin'];
        self::assertTrue($row['named']);
        self::assertSame('informed', $row['consent'], 'naming a plugin does not make writing over its work one touch');

        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $hello]);
        $plain = $this->call($c, ['session' => self::SESSION, 'seq' => $hello]);
        self::assertFalse($plain['ok']);
        self::assertStringNotContainsString('does not name it', (string) $plain['error']);
    }

    public function testNamingAnExistingPluginOnAGrantThatOpensNoneIsRefused(): void
    {
        [$c, , $blog] = $this->house();
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $blog, 'existing' => 'Blog']);

        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $blog, 'existing' => 'Blog']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('not a plugin this refusal would open existing work in', (string) $r['error']);
    }

    public function testThePasskeyTouchForThePlainGrantDoesNotApproveTheInformedOne(): void
    {
        [$c, $root] = $this->house();
        $hello = $this->helloRefused($c, $root);
        [, $touch] = $this->passkey($c, self::PASSKEY);
        $web = new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']);
        $plain = ['session' => self::SESSION, 'seq' => $hello];
        $informed = $plain + ['existing' => 'HelloPlugin'];

        $swapped = $this->call($c, $informed + ['assertion' => $touch($plain)], $web);
        self::assertFalse($swapped['ok']);
        self::assertStringContainsString('did not approve', (string) $swapped['error']);
    }

    public function testThePasskeyTouchBoundToTheInformedGrantGrantsIt(): void
    {
        [$c, $root] = $this->house();
        $hello = $this->helloRefused($c, $root);
        [, $touch] = $this->passkey($c, self::PASSKEY);
        $informed = ['session' => self::SESSION, 'seq' => $hello, 'existing' => 'HelloPlugin'];

        $r = $this->call($c, $informed + ['assertion' => $touch($informed)], new ToolContext('passkey:' . self::PASSKEY, 'web', ['identity:enroll']));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('passkey:' . self::PASSKEY, $r['authorized_by']);
        self::assertSame('plugins.HelloPlugin:write', $r['granted']);
    }

    /**
     * A card the seat moved on from expires: in evidence/1036 the HelloPlugin card stayed on offer for two hours
     * and fifteen legs the resident never spent on it. Staleness is the seat's work, not the clock.
     */
    public function testARefusalTheSeatMovedOnFromIsNoLongerOfferedNorGranted(): void
    {
        [$c, $root] = $this->house();
        $hello = $this->helloRefused($c, $root);
        $this->raw($c, 'session.run_terminated', ['reason' => 'progress_stalled']);
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);

        // The house's own notice is a fact, not a run: it does not age the card.
        $sessions->recordTurn(self::SESSION, 'user', SeatFrontier::NOTICE_PREFIX . 'passkey:x granted this seat the scope «plugins.Blog:write».');
        self::assertContains('HelloPlugin', array_column($this->frontier($c, $root)->openRefusals(self::SESSION), 'plugin'));

        // A later run begins: still offered while it works (it may yet retry).
        $sessions->recordTurn(self::SESSION, 'user', 'continue');
        $this->raw($c, 'session.model_called', []);
        self::assertContains('HelloPlugin', array_column($this->frontier($c, $root)->openRefusals(self::SESSION), 'plugin'));

        // It ends without asking again: the seat moved on.
        $this->raw($c, 'session.run_terminated', ['reason' => 'output_truncated']);
        self::assertNotContains('HelloPlugin', array_column($this->frontier($c, $root)->openRefusals(self::SESSION), 'plugin'));
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $hello, 'existing' => 'HelloPlugin']);
        $r = $this->call($c, ['session' => self::SESSION, 'seq' => $hello, 'existing' => 'HelloPlugin']);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('not an open refusal', (string) $r['error']);

        // Asking again renews it: the frontier offers the latest refusal of that shape.
        $again = $this->helloRefused($c, $root);
        self::assertSame([$again], array_values(array_map(
            static fn (array $row): int => $row['seq'],
            array_filter($this->frontier($c, $root)->openRefusals(self::SESSION), static fn (array $row): bool => $row['plugin'] === 'HelloPlugin'),
        )));
    }

    public function testARunThatDiedWithoutAnEventEndsAtTheNextTurn(): void
    {
        [$c, $root] = $this->house();
        $this->helloRefused($c, $root);
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $sessions->recordTurn(self::SESSION, 'user', 'continue');
        $this->raw($c, 'session.model_called', []);
        // The process died (evidence/1036: four memory deaths, no event); the next turn closes that run.
        $sessions->recordTurn(self::SESSION, 'user', 'continue');

        self::assertNotContains('HelloPlugin', array_column($this->frontier($c, $root)->openRefusals(self::SESSION), 'plugin'));
    }

    public function testLongArgumentsAreCutWithTheirSizeSaid(): void
    {
        [$c, $root] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        mkdir($root . '/src/Plugins/HelloPlugin', 0o777, true);
        $body = str_repeat('x', 5000);
        $sessions->recordToolCall(self::SESSION, 'implement', ['plugin' => 'HelloPlugin', 'class' => 'HelloPlugin', 'content' => $body, 'edits' => [['find' => 'a', 'replace' => 'b']]], "Missing required permission 'plugins.HelloPlugin:write' for plugin 'HelloPlugin'.", false, true);

        $call = array_column($this->frontier($c, $root)->openRefusals(self::SESSION), null, 'plugin')['HelloPlugin']['call'];

        self::assertSame(str_repeat('x', 120) . '… (5000 bytes)', $call['content']);
        self::assertSame('[{"find":"a","replace":"b"}]', $call['edits']);
    }

    /** Record the refusal of evidence/1036 seq 49 against a house that has the demo plugin; return its seq. */
    private function helloRefused(DIContainer $c, string $root): int
    {
        if (!is_dir($root . '/src/Plugins/HelloPlugin')) {
            mkdir($root . '/src/Plugins/HelloPlugin', 0o777, true);
        }
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);

        return $sessions->recordToolCall(self::SESSION, 'implement', ['plugin' => 'HelloPlugin', 'class' => 'HelloPlugin', 'content' => '', 'mode' => 'reset'], "Missing required permission 'plugins.HelloPlugin:write' for plugin 'HelloPlugin'.", false, true);
    }

    /** @param array<string, mixed> $payload */
    private function raw(DIContainer $c, string $type, array $payload): void
    {
        $events = $c->get(EventStoreInterface::class);
        \assert($events instanceof EventStoreInterface);
        $events->append(new \Milpa\EventStore\Event('agent-session:' . self::SESSION, $type, $payload, $events->nextSeq()));
    }

    private function frontier(DIContainer $c, string $root): SeatFrontier
    {
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);

        return SeatFrontier::forRoot($root, $sessions);
    }

    /** @param array<string, mixed> $arguments */
    private function signed(DIContainer $c, string $fingerprint, array $arguments): void
    {
        $authorization = new OperationAuthorization(
            operation: 'identity:grant',
            arguments: $arguments,
            host: 'lab-host',
            issuedAt: '2026-09-27T00:00:00+00:00',
            nonce: 'n-1',
        );
        // Replaced, not registered: re-registering an instance of the same class is silently kept as the first,
        // and a later step would be judged against an earlier signature.
        $c->{$c->has(GrantedAuthorization::class) ? 'replaceService' : 'registerService'}(GrantedAuthorization::class, new GrantedAuthorization(
            authorization: $authorization,
            signer: new VerifiedSigner($fingerprint, 'Lab <lab@example.invalid>'),
            payload: $authorization->canonical(),
            signature: 'exact-signature-bytes',
        ));
    }

    /**
     * Register the intent proof for one credential and return a function that touches it for a call.
     *
     * @return array{0: PasskeyIntentProof, 1: \Closure(array<string, mixed>): array<string, string>}
     */
    private function passkey(DIContainer $c, string $credentialId): array
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $admission = new PasskeyIntentAdmission($this->authenticator($credentialId, (string) $details['key']), new InMemoryIntentChallengeStore());
        $proof = new PasskeyIntentProof($admission, new RelyingParty(self::RP_ID, 'Milpa', ['https://' . self::RP_ID]));
        $c->registerService(PasskeyIntentProof::class, $proof);
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $touch = static function (array $call) use ($admission, $key, $credentialId, $b64): array {
            $challenge = $admission->challengeFor(new OperationId('identity:grant'), $call, \is_string($call['session'] ?? null) ? $call['session'] : null);
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

    private function operation(DIContainer $c): \Milpa\Command\Operation
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === 'identity:grant') {
                return $op;
            }
        }
        self::fail('identity:grant is not offered');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $c, array $input, ?ToolContext $authority = null): array
    {
        $handler = $this->operation($c)->handler;
        self::assertIsCallable($handler);
        /** @var array<string, mixed> */
        return $handler($input, null, $authority);
    }
}
