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

use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Web\ComponentDeclarations;
use Milpa\AppRuntime\Web\ComponentWordOperations;
use Milpa\AppRuntime\Web\ComponentWords;
use Milpa\AppRuntime\Web\Controllers\LiveComponentPageController;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * A house learns a word (greenhouse decisions/0465), through the real door: LivePlugin booted on a
 * house root, `component:define` and `screen:declare` called as an agent calls them, the page fetched.
 *
 * The falsifiers are Rod's: a LATER session — a fresh plugin and container, nothing shared but the
 * house's files — discovers the word and uses it; a DIFFERENT house does not know it; and the word can
 * never carry markup.
 *
 * @guards discovery in screen:types and components:catalogue, compilation to existing primitives, the
 *         word's version on the screen, and promotion with the defining authority
 *
 * @refuses markup, unknown types, stray or unused inputs, a name the framework already uses, and uses
 *          that miss, mistype or invent an input
 *
 * @subject-in milpa/app-runtime
 */
final class AHouseLearnsAWordTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testALaterSessionDiscoversTheWordAndUsesItWithoutTheFirstOnesContext(): void
    {
        $house = $this->house();

        // ── Session A: the word does not exist, so A defines it ─────────────────────────────────
        $a = $this->session($house);
        $defined = $this->call($a, 'component:define', self::evidenceBalance());
        self::assertTrue($defined['ok'], json_encode($defined) ?: '');
        self::assertSame(1, $defined['version']);
        self::assertFileExists($house . '/' . ComponentWords::PATH, 'the word lives in the versioned tree');
        self::assertFileExists($house . '/var/components.lock', 'its lock is machinery, kept in var/');

        // ── Session B: a fresh plugin and container; only the house's files are shared ──────────
        $b = $this->session($house);
        $types = $this->call($b, 'screen:types', []);
        $row = array_values(array_filter($types['types'], static fn (array $t): bool => $t['name'] === 'evidence-balance'))[0] ?? null;
        self::assertNotNull($row, 'B discovers the word where it discovers every component');
        self::assertSame('house', $row['providedBy']);
        self::assertStringContainsString('support', $row['summary']);
        self::assertSame(['supports', 'contradicts'], array_keys($row['inputs']));
        self::assertSame(['dashboard-grid', 'metric-card'], $row['composes']);

        $catalogue = (new ComponentDeclarations($b))->catalogue('evidence-balance');
        self::assertSame(1, $catalogue->total, 'components:catalogue reads the same word');
        self::assertSame(['supports', 'contradicts'], array_keys($catalogue->components[0]['propsSchema']));

        $declared = $this->call($b, 'screen:declare', ['name' => 'h1', 'type' => 'evidence-balance', 'props' => ['supports' => 5, 'contradicts' => 1]]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame('served', $declared['evidence']['predicate'] ?? null, 'the compiled page answered 200');

        $html = $this->html($b, 'h1');
        self::assertStringContainsString('Supports', $html);
        self::assertStringContainsString('Contradicts', $html);
        self::assertMatchesRegularExpression('/>\s*5\s*</', $html);
        self::assertMatchesRegularExpression('/>\s*1\s*</', $html);

        $listed = $this->call($b, 'screen:list', []);
        self::assertSame(['evidence-balance', 1], [$listed['screens'][0]['word'] ?? null, $listed['screens'][0]['wordVersion'] ?? null], 'the screen remembers the word and version that made it');
    }

    public function testAnotherHouseDoesNotKnowTheWord(): void
    {
        $surco = $this->house();
        $this->call($this->session($surco), 'component:define', self::evidenceBalance());

        $blog = $this->session($this->house());
        self::assertNotContains('evidence-balance', array_column($this->call($blog, 'screen:types', [])['types'], 'name'));
        $refused = $this->call($blog, 'screen:declare', ['name' => 'h1', 'type' => 'evidence-balance', 'props' => ['supports' => 5, 'contradicts' => 1]]);
        self::assertFalse($refused['ok']);
        self::assertSame('unknown component type', $refused['error'], 'the framework did not learn it — one house did');
    }

    public function testAWordNeverCarriesMarkupAndEveryRuleIsNamed(): void
    {
        $session = $this->session($this->house());
        $word = self::evidenceBalance();
        $cases = [
            'composition.props.children.0.props.captionHtml' => self::with($word, ['composition', 'props', 'children', 0, 'props', 'captionHtml'], '<script>x</script>'),
            'composition' => self::with($word, ['composition', 'props', 'children', 0, 'type'], 'raw-html'),
            'composition.props.children.1.props.value' => self::with($word, ['composition', 'props', 'children', 1, 'props', 'value'], '$total'),
            'inputs.contradicts' => self::with($word, ['composition', 'props', 'children', 1, 'props', 'value'], '$supports'),
            'name' => self::with($word, ['name'], 'data-table'),
            'summary' => self::with($word, ['summary'], ''),
        ];
        foreach ($cases as $path => $input) {
            $refused = $this->call($session, 'component:define', $input);
            self::assertFalse($refused['ok'], $path);
            self::assertSame($path, $refused['path'] ?? null, json_encode($refused) ?: '');
        }
        self::assertFalse(is_file($session->get(Kernel::class)->root() . '/' . ComponentWords::PATH), 'nothing refused was written');
    }

    public function testAUseThatMissesMistypesOrInventsAnInputIsRefusedByName(): void
    {
        $session = $this->session($this->house());
        $this->call($session, 'component:define', self::evidenceBalance());

        foreach ([
            'props.contradicts' => ['supports' => 5],
            'props.supports' => ['supports' => 'five', 'contradicts' => 1],
            'props.weight' => ['supports' => 5, 'contradicts' => 1, 'weight' => 2],
        ] as $path => $props) {
            $refused = $this->call($session, 'screen:declare', ['name' => 'h1', 'type' => 'evidence-balance', 'props' => $props]);
            self::assertFalse($refused['ok'], $path);
            self::assertSame($path, $refused['path'] ?? null, json_encode($refused) ?: '');
        }
    }

    public function testAWordRehearsedInATrialCrossesOnlyWithTheDefiningAuthority(): void
    {
        $house = $this->house();
        $trial = TrialWorkspace::materialize($house, 'word', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        $this->call($this->session($trial->copy), 'component:define', self::evidenceBalance());
        self::assertSame([ComponentWords::PATH], array_keys($trial->diff()), 'the diff sees the word, and only it');

        $promote = (new TrialOperations(new DIContainer(), root: $house))->operations()[0];
        try {
            ($promote->handler)(['workspace' => $trial->id], null, new ToolContext(principal: 'w', channel: 'cli', scopes: ['milpa:component:data-table:*']));
            self::fail('declaring screens is not defining the language');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString("'" . ComponentWordOperations::SCOPE . "'", $refused->getMessage());
        }
        $result = ($promote->handler)(['workspace' => $trial->id], null, new ToolContext(principal: 'w', channel: 'cli', scopes: [ComponentWordOperations::SCOPE]));
        self::assertTrue($result['ok'], json_encode($result) ?: '');
        self::assertContains('evidence-balance', array_column($this->call($this->session($house), 'screen:types', [])['types'], 'name'));
    }

    public function testObservingAScreenInTheHouseEarnsServedHereAndOnlyOnA200(): void
    {
        $house = $this->session($this->house());
        $this->call($house, 'screen:declare', ['name' => 'kpi', 'type' => 'metric-card', 'props' => ['title' => 'Open', 'value' => '3']]);

        $seen = $this->call($house, 'screen:observe', ['name' => 'kpi']);
        self::assertTrue($seen['ok'], json_encode($seen) ?: '');
        self::assertSame(['predicate' => 'served', 'subject' => 'kpi', 'servedAt' => '/live/page?component=kpi', 'environment' => ['kind' => 'house']], $seen['evidence']);

        $missing = $this->call($house, 'screen:observe', ['name' => 'nope']);
        self::assertFalse($missing['ok']);
        self::assertArrayNotHasKey('evidence', $missing);

        // A screen whose page cannot paint earns nothing, and says what it answered.
        $this->call($house, 'screen:declare', ['name' => 'broken', 'type' => 'content', 'props' => ['roles' => ['title' => 'title', 'body' => 'body'], 'rows' => [['title' => 'x']]]]);
        $broken = $this->call($house, 'screen:observe', ['name' => 'broken']);
        self::assertFalse($broken['ok']);
        self::assertSame(422, $broken['status']);
        self::assertArrayNotHasKey('evidence', $broken);
    }

    public function testAWordBindsWhereItsRootCanAndOnlyThere(): void
    {
        // Measured (evidence/1002): the resident composed a readable list without data and tried to bind
        // it at use; the house refused by a rule nobody had measured. The rule now is the type rule.
        $house = $this->session($this->house());
        $articles = new \Milpa\Data\InMemoryRepository(WordArticle::class);
        $articles->save(WordArticle::fromArray(['id' => 1, 'title' => 'Out now', 'body' => 'read me', 'published' => true]));
        $articles->save(WordArticle::fromArray(['id' => 2, 'title' => 'Secret draft', 'body' => 'no', 'published' => false]));
        $house->registerService(WordArticle::class . 'Repository', $articles);

        $this->call($house, 'component:define', [
            'name' => 'post-list', 'summary' => 'the published posts, readable',
            'inputs' => ['heading' => ['type' => 'string']],
            'composition' => ['type' => 'content', 'props' => ['heading' => '$heading', 'roles' => ['title' => 'title', 'body' => 'body']]],
        ]);
        $declared = $this->call($house, 'screen:declare', [
            'name' => 'blog', 'type' => 'post-list', 'props' => ['heading' => 'The blog'],
            'source' => ['entity' => WordArticle::class, 'columns' => ['title', 'body']],
        ]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        $html = $this->html($house, 'blog');
        self::assertStringContainsString('Out now', $html);
        self::assertStringNotContainsString('Secret draft', $html, 'bound through the entity\'s own visibility');

        // A word whose root has no rows cannot be bound — the same contract rule as any type.
        $this->call($house, 'component:define', self::evidenceBalance());
        $refused = $this->call($house, 'screen:declare', [
            'name' => 'b2', 'type' => 'evidence-balance', 'props' => ['supports' => 1, 'contradicts' => 0],
            'source' => ['entity' => WordArticle::class, 'columns' => ['title']],
        ]);
        self::assertSame('source', $refused['path'] ?? null);
        self::assertStringContainsString('declares no rows prop', (string) ($refused['reason'] ?? ''));

        // A word that already carries its source is not bound twice.
        $this->call($house, 'component:define', [
            'name' => 'post-feed', 'summary' => 'bound already', 'inputs' => ['heading' => ['type' => 'string']],
            'composition' => ['type' => 'content', 'props' => ['heading' => '$heading', 'roles' => ['title' => 'title', 'body' => 'body'],
                'source' => ['entity' => WordArticle::class, 'columns' => ['title', 'body']]]],
        ]);
        $twice = $this->call($house, 'screen:declare', [
            'name' => 'b3', 'type' => 'post-feed', 'props' => ['heading' => 'x'],
            'source' => ['entity' => WordArticle::class, 'columns' => ['title']],
        ]);
        self::assertStringContainsString('already binds its own source', (string) ($twice['reason'] ?? ''));
    }

    /** @return array<string, mixed> */
    private static function evidenceBalance(): array
    {
        return [
            'name' => 'evidence-balance',
            'summary' => 'How much evidence supports a hypothesis against how much contradicts it.',
            'inputs' => [
                'supports' => ['type' => 'integer', 'description' => 'pieces of evidence that support it'],
                'contradicts' => ['type' => 'integer', 'description' => 'pieces of evidence that contradict it'],
            ],
            'composition' => ['type' => 'dashboard-grid', 'props' => ['children' => [
                ['type' => 'metric-card', 'props' => ['title' => 'Supports', 'value' => '$supports']],
                ['type' => 'metric-card', 'props' => ['title' => 'Contradicts', 'value' => '$contradicts']],
            ]]],
        ];
    }

    /**
     * @param array<string, mixed> $word
     * @param list<int|string>     $at
     *
     * @return array<string, mixed>
     */
    private static function with(array $word, array $at, mixed $value): array
    {
        $node = &$word;
        foreach ($at as $key) {
            $node = &$node[$key];
        }
        $node = $value;

        return $word;
    }

    /** A house: its own root, its own config, nothing else. */
    private function house(): string
    {
        $root = sys_get_temp_dir() . '/milpa-word-' . bin2hex(random_bytes(6));
        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/var', 0o777, true);
        $this->roots[] = $root;

        return $root;
    }

    /** One session of a house: a fresh container and a booted live door, sharing only the files. */
    private function session(string $root): DIContainer
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['live' => ['secret' => str_repeat('k', 32)]]));
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => [], 'bootedPluginNames' => [], 'plugins' => []] as $name => $value) {
            (new \ReflectionProperty(Kernel::class, $name))->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        (new LivePlugin($c))->boot();

        return $c;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $session, string $name, array $input): array
    {
        foreach ((new LivePlugin($session))->operations() as $operation) {
            if ($operation instanceof Operation && $operation->name === $name) {
                return ($operation->handler)($input);
            }
        }
        self::fail("{$name} is not offered");
    }

    private function html(DIContainer $session, string $screen): string
    {
        $response = $session->get(LiveComponentPageController::class)
            ->show((new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => $screen]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}

/** A readable entity with a declared visibility, for the words that bind. */
final readonly class WordArticle implements \Milpa\Data\EntityInterface
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
