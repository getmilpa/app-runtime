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

use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Who may decide a seat's parked question over a channel that promises identity (greenhouse decisions/0495).
 *
 * The panel now mounts its own door to `agent:answer`. Every passkey an app enrolls may carry `agent:answer`,
 * so the scope alone would let a passkey another key enrolled answer a seat it does not answer for — the
 * same line `identity:grant` already holds (decisions/0493). These pin the line on answer and discard, and
 * the two cases it leaves as they were: the terminal, and a session nobody verified opened.
 */
final class TheSeatsLineAnswersItsQuestionTest extends TestCase
{
    private const HUMAN = 'C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';
    private const STRANGER = 'D00D0000111122223333444455556666777788889';
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';
    private const PASSKEY = 'QM1LEWEfsoWiMm';
    private const STRANGER_PASSKEY = 'ZZ9otherCredential';
    private const SESSION = 'camino-blog';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testThePasskeyOfTheSeatsLineAnswersItsQuestionFromTheWeb(): void
    {
        [$c, $sessions] = $this->house();

        $r = $this->invoke($c, 'agent:answer', ['session' => self::SESSION, 'answer' => 'no'], $this->web(self::PASSKEY));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertNull($sessions->load(self::SESSION)?->question, 'the question is answered');
    }

    public function testAPasskeyAnotherKeyEnrolledDoesNotAnswerTheSeatsQuestion(): void
    {
        [$c, $sessions] = $this->house();

        $r = $this->invoke($c, 'agent:answer', ['session' => self::SESSION, 'answer' => 'sí'], $this->web(self::STRANGER_PASSKEY));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('you do not answer for session', (string) $r['error']);
        self::assertNotNull($sessions->load(self::SESSION)?->question, 'the question is still waiting');
    }

    public function testAPasskeyAnotherKeyEnrolledDoesNotDiscardTheSeatsSession(): void
    {
        [$c, $sessions] = $this->house();

        $r = $this->invoke($c, 'agent:discard', ['session' => self::SESSION, 'because' => 'mine now'], $this->web(self::STRANGER_PASSKEY));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('nothing was discarded', (string) $r['error']);
        self::assertNotNull($sessions->load(self::SESSION)?->question, 'the session still waits');
    }

    public function testTheTerminalStaysTheHonestUnverifiedCase(): void
    {
        [$c] = $this->house();

        $r = $this->invoke($c, 'agent:answer', ['session' => self::SESSION, 'answer' => 'no'], InvocationContext::cli());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
    }

    public function testThePrincipalThatOpenedASessionAnswersIt(): void
    {
        [$c, $sessions] = $this->house();
        $sessions->start('mine', 'goal', by: new Principal('actor:passkey:' . self::STRANGER_PASSKEY, true));
        $sessions->ask('mine', new PendingQuestion('intent:x', 'Which one?'));

        $r = $this->invoke($c, 'agent:answer', ['session' => 'mine', 'answer' => 'no'], $this->web(self::STRANGER_PASSKEY));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
    }

    public function testASessionNobodyVerifiedOpenedKeepsTheRuleItHad(): void
    {
        [$c, $sessions] = $this->house();
        $sessions->start('anon', 'goal', by: new Principal('cli:someone@host', false));
        $sessions->ask('anon', new PendingQuestion('intent:x', 'Which one?'));

        $r = $this->invoke($c, 'agent:answer', ['session' => 'anon', 'answer' => 'no'], $this->web(self::STRANGER_PASSKEY));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
    }

    public function testWithoutALedgerOnlyTheOpenerDecides(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $c = new DIContainer();
        $c->registerService(SessionStore::class, $sessions);
        $sessions->start(self::SESSION, 'goal', by: new Principal('key:' . self::SEAT, true));
        $sessions->ask(self::SESSION, new PendingQuestion('intent:x', 'Which one?'));

        $r = $this->invoke($c, 'agent:answer', ['session' => self::SESSION, 'answer' => 'no'], $this->web(self::PASSKEY));

        self::assertFalse($r['ok']);
    }

    // --- helpers ---

    /** @return array{0: DIContainer, 1: SessionStore} */
    private function house(): array
    {
        $root = sys_get_temp_dir() . '/milpa-seat-answer-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        $this->dirs[] = $root;
        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $ledger->record(new IdentityEnrolled(self::SEAT, ['agent:run'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::PASSKEY, ['agent:answer'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::STRANGER_PASSKEY, ['agent:answer'], 'key:' . self::STRANGER));

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

        $sessions->start(self::SESSION, 'Build the blog', by: new Principal('key:' . self::SEAT, true));
        $sessions->ask(self::SESSION, new PendingQuestion('intent:target_not_named', 'The request does not name «BlogPlugin». Register it?'));

        return [$c, $sessions];
    }

    /** The context the HTTP projector builds for a signed-in passkey: attributed as `actor:<id>`. */
    private function web(string $credential): InvocationContext
    {
        return InvocationContext::web('actor:passkey:' . $credential, 'agent:answer');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function invoke(DIContainer $c, string $name, array $input, InvocationContext $ctx): array
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
}
