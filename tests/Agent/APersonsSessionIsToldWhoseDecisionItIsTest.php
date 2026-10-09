<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\SeatFrontier;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\ResidentSeat;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A PERSON'S SESSION IS TOLD WHOSE DECISION IT IS (greenhouse decisions/0609, path 1, I1 — information, no authority).
 *
 * A person writes in the panel's composer «build a plugin…». Her session runs with her passkey; it is nobody's seat,
 * and the frontier only knows seats (decisions/0493). The house refused the call with the bare sentence — no card,
 * nobody who could grant, not even the key that founded the house — and the model, with no way out, declared a debt of
 * the house (evidence/1175: 1 of 1, live). A seat making the same call is told that it is «a person's decision, not a
 * gap in the house». A person's session was told nothing of the kind.
 *
 * Now it is: that a person opened the session, that in this house a seat builds and not a person's session, that
 * nobody can grant the scope to it, that it is no debt of the house, and which act works today. It is a sentence. It
 * grants nothing and runs nothing.
 *
 * @guards the sentence a person's session reads after a refused authoring call, for a new plugin and for one that
 *         exists; the sentence a SEAT reads, byte for byte (Y3); nothing run and nothing offered for a person (Y5)
 *
 * @refuses a debt of the house declared for want of the sentence; a promise that the house waits, resumes or grants;
 *          the sentence said to a session no person opened
 *
 * @subject-in milpa/app-runtime
 */
final class APersonsSessionIsToldWhoseDecisionItIsTest extends TestCase
{
    private const HUMAN = 'BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666';
    private const SEAT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';
    private const PERSON = 'actor:passkey:QM1LEWEfsoWiMmAbCdEf0123456789';
    private const GOAL = 'Build the blog this house was founded for: a plugin named Blog that serves GET /blog.';

    /** What every session is told today, and still: the policy's own sentence for a plugin the house does not have. */
    private const BARE = "Missing required permission 'plugins.Blog:write' for plugin 'Blog'. No plugin 'Blog' exists in this house yet."
        . ' A new plugin takes exactly the name the task gives it.';

    /** What a SEAT is told after it, as it stood before this rule (evidence/1071, evidence/1113): not one byte of it moves. */
    private const TO_A_SEAT = ' Whoever enrolled this seat can grant «plugins.Blog:write» in the panel (Agent → Decisions). This is a'
        . " person's decision, not a gap in the house: do not declare HOUSE_DEBT for it. The leg ends here and waits for that"
        . ' grant; after it, `continue` runs this same call again.';

    /** What a PERSON's session is told after it. */
    private const TO_A_PERSON = " A person opened this session, with a passkey, and in this house a seat builds, not a person's session: no one"
        . ' can grant «plugins.Blog:write» to it. This is how the house is decided, not a gap in the house: do not declare'
        . " HOUSE_DEBT for it, and do not make this call again. What works today is the person's own act — to seat a resident"
        . ' and grant it «plugins.Blog:write» in the panel (Agent → Decisions) when it asks. Tell the person that.';

    private string $root;

    private SessionStore $sessions;

    private int $ran = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-person-told-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/identity', 0o777, true);
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))
            ->record(new IdentityEnrolled(self::SEAT, ResidentSeat::SCOPES, 'key:' . self::HUMAN));
        $this->sessions = new SessionStore(new InMemoryEventStore());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAPersonsSessionIsToldThatASeatBuildsAndThatItIsNoDebtOfTheHouse(): void
    {
        $this->sessions->start('hers', self::GOAL, by: new Principal(self::PERSON, true));

        $told = $this->refusal('hers', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], self::PERSON);

        self::assertSame(self::BARE . self::TO_A_PERSON, $told);
    }

    public function testItIsToldTheSameOfAPluginThatAlreadyExists(): void
    {
        mkdir($this->root . '/src/Plugins/Blog', 0o777, true);
        file_put_contents($this->root . '/src/Plugins/Blog/BlogPlugin.php', "<?php\n");
        $this->sessions->start('hers', self::GOAL, by: new Principal(self::PERSON, true));

        $told = $this->refusal('hers', ['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Post'], self::PERSON);

        self::assertStringStartsWith("Missing required permission 'plugins.Blog:write' for plugin 'Blog'.", $told);
        self::assertStringEndsWith(self::TO_A_PERSON, $told, 'it is not only the new plugin: she has no such scope and nobody can give it to her session');
    }

    /** Y3 — what a seat reads does not change: the whole refusal, byte for byte, as it was before this rule. */
    public function testWhatASeatIsToldDoesNotChangeByAByte(): void
    {
        $this->sessions->start('seats', self::GOAL, by: new Principal('key:' . self::SEAT, true));

        $told = $this->refusal('seats', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], 'key:' . self::SEAT);

        self::assertSame(self::BARE . self::TO_A_SEAT, $told);
    }

    public function testItPromisesNothingTheHouseWillDo(): void
    {
        foreach (['waits', 'continue', 'resume', 'can grant «plugins.Blog:write» in the panel', 'The leg ends', 'Whoever enrolled'] as $promise) {
            self::assertStringNotContainsString($promise, self::TO_A_PERSON, $promise);
        }
        self::assertStringContainsString('do not declare HOUSE_DEBT for it', self::TO_A_PERSON, 'the same words a seat reads: one way to say it is no debt');
    }

    public function testASessionNoPersonOpenedIsToldNothingOfIt(): void
    {
        // The terminal of someone the house did not verify; a verified key no one enrolled; a verified principal that is
        // neither a key nor a passkey; and a person the house did NOT verify.
        $others = ['cli:rod@host' => false, 'key:' . self::HUMAN => true, 'actor:service:ci' => true, self::PERSON => false];
        foreach ($others as $principal => $verified) {
            $session = 's' . substr(hash('sha256', $principal . (int) $verified), 0, 8);
            $this->sessions->start($session, self::GOAL, by: new Principal($principal, $verified));

            self::assertSame(self::BARE, $this->refusal($session, ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], $principal), $principal);
        }
    }

    public function testAPolicyNoLegSeatedSaysNothingOfIt(): void
    {
        $this->sessions->start('hers', self::GOAL, by: new Principal(self::PERSON, true));

        self::assertSame(self::BARE, $this->refusal('hers', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], self::PERSON, seated: false), 'the sentence belongs to a session');
    }

    /** Y5 — path 1 gives no authority: the call did not run, nothing is offered to anyone, and nobody answers for her session. */
    public function testItGrantsNothingAndRunsNothing(): void
    {
        $this->sessions->start('hers', self::GOAL, by: new Principal(self::PERSON, true));
        $ledger = (string) file_get_contents($this->root . '/storage/identity/enrollments.json');

        $this->refusal('hers', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], self::PERSON);
        $this->sessions->recordToolCall('hers', 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], self::BARE . self::TO_A_PERSON, false, true);

        self::assertSame(0, $this->ran, 'the tool never ran');
        self::assertDirectoryDoesNotExist($this->root . '/src/Plugins/Blog');
        self::assertSame($ledger, (string) file_get_contents($this->root . '/storage/identity/enrollments.json'), 'nobody was enrolled or granted anything');
        $frontier = SeatFrontier::forRoot($this->root, $this->sessions);
        self::assertNull($frontier->seatOf('hers'), 'her session is still nobody\'s seat');
        self::assertNull($frontier->wouldOffer('hers', 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog']), 'and the frontier offers nothing for it');
        self::assertSame([], $frontier->openRefusals('hers'));
        foreach (['key:' . self::HUMAN, 'passkey:QM1LEWEfsoWiMmAbCdEf0123456789', self::PERSON] as $anyone) {
            self::assertFalse($frontier->answersFor($anyone, 'hers'), $anyone . ' cannot decide it');
        }
    }

    /**
     * WHAT HER SESSION WAS REFUSED IS READ FOR HER PANEL, AS DATA (decisions/0609, I2) — a row shaped like a seat's
     * refusal, without a seat: the house judges the recorded call again, it does not read the sentence. It is not a
     * frontier: nothing in it can be granted, and the frontier stays empty for her.
     */
    public function testWhatHerSessionWasRefusedIsReadAsDataAndIsNoFrontier(): void
    {
        $this->sessions->start('hers', self::GOAL, by: new Principal(self::PERSON, true));
        $make = ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'];
        $this->sessions->recordToolCall('hers', 'make', $make, self::BARE . self::TO_A_PERSON, false, true);
        $retry = $this->sessions->recordToolCall('hers', 'make', $make, self::BARE . self::TO_A_PERSON, false, true);
        $this->sessions->recordToolCall('hers', 'read', ['path' => 'README.md'], 'No such file.', false, false);
        $frontier = SeatFrontier::forRoot($this->root, $this->sessions);

        self::assertSame(
            [['seq' => $retry, 'tool' => 'make', 'plugin' => 'Blog', 'permission' => 'plugins.Blog:write', 'call' => $make]],
            $frontier->refusedToAPerson('hers'),
            'one row per call shape, the latest retry — and a call that failed for another reason is not one',
        );
        self::assertSame([], $frontier->openRefusals('hers'), 'it is not a frontier: nothing here is open to a grant');
        self::assertNull($frontier->refusal('hers', $retry));
    }

    public function testOnlyTheRefusalsOfTheLastTurnWhenAskedSo(): void
    {
        $this->sessions->start('hers', self::GOAL, by: new Principal(self::PERSON, true));
        $this->sessions->recordTurn('hers', 'user', 'Build the blog');
        $this->sessions->recordToolCall('hers', 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], self::BARE, false, true);
        $this->sessions->recordTurn('hers', 'assistant', 'I cannot.');
        $this->sessions->recordTurn('hers', 'user', '[house] a notice of the house is nobody\'s turn');
        $frontier = SeatFrontier::forRoot($this->root, $this->sessions);

        self::assertCount(1, $frontier->refusedToAPerson('hers', ofTheLastTurn: true), 'a notice of the house does not start a turn');

        $this->sessions->recordTurn('hers', 'user', 'Then add a page');
        self::assertSame([], $frontier->refusedToAPerson('hers', ofTheLastTurn: true), 'she asked for something else: nothing was refused in THIS turn');
        self::assertCount(1, $frontier->refusedToAPerson('hers'), 'the session still holds it');

        $mine = $this->sessions->recordToolCall('hers', 'make', ['what' => 'entity', 'plugin' => 'Shop', 'name' => 'Item'], 'refused', false, true);
        self::assertSame([$mine], array_column($frontier->refusedToAPerson('hers', ofTheLastTurn: true), 'seq'));
    }

    public function testItIsReadOnlyOfASessionAPersonOpened(): void
    {
        $make = ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'];
        foreach (['seats' => new Principal('key:' . self::SEAT, true), 'anon' => new Principal('cli:rod@host', false), 'unverified' => new Principal(self::PERSON, false)] as $session => $by) {
            $this->sessions->start($session, self::GOAL, by: $by);
            $this->sessions->recordToolCall($session, 'make', $make, self::BARE, false, true);

            self::assertSame([], SeatFrontier::forRoot($this->root, $this->sessions)->refusedToAPerson($session), $session);
        }
        self::assertSame([], SeatFrontier::forRoot($this->root, $this->sessions)->refusedToAPerson('no-such-session'));
    }

    /**
     * The door a leg really calls through (evidence/1077): the call policy is asked before the registry.
     *
     * @param array<string, mixed> $arguments
     */
    private function refusal(string $session, array $arguments, string $principal, bool $seated = true): string
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('make', 'scaffolds', ['type' => 'object'], function (array $args): array {
            ++$this->ran;

            return ['ran' => true];
        });
        $policy = new PluginAuthoringPolicy($this->root);
        $registry->getPolicyGate()->setCallPolicy($seated ? $policy->withSeatSession($this->sessions, $session) : $policy);
        $calls = new GatedToolCalls(new TrialAwareRegistry($registry, new TrialRouter($this->root, new TrialRunner(), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php'), [], $this->sessions, $session));
        $calls->setContext(new ToolContext($principal, 'web', ResidentSeat::SCOPES));
        try {
            $calls->callTool('make', $arguments);
        } catch (\Exception $refused) {
            return $refused->getMessage();
        }
        self::fail('nobody here holds plugins.' . ($arguments['plugin'] ?? '?') . ':write: the call must be refused');
    }
}
