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

use Milpa\Agent\Evidence;
use Milpa\Agent\SessionFacts;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\HouseObservedClosure;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\StandingAsk;
use Milpa\EventStore\Event;
use Milpa\EventStore\FileEventStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A route the goal writes explicitly is the only one that closes (greenhouse decisions/0555).
 *
 * Measured live (evidence/1088, first cut): the goal said «…serves GET /blog… listing only published posts…», the
 * resident ran `make crud`, `/posts` landed, the goal's word «posts» named it (0522), and the house wrote
 * `closure_derived {verified: true, scope: house_observation}` on `/posts@201` while `/blog` answered 404. The scaffold
 * rule (0554) stopped that body — an empty list `make` generated — but a `/posts` WITH data would still close a goal
 * that asked for `GET /blog`.
 *
 * The recorded stream is read from a cut of its FILE (`tests/Fixtures/streams/`), line by line up to seq 210: the lines
 * that carry the model's transcript are left out (`model_called`, `system_set`, `model_reasoned`, `compacted` — the
 * verdict reads none of them), and what names the lab is written neutral: its absolute directory (`/lab`), the session id
 * (`lab-session`), the host, the lab key's fingerprint, its signature and the passkey id. Every other byte is the cattle's.
 *
 * @guards a goal that writes no explicit route names its subjects as before; the route it writes still closes
 *
 * @refuses any other route, once the goal writes one — a scaffold's body or the work's, named by a word or not
 *
 * @subject-in milpa/app-runtime
 */
final class ARouteTheGoalWritesIsTheOnlyOneThatClosesTest extends TestCase
{
    private const GOAL = 'Build the blog this house was founded for: a plugin named Blog that serves GET /blog to anonymous'
        . ' visitors, listing only published posts (title and body), never drafts. Seed one published post and one draft so'
        . ' the page shows something. When it is live, confirm /blog is served.';

    /** The same ask without the method: no explicit route, so its words name routes as 0522 reads them. */
    private const GOAL_IN_WORDS = 'Build the blog this house was founded for: a plugin named Blog listing only published'
        . ' posts (title and body), never drafts.';

    private const STREAM_1088 = __DIR__ . '/../Fixtures/streams/camino-1088-first-cut-upto-210.jsonl';

    private const SESSION_1088 = 'lab-session';

    private const EMPTY_LIST = '2a797c8443253cb630662a190c304f5a70654f6d3c5e1f367d8bb1570fd1cac1';

    private const POSTS = 'b7fe262df4ca989dcc245225f1cf02805807f3af83e316be36456c56828cefe1';

    private InMemoryEventStore $events;

    private SessionStore $store;

    private int $trials = 0;

    /** @var list<string> */
    private array $copies = [];

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', self::GOAL);
    }

    protected function tearDown(): void
    {
        foreach ($this->copies as $copy) {
            @unlink($copy);
        }
    }

    /** What the cattle recorded: the house verified the session on /posts, and answered four `continue` from it. */
    public function testTheStreamOf1088RecordsAClosureOnPosts(): void
    {
        $recorded = array_values(array_filter($this->cutOf1088(210), static fn (Event $e): bool => $e->type === 'session.closure_derived'));

        self::assertCount(1, $recorded);
        self::assertSame(210, $recorded[0]->seq);
        self::assertTrue($recorded[0]->payload['verified']);
        self::assertSame('house_observation', $recorded[0]->payload['scope']);
        self::assertSame(['subject' => '/posts', 'seq' => 201], $recorded[0]->payload['derivedFrom']['observation']);
        self::assertStringContainsString('serves GET /blog', StandingAsk::goalIn($this->cutOf1088(210)));
    }

    /** The same stream, judged now: /posts is not the route the goal writes, and the reason says which one it writes. */
    public function testTheStreamOf1088IsNotClosedByPosts(): void
    {
        $closure = $this->verdictOf1088(210);

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);
        self::assertContains('the house observed «/posts» served (seq 201), and the goal writes «GET /blog»: only a route the goal'
            . ' writes closes it', $closure['reasons']);
    }

    /** No cut of that file is closed by the house: not on «/» (seq 98), not on «/posts» (seq 201). */
    public function testNoCutOfTheStreamOf1088IsClosed(): void
    {
        foreach ([8, 82, 98, 150, 201, 203, 210] as $upto) {
            $closure = $this->verdictOf1088($upto);
            self::assertFalse($closure['verified'], "cut at seq {$upto}");
            self::assertArrayNotHasKey('derivedFrom', $closure, "cut at seq {$upto}");
        }
    }

    /** The control of the fixture: without the goal's filter, that stream's house still sees /posts served after its last change. */
    public function testTheStreamOf1088HoldsTheObservationTheRuleRefuses(): void
    {
        $stream = $this->cutOf1088(210);
        $facts = SessionFacts::fromEvents(self::SESSION_1088, $stream);
        $ask = StandingAsk::in($stream);

        self::assertSame(['GET /blog'], $ask->explicitRoutes());
        self::assertTrue($ask->namesIdentifier('posts'), 'the word that named /posts under 0522 is still in the goal');
        self::assertFalse($ask->namesSubject('/posts'));
        self::assertSame(['subject' => '/posts', 'seq' => 201], HouseObservedClosure::of($this->withoutScaffolds($stream), $facts)['observation']);
    }

    /** The case 0554 left open: /posts with data. The scaffold rule lets it through; the goal asked for GET /blog. */
    public function testPostsWithDataDoesNotCloseAGoalThatWritesGetBlog(): void
    {
        $this->blogPluginRegistered();
        $this->land('make', ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::EMPTY_LIST, 34)]);
        $seeded = $this->land('implement', ['plugin' => 'Blog', 'class' => 'Blog'], ['src/Plugins/Blog/Blog.php'], [$this->served('/posts', self::POSTS, 222)]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);
        self::assertContains("the house observed «/posts» served (seq {$seeded}), and the goal writes «GET /blog»: only a route the"
            . ' goal writes closes it', $closure['reasons']);
    }

    public function testTheEpilogueDoesNotOpenOnPostsWithData(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        $this->blogPluginRegistered();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::POSTS, 222)]);
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));

        self::assertArrayNotHasKey('epilogue', $probe->afterStep(1) ?? []);
    }

    /** The negative: the same receipts under a goal that writes no route close exactly as they did (0522). */
    public function testAGoalThatWritesNoRouteIsStillClosedByTheRouteItsWordsName(): void
    {
        $this->store->setGoal('s', self::GOAL_IN_WORDS);
        $this->blogPluginRegistered();
        $seeded = $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::POSTS, 222)]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => '/posts', 'seq' => $seeded], $closure['derivedFrom']['observation'] ?? null);
    }

    /** And its refusal keeps its wording: with no route written, an unnamed subject is «a subject the goal does not name». */
    public function testAGoalThatWritesNoRouteKeepsItsReason(): void
    {
        $this->store->setGoal('s', self::GOAL_IN_WORDS);
        $about = $this->land('implement', ['plugin' => 'Blog', 'class' => 'AboutController'], ['src/Plugins/Blog/Controllers/AboutController.php'], [$this->served('/about', self::POSTS, 222)]);

        self::assertContains("the house observed «/about» served (seq {$about}), a subject the goal does not name", $this->verdict()['reasons']);
    }

    /**
     * The route the goal writes closes, however the house records it: a promotion says `blog`, route:observe `/blog`.
     */
    #[DataProvider('writingsOfTheRoute')]
    public function testTheRouteTheGoalWritesCloses(string $subject): void
    {
        $this->blogPluginRegistered();
        $served = $this->land('implement', ['plugin' => 'Blog', 'class' => 'BlogController'], ['src/Plugins/Blog/Controllers/BlogController.php'], [$this->served($subject, self::POSTS, 222)]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => $subject, 'seq' => $served], $closure['derivedFrom']['observation'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writingsOfTheRoute(): iterable
    {
        yield 'as a promotion records it' => ['blog'];
        yield 'as route:observe records it' => ['/blog'];
        yield 'with a slash at its end' => ['/blog/'];
    }

    /** /posts served beside /blog does not hide it, and is not the observation the closure rests on. */
    public function testAnotherRouteServedBesideTheWrittenOneDoesNotHideIt(): void
    {
        $this->blogPluginRegistered();
        $served = $this->land('implement', ['plugin' => 'Blog', 'class' => 'BlogController'], ['src/Plugins/Blog/Controllers/BlogController.php'], [$this->served('/posts', self::POSTS, 222), $this->served('blog', self::POSTS, 222)]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => 'blog', 'seq' => $served], $closure['derivedFrom']['observation'] ?? null);
    }

    /** Several routes written: any of them closes, a third one does not, and the reason lists what the goal writes. */
    public function testWithSeveralRoutesWrittenAnyOfThemClosesAndNoOther(): void
    {
        $this->store->setGoal('s', 'A plugin named Blog that serves GET /blog and GET /feed.xml, listing published posts.');
        $this->blogPluginRegistered();
        $posts = $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::POSTS, 222)]);

        self::assertContains("the house observed «/posts» served (seq {$posts}), and the goal writes «GET /blog», «GET /feed.xml»: only a"
            . ' route the goal writes closes it', $this->verdict()['reasons']);

        $feed = $this->land('implement', ['plugin' => 'Blog', 'class' => 'FeedController'], ['src/Plugins/Blog/Controllers/FeedController.php'], [$this->served('/feed.xml', self::EMPTY_LIST, 34)]);
        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => '/feed.xml', 'seq' => $feed], $closure['derivedFrom']['observation'] ?? null);
    }

    /** The human's turns are part of the ask (0522): a route they write later closes too. */
    public function testARouteTheHumanWritesInATurnCloses(): void
    {
        $this->store->recordTurn('s', 'user', 'and serve the list at GET /posts as well');
        $this->blogPluginRegistered();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::POSTS, 222)]);

        self::assertTrue($this->verdict()['verified']);
    }

    /** The resident's own turns are not: a route it writes in its answer names nothing. */
    public function testARouteTheResidentWritesInItsAnswerDoesNotClose(): void
    {
        $this->store->recordTurn('s', 'assistant', 'The blog is live: GET /posts answers with the published posts.');
        $this->blogPluginRegistered();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::POSTS, 222)]);

        self::assertFalse($this->verdict()['verified']);
    }

    /** A path the human writes without its method, after the goal wrote a route, is prose: it no longer names (the cost). */
    public function testAPathWrittenWithoutItsMethodDoesNotCloseOnceARouteIsWritten(): void
    {
        $this->store->recordTurn('s', 'user', 'and the list at /posts too');
        $this->blogPluginRegistered();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::POSTS, 222)]);

        self::assertFalse($this->verdict()['verified']);
    }

    /** The goal in force is the one read: a route the first goal wrote stops binding when the goal changes. */
    public function testARouteOfAGoalThatWasReplacedNoLongerBinds(): void
    {
        $this->store->setGoal('s', 'Serve the published posts at GET /posts.');
        $this->blogPluginRegistered();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'BlogController'], ['src/Plugins/Blog/Controllers/BlogController.php'], [$this->served('blog', self::POSTS, 222)]);

        self::assertFalse($this->verdict()['verified']);

        $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::POSTS, 222)]);

        self::assertTrue($this->verdict()['verified']);
    }

    /** A screen is a subject too: named by a word of the goal, it does not close a goal that writes a route. */
    public function testAScreenNamedByAWordDoesNotCloseAGoalThatWritesARoute(): void
    {
        $this->blogPluginRegistered();
        $this->store->recordToolCall('s', 'screen_observe', ['screen' => 'posts'], (string) json_encode([
            'ok' => true, 'evidence' => ['predicate' => 'served', 'subject' => 'posts', 'environment' => ['kind' => 'house']],
        ]), mutating: false);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertStringContainsString('the house observed «posts» served', implode('; ', $closure['reasons']));
        self::assertStringContainsString('the goal writes «GET /blog»', implode('; ', $closure['reasons']));
    }

    /** With todos (0509): the house does not stand beside the record on /posts, so what never landed still binds. */
    public function testWithTodosPostsDoesNotSpeakForTheHouse(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'Serve GET /blog', TodoStatus::Pending));
        $this->blogPluginRegistered();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::POSTS, 222)]);
        $this->store->completeTodo('s', 't1', Evidence::operationOk('e1', 'sandbox_promote'));

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);

        // CONTROL: the same record under the goal in words is closed with the house beside it.
        $this->store->setGoal('s', self::GOAL_IN_WORDS);
        $control = $this->verdict();
        self::assertTrue($control['verified'], implode('; ', $control['reasons']));
        self::assertSame('recorded_work_and_house_observation', $control['scope']);
    }

    /** A route the goal does not write still counts AGAINST the house when it fails. */
    public function testAnotherRouteFailingStillCountsAgainstTheHouse(): void
    {
        $this->blogPluginRegistered();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'BlogController'], ['src/Plugins/Blog/Controllers/BlogController.php'], [
            $this->served('blog', self::POSTS, 222),
            ['route' => 'GET /posts', 'subject' => '/posts', 'status' => 500, 'environment' => ['kind' => 'house']],
        ]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertStringContainsString('the house answered «/posts» with HTTP 500', implode('; ', $closure['reasons']));
    }

    /** The scaffold of the written route is still the harder fact (0554): its reason is the one said. */
    public function testTheScaffoldOfTheWrittenRouteKeepsItsReason(): void
    {
        $this->blogPluginRegistered();
        $scaffold = $this->land('make', ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController', 'route' => 'blog'], ['src/Plugins/Blog/Controllers/BlogController.php'], [$this->served('blog', self::EMPTY_LIST, 26)]);
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::POSTS, 222)]);

        self::assertContains("the house observed «blog» serving the body of its scaffold (seq {$scaffold}): what «make» generated is"
            . ' not the work', $this->verdict()['reasons']);
    }

    /**
     * @param list<string> $routes
     */
    #[DataProvider('asks')]
    public function testWhatAnAskWritesExplicitly(string $ask, array $routes): void
    {
        self::assertSame($routes, StandingAsk::ofText($ask)->explicitRoutes());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function asks(): iterable
    {
        yield 'the method and the path' => [self::GOAL, ['GET /blog']];
        yield 'none written' => [self::GOAL_IN_WORDS, []];
        yield 'a path without its method' => ['confirm /blog is served', []];
        yield 'the full stop of the sentence is not the path' => ['It serves GET /blog.', ['GET /blog']];
        yield 'a dot inside the path is' => ['serve GET /feed.xml, please', ['GET /feed.xml']];
        yield 'the root' => ['serve GET / to anyone', ['GET /']];
        yield 'the root at the end of a sentence' => ['serve GET /.', ['GET /']];
        yield 'a deeper path' => ['serve GET /blog/archive', ['GET /blog/archive']];
        yield 'a parameter' => ['serve GET /blog/{slug}', ['GET /blog/{slug}']];
        yield 'a query is not read' => ['serve GET /blog?page=2', ['GET /blog']];
        yield 'each once, in order' => ['GET /b then GET /a, and GET /b/ and GET /B again', ['GET /b', 'GET /a']];
        yield 'several blanks' => ["serve GET  \t/blog", ['GET /blog']];
        yield 'a line break is not a blank' => ["what you GET\n/blog is another line", []];
        yield 'lowercase is prose' => ['to get /blog working', []];
        yield 'another method is not read' => ['accept POST /posts and DELETE /posts/{id}', []];
        yield 'a longer word ending in GET' => ['see TARGET /blog', []];
        yield 'GET without a path' => ['GET the blog done at /blog', []];
        yield 'backticks around it' => ['serve `GET /blog`', ['GET /blog']];
    }

    #[DataProvider('subjects')]
    public function testWhatAnAskThatWritesARouteNames(string $ask, string $subject, bool $named): void
    {
        self::assertSame($named, StandingAsk::ofText($ask)->namesSubject($subject));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function subjects(): iterable
    {
        yield 'the route it writes' => [self::GOAL, '/blog', true];
        yield 'without its slash' => [self::GOAL, 'blog', true];
        yield 'whatever its case' => [self::GOAL, '/Blog', true];
        yield 'a route one of its words names' => [self::GOAL, '/posts', false];
        yield 'a screen one of its words names' => [self::GOAL, 'posts', false];
        yield 'a deeper path of the written one' => [self::GOAL, '/blog/archive', false];
        yield 'a longer path' => [self::GOAL, '/blogs', false];
        yield 'the root' => [self::GOAL, '/', false];
        yield 'the root, written' => ['serve GET / to anyone', '/', true];
        yield 'the root, written, is not another route' => ['serve GET / to anyone', '/blog', false];
        yield 'a deeper path, written' => ['serve GET /blog/archive', '/blog/archive', true];
        yield 'its first segment is not written' => ['serve GET /blog/archive', '/blog', false];
        yield 'the second of two' => ['serve GET /blog and GET /posts', '/posts', true];
        yield 'no route written: the word names it (0522)' => [self::GOAL_IN_WORDS, '/posts', true];
        yield 'another method written: the word still names it' => ['accept POST /comments on the posts', '/posts', true];
    }

    /**
     * The first lines of 1088's stream file up to a seq, copied to a file of their own and read back by the store.
     *
     * @return list<Event>
     */
    private function cutOf1088(int $upto): array
    {
        return $this->storeOf1088($upto)->replay(SessionStore::PREFIX . self::SESSION_1088);
    }

    private function storeOf1088(int $upto): FileEventStore
    {
        $copy = (string) tempnam(sys_get_temp_dir(), 'cut-1088-');
        $this->copies[] = $copy;
        $out = fopen($copy, 'w');
        self::assertIsResource($out);
        foreach ((array) file(self::STREAM_1088) as $line) {
            $row = json_decode((string) $line, true);
            self::assertIsArray($row);
            if ($row['seq'] > $upto) {
                break;
            }
            fwrite($out, (string) $line);
        }
        fclose($out);

        return new FileEventStore($copy);
    }

    /** @return array<string, mixed> */
    private function verdictOf1088(int $upto): array
    {
        $events = $this->storeOf1088($upto);
        $session = (new SessionStore($events))->load(self::SESSION_1088);
        self::assertNotNull($session);
        $stream = $events->replay(SessionStore::PREFIX . self::SESSION_1088);

        return ClosureVerdict::derive($session, SessionFacts::fromEvents(self::SESSION_1088, $stream), $stream);
    }

    /**
     * The same stream with every `make` recorded as any other writer, so only the goal's filter is under test.
     *
     * @param list<Event> $stream
     *
     * @return list<Event>
     */
    private function withoutScaffolds(array $stream): array
    {
        return array_map(static function (Event $event): Event {
            if (($event->payload['tool'] ?? null) !== 'make') {
                return $event;
            }

            return new Event($event->streamId, $event->type, ['tool' => 'implement'] + $event->payload, $event->seq);
        }, $stream);
    }

    private function blogPluginRegistered(): void
    {
        $this->land('make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], ['src/Plugins/Blog/Blog.php'], []);
        $this->land('plugins_register', ['name' => 'Blog'], ['config/plugins.php'], [$this->served('/', str_repeat('c', 64), 43431)]);
    }

    /**
     * A call that ran in its own trial, then the promotion that landed it — the receipts as 1088 recorded them.
     *
     * @param array<string, mixed>       $arguments
     * @param list<string>               $paths
     * @param list<array<string, mixed>> $observed
     */
    private function land(string $tool, array $arguments, array $paths, array $observed): int
    {
        $workspace = 'w' . ++$this->trials;
        $this->store->recordToolCall('s', $tool, $arguments, (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => $workspace,
            'changed' => array_fill_keys($paths, 'modified'), 'output' => ['ok' => true],
        ]), mutating: true);

        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => $workspace], (string) json_encode([
            'ok' => true,
            'promoted' => $paths,
            'evidence' => ['predicate' => 'promoted', 'subject' => $workspace, 'environment' => ['kind' => 'house'],
                'from' => ['kind' => 'trial', 'workspace' => $workspace], 'paths' => $paths],
        ] + ($observed !== [] ? ['observed' => $observed] : [])), mutating: true);
    }

    /** @return array<string, mixed> */
    private function served(string $path, string $sha, int $bytes): array
    {
        return ['predicate' => 'served', 'route' => "GET {$path}", 'subject' => $path, 'status' => 200,
            'environment' => ['kind' => 'house'], 'servedAt' => $path, 'bytes' => $bytes, 'sha256' => $sha];
    }

    /** @return array<string, mixed> */
    private function verdict(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $this->store->facts('s'), $this->store->stream('s'));
    }
}
