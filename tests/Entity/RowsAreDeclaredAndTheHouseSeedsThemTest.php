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

namespace App\Plugins\SeedBlog\Entities {
    use Milpa\Data\EntityInterface;

    /** A generated-shape entity of a plugin of the house. */
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

namespace Milpa\AppRuntime\Tests\Entity {
    use App\Plugins\SeedBlog\Entities\Post;
    use Milpa\AppRuntime\Entity\SeedDeclarations;
    use Milpa\AppRuntime\Entity\SeedOperations;
    use Milpa\Command\Effect\Mutation;
    use Milpa\Command\Effect\Subject;
    use Milpa\Container\DIContainer;
    use Milpa\Data\EntityInterface;
    use Milpa\Data\InMemoryRepository;
    use Milpa\Data\RepositoryInterface;
    use PHPUnit\Framework\TestCase;

    /**
     * ROWS ARE DECLARED, AND THE HOUSE SEEDS THEM (greenhouse decisions/0574, slice BV-4).
     *
     * Measured with real residents (greenhouse evidence/1104, 1107): the house had no governed way to leave two
     * rows. One edited the plugin's `boot()`, one invented a sequence, one scaffolded a tool the house refused to
     * run — 21 model calls without a row. A row a work is born with is part of what was built: it is declared in
     * the plugin's own tree, crosses by promotion like any work, and the house writes the store.
     */
    final class RowsAreDeclaredAndTheHouseSeedsThemTest extends TestCase
    {
        private const HELLO = ['title' => 'Hello, blog', 'body' => 'the public body', 'published' => true];
        private const DRAFT = ['title' => 'A draft', 'body' => 'not for visitors', 'published' => false];
        private const FILE = 'src/Plugins/SeedBlog/Seeds/Post.json';

        private string $root = '';

        protected function setUp(): void
        {
            $this->root = sys_get_temp_dir() . '/milpa-seeds-' . bin2hex(random_bytes(6));
            mkdir($this->root . '/src/Plugins/SeedBlog', 0o777, true);
        }

        protected function tearDown(): void
        {
            exec('rm -rf ' . escapeshellarg($this->root));
        }

        public function testDeclaringRowsWritesThemInThePluginsOwnTree(): void
        {
            $declared = $this->seeds()->declare('SeedBlog/Post', [self::HELLO, self::DRAFT]);

            self::assertSame(['ok' => true, 'entity' => 'SeedBlog/Post', 'file' => self::FILE, 'declared' => 2, 'added' => 2], $declared);
            self::assertSame(
                ['entity' => 'SeedBlog/Post', 'rows' => [self::HELLO, self::DRAFT]],
                json_decode((string) file_get_contents($this->root . '/' . self::FILE), true),
                'the declaration is a file a trial diffs and a promotion crosses: under the plugin its write scope already covers',
            );
            self::assertDirectoryDoesNotExist($this->root . '/var', 'declaring writes no store');
        }

        public function testDeclaringAgainAddsWhatWasNotThereAndNeverDuplicates(): void
        {
            $this->seeds()->declare('SeedBlog/Post', [self::HELLO]);

            $again = $this->seeds()->declare(Post::class, [['published' => true, 'body' => 'the public body', 'title' => 'Hello, blog'], self::DRAFT]);

            self::assertSame(['declared' => 2, 'added' => 1], ['declared' => $again['declared'], 'added' => $again['added']], 'the same row in another key order is the same row');
            self::assertCount(2, json_decode((string) file_get_contents($this->root . '/' . self::FILE), true)['rows']);

            $twice = $this->seeds()->declare('SeedBlog/Post', [['title' => 'Twice', 'body' => 'b', 'published' => true], ['title' => 'Twice', 'body' => 'b', 'published' => true]]);
            self::assertSame(['declared' => 3, 'added' => 1], ['declared' => $twice['declared'], 'added' => $twice['added']], 'the same row twice in one call is one row');
        }

        public function testWhatIsNotARowOfThatEntityIsRefusedByNameAndDeclaresNothing(): void
        {
            foreach ([
                'no entity «Nope»' => ['Nope', [self::HELLO]],
                'is not an entity of a plugin of this house' => [\Milpa\Data\InMemoryRepository::class, [self::HELLO]],
                '«rows» is a list of rows' => ['SeedBlog/Post', 'two posts'],
                'at least one row' => ['SeedBlog/Post', []],
                'rows.1 is not an object' => ['SeedBlog/Post', [self::HELLO, 'a title']],
                'rows.0 names «summary», which Post does not have; its fields are: title, body, published' => ['SeedBlog/Post', [self::HELLO + ['summary' => 'x']]],
                'rows.0 carries «id»: the store assigns it' => ['SeedBlog/Post', [['id' => 7] + self::HELLO]],
                'rows.1 cannot be a Post' => ['SeedBlog/Post', [self::HELLO, ['title' => 'no body', 'published' => true]]],
                'rows.0 cannot be a Post: ' => ['SeedBlog/Post', [['title' => 'x', 'body' => 'y', 'published' => 'yes']]],
            ] as $why => [$entity, $rows]) {
                $refused = $this->seeds()->declare($entity, $rows);
                self::assertFalse($refused['ok'], $why);
                self::assertStringContainsString($why, (string) $refused['error'], $why);
                self::assertFileDoesNotExist($this->root . '/' . self::FILE, "«{$why}» declared something");
            }
        }

        public function testTheHouseSeedsEachDeclaredRowOnce(): void
        {
            $this->seeds()->declare('SeedBlog/Post', [self::HELLO, self::DRAFT]);
            $posts = new InMemoryRepository(Post::class);

            $first = $this->seeds()->apply([self::FILE], $this->service($posts));
            self::assertSame([['entity' => 'SeedBlog/Post', 'declared' => 2, 'added' => 2, 'already' => 0]], $first);
            self::assertSame(['Hello, blog', 'A draft'], array_map(static fn (EntityInterface $post): string => $post->toArray()['title'], array_values($posts->all())));
            self::assertNotNull(array_values($posts->all())[0]->id(), 'the store assigned the id');

            $second = $this->seeds()->apply([self::FILE], $this->service($posts));
            self::assertSame([['entity' => 'SeedBlog/Post', 'declared' => 2, 'added' => 0, 'already' => 2]], $second, 'promoting twice does not duplicate');
            self::assertCount(2, $posts->all());

            // A row somebody deleted later does not come back, and a newly declared one is added alone.
            $posts->delete(array_values($posts->all())[0]->id() ?? 0);
            $this->seeds()->declare('SeedBlog/Post', [['title' => 'Second', 'body' => 'b', 'published' => true]]);
            $third = $this->seeds()->apply([self::FILE], $this->service($posts));
            self::assertSame(['declared' => 3, 'added' => 1, 'already' => 2], array_diff_key($third[0], ['entity' => 0]));
            self::assertSame(['A draft', 'Second'], array_map(static fn (EntityInterface $post): string => $post->toArray()['title'], array_values($posts->all())));
        }

        public function testOnlySeedDeclarationsThatLandedAreApplied(): void
        {
            $this->seeds()->declare('SeedBlog/Post', [self::HELLO]);
            $posts = new InMemoryRepository(Post::class);

            self::assertSame([self::FILE], SeedDeclarations::landed(['src/Plugins/SeedBlog/Blog.php', self::FILE, 'config/screens.json', 'src/Plugins/SeedBlog/Seeds/readme.md', 'src/Seeds/Post.json']));
            self::assertSame([], $this->seeds()->apply(['src/Plugins/SeedBlog/Blog.php', 'config/screens.json'], $this->service($posts)));
            self::assertSame([], $posts->all(), 'a promotion that lands no declaration seeds nothing');
        }

        public function testARowThatCouldNotBeSavedIsSaidAndNotCountedAsSeeded(): void
        {
            $this->seeds()->declare('SeedBlog/Post', [self::HELLO, self::DRAFT]);
            $refusing = new class () extends \ArrayObject implements RepositoryInterface {
                public function find(int|string $id): ?EntityInterface
                {
                    return null;
                }

                public function save(EntityInterface $entity): int|string
                {
                    if ($entity->toArray()['published'] === false) {
                        throw new \RuntimeException('the store is full');
                    }
                    $this[] = $entity;

                    return \count($this);
                }

                public function delete(int|string $id): void
                {
                }

                public function all(): array
                {
                    return $this->getArrayCopy();
                }

                public function nextId(): int|string
                {
                    return 1;
                }

                public function query(array $criteria): array
                {
                    return [];
                }
            };

            $said = $this->seeds()->apply([self::FILE], $this->service($refusing));

            self::assertSame(1, $said[0]['added']);
            self::assertSame([['row' => 1, 'reason' => 'RuntimeException: the store is full']], $said[0]['failed']);
            self::assertSame(['added' => 1, 'already' => 1], array_intersect_key($this->seeds()->apply([self::FILE], $this->service(new InMemoryRepository(Post::class)))[0], ['added' => 0, 'already' => 0]), 'the failed row is tried again; the saved one is not');
        }

        public function testAnEntityWithNoRepositoryIsNotSeededAndSaysSo(): void
        {
            $this->seeds()->declare('SeedBlog/Post', [self::HELLO]);

            $said = $this->seeds()->apply([self::FILE], static fn (string $id): ?object => null);

            self::assertSame(0, $said[0]['added']);
            self::assertStringContainsString('no repository is registered for SeedBlog/Post', $said[0]['unseeded']);
            self::assertDirectoryDoesNotExist($this->root . '/var/seeds', 'nothing is remembered as seeded');
        }

        public function testADeclarationTheHouseCannotReadIsSaidNotGuessed(): void
        {
            mkdir($this->root . '/src/Plugins/SeedBlog/Seeds', 0o777, true);
            file_put_contents($this->root . '/' . self::FILE, '{not json');

            $said = $this->seeds()->apply([self::FILE], $this->service(new InMemoryRepository(Post::class)));

            self::assertSame('SeedBlog/Post', $said[0]['entity']);
            self::assertStringContainsString('is not a seed declaration', $said[0]['unseeded']);
        }

        public function testTheOperationDeclaresAndSeedsWhereItRuns(): void
        {
            $container = new DIContainer();
            $posts = new InMemoryRepository(Post::class);
            $container->registerService(Post::class . 'Repository', $posts);
            $operation = (new SeedOperations($container, $this->root))->operations()[0];

            self::assertSame('entity:seed', $operation->name);
            self::assertTrue($operation->mutating);
            self::assertSame(['plugins:write'], $operation->scopes, 'what a seat already holds; the plugin\'s own scope is asked where the file crosses');
            self::assertFalse($operation->requiresConfirmation, 'so it is rehearsed in a trial like any work');
            self::assertSame(Mutation::Persistent, $operation->effectCeiling()->mutation);
            self::assertSame(Subject::Data, $operation->effectCeiling()->subject);
            self::assertTrue($operation->effectCeiling()->isFullyClassified(), 'every axis is said: an unsaid one reads as the worst');
            self::assertSame(['entity', 'rows'], $operation->inputSchema['required']);
            self::assertStringContainsString('boot()', $operation->description, 'it says what it replaces');

            $said = ($operation->handler)(['entity' => 'SeedBlog/Post', 'rows' => [self::HELLO, self::DRAFT]]);

            self::assertTrue($said['ok'], json_encode($said) ?: '');
            self::assertSame(self::FILE, $said['file']);
            self::assertSame(['entity' => 'SeedBlog/Post', 'declared' => 2, 'added' => 2, 'already' => 0], $said['seeded'], 'in a trial this is the copy\'s store: the rehearsal proves the rows save');
            self::assertCount(2, $posts->all());

            $refused = ($operation->handler)(['entity' => 'SeedBlog/Post', 'rows' => [['title' => 'x']]]);
            self::assertFalse($refused['ok']);
            self::assertArrayNotHasKey('seeded', $refused);
            self::assertCount(2, $posts->all(), 'a refused declaration seeds nothing');

            $json = ($operation->handler)(['entity' => 'SeedBlog/Post', 'rows' => '[{"title":"From a terminal","body":"b","published":true}]']);
            self::assertTrue($json['ok'], 'a terminal passes rows as JSON: ' . (json_encode($json) ?: ''));
            self::assertCount(3, $posts->all());
        }

        private function seeds(): SeedDeclarations
        {
            return new SeedDeclarations($this->root);
        }

        /** @return \Closure(string): ?object */
        private function service(RepositoryInterface $posts): \Closure
        {
            return static fn (string $id): ?object => $id === Post::class . 'Repository' ? $posts : null;
        }
    }
}
