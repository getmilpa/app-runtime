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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Evidence;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * THE HOUSE CLOSES ON A CAPABILITY IT SAW DECLARED WHOLE, AND SAYS IT DID NOT SEE IT WORK (greenhouse decisions/0595).
 *
 * Measured with a real resident (greenhouse evidence/1137 §5), app-runtime 0.211.3: three runs left four operations
 * that work, and `closure_derived` said `verified: false` with «artifact … has no current verification» once per
 * class — they ran their tests four times each, and nothing they could call verifies an operation. A served route was
 * the only thing the house could observe of what landed. A promotion now carries what the capabilities built where it
 * landed declare, and the closure reads that receipt.
 *
 * The streams here are the shape those runs left: every change is rehearsed in a trial and lands by a promotion.
 */
final class TheHouseClosesOnACapabilityItSawDeclaredWholeTest extends TestCase
{
    private const GOAL = 'Build a plugin named Ledger to open an account and to list the accounts.';
    private const OPEN = 'src/Plugins/Ledger/Operations/OpenAccount.php';
    private const LISTS = 'src/Plugins/Ledger/Operations/ListAccounts.php';

    private SessionStore $store;

    private int $trials = 0;

    protected function setUp(): void
    {
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('s', self::GOAL, AutonomyMode::Auto);
    }

    public function testACapabilityOfScaffoldsDoesNotClose(): void
    {
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $seq = $this->scaffold('ListAccounts', self::LISTS, $this->both());

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'the catalogue grows the moment «make» lands: that is not the work');
        self::assertSame(
            ["the house observed «Ledger» declaring 2 operations, 2 of them still the scaffold «make» landed (seq {$seq}): ledger:open, ledger:list — fill them with implement"],
            $this->seen($closure),
            'said once, naming each',
        );
        self::assertArrayNotHasKey('derivedFrom', $closure);
    }

    public function testFilledItClosesAndTheVerdictSaysItWasNotSeenWorking(): void
    {
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $this->scaffold('ListAccounts', self::LISTS, $this->both());
        $this->fill('OpenAccount', self::OPEN, $this->both());
        $seq = $this->fill('ListAccounts', self::LISTS, $this->both());

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('house_observation', $closure['scope']);
        self::assertSame(
            ['subject' => 'Ledger', 'seq' => $seq, 'capability' => ['operations' => 2, 'exercised' => 'unjudged']],
            $closure['derivedFrom']['observation'],
            'the house read the declarations and called nothing — whoever reads the verdict sees that',
        );
    }

    public function testTheOneLeftUnfilledIsNamed(): void
    {
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $this->scaffold('ListAccounts', self::LISTS, $this->both());
        $seq = $this->fill('OpenAccount', self::OPEN, $this->both());

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame(["the house observed «Ledger» declaring 2 operations, 1 of them still the scaffold «make» landed (seq {$seq}): ledger:list — fill it with implement"], $this->seen($closure));
    }

    /**
     * The shape measured: the resident plans with todos, closes them with accepted evidence, and every class it
     * wrote in a trial was an obligation no call could meet.
     */
    public function testWithTodosTheSameWorkClosesAndWhatNeverLandedStopsBinding(): void
    {
        $this->workPlannedWithATodo(withTheReceipt: false);
        $before = $this->verdict();
        self::assertFalse($before['verified'], 'the control: this is what the published house answered');
        self::assertContains('artifact OpenAccount has no current verification', $before['reasons']);
        self::assertContains('artifact ListAccounts has no current verification', $before['reasons']);

        $this->setUp();
        $this->workPlannedWithATodo(withTheReceipt: true);
        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('recorded_work_and_house_observation', $closure['scope']);
        self::assertSame(['operations' => 2, 'exercised' => 'unjudged'], $closure['derivedFrom']['observation']['capability']);
    }

    public function testWithTodosAScaffoldLeftStandingIsSaidToo(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'Build Ledger', TodoStatus::Pending));
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $seq = $this->scaffold('ListAccounts', self::LISTS, $this->both());
        $this->store->completeTodo('s', 't1', Evidence::operationOk('e1', 'sandbox_promote'));

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("the house observed «Ledger» declaring 2 operations, 2 of them still the scaffold «make» landed (seq {$seq}): ledger:open, ledger:list — fill them with implement", $closure['reasons'], 'closing the todos does not make what the house saw vanish');
    }

    public function testAGoalThatDoesNotNameItIsNotClosedByIt(): void
    {
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('s', 'Build the blog.', AutonomyMode::Auto);
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $seq = $this->fill('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'any promotion that touches a plugin with operations would close any goal');
        self::assertContains("the house observed «Ledger» declared (seq {$seq}), a subject the goal does not name", $closure['reasons']);
    }

    public function testAGoalThatWritesARouteIsClosedByThatRouteAlone(): void
    {
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('s', 'Build a plugin named Ledger and serve GET /ledger.', AutonomyMode::Auto);
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $seq = $this->fill('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified'], 'a capability named like the route is not the route');
        self::assertContains("the house observed «Ledger» declared (seq {$seq}), and the goal writes «GET /ledger»: only a route the goal writes closes it", $closure['reasons']);
    }

    public function testTheLastThingTheHouseSawOfItDecides(): void
    {
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $this->fill('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        self::assertTrue($this->verdict()['verified'], 'whole');

        $seq = $this->scaffold('ListAccounts', self::LISTS, $this->both());
        $grown = $this->verdict();
        self::assertFalse($grown['verified'], 'a capability that was whole and no longer is, is not closed by what it once was');
        self::assertSame(["the house observed «Ledger» declaring 2 operations, 1 of them still the scaffold «make» landed (seq {$seq}): ledger:list — fill it with implement"], $this->seen($grown));

        $this->fill('ListAccounts', self::LISTS, $this->both());
        self::assertTrue($this->verdict()['verified'], 'and whole again');
    }

    public function testAnotherMakeDoesNotTakeAScaffoldDownAndAnotherWriterDoes(): void
    {
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        self::assertFalse($this->verdict()['verified'], 'a scaffold over a scaffold is still a scaffold');

        $this->land('edit', ['path' => self::OPEN], [self::OPEN => 'modified'], [$this->operation('ledger:open', self::OPEN)]);
        self::assertTrue($this->verdict()['verified'], 'whoever lands that file after «make» wrote it');
    }

    public function testAnOperationThatDoesNotSayWhatItDoesIsNotWhole(): void
    {
        $seq = $this->land(
            'implement',
            ['plugin' => 'Ledger', 'class' => 'Ledger'],
            ['src/Plugins/Ledger/Ledger.php' => 'modified'],
            [$this->operation('ledger:open', self::OPEN), ['effects' => false] + $this->operation('ledger:audit', null)]
        );

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame(["the house observed «Ledger» declaring 2 operations, 1 of them declaring no effects (seq {$seq}): ledger:audit"], $this->seen($closure));
    }

    public function testAnOperationThatMutatesWithNoScopeIsNotWhole(): void
    {
        $seq = $this->land(
            'implement',
            ['plugin' => 'Ledger', 'class' => 'Ledger'],
            ['src/Plugins/Ledger/Ledger.php' => 'modified'],
            [['scoped' => false] + $this->operation('ledger:open', self::OPEN)]
        );

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame(["the house observed «Ledger» declaring 1 operation, 1 of them mutating with no scope (seq {$seq}): ledger:open"], $this->seen($closure));
    }

    public function testWhatAnEntryDoesNotSayIsNotAssumed(): void
    {
        $silent = ['name' => 'ledger:open', 'file' => self::OPEN, 'mutating' => true, 'scoped' => true];
        $seq = $this->land('implement', ['plugin' => 'Ledger', 'class' => 'OpenAccount'], [self::OPEN => 'modified'], [$silent]);
        self::assertSame(
            ["the house observed «Ledger» declaring 1 operation, 1 of them declaring no effects (seq {$seq}): ledger:open"],
            $this->seen($this->verdict()),
            'a receipt that does not say an operation declares its effects does not get them for free'
        );

        $this->setUp();
        $open = ['name' => 'ledger:open', 'file' => self::OPEN, 'mutating' => true, 'effects' => true];
        $seq = $this->land('implement', ['plugin' => 'Ledger', 'class' => 'OpenAccount'], [self::OPEN => 'modified'], [$open]);
        self::assertSame(
            ["the house observed «Ledger» declaring 1 operation, 1 of them mutating with no scope (seq {$seq}): ledger:open"],
            $this->seen($this->verdict()),
            'nor its scope'
        );
    }

    public function testOnlyAnOperationScaffoldIsAnOperationScaffold(): void
    {
        $this->land('make', ['what' => 'service', 'plugin' => 'Ledger', 'name' => 'OpenAccount'], [self::OPEN => 'added'], [$this->operation('ledger:open', self::OPEN)]);

        self::assertTrue($this->verdict()['verified'], 'a file «make» wrote as something else is not the body that answers «not implemented»');
    }

    public function testACapabilityThatDeclaresNothingIsNotWhole(): void
    {
        $seq = $this->land('implement', ['plugin' => 'Ledger', 'class' => 'Ledger'], ['src/Plugins/Ledger/Ledger.php' => 'modified'], []);

        self::assertSame(["the house observed «Ledger» declaring no operation (seq {$seq})"], $this->seen($this->verdict()));
    }

    public function testWhatATrialDeclaresIsNotTheHouse(): void
    {
        $entry = ['predicate' => 'declared', 'subject' => 'Ledger', 'environment' => ['kind' => 'trial'], 'operations' => [$this->operation('ledger:open', self::OPEN)]];
        $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w0'], (string) json_encode(['ok' => true, 'promoted' => [self::OPEN],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w0', 'environment' => ['kind' => 'house'], 'paths' => [self::OPEN]], 'capabilities' => [$entry]]), mutating: true);
        self::assertFalse($this->verdict()['verified'], 'an entry that does not declare the house is not observed in it');

        $this->setUp();
        $this->store->recordToolCall('s', 'implement', ['plugin' => 'Ledger', 'class' => 'OpenAccount'], (string) json_encode(['ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1',
            'changed' => [self::OPEN => 'modified'], 'capabilities' => [['environment' => ['kind' => 'house']] + $entry]]), mutating: true);
        self::assertFalse($this->verdict()['verified'], 'a receipt inside the result of a rehearsal is the rehearsal speaking');
    }

    public function testACapabilityThatIsNotWholeStopsTheClosureThoughARouteAnswers(): void
    {
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('s', 'Build a plugin named Ledger, with a ledger page.', AutonomyMode::Auto);
        $this->trial('make', ['what' => 'operation', 'plugin' => 'Ledger', 'name' => 'OpenAccount'], [self::OPEN => 'added']);
        $this->promote([self::OPEN], [$this->operation('ledger:open', self::OPEN)], [['predicate' => 'served', 'route' => 'GET /ledger', 'subject' => '/ledger',
            'status' => 200, 'environment' => ['kind' => 'house'], 'servedAt' => '/ledger', 'bytes' => 9, 'sha256' => str_repeat('b', 64)]]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertStringContainsString('still the scaffold «make» landed', implode('; ', $closure['reasons']));
    }

    public function testAChangeAfterTheHouseLookedUnverifiesIt(): void
    {
        $this->scaffold('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $seen = $this->fill('OpenAccount', self::OPEN, [$this->operation('ledger:open', self::OPEN)]);
        $this->trial('edit', ['path' => 'config/app.php'], ['config/app.php' => 'modified']);
        $changed = $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w' . $this->trials], (string) json_encode(['ok' => true, 'promoted' => ['config/app.php'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w' . $this->trials, 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => 'w' . $this->trials], 'paths' => ['config/app.php']]]), mutating: true);

        self::assertSame(["the house changed at seq {$changed} after its last observation (seq {$seen})"], $this->seen($this->verdict()));
    }

    private function workPlannedWithATodo(bool $withTheReceipt): void
    {
        $this->store->setTodo('s', new Todo('t1', 'Build Ledger', TodoStatus::Pending));
        foreach (['OpenAccount' => self::OPEN, 'ListAccounts' => self::LISTS] as $class => $file) {
            $this->trial('make', ['what' => 'operation', 'plugin' => 'Ledger', 'name' => $class], [$file => 'added']);
            $this->promote([$file], $withTheReceipt ? $this->both() : null);
            $this->trial('implement', ['plugin' => 'Ledger', 'class' => $class], [$file => 'modified']);
            $this->promote([$file], $withTheReceipt ? $this->both() : null);
        }
        $this->store->completeTodo('s', 't1', Evidence::operationOk('e1', 'sandbox_promote'));
    }

    /** `make what=operation` rehearsed and promoted; the promotion's seq. */
    private function scaffold(string $class, string $file, array $operations): int
    {
        return $this->land('make', ['what' => 'operation', 'plugin' => 'Ledger', 'name' => $class], [$file => 'added'], $operations);
    }

    /** `implement` rehearsed and promoted; the promotion's seq. */
    private function fill(string $class, string $file, array $operations): int
    {
        return $this->land('implement', ['plugin' => 'Ledger', 'class' => $class], [$file => 'modified'], $operations);
    }

    /**
     * One writer rehearsed in its own trial, then that trial promoted with what the house read of the capability.
     *
     * @param array<string, mixed>       $arguments
     * @param array<string, string>      $changed
     * @param list<array<string, mixed>> $operations
     */
    private function land(string $tool, array $arguments, array $changed, array $operations): int
    {
        $this->trial($tool, $arguments, $changed);

        return $this->promote(array_keys($changed), $operations);
    }

    /**
     * @param array<string, mixed>  $arguments
     * @param array<string, string> $changed
     */
    private function trial(string $tool, array $arguments, array $changed): void
    {
        $this->store->recordToolCall('s', $tool, $arguments, (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w' . ++$this->trials, 'changed' => $changed, 'output' => ['ok' => true],
        ]), mutating: true);
    }

    /**
     * The promotion of the last trial, as the house records it — `$operations` null for a receipt from before the house
     * read capabilities.
     *
     * @param list<string>                    $paths
     * @param list<array<string, mixed>>|null $operations
     * @param list<array<string, mixed>>      $observed
     */
    private function promote(array $paths, ?array $operations, array $observed = []): int
    {
        $workspace = 'w' . $this->trials;

        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => $workspace], (string) json_encode([
            'ok' => true,
            'promoted' => $paths,
            'evidence' => ['predicate' => 'promoted', 'subject' => $workspace, 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => $workspace], 'paths' => $paths],
            ...($observed !== [] ? ['observed' => $observed] : []),
            ...($operations !== null ? ['capabilities' => [['predicate' => 'declared', 'subject' => 'Ledger', 'environment' => ['kind' => 'house'], 'operations' => $operations]]] : []),
        ]), mutating: true);
    }

    /** @return list<array<string, mixed>> */
    private function both(): array
    {
        return [$this->operation('ledger:open', self::OPEN), ['mutating' => false] + $this->operation('ledger:list', self::LISTS)];
    }

    /** @return array<string, mixed> */
    private function operation(string $name, ?string $file): array
    {
        return ['name' => $name, 'file' => $file, 'mutating' => true, 'effects' => true, 'scoped' => true];
    }

    /**
     * What the verdict says the HOUSE saw — beside what it says of the session's own record, which a house that did
     * not derive its closure still asks for («artifact … has no current verification»).
     *
     * @param array<string, mixed> $closure
     *
     * @return list<string>
     */
    private function seen(array $closure): array
    {
        return array_values(array_filter($closure['reasons'], static fn (string $why): bool => str_starts_with($why, 'the house ')));
    }

    /** @return array<string, mixed> */
    private function verdict(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $this->store->facts('s'), $this->store->stream('s'));
    }
}
