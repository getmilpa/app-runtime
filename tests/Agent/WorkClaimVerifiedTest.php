<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\Evidence;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The WorkProtocol graduation at the model's surface (greenhouse decisions/0183).
 *
 * The model no longer even has `todo: done` — it has `work:claim-verified`, and the HOUSE judges
 * whether the recorded evidence covers the claim: deterministically, from the session's own
 * recorded facts, with no re-running and no filesystem. No covering fact refuses and the todo does
 * not move; a recorded RED verdict refuses naming the red; a covering green fact completes the todo
 * through the store's sanctioned door and the result says what evidence closed it.
 */
final class WorkClaimVerifiedTest extends TestCase
{
    private InMemoryEventStore $eventos;

    private SessionStore $almacen;

    protected function setUp(): void
    {
        $this->eventos = new InMemoryEventStore();
        $this->almacen = new SessionStore($this->eventos);
        $this->almacen->start('s1', 'build the thing');
    }

    /** @param array<string, mixed> $entrada */
    private function llamar(string $nombre, array $entrada): array
    {
        foreach ((new SessionBookkeeping($this->almacen, 's1', $this->eventos))->operations() as $operacion) {
            if ($operacion->name === $nombre) {
                $handler = $operacion->handler;
                self::assertIsCallable($handler);

                /** @var array<string, mixed> $r */
                $r = $handler($entrada);

                return $r;
            }
        }

        self::fail("no existe «{$nombre}»");
    }

    /** The notebook offers the claim beside plan and todo, and the gate exemption list names it. */
    public function testTheNotebookOffersTheClaimBesidePlanAndTodo(): void
    {
        $names = array_map(
            static fn ($op): string => $op->name,
            (new SessionBookkeeping($this->almacen, 's1', $this->eventos))->operations(),
        );

        self::assertSame(['plan', 'todo', 'work:claim-verified'], $names);
        self::assertSame(['plan', 'todo', 'work:claim-verified'], SessionBookkeeping::names());
    }

    /** SURFACE LAW: `done` left the schema — the model cannot even ask for it by enum. */
    public function testTheStatusEnumNoLongerOffersDone(): void
    {
        foreach ((new SessionBookkeeping($this->almacen, 's1', $this->eventos))->operations() as $op) {
            if ($op->name !== 'todo') {
                continue;
            }

            self::assertSame(
                ['pending', 'in_progress', 'blocked'],
                $op->inputSchema['properties']['status']['enum'],
                'done is not this tool\'s to offer any more',
            );

            return;
        }

        self::fail('no existe «todo»');
    }

    /**
     * A `done` input is answered with a CORRECTING error naming the new door — never silence, and
     * never the transition: the model can act on the answer, and the card does not move.
     */
    public function testATodoSentToDoneIsAnsweredWithTheClaimDoor(): void
    {
        $this->llamar('todo', ['text' => 'ship it']);

        $r = $this->llamar('todo', ['id' => 't1', 'status' => 'done']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('work:claim-verified', (string) $r['error'], 'the refusal names the door');
        self::assertSame(TodoStatus::Pending, $this->almacen->load('s1')?->todos[0]->status, 'the card did not move');

        $nuevo = $this->llamar('todo', ['text' => 'born finished', 'status' => 'done']);
        self::assertFalse($nuevo['ok'], 'a card cannot be born done either');
        self::assertStringContainsString('work:claim-verified', (string) $nuevo['error']);
        self::assertCount(1, $this->almacen->load('s1')?->todos ?? [], 'and none was created');
    }

    /** FAIL CLOSED: no covering recorded fact — refused naming what was looked for, todo untouched. */
    public function testAClaimWithoutACoveringRecordedFactIsRefusedAndTheTodoStaysOpen(): void
    {
        $this->llamar('todo', ['text' => 'ship it']);

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'test-passed',
            'reference' => 'vendor/bin/phpunit --filter ShipTest',
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no recorded fact covers', (string) $r['error']);
        self::assertStringContainsString('ShipTest', (string) $r['error'], 'it names what was looked for');
        self::assertSame(TodoStatus::Pending, $this->almacen->load('s1')?->todos[0]->status, 'fail closed: still open');
        self::assertFalse($this->almacen->load('s1')?->isDoneVerified('t1'));
    }

    /** A recorded RED verdict for the reference refuses the claim, naming the red. */
    public function testAClaimWhoseReferenceCarriesARedVerdictIsRefusedNamingTheRed(): void
    {
        $this->llamar('todo', ['text' => 'build the Lista screen']);
        $this->almacen->recordToolCall(
            's1',
            'make',
            ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'],
            (string) json_encode(['ok' => true, 'verify' => ['ok' => false]]),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'artifact-created',
            'reference' => 'Lista',
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('RED', (string) $r['error'], 'the red is named, not skipped');
        self::assertStringContainsString('Lista', (string) $r['error']);
        self::assertSame(TodoStatus::Pending, $this->almacen->load('s1')?->todos[0]->status);
    }

    /** A producer-declared green verification verdict covers a `test-passed` claim. */
    public function testAGreenVerificationVerdictCoversATestPassedClaim(): void
    {
        $this->llamar('todo', ['text' => 'write GreeterService']);
        $this->almacen->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService'],
            (string) json_encode(['ok' => true, 'verified' => 'syntax, strict_types, class and namespace']),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'test-passed',
            'reference' => 'GreeterService',
        ]);

        self::assertTrue($r['ok']);
        self::assertSame('done', $r['todo']['status']);
        self::assertSame('test-passed', $r['evidence']['kind']);
        self::assertSame('GreeterService', $r['evidence']['reference']);
        self::assertSame('verification', $r['evidence']['coveredBy']['fact'], 'the result says WHAT closed it');
        self::assertTrue($this->almacen->load('s1')?->isDoneVerified('t1'), 'done, and the ledger vouches');
    }

    /** A recorded `test` call with ok:true whose identity matches the reference covers the claim too. */
    public function testAGreenTestCallCoversATestPassedClaim(): void
    {
        $this->llamar('todo', ['text' => 'make TareasService pass']);
        $this->almacen->recordToolCall(
            's1',
            'test',
            ['target' => 'TareasServiceTest'],
            (string) json_encode(['ok' => true, 'tests' => 12]),
            true,
            false,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'test-passed',
            'reference' => 'TareasServiceTest',
        ]);

        self::assertTrue($r['ok']);
        self::assertSame('call', $r['evidence']['coveredBy']['fact']);
        self::assertSame('test', $r['evidence']['coveredBy']['operation']);
        self::assertTrue($this->almacen->load('s1')?->isDoneVerified('t1'));
    }

    /** A recorded tool call with ok:true for the named operation covers an `operation-ok` claim. */
    public function testARecordedOkCallCoversAnOperationOkClaim(): void
    {
        $this->llamar('todo', ['text' => 'set the config']);
        $this->almacen->recordToolCall('s1', 'config_set', ['key' => 'agent.model'], (string) json_encode(['ok' => true]), true, true);

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'operation-ok',
            'reference' => 'config_set',
        ]);

        self::assertTrue($r['ok']);
        self::assertSame('call', $r['evidence']['coveredBy']['fact']);
        self::assertTrue($this->almacen->load('s1')?->isDoneVerified('t1'));
    }

    /** An execution receipt for the named operation covers an `operation-ok` claim as well. */
    public function testAnExecutionReceiptCoversAnOperationOkClaim(): void
    {
        $this->llamar('todo', ['text' => 'refresh the capabilities']);
        $this->almacen->recordExecution('s1', 'capabilities.refresh', null, 'agent', null, 'sha256:abc');

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'operation-ok',
            'reference' => 'capabilities.refresh',
        ]);

        self::assertTrue($r['ok']);
        self::assertSame('execution', $r['evidence']['coveredBy']['fact']);
        self::assertTrue($this->almacen->load('s1')?->isDoneVerified('t1'));
    }

    /** A materialised artifact — a mutating call's own ok:true naming it — covers `artifact-created`. */
    public function testAMaterialisedArtifactCoversAnArtifactCreatedClaim(): void
    {
        $this->llamar('todo', ['text' => 'build the Lista screen']);
        $this->almacen->recordToolCall(
            's1',
            'make',
            ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'],
            (string) json_encode(['ok' => true, 'files' => [['path' => 'src/Plugins/Demo/Screens/Lista.php', 'action' => 'created']]]),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'artifact-created',
            'reference' => 'Lista',
        ]);

        self::assertTrue($r['ok']);
        self::assertSame('work-state', $r['evidence']['coveredBy']['fact']);
        self::assertTrue($this->almacen->load('s1')?->isDoneVerified('t1'));
    }

    /** An artifact only ATTEMPTED — the call failed — does not cover the claim: fail closed. */
    public function testAnAttemptedButNotMaterialisedArtifactDoesNotCoverTheClaim(): void
    {
        $this->llamar('todo', ['text' => 'build the Lista screen']);
        $this->almacen->recordToolCall(
            's1',
            'make',
            ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'],
            (string) json_encode(['ok' => false]),
            false,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'artifact-created',
            'reference' => 'Lista',
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no recorded fact covers', (string) $r['error']);
        self::assertSame(TodoStatus::Pending, $this->almacen->load('s1')?->todos[0]->status);
    }

    /** A claim on a todo this session does not hold is refused by name. */
    public function testAClaimOnATodoThatDoesNotExistIsRefused(): void
    {
        $r = $this->llamar('work:claim-verified', [
            'todo' => 't9',
            'kind' => 'operation-ok',
            'reference' => 'config_set',
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('t9', (string) $r['error']);
    }

    /** A claim on a card already done is refused: there is nothing left to claim. */
    public function testAClaimOnAnAlreadyDoneTodoIsRefused(): void
    {
        $this->llamar('todo', ['text' => 'ship it']);
        $this->almacen->completeTodo('s1', 't1', Evidence::testPassed('e1', 'vendor/bin/phpunit'));

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'test-passed',
            'reference' => 'vendor/bin/phpunit',
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('already done', (string) $r['error']);
    }

    /** Missing or unknown fields are said out loud — a correcting error, never silence. */
    public function testAClaimWithMissingOrUnknownFieldsSaysWhatIsRequired(): void
    {
        $vacio = $this->llamar('work:claim-verified', []);
        self::assertFalse($vacio['ok']);
        self::assertStringContainsString('todo', (string) $vacio['error']);
        self::assertStringContainsString('kind', (string) $vacio['error']);
        self::assertStringContainsString('reference', (string) $vacio['error']);

        $this->llamar('todo', ['text' => 'ship it']);
        $raro = $this->llamar('work:claim-verified', ['todo' => 't1', 'kind' => 'vibes', 'reference' => 'x']);
        self::assertFalse($raro['ok']);
        self::assertStringContainsString('kind', (string) $raro['error']);
    }

    /** Without a reachable session stream the claim cannot be judged, and it FAILS CLOSED. */
    public function testWithoutAReachableStreamTheClaimFailsClosed(): void
    {
        $this->almacen->setTodo('s1', new Todo('t1', 'ship it', TodoStatus::Pending));

        $sinStream = null;
        foreach ((new SessionBookkeeping($this->almacen, 's1'))->operations() as $operacion) {
            if ($operacion->name === 'work:claim-verified') {
                $handler = $operacion->handler;
                self::assertIsCallable($handler);
                $sinStream = $handler(['todo' => 't1', 'kind' => 'operation-ok', 'reference' => 'config_set']);
            }
        }

        self::assertIsArray($sinStream, 'the operation is still declared: judging is what needs the stream');
        self::assertFalse($sinStream['ok']);
        self::assertStringContainsString('stream', (string) $sinStream['error']);
        self::assertSame(TodoStatus::Pending, $this->almacen->load('s1')?->todos[0]->status);
    }
}
