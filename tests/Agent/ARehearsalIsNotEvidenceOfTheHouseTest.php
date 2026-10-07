<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\Evidence;
use Milpa\Agent\EvidenceKind;
use Milpa\Agent\SessionFacts;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\LandedCalls;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A rehearsal is not evidence of the house (greenhouse decisions/0587, evidence/1129).
 *
 * Every mutating call a session makes runs first in a disposable trial, and the house records it `ok: true` with
 * `ran_in_trial: true, applied: false`. That call reaches the house only when its trial is promoted (decisions/0463,
 * 0494 §4). Measured on published 0.211.1 (greenhouse decisions/0585, session `w4`): a domain operation whose store
 * lives in `var/` ran in a trial that had «nothing to apply», the claim `operation-ok` over it was accepted, and the
 * house closed `verified: true` — with its own store unchanged.
 *
 * The door and the verdict now ask one reading, {@see LandedCalls}: a succeeded call is a fact about the house when it
 * ran in it, or when the trial it ran in was promoted and carried something. A rehearsal nothing promoted says nothing
 * of the house — it neither covers a claim nor takes back an earlier call that did land.
 *
 * @guards the claim of a call that ran in the house, of a rehearsal that was promoted, and of a trial the house
 *         applied; the refusal naming the promotion that would land a rehearsal; a closed todo staying closed
 *
 * @refuses `operation-ok` over a call that only ran in a trial, by its recorded call or by its execution receipt;
 *          `artifact-created` over an artifact only a trial holds; a promotion that failed, carried nothing or was
 *          of another trial; a verified closure resting on any of them
 *
 * @subject-in milpa/app-runtime
 */
final class ARehearsalIsNotEvidenceOfTheHouseTest extends TestCase
{
    private InMemoryEventStore $events;

    private SessionStore $store;

    private int $trials = 0;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'Registra un taladro en el taller.');
        $this->store->setTodo('s', new Todo('t1', 'Registrar el taladro en el taller', TodoStatus::Pending));
    }

    /** The measurement: a domain write whose trial changed no file the house keeps. */
    public function testACallThatOnlyRanInATrialDoesNotCoverTheClaim(): void
    {
        [$seq] = $this->rehearse('herramientas_agregar', ['nombre' => 'Taladro'], [], ['ok' => true, 'herramienta' => ['id' => 1, 'nombre' => 'Taladro', 'prestada' => false]]);

        $refused = $this->claim('operation-ok', 'herramientas_agregar');

        self::assertFalse($refused['ok']);
        self::assertStringContainsString("«herramientas_agregar» answered ok only inside a trial (seq {$seq})", (string) $refused['error']);
        self::assertStringContainsString('there is nothing to promote', (string) $refused['error'], 'a trial that changed nothing can never land');
        self::assertStringNotContainsString('sandbox:promote {', (string) $refused['error'], 'and the refusal names no call that would not help');
        self::assertSame(TodoStatus::Pending, $this->store->load('s')?->todos[0]->status, 'the todo stays open');
    }

    /** The execution receipt of a rehearsal says the operation ran — in the trial. */
    public function testTheExecutionReceiptOfARehearsalDoesNotCoverEither(): void
    {
        $this->rehearse('herramientas_agregar', ['nombre' => 'Taladro'], [], ['ok' => true]);

        $refused = $this->claim('operation-ok', 'herramientas.agregar');

        self::assertFalse($refused['ok'], 'the receipt names the operation, and the call it belongs to never left the trial');
        self::assertStringContainsString('answered ok only inside a trial', (string) $refused['error']);
    }

    public function testARehearsalNobodyPromotedIsRefusedAndTheRefusalNamesThePromotion(): void
    {
        [, $workspace] = $this->rehearse('plugins_register', ['name' => 'Prestamos'], ['config/plugins.php' => 'modified'], ['ok' => true, 'plugin' => 'Prestamos']);

        $refused = $this->claim('operation-ok', 'plugins_register');

        self::assertFalse($refused['ok']);
        self::assertStringContainsString('sandbox:promote {"workspace":"' . $workspace . '"}', (string) $refused['error'], 'a call the house runs (greenhouse decisions/0571)');
    }

    public function testARehearsalThatWasPromotedCovers(): void
    {
        [$seq, $workspace] = $this->rehearse('plugins_register', ['name' => 'Prestamos'], ['config/plugins.php' => 'modified'], ['ok' => true, 'plugin' => 'Prestamos']);
        $this->promote($workspace, ['config/plugins.php']);

        $claimed = $this->claim('operation-ok', 'plugins_register');

        self::assertTrue($claimed['ok'], (string) ($claimed['error'] ?? ''));
        self::assertSame(['fact' => 'call', 'operation' => 'plugins_register', 'seq' => $seq], $claimed['evidence']['coveredBy'], 'the shape the door always answered with');
    }

    /** @param array<string, mixed> $promotion */
    #[\PHPUnit\Framework\Attributes\DataProvider('promotionsThatLandNothing')]
    public function testAPromotionThatLandedNothingOfItDoesNotCover(array $promotion): void
    {
        [, $workspace] = $this->rehearse('plugins_register', ['name' => 'Prestamos'], ['config/plugins.php' => 'modified'], ['ok' => true]);
        $this->promote($promotion['workspace'] ?? $workspace, $promotion['paths'], $promotion['ok']);

        self::assertFalse($this->claim('operation-ok', 'plugins_register')['ok']);
    }

    /** @return iterable<string, array{array{ok: bool, paths: list<string>, workspace?: string}}> */
    public static function promotionsThatLandNothing(): iterable
    {
        yield 'it was refused' => [['ok' => false, 'paths' => ['config/plugins.php']]];
        yield 'it carried nothing' => [['ok' => true, 'paths' => []]];
        yield 'it was of another trial' => [['ok' => true, 'paths' => ['config/plugins.php'], 'workspace' => 'wffff']];
    }

    public function testACallThatRanInTheHouseCoversAsItAlwaysDid(): void
    {
        $seq = $this->store->recordToolCall('s', 'route_observe', ['path' => '/'], '{"ok":true,"observed":[]}', true, false, null, false);
        self::assertSame($seq, $this->claim('operation-ok', 'route_observe')['evidence']['coveredBy']['seq'] ?? null);

        $this->store->setTodo('s', new Todo('t2', 'Seat the resident', TodoStatus::Pending));
        $this->inTheHouse('identity_seat', 'identity:seat');
        self::assertTrue($this->claim('operation-ok', 'identity_seat', 't2')['ok'], 'a mutating call that never goes to trial');

        $this->store->setTodo('s', new Todo('t3', 'And by its receipt', TodoStatus::Pending));
        self::assertSame('execution', $this->claim('operation-ok', 'identity:seat', 't3')['evidence']['coveredBy']['fact'] ?? null, 'a receipt of the house covers as before');
    }

    /** `apply: when_verified` (greenhouse decisions/0578): the house applied the trial itself. */
    public function testATrialTheHouseAppliedCovers(): void
    {
        $this->trial('plugins_register', 'plugins.register', ['ran_in_trial' => true, 'applied' => true, 'workspace' => 'w9', 'changed' => ['config/plugins.php' => 'modified'], 'output' => ['ok' => true]]);

        self::assertTrue($this->claim('operation-ok', 'plugins_register')['ok']);
    }

    /**
     * A trial also confines calls that leave nothing behind (greenhouse decisions/0523): a test run answered what
     * it answered, and there is nothing of it to promote.
     */
    public function testACallThatWouldNotHaveChangedTheHouseDoesNotHaveToLandInIt(): void
    {
        $lasting = static fn (string $tool, array $arguments): ?bool => $tool === 'test' ? false : null;
        [$seq] = $this->rehearse('test', ['filter' => 'PrestamosTest'], [], ['ok' => true, 'tests' => 3, 'failures' => 0]);

        $claimed = $this->claim('operation-ok', 'test', 't1', $lasting);
        self::assertSame($seq, $claimed['evidence']['coveredBy']['seq'] ?? null, (string) ($claimed['error'] ?? ''));

        $this->store->setTodo('s', new Todo('t2', 'And a write does', TodoStatus::Pending));
        $this->rehearse('herramientas_agregar', ['nombre' => 'Taladro'], [], ['ok' => true]);
        self::assertFalse($this->claim('operation-ok', 'herramientas_agregar', 't2', $lasting)['ok'], 'a tool the catalogue does not classify lasts');
    }

    /** A promotion recorded before its receipt said which trial it came from still names it in what was asked. */
    public function testAPromotionIsKnownByTheTrialItWasAskedFor(): void
    {
        [, $workspace] = $this->rehearse('plugins_register', ['name' => 'Prestamos'], ['config/plugins.php' => 'modified'], ['ok' => true]);
        $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => $workspace], (string) json_encode([
            'ok' => true, 'promoted' => ['config/plugins.php'], 'evidence' => ['predicate' => 'promoted', 'subject' => $workspace],
        ]), true, true, null, false);

        self::assertTrue($this->claim('operation-ok', 'plugins_register')['ok']);
    }

    /** A ledger that kept only part of a promotion's result does not show that it carried nothing. */
    public function testAPromotionWhoseResultWasCutStillLandedTheTrialItWasAskedFor(): void
    {
        [, $workspace] = $this->rehearse('plugins_register', ['name' => 'Prestamos'], ['config/plugins.php' => 'modified'], ['ok' => true]);
        $this->store->recordToolCall('s', 'source_read', ['workspace' => $workspace], '{"ok":true,"content":"<?php return [', true, false, null, false);
        self::assertFalse($this->claim('operation-ok', 'plugins_register')['ok'], 'any other call that names the trial did not promote it');

        $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => $workspace], '{"ok":true,"promoted":["config/plugins.php"],"evidence":{"predicate":"promo', true, true, null, false);
        self::assertTrue($this->claim('operation-ok', 'plugins_register')['ok']);
    }

    public function testARehearsalSaysNothingOfTheCallsBeforeIt(): void
    {
        [$landed, $workspace] = $this->rehearse('plugins_register', ['name' => 'Prestamos'], ['config/plugins.php' => 'modified'], ['ok' => true]);
        $this->promote($workspace, ['config/plugins.php']);
        $this->rehearse('plugins_register', ['name' => 'Otro'], ['config/plugins.php' => 'modified'], ['ok' => true]);

        $claimed = $this->claim('operation-ok', 'plugins_register');
        self::assertSame($landed, $claimed['evidence']['coveredBy']['seq'] ?? null, 'the call that landed still answers for the tool');

        $this->store->setTodo('s', new Todo('t2', 'Seed', TodoStatus::Pending));
        $this->store->recordToolCall('s', 'entity_seed', ['entity' => 'Post'], '{"ok":false,"error":"no such entity"}', false, true, null, false);
        $this->rehearse('entity_seed', ['entity' => 'Post'], ['src/Plugins/Blog/Seeds/Post.json' => 'added'], ['ok' => true]);
        self::assertFalse($this->claim('operation-ok', 'entity_seed', 't2')['ok'], 'nor does it make a failure in the house an ok');
    }

    /** The store may keep only part of a long result; the house still recorded that the call ran in a trial. */
    public function testAResultCutBeforeStorageIsStillKnownAsARehearsal(): void
    {
        $this->trial('make', 'make', '{"ran_in_trial":true,"applied":false,"workspace":"w7","changed":{"src/Plugins/Prestamos/Prestamos.php":"added"},"output":{"ok":true,"files":[{"path":"/app/var/tri');
        self::assertFalse($this->claim('operation-ok', 'make')['ok'], 'known by the trial the house recorded for it');

        $this->store->setTodo('s', new Todo('t2', 'Read', TodoStatus::Pending));
        $this->store->recordToolCall('s', 'source_read', ['path' => 'config/app.php'], '{"ok":true,"content":"<?php return [', true, false, null, false);
        self::assertTrue($this->claim('operation-ok', 'source_read', 't2')['ok'], 'and a cut result with no trial behind it ran in the house');
    }

    public function testTheRefusalOffersOnlyTheToolsThatReachedTheHouse(): void
    {
        $this->rehearse('herramientas_agregar', ['nombre' => 'Taladro'], [], ['ok' => true]);
        $this->store->recordToolCall('s', 'herramientas_listar', [], '{"ok":true,"herramientas":[]}', true, false, null, false);

        $refused = (string) $this->claim('operation-ok', 'I registered the drill with herramientas_agregar and it answered ok')['error'];

        self::assertStringContainsString('«herramientas_listar»', $refused);
        self::assertStringNotContainsString('«herramientas_agregar»', $refused, 'what the judge would refuse is not offered');
    }

    public function testAnArtifactOnlyATrialHoldsIsNotCreatedInTheHouse(): void
    {
        $this->store->recordToolCall('s', 'make', ['what' => 'entity', 'plugin' => 'Prestamos', 'name' => 'Herramienta'], '{"ok":false,"error":"name the fields"}', false, true, null, false);
        [, $workspace] = $this->rehearse(
            'make',
            ['what' => 'entity', 'plugin' => 'Prestamos', 'name' => 'Herramienta'],
            ['src/Plugins/Prestamos/Entities/Herramienta.php' => 'added'],
            ['ok' => true, 'files' => [['path' => '/app/var/trials/w/copy/src/Plugins/Prestamos/Entities/Herramienta.php', 'action' => 'created']], 'verify' => ['ok' => true]],
        );

        $refused = $this->claim('artifact-created', 'Herramienta');
        self::assertFalse($refused['ok'], 'made in the copy: the house does not have it');
        self::assertStringContainsString('sandbox:promote {"workspace":"' . $workspace . '"}', (string) $refused['error']);

        $this->promote($workspace, ['src/Plugins/Prestamos/Entities/Herramienta.php']);
        self::assertTrue($this->claim('artifact-created', 'Herramienta')['ok'], 'and once the trial is promoted, it is');
    }

    /** One trial was abandoned and another, promoted, made it again: the house has it. */
    public function testAnAbandonedTrialSaysNothingOfAnArtifactAnotherTrialLanded(): void
    {
        $this->rehearse('implement', ['plugin' => 'Prestamos', 'class' => 'Prestamos'], ['src/Plugins/Prestamos/Prestamos.php' => 'modified'], ['ok' => true]);
        $this->store->completeTodo('s', 't1', new Evidence('e1', EvidenceKind::ArtifactCreated, 'Prestamos'));
        self::assertNotSame([], array_filter($this->verdict()['reasons'], static fn (string $r): bool => str_contains($r, 'was made only inside a trial')), 'only the abandoned trial holds it');

        [, $workspace] = $this->rehearse('implement', ['plugin' => 'Prestamos', 'class' => 'Prestamos'], ['src/Plugins/Prestamos/Prestamos.php' => 'modified'], ['ok' => true]);
        $this->promote($workspace, ['src/Plugins/Prestamos/Prestamos.php']);
        $this->rehearse('implement', ['plugin' => 'Prestamos', 'class' => 'Prestamos'], ['src/Plugins/Prestamos/Prestamos.php' => 'modified'], ['ok' => true]);
        self::assertSame([], array_filter($this->verdict()['reasons'], static fn (string $r): bool => str_contains($r, 'rests on')), 'whatever was rehearsed before or after');
    }

    /** A session recorded before this slice already holds the evidence: the verdict asks the stream again. */
    public function testATodoClosedOverARehearsalIsNotDoneForTheVerdict(): void
    {
        [$seq] = $this->rehearse('herramientas_agregar', ['nombre' => 'Taladro'], [], ['ok' => true]);
        $this->store->completeTodo('s', 't1', new Evidence('e1', EvidenceKind::OperationOk, 'herramientas_agregar'));

        $verdict = $this->verdict();

        self::assertFalse($verdict['verified'], 'the house does not close verified over a call that never left the trial');
        self::assertContains("todo t1 rests on «herramientas_agregar», which answered ok only inside a trial nothing promoted (seq {$seq})", $verdict['reasons']);
        self::assertSame('recorded_work', $verdict['scope']);
    }

    /** Reopened, the todo is open again and says so itself: the evidence it once closed on is no longer asked about. */
    public function testATodoThatWasReopenedIsJudgedAsOpenAndNothingMore(): void
    {
        $this->rehearse('herramientas_agregar', ['nombre' => 'Taladro'], [], ['ok' => true]);
        $this->store->completeTodo('s', 't1', new Evidence('e1', EvidenceKind::OperationOk, 'herramientas_agregar'));
        $this->store->setTodo('s', new Todo('t1', 'Registrar el taladro en el taller', TodoStatus::Pending));

        $reasons = $this->verdict()['reasons'];
        self::assertContains('1 todo open', $reasons);
        self::assertSame([], array_filter($reasons, static fn (string $r): bool => str_contains($r, 'rests on')));
    }

    public function testATodoClosedOverWhatReachedTheHouseStaysDone(): void
    {
        [, $workspace] = $this->rehearse('plugins_register', ['name' => 'Prestamos'], ['config/plugins.php' => 'modified'], ['ok' => true]);
        $this->promote($workspace, ['config/plugins.php']);
        $this->store->completeTodo('s', 't1', new Evidence('e1', EvidenceKind::OperationOk, 'plugins_register'));

        self::assertSame([], array_values(array_filter($this->verdict()['reasons'], static fn (string $r): bool => str_contains($r, 'rests on'))));
    }

    /** The door covered it by a receipt of the house; a later failure and a later rehearsal do not undo that. */
    public function testAReceiptOfTheHouseStillAnswersForTheVerdict(): void
    {
        $this->inTheHouse('make', 'make');
        $this->store->recordToolCall('s', 'make', ['what' => 'plugin'], '{"ok":false,"error":"it exists"}', false, true, null, false);
        $this->rehearse('make', ['what' => 'entity', 'plugin' => 'Prestamos', 'name' => 'Herramienta'], ['src/x.php' => 'added'], ['ok' => true]);
        self::assertSame('execution', $this->claim('operation-ok', 'make')['evidence']['coveredBy']['fact'] ?? null);

        self::assertSame([], array_filter($this->verdict()['reasons'], static fn (string $r): bool => str_contains($r, 'rests on')));
    }

    public function testAnArtifactClaimOverATrialIsNotDoneForTheVerdictEither(): void
    {
        $this->rehearse('make', ['what' => 'entity', 'plugin' => 'Prestamos', 'name' => 'Herramienta'], ['src/Plugins/Prestamos/Entities/Herramienta.php' => 'added'], ['ok' => true, 'verify' => ['ok' => true]]);
        $this->store->completeTodo('s', 't1', new Evidence('e1', EvidenceKind::ArtifactCreated, 'Herramienta'));

        self::assertNotSame([], array_filter($this->verdict()['reasons'], static fn (string $r): bool => str_starts_with($r, 'todo t1 rests on «Herramienta»')));
    }

    /** Evidence the stream says nothing about — recorded elsewhere, or compacted away — is judged as it always was. */
    public function testEvidenceTheStreamDoesNotContradictIsLeftAlone(): void
    {
        $this->store->completeTodo('s', 't1', new Evidence('e1', EvidenceKind::OperationOk, 'tool_nobody_recorded'));

        self::assertTrue($this->verdict()['verified']);
        $session = $this->store->load('s');
        self::assertNotNull($session);
        self::assertTrue(ClosureVerdict::derive($session, SessionFacts::fromEvents('s', $this->store->stream('s')))['verified'], 'and without the stream the record is judged alone');
    }

    public function testTheReadingSaysWhereEachCallRan(): void
    {
        [$rehearsed, $workspace] = $this->rehearse('plugins_register', ['name' => 'Prestamos'], ['config/plugins.php' => 'modified'], ['ok' => true]);
        $inHouse = $this->store->recordToolCall('s', 'route_observe', ['path' => '/'], '{"ok":true}', true, false, null, false);
        $failed = $this->store->recordToolCall('s', 'make', ['what' => 'plugin'], '{"ok":false}', false, true, null, false);

        $before = LandedCalls::of($this->store->stream('s'));
        self::assertFalse($before->reached($rehearsed));
        self::assertTrue($before->reached($inHouse));
        self::assertNull($before->reached($failed), 'a call that did not succeed is not asked about');
        self::assertNull($before->reached(9999));
        self::assertSame(['seq' => $rehearsed, 'workspace' => $workspace, 'promotable' => true], $before->rehearsalOf('plugins_register'));
        self::assertNull($before->rehearsalOf('route_observe'));

        $this->promote($workspace, ['config/plugins.php']);
        $after = LandedCalls::of($this->store->stream('s'));
        self::assertTrue($after->reached($rehearsed));
        self::assertNull($after->rehearsalOf('plugins_register'), 'a rehearsal that landed is no longer one');
    }

    /**
     * A call as the house records it when it runs in a trial that is not applied: the trial's own fact, the call,
     * and its execution receipt.
     *
     * @param array<string, mixed>  $arguments
     * @param array<string, string> $changed
     * @param array<string, mixed>  $output
     *
     * @return array{int, string}
     */
    private function rehearse(string $tool, array $arguments, array $changed, array $output): array
    {
        $workspace = 'w' . str_pad((string) ++$this->trials, 4, '0', \STR_PAD_LEFT);
        $seq = $this->trial($tool, str_replace('_', '.', $tool), [
            'ran_in_trial' => true, 'applied' => false, 'workspace' => $workspace, 'changed' => $changed, 'output' => $output,
        ] + ($changed === [] ? [] : ['to_apply' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $workspace]]]), $arguments);

        return [$seq, $workspace];
    }

    /**
     * @param array<string, mixed>|string $result
     * @param array<string, mixed>        $arguments
     */
    private function trial(string $tool, string $operation, array|string $result, array $arguments = []): int
    {
        $workspace = \is_array($result) ? (string) $result['workspace'] : 'w7';
        $this->append('session.trial_run_recorded', ['workspace' => $workspace, 'operation' => $operation, 'exit' => 0]);
        $seq = $this->store->recordToolCall('s', $tool, $arguments, \is_array($result) ? (string) json_encode($result) : $result, true, true, null, false);
        $this->append('session.operation_executed', ['operation' => $operation, 'executed_by' => ['principal' => 'key:AB', 'source' => 'cli', 'verified' => true], 'authorized_by' => null]);

        return $seq;
    }

    private function inTheHouse(string $tool, string $operation): int
    {
        $seq = $this->store->recordToolCall('s', $tool, [], '{"ok":true}', true, true, null, false);
        $this->append('session.operation_executed', ['operation' => $operation, 'executed_by' => ['principal' => 'key:AB', 'source' => 'cli', 'verified' => true], 'authorized_by' => null]);

        return $seq;
    }

    /** @param list<string> $paths */
    private function promote(string $workspace, array $paths, bool $ok = true): int
    {
        // A refused promotion is recorded with what it WOULD have carried: only its `ok` says it carried nothing.
        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => $workspace], (string) json_encode([
            'ok' => $ok,
            'promoted' => $paths,
            'evidence' => ['predicate' => 'promoted', 'subject' => $workspace, 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => $workspace], 'paths' => $paths],
        ] + ($ok ? [] : ['error' => 'the house would not boot with it'])), true, true, null, false);
    }

    /** @param array<string, mixed> $payload */
    private function append(string $type, array $payload): void
    {
        $this->events->append(new Event(streamId: SessionStore::PREFIX . 's', type: $type, payload: $payload, seq: $this->events->nextSeq()));
    }

    /** @return array{verified: bool, reasons: list<string>, scope: string} */
    private function verdict(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);
        $stream = $this->store->stream('s');

        return ClosureVerdict::derive($session, SessionFacts::fromEvents('s', $stream), $stream);
    }

    /** @return array<string, mixed> */
    private function claim(string $kind, string $reference, string $todo = 't1', ?\Closure $lasting = null): array
    {
        foreach ((new SessionBookkeeping($this->store, 's', $this->events, $lasting))->operations() as $operation) {
            if ($operation->name === 'work:claim-verified') {
                /** @var array<string, mixed> */
                return ($operation->handler)(['todo' => $todo, 'kind' => $kind, 'reference' => $reference]);
            }
        }
        self::fail('work:claim-verified is not offered');
    }
}
