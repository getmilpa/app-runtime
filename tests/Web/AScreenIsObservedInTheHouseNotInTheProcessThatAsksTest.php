<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\AppRuntime\Web\ScreenOperations;
use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * `screen:observe` asks the HOUSE, in a process of its own — not the process that happens to be asking
 * (greenhouse evidence/1109, debt 7 of decisions/0575).
 *
 * Measured on the BV-4 run with the real resident and reproduced on fresh cattle: within one leg the resident
 * scaffolded the plugin, registered it, declared the screen `blog` and seeded it, each through a promotion. Then
 * `screen:observe blog` answered 422 while a browser was served 200 at `/blog` and at `/live/page?component=blog`.
 * The leg's process had booted before any of that existed, and `screen:observe` asked that process. The same call
 * in a new process answered 200. `route:observe` had this debt and paid it (decisions/0494); this is the same payment.
 *
 * @guards screen:observe records «served» from what a fresh process of the house answers, even when the asking
 *         process cannot paint the page; it earns nothing when the house does not serve it, whatever the asking
 *         process believes; a house that cannot be asked that way is asked in-process, as before
 *
 * @refuses a served receipt the house did not earn; a 422 for a screen the house serves
 *
 * @subject-in milpa/app-runtime
 */
final class AScreenIsObservedInTheHouseNotInTheProcessThatAsksTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testTheHouseServesWhatTheAskingProcessCannotPaint(): void
    {
        $seen = self::observe($this->operations(process: 422, house: 200), 'blog');

        self::assertTrue($seen['ok'], json_encode($seen) ?: '');
        self::assertSame(200, $seen['status']);
        self::assertSame(['predicate' => 'served', 'subject' => 'blog', 'servedAt' => '/live/page?component=blog', 'environment' => ['kind' => 'house']], $seen['evidence']);
    }

    public function testWhatTheAskingProcessBelievesEarnsNothingWhenTheHouseDoesNotServe(): void
    {
        $seen = self::observe($this->operations(process: 200, house: 422), 'blog');

        self::assertFalse($seen['ok']);
        self::assertSame(422, $seen['status']);
        self::assertArrayNotHasKey('evidence', $seen);
    }

    public function testAHouseThatCannotBeAskedInAProcessOfItsOwnIsAskedHere(): void
    {
        self::assertTrue(self::observe($this->operations(process: 200, house: null), 'blog')['ok']);
        self::assertSame(422, self::observe($this->operations(process: 422, house: null), 'blog')['status']);
    }

    public function testAStandaloneCallerKeepsAskingItsOwnProcess(): void
    {
        $store = $this->store();
        $operations = (new ScreenOperations($store, serve: static fn (string $name): int => 200))->operations();

        self::assertTrue(self::observe($operations, 'blog')['ok']);
    }

    public function testDeclaringStillAsksTheProcessThatDeclares(): void
    {
        // A declaration is rehearsed in a trial, whose process IS the copy it describes: the house's own process
        // would answer for a house the declaration has not reached yet.
        $asked = [];
        $operations = (new ScreenOperations(
            $this->store(),
            serve: static function (string $name) use (&$asked): int {
                $asked[] = 'process';

                return 200;
            },
            observe: static function (string $name) use (&$asked): int {
                $asked[] = 'house';

                return 200;
            },
        ))->operations();

        $declared = self::call($operations, 'screen:declare', ['name' => 'kpi', 'rows' => [], 'columns' => []]);

        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame(['process'], $asked);
    }

    public function testTheLiveDoorAsksTheHouseThroughItsFrontController(): void
    {
        // The asking process cannot paint «blog»: its screen is bound to an entity no plugin of THIS process
        // registered. The house's own process answers through public/index.php, which says 200.
        $root = $this->house(frontController: '<?php http_response_code(200); echo "<h1>blog</h1>";');
        $seen = self::observe($this->liveDoor($root), 'blog');

        self::assertTrue($seen['ok'], json_encode($seen) ?: '');
        self::assertSame('served', $seen['evidence']['predicate']);
        self::assertSame(['kind' => 'house'], $seen['evidence']['environment']);
    }

    public function testTheLiveDoorAsksForThatScreensPage(): void
    {
        $root = $this->house(frontController: '<?php http_response_code(($_GET["component"] ?? "") === "blog" && parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) === "/live/page" ? 200 : 404);');

        self::assertTrue(self::observe($this->liveDoor($root), 'blog')['ok']);
    }

    public function testAHouseWhoseProcessDiesDoesNotServe(): void
    {
        $root = $this->house(frontController: '<?php exit(3);');
        $seen = self::observe($this->liveDoor($root), 'blog');

        self::assertFalse($seen['ok']);
        self::assertSame(500, $seen['status']);
        self::assertArrayNotHasKey('evidence', $seen);
    }

    public function testWhatTheHouseAnswersIsWhatIsReported(): void
    {
        $root = $this->house(frontController: '<?php http_response_code(404);');
        $seen = self::observe($this->liveDoor($root), 'blog');

        self::assertFalse($seen['ok']);
        self::assertSame(404, $seen['status']);
    }

    public function testALiveDoorWithoutAFrontControllerAsksItsOwnProcess(): void
    {
        $root = $this->house(frontController: null);
        $seen = self::observe($this->liveDoor($root), 'blog');

        // In-process the bound entity has no repository: the page cannot paint, and that is what is said.
        self::assertFalse($seen['ok']);
        self::assertSame(422, $seen['status']);
    }

    /** @return list<Operation> */
    private function operations(int $process, ?int $house): array
    {
        return (new ScreenOperations(
            $this->store(),
            serve: static fn (string $name): int => $process,
            observe: static fn (string $name): ?int => $house,
        ))->operations();
    }

    private function store(): ScreenStore
    {
        $root = $this->root();
        file_put_contents($root . '/config/screens.json', json_encode(['blog' => ['type' => 'data-table', 'props' => ['rows' => [], 'columns' => [], 'name' => 'blog']]]));

        return new ScreenStore($root . '/config/screens.json');
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-screen-observe-' . bin2hex(random_bytes(6));
        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/var', 0o777, true);

        return $this->roots[] = $root;
    }

    /** A house on disk whose screen «blog» is bound to an entity this process never registered. */
    private function house(?string $frontController): string
    {
        $root = $this->root();
        file_put_contents($root . '/config/screens.json', json_encode(['blog' => [
            'type' => 'content',
            'props' => ['heading' => 'Blog', 'roles' => ['title' => 'title', 'body' => 'body'],
                'source' => ['entity' => 'App\\Plugins\\Blog\\Entities\\Post', 'columns' => ['title', 'body'], 'limit' => 50], 'name' => 'blog'],
            'route' => '/blog',
        ]]));
        if ($frontController !== null) {
            mkdir($root . '/public');
            mkdir($root . '/vendor');
            file_put_contents($root . '/public/index.php', $frontController);
            file_put_contents($root . '/vendor/autoload.php', '<?php return require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        }

        return $root;
    }

    /** @return list<Operation> */
    private function liveDoor(string $root): array
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['live' => ['secret' => str_repeat('k', 32)]]));
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => [], 'bootedPluginNames' => [], 'plugins' => []] as $name => $value) {
            (new \ReflectionProperty(Kernel::class, $name))->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        $c->registerService(HouseRouteObserver::class, new HouseRouteObserver(timeoutSeconds: 10));
        $live = new LivePlugin($c);
        $live->boot();

        return array_values(array_filter($live->operations(), static fn (mixed $o): bool => $o instanceof Operation));
    }

    /**
     * @param list<Operation> $operations
     *
     * @return array<string, mixed>
     */
    private static function observe(array $operations, string $name): array
    {
        return self::call($operations, 'screen:observe', ['name' => $name]);
    }

    /**
     * @param list<Operation>      $operations
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function call(array $operations, string $name, array $input): array
    {
        foreach ($operations as $operation) {
            if ($operation->name === $name) {
                return ($operation->handler)($input);
            }
        }
        self::fail("{$name} is not offered");
    }
}
