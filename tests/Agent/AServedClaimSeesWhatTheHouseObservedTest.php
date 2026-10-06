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

use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A `screen-served` claim sees what the house observed (greenhouse decisions/0580, evidence/1118).
 *
 * The house observes a route when a promotion lands and when `route:observe` is asked, and leaves a `served`
 * receipt under `observed[]` — the receipt its own closure reads (greenhouse decisions/0494, 0576). The claims
 * judge looked only under `evidence`. Measured (evidence/1109 §6.4): three times it answered «Nothing in this
 * session is of that kind yet» with two receipts of `/blog` served in the house on record, and the todo was
 * closed instead as `operation-ok` / `route_observe` — «a call answered», which says nothing of the page.
 *
 * The judge now reads those receipts too, through the same reading the house closes by, so nothing of the
 * contract decisions/0576 fixed is derived a second time: only the house's own receipt, only a 200, only the
 * last thing the house saw of that route, only after the last change that landed, and — when the receipt says
 * what the page listed — only a page that lists.
 *
 * @guards a claim for a route covered by the house's own `served` receipt of it, with the content the receipt
 *         carries; the refusal saying what the house saw instead, and naming a call the house runs
 *
 * @refuses a receipt earned in a trial; a page that does not list; a route the house saw served before its last
 *          change, or that it last saw failing; a screen served at its own page taken for the route of the same
 *          name, and the route for the screen; a hint with a sentence inside a call
 *
 * @subject-in milpa/app-runtime
 */
final class AServedClaimSeesWhatTheHouseObservedTest extends TestCase
{
    private const LISTS = ['entity' => 'Blog/Post', 'public' => 1, 'shown' => 1, 'withheld' => 1, 'leaked' => 0, 'withholding' => 'exercised'];
    private const EMPTY = ['entity' => 'Blog/Post', 'public' => 0, 'shown' => 0, 'withheld' => 0, 'leaked' => 0, 'withholding' => 'unexercised'];

    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'Build the blog: serve GET /blog as a page listing published posts.');
        $this->store->setTodo('s', new Todo('t1', 'Confirm /blog is served', TodoStatus::Pending));
    }

    public function testARouteThePromotionObservedServedCoversTheClaim(): void
    {
        $seq = $this->promote([self::route('/blog', self::LISTS)]);

        $claimed = $this->claim('/blog');

        self::assertTrue($claimed['ok'], (string) ($claimed['error'] ?? ''));
        self::assertSame(
            ['fact' => 'observed', 'predicate' => 'served', 'subject' => '/blog', 'environment' => 'house', 'seq' => $seq, 'fresh' => true, 'content' => self::LISTS],
            $claimed['evidence']['coveredBy'],
            'the receipt that closed it, with what the page listed — read from the receipt',
        );
    }

    public function testARouteThatRouteObserveSawServedCoversTheClaim(): void
    {
        $this->promote([]);
        $seq = $this->observe([self::route('/blog', self::LISTS)]);

        $claimed = $this->claim('/blog');

        self::assertTrue($claimed['ok'], (string) ($claimed['error'] ?? ''));
        self::assertSame($seq, $claimed['evidence']['coveredBy']['seq']);
    }

    /** greenhouse decisions/0576: a todo is not closed with a `served` that does not list. */
    #[\PHPUnit\Framework\Attributes\DataProvider('pagesThatDoNotList')]
    public function testAPageThatDoesNotListDoesNotCloseATodo(array $content, string $why): void
    {
        $this->promote([self::route('/blog', $content)]);

        $refused = $this->claim('/blog');

        self::assertFalse($refused['ok']);
        self::assertStringContainsString($why, (string) $refused['error']);
        self::assertStringContainsString('route:observe {"path":"/blog"}', (string) $refused['error'], 'and how to have the house look again');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function pagesThatDoNotList(): iterable
    {
        yield 'nothing to read' => [self::EMPTY, 'the house observed «/blog» served with nothing to read'];
        yield 'part of what is public' => [['public' => 2, 'shown' => 1] + self::LISTS, 'showing 1 of 2 public rows of Blog/Post'];
        yield 'a row that is not public' => [['leaked' => 1] + self::LISTS, 'showing 1 row of Blog/Post that is not public'];
    }

    public function testTheLastThingTheHouseSawOfTheRouteDecides(): void
    {
        $this->promote([self::route('/blog', self::LISTS)]);
        $this->observe([self::route('/blog', self::EMPTY)]);
        self::assertFalse($this->claim('/blog')['ok'], 'it listed, and no longer does');

        $this->observe([self::route('/blog', self::LISTS)]);
        self::assertTrue($this->claim('/blog')['ok'], 'and lists again');
    }

    /** greenhouse decisions/0579 §4: no page, or a receipt older than that slice — the house affirms nothing. */
    public function testAReceiptThatIsNoPageCoversAsItClosesTheHouseAndSaysNothingOfItsContent(): void
    {
        $this->promote([self::route('/feed.json', null)]);

        $claimed = $this->claim('/feed.json');

        self::assertTrue($claimed['ok'], (string) ($claimed['error'] ?? ''));
        self::assertArrayNotHasKey('content', $claimed['evidence']['coveredBy'], 'what the house did not judge is not said to list');
        self::assertArrayNotHasKey('surface', $claimed['evidence']['coveredBy']);
    }

    /**
     * greenhouse decisions/0579 §3: a page the house saw served and did not read closes, and whoever reads the
     * verdict is told so. «unjudged» is neither a failure nor a pass, and it is never left out.
     */
    public function testAPageTheHouseDidNotReadCoversAndSaysItWasNotRead(): void
    {
        $byHand = ['contentType' => 'text/html; charset=utf-8', 'surface' => ['kind' => 'visual', 'screen' => null]];
        $this->promote([$byHand + self::route('/about', null)]);

        $claimed = $this->claim('/about');

        self::assertTrue($claimed['ok'], (string) ($claimed['error'] ?? ''));
        self::assertSame('unjudged', $claimed['evidence']['coveredBy']['content'], 'the house saw it served and did not read it: said, not left out');
        self::assertSame(['kind' => 'visual', 'screen' => null], $claimed['evidence']['coveredBy']['surface']);
    }

    public function testAPageTheHouseReadSaysWhichScreenServedIt(): void
    {
        $mounted = ['contentType' => 'text/html; charset=utf-8', 'surface' => ['kind' => 'visual', 'screen' => 'blog']];
        $this->promote([$mounted + self::route('/blog', self::LISTS)]);

        $covered = $this->claim('/blog')['evidence']['coveredBy'];

        self::assertSame(self::LISTS, $covered['content']);
        self::assertSame(['kind' => 'visual', 'screen' => 'blog'], $covered['surface']);
    }

    public function testWhatATrialObservedNeverCoversTheHouse(): void
    {
        $this->store->recordToolCall('s', 'screen_declare', ['name' => 'blog'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'changed' => ['config/screens.json' => 'added'],
            'output' => ['ok' => true], 'observed' => [['environment' => ['kind' => 'trial', 'workspace' => 'w1']] + self::route('/blog', self::LISTS)],
        ]), true, true);
        $this->store->recordToolCall('s', 'route_observe', ['path' => '/blog'], (string) json_encode([
            'ok' => true, 'observed' => [['environment' => ['kind' => 'trial', 'workspace' => 'w1']] + self::route('/blog', self::LISTS)],
        ]));
        // A rehearsal whose result says «house» still ran in the copy: the trial is what it is, not what it says.
        $this->store->recordToolCall('s', 'screen_declare', ['name' => 'blog'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w2', 'changed' => ['config/screens.json' => 'added'],
            'output' => ['ok' => true], 'observed' => [self::route('/blog', self::LISTS)],
        ]), true, true);

        $refused = $this->claim('/blog');

        self::assertFalse($refused['ok']);
        self::assertStringNotContainsString('«/blog»,', (string) $refused['error']);
        self::assertStringContainsString('Nothing in this session is of that kind yet', (string) $refused['error']);
    }

    public function testACallThatFailedLeftNoReceipt(): void
    {
        $this->store->recordToolCall('s', 'route_observe', ['path' => '/blog'], (string) json_encode(['ok' => true, 'observed' => [self::route('/blog', self::LISTS)]]), false);
        $this->store->recordToolCall('s', 'route_observe', ['path' => '/blog'], (string) json_encode(['ok' => false, 'error' => 'the house did not answer', 'observed' => [self::route('/blog', self::LISTS)]]));

        self::assertStringContainsString('Nothing in this session is of that kind yet', (string) $this->claim('/blog')['error']);
    }

    /** The claim door reads which calls last as the house's closure does (greenhouse decisions/0523). */
    public function testACallThatDoesNotLastDoesNotMakeTheObservationStale(): void
    {
        $this->promote([self::route('/blog', self::LISTS)]);
        $this->store->recordToolCall('s', 'test', ['filter' => 'BlogTest'], '{"ok":true,"tests":3}', true, true);

        $says = static fn (string $tool, array $arguments): ?bool => $tool === 'test' ? false : null;
        self::assertTrue($this->claim('/blog', 't1', $says)['ok'], 'a test run changes nothing in the house');
    }

    public function testAChangeThatLandedAfterTheObservationMakesItStale(): void
    {
        $seen = $this->promote([self::route('/blog', self::LISTS)]);
        $changed = $this->promote([]);

        $refused = $this->claim('/blog');

        self::assertFalse($refused['ok']);
        self::assertStringContainsString("the house changed at seq {$changed} after its last observation (seq {$seen})", (string) $refused['error']);
        self::assertStringContainsString('route:observe {"path":"/blog"}', (string) $refused['error']);
    }

    public function testARouteTheHouseLastSawFailingDoesNotCover(): void
    {
        $this->promote([self::route('/blog', self::LISTS)]);
        $failed = $this->observe([['route' => 'GET /blog', 'subject' => '/blog', 'status' => 404, 'environment' => ['kind' => 'house']]]);

        $refused = $this->claim('/blog');

        self::assertFalse($refused['ok']);
        self::assertStringContainsString("the house answered «/blog» with HTTP 404 at seq {$failed}", (string) $refused['error']);
    }

    public function testOnlyA200IsServedWhateverTheEntrySaysOfItself(): void
    {
        $seq = $this->observe([['status' => 302] + self::route('/blog', self::LISTS)]);

        $refused = $this->claim('/blog');

        self::assertFalse($refused['ok']);
        self::assertStringContainsString("the house answered «/blog» with HTTP 302 at seq {$seq}", (string) $refused['error']);
    }

    public function testAServerErrorOnAnyRouteTheHouseObservedPreventsItAsItPreventsTheClosure(): void
    {
        $seq = $this->promote([self::route('/blog', self::LISTS), ['route' => 'GET /feed', 'subject' => '/feed', 'status' => 500, 'environment' => ['kind' => 'house']]]);

        $refused = $this->claim('/blog');

        self::assertFalse($refused['ok']);
        self::assertStringContainsString("the house answered «/feed» with HTTP 500 at seq {$seq}", (string) $refused['error']);
    }

    /** greenhouse decisions/0576 §8: a screen served at its own page is not the route of the same name. */
    public function testTheScreenAndTheRouteAreDifferentReferences(): void
    {
        $this->store->recordToolCall('s', 'screen_observe', ['name' => 'blog'], (string) json_encode([
            'ok' => true, 'screen' => 'blog', 'status' => 200, 'servedAt' => '/live/page?component=blog',
            'evidence' => ['predicate' => 'served', 'subject' => 'blog', 'servedAt' => '/live/page?component=blog', 'environment' => ['kind' => 'house']],
        ]));

        $refused = $this->claim('/blog');
        self::assertFalse($refused['ok'], 'nobody saw /blog: the screen was served at /live/page');
        self::assertStringContainsString('«blog»', (string) $refused['error'], 'the reference the session does hold is offered');

        $this->store->setTodo('s', new Todo('t2', 'Mount it', TodoStatus::Pending));
        $this->observe([self::route('/shop', self::LISTS)]);
        $notAScreen = $this->claim('shop', 't2');
        self::assertFalse($notAScreen['ok'], 'and a route the house observed is not a screen of that name');
        self::assertStringContainsString('screen:observe {"name":"shop"}', (string) $notAScreen['error'], 'nothing was observed under that name: the refusal says how to');
        self::assertTrue($this->claim('blog', 't2')['ok'], 'the screen covers as it always did');
    }

    public function testTheRefusalOffersTheRoutesTheJudgeWouldAcceptAndOnlyThose(): void
    {
        $this->promote([self::route('/blog', self::LISTS), self::route('/drafts', self::EMPTY)]);

        $refused = $this->claim('route_observe GET /blog in the house: HTTP 200, content {public:1, shown:1}');

        self::assertFalse($refused['ok']);
        self::assertStringContainsString('This session holds, exactly as a reference: «/blog»', (string) $refused['error']);
        self::assertStringNotContainsString('«/drafts»', (string) $refused['error'], 'a page that does not list is not offered');
        self::assertTrue($this->claim('/blog')['ok'], 'and the one it names is accepted');
    }

    /** The hint used to print the whole sentence inside a call: `screen:observe {"name":"route_observe GET /blog …"}`. */
    public function testTheHintNamesACallTheHouseRunsOrNone(): void
    {
        $prose = (string) $this->claim('route_observe GET /blog in the house: HTTP 200')['error'];
        self::assertStringNotContainsString('{"name":"route_observe', $prose);
        self::assertStringNotContainsString('{"path":"route_observe', $prose);
        self::assertStringContainsString('the screen\'s name or the route, alone', $prose);

        self::assertStringContainsString('route:observe {"path":"/shop"}', (string) $this->claim('/shop')['error'], 'a route nobody observed: the verb that observes it');
        self::assertStringContainsString('screen:observe {"name":"shop"}', (string) $this->claim('shop')['error'], 'a screen nobody observed: the verb that observes it');
    }

    /**
     * A promotion that landed in the house and what the house observed after it.
     *
     * @param list<array<string, mixed>> $observed
     */
    private function promote(array $observed): int
    {
        static $n = 0;
        $workspace = 'w' . ++$n;

        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => $workspace], (string) json_encode([
            'ok' => true,
            'promoted' => ['config/screens.json'],
            'evidence' => ['predicate' => 'promoted', 'subject' => $workspace, 'environment' => ['kind' => 'house'], 'paths' => ['config/screens.json']],
        ] + ($observed === [] ? [] : ['observed' => $observed])), true, true);
    }

    /**
     * `route:observe`, as the house answers it.
     *
     * @param list<array<string, mixed>> $observed
     */
    private function observe(array $observed): int
    {
        return $this->store->recordToolCall('s', 'route_observe', ['path' => $observed[0]['subject']], (string) json_encode(['ok' => true, 'observed' => $observed]));
    }

    /**
     * @param array<string, mixed>|null $content
     *
     * @return array<string, mixed>
     */
    private static function route(string $subject, ?array $content): array
    {
        return ['predicate' => 'served', 'route' => 'GET ' . $subject, 'subject' => $subject, 'status' => 200, 'environment' => ['kind' => 'house'],
            'servedAt' => $subject, 'bytes' => 3551, 'sha256' => hash('sha256', $subject . json_encode($content))] + ($content === null ? [] : ['content' => $content]);
    }

    /** @return array<string, mixed> */
    private function claim(string $reference, string $todo = 't1', ?\Closure $lasting = null): array
    {
        foreach ((new SessionBookkeeping($this->store, 's', $this->events, $lasting))->operations() as $operation) {
            if ($operation->name === 'work:claim-verified') {
                /** @var array<string, mixed> */
                return ($operation->handler)(['todo' => $todo, 'kind' => 'screen-served', 'reference' => $reference]);
            }
        }
        self::fail('work:claim-verified is not offered');
    }
}
