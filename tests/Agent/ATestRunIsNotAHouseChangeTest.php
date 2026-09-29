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

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\HouseObservedClosure;
use Milpa\AppRuntime\Agent\LastingCalls;
use Milpa\AppRuntime\Agent\LegClosure;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Console\UnsignedTerminal;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A green test run is not a change to the house (greenhouse decisions/0523).
 *
 * Measured (evidence/1050, legs 4–13): the resident promoted `BlogRouteTest`, the house observed `GET /blog` → 200,
 * and then the resident ran that test, green, three times. Every succeeded mutating call counted as a change, `test`
 * is mutating, so the observation went stale and the closure stayed `verified: false` — the same stream without the
 * three test calls closes `verified: true` on /blog. A change is now what the operation's OWN declaration says lasts,
 * the line the terminal's unsigned door draws ({@see UnsignedTerminal::lasts()}, decisions/0522).
 *
 * @guards a real write after the observation still stales it; an undeclared operation and an unknown tool still count
 *
 * @refuses a call whose operation declares a `none` or `ephemeral` mutation (a test run), or a declared dry run, as a
 *          change to the house
 *
 * @subject-in milpa/app-runtime
 */
final class ATestRunIsNotAHouseChangeTest extends TestCase
{
    private const GOAL = 'Build the blog: a plugin named Blog that serves GET /blog to anonymous visitors.';

    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', self::GOAL);
    }

    public function testThe1050ShapeATestRunAfterTheObservationKeepsTheClosure(): void
    {
        $blog = $this->promote([$this->served('/blog')]);
        $this->testRun();
        $this->testRun();
        $this->testRun();

        $closure = $this->verdict($this->lasting());

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => '/blog', 'seq' => $blog], $closure['derivedFrom']['observation'] ?? null);
        self::assertSame($blog, $closure['derivedFrom']['lastChangeSeq'] ?? null, 'the test runs are not the last change');
    }

    /** The positive control: read by the recorded flag alone — what 1050's house did — the same stream stays open. */
    public function testReadByTheRecordedFlagAloneTheSameStreamStaysOpenAsIn1050(): void
    {
        $blog = $this->promote([$this->served('/blog')]);
        $last = $this->testRun();

        $closure = $this->verdict(null);

        self::assertFalse($closure['verified']);
        self::assertContains("the house changed at seq {$last} after its last observation (seq {$blog})", $closure['reasons']);
    }

    public function testARealWriteAfterTheObservationStillStalesIt(): void
    {
        $blog = $this->promote([$this->served('/blog')]);
        $this->testRun();
        $write = $this->store->recordToolCall('s', 'plugins_register', ['class' => 'Blog'], '{"ok":true}', mutating: true);
        $this->testRun();

        $closure = $this->verdict($this->lasting());

        self::assertFalse($closure['verified']);
        self::assertContains("the house changed at seq {$write} after its last observation (seq {$blog})", $closure['reasons']);
    }

    public function testAPromotionWithoutAnObservationAfterATestRunStillStalesIt(): void
    {
        $blog = $this->promote([$this->served('/blog')]);
        $this->testRun();
        $write = $this->promote([]);

        $closure = $this->verdict($this->lasting());

        self::assertFalse($closure['verified']);
        self::assertContains("the house changed at seq {$write} after its last observation (seq {$blog})", $closure['reasons']);
    }

    public function testAnOperationThatNeverDeclaredItsEffectsCounts(): void
    {
        $this->promote([$this->served('/blog')]);
        $undeclared = $this->store->recordToolCall('s', 'legacy_write', [], '{"ok":true}', mutating: true);

        $house = HouseObservedClosure::of($this->store->stream('s'), $this->store->facts('s'), null, $this->lasting());

        self::assertFalse($house['derived']);
        self::assertSame($undeclared, $house['lastChangeSeq']);
    }

    public function testAToolTheCatalogueDoesNotDeclareKeepsItsRecordedFlag(): void
    {
        $this->promote([$this->served('/blog')]);
        $unknown = $this->store->recordToolCall('s', 'somebody_elses_tool', [], '{"ok":true}', mutating: true);

        $house = HouseObservedClosure::of($this->store->stream('s'), $this->store->facts('s'), null, $this->lasting());

        self::assertSame($unknown, $house['lastChangeSeq']);
    }

    public function testADeclaredDryRunOfALastingOperationIsNotAChange(): void
    {
        $blog = $this->promote([$this->served('/blog')]);
        $this->store->recordToolCall('s', 'plugins_register', ['class' => 'Blog', 'dry_run' => true], '{"ok":true}', mutating: true);
        $wet = $this->store->recordToolCall('s', 'plugins_register', ['class' => 'Blog', 'dry_run' => false], '{"ok":true}', mutating: true);

        $house = HouseObservedClosure::of($this->store->stream('s'), $this->store->facts('s'), null, $this->lasting());

        self::assertSame($wet, $house['lastChangeSeq'], 'dry_run: false is a write');
        self::assertNotContains($blog + 1, $house['landed'], 'the dry run did not land');
    }

    public function testTheDeclarationOnlySubtractsACallRecordedAsNotMutatingNeverBecomesAChange(): void
    {
        $blog = $this->promote([$this->served('/blog')]);
        $this->store->recordToolCall('s', 'plugins_register', ['class' => 'Blog'], '{"ok":true}', mutating: false);

        $house = HouseObservedClosure::of($this->store->stream('s'), $this->store->facts('s'), null, $this->lasting());

        self::assertTrue($house['derived']);
        self::assertSame([$blog], $house['landed']);
    }

    public function testTheClassifierIsTheTerminalsOwnLine(): void
    {
        $lasting = $this->lasting();
        foreach ($this->catalogue() as $operation) {
            foreach ([[], ['dry_run' => true]] as $arguments) {
                self::assertSame(UnsignedTerminal::lasts($operation, $arguments), $lasting(str_replace(':', '_', $operation->name), $arguments), $operation->name);
                self::assertSame(UnsignedTerminal::lasts($operation, $arguments), $lasting($operation->name, $arguments), $operation->name);
            }
        }
        self::assertNull($lasting('not_in_the_catalogue', []));
    }

    public function testTheEpilogueOpensOnTheClosureATestRunNoLongerTakesBack(): void
    {
        $probe = new SessionProgressProbe($this->events, 's', $this->lasting());
        $this->promote([$this->served('/blog')]);
        $this->testRun();
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));

        self::assertArrayHasKey('epilogue', $probe->afterStep(1) ?? []);
    }

    public function testWithoutTheHousesReadingTheEpilogueStaysClosedAsIn1050(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        $this->promote([$this->served('/blog')]);
        $this->testRun();
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));

        self::assertArrayNotHasKey('epilogue', $probe->afterStep(1) ?? []);
    }

    /** The claim door (0517's deferral): it reads the leg's closure, so a test run no longer hides the house's close. */
    public function testTheClaimDoorSaysTheHouseClosedItAfterATestRun(): void
    {
        $this->promote([$this->served('/blog')]);
        $this->testRun();

        $refused = $this->claim($this->lasting());

        self::assertStringContainsString('the house already closed it on its own observation of «/blog»', (string) $refused['error']);
    }

    public function testTheClaimDoorAgreesWithTheLegsClosureOnEveryStream(): void
    {
        $streams = [
            'observed, then a test run' => function (): void {
                $this->promote([$this->served('/blog')]);
                $this->testRun();
            },
            'observed, then a write' => function (): void {
                $this->promote([$this->served('/blog')]);
                $this->promote([]);
            },
            'nothing observed' => function (): void {
                $this->testRun();
            },
        ];
        foreach ($streams as $name => $build) {
            $this->setUp();
            $build();
            $session = $this->store->load('s');
            self::assertNotNull($session);
            $closed = (LegClosure::betweenSteps($session, $this->store->stream('s'), $this->lasting())['verified'] ?? false) === true;

            $refused = $this->claim($this->lasting());

            self::assertSame($closed, str_contains((string) $refused['error'], 'the house already closed it'), $name);
        }
    }

    /** The door without the house's reading judges as 1050's did: the test run hides the close. */
    public function testTheClaimDoorWithoutTheHousesReadingDoesNotSeeTheClose(): void
    {
        $this->promote([$this->served('/blog')]);
        $this->testRun();

        self::assertStringNotContainsString('the house already closed it', (string) $this->claim(null)['error']);
    }

    /**
     * @param (\Closure(string, array<string, mixed>): ?bool)|null $lasting
     *
     * @return array<string, mixed>
     */
    private function claim(?\Closure $lasting): array
    {
        foreach ((new SessionBookkeeping($this->store, 's', $this->events, $lasting))->operations() as $operation) {
            if ($operation->name === 'work:claim-verified') {
                return ($operation->handler)(['todo' => 't1', 'kind' => 'screen-served', 'reference' => '/blog']);
            }
        }
        self::fail('work:claim-verified is not offered');
    }

    /** @return \Closure(string, array<string, mixed>): ?bool */
    private function lasting(): \Closure
    {
        return LastingCalls::of($this->catalogue());
    }

    /** @return list<Operation> */
    private function catalogue(): array
    {
        $write = new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Executable);

        return [
            new Operation(name: 'sandbox:promote', description: 'promote', handler: static fn (): array => [], mutating: true, effects: $write),
            new Operation(
                name: 'plugins:register',
                description: 'register',
                handler: static fn (): array => [],
                mutating: true,
                effects: $write,
                inputSchema: ['type' => 'object', 'properties' => ['class' => ['type' => 'string'], 'dry_run' => ['type' => 'boolean']]]
            ),
            // The declaration devtools ships for `test` (decisions/0523): it runs the app's code and keeps nothing.
            new Operation(
                name: 'test',
                description: 'test',
                handler: static fn (): array => [],
                mutating: true,
                effects: new EffectProfile(Mutation::Ephemeral, Externality::Public, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Data)
            ),
            new Operation(name: 'legacy:write', description: 'undeclared', handler: static fn (): array => [], mutating: true),
        ];
    }

    private function testRun(): int
    {
        return $this->store->recordToolCall('s', 'test', ['filter' => 'BlogRouteTest'], (string) json_encode(
            ['ok' => true, 'ran' => true, 'tests' => 2, 'assertions' => 10, 'failures' => 0, 'errors' => 0],
        ), mutating: true);
    }

    /** @param list<array<string, mixed>> $observed */
    private function promote(array $observed): int
    {
        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'wabc'], (string) json_encode([
            'ok' => true,
            'promoted' => ['src/Plugins/Blog/BlogController.php'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'wabc', 'environment' => ['kind' => 'house']],
            'observed' => $observed,
        ]), mutating: true);
    }

    /** @return array<string, mixed> */
    private function served(string $path): array
    {
        return ['predicate' => 'served', 'route' => "GET {$path}", 'subject' => $path, 'status' => 200,
            'environment' => ['kind' => 'house'], 'servedAt' => $path, 'bytes' => 42, 'sha256' => str_repeat('a', 64)];
    }

    /**
     * @param (\Closure(string, array<string, mixed>): ?bool)|null $lasting
     *
     * @return array<string, mixed>
     */
    private function verdict(?\Closure $lasting): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $this->store->facts('s'), $this->store->stream('s'), $lasting);
    }
}
