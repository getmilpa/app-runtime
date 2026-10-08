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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\OpenedWorks;
use Milpa\AppRuntime\Agent\GrantedCall;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\SeatFrontier;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Tests\Agent\BuiltHouse;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * Two acts of a person for every change to an admitted capability (greenhouse decisions/0590, rule 10).
 *
 * The seat that built «Prestamos» holds its building permit. A person admits one of its scopes — and that closes
 * the permit, said in what the operation answers and in what the seat's session reads. The seat's next authoring
 * call is refused and offered as the grant a person already gives knowingly over an existing plugin, now saying what
 * it suspends. Granted, the capability is in works again; the next admission closes it.
 */
final class ReopeningTheWorksOfAnAdmittedCapabilityTest extends TestCase
{
    use BuiltHouse;

    private const SESSION = 'taller';
    private const PERMIT = 'plugins.Prestamos:write';
    private const EDIT = ['plugin' => 'Prestamos', 'class' => 'Prestamos', 'method' => 'operations', 'body' => 'return [];'];

    public function testAdmittingFromARefusalClosesThePermitAndSaysSo(): void
    {
        [$c, $root, , $write] = $this->house();
        $digest = (string) $this->card($c, $write)['contract'];
        $this->signed($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $write, 'admits' => $digest]);

        $r = $this->call($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $write, 'admits' => $digest]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame([self::SEAT, self::OTHER_SEAT], $r['closed'], 'the seats whose building permit it closed');
        self::assertNotContains(self::PERMIT, $r['scopes']);
        self::assertSame(self::SEAT_SCOPES, $this->ledger($root)->scopesFor(self::OTHER_SEAT));
        // The seat's session reads it: it built the capability, and it can no longer write it.
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $told = '';
        foreach ($sessions->stream(self::SESSION) as $event) {
            if ($event->type === 'session.turn' && ($event->payload['role'] ?? null) === 'user') {
                $told = (string) ($event->payload['content'] ?? '');
            }
        }
        self::assertStringContainsString('closed this seat\'s building permit of «Prestamos»', $told);
    }

    public function testAdmittingWithNoRefusalClosesItTooAndAnswersTheSeatsScopesAsTheyAre(): void
    {
        [$c, $root] = $this->house();
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $digest = (string) (CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', 'herramientas:read')['contract'] ?? '');
        $this->signed($c, 'identity:admit', ['seat' => self::OTHER_SEAT, 'admits' => $digest]);

        $r = $this->call($c, 'identity:admit', ['seat' => self::OTHER_SEAT, 'admits' => $digest]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame([self::SEAT, self::OTHER_SEAT], $r['closed']);
        self::assertSame(self::SEAT_SCOPES, $r['scopes'], 'read after the write: the admission took a word from them');
    }

    /** A house where nobody holds the permit: an admission closes nothing, and says nothing was closed. */
    public function testWhereNobodyHoldsThePermitNothingIsClosed(): void
    {
        [$c, $root, , $write] = $this->house(permit: false);
        $digest = (string) $this->card($c, $write)['contract'];
        $this->signed($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $write, 'admits' => $digest]);

        $r = $this->call($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $write, 'admits' => $digest]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame([], $r['closed']);
        self::assertSame([], $this->ledger($root)->closuresFor(self::SEAT));
    }

    public function testTheSeatsNextAuthoringCallIsOfferedAsTheGrantThatSuspendsWhatWasAdmitted(): void
    {
        [$c, , $read, $write] = $this->house();
        $this->admitFrom($c, $write);
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $edit = $sessions->recordToolCall(self::SESSION, 'edit', self::EDIT, "Missing required permission 'plugins.Prestamos:write' for plugin 'Prestamos'.", false, false);

        $row = $this->card($c, $edit);

        self::assertSame(self::PERMIT, $row['permission']);
        self::assertSame('informed', $row['consent']);
        self::assertSame([['seat' => self::SEAT, 'scopes' => ['herramientas:write']]], $row['suspends'], 'what stops running while the works are open');
        // A scope nobody admitted waits as it did, and says nothing is in works: the permit is closed.
        self::assertNull($this->card($c, $read)['works']);

        // The plain grant grants nothing, and says what else it does.
        $this->signed($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $edit]);
        $plain = $this->call($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $edit]);
        self::assertFalse($plain['ok']);
        self::assertStringContainsString('opens write over the existing plugin «Prestamos»', (string) $plain['error']);
        self::assertStringContainsString('«Prestamos» is admitted to 1 seat: what was admitted is suspended while that permit stands', (string) $plain['error']);
    }

    public function testGrantedKnowinglyTheCapabilityIsInWorksAndTheNextAdmissionClosesIt(): void
    {
        [$c, $root, $read, $write] = $this->house();
        $this->admitFrom($c, $write);
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $edit = $sessions->recordToolCall(self::SESSION, 'edit', self::EDIT, "Missing required permission 'plugins.Prestamos:write' for plugin 'Prestamos'.", false, false);
        $grant = ['session' => self::SESSION, 'seq' => $edit, 'existing' => 'Prestamos'];
        $this->signed($c, 'identity:grant', $grant);

        $opened = $this->call($c, 'identity:grant', $grant);

        self::assertTrue($opened['ok'], (string) ($opened['error'] ?? ''));
        self::assertSame(self::PERMIT, $opened['granted']);
        self::assertSame([['seat' => self::SEAT, 'scopes' => ['herramientas:write']]], $opened['suspended']);
        self::assertSame([self::SEAT], $this->ledger($root)->permitHolders('Prestamos'));
        self::assertCount(1, $this->ledger($root)->admissionsFor(self::SEAT)['Prestamos'], 'suspended, not dropped');
        // In works: the card of a scope that waits says who holds the permit, so the person knows what admitting does.
        self::assertSame(['holders' => [self::SEAT]], $this->card($c, $read)['works']);

        // The second act.
        $this->admitFrom($c, $read);

        self::assertSame([], $this->ledger($root)->permitHolders('Prestamos'));
        self::assertCount(2, $this->ledger($root)->closuresFor(self::SEAT), 'closed twice, and both are said');
    }

    /**
     * HELD — greenhouse decisions/0602 is NOT decided. Built for the candidate train only.
     *
     * The grant that opens the works leaves, on the fact the session keeps, what it opened: which plugin, for which
     * seat, and how many times that seat's permit had been closed. That is all {@see OpenedWorks} reads later.
     */
    public function testTheGrantThatOpensTheWorksLeavesOnItsFactWhatItOpened(): void
    {
        [$c, $root, $read, $write] = $this->house();
        $this->admitFrom($c, $write);
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $edit = $sessions->recordToolCall(self::SESSION, 'edit', self::EDIT, "Missing required permission 'plugins.Prestamos:write' for plugin 'Prestamos'.", false, false);
        self::assertFalse(OpenedWorks::standFor($sessions->stream(self::SESSION), 'Prestamos', $root), 'nobody opened anything yet');
        $grant = ['session' => self::SESSION, 'seq' => $edit, 'existing' => 'Prestamos'];
        $this->signed($c, 'identity:grant', $grant);

        self::assertTrue($this->call($c, 'identity:grant', $grant)['ok']);

        $fact = null;
        foreach ($sessions->stream(self::SESSION) as $event) {
            $fact = $event->type === GrantedCall::GRANTED && ($event->payload['seq'] ?? null) === $edit ? $event->payload : $fact;
        }
        self::assertNotNull($fact);
        self::assertSame('Prestamos', $fact['existing'] ?? null);
        self::assertSame(self::SEAT, $fact['seat'] ?? null);
        self::assertSame(1, $fact['closures'] ?? null, 'the admission before it had closed this seat\'s permit once');
        self::assertTrue(OpenedWorks::standFor($sessions->stream(self::SESSION), 'Prestamos', $root));

        // The second act closes them: what the grant opened no longer stands.
        $this->admitFrom($c, $read);
        self::assertFalse(OpenedWorks::standFor($sessions->stream(self::SESSION), 'Prestamos', $root));
    }

    /** Part of the rule, not a detail of it (decisions/0602): what will no longer be asked is said BEFORE the act. */
    public function testThePersonIsToldBeforeGrantingWhatWillNoLongerBeAsked(): void
    {
        [$c, , , $write] = $this->house();
        $this->admitFrom($c, $write);
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $edit = $sessions->recordToolCall(self::SESSION, 'edit', self::EDIT, "Missing required permission 'plugins.Prestamos:write' for plugin 'Prestamos'.", false, false);
        $this->signed($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $edit]);

        $plain = $this->call($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $edit]);

        self::assertFalse($plain['ok']);
        self::assertStringContainsString(
            'In this session the house will then write inside «Prestamos» without asking you again about each piece; approve it knowingly with existing=Prestamos; nothing was granted',
            (string) $plain['error'],
        );
    }

    /** A grant over a plugin that does not exist yet is one touch, and opens no existing work: its fact says none. */
    public function testAOneTouchGrantLeavesNoWorksOnItsFact(): void
    {
        [$c, $root] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        // The session's errand names the workshop: a new plugin the task names is offered as one touch (decisions/0510).
        $make = $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'Taller', 'name' => 'Taller'], "Missing required permission 'plugins.Taller:write' for plugin 'Taller'.", false, false);
        self::assertSame('touch', $this->card($c, $make)['consent']);
        $this->signed($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $make]);

        self::assertTrue($this->call($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $make])['ok']);

        foreach ($sessions->stream(self::SESSION) as $event) {
            if ($event->type === GrantedCall::GRANTED) {
                self::assertArrayNotHasKey('existing', $event->payload);
                self::assertArrayNotHasKey('closures', $event->payload);
            }
        }
        self::assertContains('plugins.Taller:write', $this->ledger($root)->scopesFor(self::SEAT) ?? [], 'the permit was granted');
        self::assertFalse(OpenedWorks::standFor($sessions->stream(self::SESSION), 'Taller', $root));
    }

    /** An authoring grant over a plugin nobody was admitted anything of suspends nothing, and says nothing of it. */
    public function testAGrantOverACapabilityNobodyWasAdmittedSuspendsNothing(): void
    {
        [$c] = $this->house(permit: false);
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $edit = $sessions->recordToolCall(self::SESSION, 'edit', self::EDIT, "Missing required permission 'plugins.Prestamos:write' for plugin 'Prestamos'.", false, false);

        self::assertSame([], $this->card($c, $edit)['suspends']);
        $this->signed($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $edit]);
        $plain = $this->call($c, 'identity:grant', ['session' => self::SESSION, 'seq' => $edit]);
        self::assertStringNotContainsString('is admitted to', (string) $plain['error']);
        $grant = ['session' => self::SESSION, 'seq' => $edit, 'existing' => 'Prestamos'];
        $this->signed($c, 'identity:grant', $grant);
        self::assertSame([], $this->call($c, 'identity:grant', $grant)['suspended']);
    }

    /**
     * The course's capability, a session of the seat that built it with two refused calls, and — unless told
     * otherwise — both seats still holding its building permit.
     *
     * @return array{0: DIContainer, 1: string, 2: int, 3: int}
     */
    private function house(bool $permit = true): array
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        if ($permit) {
            foreach ([self::SEAT, self::OTHER_SEAT] as $seat) {
                $this->ledger($root)->record(new IdentityEnrolled($seat, [...self::SEAT_SCOPES, self::PERMIT], 'key:' . self::HUMAN));
            }
        }
        $c = new DIContainer();
        $c->registerService(Kernel::class, $this->kernel($root, [$plugin]));
        $events = new InMemoryEventStore();
        $c->registerService(EventStoreInterface::class, $events);
        $sessions = new SessionStore($events);
        $c->registerService(SessionStore::class, $sessions);
        $sessions->start(self::SESSION, 'Registra un taladro en el taller y préstalo.', by: new Principal('key:' . self::SEAT, true));
        $read = $sessions->recordToolCall(self::SESSION, 'herramientas_listar', [], 'refused', false, false);
        $write = $sessions->recordToolCall(self::SESSION, 'herramientas_agregar', ['nombre' => 'Taladro'], 'refused', false, false);

        return [$c, $root, $read, $write];
    }

    /** @return array<string, mixed> the open refusal at that position, as a person is shown it */
    private function card(DIContainer $c, int $seq): array
    {
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $row = SeatFrontier::forRoot($kernel->root(), $sessions, BuiltCapabilities::of($kernel))->refusal(self::SESSION, $seq);
        self::assertNotNull($row, "#{$seq} is not an open refusal");

        return $row;
    }

    private function admitFrom(DIContainer $c, int $seq): void
    {
        $call = ['session' => self::SESSION, 'seq' => $seq, 'admits' => (string) $this->card($c, $seq)['contract']];
        $this->signed($c, 'identity:grant', $call);
        $r = $this->call($c, 'identity:grant', $call);
        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
    }

    /** @param array<string, mixed> $arguments */
    private function signed(DIContainer $c, string $operation, array $arguments): void
    {
        $authorization = new OperationAuthorization(operation: $operation, arguments: $arguments, host: 'lab-host', issuedAt: '2026-10-08T00:00:00+00:00', nonce: 'n-1');
        $c->{$c->has(GrantedAuthorization::class) ? 'replaceService' : 'registerService'}(GrantedAuthorization::class, new GrantedAuthorization(
            authorization: $authorization,
            signer: new VerifiedSigner(self::HUMAN, 'Lab <lab@example.invalid>'),
            payload: $authorization->canonical(),
            signature: 'exact-signature-bytes',
        ));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $c, string $operation, array $input): array
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === $operation) {
                $handler = $op->handler;
                self::assertIsCallable($handler);

                /** @var array<string, mixed> */
                return $handler($input, null, null);
            }
        }
        self::fail($operation . ' is not offered');
    }
}
