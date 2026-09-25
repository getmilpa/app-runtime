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

use Composer\Autoload\ClassLoader;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Web\Controllers\LiveComponentPageController;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Data\InMemoryRepository;
use Milpa\Runtime\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * An entity is named without backslashes (greenhouse decisions/0471), through the real door: an app's
 * `App\` prefix registered with a real Composer autoloader, its entities as files under
 * `src/Plugins/<Plugin>/Entities/`, `screen:declare` called as an agent calls it, the page fetched.
 *
 * Measured on the resident: re-writing `App\Plugins\…` inside a JSON string after a consent killed 7 of
 * 94 sessions (`App\nginx\nginx…` until the output budget ran out). A short name needs no backslash.
 *
 * @guards the short name resolving to the one entity that carries it, `Plugin/Entity`, the `/`-written
 *         class, the class itself, the stored binding staying the class — and entity:contract reading the
 *         same names (decisions/0472)
 *
 * @refuses a short name two entities carry (naming both, written with `/`), and a name nothing carries
 *
 * @subject-in milpa/app-runtime
 */
final class AnEntityIsNamedWithoutBackslashesTest extends TestCase
{
    private string $app = '';

    private ?ClassLoader $loader = null;

    private DIContainer $container;

    protected function setUp(): void
    {
        $this->app = sys_get_temp_dir() . '/milpa-names-' . bin2hex(random_bytes(6));
        $space = 'N' . bin2hex(random_bytes(4));
        // Two plugins own an entity called Twin; only Shelf owns Leaf. The plugin names are unique per
        // run so each test's classes are its own (PHP cannot unload a class).
        foreach ([['Shelf' . $space, 'Leaf' . $space], ['Shelf' . $space, 'Twin' . $space], ['Desk' . $space, 'Twin' . $space]] as [$plugin, $entity]) {
            @mkdir("{$this->app}/src/Plugins/{$plugin}/Entities", 0o777, true);
            file_put_contents("{$this->app}/src/Plugins/{$plugin}/Entities/{$entity}.php", self::entity($plugin, $entity));
        }
        $this->loader = new ClassLoader($this->app . '/vendor');
        $this->loader->addPsr4('App\\', $this->app . '/src/');
        $this->loader->register();

        $this->container = new DIContainer();
        $this->container->registerService(Config::class, new Config(['live' => [
            'secret' => str_repeat('k', 32),
            'screens_path' => $this->app . '/screens.json',
        ]]));
        (new LivePlugin($this->container))->boot();
        $this->space = $space;
    }

    private string $space = '';

    protected function tearDown(): void
    {
        $this->loader?->unregister();
        self::rmrf($this->app);
    }

    public function testAShortNameBindsTheOneEntityThatCarriesItAndTheClassIsWhatIsStored(): void
    {
        $leaf = "App\\Plugins\\Shelf{$this->space}\\Entities\\Leaf{$this->space}";
        $this->rows($leaf);

        foreach (["Leaf{$this->space}", "Shelf{$this->space}/Leaf{$this->space}", str_replace('\\', '/', $leaf), $leaf] as $i => $written) {
            $declared = $this->declare(['name' => "leaves-{$i}", 'type' => 'content', 'props' => ['roles' => ['title' => 'title', 'body' => 'body']],
                'source' => ['entity' => $written, 'columns' => ['title', 'body']]]);
            self::assertTrue($declared['ok'], $written . ' ' . (json_encode($declared) ?: ''));
            $html = $this->page("leaves-{$i}");
            self::assertStringContainsString('Out now', $html);
            self::assertStringNotContainsString('Secret draft', $html, 'the short name binds through the entity\'s own visibility');
        }

        $stored = json_decode((string) file_get_contents($this->app . '/screens.json'), true);
        self::assertSame($leaf, $stored['leaves-0']['props']['source']['entity'] ?? $stored['leaves-0']['source']['entity'] ?? null, 'the class is what is stored');
    }

    public function testAShortNameTwoEntitiesCarryIsRefusedNamingBothWithoutBackslashes(): void
    {
        $refused = $this->declare(['name' => 'twins', 'type' => 'content', 'props' => ['roles' => ['title' => 'title', 'body' => 'body']],
            'source' => ['entity' => "Twin{$this->space}", 'columns' => ['title']]]);

        self::assertFalse($refused['ok']);
        self::assertSame('source.entity', $refused['path'] ?? null);
        $reason = (string) ($refused['reason'] ?? '');
        self::assertStringContainsString("Desk{$this->space}/Twin{$this->space}, Shelf{$this->space}/Twin{$this->space}", $reason, 'named in the shortest form that tells them apart');
        self::assertStringNotContainsString('\\', $reason, 'the refusal never asks for a backslash');
        self::assertFileDoesNotExist($this->app . '/screens.json', 'nothing refused was stored');

        $nothing = $this->declare(['name' => 'nope', 'type' => 'content', 'props' => ['roles' => ['title' => 'title', 'body' => 'body']],
            'source' => ['entity' => 'Nobody', 'columns' => ['title']]]);
        self::assertStringContainsString('is not an entity this app can load', (string) ($nothing['reason'] ?? ''));
    }

    public function testEntityContractReadsTheSameNameEveryOtherDoorReads(): void
    {
        // Measured (greenhouse decisions/0472): once screen:declare taught `Post`, the model wrote it to
        // entity:contract too, which asked for the class and refused it in 16 of 35 sessions.
        $leaf = "App\\Plugins\\Shelf{$this->space}\\Entities\\Leaf{$this->space}";
        $contract = null;
        foreach ((new AgentOperations(new DIContainer()))->operations() as $operation) {
            if ($operation->name === 'entity:contract') {
                $contract = $operation;
            }
        }
        self::assertNotNull($contract);

        foreach (["Leaf{$this->space}", "Shelf{$this->space}/Leaf{$this->space}", str_replace('\\', '/', $leaf), $leaf] as $written) {
            $read = ($contract->handler)(['class' => $written]);
            self::assertTrue($read['ok'] ?? false, $written . ' ' . (json_encode($read) ?: ''));
            self::assertSame($leaf, $read['class'], 'the contract names the class it proved');
        }

        $ambiguous = ($contract->handler)(['class' => "Twin{$this->space}"]);
        self::assertFalse($ambiguous['ok']);
        self::assertStringContainsString("Desk{$this->space}/Twin{$this->space}, Shelf{$this->space}/Twin{$this->space}", (string) $ambiguous['error']);
        self::assertStringNotContainsString('\\', (string) $ambiguous['error']);
    }

    private function rows(string $class): void
    {
        $repository = new InMemoryRepository($class);
        $repository->save($class::fromArray(['id' => 1, 'title' => 'Out now', 'body' => 'read me', 'published' => true]));
        $repository->save($class::fromArray(['id' => 2, 'title' => 'Secret draft', 'body' => 'no', 'published' => false]));
        $this->container->registerService($class . 'Repository', $repository);
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

    private static function entity(string $plugin, string $entity): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Plugins\\{$plugin}\\Entities;

            final readonly class {$entity} implements \\Milpa\\Data\\EntityInterface
            {
                public const PUBLIC_WHEN = 'published';

                public function __construct(public int|string|null \$id, public string \$title, public string \$body, public bool \$published)
                {
                }

                public function id(): int|string|null
                {
                    return \$this->id;
                }

                public function toArray(): array
                {
                    return ['id' => \$this->id, 'title' => \$this->title, 'body' => \$this->body, 'published' => \$this->published];
                }

                public static function fromArray(array \$row): static
                {
                    return new self(\$row['id'] ?? null, \$row['title'], \$row['body'], \$row['published']);
                }
            }
            PHP;
    }

    private static function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (glob($path . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $child) {
            self::rmrf($child);
        }
        @rmdir($path);
    }
}
