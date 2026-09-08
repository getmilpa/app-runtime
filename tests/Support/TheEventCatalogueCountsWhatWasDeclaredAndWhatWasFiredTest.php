<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Support\Events;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Command\Operation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * F1 and F2 of `events:catalogue` (greenhouse decisions/0228): the house counts what its emitters DECLARED
 * against what the dispatcher really FIRED, and says so when it cannot count at all.
 *
 * The three states a row can be in are the whole finding, and each is exercised against the REAL
 * `Milpa\Eventing\EventDispatcher` rather than a mock of it — the fold reads a dispatcher's own memory, so a
 * fake memory would be the instrument answering the question it was built to ask:
 *
 * - declared and never fired — the event this process did not reach;
 * - declared and fired — the ordinary case;
 * - fired without a declaration — DEBT WITH A NAME, the row that must not be silently dropped.
 *
 * And the gap itself is named rather than answered: a dispatcher that does not implement
 * {@see DeclaredEvents} keeps no such memory, so the catalogue refuses in words naming the class and the
 * interface, never an empty list that would read as «this app dispatches no events».
 */
#[CoversClass(Events::class)]
final class TheEventCatalogueCountsWhatWasDeclaredAndWhatWasFiredTest extends TestCase
{
    /** The two events the fixture emitter declares — one of them is never dispatched, on purpose. */
    private const DECLARED = ['fixture.opened', 'fixture.closed'];

    #[Test]
    public function a_declared_event_that_never_fired_a_declared_one_that_did_and_an_undeclared_dispatch_are_three_distinct_rows(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->declare(...FixtureEmitter::declarations());
        $container = $this->containerWith($dispatcher);

        // Only ONE of the two declared events is fired, through the emitter's own code path.
        (new FixtureEmitter($dispatcher))->open();

        // CONTROL, before the undeclared dispatch: `foo.bar` is not in the catalogue at all. The row cannot
        // be a fixture of the fold — it appears because the dispatcher saw the name, or it does not appear.
        $before = Events::catalogue($container);
        self::assertTrue($before['ok']);
        self::assertSame(['fixture.closed', 'fixture.opened'], array_column($before['events'], 'name'));
        self::assertSame(['declared' => 2, 'dispatched' => 1, 'undeclared' => 0], $before['counts']);

        $dispatcher->dispatch('foo.bar', ['event' => new \stdClass()]);

        $after = Events::catalogue($container);
        self::assertTrue($after['ok']);
        self::assertSame(EventDispatcher::class, $after['dispatcher']);
        // Sorted by name, and the undeclared one is IN the list — not hidden because nobody declared it.
        self::assertSame(['fixture.closed', 'fixture.opened', 'foo.bar'], array_column($after['events'], 'name'));
        self::assertSame(['declared' => 2, 'dispatched' => 2, 'undeclared' => 1], $after['counts']);

        $byName = [];
        foreach ($after['events'] as $row) {
            $byName[$row['name']] = $row;
        }

        // DECLARED, NEVER FIRED — this process did not reach it.
        self::assertTrue($byName['fixture.closed']['declared']);
        self::assertFalse($byName['fixture.closed']['dispatched']);
        self::assertSame(FixtureEmitter::class, $byName['fixture.closed']['dispatchedBy']);

        // DECLARED AND FIRED — and the row carries the declaration verbatim, subject included.
        self::assertTrue($byName['fixture.opened']['declared']);
        self::assertTrue($byName['fixture.opened']['dispatched']);
        self::assertSame(FixtureEmitter::class, $byName['fixture.opened']['dispatchedBy']);
        self::assertSame('The fixture emitter opened, once.', $byName['fixture.opened']['when']);
        self::assertSame(
            ['key' => 'event', 'type' => FixtureOpened::class, 'mutable' => false, 'interceptable' => true],
            $byName['fixture.opened']['subject'],
        );

        // FIRED WITHOUT A DECLARATION — debt with a name: everything only a declaration could say is null,
        // and nothing is invented to fill the row out.
        self::assertFalse($byName['foo.bar']['declared']);
        self::assertTrue($byName['foo.bar']['dispatched']);
        self::assertNull($byName['foo.bar']['dispatchedBy']);
        self::assertNull($byName['foo.bar']['when']);
        self::assertNull($byName['foo.bar']['subject']);
    }

    #[Test]
    public function the_declaration_matches_the_payload_the_dispatch_really_carries(): void
    {
        // A declaration is only worth reading if it describes THIS dispatch: the subject key and type are
        // checked against what the dispatcher actually saw, not against the declaration's own words.
        $seen = null;
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe('fixture.opened', static function (string $name, array $payload) use (&$seen): void {
            $seen = $payload;
        });
        (new FixtureEmitter($dispatcher))->open();

        $declaration = null;
        foreach (FixtureEmitter::declarations() as $candidate) {
            if ($candidate->name === 'fixture.opened') {
                $declaration = $candidate;
            }
        }

        self::assertInstanceOf(EventDeclaration::class, $declaration);
        self::assertIsArray($seen);
        self::assertArrayHasKey($declaration->subjectKey, $seen, 'the declared subject key is the key the dispatch really uses');
        self::assertInstanceOf((string) $declaration->subjectType, $seen[$declaration->subjectKey]);
        self::assertSame($declaration->interceptable, isset($seen['slot']), 'interceptable is declared only when the payload really carries a slot');
    }

    #[Test]
    public function a_dispatcher_that_keeps_no_record_is_named_instead_of_answered_with_an_empty_list(): void
    {
        // F2: a plain dispatcher — it implements the dispatch contract and nothing else, which is exactly
        // what an app on milpa/events < 0.4 holds.
        $answer = Events::catalogue($this->containerWith(new PlainDispatcher()));

        self::assertFalse($answer['ok']);
        self::assertArrayNotHasKey('events', $answer, 'an empty list would read as «this app dispatches no events»');
        self::assertSame(PlainDispatcher::class, $answer['dispatcher']);
        self::assertStringContainsString(PlainDispatcher::class, $answer['error']);
        self::assertStringContainsString(DeclaredEvents::class, $answer['error'], 'the reason names the interface the app lacks');
        self::assertStringContainsString('milpa/events >= 0.4', $answer['error'], 'and where the app gets one that has it');

        // POSITIVE CONTROL: the same container, the same call, with a dispatcher that DOES keep the record —
        // the refusal is about the dispatcher, not about the fold being unable to answer at all.
        $ok = Events::catalogue($this->containerWith(new EventDispatcher(new NullLogger())));
        self::assertTrue($ok['ok']);
        self::assertSame([], $ok['events']);
    }

    #[Test]
    public function an_app_with_no_dispatcher_at_all_fails_closed_in_words(): void
    {
        $answer = Events::catalogue(new DIContainer());

        self::assertFalse($answer['ok']);
        self::assertStringContainsString('no event dispatcher', $answer['error']);
    }

    #[Test]
    public function the_first_declaration_of_a_name_wins_and_prints_one_row(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->declare(...FixtureEmitter::declarations());
        // A second emitter declaring the SAME name: one row, the first declaration's words.
        $dispatcher->declare(new EventDeclaration(
            name: 'fixture.opened',
            dispatchedBy: PlainDispatcher::class,
            when: 'A second declarer got there later.',
        ));

        $answer = Events::catalogue($this->containerWith($dispatcher));

        self::assertSame(['fixture.closed', 'fixture.opened'], array_column($answer['events'], 'name'));
        self::assertSame(2, $answer['counts']['declared']);
        self::assertSame('The fixture emitter opened, once.', $answer['events'][1]['when']);

        // AND THE FOLD UPHOLDS IT ITSELF, not by trusting the dispatcher to. `DeclaredEvents` states the rule
        // but cannot enforce it, and this fold reads the INTERFACE — so a conformant dispatcher that kept both
        // declarations must still print one row, with the first declarer's words.
        $naive = new NaivelyDeclaringDispatcher();
        $naive->declare(...FixtureEmitter::declarations());
        $naive->declare(new EventDeclaration(
            name: FixtureEmitter::OPENED,
            dispatchedBy: PlainDispatcher::class,
            when: 'A second declarer got there later.',
        ));
        self::assertCount(3, $naive->declared(), 'the fixture really did keep both — the fold, not the dispatcher, is what is under test');

        $folded = Events::catalogue($this->containerWith($naive));
        self::assertSame(['fixture.closed', 'fixture.opened'], array_column($folded['events'], 'name'));
        self::assertSame(2, $folded['counts']['declared']);
        self::assertSame('The fixture emitter opened, once.', $folded['events'][1]['when']);
        self::assertSame(FixtureEmitter::class, $folded['events'][1]['dispatchedBy']);
    }

    #[Test]
    public function the_compact_summary_carries_the_same_counts_and_the_same_refusal(): void
    {
        // `house:context` prints the compact shape; two doors, one fact — so the counts cannot differ.
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->declare(...FixtureEmitter::declarations());
        (new FixtureEmitter($dispatcher))->open();
        $dispatcher->dispatch('foo.bar');
        $container = $this->containerWith($dispatcher);

        $catalogue = Events::catalogue($container);
        $summary = Events::summary($container);

        self::assertSame($catalogue['counts'], $summary['counts']);
        self::assertSame(array_column($catalogue['events'], 'name'), $summary['names']);
        self::assertSame($catalogue['dispatcher'], $summary['dispatcher']);

        // And the section refuses exactly as the catalogue does: zeros with the right shape would be a lie.
        $refused = Events::summary($this->containerWith(new PlainDispatcher()));
        self::assertFalse($refused['ok']);
        self::assertStringContainsString(DeclaredEvents::class, $refused['error']);
    }

    #[Test]
    public function the_operation_is_projected_to_the_same_surfaces_its_neighbours_are(): void
    {
        $byName = [];
        foreach ((new AgentOperations(new DIContainer()))->operations() as $operation) {
            $byName[$operation->name] = $operation;
        }

        self::assertArrayHasKey('events:catalogue', $byName, 'the catalogue is offered by the same provider as routes:list');
        $operation = $byName['events:catalogue'];
        self::assertInstanceOf(Operation::class, $operation);

        // Like `routes:list` and `house:context`: read-only, on the three surfaces, never over http.
        self::assertTrue($operation->supportsSurface('cli'));
        self::assertTrue($operation->supportsSurface('tui'));
        self::assertTrue($operation->supportsSurface('mcp'));
        self::assertFalse($operation->supportsSurface('http'), 'the answer names this app\'s classes: not for a route that answers without a principal');
        self::assertSame($byName['routes:list']->surfaces, $operation->surfaces);
        self::assertFalse($operation->mutating);
        self::assertNotNull($operation->effects);
        self::assertSame('none', $operation->effects->toArray()['mutation']);
        self::assertSame('read', $operation->effects->toArray()['authority']);
        self::assertNotNull($operation->observableEvidence);
    }

    #[Test]
    public function the_operation_answers_through_the_same_fold_the_section_uses(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->declare(...FixtureEmitter::declarations());
        $container = $this->containerWith($dispatcher);

        self::assertSame(
            Events::catalogue($container),
            (new AgentOperations($container))->eventsCatalogue(),
            'the operation is the fold, not a second derivation of it',
        );
    }

    private function containerWith(MilpaEventDispatcherInterface $dispatcher): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(MilpaEventDispatcherInterface::class, $dispatcher);

        return $container;
    }
}

/** The subject `fixture.opened` carries — a payload type the declaration names by FQCN. */
final class FixtureOpened
{
}

/**
 * An emitter that declares the events it dispatches, built from the SAME constants the dispatch uses.
 *
 * The constants are the point: a declaration that retyped the string could drift from the dispatch beside it
 * and stay green forever (greenhouse decisions/0228).
 */
final class FixtureEmitter
{
    /** The name dispatched when the fixture opens. */
    public const OPENED = 'fixture.opened';

    /** The name declared and never dispatched in this fixture — the row that must show `dispatched: false`. */
    public const CLOSED = 'fixture.closed';

    public function __construct(private readonly MilpaEventDispatcherInterface $dispatcher)
    {
    }

    /**
     * Everything this emitter dispatches, one declaration per name.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new EventDeclaration(
                name: self::OPENED,
                dispatchedBy: self::class,
                when: 'The fixture emitter opened, once.',
                subjectKey: 'event',
                subjectType: FixtureOpened::class,
                interceptable: true,
            ),
            new EventDeclaration(
                name: self::CLOSED,
                dispatchedBy: self::class,
                when: 'The fixture emitter closed — never, in this fixture.',
                subjectKey: 'event',
                subjectType: FixtureOpened::class,
            ),
        ];
    }

    /** Dispatches the opened event, with the very constant its declaration is built from. */
    public function open(): void
    {
        $this->dispatcher->dispatch(self::OPENED, ['event' => new FixtureOpened(), 'slot' => new \Milpa\Events\InterceptionSlot()]);
    }
}

/**
 * A dispatcher that keeps its declarations WITHOUT applying «the first of a name wins».
 *
 * The interface states that rule; nothing in it can enforce it. This fixture is the conformant
 * implementation that did not, so the fold's own handling of a repeated name is what is measured.
 */
final class NaivelyDeclaringDispatcher implements MilpaEventDispatcherInterface, DeclaredEvents
{
    /** @var list<EventDeclaration> every declaration, repeats included */
    private array $declarations = [];

    /** @var list<string> every name dispatched, first occurrence first */
    private array $fired = [];

    /** Keeps every declaration handed to it, repeated names and all. */
    public function declare(EventDeclaration ...$events): void
    {
        foreach ($events as $event) {
            $this->declarations[] = $event;
        }
    }

    /**
     * Everything declared, repeats included.
     *
     * @return list<EventDeclaration>
     */
    public function declared(): array
    {
        return $this->declarations;
    }

    /**
     * Every name dispatched in this process.
     *
     * @return list<string>
     */
    public function dispatched(): array
    {
        return $this->fired;
    }

    /** Remembers the name and invokes nothing: this fixture is asked, never listened to. */
    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
        if (!\in_array($eventName, $this->fired, true)) {
            $this->fired[] = $eventName;
        }
    }

    /** Records nothing. */
    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
    }

    /**
     * No subscriber, ever.
     *
     * @return array<callable>
     */
    public function getSubscribers(string $eventName): array
    {
        return [];
    }

    /** Never. */
    public function hasSubscribers(string $eventName): bool
    {
        return false;
    }
}

/** A dispatcher that implements the dispatch contract and NOTHING else — no memory to ask. */
final class PlainDispatcher implements MilpaEventDispatcherInterface
{
    /** Dispatches nowhere: this fixture exists to be asked what it cannot answer. */
    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
    }

    /** Records nothing. */
    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
    }

    /**
     * No subscriber, ever.
     *
     * @return array<callable>
     */
    public function getSubscribers(string $eventName): array
    {
        return [];
    }

    /** Never. */
    public function hasSubscribers(string $eventName): bool
    {
        return false;
    }
}
