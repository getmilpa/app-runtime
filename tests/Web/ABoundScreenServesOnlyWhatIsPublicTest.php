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
use Milpa\AppRuntime\Web\LivePageProvider;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Data\EntityInterface;
use Milpa\Data\InMemoryRepository;
use Milpa\Runtime\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * A declared screen BOUND to an entity serves only what that entity already declared public
 * (greenhouse decisions/0462) — driven through the real door: LivePlugin booted, `screen:declare`
 * called as the agent calls it, the page fetched at `/live/page`.
 *
 * The binding exists so the agent composes a page instead of writing it. What it must never become is
 * a way to read what the controller would not show a stranger, so every test here is a pair: what is
 * served, and what is NOT — a draft body in the HTML would pass any test that only looked for the title.
 *
 * @guards the public-only, column-only projection of a bound data-table, read per request
 *
 * @refuses a binding to an entity with no declared visibility, a column that is not a field, rows and
 *          source together, a non-table type, and a source whose repository is not registered
 *
 * @subject-in milpa/app-runtime
 */
final class ABoundScreenServesOnlyWhatIsPublicTest extends TestCase
{
    private string $dir = '';

    private DIContainer $container;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-bound-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        $this->container = new DIContainer();
        $this->container->registerService(Config::class, new Config(['live' => [
            'secret' => str_repeat('k', 32),
            'screens_path' => $this->dir . '/screens.json',
        ]]));
        (new LivePlugin($this->container))->boot();
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/screens.json');
        @unlink($this->dir . '/screens.json.lock');
        foreach (glob($this->dir . '/*') ?: [] as $left) {
            @unlink($left);
        }
        @rmdir($this->dir);
    }

    public function testAStrangerSeesThePublishedTitlesAndNothingElse(): void
    {
        // Registered AFTER the live door booted: the plugin that owns an entity may boot later.
        $posts = $this->posts();

        $declared = $this->declare(['name' => 'blog', 'source' => ['entity' => BoundPost::class, 'columns' => ['title']]]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');

        // THE DATA, not the painting. The table paints only its columns, so a full row — or the
        // binding itself — would ride the signed state envelope to the browser, encoded but not secret,
        // while every plain-text assertion on the HTML stayed green. Measured: both mutations survived
        // an HTML-only version of this test.
        $props = $this->container->get(LivePageProvider::class)->propsFor('blog', new ServerRequest('GET', '/live/page'));
        self::assertSame([['title' => 'Hello, world']], $props['rows'] ?? null, 'only the published row, only the named column');
        self::assertArrayNotHasKey('source', $props, 'the binding never reaches the component');

        $html = $this->page('blog');
        self::assertStringContainsString('Hello, world', $html);
        self::assertStringNotContainsString('Secret draft', $html, 'the unpublished row is not served');
        self::assertStringNotContainsString('the public body', $html, 'an undeclared column is not served');
        self::assertStringNotContainsString(BoundPost::class, $html, 'the binding itself never reaches the page');

        // Read per request, not frozen at declare time: publishing a row shows it on the next load.
        $posts->save(BoundPost::fromArray(['id' => 3, 'title' => 'Fresh post', 'body' => 'x', 'published' => true]));
        self::assertStringContainsString('Fresh post', $this->page('blog'));
    }

    public function testTheColumnsTheTableShowsAreTheColumnsTheBindingReads(): void
    {
        $this->posts();
        $this->declare(['name' => 'blog', 'source' => ['entity' => BoundPost::class, 'columns' => ['title', 'body']]]);

        $html = $this->page('blog');
        self::assertStringContainsString('the public body', $html, 'a declared column is served');
        self::assertStringNotContainsString('the draft body', $html);
    }

    public function testAnEntityThatDeclaresNothingPublicCannotBeBound(): void
    {
        $refused = $this->declare(['name' => 'notes', 'source' => ['entity' => PrivateNote::class, 'columns' => ['text']]]);

        self::assertFalse($refused['ok']);
        self::assertSame('source.entity', $refused['path']);
        self::assertStringContainsString('declares no PUBLIC_WHEN', $refused['reason']);
        self::assertStringContainsString('--public-when', $refused['reason'], 'the refusal names the fix');
    }

    public function testEachWrongShapeIsRefusedByName(): void
    {
        $cases = [
            'source.columns.1' => ['name' => 'a', 'source' => ['entity' => BoundPost::class, 'columns' => ['title', 'password']]],
            'rows' => ['name' => 'b', 'rows' => [['title' => 'typed by hand']], 'source' => ['entity' => BoundPost::class, 'columns' => ['title']]],
            'source' => ['name' => 'c', 'type' => 'metric-card', 'source' => ['entity' => BoundPost::class, 'columns' => ['title']]],
            'source.limit' => ['name' => 'd', 'source' => ['entity' => BoundPost::class, 'columns' => ['title'], 'limit' => 10_000]],
        ];
        foreach ($cases as $path => $input) {
            $refused = $this->declare($input);
            self::assertFalse($refused['ok'], $path);
            self::assertSame($path, $refused['path'] ?? null, json_encode($refused) ?: '');
        }
        self::assertStringContainsString('its fields are: id, title, body, published', $this->declare($cases['source.columns.1'])['reason']);
    }

    public function testASourceWhoseRepositoryIsMissingIsNamedNotPainted(): void
    {
        // No repository registered — the page answers 422 with the reason, never an empty table that
        // would read as «no posts yet».
        $declared = $this->declare(['name' => 'blog', 'source' => ['entity' => BoundPost::class, 'columns' => ['title']]]);
        self::assertTrue($declared['ok']);

        $response = $this->container->get(LiveComponentPageController::class)
            ->show((new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'blog']));
        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('source.entity', $body['path']);
        self::assertStringContainsString(BoundPost::class . 'Repository', $body['reason']);
    }

    private function posts(): InMemoryRepository
    {
        $posts = new InMemoryRepository(BoundPost::class);
        $posts->save(BoundPost::fromArray(['id' => 1, 'title' => 'Hello, world', 'body' => 'the public body', 'published' => true]));
        $posts->save(BoundPost::fromArray(['id' => 2, 'title' => 'Secret draft', 'body' => 'the draft body', 'published' => false]));
        $this->container->registerService(BoundPost::class . 'Repository', $posts);

        return $posts;
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

    private function page(string $name): string
    {
        $response = $this->container->get(LiveComponentPageController::class)
            ->show((new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => $name]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }
}

/** A generated-shape entity that declares what of it is public. */
final readonly class BoundPost implements EntityInterface
{
    public const PUBLIC_WHEN = 'published';

    public function __construct(
        public int|string|null $id,
        public string $title,
        public string $body,
        public bool $published,
    ) {
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

/** An entity that declared nothing public — it must not be bindable at all. */
final readonly class PrivateNote implements EntityInterface
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
