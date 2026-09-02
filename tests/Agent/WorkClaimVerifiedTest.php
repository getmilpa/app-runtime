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
    /** The result route is dead: a reference that only appears in the OUTPUT text covers nothing. */
    public function testAReferenceHidingInResultProseDoesNotCoverTheClaim(): void
    {
        $this->llamar('todo', ['text' => 'make it green']);
        $this->almacen->recordToolCall(
            's1',
            'test',
            ['filter' => 'SomethingElseTest'],
            (string) json_encode(['ok' => true, 'tests' => 12, 'output' => 'all green for VerdeArtifact']),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'test-passed',
            'reference' => 'VerdeArtifact',
        ]);

        self::assertFalse((bool) $r['ok'], 'prose is not a declaration: the claim must be refused');
        self::assertStringContainsString('no recorded fact covers', (string) $r['error']);
    }

    /**
     * THE RUN-8 DEFECT CLOSES (greenhouse decisions/0187): screen:declare served «tareas-preview»
     * (ok, a served address), and a `screen-served` claim now closes the preview todo — the fourth
     * authority, EVIDENCE read by predicate, covers work the three producer-shaped kinds could not.
     */
    public function testAServedScreenCoversAScreenServedClaim(): void
    {
        $this->llamar('todo', ['text' => 'preview the tareas screen']);
        $this->almacen->recordToolCall(
            's1',
            'screen:declare',
            ['name' => 'tareas-preview'],
            (string) json_encode([
                'ok' => true,
                'screen' => 'tareas-preview',
                'servedAt' => '/live/page?component=tareas-preview',
                'evidence' => ['predicate' => 'served', 'subject' => 'tareas-preview', 'servedAt' => '/live/page?component=tareas-preview'],
            ]),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'screen-served',
            'reference' => 'tareas-preview',
        ]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('done', $r['todo']['status']);
        self::assertSame('screen-served', $r['evidence']['kind']);
        self::assertSame('served', $r['evidence']['coveredBy']['fact'], 'the result names the served receipt');
        self::assertTrue($this->almacen->load('s1')?->isDoneVerified('t1'));
    }

    /**
     * PRODUCER-AGNOSTIC: the coverage matches on predicate + subject, never the tool name. A served
     * receipt recorded under a DIFFERENT producer label still covers a `screen-served` claim.
     */
    public function testAServedReceiptUnderAnyProducerCoversTheClaim(): void
    {
        $this->llamar('todo', ['text' => 'preview the tareas screen']);
        $this->almacen->recordToolCall(
            's1',
            'some-future-screen-op',
            ['name' => 'tareas-preview'],
            (string) json_encode([
                'ok' => true,
                'evidence' => ['predicate' => 'served', 'subject' => 'tareas-preview'],
            ]),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'screen-served',
            'reference' => 'tareas-preview',
        ]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('served', $r['evidence']['coveredBy']['fact']);
        self::assertSame('some-future-screen-op', $r['evidence']['coveredBy']['operation'], 'the producer is reported, not matched on');
    }

    /**
     * UNSERVED / WRONG SUBJECT / FAILED DECLARE: a `screen-served` claim for a screen never served,
     * or naming a subject other than the served one, is refused fail-closed and the todo stays open.
     */
    public function testAScreenNeverServedOrTheWrongSubjectIsRefused(): void
    {
        $this->llamar('todo', ['text' => 'preview the tareas screen']);
        // A declare that FAILED — it carries no served receipt even though it names the subject.
        $this->almacen->recordToolCall(
            's1',
            'screen:declare',
            ['name' => 'tareas-preview'],
            (string) json_encode(['ok' => false, 'error' => 'a screen name is a-z, 0-9, dash']),
            false,
            true,
        );
        // And a served screen with a DIFFERENT subject than the claim will name.
        $this->almacen->recordToolCall(
            's1',
            'screen:declare',
            ['name' => 'otra-pantalla'],
            (string) json_encode([
                'ok' => true,
                'screen' => 'otra-pantalla',
                'servedAt' => '/live/page?component=otra-pantalla',
                'evidence' => ['predicate' => 'served', 'subject' => 'otra-pantalla'],
            ]),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'screen-served',
            'reference' => 'tareas-preview',
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no recorded fact covers', (string) $r['error']);
        self::assertStringContainsString('tareas-preview', (string) $r['error'], 'it names what was looked for');
        self::assertSame(TodoStatus::Pending, $this->almacen->load('s1')?->todos[0]->status, 'fail closed: still open');
    }

    /** A RED verdict recorded for the served screen refuses the claim first, before any coverage. */
    public function testARedVerdictRefusesAScreenServedClaimFirst(): void
    {
        $this->llamar('todo', ['text' => 'preview the tareas screen']);
        // A served receipt exists…
        $this->almacen->recordToolCall(
            's1',
            'screen:declare',
            ['name' => 'tareas-preview'],
            (string) json_encode([
                'ok' => true,
                'screen' => 'tareas-preview',
                'servedAt' => '/live/page?component=tareas-preview',
                'evidence' => ['predicate' => 'served', 'subject' => 'tareas-preview'],
            ]),
            true,
            true,
        );
        // …but a later RED verification verdict names the same reference.
        $this->almacen->recordToolCall(
            's1',
            'validate',
            ['target' => 'tareas-preview'],
            (string) json_encode(['ok' => false, 'checks' => [['name' => 'schema', 'ok' => false]]]),
            false,
            false,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'screen-served',
            'reference' => 'tareas-preview',
        ]);

        self::assertFalse($r['ok'], 'a red refuses first, whatever the kind');
        self::assertStringContainsString('RED', (string) $r['error']);
        self::assertSame(TodoStatus::Pending, $this->almacen->load('s1')?->todos[0]->status);
    }

    /**
     * FRESHNESS — FRESH COVERS (greenhouse decisions/0187, the EvidenceReceipt continuation of D-02):
     * a served receipt with nothing later that invalidated it closes the claim, and the result names
     * the fresh served receipt that covered it. The D-02 case preserved, now with the freshness read.
     */
    public function testAFreshServedScreenClosesTheClaim(): void
    {
        $this->llamar('todo', ['text' => 'preview the tareas screen']);
        $this->almacen->recordToolCall(
            's1',
            'screen:declare',
            ['name' => 'tareas-preview'],
            (string) json_encode([
                'ok' => true,
                'screen' => 'tareas-preview',
                'servedAt' => '/live/page?component=tareas-preview',
                'evidence' => ['predicate' => 'served', 'subject' => 'tareas-preview', 'servedAt' => '/live/page?component=tareas-preview'],
            ]),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'screen-served',
            'reference' => 'tareas-preview',
        ]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('done', $r['todo']['status']);
        self::assertSame('served', $r['evidence']['coveredBy']['fact']);
        self::assertTrue($r['evidence']['coveredBy']['fresh'] ?? null, 'the covering receipt is reported fresh');
    }

    /**
     * FRESHNESS — STALE REFUSED (THE LEVER, greenhouse decisions/0187): a screen served, THEN
     * forgotten, is no longer served — yet its old served receipt used to close a claim. The judge
     * now covers only a FRESH receipt: the claim is REFUSED, the todo stays open, and the refusal
     * names that the evidence went stale and at which seq. Against D-02 code (no freshness) this
     * WRONGLY closed — this is the red-first proof.
     */
    public function testAForgottenServedScreenNoLongerCoversTheClaim(): void
    {
        $this->llamar('todo', ['text' => 'preview the tareas screen']);
        $this->almacen->recordToolCall(
            's1',
            'screen:declare',
            ['name' => 'tareas-preview'],
            (string) json_encode([
                'ok' => true,
                'screen' => 'tareas-preview',
                'servedAt' => '/live/page?component=tareas-preview',
                'evidence' => ['predicate' => 'served', 'subject' => 'tareas-preview', 'servedAt' => '/live/page?component=tareas-preview'],
            ]),
            true,
            true,
        );
        // Later the screen is FORGOTTEN — a call declaring an invalidation of the served receipt.
        $this->almacen->recordToolCall(
            's1',
            'screen:forget',
            ['name' => 'tareas-preview'],
            (string) json_encode([
                'ok' => true,
                'forgotten' => 'tareas-preview',
                'evidence' => ['predicate' => 'served', 'subject' => 'tareas-preview', 'invalidates' => true],
            ]),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'screen-served',
            'reference' => 'tareas-preview',
        ]);

        self::assertFalse($r['ok'], 'a forgotten screen is not served: refuse');
        self::assertStringContainsString('stale', (string) $r['error']);
        self::assertStringContainsString('tareas-preview', (string) $r['error']);
        self::assertMatchesRegularExpression('/seq \d+/', (string) $r['error'], 'the refusal names the invalidating seq');
        self::assertSame(TodoStatus::Pending, $this->almacen->load('s1')?->todos[0]->status, 'fail closed: still open');
    }

    /**
     * FRESHNESS — a served screen whose later re-declare FAILED is also stale: the model tried to
     * re-establish it and the attempt did not hold, so the fail-closed judge refuses the claim.
     */
    public function testAServedScreenWithAFailedReDeclareIsRefused(): void
    {
        $this->llamar('todo', ['text' => 'preview the tareas screen']);
        $this->almacen->recordToolCall(
            's1',
            'screen:declare',
            ['name' => 'tareas-preview'],
            (string) json_encode([
                'ok' => true,
                'screen' => 'tareas-preview',
                'servedAt' => '/live/page?component=tareas-preview',
                'evidence' => ['predicate' => 'served', 'subject' => 'tareas-preview', 'servedAt' => '/live/page?component=tareas-preview'],
            ]),
            true,
            true,
        );
        $this->almacen->recordToolCall(
            's1',
            'screen:declare',
            ['name' => 'tareas-preview'],
            (string) json_encode([
                'ok' => false,
                'error' => 'unknown component type',
                'evidence' => ['predicate' => 'served', 'subject' => 'tareas-preview'],
            ]),
            false,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'screen-served',
            'reference' => 'tareas-preview',
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('stale', (string) $r['error']);
        self::assertSame(TodoStatus::Pending, $this->almacen->load('s1')?->todos[0]->status);
    }

    /**
     * THE JUDGE NEVER NAMES THE PRODUCER: the coverage code contains no literal «screen:declare»,
     * so it keys on the predicate, not the tool. If a mutation made it key on the name, this reddens.
     */
    public function testTheJudgeDoesNotHardcodeTheScreenDeclareProducer(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/src/Agent/SessionBookkeeping.php');
        self::assertIsString($source);
        // The covering half of the judge lives between coveringFact() and lookedFor(): assert the
        // whole file never keys on the screen:declare tool name for its coverage.
        self::assertStringNotContainsString("'screen:declare'", $source, 'the judge must match the predicate, never the producer name');
        self::assertStringNotContainsString('"screen:declare"', $source);
        // Nor the invalidating producer: freshness is derived from the receipt shape, never the tool.
        self::assertStringNotContainsString("'screen:forget'", $source, 'the judge must not key on the invalidating producer either');
        self::assertStringNotContainsString('"screen:forget"', $source);
    }

    /** The declared route stays alive: a reference named as the test FILTER covers the claim. */
    public function testAReferenceDeclaredAsTheTestFilterCoversTheClaim(): void
    {
        $this->llamar('todo', ['text' => 'prove VerdeArtifact']);
        $this->almacen->recordToolCall(
            's1',
            'test',
            ['filter' => 'VerdeArtifact'],
            (string) json_encode(['ok' => true, 'tests' => 3]),
            true,
            true,
        );

        $r = $this->llamar('work:claim-verified', [
            'todo' => 't1',
            'kind' => 'test-passed',
            'reference' => 'VerdeArtifact',
        ]);

        self::assertTrue((bool) $r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('call', $r['evidence']['coveredBy']['fact']);
    }
}
