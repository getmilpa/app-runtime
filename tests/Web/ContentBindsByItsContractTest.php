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
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Narrative content is composed, not written (greenhouse decisions/0464), through the real door:
 * LivePlugin booted, `screen:declare` called as the agent calls it, the page fetched.
 *
 * Who may bind is the CONTRACT's: a type whose contract declares `rows`. The proof that the runtime
 * learned nothing about blogs is a second narrative entity — different fields, a different visibility
 * field — served by the same code.
 *
 * @guards content bound to a public entity, a second entity with no runtime change, autocomplete's own
 *         string `source`, and roles that do not fit refused with a 422 and a reason
 *
 * @refuses binding a type whose contract declares no rows
 *
 * @subject-in milpa/app-runtime
 */
final class ContentBindsByItsContractTest extends TestCase
{
    private string $dir = '';

    private DIContainer $container;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-content-' . bin2hex(random_bytes(6));
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
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $left) {
            is_file($left) && @unlink($left);
        }
        @rmdir($this->dir);
    }

    public function testAnEntityIsReadAsArticlesAndOnlyWhatIsPublic(): void
    {
        $articles = new InMemoryRepository(ContentArticle::class);
        $articles->save(ContentArticle::fromArray(['id' => 1, 'title' => 'First light', 'body' => "One.\n\nTwo.", 'published' => true]));
        $articles->save(ContentArticle::fromArray(['id' => 2, 'title' => 'Hidden draft', 'body' => 'no', 'published' => false]));
        $articles->save(ContentArticle::fromArray(['id' => 3, 'title' => "<script>alert('x')</script>", 'body' => 'x', 'published' => true]));
        $this->container->registerService(ContentArticle::class . 'Repository', $articles);

        $declared = $this->declare([
            'name' => 'reading',
            'type' => 'content',
            'source' => ['entity' => ContentArticle::class, 'columns' => ['title', 'body']],
            'props' => ['roles' => ['title' => 'title', 'body' => 'body']],
        ]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame('served', $declared['evidence']['predicate'] ?? null, 'the page answered 200 through the real controller');

        $html = $this->html('reading');
        self::assertStringContainsString('<article class="entry"><h2 class="title">First light</h2>', $html);
        self::assertStringContainsString('<p>One.</p><p>Two.</p>', $html);
        self::assertStringNotContainsString('Hidden draft', $html);
        self::assertStringNotContainsString("<script>alert('x')</script>", $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testASecondNarrativeEntityNeedsNoRuntimeChange(): void
    {
        // THE FALSIFIER OF 0464: other fields, another visibility field, the same runtime.
        $notices = new InMemoryRepository(ContentNotice::class);
        $notices->save(ContentNotice::fromArray(['id' => 1, 'headline' => 'Maintenance tonight', 'message' => 'Pauses at 22:00.', 'audience' => 'everyone', 'live' => true]));
        $notices->save(ContentNotice::fromArray(['id' => 2, 'headline' => 'Not yet announced', 'message' => 'secret', 'audience' => 'staff', 'live' => false]));
        $this->container->registerService(ContentNotice::class . 'Repository', $notices);

        $declared = $this->declare([
            'name' => 'notices',
            'type' => 'content',
            'source' => ['entity' => ContentNotice::class, 'columns' => ['headline', 'message', 'audience']],
            'props' => ['heading' => 'Notices', 'roles' => ['title' => 'headline', 'body' => 'message', 'meta' => ['audience']]],
        ]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');

        $html = $this->html('notices');
        self::assertStringContainsString('<h3 class="title">Maintenance tonight</h3>', $html);
        self::assertStringContainsString('<dt>Audience</dt><dd>everyone</dd>', $html);
        self::assertStringNotContainsString('Not yet announced', $html, 'its own PUBLIC_WHEN, not a blog\'s');
    }

    public function testATypeWhoseContractHasNoRowsCannotBind(): void
    {
        $refused = $this->declare(['name' => 'kpi', 'type' => 'metric-card', 'source' => ['entity' => ContentArticle::class, 'columns' => ['title']], 'props' => ['title' => 'x', 'value' => '1']]);

        self::assertFalse($refused['ok']);
        self::assertSame('source', $refused['path']);
        self::assertStringContainsString('declares no rows prop', $refused['reason']);
    }

    public function testAnAutocompleteKeepsItsOwnStringSource(): void
    {
        // REGRESSION (0.180.0–0.181.0): a string `source` is autocomplete's data-source name, not a binding.
        $declared = $this->declare(['name' => 'city', 'type' => 'autocomplete', 'props' => [
            'name' => 'city', 'source' => 'cities', 'options' => [['value' => 'mx', 'label' => 'Mexico']],
        ]]);

        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame(200, $this->page('city')->getStatusCode());
    }

    public function testRolesThatDoNotFitTheBoundColumnsAreNamedNotPainted(): void
    {
        $articles = new InMemoryRepository(ContentArticle::class);
        $articles->save(ContentArticle::fromArray(['id' => 1, 'title' => 'x', 'body' => 'y', 'published' => true]));
        $this->container->registerService(ContentArticle::class . 'Repository', $articles);

        // The body role names a field the binding does not read.
        $declared = $this->declare([
            'name' => 'reading',
            'type' => 'content',
            'source' => ['entity' => ContentArticle::class, 'columns' => ['title']],
            'props' => ['roles' => ['title' => 'title', 'body' => 'body']],
        ]);
        self::assertFalse($declared['served'] ?? true, 'no served receipt over a page that cannot paint');
        self::assertSame(422, $declared['status'] ?? null);

        $response = $this->page('reading');
        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('props.rows.0', $body['path']);
        self::assertStringContainsString('«body»', $body['reason']);
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

    private function page(string $name): ResponseInterface
    {
        return $this->container->get(LiveComponentPageController::class)
            ->show((new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => $name]));
    }

    private function html(string $name): string
    {
        $response = $this->page($name);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }
}

/** A readable entity whose visibility field is `published`. */
final readonly class ContentArticle implements EntityInterface
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

/** A second narrative entity: other fields, and `live` decides what is public. */
final readonly class ContentNotice implements EntityInterface
{
    public const PUBLIC_WHEN = 'live';

    public function __construct(public int|string|null $id, public string $headline, public string $message, public string $audience, public bool $live)
    {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'headline' => $this->headline, 'message' => $this->message, 'audience' => $this->audience, 'live' => $this->live];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): static
    {
        return new self($row['id'] ?? null, $row['headline'], $row['message'], $row['audience'], $row['live']);
    }
}
