<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Console\KernelSupervisor;
use Milpa\AppRuntime\Console\StaleWatchTerminal;
use Milpa\AppRuntime\Support\KernelDefinition;
use Milpa\Live\Contracts\Tui\TerminalInterface;
use PHPUnit\Framework\TestCase;

/**
 * `coa mcp` and `coa panel` outlive what defines the house (greenhouse decisions/0507, evidence/1040).
 *
 * The relay is driven for real, in its own process, over a stand-in child with the same contract as `coa mcp
 * --child` (exit 75 when stale, before or after a request): what is asserted is what crosses the client's wire and
 * what the children actually ran.
 */
final class ALongLivedProcessKnowsWhenItWentStaleTest extends TestCase
{
    private string $state;

    /** @var null|resource */
    private $relay = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $buffer = '';

    protected function setUp(): void
    {
        $this->state = sys_get_temp_dir() . '/milpa-supervisor-' . bin2hex(random_bytes(5));
        mkdir($this->state, 0o775, true);
    }

    protected function tearDown(): void
    {
        if ($this->relay !== null) {
            foreach ($this->pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            // Terminated, not awaited: a relay that lost a request (a mutant) would otherwise keep this test hanging
            // instead of failing it.
            proc_terminate($this->relay);
            proc_close($this->relay);
        }
        foreach (glob($this->state . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->state);
    }

    public function testARequestThatMeetsAStaleChildRunsOnceInTheNextOne(): void
    {
        $this->start();
        self::assertSame('0', $this->ask(1, 'work', 'a')['result']['generation']);

        file_put_contents($this->state . '/generation', '1');
        $answer = $this->ask(2, 'work', 'b');

        self::assertSame('1', $answer['result']['generation'], 'the request was served by a kernel of now');
        self::assertSame(["work a 0", "work b 1"], $this->lines('ran.log'), 'b ran exactly once — never in the stale child');
        self::assertCount(2, $this->lines('starts.log'));
    }

    public function testPipelinedRequestsBehindAStaleChildAreAllAnsweredOnce(): void
    {
        $this->start();
        $this->ask(1, 'work', 'a');
        file_put_contents($this->state . '/generation', '1');
        $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'work', 'params' => ['tag' => 'p1']]);
        $this->send(['jsonrpc' => '2.0', 'id' => 'three', 'method' => 'work', 'params' => ['tag' => 'p2']]);

        $answers = [$this->next(), $this->next()];

        self::assertSame([2, 'three'], array_map(static fn (array $m): mixed => $m['id'], $answers), 'in order, string ids too');
        self::assertSame(["work a 0", "work p1 1", "work p2 1"], $this->lines('ran.log'));
    }

    public function testTheRequestThatChangedTheHouseIsAnsweredBeforeItsChildLeaves(): void
    {
        $this->start();
        $bumped = $this->ask(1, 'bump', 'x');
        $after = $this->ask(2, 'work', 'y');

        self::assertSame('0', $bumped['result']['generation'], 'the change was answered by the child that made it');
        self::assertSame('1', $after['result']['generation']);
        self::assertSame(["bump x 0", "work y 1"], $this->lines('ran.log'));
    }

    public function testTheClientIsToldOnceWhenTheToolsMovedAndNotWhenTheyDidNot(): void
    {
        file_put_contents($this->state . '/tools.json', json_encode([['name' => 'a']]));
        $this->start();
        $init = $this->ask(1, 'initialize');
        self::assertTrue($init['result']['capabilities']['tools']['listChanged'], 'the relay declares what it can tell');
        $this->ask(2, 'tools/list');

        // A change that does not move the list: a restart, and silence.
        file_put_contents($this->state . '/generation', '1');
        self::assertNull($this->next(1.2), 'no notification for a list that did not move');
        self::assertCount(2, $this->lines('starts.log'), 'the idle ping found the change without a client call');

        // A change that moves it, while the client asks nothing.
        file_put_contents($this->state . '/tools.json', json_encode([['name' => 'a'], ['name' => 'b']]));
        file_put_contents($this->state . '/generation', '2');
        $notice = $this->next(1.2);
        self::assertSame(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed'], $notice);
        self::assertNull($this->next(1.0), 'once');
    }

    public function testAChildThatCannotBootIsAnErrorPerRequestAndNeverALoop(): void
    {
        $this->start();
        $this->ask(1, 'work', 'a');
        touch($this->state . '/broken');
        file_put_contents($this->state . '/generation', '1');

        $refused = $this->ask(2, 'work', 'b');
        self::assertSame(-32603, $refused['error']['code']);
        self::assertStringContainsString('the house did not start', $refused['error']['message']);
        self::assertStringContainsString('the plugin Broken is no plugin', $refused['error']['message'], 'the client reads why');

        usleep(1_500_000);
        self::assertCount(2, $this->lines('starts.log'), 'silence while broken starts nothing');

        unlink($this->state . '/broken');
        self::assertSame('1', $this->ask(3, 'work', 'c')['result']['generation'], 'the pipe stayed open; the next call works');
        self::assertSame(["work a 0", "work c 1"], $this->lines('ran.log'), 'nothing ran while the house was broken');
    }

    public function testALineThatIsNoMessageNeverReachesTheClientsWire(): void
    {
        $this->start();
        $answer = $this->ask(1, 'echo-garbage');

        self::assertSame(1, $answer['id'], 'the next thing on the wire is the answer, not the echo');
    }

    public function testNothingRestartsInSteadyState(): void
    {
        $this->start();
        for ($i = 1; $i <= 50; ++$i) {
            $this->ask($i, 'work', "s{$i}");
        }
        usleep(1_200_000);

        self::assertCount(1, $this->lines('starts.log'), '50 calls and four idle pings: one child');
    }

    public function testAStalePanelIsReopenedOnTheSectionItWasShowing(): void
    {
        file_put_contents($this->state . '/stale', '2');
        file_put_contents($this->state . '/showing', 'plugins');
        file_put_contents($this->state . '/exit', '0');

        $code = KernelSupervisor::terminal(
            fn (?string $showing): array => [\PHP_BINARY, __DIR__ . '/../Fixtures/supervisor/terminal-child.php', $this->state, ...($showing !== null ? [$showing] : [])],
            $this->state,
            'routes',
        );

        self::assertSame(0, $code);
        self::assertSame(['routes', 'plugins', 'plugins'], $this->lines('opened.log'));
    }

    public function testAPanelThatWillNotSettleStops(): void
    {
        file_put_contents($this->state . '/stale', '99');
        $code = KernelSupervisor::terminal(
            fn (?string $showing): array => [\PHP_BINARY, __DIR__ . '/../Fixtures/supervisor/terminal-child.php', $this->state],
            $this->state,
        );

        self::assertSame(1, $code);
        self::assertCount(KernelSupervisor::STALE_IN_A_ROW, $this->lines('opened.log'));
    }

    public function testTheScreensTerminalClosesTheScreenOnlyWhenTheKernelWentStale(): void
    {
        mkdir($this->state . '/storage');
        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[]}');
        $inner = new class () implements TerminalInterface {
            public function start(callable $onInput, callable $onResize): void
            {
            }

            public function stop(): void
            {
            }

            public function write(string $data): void
            {
            }

            public function pollInput(): string
            {
                return 'k';
            }

            public function atEndOfInput(): bool
            {
                return false;
            }

            public function columns(): int
            {
                return 80;
            }

            public function rows(): int
            {
                return 24;
            }

            public function moveBy(int $lines): void
            {
            }

            public function hideCursor(): void
            {
            }

            public function showCursor(): void
            {
            }

            public function clearLine(): void
            {
            }

            public function clearFromCursor(): void
            {
            }

            public function clearScreen(): void
            {
            }

            public function setTitle(string $title): void
            {
            }
        };
        $watch = new StaleWatchTerminal($inner, KernelDefinition::before($this->state), every: 0.0);

        self::assertSame('k', $watch->pollInput(), 'current: the person\'s keys pass');
        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[]}');
        self::assertSame('k', $watch->pollInput(), 'an identical rewrite is no change');
        self::assertNull($watch->staleBecause());

        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[{"name":"Blog"}]}');
        self::assertSame("\x03", $watch->pollInput(), 'stale: the key that closes the screen');
        self::assertSame('storage/plugins.json', $watch->staleBecause());
        self::assertSame("\x03", $watch->pollInput(), 'and it stays closed');
        rename($this->state . '/storage/plugins.json', $this->state . '/plugins.json.bak');
        rmdir($this->state . '/storage');
    }

    public function testCoaMcpWithoutTheSurfaceSaysSoOnStderrAndKeepsStdoutEmpty(): void
    {
        if (class_exists(\Milpa\McpServer\JsonRpcService::class)) {
            self::markTestSkipped('milpa/mcp-server is installed here');
        }
        mkdir($this->state . '/config');
        file_put_contents($this->state . '/config/app.php', '<?php return [];');

        ob_start();
        $code = (new Application($this->state))->run(['coa', 'mcp']);
        $stdout = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertSame('', $stdout, 'STDOUT is the protocol');
        unlink($this->state . '/config/app.php');
        rmdir($this->state . '/config');
    }

    private function start(): void
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__ . '/../Fixtures/supervisor/relay.php', $this->state, \dirname(__DIR__, 2) . '/vendor/autoload.php', '0.3'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->state . '/stderr.log', 'a']],
            $pipes,
        );
        self::assertIsResource($process);
        $this->relay = $process;
        $this->pipes = $pipes;
        stream_set_blocking($pipes[1], false);
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        fwrite($this->pipes[0], json_encode($message) . "\n");
    }

    /** @return array<string, mixed> */
    private function ask(int $id, string $method, ?string $tag = null): array
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method] + ($tag !== null ? ['params' => ['tag' => $tag]] : []));
        $answer = $this->next(10.0);
        self::assertIsArray($answer, "no answer to {$method} #{$id}");
        self::assertSame($id, $answer['id'] ?? null);

        return $answer;
    }

    /** @return null|array<string, mixed> the next line the client receives, or null after `$seconds` */
    private function next(float $seconds = 5.0): ?array
    {
        $until = microtime(true) + $seconds;
        while (($nl = strpos($this->buffer, "\n")) === false) {
            $left = $until - microtime(true);
            if ($left <= 0) {
                return null;
            }
            $read = [$this->pipes[1]];
            $write = $except = null;
            if (stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1_000_000)) > 0) {
                $this->buffer .= (string) fread($this->pipes[1], 65536);
            }
        }
        $line = substr($this->buffer, 0, $nl);
        $this->buffer = substr($this->buffer, $nl + 1);
        $message = json_decode($line, true);
        self::assertIsArray($message, "the client's wire carried a line that is no message: {$line}");

        return $message;
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        return is_file($this->state . '/' . $file) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->state . '/' . $file)))) : [];
    }
}
