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
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\EventStore\Event;
use Milpa\EventStore\FileEventStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The body of a scaffold is the scaffold's on whatever route serves it (greenhouse decisions/0556).
 *
 * Measured live (evidence/1089, overlay): `make crud … route=/blog` landed `/posts` answering the empty list `make`
 * generated (seq 117, 34 bytes). The resident then edited the route from `/posts` to `/blog`, and the house observed
 * `GET /blog` → 200 with the very same 34 bytes (seq 133). The scaffold rule (0554) knew that body by the route it was
 * born on; `/blog` had been seen before (404) and the change was an `edit`, so the house counted it and stood beside
 * the record. Five todos were open, so nothing closed; a session without todos would have been verified on an empty
 * list at the route the goal writes.
 *
 * The recorded stream is read from a cut of its FILE (`tests/Fixtures/streams/`), line by line up to seq 133, without
 * the lines that carry the model's transcript, and with what names the lab written neutral (as 1088's fixture is). The
 * session WITHOUT todos is that same file without its seven `todo_changed` lines and its one `evidence_recorded`: no
 * cattle recorded one.
 *
 * @guards another body on that route closes; a route that serves those bytes with no scaffold seen still counts
 *
 * @refuses the bytes the house learned as a scaffold's, served by any route, then or after
 *
 * @subject-in milpa/app-runtime
 */
final class AScaffoldsBodyOnAnotherRouteIsNotTheWorkTest extends TestCase
{
    private const GOAL = 'Build the blog this house was founded for: a plugin named Blog that serves GET /blog to anonymous'
        . ' visitors, listing only published posts (title and body), never drafts. Seed one published post and one draft so'
        . ' the page shows something. When it is live, confirm /blog is served.';

    private const STREAM_1089 = __DIR__ . '/../Fixtures/streams/camino-1089-overlay-upto-133.jsonl';

    private const SESSION_1089 = 'lab-session';

    private const TODO_LINES = ['session.todo_changed', 'session.evidence_recorded'];

    private const EMPTY_LIST = '2a797c8443253cb630662a190c304f5a70654f6d3c5e1f367d8bb1570fd1cac1';

    private const POSTS = 'b7fe262df4ca989dcc245225f1cf02805807f3af83e316be36456c56828cefe1';

    private const REASON = 'the house observed «/blog» serving the body of a scaffold (seq %d): what «make» generated is not the work';

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

    /** What the cattle recorded: the same bytes on /posts at seq 117 and on /blog at seq 133. */
    public function testTheStreamOf1089ServesTheSameBytesOnPostsAndThenOnBlog(): void
    {
        $seen = [];
        foreach ($this->storeOf1089(133, [])->replay(SessionStore::PREFIX . self::SESSION_1089) as $event) {
            $result = json_decode(\is_string($event->payload['result'] ?? null) ? $event->payload['result'] : '', true);
            foreach (\is_array($result) && \is_array($result['observed'] ?? null) ? $result['observed'] : [] as $entry) {
                $seen[] = [$event->seq, $entry['subject'], $entry['status'], $entry['sha256'] ?? null];
            }
        }

        self::assertContains([117, '/posts', 200, self::EMPTY_LIST], $seen);
        self::assertContains([133, '/blog', 200, self::EMPTY_LIST], $seen);
    }

    /** That file without its todo lines: the house does not close on the scaffold's empty list at /blog. */
    public function testTheStreamOf1089WithoutTodosIsNotClosedByTheScaffoldsBodyOnBlog(): void
    {
        $closure = $this->verdictOf1089(133, self::TODO_LINES);

        self::assertFalse($closure['verified'], 'verified on an empty list');
        self::assertSame('recorded_work', $closure['scope']);
        self::assertContains(sprintf(self::REASON, 133), $closure['reasons']);
    }

    /** The file as recorded, with its todos: the house does not stand beside the record on that body. */
    public function testTheStreamOf1089AsRecordedDoesNotPutTheHouseBesideTheRecord(): void
    {
        $closure = $this->verdictOf1089(133, []);

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);
        self::assertArrayNotHasKey('derivedFrom', $closure);
    }

    /** No cut of that file, with or without its todos, is closed. */
    public function testNoCutOfTheStreamOf1089IsClosed(): void
    {
        foreach ([76, 105, 117, 126, 133] as $upto) {
            foreach ([[], self::TODO_LINES] as $without) {
                $closure = $this->verdictOf1089($upto, $without);
                self::assertFalse($closure['verified'], "cut at seq {$upto}");
                self::assertArrayNotHasKey('derivedFrom', $closure, "cut at seq {$upto}");
            }
        }
    }

    /** 1089's order, in receipts: the crud lands /posts, an edit moves it to /blog, the bytes are the same. */
    public function testTheScaffoldsBodyMovedToTheRouteTheGoalWritesDoesNotClose(): void
    {
        $moved = $this->crudThenMovedToBlog();

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);
        self::assertContains(sprintf(self::REASON, $moved), $closure['reasons']);
    }

    public function testTheEpilogueDoesNotOpenOnTheMovedScaffold(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        $this->crudThenMovedToBlog();
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));

        self::assertArrayNotHasKey('epilogue', $probe->afterStep(1) ?? []);
    }

    /** route:observe on the new route reads the same bytes the same way. */
    public function testRouteObserveOnTheMovedScaffoldDoesNotClose(): void
    {
        $this->crudThenMovedToBlog();
        $this->store->recordToolCall('s', 'route_observe', ['path' => '/blog'], (string) json_encode([
            'ok' => true, 'observed' => [$this->served('/blog', self::EMPTY_LIST, 34)],
        ]), mutating: false);

        self::assertFalse($this->verdict()['verified']);
    }

    /** The work: once /blog serves other bytes, it closes on them. */
    public function testAnotherBodyOnTheNewRouteCloses(): void
    {
        $this->crudThenMovedToBlog();
        $seeded = $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/blog', self::POSTS, 223)]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => '/blog', 'seq' => $seeded], $closure['derivedFrom']['observation'] ?? null);
    }

    /** And the scaffold's bytes again after the work do not take it back as the observation, nor close by themselves. */
    public function testTheScaffoldsBodyAgainAfterTheWorkDoesNotClose(): void
    {
        $this->crudThenMovedToBlog();
        $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/blog', self::POSTS, 223)]);
        $this->land('edit', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/blog', self::EMPTY_LIST, 34)]);

        self::assertFalse($this->verdict()['verified']);
    }

    /** With todos closed (0509): the house does not stand beside the record on the moved scaffold. */
    public function testWithTodosTheMovedScaffoldDoesNotSpeakForTheHouse(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'Serve GET /blog', TodoStatus::Pending));
        $this->crudThenMovedToBlog();
        $this->store->completeTodo('s', 't1', Evidence::operationOk('e1', 'sandbox_promote'));

        self::assertSame('recorded_work', $this->verdict()['scope']);
    }

    /** The control: the same bytes on /blog when the house learned no scaffold with them count, as they always did. */
    public function testTheSameBytesWithNoScaffoldSeenStillClose(): void
    {
        $this->blogPluginRegistered();
        $served = $this->land('implement', ['plugin' => 'Blog', 'class' => 'PostController'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/blog', self::EMPTY_LIST, 34)]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => '/blog', 'seq' => $served], $closure['derivedFrom']['observation'] ?? null);
    }

    /** A receipt without a digest on the new route cannot be told apart from anything: it counts, as before. */
    public function testAReceiptWithoutADigestOnTheNewRouteStillCounts(): void
    {
        $this->blogPluginRegistered();
        $this->land('make', ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::EMPTY_LIST, 34)]);
        $this->land('edit', ['plugin' => 'Blog', 'class' => 'Blog'], ['src/Plugins/Blog/Blog.php'], [
            ['predicate' => 'served', 'route' => 'GET /blog', 'subject' => '/blog', 'status' => 200, 'environment' => ['kind' => 'house']],
        ]);

        self::assertTrue($this->verdict()['verified']);
    }

    /** A route the goal does not write, serving a scaffold's bytes it was not born with: said as what was seen instead. */
    public function testTheMovedScaffoldOnARouteTheGoalDoesNotWriteIsSaidAsUnwritten(): void
    {
        $this->blogPluginRegistered();
        $this->land('make', ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::EMPTY_LIST, 34)]);
        $moved = $this->land('edit', ['plugin' => 'Blog', 'class' => 'Blog'], ['src/Plugins/Blog/Blog.php'], [$this->served('/articles', self::EMPTY_LIST, 34)]);

        self::assertContains("the house observed «/articles» served (seq {$moved}), and the goal writes «GET /blog»: only a route the goal"
            . ' writes closes it', $this->verdict()['reasons']);
    }

    /** Only a route SERVED is read as serving: a 404 that happens to carry those bytes says nothing about a scaffold. */
    public function testAnAnswerThatIsNotServedIsNotTheScaffoldServed(): void
    {
        $this->blogPluginRegistered();
        $this->land('make', ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post'], ['src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::EMPTY_LIST, 34)]);
        $this->land('edit', ['plugin' => 'Blog', 'class' => 'Blog'], ['src/Plugins/Blog/Blog.php'], [
            ['predicate' => 'answered', 'route' => 'GET /blog', 'subject' => '/blog', 'status' => 404, 'environment' => ['kind' => 'house'], 'sha256' => self::EMPTY_LIST],
        ]);

        self::assertStringNotContainsString('«/blog» serving the body of a scaffold', implode('; ', $this->verdict()['reasons']));
    }

    private function crudThenMovedToBlog(): int
    {
        $this->blogPluginRegistered();
        $this->store->recordToolCall('s', 'route_observe', ['path' => '/blog'], (string) json_encode([
            'ok' => true, 'observed' => [['predicate' => 'answered', 'route' => 'GET /blog', 'subject' => '/blog', 'status' => 404, 'environment' => ['kind' => 'house']]],
        ]), mutating: false);
        $this->land('make', ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post', 'route' => '/blog'], ['src/Plugins/Blog/Blog.php', 'src/Plugins/Blog/Controllers/PostController.php'], [$this->served('/posts', self::EMPTY_LIST, 34)]);

        return $this->land('edit', ['plugin' => 'Blog', 'class' => 'Blog'], ['src/Plugins/Blog/Blog.php'], [$this->served('/blog', self::EMPTY_LIST, 34)]);
    }

    /**
     * @param list<string> $without event types whose lines are left out of the copy
     */
    private function storeOf1089(int $upto, array $without): FileEventStore
    {
        $copy = (string) tempnam(sys_get_temp_dir(), 'cut-1089-');
        $this->copies[] = $copy;
        $out = fopen($copy, 'w');
        self::assertIsResource($out);
        foreach ((array) file(self::STREAM_1089) as $line) {
            $row = json_decode((string) $line, true);
            self::assertIsArray($row);
            if ($row['seq'] > $upto) {
                break;
            }
            if (! \in_array($row['type'], $without, true)) {
                fwrite($out, (string) $line);
            }
        }
        fclose($out);

        return new FileEventStore($copy);
    }

    /**
     * @param list<string> $without
     *
     * @return array<string, mixed>
     */
    private function verdictOf1089(int $upto, array $without): array
    {
        $events = $this->storeOf1089($upto, $without);
        $session = (new SessionStore($events))->load(self::SESSION_1089);
        self::assertNotNull($session);
        self::assertSame($without === [] ? 6 : 0, \count($session->todos));
        $stream = $events->replay(SessionStore::PREFIX . self::SESSION_1089);

        return ClosureVerdict::derive($session, SessionFacts::fromEvents(self::SESSION_1089, $stream), $stream);
    }

    private function blogPluginRegistered(): void
    {
        $this->land('make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'], ['src/Plugins/Blog/Blog.php'], []);
        $this->land('plugins_register', ['name' => 'Blog'], ['config/plugins.php'], [$this->served('/', str_repeat('c', 64), 43431)]);
    }

    /**
     * A call that ran in its own trial, then the promotion that landed it — the receipts as 1089 recorded them.
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
