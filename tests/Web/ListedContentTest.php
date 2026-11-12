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

namespace App\Plugins\ListBlog\Entities {
    use Milpa\Data\EntityInterface;

    /** A generated-shape entity that declares what of it is public. */
    final readonly class Post implements EntityInterface
    {
        public const PUBLIC_WHEN = 'published';

        public function __construct(public int|string|null $id, public string $title, public string $body, public bool $published)
        {
        }

        public function id(): int|string|null
        {
            return $this->id;
        }

        /** @return array<string, mixed> */
        public function toArray(): array
        {
            return ['id' => $this->id, 'title' => $this->title, 'body' => $this->body, 'published' => $this->published];
        }

        /** @param array<string, mixed> $row */
        public static function fromArray(array $row): static
        {
            return new self($row['id'] ?? null, $row['title'], $row['body'], $row['published']);
        }
    }
}

namespace Milpa\AppRuntime\Tests\Web {
    use App\Plugins\ListBlog\Entities\Post;
    use Milpa\AppRuntime\Web\DeclaredScreensPageProvider;
    use Milpa\AppRuntime\Web\ListedContent;
    use Milpa\AppRuntime\Web\LivePageProvider;
    use Milpa\AppRuntime\Web\ScreenStore;
    use Milpa\Data\InMemoryRepository;
    use PHPUnit\Framework\TestCase;
    use Psr\Http\Message\ServerRequestInterface;

    /**
     * WHAT A MOUNTED SCREEN SERVED, AGAINST WHAT IT HAD TO SERVE (greenhouse decisions/0576, slice BV-3b).
     *
     * Measured (greenhouse evidence/1108): on app-runtime 0.208.0 the house closed `verified: true` over a mounted
     * screen that read «Nothing to read yet.». The screen's declaration says which entity it lists and which fields
     * it paints; the entity says which rows are public. The house only had to compare.
     */
    final class ListedContentTest extends TestCase
    {
        private string $dir = '';

        private InMemoryRepository $posts;

        protected function setUp(): void
        {
            $this->dir = sys_get_temp_dir() . '/milpa-listed-' . bin2hex(random_bytes(6));
            mkdir($this->dir, 0o777, true);
            $this->posts = new InMemoryRepository(Post::class);
            $this->store()->declare(['name' => 'blog', 'type' => 'content', 'route' => '/blog', 'props' => ['roles' => ['title' => 'title', 'body' => 'body']],
                'source' => ['entity' => Post::class, 'columns' => ['title', 'body'], 'limit' => 2]]);
        }

        protected function tearDown(): void
        {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }

        public function testAPageThatListsWhatIsPublicAndWithholdsTheRest(): void
        {
            $this->post('Hello, blog', 'the public body', true);
            $this->post('A draft', 'not for visitors', false);

            self::assertSame(
                ['entity' => 'ListBlog/Post', 'public' => 1, 'shown' => 1, 'withheld' => 1, 'leaked' => 0, 'withholding' => 'exercised'],
                $this->judge('<section><article><h2 class="title">Hello, blog</h2><div><p>the public body</p></div></article></section>'),
            );
        }

        public function testAnEmptyPageHasNothingPublicToShow(): void
        {
            self::assertSame(
                ['entity' => 'ListBlog/Post', 'public' => 0, 'shown' => 0, 'withheld' => 0, 'leaked' => 0, 'withholding' => 'unexercised'],
                $this->judge('<section><p class="empty">Nothing to read yet.</p></section>'),
            );

            $this->post('Only a draft', 'x', false);
            self::assertSame(['public' => 0, 'withheld' => 1], array_intersect_key($this->judge('<p>Nothing to read yet.</p>') ?? [], ['public' => 0, 'withheld' => 0]), 'a draft is not a public row');
        }

        public function testARowCountsAsShownOnlyWithEveryFieldTheScreenPaints(): void
        {
            $this->post('Hello, blog', 'the public body', true);
            $this->post('Second', 'another body', true);

            $half = $this->judge('<h2>Hello, blog</h2><p>the public body</p><h2>Second</h2>');

            self::assertSame(['public' => 2, 'shown' => 1], array_intersect_key($half ?? [], ['public' => 0, 'shown' => 0]), 'a title without its body is not the row');
        }

        public function testAFieldIsReadAsAVisitorReadsItNotAsItIsEscaped(): void
        {
            $this->post('Tom & Jerry <3', "two\nlines", true);

            $said = $this->judge('<h2>Tom &amp; Jerry &lt;3</h2><div><p>two</p>   <p>lines</p></div><script>var x = "noise";</script>');

            self::assertSame(1, $said['shown'] ?? null);
        }

        public function testWhatAScriptOrAStyleCarriesIsNotWhatAVisitorReads(): void
        {
            $this->post('Plain title', 'plain body', true);

            self::assertSame(0, $this->judge('<script>var seed = ["Plain title", "plain body"];</script><style>/* Plain title plain body */</style><p>Nothing to read yet.</p>')['shown'] ?? null);
            self::assertSame(1, $this->judge('<script>var x = 1;</script><h2>Plain title</h2><p>plain body</p>')['shown'] ?? null);
        }

        public function testARowThatIsNotPublicAndShowsOnThePageLeaked(): void
        {
            $this->post('Hello, blog', 'the public body', true);
            $this->post('Secret draft', 'the draft body', false);

            $leaked = $this->judge('<h2>Hello, blog</h2><p>the public body</p><h2>Secret draft</h2>');
            self::assertSame(['shown' => 1, 'withheld' => 1, 'leaked' => 1], array_intersect_key($leaked ?? [], ['shown' => 0, 'withheld' => 0, 'leaked' => 0]));

            self::assertSame(1, $this->judge('<h2>Secret draft</h2><p>the draft body</p>')['leaked'] ?? null, 'a row leaks once, however many of its fields show');

            // A draft whose title is also a published title cannot be told apart from it: it is not counted as leaked.
            $this->post('Hello, blog', 'a second secret', false);
            self::assertSame(1, $this->judge('<h2>Hello, blog</h2><p>the public body</p><h2>Secret draft</h2>')['leaked'] ?? null);
            self::assertSame(0, $this->judge('<h2>Hello, blog</h2><p>the public body</p>')['leaked'] ?? null);
        }

        public function testOnlyTheRowsTheScreenServesAreAskedOfThePage(): void
        {
            foreach (['One', 'Two', 'Three'] as $title) {
                $this->post($title, 'body of ' . $title, true);
            }

            $said = $this->judge('<h2>One</h2><p>body of One</p><h2>Two</h2><p>body of Two</p>');

            self::assertSame(['public' => 2, 'shown' => 2], array_intersect_key($said ?? [], ['public' => 0, 'shown' => 0]), 'the screen serves two: the third is not owed');
        }

        public function testATableThatDeclaresNothingListsNothing(): void
        {
            // greenhouse evidence/1211, H2: exactly the screen a resident closed `verified: true` over — a data-table
            // with columns and no source, no rows of its own.
            $this->store()->declare(['name' => 'blogpage', 'type' => 'data-table', 'route' => '/tabla', 'columns' => ['title', 'body']]);
            $said = ListedContent::of($this->store(), '/tabla', static fn (string $id): ?object => null, '<table><thead><tr></tr></thead></table>');

            self::assertSame(
                ['entity' => null, 'public' => 0, 'shown' => 0, 'withheld' => 0, 'leaked' => 0, 'withholding' => 'unexercised', 'declares' => 'no rows', 'screen' => 'blogpage'],
                $said,
                'nothing on it to read is judged, not left unjudged',
            );

            $this->store()->declare(['name' => 'empty', 'route' => '/vacia', 'columns' => [['key' => 't', 'label' => 'T']], 'rows' => []]);
            self::assertSame('no rows', ListedContent::of($this->store(), '/vacia', static fn (string $id): ?object => null, 'x')['declares'] ?? null, 'rows: [] declares no rows either');

            // The other type whose contract lists rows (C11 review of the H2 fix, H-1): «Nothing to read yet.».
            $this->store()->declare(['name' => 'posts', 'type' => 'content', 'route' => '/posts', 'props' => ['roles' => ['title' => 'title', 'body' => 'body']]]);
            self::assertSame('no rows', ListedContent::of($this->store(), '/posts', static fn (string $id): ?object => null, 'posts Nothing to read yet.')['declares'] ?? null, 'a content screen with no rows lists nothing too');
        }

        public function testAScreenTheAppsOwnProviderMayFillIsNotJudgedEmpty(): void
        {
            // decisions/0159: the app's provider is asked first, and its code may hand a declared table its rows.
            $this->store()->declare(['name' => 'pages', 'type' => 'data-table', 'route' => '/pages', 'columns' => ['t']]);
            $app = new class () implements LivePageProvider {
                public function propsFor(string $component, ServerRequestInterface $request): ?array
                {
                    return $component === 'pages' ? ['columns' => [['key' => 't', 'label' => 'T']], 'rows' => [['t' => 'row from the app provider']]] : null;
                }
            };
            $service = static fn (string $id): ?object => $id === LivePageProvider::class ? $app : null;

            self::assertNull(ListedContent::of($this->store(), '/pages', $service, 'T row from the app provider'), 'the declaration alone does not say the page is empty');

            // A provider that is there and declines the screen by name (every house from the image registers one):
            // the declared screen is what serves, and it declares nothing (C11 review of the H2 fix, round 2, H-2).
            $declines = new class () implements LivePageProvider {
                public function propsFor(string $component, ServerRequestInterface $request): ?array
                {
                    return $component === 'data-table' ? ['columns' => [], 'rows' => []] : null;
                }
            };
            self::assertSame('no rows', ListedContent::of($this->store(), '/pages', static fn (string $id): ?object => $id === LivePageProvider::class ? $declines : null, 'T')['declares'] ?? null, 'a provider that answers for other names does not fill this one');

            // An answer with nothing to list fills nothing (C11 review of the H2 fix, round 3, L-3).
            foreach ([[], ['columns' => [['key' => 't', 'label' => 'T']], 'rows' => []]] as $empty) {
                $answers = new class ($empty) implements LivePageProvider {
                    /** @param array<string, mixed> $props */
                    public function __construct(private readonly array $props)
                    {
                    }

                    public function propsFor(string $component, ServerRequestInterface $request): ?array
                    {
                        return $component === 'pages' ? $this->props : null;
                    }
                };
                self::assertSame('no rows', ListedContent::of($this->store(), '/pages', static fn (string $id): ?object => $id === LivePageProvider::class ? $answers : null, 'T')['declares'] ?? null, json_encode($empty) ?: '');
            }

            // An answer that binds an entity's rows is the app's binding to judge, not the declaration's emptiness.
            $binds = new class () implements LivePageProvider {
                public function propsFor(string $component, ServerRequestInterface $request): ?array
                {
                    return $component === 'pages' ? ['source' => ['entity' => 'ListBlog/Post', 'columns' => ['title']], 'rows' => []] : null;
                }
            };
            self::assertNull(ListedContent::of($this->store(), '/pages', static fn (string $id): ?object => $id === LivePageProvider::class ? $binds : null, 'x'), 'a binding the app hands over is not an empty page');

            // It is asked as the mounted page asks it: `component` names the screen (round 3, L-2).
            $byQuery = new class () implements LivePageProvider {
                public function propsFor(string $component, ServerRequestInterface $request): ?array
                {
                    return ($request->getQueryParams()['component'] ?? null) === 'pages' && $request->getMethod() === 'GET' && $request->getUri()->getPath() === '/pages'
                        ? ['rows' => [['t' => 'row from the app provider']]] : null;
                }
            };
            self::assertNull(ListedContent::of($this->store(), '/pages', static fn (string $id): ?object => $id === LivePageProvider::class ? $byQuery : null, 'T row from the app provider'), 'what the page is asked, the judge asks');

            $throws = new class () implements LivePageProvider {
                public function propsFor(string $component, ServerRequestInterface $request): ?array
                {
                    throw new \RuntimeException('the app provider failed');
                }
            };
            self::assertNull(ListedContent::of($this->store(), '/pages', static fn (string $id): ?object => $id === LivePageProvider::class ? $throws : null, 'x'), 'what the house cannot read it does not judge empty');

            $declared = new DeclaredScreensPageProvider($this->store(), static fn (string $id): ?object => null);
            self::assertSame('no rows', ListedContent::of($this->store(), '/pages', static fn (string $id): ?object => $id === LivePageProvider::class ? $declared : null, 'x')['declares'] ?? null, 'the runtime\'s own provider serves the declaration and nothing else');
        }

        public function testWhatTheHouseCannotJudgeIsNotJudged(): void
        {
            $this->post('Hello, blog', 'the public body', true);
            $service = fn (string $id): ?object => $id === Post::class . 'Repository' ? $this->posts : null;

            self::assertNull(ListedContent::of($this->store(), '/elsewhere', $service, 'x'), 'no screen is mounted there');
            self::assertNull(ListedContent::of($this->store(), '/blog', static fn (string $id): ?object => null, 'x'), 'no repository to ask');

            $this->store()->declare(['name' => 'plain', 'route' => '/plain', 'columns' => [['key' => 't', 'label' => 'T']], 'rows' => [['t' => 'literal']]]);
            self::assertNull(ListedContent::of($this->store(), '/plain', $service, 'literal'), 'literal rows are the declaration\'s own: nothing to compare');

            $this->store()->declare(['name' => 'howmany', 'route' => '/count', 'type' => 'metric-card', 'source' => ['entity' => Post::class, 'count' => true]]);
            self::assertNull(ListedContent::of($this->store(), '/count', $service, '1'), 'a count is a value, not a list of rows');

            $this->store()->declare(['name' => 'card', 'route' => '/card', 'type' => 'metric-card', 'props' => ['label' => 'Posts', 'value' => 3]]);
            self::assertNull(ListedContent::of($this->store(), '/card', $service, '3'), 'a screen that does not list rows is not judged as an empty table');

            self::assertNotNull(ListedContent::of($this->store(), 'Blog/', $service, 'x'), 'the route is the same route however it is spelled');
        }

        private function post(string $title, string $body, bool $published): void
        {
            $this->posts->save(new Post(null, $title, $body, $published));
        }

        /** @return array<string, mixed>|null */
        private function judge(string $body): ?array
        {
            return ListedContent::of($this->store(), '/blog', fn (string $id): ?object => $id === Post::class . 'Repository' ? $this->posts : null, $body);
        }

        private function store(): ScreenStore
        {
            return ScreenStore::fromConfig(['screens_path' => $this->dir . '/screens.json'], $this->dir);
        }
    }
}
