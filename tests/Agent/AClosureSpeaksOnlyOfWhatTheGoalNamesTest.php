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
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\StandingAsk;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A closure the house derives speaks only of what the goal names (greenhouse decisions/0522).
 *
 * Measured (evidence/1050, leg 2): the resident scaffolded an empty `Blog` plugin and registered it; the promotion
 * observed `GET /` → 200, and the house wrote `closure_derived {verified: true, scope: house_observation}` for a
 * goal that asked for `GET /blog` — while `/blog` answered 404 and the resident said «the goal is not met». The
 * panel painted «✓ Verified by the house».
 *
 * @guards an observation of a subject the goal names still closes; the naming rule is the frontier's (0496)
 *
 * @refuses an observation of a subject the goal does not name — it never closes, and the reason says what was seen
 *
 * @subject-in milpa/app-runtime
 */
final class AClosureSpeaksOnlyOfWhatTheGoalNamesTest extends TestCase
{
    private const GOAL_1050 = 'Build the blog this house was founded for: a plugin named Blog that serves GET /blog to anonymous'
        . ' visitors, listing only published posts (title and body), never drafts. Seed one published post and one draft so'
        . ' the page shows something. When it is live, confirm /blog is served.';

    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', self::GOAL_1050);
    }

    public function testLeg2Of1050TheRootServedAfterAnEmptyPluginDoesNotClose(): void
    {
        $registered = $this->promote(['config/plugins.php'], [$this->served('/')]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);
        self::assertContains("the house observed «/» served (seq {$registered}), a subject the goal does not name", $closure['reasons']);
    }

    public function testTheEpilogueDoesNotOpenOnAnObservationTheGoalDoesNotName(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        $this->promote(['config/plugins.php'], [$this->served('/')]);
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));

        self::assertArrayNotHasKey('epilogue', $probe->afterStep(1) ?? []);
        self::assertSame([], $this->facts(SessionProgressProbe::EPILOGUE_OPENED));
    }

    public function testLeg4Of1050TheBlogServedAfterItsLastChangeCloses(): void
    {
        $this->promote(['config/plugins.php'], [$this->served('/')]);
        $blog = $this->promote(['src/Plugins/Blog/BlogController.php'], [$this->served('/blog')]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => '/blog', 'seq' => $blog], $closure['derivedFrom']['observation'] ?? null);
    }

    public function testAnUnnamedRouteServedLastDoesNotHideTheNamedOne(): void
    {
        $blog = $this->promote(['src/Plugins/Blog/BlogController.php'], [$this->served('/blog'), $this->served('/')]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => '/blog', 'seq' => $blog], $closure['derivedFrom']['observation'] ?? null);
    }

    public function testAHumanTurnThatNamesTheRouteNamesIt(): void
    {
        $this->store->recordTurn('s', 'user', 'and the home page at / too');
        $this->promote(['config/plugins.php'], [$this->served('/')]);

        self::assertTrue($this->verdict()['verified']);
    }

    public function testAnUnnamedObservationStillCountsAgainstTheHouseWhenItFails(): void
    {
        $this->promote(['src/Plugins/Blog/BlogController.php'], [$this->served('/blog'), ['route' => 'GET /', 'subject' => '/', 'status' => 500, 'environment' => ['kind' => 'house']]]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertStringContainsString('the house answered «/» with HTTP 500', implode('; ', $closure['reasons']));
    }

    /** The negative control of this rule: without the goal's filter, 1050's leg 2 closes exactly as it did. */
    public function testWithoutTheGoalsFilterTheRootWouldCloseAsItDidIn1050(): void
    {
        $this->promote(['config/plugins.php'], [$this->served('/')]);
        $stream = $this->store->stream('s');

        self::assertTrue(HouseObservedClosure::of($stream, $this->store->facts('s'))['derived']);
        self::assertFalse(HouseObservedClosure::of($stream, $this->store->facts('s'), static fn (string $s): bool => StandingAsk::in($stream)->namesSubject($s))['derived']);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function namings(): iterable
    {
        yield 'the path, written' => [self::GOAL_1050, '/blog', true];
        yield 'the root, inside another path' => [self::GOAL_1050, '/', false];
        yield 'the root, written alone' => ['serve the home page at / first', '/', true];
        yield 'a longer path is another route' => ['serve GET /blog', '/blogs', false];
        yield 'a deeper path of a named first segment' => ['serve GET /blog', '/blog/{slug}', true];
        yield 'a trailing slash is the same route' => ['serve GET /blog', '/blog/', true];
        yield 'the path at the end of a sentence' => ['confirm it at /blog.', '/blog', true];
        yield 'a file named after it names its first segment (the cost of 0496)' => ['download /blog.json', '/blog', true];
        yield 'the first segment, named as a word' => ['build the blog', '/blog', true];
        yield 'a word inside another word' => ['build the blog', '/log', false];
        yield 'a parameter names nothing' => ['build the blog', '/{id}', false];
        yield 'a screen, named' => ['a tasks screen for the team', 'tasks', true];
        yield 'a screen, not named' => ['a tasks screen for the team', 'task', false];
        yield 'another plugin' => [self::GOAL_1050, 'HelloPlugin', false];
    }

    #[DataProvider('namings')]
    public function testWhatTheAskNames(string $ask, string $subject, bool $named): void
    {
        self::assertSame($named, StandingAsk::ofText($ask)->namesSubject($subject));
    }

    public function testTheAskIsTheCurrentGoalAndTheHumansTurns(): void
    {
        $this->store->recordTurn('s', 'assistant', 'I will also serve /about');
        $this->store->recordTurn('s', 'user', 'and /archive');
        $ask = StandingAsk::in($this->store->stream('s'));

        self::assertTrue($ask->namesSubject('/archive'));
        self::assertFalse($ask->namesSubject('/about'), 'what the resident says is not what was asked');
        self::assertSame(self::GOAL_1050, StandingAsk::goalIn($this->store->stream('s')));
    }

    /**
     * A promotion whose receipt carries what the house answered after it landed.
     *
     * @param list<string>               $paths
     * @param list<array<string, mixed>> $observed
     */
    private function promote(array $paths, array $observed): int
    {
        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'wabc'], (string) json_encode([
            'ok' => true,
            'promoted' => $paths,
            'evidence' => ['predicate' => 'promoted', 'subject' => 'wabc', 'environment' => ['kind' => 'house'], 'paths' => $paths],
            'observed' => $observed,
        ]), mutating: true);
    }

    /** @return array<string, mixed> */
    private function served(string $path): array
    {
        return ['predicate' => 'served', 'route' => "GET {$path}", 'subject' => $path, 'status' => 200,
            'environment' => ['kind' => 'house'], 'servedAt' => $path, 'bytes' => 42, 'sha256' => str_repeat('a', 64)];
    }

    /** @return array<string, mixed> */
    private function verdict(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $this->store->facts('s'), $this->store->stream('s'));
    }

    /** @return list<array<string, mixed>> */
    private function facts(string $type): array
    {
        return array_values(array_map(
            static fn (Event $e): array => $e->payload,
            array_filter($this->events->replay(SessionStore::PREFIX . 's'), static fn (Event $e): bool => $e->type === $type),
        ));
    }
}
