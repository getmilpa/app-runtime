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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\SeatFrontier;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
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
 * A refusal the panel can grant says so, and is never a house debt (greenhouse decisions/0543, evidence/1071 B8).
 *
 * In Rod's first live run the resident's `make plugin name=Blog` was refused for `plugins.Blog:write`. The
 * refusal never said a person could grant it, the stall notice offered `HOUSE_DEBT` «if the blocker is
 * framework-owned», and the resident declared the scope a chicken-and-egg gap of the scaffolder: the ledger got
 * a false `framework_gap`. The frontier ({@see SeatFrontier}) is the one judge of what the panel offers; the
 * refusal, the notice and the debt all ask it.
 */
final class AGrantableRefusalSaysWhoGrantsItTest extends TestCase
{
    private const HUMAN = 'C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';
    private const SESSION = 'camino-rod-blog';
    private const GOAL = 'Build the blog this house was founded for: a plugin named Blog that serves GET /blog.';

    private string $root;
    private SessionStore $sessions;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-grantable-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/identity', 0o777, true);
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))
            ->record(new IdentityEnrolled(self::SEAT, ResidentSeat::SCOPES, 'key:' . self::HUMAN));
        $this->sessions = new SessionStore(new InMemoryEventStore());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheFrontierSaysWhetherItWouldOfferACallBeforeItIsRecorded(): void
    {
        $this->sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));
        $frontier = SeatFrontier::forRoot($this->root, $this->sessions);

        self::assertSame('plugins.Blog:write', $frontier->wouldOffer(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'])['permission'] ?? null);
        self::assertNull($frontier->wouldOffer(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'BlogPlugin', 'name' => 'BlogPlugin']), 'an invented name is never offered');
        self::assertNull($frontier->wouldOffer('nobody', 'make', ['what' => 'plugin', 'plugin' => 'Blog']), 'a session with no seat has no frontier');
        self::assertSame([], $frontier->openRefusals(self::SESSION), 'asking records nothing');
    }

    public function testTheRefusalOfAGrantableScopeSaysWhoGrantsItAndThatItIsNotADebt(): void
    {
        $this->sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));

        $error = $this->refusalThroughTheGate(self::SESSION, ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog']);

        self::assertStringStartsWith("Missing required permission 'plugins.Blog:write' for plugin 'Blog'.", $error, 'the refusal stays the policy\'s sentence');
        self::assertStringContainsString('Whoever enrolled this seat can grant «plugins.Blog:write» in the panel (Agent → Decisions)', $error);
        self::assertStringContainsString('not a gap in the house: do not declare HOUSE_DEBT for it', $error);
        self::assertStringContainsString('saying you are waiting for that grant', $error);
    }

    public function testARefusalThePanelWouldNotOfferSaysNothingAboutThePanel(): void
    {
        $this->sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));
        $this->sessions->start('terminal', self::GOAL, by: new Principal('cli:rod', false));

        $invented = $this->refusalThroughTheGate(self::SESSION, ['what' => 'plugin', 'plugin' => 'BlogPlugin', 'name' => 'BlogPlugin']);
        $unseated = $this->refusalThroughTheGate('terminal', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog']);

        self::assertStringContainsString("Missing required permission 'plugins.BlogPlugin:write'", $invented);
        self::assertStringNotContainsString('panel', $invented, 'a name nobody asked for is not sent to a person');
        self::assertStringContainsString("Missing required permission 'plugins.Blog:write'", $unseated);
        self::assertStringNotContainsString('panel', $unseated, 'nobody enrolled the terminal, so nobody grants it there');
    }

    public function testThePolicyTheHouseInstallsSaysNothingUntilALegSeatsIt(): void
    {
        $this->sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));

        $plain = $this->refusalThroughTheGate(self::SESSION, ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], seated: false);

        self::assertStringContainsString("Missing required permission 'plugins.Blog:write'", $plain);
        self::assertStringNotContainsString('panel', $plain, 'the sentence belongs to a session; the shared policy has none');
    }

    public function testALegSeatsThePolicyForItsSessionAndPutsTheHousesBack(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $house = new PluginAuthoringPolicy($this->root);
        $registry->getPolicyGate()->setCallPolicy($house);
        $seat = new \ReflectionMethod(\Milpa\AppRuntime\Operations\AgentOperations::class, 'seatThePolicy');
        $operations = new \Milpa\AppRuntime\Operations\AgentOperations(new \Milpa\Container\DIContainer());

        $restore = $seat->invoke($operations, $registry, $this->sessions, self::SESSION);
        $during = $registry->getPolicyGate()->getCallPolicy();
        $restore();

        self::assertInstanceOf(PluginAuthoringPolicy::class, $during, 'the same class: the trial executor confines by it');
        self::assertNotSame($house, $during, 'the leg answers with a copy that knows its session');
        self::assertSame($house, $registry->getPolicyGate()->getCallPolicy(), 'and the house gets its own policy back');
        $seat->invoke($operations, $registry, $this->sessions, '')();
        self::assertSame($house, $registry->getPolicyGate()->getCallPolicy(), 'no session, nothing is swapped');
    }

    public function testTheStallNoticeTakesTheDebtOffTheTableForAGrantableScope(): void
    {
        $events = new InMemoryEventStore();
        $probe = new SessionProgressProbe($events, self::SESSION, null, static fn (): array => ['plugins.Blog:write']);
        $plain = new SessionProgressProbe($events, self::SESSION);

        $notice = $this->notice($probe);

        self::assertStringContainsString('«plugins.Blog:write» is a scope, not a framework gap', $notice);
        self::assertStringContainsString('grants it in the panel (Agent → Decisions)', $notice);
        self::assertStringContainsString('Do not answer HOUSE_DEBT for it', $notice);
        self::assertStringNotContainsString('is a scope, not a framework gap', $this->notice($plain), 'no grantable refusal, the notice is as it was');
    }

    private function notice(SessionProgressProbe $probe): string
    {
        $receipt = new \Milpa\Agent\ProgressReceipt(1, 5, 5, 0, 0, 0, 0, 0, 'stalled');
        $method = new \ReflectionMethod(SessionProgressProbe::class, 'notice');

        return (string) $method->invoke($probe, $receipt);
    }

    private function seat(): ToolContext
    {
        return new ToolContext('key:' . self::SEAT, 'cli', ResidentSeat::SCOPES);
    }

    /**
     * The door a leg really calls through (evidence/1077): tool-runtime's GatedToolCalls asks the call policy BEFORE the
     * registry, so a sentence added anywhere after it never reaches the model — as measured on the first overlay leg.
     *
     * @param array<string, mixed> $arguments
     */
    private function refusalThroughTheGate(string $session, array $arguments, bool $seated = true): string
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('make', 'scaffolds', ['type' => 'object'], static fn (array $args): array => ['ran' => true]);
        $policy = new PluginAuthoringPolicy($this->root);
        $registry->getPolicyGate()->setCallPolicy($seated ? $policy->withSeatSession($this->sessions, $session) : $policy);
        $calls = new GatedToolCalls(new TrialAwareRegistry($registry, new TrialRouter($this->root, new TrialRunner(), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php'), [], $this->sessions, $session));
        $calls->setContext($this->seat());
        try {
            $calls->callTool('make', $arguments);
        } catch (\Exception $refused) {
            return $refused->getMessage();
        }
        self::fail('the seat lacks plugins.' . ($arguments['plugin'] ?? '?') . ':write, the call must be refused');
    }
}
