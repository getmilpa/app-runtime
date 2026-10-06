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

use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Data\InMemoryRepository;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

/**
 * A SCREEN BOUND TO AN ENTITY WITH NOTHING PUBLIC SAYS SO, AND SAYS HOW ROWS ARE LEFT (greenhouse decisions/0574 §8).
 *
 * Measured (greenhouse evidence/1107 §5): a resident declared the page in one call, got `ok` and a `served` receipt,
 * and the page read «Nothing to read yet». Nothing in that answer said the page was empty or where rows come from;
 * it spent 21 model calls looking.
 */
final class AnEmptyBoundScreenSaysHowToSeedItTest extends TestCase
{
    private string $dir = '';

    private DIContainer $container;

    private InMemoryRepository $posts;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-empty-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        $this->container = new DIContainer();
        $this->container->registerService(Config::class, new Config(['live' => ['secret' => str_repeat('k', 32), 'screens_path' => $this->dir . '/screens.json']]));
        (new LivePlugin($this->container))->boot();
        $this->posts = new InMemoryRepository(MountedPost::class);
        $this->container->registerService(MountedPost::class . 'Repository', $this->posts);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testAScreenOverNoPublicRowSaysItIsEmptyAndNamesTheSeed(): void
    {
        $this->posts->save(MountedPost::fromArray(['id' => 1, 'title' => 'Only a draft', 'body' => 'x', 'published' => false]));

        $declared = $this->declare();

        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame(['public' => 0], $declared['rows'], 'a draft is not a public row');
        self::assertStringContainsString('has no public row yet', $declared['guidance']);
        self::assertStringContainsString('entity:seed', $declared['guidance'], 'the house reads «guidance» as the real next step');
        self::assertStringContainsString('MountedPost', $declared['guidance']);
        self::assertSame('served', $declared['evidence']['predicate'] ?? null, 'the page IS served: an empty page is still a page');
    }

    public function testAScreenOverPublicRowsSaysHowManyAndGuidesNowhere(): void
    {
        $this->posts->save(MountedPost::fromArray(['id' => 1, 'title' => 'Hello', 'body' => 'x', 'published' => true]));
        $this->posts->save(MountedPost::fromArray(['id' => 2, 'title' => 'Draft', 'body' => 'x', 'published' => false]));

        $declared = $this->declare();

        self::assertSame(['public' => 1], $declared['rows']);
        self::assertArrayNotHasKey('guidance', $declared);
    }

    public function testAScreenThatBindsNoEntitySaysNothingAboutRows(): void
    {
        $literal = (self::operation()->handler)(['name' => 'plain', 'rows' => [['t' => 'a']], 'columns' => [['key' => 't', 'label' => 'T']]]);
        self::assertTrue($literal['ok']);
        self::assertArrayNotHasKey('rows', $literal, 'literal rows are the caller\'s: there is nothing to count');

        $counted = (self::operation()->handler)(['name' => 'howmany', 'type' => 'metric-card', 'props' => ['title' => 'Posts'], 'source' => ['entity' => MountedPost::class, 'count' => true]]);
        self::assertTrue($counted['ok'], json_encode($counted) ?: '');
        self::assertArrayNotHasKey('guidance', $counted, 'a count of zero is an answer, not an empty page');
    }

    public function testWhatTheHouseCannotCountIsNotCalledEmpty(): void
    {
        $count = new \ReflectionMethod(LivePlugin::class, 'publicRowsOf');
        $door = new LivePlugin(new DIContainer());

        self::assertNull($count->invoke($door, ['entity' => MountedPost::class, 'columns' => ['title']]), 'no repository to ask: unknown, never zero');

        $operations = new \Milpa\AppRuntime\Web\ScreenOperations(
            \Milpa\AppRuntime\Web\ScreenStore::fromConfig(['screens_path' => $this->dir . '/other.json'], $this->dir),
            serve: static fn (): int => 200,
            publicRows: static fn (array $source): ?int => null,
        );
        $declared = ($operations->operations()[0]->handler)(['name' => 'blog', 'source' => ['entity' => MountedPost::class, 'columns' => ['title']]]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertArrayNotHasKey('rows', $declared);
        self::assertArrayNotHasKey('guidance', $declared);
    }

    /** @return array<string, mixed> */
    private function declare(): array
    {
        return (self::operation()->handler)([
            'name' => 'blog', 'type' => 'content', 'props' => ['roles' => ['title' => 'title', 'body' => 'body']],
            'source' => ['entity' => MountedPost::class, 'columns' => ['title', 'body']],
        ]);
    }

    private function operation(): Operation
    {
        $door = new LivePlugin($this->container);
        $door->boot();
        foreach ($door->operations() as $operation) {
            if ($operation instanceof Operation && $operation->name === 'screen:declare') {
                return $operation;
            }
        }
        self::fail('screen:declare is not offered');
    }
}
