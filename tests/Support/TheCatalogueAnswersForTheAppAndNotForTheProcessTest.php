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

use Milpa\AppRuntime\Support\Events;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\Interfaces\Event\DeclaresEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * F5 of greenhouse decisions/0228: the catalogue answers for the APP, not for the process that asked.
 *
 * An emitter declares its events WHEN IT IS CONSTRUCTED, and a CLI process constructs almost none of them —
 * measured on cattle, the catalogue knew seven of the family's twenty-four names. The package itself can
 * speak for the emitter nobody built: it names a {@see DeclaresEvents} holder in its manifest
 * (`extra.milpa.events`), and the fold reads those manifests from `vendor/composer/installed.json`.
 *
 * Every test here runs against a REAL app root written to a temp directory, with a REAL
 * `Milpa\Eventing\EventDispatcher`: the manifest is a file on disk and the declaration really lands in the
 * dispatcher, because a fixture that faked either would be the instrument answering its own question.
 *
 * - a — a manifest naming a holder whose emitter NOBODY constructs puts its events in the catalogue,
 *   `declared: true`. CONTROL: without the manifest entry those names are absent — the fold does not invent.
 * - b — a manifest naming a class that does not exist, and one naming a class that is not a holder, come
 *   back as warnings carrying package and class; `ok` stays true and no row is invented for them.
 * - c — an emitter that ALSO declared at construction is not duplicated, and its declaration wins.
 * - d — an app root without `vendor/composer/installed.json` still answers from the dispatcher alone.
 */
#[CoversClass(Events::class)]
final class TheCatalogueAnswersForTheAppAndNotForTheProcessTest extends TestCase
{
    /** The app root each test writes its manifests into. */
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-manifest-events-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/vendor/composer', 0o777, true);
        UnbuiltEmitter::$constructions = 0;
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->root . '/vendor/composer/installed.json',
            $this->root . '/composer.json',
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ([$this->root . '/vendor/composer', $this->root . '/vendor', $this->root] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    #[Test]
    public function a_manifest_declares_for_an_emitter_this_process_never_constructs(): void
    {
        // CONTROL FIRST: the same app, the same dispatcher, with an installed.json that names NOBODY. The
        // holder's class is loaded and its events are still absent — the fold reads manifests, it does not
        // go looking for holders it was not told about.
        $this->writeInstalled([['name' => 'acme/silent']]);
        $control = Events::catalogue($this->containerWithDispatcher(), $this->root);

        self::assertTrue($control['ok']);
        self::assertSame([], $control['events'], 'nothing declared and nothing dispatched: the fold invents no row');
        self::assertSame([], $control['warnings']);

        // NOW the manifest names the holder. Nothing else changes.
        $this->writeInstalled([[
            'name' => 'acme/unbuilt',
            'extra' => ['milpa' => ['events' => [UnbuiltEmitterEvents::class]]],
        ]]);
        $answer = Events::catalogue($this->containerWithDispatcher(), $this->root);

        self::assertTrue($answer['ok']);
        self::assertSame([], $answer['warnings']);
        self::assertSame(['unbuilt.arrived', 'unbuilt.left'], array_column($answer['events'], 'name'));
        self::assertSame(['declared' => 2, 'dispatched' => 0, 'undeclared' => 0], $answer['counts']);

        foreach ($answer['events'] as $row) {
            self::assertTrue($row['declared'], 'the package declared it, though this process built nothing');
            self::assertFalse($row['dispatched']);
            self::assertSame(UnbuiltEmitter::class, $row['dispatchedBy']);
        }
        self::assertSame(
            ['key' => 'event', 'type' => UnbuiltEmitter::class, 'mutable' => false, 'interceptable' => false],
            $answer['events'][0]['subject'],
        );

        // THE WHOLE POINT, asserted rather than assumed: the emitter was never constructed. If the events
        // arrived because something built it, this test would be measuring the old mechanism.
        self::assertSame(0, UnbuiltEmitter::$constructions, 'nobody built the emitter — the manifest spoke for it');
    }

    #[Test]
    public function an_app_declares_its_own_events_in_its_own_composer_json(): void
    {
        // The app is a package too. Its own manifest is read last, so a package stays the authority on its
        // own events and the app adds what IT dispatches.
        $this->writeInstalled([]);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['name' => 'acme/app', 'extra' => ['milpa' => ['events' => UnbuiltEmitterEvents::class]]]),
        );

        $answer = Events::catalogue($this->containerWithDispatcher(), $this->root);

        self::assertTrue($answer['ok']);
        self::assertSame([], $answer['warnings'], 'a single class name instead of a list is still a name, and it resolves');
        self::assertSame(['unbuilt.arrived', 'unbuilt.left'], array_column($answer['events'], 'name'));
    }

    #[Test]
    public function a_manifest_that_names_what_cannot_be_resolved_is_named_never_dropped_and_never_invented(): void
    {
        $this->writeInstalled([
            [
                'name' => 'acme/ghost',
                'extra' => ['milpa' => ['events' => ['Acme\\Ghost\\NoSuchEventsHolder']]],
            ],
            [
                'name' => 'acme/not-a-holder',
                'extra' => ['milpa' => ['events' => [NotAHolder::class]]],
            ],
            [
                'name' => 'acme/nonsense',
                'extra' => ['milpa' => ['events' => [42]]],
            ],
            [
                'name' => 'acme/thrower',
                'extra' => ['milpa' => ['events' => [ThrowingHolder::class]]],
            ],
            // And one that DOES resolve, in the same installed.json: a warning must not stop the pass.
            [
                'name' => 'acme/unbuilt',
                'extra' => ['milpa' => ['events' => [UnbuiltEmitterEvents::class]]],
            ],
        ]);

        $answer = Events::catalogue($this->containerWithDispatcher(), $this->root);

        // `ok` STAYS TRUE: a manifest that cannot be resolved is a gap in the answer, not a broken answer.
        self::assertTrue($answer['ok']);
        // And nothing was invented for the four that failed — only the holder that resolved is in the table.
        self::assertSame(['unbuilt.arrived', 'unbuilt.left'], array_column($answer['events'], 'name'));

        $byPackage = [];
        foreach ($answer['warnings'] as $warning) {
            $byPackage[$warning['package']] = $warning;
        }
        self::assertSame(['acme/ghost', 'acme/not-a-holder', 'acme/nonsense', 'acme/thrower'], array_keys($byPackage));

        // A CLASS THAT DOES NOT EXIST — the package and the class are both named, and the reason says the
        // events are missing, so a reader is not left thinking the catalogue is complete.
        self::assertSame('Acme\\Ghost\\NoSuchEventsHolder', $byPackage['acme/ghost']['class']);
        self::assertStringContainsString('no such class is autoloadable', $byPackage['acme/ghost']['why']);
        self::assertStringContainsString('missing from this catalogue', $byPackage['acme/ghost']['why']);

        // A CLASS THAT EXISTS BUT IS NOT A HOLDER — the same shape, naming the interface it lacks.
        self::assertSame(NotAHolder::class, $byPackage['acme/not-a-holder']['class']);
        self::assertStringContainsString(DeclaresEvents::class, $byPackage['acme/not-a-holder']['why']);
        self::assertStringContainsString('missing from this catalogue', $byPackage['acme/not-a-holder']['why']);

        // AN ENTRY THAT IS NOT EVEN A NAME — said out loud too, with what it was instead.
        self::assertSame('int', $byPackage['acme/nonsense']['class']);
        self::assertStringContainsString('list of class names', $byPackage['acme/nonsense']['why']);

        // A HOLDER THAT THROWS — reported with what it threw, not swallowed by the catch that caught it.
        self::assertSame(ThrowingHolder::class, $byPackage['acme/thrower']['class']);
        self::assertStringContainsString('declarations() failed', $byPackage['acme/thrower']['why']);
        self::assertStringContainsString('this holder cannot list its events', $byPackage['acme/thrower']['why']);
    }

    #[Test]
    public function an_emitter_that_already_declared_is_not_duplicated_and_its_declaration_wins(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        // The emitter IS constructed here, and declares as it always did — with its own words.
        new BuiltEmitter($dispatcher);
        $this->writeInstalled([[
            'name' => 'acme/built',
            'extra' => ['milpa' => ['events' => [BuiltEmitterEvents::class]]],
        ]]);

        $container = new DIContainer();
        $container->registerService(MilpaEventDispatcherInterface::class, $dispatcher);
        $answer = Events::catalogue($container, $this->root);

        self::assertSame(['built.opened'], array_column($answer['events'], 'name'), 'one row, not two');
        self::assertSame(1, $answer['counts']['declared']);
        self::assertSame(
            'Declared by the emitter that was really constructed.',
            $answer['events'][0]['when'],
            'the first declaration wins, and the constructed emitter got there first',
        );

        // IDEMPOTENT: asking twice declares the manifest's copy again and the answer does not move.
        self::assertSame($answer, Events::catalogue($container, $this->root));
    }

    #[Test]
    public function an_app_without_an_installed_json_answers_from_the_dispatcher_alone(): void
    {
        // No manifest of any kind on disk: «I could not know» about packages, not «there is nothing».
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->declare(...BuiltEmitterEvents::declarations());
        $container = new DIContainer();
        $container->registerService(MilpaEventDispatcherInterface::class, $dispatcher);

        $answer = Events::catalogue($container, $this->root);

        self::assertTrue($answer['ok'], 'no crash, and no refusal: the dispatcher is still an authority');
        self::assertSame(['built.opened'], array_column($answer['events'], 'name'));
        self::assertSame([], $answer['warnings'], 'a missing installed.json is not a manifest that failed');
    }

    /**
     * Writes `vendor/composer/installed.json` the way Composer writes it.
     *
     * @param list<array<string, mixed>> $packages
     */
    private function writeInstalled(array $packages): void
    {
        file_put_contents(
            $this->root . '/vendor/composer/installed.json',
            (string) json_encode(['packages' => $packages, 'dev' => true]),
        );
    }

    /** A container holding a real dispatcher that has been told nothing yet. */
    private function containerWithDispatcher(): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(MilpaEventDispatcherInterface::class, new EventDispatcher(new NullLogger()));

        return $container;
    }
}

/**
 * An emitter of the kind a CLI process never builds — and this test asserts it never was.
 *
 * It declares to the dispatcher in its constructor, like every emitter of the family: the counter is what
 * makes «the manifest spoke for it» a measurement instead of a claim.
 */
final class UnbuiltEmitter
{
    /** The name dispatched when this emitter arrives. */
    public const ARRIVED = 'unbuilt.arrived';

    /** The name dispatched when it leaves. */
    public const LEFT = 'unbuilt.left';

    /** How many times this emitter was constructed in the current test. */
    public static int $constructions = 0;

    public function __construct(private readonly MilpaEventDispatcherInterface $dispatcher)
    {
        ++self::$constructions;
        if ($dispatcher instanceof \Milpa\Interfaces\Event\DeclaredEvents) {
            $dispatcher->declare(...UnbuiltEmitterEvents::declarations());
        }
    }

    /** Dispatches the arrival, from the same constant its declaration is built from. */
    public function arrive(): void
    {
        $this->dispatcher->dispatch(self::ARRIVED, ['event' => $this]);
    }
}

/** The holder {@see UnbuiltEmitter}'s package names in its manifest — readable without building the emitter. */
final class UnbuiltEmitterEvents implements DeclaresEvents
{
    /**
     * Both names the emitter dispatches, built from its own constants.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new EventDeclaration(
                name: UnbuiltEmitter::ARRIVED,
                dispatchedBy: UnbuiltEmitter::class,
                when: 'The emitter nobody constructed arrived.',
                subjectKey: 'event',
                subjectType: UnbuiltEmitter::class,
            ),
            new EventDeclaration(
                name: UnbuiltEmitter::LEFT,
                dispatchedBy: UnbuiltEmitter::class,
                when: 'The emitter nobody constructed left.',
                subjectKey: 'event',
                subjectType: UnbuiltEmitter::class,
            ),
        ];
    }
}

/** An emitter that IS constructed, so its declaration is on the dispatcher before any manifest is read. */
final class BuiltEmitter
{
    /** The one name it dispatches. */
    public const OPENED = 'built.opened';

    public function __construct(MilpaEventDispatcherInterface $dispatcher)
    {
        if ($dispatcher instanceof \Milpa\Interfaces\Event\DeclaredEvents) {
            $dispatcher->declare(new EventDeclaration(
                name: self::OPENED,
                dispatchedBy: self::class,
                when: 'Declared by the emitter that was really constructed.',
                subjectKey: 'event',
                subjectType: self::class,
            ));
        }
    }
}

/**
 * The holder for {@see BuiltEmitter}, wording the SAME name differently on purpose.
 *
 * Different words are how «the first declaration wins» becomes observable: whichever text comes back names
 * which of the two declarers got there first.
 */
final class BuiltEmitterEvents implements DeclaresEvents
{
    /**
     * The one name, in the manifest's words.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new EventDeclaration(
                name: BuiltEmitter::OPENED,
                dispatchedBy: BuiltEmitter::class,
                when: 'Declared by the manifest, for an emitter nobody built.',
                subjectKey: 'event',
                subjectType: BuiltEmitter::class,
            ),
        ];
    }
}

/** A class that exists and is NOT a holder — the second control of F5. */
final class NotAHolder
{
    /**
     * The right shape and the wrong type: a manifest cannot be trusted because a class «looks like» a holder.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [];
    }
}

/** A holder that cannot answer: the failure is reported with what it threw, never swallowed. */
final class ThrowingHolder implements DeclaresEvents
{
    /**
     * Throws instead of declaring.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        throw new \RuntimeException('this holder cannot list its events');
    }
}
