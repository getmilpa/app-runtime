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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\Controllers\LiveComponentPageController;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Data\EntityInterface;
use Milpa\Data\InMemoryRepository;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A number read live (greenhouse decisions/0478), through the real door.
 *
 * Measured: asked «how many posts are published», the resident read the data file and wrote «2» by hand —
 * a snapshot the next post makes false — or bound a table and had no number. `source: {entity, count: true}`
 * fills a `value` with how many PUBLIC rows there are, read on every request.
 *
 * @guards the public count, read per request (a new published row moves it, a draft does not)
 *
 * @refuses a type with no value prop, a value written beside a count, a count with columns, an entity that
 *          declares nothing public — and (0479) a missing required prop; an undeclared one is named, not refused
 *
 * @subject-in milpa/app-runtime
 */
final class ACountIsReadLiveTest extends TestCase
{
    private string $dir = '';

    private DIContainer $container;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-count-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        $this->container = new DIContainer();
        $this->container->registerService(Config::class, new Config(['live' => [
            'secret' => str_repeat('k', 32),
            'screens_path' => $this->dir . '/screens.json',
        ]]));
        // The house root is THIS test's directory: a word defined here writes config/components.json under it,
        // never under the repository the suite runs in (it did, before the Kernel was registered).
        $kernel = Kernel::boot(['root' => $this->dir, 'container' => $this->container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $this->container->registerService(Kernel::class, $kernel);
        (new LivePlugin($this->container))->boot();
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testTheCountIsHowManyPublicRowsThereAreReadOnEveryRequest(): void
    {
        $posts = new InMemoryRepository(CountedPost::class);
        $posts->save(CountedPost::fromArray(['id' => 1, 'title' => 'Hello', 'body' => 'x', 'published' => true]));
        $posts->save(CountedPost::fromArray(['id' => 2, 'title' => 'Secret draft', 'body' => 'x', 'published' => false]));
        $posts->save(CountedPost::fromArray(['id' => 3, 'title' => 'Also out', 'body' => 'x', 'published' => true]));
        $this->container->registerService(CountedPost::class . 'Repository', $posts);

        $declared = $this->declare(['name' => 'post-count', 'type' => 'metric-card', 'props' => ['title' => 'Published posts'],
            'source' => ['entity' => CountedPost::class, 'count' => true]]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame('2', $this->value(), 'the drafts are not counted');

        $posts->save(CountedPost::fromArray(['id' => 4, 'title' => 'Fresh', 'body' => 'x', 'published' => true]));
        self::assertSame('3', $this->value(), 'read now, not when it was declared');
        $posts->save(CountedPost::fromArray(['id' => 5, 'title' => 'Another draft', 'body' => 'x', 'published' => false]));
        self::assertSame('3', $this->value(), 'a draft never moves it');
        self::assertStringNotContainsString(CountedPost::class, $this->page(), 'the binding never reaches the page');
    }

    public function testEachWrongShapeIsRefusedByName(): void
    {
        $cases = [
            'source' => ['name' => 'a', 'type' => 'data-table', 'source' => ['entity' => CountedPost::class, 'count' => true]],
            'value' => ['name' => 'b', 'type' => 'metric-card', 'props' => ['title' => 'P', 'value' => '9'], 'source' => ['entity' => CountedPost::class, 'count' => true]],
            'source.columns' => ['name' => 'c', 'type' => 'metric-card', 'props' => ['title' => 'P'], 'source' => ['entity' => CountedPost::class, 'count' => true, 'columns' => ['title']]],
            'source.entity' => ['name' => 'd', 'type' => 'metric-card', 'props' => ['title' => 'P'], 'source' => ['entity' => UncountedNote::class, 'count' => true]],
        ];
        foreach ($cases as $path => $input) {
            $refused = $this->declare($input);
            self::assertFalse($refused['ok'], $path);
            self::assertSame($path, $refused['path'] ?? null, json_encode($refused) ?: '');
        }
        self::assertStringContainsString('declares no value prop', $this->declare($cases['source'])['reason']);
    }

    /**
     * The contract holds at declaration (greenhouse decisions/0479): measured with the count, a card declared
     * without its title said «2» without saying of what, and `label` (not a prop) was dropped in silence.
     */
    public function testAMissingRequiredPropIsRefusedAndAnUnknownOneIsNamed(): void
    {
        $posts = new InMemoryRepository(CountedPost::class);
        $this->container->registerService(CountedPost::class . 'Repository', $posts);

        $untitled = $this->declare(['name' => 'post-count', 'type' => 'metric-card', 'source' => ['entity' => CountedPost::class, 'count' => true]]);
        self::assertFalse($untitled['ok']);
        self::assertSame('props.title', $untitled['path'] ?? null);

        $labelled = $this->declare(['name' => 'post-count', 'type' => 'metric-card', 'props' => ['title' => 'Published posts', 'label' => 'x'],
            'source' => ['entity' => CountedPost::class, 'count' => true]]);
        self::assertTrue($labelled['ok'], 'an undeclared prop is not refused: contracts are not complete');
        self::assertSame(['label'], $labelled['ignoredProps'] ?? null);
        self::assertStringContainsString('«metric-card» declares: title, value', (string) ($labelled['note'] ?? ''));

        $novalue = $this->declare(['name' => 'kpi', 'type' => 'metric-card', 'props' => ['title' => 'Something']]);
        self::assertSame('props.value', $novalue['path'] ?? null, 'without a count, the value is the caller\'s to give');
    }

    /** The children obey the rule the root obeys — in a declared tree and in a word's composition. */
    public function testAContainersChildrenAreJudgedByTheirOwnContract(): void
    {
        $grid = $this->declare(['name' => 'pulse', 'type' => 'dashboard-grid', 'props' => ['children' => [
            ['type' => 'metric-card', 'props' => ['title' => 'Published', 'value' => '2']],
            ['type' => 'metric-card', 'props' => ['value' => '1']],
        ]]]);
        self::assertSame('props.children.1.props.title', $grid['path'] ?? null, json_encode($grid) ?: '');

        $labelled = $this->declare(['name' => 'pulse', 'type' => 'dashboard-grid', 'props' => ['children' => [
            ['type' => 'metric-card', 'props' => ['title' => 'Published', 'value' => '2', 'label' => 'x']],
        ]]]);
        self::assertTrue($labelled['ok'], json_encode($labelled) ?: '');
        self::assertSame(['children.0.props.label'], $labelled['ignoredProps'] ?? null);

        foreach ((new LivePlugin($this->container))->operations() as $operation) {
            if ($operation instanceof Operation && $operation->name === 'component:define') {
                $word = ($operation->handler)(['name' => 'two-cards', 'summary' => 'two numbers', 'inputs' => ['a' => ['type' => 'string']],
                    'composition' => ['type' => 'dashboard-grid', 'props' => ['children' => [
                        ['type' => 'metric-card', 'props' => ['title' => 'A', 'value' => '$a']],
                        ['type' => 'metric-card', 'props' => ['value' => '1']],
                    ]]]]);
                self::assertSame('composition.props.children.1.props.title', $word['path'] ?? null, json_encode($word) ?: '');
            }
        }
    }

    /**
     * A WORD can carry a live count (the part of 0478's deferred word that the public boundary allows): its root
     * card leaves `value` to the binding, and the count arrives where the word is used.
     */
    public function testAWordWhoseCardLeavesItsValueToTheBindingCountsLive(): void
    {
        $posts = new InMemoryRepository(CountedPost::class);
        $posts->save(CountedPost::fromArray(['id' => 1, 'title' => 'a', 'body' => 'x', 'published' => true]));
        $posts->save(CountedPost::fromArray(['id' => 2, 'title' => 'b', 'body' => 'x', 'published' => false]));
        $this->container->registerService(CountedPost::class . 'Repository', $posts);

        foreach ((new LivePlugin($this->container))->operations() as $operation) {
            if ($operation instanceof Operation && $operation->name === 'component:define') {
                $word = ($operation->handler)(['name' => 'published-count', 'summary' => 'how many posts readers can see, live',
                    'inputs' => ['title' => ['type' => 'string']],
                    'composition' => ['type' => 'metric-card', 'props' => ['title' => '$title']]]);
                self::assertTrue($word['ok'], json_encode($word) ?: '');
            }
        }
        $declared = $this->declare(['name' => 'post-count', 'type' => 'published-count', 'props' => ['title' => 'Publicados'],
            'source' => ['entity' => CountedPost::class, 'count' => true]]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame('1', $this->value());
        $posts->save(CountedPost::fromArray(['id' => 3, 'title' => 'c', 'body' => 'x', 'published' => true]));
        self::assertSame('2', $this->value(), 'read live through the word');
    }

    private function value(): string
    {
        preg_match('~<span class="mui-stat__value">(.*?)</span>~s', $this->page(), $m);

        return trim(strip_tags($m[1] ?? ''));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function declare(array $input): array
    {
        foreach ((new LivePlugin($this->container))->operations() as $operation) {
            if ($operation instanceof Operation && $operation->name === 'screen:declare') {
                return ($operation->handler)($input);
            }
        }
        self::fail('screen:declare is not offered');
    }

    private function page(): string
    {
        $response = $this->container->get(LiveComponentPageController::class)
            ->show((new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'post-count']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }
}

final readonly class CountedPost implements EntityInterface
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

/** An entity that declares nothing public: nothing about it may be counted. */
final readonly class UncountedNote implements EntityInterface
{
    public function __construct(public int|string|null $id, public string $text)
    {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'text' => $this->text];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): static
    {
        return new self($row['id'] ?? null, $row['text']);
    }
}
