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
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\HouseObservedClosure;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\StandingAsk;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A route served by its scaffold is not the work (greenhouse decisions/0554).
 *
 * Measured (evidence/1081, D1): in a session without todos, `make controller` landed and `GET /blog` answered 200 with
 * the scaffold's body — 26 bytes, «BlogController is running.», no posts. The house opened the epilogue (seq 135), and
 * the verdict on the stream cut there was `verified: true, scope: house_observation`. A resident that answered there
 * would have left «✓ Verified by the house» over an empty page, and the next `continue` would have done nothing.
 *
 * @guards the body served after the scaffold's file was written by something else closes, as 1081's and 1069's did
 *
 * @refuses a body the house saw while the route's scaffold stood untouched — then, or ever after
 *
 * @subject-in milpa/app-runtime
 */
final class AScaffoldServedIsNotTheWorkTest extends TestCase
{
    private const GOAL = 'Build the blog this house was founded for: a plugin named Blog that serves GET /blog to anonymous'
        . ' visitors, listing only published posts (title and body), never drafts. Seed one published post and one draft so'
        . ' the page shows something. When it is live, confirm /blog is served.';

    /** A goal that writes no route: its word «posts» names /posts (0522), where a written `GET /blog` would not (0555). */
    private const GOAL_IN_WORDS = 'Build the blog this house was founded for: a plugin named Blog listing only published posts'
        . ' (title and body), never drafts.';

    private const CONTROLLER = 'src/Plugins/Blog/Controllers/BlogController.php';

    private const SCAFFOLD = '7a4d348fa987f2663eebf5d50764ad6125b359ccc9bb9aac86bd6c72c427d41c';

    private const EMPTY_LIST = '2a797c8443253cb630662a190c304f5a70654f6d3c5e1f367d8bb1570fd1cac1';

    private const POSTS = 'b7fe262df4ca989dcc245225f1cf02805807f3af83e316be36456c56828cefe1';

    private InMemoryEventStore $events;

    private SessionStore $store;

    private int $trials = 0;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', self::GOAL);
    }

    /** 1081 cut at seq 134: the scaffold landed and answered 200, nothing implemented it. */
    public function testD1Of1081TheScaffoldServedDoesNotClose(): void
    {
        $this->blogPluginRegistered();
        $landed = $this->scaffoldTheController();

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);
        self::assertContains("the house observed «blog» serving the body of its scaffold (seq {$landed}): what «make» generated is"
            . ' not the work', $closure['reasons']);
    }

    public function testTheEpilogueDoesNotOpenOnTheScaffold(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        $this->blogPluginRegistered();
        $this->scaffoldTheController();
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));

        self::assertArrayNotHasKey('epilogue', $probe->afterStep(1) ?? []);
    }

    /** 1081 at seq 177: an edit elsewhere in the plugin landed, /blog still answers the scaffold's body. */
    public function testTheScaffoldsBodyAfterAnotherChangeStillDoesNotClose(): void
    {
        $this->blogPluginRegistered();
        $this->scaffoldTheController();
        $this->land('edit', ['plugin' => 'Blog', 'class' => 'Blog'], ['src/Plugins/Blog/Blog.php'], [$this->served('blog', self::SCAFFOLD, 26)]);

        self::assertFalse($this->verdict()['verified']);
    }

    /** 1081 at seq 193: the controller implemented, /blog serves the post. */
    public function testTheBodyServedAfterTheControllerWasImplementedCloses(): void
    {
        $this->blogPluginRegistered();
        $this->scaffoldTheController();
        $implemented = $this->land('implement', ['plugin' => 'Blog', 'class' => 'BlogController'], [self::CONTROLLER], [$this->served('blog', self::POSTS, 222)]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => 'blog', 'seq' => $implemented], $closure['derivedFrom']['observation'] ?? null);
    }

    /** 1069 at seq 247: a later scaffold of a test promotes, /blog answers the body the implementation served. */
    public function testAnotherScaffoldAfterTheWorkDoesNotTakeItBack(): void
    {
        $this->blogPluginRegistered();
        $this->scaffoldTheController();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'BlogController'], [self::CONTROLLER], [$this->served('blog', self::POSTS, 222)]);
        $test = $this->land('make', ['what' => 'test', 'plugin' => 'Blog', 'name' => 'Blog'], ['tests/Plugins/Blog/BlogTest.php'], [$this->served('blog', self::POSTS, 222)]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => 'blog', 'seq' => $test], $closure['derivedFrom']['observation'] ?? null);
    }

    /** The order 1081 did not take: the controller scaffolded first, the plugin registered after — the scaffold still speaks. */
    public function testTheScaffoldFirstSeenWhenThePluginIsRegisteredDoesNotClose(): void
    {
        $this->land('make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], ['src/Plugins/Blog/Blog.php'], []);
        $this->land('make', ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController', 'methods' => 'index'], [self::CONTROLLER, 'src/Plugins/Blog/Blog.php'], []);
        $this->land('plugins_register', ['name' => 'Blog'], ['config/plugins.php'], [$this->served('/', str_repeat('c', 64), 43431), $this->served('blog', self::SCAFFOLD, 26)]);

        self::assertFalse($this->verdict()['verified']);
    }

    /** The scaffold seen once is that body for good: the same bytes after the controller was rewritten are still not the work. */
    public function testTheScaffoldsBodyAgainAfterTheControllerWasRewrittenDoesNotClose(): void
    {
        $this->blogPluginRegistered();
        $this->scaffoldTheController();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'BlogController'], [self::CONTROLLER], [$this->served('blog', self::SCAFFOLD, 26)]);

        self::assertFalse($this->verdict()['verified']);
    }

    /** A route served from elsewhere while the scaffold's file stood untouched: the bytes are not the scaffold's, so they are the work. */
    public function testAnotherBodyWhileTheScaffoldStandsIsTheWork(): void
    {
        $this->blogPluginRegistered();
        $this->scaffoldTheController();
        $this->land('edit', ['plugin' => 'Blog', 'class' => 'Blog'], ['src/Plugins/Blog/Blog.php'], [$this->served('blog', self::POSTS, 222)]);

        self::assertTrue($this->verdict()['verified']);
    }

    /** A scaffold implemented before the house ever saw it: the first body it sees is the work's, not the scaffold's. */
    public function testAScaffoldImplementedBeforeItWasSeenCloses(): void
    {
        $this->land('make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], ['src/Plugins/Blog/Blog.php'], []);
        $this->land('make', ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController'], [self::CONTROLLER], []);
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'BlogController'], [self::CONTROLLER], []);
        $this->land('plugins_register', ['name' => 'Blog'], ['config/plugins.php'], [$this->served('blog', self::POSTS, 222)]);

        self::assertTrue($this->verdict()['verified']);
    }

    /** Another file of the plugin written before the house first saw the route: the scaffold's file still stands. */
    public function testAnEditElsewhereBeforeTheFirstSightLeavesTheScaffoldStanding(): void
    {
        $this->land('make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], ['src/Plugins/Blog/Blog.php'], []);
        $this->land('make', ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController'], [self::CONTROLLER], []);
        $this->land('edit', ['plugin' => 'Blog', 'class' => 'Blog'], ['src/Plugins/Blog/Blog.php'], []);
        $this->land('plugins_register', ['name' => 'Blog'], ['config/plugins.php'], [$this->served('blog', self::SCAFFOLD, 26)]);

        self::assertFalse($this->verdict()['verified']);
    }

    /** A receipt without the body's digest, while the scaffold stands, cannot tell the bytes apart: it does not close. */
    public function testAServedReceiptWithoutADigestWhileTheScaffoldStandsDoesNotClose(): void
    {
        $this->blogPluginRegistered();
        $this->land(
            'make',
            ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController', 'route' => 'blog'],
            [self::CONTROLLER],
            [['predicate' => 'served', 'route' => 'GET blog', 'subject' => 'blog', 'status' => 200, 'environment' => ['kind' => 'house']]]
        );

        self::assertFalse($this->verdict()['verified']);
    }

    /** The scaffold answered, then the route stopped answering 200: the stale route is the harder fact. */
    public function testAScaffoldThatWentStaleSaysItWentStale(): void
    {
        $this->blogPluginRegistered();
        $this->scaffoldTheController();
        $gone = $this->land(
            'edit',
            ['plugin' => 'Blog', 'class' => 'Blog'],
            ['src/Plugins/Blog/Blog.php'],
            [['predicate' => 'answered', 'route' => 'GET blog', 'subject' => 'blog', 'status' => 404, 'environment' => ['kind' => 'house']]]
        );

        self::assertContains("the house observation of «blog» went stale: it answered HTTP 404 at seq {$gone}", $this->verdict()['reasons']);
    }

    /**
     * Live, evidence/1088: `make crud … route=/blog` landed `/posts` answering an empty list; the goal's word «posts»
     * names it, the session had no todos, and the house verified it while /blog answered 404.
     */
    public function testTheCrudScaffoldOf1088DoesNotClose(): void
    {
        $this->blogPluginRegistered();
        $landed = $this->land(
            'make',
            ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post', 'table' => 'posts', 'route' => '/blog'],
            ['src/Plugins/Blog/Blog.php', 'src/Plugins/Blog/Controllers/PostController.php'],
            [$this->served('/posts', self::EMPTY_LIST, 34)]
        );

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        // The goal writes GET /blog, so /posts is not even the route asked for, and that is the reason (decisions/0555).
        self::assertContains("the house observed «/posts» served (seq {$landed}), and the goal writes «GET /blog»: only a route the goal"
            . ' writes closes it', $closure['reasons']);

        // Under a goal that writes no route, the word «posts» names /posts (0522) and the scaffold is what refuses it.
        $this->store->setGoal('s', self::GOAL_IN_WORDS);
        self::assertContains("the house observed «/posts» serving the body of its scaffold (seq {$landed}): what «make» generated is"
            . ' not the work', $this->verdict()['reasons']);
    }

    /** The same crud under a goal that writes no route, once a post exists: the list is no longer the scaffold's, and it closes. */
    public function testTheCrudScaffoldServingAnotherBodyCloses(): void
    {
        $this->store->setGoal('s', self::GOAL_IN_WORDS);
        $this->blogPluginRegistered();
        $this->land('make', ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::EMPTY_LIST, 34)]);
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'Blog'], ['src/Plugins/Blog/Blog.php'], [$this->served('/posts', self::POSTS, 222)]);

        self::assertTrue($this->verdict()['verified']);
    }

    /** A route the house had already seen is not born of a later scaffold: its body keeps counting. */
    public function testARouteSeenBeforeIsNotGivenToALaterScaffold(): void
    {
        $this->store->setGoal('s', self::GOAL_IN_WORDS);
        $this->blogPluginRegistered();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostsController'], ['src/Plugins/Blog/Controllers/PostsController.php'], [$this->served('/posts', self::POSTS, 222)]);
        $this->land('make', ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Tag'], ['src/Plugins/Blog/Controllers/TagController.php'], [$this->served('/posts', self::POSTS, 222), $this->served('/tags', self::EMPTY_LIST, 34)]);

        self::assertTrue($this->verdict()['verified']);
    }

    /** A trial that held more than scaffolds: what it serves first is not known to be a scaffold's. */
    public function testAPromotionOfMoreThanScaffoldsGivesItsRoutesToNoScaffold(): void
    {
        $this->store->setGoal('s', self::GOAL_IN_WORDS);
        $this->blogPluginRegistered();
        $this->land('make', ['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Post'], ['src/Plugins/Blog/Entities/Post.php'], [$this->served('/posts', self::POSTS, 222)]);

        self::assertTrue($this->verdict()['verified']);
    }

    /** The on-demand observer (0549) is read the same way: route:observe on the scaffold does not close. */
    public function testRouteObserveOnTheScaffoldDoesNotClose(): void
    {
        $this->blogPluginRegistered();
        $this->land('make', ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController', 'route' => '/blog'], [self::CONTROLLER], []);
        $this->store->recordToolCall('s', 'route_observe', ['path' => '/blog'], (string) json_encode([
            'ok' => true, 'observed' => [$this->served('/blog', self::SCAFFOLD, 26)], 'excerpt' => 'BlogController is running.',
        ]), mutating: false);

        self::assertFalse($this->verdict()['verified']);
    }

    /** A closure on another route does not count: the scaffold's /blog stays open while /about, which the goal does not name, serves. */
    public function testAnotherRouteServedDoesNotCloseTheScaffoldedOne(): void
    {
        $this->blogPluginRegistered();
        $this->scaffoldTheController();
        $about = $this->land('implement', ['plugin' => 'Blog', 'class' => 'AboutController'], ['src/Plugins/Blog/Controllers/AboutController.php'], [$this->served('about', str_repeat('d', 64), 300)]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);
        self::assertStringContainsString('the body of its scaffold', implode('; ', $closure['reasons']));
        self::assertNotContains("the house observed «about» served (seq {$about}), a subject the goal does not name", $closure['reasons'], 'the named scaffold is the harder fact');
    }

    /** A session with todos: the house does not stand beside the record on a scaffold, so what never landed still binds. */
    public function testWithTodosTheScaffoldDoesNotSpeakForTheHouse(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'Serve GET /blog', TodoStatus::Pending));
        $this->blogPluginRegistered();
        $landed = $this->scaffoldTheController();
        $this->store->completeTodo('s', 't1', Evidence::operationOk('e1', 'sandbox_promote'));

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);

        // CONTROL: the same record, with the scaffold read as any other writer, is closed by the house (0509).
        $session = $this->store->load('s');
        self::assertNotNull($session);
        $control = ClosureVerdict::derive($session, $this->store->facts('s'), $this->withoutTheScaffold($this->store->stream('s')));
        self::assertTrue($control['verified'], implode('; ', $control['reasons']));
        self::assertSame(['subject' => 'blog', 'seq' => $landed], $control['derivedFrom']['observation'] ?? null);
    }

    /** The negative control: without the scaffold's rule the cut of 1081 closes exactly as it did. */
    public function testWithoutTheScaffoldsRuleTheCutOf1081WouldClose(): void
    {
        $this->blogPluginRegistered();
        $this->scaffoldTheController();
        $stream = $this->store->stream('s');
        $named = static fn (string $s): bool => StandingAsk::in($stream)->namesSubject($s);

        self::assertFalse(HouseObservedClosure::of($stream, $this->store->facts('s'), $named)['derived']);
        self::assertTrue(HouseObservedClosure::of($this->withoutTheScaffold($stream), $this->store->facts('s'), $named)['derived']);
    }

    /**
     * The same stream with `make controller` recorded as any other writer: the rule reads only the scaffold's own call.
     *
     * @param list<Event> $stream
     *
     * @return list<Event>
     */
    private function withoutTheScaffold(array $stream): array
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

    /** 1081's seq 126 → 133: `make controller … route=blog`, promoted; GET blog answers the scaffold. */
    private function scaffoldTheController(): int
    {
        return $this->land(
            'make',
            ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController', 'methods' => 'index', 'route' => 'blog'],
            ['src/Plugins/Blog/Blog.php', self::CONTROLLER],
            [$this->served('blog', self::SCAFFOLD, 26)]
        );
    }

    /**
     * A call that ran in its own trial, then the promotion that landed it — the receipts as 1081 recorded them.
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
