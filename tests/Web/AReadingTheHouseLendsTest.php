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
use Milpa\AppRuntime\Web\HouseReading;
use Milpa\AppRuntime\Web\HouseReadings;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\AppRuntime\Web\ReadingAudience;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * A reading the house lends (greenhouse decisions/0484), through the real door.
 *
 * Surco taught a word, `evidence-balance`, that an agent declares without code — but with fixed numbers: the
 * balance of a real hypothesis lives in a PRIVATE graph that no `PUBLIC_WHEN` may expose. A reading is the
 * house's own code that says what it shows and to whom; a screen only names it. The runtime judges the
 * audience on every request before reading, and declaring never reads.
 *
 * @guards the audience judged per request before anything is read (401 anonymous, 403 without the scope),
 *         values read now, projected to what the reading fills, through a primitive and through a word
 *
 * @refuses an unknown reading, a missing / undeclared / mistyped argument, a type that does not declare what
 *          the reading fills, a filled prop written by hand, a word re-taught since the screen was declared
 *
 * @subject-in milpa/app-runtime
 */
final class AReadingTheHouseLendsTest extends TestCase
{
    private string $dir = '';

    private DIContainer $container;

    private LentBalance $balance;

    protected function setUp(): void
    {
        if (! class_exists(AuthContext::class)) {
            self::markTestSkipped('milpa/auth is not installed');
        }
        $this->dir = sys_get_temp_dir() . '/milpa-reading-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        $this->container = new DIContainer();
        $this->container->registerService(Config::class, new Config(['live' => [
            'secret' => str_repeat('k', 32),
            'screens_path' => $this->dir . '/screens.json',
        ]]));
        $kernel = Kernel::boot(['root' => $this->dir, 'container' => $this->container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $this->container->registerService(Kernel::class, $kernel);
        (new LivePlugin($this->container))->boot();
        $this->balance = new LentBalance(ReadingAudience::members());
        HouseReadings::in($this->container)->register($this->balance);
    }

    protected function tearDown(): void
    {
        if ($this->dir === '') {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testAMembersReadingIsShownToAMemberReadNowAndToNobodyElse(): void
    {
        $declared = $this->declare(['name' => 'h-balance', 'type' => 'metric-card', 'props' => ['title' => 'Supporting'],
            'source' => ['reading' => 'claim-balance', 'arguments' => ['claim' => 'h-1']]]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame(0, $this->balance->reads, 'declaring never reads');

        $anonymous = $this->show(null);
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertStringNotContainsString('41', (string) $anonymous->getBody(), 'a denied page carries no value');
        self::assertSame(0, $this->balance->reads, 'the audience is judged before anything is read');

        self::assertSame('41', $this->value($this->show(['live:act'])));
        LentBalance::$supporting['h-1'] = '42';
        self::assertSame('42', $this->value($this->show(['live:act'])), 'read now, not when it was declared');
        self::assertStringNotContainsString('private-note', (string) $this->show(['live:act'])->getBody(), 'only what the reading fills reaches the page');
    }

    public function testAScopedReadingDeniesAPrincipalWithoutItsScope(): void
    {
        $scoped = new LentBalance(ReadingAudience::scope('claims:read'), 'scoped-balance');
        HouseReadings::in($this->container)->register($scoped);
        self::assertTrue($this->declare(['name' => 'h-balance', 'type' => 'metric-card', 'props' => ['title' => 'Supporting'],
            'source' => ['reading' => 'scoped-balance', 'arguments' => ['claim' => 'h-1']]])['ok']);

        self::assertSame(403, $this->show(['live:act'])->getStatusCode());
        self::assertSame(0, $scoped->reads);
        self::assertSame(200, $this->show(['claims:read'])->getStatusCode());
    }

    public function testEachWrongBindingIsRefusedByName(): void
    {
        $bind = static fn (array $source, string $type = 'metric-card', array $props = ['title' => 'S']): array => ['name' => 'x', 'type' => $type, 'props' => $props, 'source' => $source];
        $cases = [
            'source.reading' => $bind(['reading' => 'nothing-here', 'arguments' => []]),
            'source.arguments.claim' => $bind(['reading' => 'claim-balance', 'arguments' => []]),
            'source.arguments.since' => $bind(['reading' => 'claim-balance', 'arguments' => ['claim' => 'h-1', 'since' => 'x']]),
            'source.arguments.claim ' => $bind(['reading' => 'claim-balance', 'arguments' => ['claim' => 7]]),
            'source.reading ' => $bind(['reading' => 'claim-balance', 'arguments' => ['claim' => 'h-1']], 'data-table', []),
            'value' => $bind(['reading' => 'claim-balance', 'arguments' => ['claim' => 'h-1']], 'metric-card', ['title' => 'S', 'value' => '9']),
        ];
        foreach ($cases as $path => $input) {
            $refused = $this->declare($input);
            self::assertFalse($refused['ok'], $path);
            self::assertSame(trim($path), $refused['path'] ?? null, json_encode($refused) ?: '');
        }
        self::assertSame(0, $this->balance->reads);
    }

    public function testAReadingFillsAWordsInputsAndTheWordIsCompiledWithWhatWasRead(): void
    {
        $this->define(['name' => 'balance-card', 'summary' => 'how much supports a claim',
            'inputs' => ['label' => ['type' => 'string'], 'value' => ['type' => 'string']],
            'composition' => ['type' => 'metric-card', 'props' => ['title' => '$label', 'value' => '$value']]]);
        $declared = $this->declare(['name' => 'h-balance', 'type' => 'balance-card', 'props' => ['label' => 'Supporting'],
            'source' => ['reading' => 'claim-balance', 'arguments' => ['claim' => 'h-1']]]);
        self::assertTrue($declared['ok'], json_encode($declared) ?: '');
        self::assertSame(0, $this->balance->reads);
        self::assertSame(401, $this->show(null)->getStatusCode());
        self::assertSame('41', $this->value($this->show(['live:act'])));
        LentBalance::$supporting['h-1'] = '43';
        self::assertSame('43', $this->value($this->show(['live:act'])));

        $written = $this->declare(['name' => 'h2', 'type' => 'balance-card', 'props' => ['label' => 'S', 'value' => '1'],
            'source' => ['reading' => 'claim-balance', 'arguments' => ['claim' => 'h-1']]]);
        self::assertSame('props.value', $written['path'] ?? null, json_encode($written) ?: '');

        // Re-taught: the screen was declared with version 1, and is refused rather than half-applied.
        $this->define(['name' => 'balance-card', 'summary' => 'how much supports a claim, now labelled',
            'inputs' => ['label' => ['type' => 'string'], 'value' => ['type' => 'string']],
            'composition' => ['type' => 'metric-card', 'props' => ['title' => '$label', 'value' => '$value', 'label' => 'x']]]);
        self::assertSame(422, $this->show(['live:act'])->getStatusCode());
    }

    public function testTheReadingsAreDiscoverable(): void
    {
        foreach ((new LivePlugin($this->container))->operations() as $operation) {
            if ($operation instanceof Operation && $operation->name === 'screen:readings') {
                $listed = ($operation->handler)([]);
                self::assertSame([[
                    'name' => 'claim-balance',
                    'summary' => 'how much evidence supports a claim',
                    'arguments' => ['claim' => ['type' => 'string', 'description' => 'the claim id']],
                    'fills' => ['value'],
                    'audience' => 'members',
                ]], $listed['readings']);

                return;
            }
        }
        self::fail('screen:readings is not offered');
    }

    /** @param list<string>|null $scopes null: an anonymous request */
    private function show(?array $scopes): ResponseInterface
    {
        $request = (new ServerRequest('GET', '/live/page'))->withQueryParams(['component' => 'h-balance']);
        if ($scopes !== null) {
            $request = $request->withAttribute('milpa.auth', AuthContext::authenticated(new Actor('rod', ActorType::Service, $scopes)));
        }

        return $this->container->get(LiveComponentPageController::class)->show($request);
    }

    private function value(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        preg_match('~<span class="mui-stat__value">(.*?)</span>~s', (string) $response->getBody(), $m);

        return trim(strip_tags($m[1] ?? ''));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function declare(array $input): array
    {
        return $this->operate('screen:declare', $input);
    }

    /** @param array<string, mixed> $word */
    private function define(array $word): void
    {
        $defined = $this->operate('component:define', $word);
        self::assertTrue($defined['ok'], json_encode($defined) ?: '');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function operate(string $name, array $input): array
    {
        foreach ((new LivePlugin($this->container))->operations() as $operation) {
            if ($operation instanceof Operation && $operation->name === $name) {
                return ($operation->handler)($input);
            }
        }
        self::fail("{$name} is not offered");
    }
}

/** A house's private balance, lent to its screens: what supports each claim, and a note it never shows. */
final class LentBalance implements HouseReading
{
    /** @var array<string, string> */
    public static array $supporting = ['h-1' => '41'];

    public int $reads = 0;

    public function __construct(private readonly ReadingAudience $audience, private readonly string $name = 'claim-balance')
    {
        self::$supporting = ['h-1' => '41'];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function summary(): string
    {
        return 'how much evidence supports a claim';
    }

    public function arguments(): array
    {
        return ['claim' => ['type' => 'string', 'description' => 'the claim id']];
    }

    public function fills(): array
    {
        return ['value'];
    }

    public function audience(): ReadingAudience
    {
        return $this->audience;
    }

    public function read(array $arguments): array
    {
        ++$this->reads;

        return ['value' => self::$supporting[(string) $arguments['claim']] ?? '0', 'note' => 'private-note'];
    }
}
