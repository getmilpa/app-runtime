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
 * THE HOUSE CLOSES ONLY OVER A PAGE THAT LISTS (greenhouse decisions/0576, slice BV-3b — H1 of decisions/0565).
 *
 * Measured on cattle (greenhouse evidence/1108 §4), app-runtime 0.208.0: a session without todos mounted a screen,
 * the house observed `GET /blog` → 200, and `closure_derived` said `verified: true` — over a page reading «Nothing
 * to read yet.». The receipt now says what the page listed, and only an observation that lists counts.
 */
final class TheHouseClosesOnlyOverAPageThatListsTest extends TestCase
{
    private const LISTS = ['entity' => 'Blog/Post', 'public' => 1, 'shown' => 1, 'withheld' => 1, 'leaked' => 0, 'withholding' => 'exercised'];
    private const EMPTY = ['entity' => 'Blog/Post', 'public' => 0, 'shown' => 0, 'withheld' => 0, 'leaked' => 0, 'withholding' => 'unexercised'];

    public function testAPageWithNothingToReadDoesNotClose(): void
    {
        $closure = $this->closureAfter([self::EMPTY]);

        self::assertFalse($closure['verified'], 'the published house said verified: true here');
        self::assertSame(
            ['the house observed «/blog» served with nothing to read (seq 2): its screen lists Blog/Post and no public row exists — leave rows with entity:seed'],
            array_values(array_filter($closure['reasons'], static fn (string $why): bool => str_contains($why, '/blog'))),
            'said, and said once',
        );
        self::assertArrayNotHasKey('derivedFrom', $closure, 'a page with nothing to read is not an observation the house derives a closure from');
        self::assertSame('recorded_work', $closure['scope']);
    }

    public function testAPageThatListsCloses(): void
    {
        $closure = $this->closureAfter([self::LISTS]);

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('/blog', $closure['derivedFrom']['observation']['subject']);
        self::assertSame(self::LISTS, $closure['derivedFrom']['observation']['content'], 'the verdict carries what the page listed');
    }

    public function testNothingWithheldIsSaidAndDoesNotStopTheClosure(): void
    {
        $closure = $this->closureAfter([['withheld' => 0, 'withholding' => 'unexercised'] + self::LISTS]);

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('unexercised', $closure['derivedFrom']['observation']['content']['withholding'], '«never drafts» was not exercised, and whoever reads the verdict sees it');
    }

    public function testAPageThatShowsOnlyPartOfWhatIsPublicDoesNotClose(): void
    {
        $closure = $this->closureAfter([['public' => 2] + self::LISTS]);

        self::assertFalse($closure['verified']);
        self::assertContains('the house observed «/blog» served showing 1 of 2 public rows of Blog/Post (seq 2)', $closure['reasons']);
    }

    public function testAPageThatShowsWhatIsNotPublicDoesNotClose(): void
    {
        $closure = $this->closureAfter([['leaked' => 1] + self::LISTS]);

        self::assertFalse($closure['verified']);
        self::assertContains('the house observed «/blog» served showing 1 row of Blog/Post that is not public (seq 2)', $closure['reasons']);
    }

    public function testTheLastThingTheHouseSawDecides(): void
    {
        self::assertTrue($this->closureAfter([self::EMPTY, self::LISTS])['verified'], 'empty, then seeded: the page that lists closes');

        $emptied = $this->closureAfter([self::LISTS, self::EMPTY]);
        self::assertFalse($emptied['verified'], 'a page that listed and no longer does is not closed by what it once listed');
        self::assertStringContainsString('with nothing to read (seq 3)', implode('; ', $emptied['reasons']));
    }

    public function testAnEmptyPageIsNotClosedByAnotherRouteThatAnswers(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the site: a blog and an about page.', AutonomyMode::Auto);
        $served = static fn (string $subject, ?array $content): array => ['predicate' => 'served', 'route' => 'GET ' . $subject, 'subject' => $subject, 'status' => 200,
            'environment' => ['kind' => 'house'], 'servedAt' => $subject, 'bytes' => 10, 'sha256' => hash('sha256', $subject)] + ($content === null ? [] : ['content' => $content]);
        $store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w'], (string) json_encode(['ok' => true, 'promoted' => ['config/screens.json'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w', 'environment' => ['kind' => 'house'], 'paths' => ['config/screens.json']],
            'observed' => [$served('/about', null), $served('/blog', self::EMPTY)]]), mutating: true);
        $session = $store->load('s');
        self::assertNotNull($session);

        $closure = ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));

        self::assertFalse($closure['verified'], 'a goal that writes no route counts every route — and one of them has nothing to read');
        self::assertStringContainsString('«/blog» served with nothing to read', implode('; ', $closure['reasons']));
    }

    /**
     * Measured with the real resident (greenhouse evidence/1110 §5): it always plans with todos, and a session with
     * todos whose house observation does not derive was judged by its record alone — so closing its todos made the
     * house's finding vanish and the empty page closed `verified: true`. What the house SAW is said in both forms.
     */
    public function testWithTodosAnEmptyPageDoesNotCloseEither(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog: serve GET /blog as a page listing published posts.', AutonomyMode::Auto);
        $store->setTodo('s', new Todo('t1', 'Confirm /blog served', TodoStatus::Pending));
        $this->promote($store, 'w1', [$this->route('/blog', self::EMPTY)]);
        $store->completeTodo('s', 't1', Evidence::operationOk('e1', 'sandbox_promote'));

        $closure = $this->verdict($store);

        self::assertFalse($closure['verified'], 'every todo is closed with accepted evidence, and the page has nothing to read');
        self::assertContains(
            'the house observed «/blog» served with nothing to read (seq 3): its screen lists Blog/Post and no public row exists — leave rows with entity:seed',
            $closure['reasons'],
        );

        $this->promote($store, 'w2', [$this->route('/blog', self::LISTS)]);
        $closed = $this->verdict($store);
        self::assertTrue($closed['verified'], implode('; ', $closed['reasons']));
        self::assertSame('recorded_work_and_house_observation', $closed['scope']);
    }

    public function testAnEmptyPageIsSaidOnceEvenWhenNothingLandedInTheSession(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Check that GET /blog lists the published posts.', AutonomyMode::Auto);
        $store->recordToolCall('s', 'route_observe', ['path' => '/blog'], (string) json_encode(['ok' => true, 'observed' => [$this->route('/blog', self::EMPTY)]]), mutating: false);

        $closure = $this->verdict($store);

        self::assertFalse($closure['verified']);
        self::assertSame(1, \count(array_filter($closure['reasons'], static fn (string $why): bool => str_contains($why, 'nothing to read'))), implode('; ', $closure['reasons']));
    }

    /**
     * Measured with the real resident (greenhouse evidence/1110 §5): its verdict rested on `screen:observe` of the
     * screen «blog» — a receipt of `/live/page?component=blog`, which the house does not judge — because a screen
     * named like the route was taken for the route. A screen served at its own page is not `GET /blog`.
     */
    public function testAScreenObservedAtItsOwnPageIsNotTheRouteTheGoalWrites(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog: serve GET /blog as a page listing published posts.', AutonomyMode::Auto);
        $this->promote($store, 'w1', []);
        $this->observeScreen($store, 'blog');

        $closure = $this->verdict($store);

        self::assertFalse($closure['verified'], 'nobody ever saw /blog answer: the published house said verified: true here');
        self::assertContains(
            'the house observed «blog» served at «/live/page?component=blog» (seq 3), and the goal writes «GET /blog»: only a route the goal writes closes it',
            $closure['reasons'],
        );
    }

    public function testAReceiptServedAtTheWrittenRouteCountsWhateverItsQuery(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog: serve GET /blog as a page listing published posts.', AutonomyMode::Auto);
        $this->promote($store, 'w1', []);
        $store->recordToolCall('s', 'verify', [], (string) json_encode([
            'ok' => true, 'evidence' => ['predicate' => 'served', 'subject' => 'blog', 'servedAt' => '/blog?page=1', 'environment' => ['kind' => 'house']],
        ]), mutating: false);

        $closure = $this->verdict($store);

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
    }

    public function testAReceiptThatDoesNotSayWhereItWasServedKeepsItsReason(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog: serve GET /blog as a page listing published posts.', AutonomyMode::Auto);
        $this->promote($store, 'w1', []);
        $store->recordToolCall('s', 'screen_observe', ['name' => 'posts'], (string) json_encode([
            'ok' => true, 'evidence' => ['predicate' => 'served', 'subject' => 'posts', 'environment' => ['kind' => 'house']],
        ]), mutating: false);

        self::assertContains(
            'the house observed «posts» served (seq 3), and the goal writes «GET /blog»: only a route the goal writes closes it',
            $this->verdict($store)['reasons'],
        );
    }

    public function testTheVerdictRestsOnTheRouteTheHouseJudgedNotOnAScreenOfTheSameName(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog: serve GET /blog as a page listing published posts.', AutonomyMode::Auto);
        $this->promote($store, 'w1', [$this->route('/blog', self::LISTS)]);
        $this->observeScreen($store, 'blog');

        $closure = $this->verdict($store);

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => '/blog', 'seq' => 2, 'content' => self::LISTS], $closure['derivedFrom']['observation']);
    }

    public function testAScreenTheGoalNamesInWordsStillClosesAGoalThatWritesNoRoute(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Declare a screen named blog that lists the published posts.', AutonomyMode::Auto);
        $this->promote($store, 'w1', []);
        $this->observeScreen($store, 'blog');

        $closure = $this->verdict($store);

        self::assertTrue($closure['verified'], 'no route is written: a screen the goal names is observed where screens are served, as before (decisions/0522)');
        self::assertSame('blog', $closure['derivedFrom']['observation']['subject']);
    }

    public function testARouteTheHouseDidNotJudgeCountsAsBefore(): void
    {
        $closure = $this->closureAfter([null]);

        self::assertTrue($closure['verified'], 'a receipt without «content» is one the house did not judge — a raw route, a screen with literal rows — not one that fails');
        self::assertArrayNotHasKey('content', $closure['derivedFrom']['observation']);
    }

    public function testAnEmptyPageOfARouteTheGoalDoesNotWriteIsNotTheReason(): void
    {
        $closure = $this->closureAfter([self::EMPTY], subject: '/posts');

        self::assertFalse($closure['verified']);
        self::assertStringContainsString('only a route the goal writes closes it', implode('; ', $closure['reasons']), 'the unwritten route is the fact, as before');
    }

    /**
     * A promotion that landed the screens' declarations, and what the house observed after it.
     *
     * @param list<array<string, mixed>> $observed
     */
    private function promote(SessionStore $store, string $workspace, array $observed): void
    {
        $store->recordToolCall('s', 'sandbox_promote', ['workspace' => $workspace], (string) json_encode([
            'ok' => true,
            'promoted' => ['config/screens.json'],
            'evidence' => ['predicate' => 'promoted', 'subject' => $workspace, 'environment' => ['kind' => 'house'], 'paths' => ['config/screens.json']],
        ] + ($observed === [] ? [] : ['observed' => $observed])), mutating: true);
    }

    /** `screen:observe` as the house answers it: a receipt of the screen, served at its own page. */
    private function observeScreen(SessionStore $store, string $name): void
    {
        $store->recordToolCall('s', 'screen_observe', ['name' => $name], (string) json_encode([
            'ok' => true, 'screen' => $name, 'status' => 200, 'servedAt' => '/live/page?component=' . $name,
            'evidence' => ['predicate' => 'served', 'subject' => $name, 'servedAt' => '/live/page?component=' . $name, 'environment' => ['kind' => 'house']],
        ]), mutating: false);
    }

    /**
     * @param array<string, mixed>|null $content
     *
     * @return array<string, mixed>
     */
    private function route(string $subject, ?array $content): array
    {
        return ['predicate' => 'served', 'route' => 'GET ' . $subject, 'subject' => $subject, 'status' => 200, 'environment' => ['kind' => 'house'],
            'servedAt' => $subject, 'bytes' => 3551, 'sha256' => hash('sha256', $subject . json_encode($content))] + ($content === null ? [] : ['content' => $content]);
    }

    /** @return array{verified: bool, reasons: list<string>, scope: string, derivedFrom?: array<string, mixed>} */
    private function verdict(SessionStore $store): array
    {
        $session = $store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));
    }

    /**
     * The house's verdict over a session whose promotions observed the goal's route with these contents, in order.
     *
     * @param list<array<string, mixed>|null> $contents
     *
     * @return array{verified: bool, reasons: list<string>, derivedFrom?: array<string, mixed>}
     */
    private function closureAfter(array $contents, string $subject = '/blog'): array
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog: serve GET /blog as a page listing published posts.', AutonomyMode::Auto);
        foreach ($contents as $at => $content) {
            $store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w' . $at], (string) json_encode([
                'ok' => true,
                'promoted' => ['config/screens.json'],
                'evidence' => ['predicate' => 'promoted', 'subject' => 'w' . $at, 'environment' => ['kind' => 'house'], 'paths' => ['config/screens.json']],
                'observed' => [[
                    'predicate' => 'served', 'route' => 'GET ' . $subject, 'subject' => $subject, 'status' => 200, 'environment' => ['kind' => 'house'],
                    'servedAt' => $subject, 'bytes' => 3551 + $at, 'sha256' => hash('sha256', 'page ' . $at),
                ] + ($content === null ? [] : ['content' => $content])],
            ]), mutating: true);
        }
        $session = $store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));
    }
}
