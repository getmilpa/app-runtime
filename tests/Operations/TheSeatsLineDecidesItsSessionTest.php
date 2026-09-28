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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * The seat's line decides the rest of its session too (greenhouse decisions/0497).
 *
 * The panel mounts its own doors to `agent:goal` and to the turn (`agent`), and `agent:mode` has an HTTP surface a
 * host may expose. All three take `agent:run`, which every driving passkey holds — so the scope alone would let a
 * passkey another key enrolled change the seat's goal, raise its mode, or send it the next turn. These pin the
 * judgment of decisions/0495 on the three, the cases it leaves as they were, and the scope `agent:model` gained.
 */
final class TheSeatsLineDecidesItsSessionTest extends TestCase
{
    private const HUMAN = 'C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';
    private const STRANGER = 'D00D0000111122223333444455556666777788889';
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';
    private const PASSKEY = 'QM1LEWEfsoWiMm';
    private const STRANGER_PASSKEY = 'ZZ9otherCredential';
    private const SESSION = 'camino-blog';
    private const GOAL = 'Build the blog';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testThePasskeyOfTheSeatsLineSetsItsGoal(): void
    {
        [$c, $sessions] = $this->house();

        $r = $this->session($c, 'agent:goal', ['session' => self::SESSION, 'goal' => 'Serve the blog at /blog'], $this->web(self::PASSKEY));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('Serve the blog at /blog', $sessions->load(self::SESSION)?->goal);
    }

    public function testAPasskeyAnotherKeyEnrolledNeitherSetsNorReadsTheSeatsGoal(): void
    {
        [$c, $sessions] = $this->house();

        $set = $this->session($c, 'agent:goal', ['session' => self::SESSION, 'goal' => 'Delete everything'], $this->web(self::STRANGER_PASSKEY));
        $read = $this->session($c, 'agent:goal', ['session' => self::SESSION], $this->web(self::STRANGER_PASSKEY));

        self::assertFalse($set['ok']);
        self::assertStringContainsString('you do not answer for session', (string) $set['error']);
        self::assertStringContainsString('nothing was changed', (string) $set['error']);
        self::assertSame(self::GOAL, $sessions->load(self::SESSION)?->goal, 'the goal stands');
        self::assertFalse($read['ok'], 'reading the standing goal is deciding on it too');
        self::assertArrayNotHasKey('goal', $read);
    }

    public function testThePasskeyOfTheSeatsLineChangesItsMode(): void
    {
        [$c, $sessions] = $this->house();

        $r = $this->session($c, 'agent:mode', ['session' => self::SESSION, 'mode' => 'ask'], $this->web(self::PASSKEY));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(AutonomyMode::Ask, $sessions->load(self::SESSION)?->mode);
    }

    public function testAPasskeyAnotherKeyEnrolledDoesNotChangeTheSeatsMode(): void
    {
        [$c, $sessions] = $this->house();
        $sessions->setMode(self::SESSION, AutonomyMode::Ask);

        $r = $this->session($c, 'agent:mode', ['session' => self::SESSION, 'mode' => 'auto'], $this->web(self::STRANGER_PASSKEY));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('you do not answer for session', (string) $r['error']);
        self::assertSame(AutonomyMode::Ask, $sessions->load(self::SESSION)?->mode, 'the mode stands');
    }

    public function testAPasskeyAnotherKeyEnrolledDoesNotSendTheSeatsSessionATurn(): void
    {
        [$c, $sessions] = $this->house();
        $before = \count($sessions->stream(self::SESSION));

        $r = $this->turn($c, ['prompt' => 'continue', 'session' => self::SESSION, 'mode' => 'auto'], $this->web(self::STRANGER_PASSKEY));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('you do not answer for session', (string) $r['error']);
        self::assertStringContainsString('nothing was run', (string) $r['error']);
        self::assertCount($before, $sessions->stream(self::SESSION), 'not one event was written');
    }

    /**
     * The owner's line passes the judgment: the turn goes on to the next refusal in its path — an unknown effect
     * class, chosen because it is refused before any mode is written or any provider called.
     */
    public function testTheSeatsLineGetsPastTheJudgmentOnATurn(): void
    {
        [$c] = $this->house();

        $r = $this->turn($c, ['prompt' => 'continue', 'session' => self::SESSION, 'denyEffects' => 'no-such-class'], $this->web(self::PASSKEY));

        self::assertFalse($r['ok']);
        self::assertStringNotContainsString('you do not answer for session', (string) ($r['error'] ?? ''));
    }

    public function testATurnThatOpensANewSessionHasNobodyToAsk(): void
    {
        [$c] = $this->house();

        $r = $this->turn($c, ['prompt' => 'hello', 'session' => 'brand-new', 'denyEffects' => 'no-such-class'], $this->web(self::STRANGER_PASSKEY));

        self::assertStringNotContainsString('you do not answer for session', (string) ($r['error'] ?? ''));
    }

    public function testTheTerminalStaysTheHonestUnverifiedCase(): void
    {
        [$c, $sessions] = $this->house();

        $r = $this->session($c, 'agent:goal', ['session' => self::SESSION, 'goal' => 'From the terminal'], InvocationContext::cli());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('From the terminal', $sessions->load(self::SESSION)?->goal);
    }

    public function testASessionNobodyVerifiedOpenedKeepsTheRuleItHad(): void
    {
        [$c, $sessions] = $this->house();
        $sessions->start('anon', 'goal', by: new Principal('cli:someone@host', false));

        $r = $this->session($c, 'agent:mode', ['session' => 'anon', 'mode' => 'auto'], $this->web(self::STRANGER_PASSKEY));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
    }

    public function testWhoeverReadsTheAgentMayAskWhichModelItTalksTo(): void
    {
        foreach ((new AgentOperations(new DIContainer()))->operations() as $op) {
            if ($op->name === 'agent:model') {
                self::assertSame(['agent:read'], $op->scopes, 'a scope, so the HTTP policy is consulted at all');

                return;
            }
        }
        self::fail('agent:model is not offered');
    }

    // --- helpers ---

    /** @return array{0: DIContainer, 1: SessionStore} */
    private function house(): array
    {
        $root = sys_get_temp_dir() . '/milpa-seat-session-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        $this->dirs[] = $root;
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $ledger->record(new IdentityEnrolled(self::SEAT, ['agent:run'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::PASSKEY, ['agent:run'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::STRANGER_PASSKEY, ['agent:run'], 'key:' . self::STRANGER));

        $c = new DIContainer();
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        $events = new InMemoryEventStore();
        $c->registerService(EventStoreInterface::class, $events);
        $sessions = new SessionStore($events);
        $c->registerService(SessionStore::class, $sessions);

        $sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));
        $sessions->setMode(self::SESSION, AutonomyMode::Auto);

        return [$c, $sessions];
    }

    /** The context the HTTP projector builds for a signed-in passkey: attributed as `actor:<id>`. */
    private function web(string $credential): InvocationContext
    {
        return InvocationContext::web('actor:passkey:' . $credential, 'agent:run');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function session(DIContainer $c, string $name, array $input, InvocationContext $ctx): array
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === $name) {
                $handler = $op->handler;
                self::assertIsCallable($handler);
                /** @var array<string, mixed> */
                return $handler($input, $ctx);
            }
        }
        self::fail($name . ' is not offered');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function turn(DIContainer $c, array $input, InvocationContext $ctx): array
    {
        foreach ((new AgentOperations($c))->operations() as $op) {
            if ($op->name === 'agent') {
                $handler = $op->handler;
                self::assertIsCallable($handler);
                $authority = new ToolContext(principal: (string) $ctx->actor, channel: 'web', scopes: ['agent:run']);
                /** @var array<string, mixed> */
                return $handler($input, $ctx, $authority);
            }
        }
        self::markTestSkipped('this build offers no agent turn (milpa/ai-gateway is not installed)');
    }
}
