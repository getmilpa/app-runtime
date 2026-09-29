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
 * The relay runs for real, IN this process, between two others: a scripted client (`Fixtures/supervisor/client.php`)
 * on the client's side, and a stand-in child with the same contract as `coa mcp --child` (exit 75 when stale, before
 * or after a request) on the other. What is asserted is what crossed the client's wire and what the children RAN.
 */
final class ALongLivedProcessKnowsWhenItWentStaleTest extends TestCase
{
    private const INIT = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'];

    private string $state;

    protected function setUp(): void
    {
        $this->state = sys_get_temp_dir() . '/milpa-supervisor-' . bin2hex(random_bytes(5));
        mkdir($this->state, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->state . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->state);
    }

    public function testARequestThatMeetsAStaleChildRunsOnceInTheNextOne(): void
    {
        $seen = $this->relay([
            ['send' => self::work(1, 'a')], ['expect' => 5],
            ['write' => ['generation', '1']],
            ['send' => self::work(2, 'b')], ['expect' => 5],
        ]);

        self::assertSame('0', $seen[0]['result']['generation']);
        self::assertSame('1', $seen[1]['result']['generation'], 'the request was served by a kernel of now');
        self::assertSame(['work a 0', 'work b 1'], $this->lines('ran.log'), 'b ran exactly once — never in the stale child');
        self::assertCount(2, $this->lines('starts.log'));
    }

    public function testPipelinedRequestsBehindAStaleChildAreAllAnsweredOnce(): void
    {
        $seen = $this->relay([
            ['send' => self::work(1, 'a')], ['expect' => 5],
            ['write' => ['generation', '1']],
            ['send' => self::work(2, 'p1')], ['send' => self::work('three', 'p2')],
            ['expect' => 5], ['expect' => 5],
        ]);

        self::assertSame([1, 2, 'three'], array_map(static fn (array $m): mixed => $m['id'], $seen), 'in order, string ids too');
        self::assertSame(['work a 0', 'work p1 1', 'work p2 1'], $this->lines('ran.log'));
    }

    public function testTheRequestThatChangedTheHouseIsAnsweredBeforeItsChildLeaves(): void
    {
        $seen = $this->relay([
            ['send' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'bump', 'params' => ['tag' => 'x']]], ['expect' => 5],
            ['send' => self::work(2, 'y')], ['expect' => 5],
        ]);

        self::assertSame('0', $seen[0]['result']['generation'], 'the change was answered by the child that made it');
        self::assertSame('1', $seen[1]['result']['generation']);
        self::assertSame(['bump x 0', 'work y 1'], $this->lines('ran.log'));
    }

    public function testTheClientIsToldOnceWhenTheToolsMovedAndNotWhenTheyDidNot(): void
    {
        file_put_contents($this->state . '/tools.json', json_encode([['name' => 'a']]));
        $seen = $this->relay([
            ['send' => self::INIT], ['expect' => 5],
            ['send' => ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']], ['expect' => 5],
            // A change that does not move the list: the idle ping finds it, and nothing is said.
            ['write' => ['generation', '1']], ['quiet' => 1.2],
            // A change that moves it, while the client asks nothing.
            ['write' => ['tools.json', json_encode([['name' => 'a'], ['name' => 'b']])]],
            ['write' => ['generation', '2']], ['quiet' => 1.5],
        ]);

        self::assertTrue($seen[0]['result']['capabilities']['tools']['listChanged'], 'the relay declares what it can tell');
        self::assertSame([['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']], \array_slice($seen, 2), 'once, and only for the move');
        self::assertCount(3, $this->lines('starts.log'), 'both changes were found without a client call');
    }

    public function testAChildThatCannotBootIsAnErrorPerRequestAndNeverALoop(): void
    {
        $seen = $this->relay([
            ['send' => self::work(1, 'a')], ['expect' => 5],
            ['touch' => 'broken'], ['write' => ['generation', '1']],
            ['send' => self::work(2, 'b')], ['expect' => 5],
            ['quiet' => 1.0],
            ['remove' => 'broken'],
            ['send' => self::work(3, 'c')], ['expect' => 5],
        ]);

        self::assertSame(-32603, $seen[1]['error']['code']);
        self::assertStringContainsString('the house did not start', $seen[1]['error']['message']);
        self::assertStringContainsString('the plugin Broken is no plugin', $seen[1]['error']['message'], 'the client reads why');
        self::assertSame('1', $seen[2]['result']['generation'], 'the pipe stayed open; the next call works');
        self::assertCount(3, $seen, 'nothing else on the wire while it was broken');
        self::assertCount(3, $this->lines('starts.log'), 'silence while broken starts nothing');
        self::assertSame(['work a 0', 'work c 1'], $this->lines('ran.log'), 'nothing ran while the house was broken');
    }

    public function testAChildThatDiesWhileServingSaysTheCallMayHaveRun(): void
    {
        $seen = $this->relay([
            ['send' => self::work(1, 'a')], ['expect' => 5],
            ['send' => ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'die']], ['expect' => 5],
            ['send' => self::work(3, 'c')], ['expect' => 5],
        ]);

        self::assertStringContainsString('ended while serving (exit 3): ✗ died while serving', $seen[1]['error']['message']);
        self::assertStringContainsString('may or may not have taken effect', $seen[1]['error']['message']);
        self::assertSame('c', $seen[2]['result']['tag'], 'the next call starts a new process');
    }

    public function testAHouseThatNeverSettlesIsRefusedAfterACeiling(): void
    {
        $seen = $this->relay([
            ['send' => self::work(1, 'a')], ['expect' => 5],
            ['touch' => 'restless'], ['write' => ['generation', '1']],
            ['send' => self::work(2, 'b')], ['expect' => 10],
        ]);

        self::assertStringContainsString('changed ' . KernelSupervisor::STALE_IN_A_ROW . ' times in a row', $seen[1]['error']['message']);
        self::assertSame(['work a 0'], $this->lines('ran.log'), 'b never ran in a kernel that was stale at birth');
        self::assertCount(KernelSupervisor::STALE_IN_A_ROW, $this->lines('starts.log'), 'five stale exits in a row, then no sixth start');
    }

    public function testALineThatIsNoMessageNeverReachesTheClientsWire(): void
    {
        $seen = $this->relay([
            ['send' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'echo-garbage']], ['expect' => 5],
            ['raw' => ''],
            ['send' => ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']],
            ['send' => self::work(2, 'b')], ['expect' => 5],
        ]);

        self::assertSame([1, 2], array_map(static fn (array $m): mixed => $m['id'], $seen), 'the answers, not the echo');
        self::assertStringContainsString('an echo somewhere in the house', (string) file_get_contents($this->state . '/stderr.log'));
    }

    public function testNothingRestartsInSteadyState(): void
    {
        $plan = [];
        for ($i = 1; $i <= 50; ++$i) {
            $plan[] = ['send' => self::work($i, "s{$i}")];
            $plan[] = ['expect' => 5];
        }
        $plan[] = ['quiet' => 1.2];
        $seen = $this->relay($plan);

        self::assertCount(50, $seen, 'fifty answers and not one line more');
        self::assertCount(1, $this->lines('starts.log'), '50 calls and four idle pings: one child');
    }

    public function testAStalePanelIsReopenedOnTheSectionItWasShowing(): void
    {
        file_put_contents($this->state . '/stale', '2');
        file_put_contents($this->state . '/showing', 'plugins');
        file_put_contents($this->state . '/exit', '0');

        self::assertSame(0, KernelSupervisor::terminal($this->panel(...), $this->state, 'routes'));
        self::assertSame(['routes', 'plugins', 'plugins'], $this->lines('opened.log'));
    }

    public function testAPanelWhoseHouseDoesNotStartLeavesWithItsCode(): void
    {
        file_put_contents($this->state . '/exit', '2');

        self::assertSame(2, KernelSupervisor::terminal($this->panel(...), $this->state));
        self::assertSame(['-'], $this->lines('opened.log'));
    }

    public function testAPanelThatWillNotSettleStops(): void
    {
        file_put_contents($this->state . '/stale', '99');

        self::assertSame(1, KernelSupervisor::terminal($this->panel(...), $this->state));
        self::assertCount(KernelSupervisor::STALE_IN_A_ROW, $this->lines('opened.log'));
    }

    public function testTheScreensTerminalClosesTheScreenOnlyWhenTheKernelWentStale(): void
    {
        mkdir($this->state . '/storage');
        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[]}');
        $inner = new RecordingTerminal();
        $watch = new StaleWatchTerminal($inner, KernelDefinition::before($this->state), every: 0.0);

        self::assertSame('k', $watch->pollInput(), 'current: the person\'s keys pass');
        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[]}');
        self::assertSame('k', $watch->pollInput(), 'an identical rewrite is no change');
        self::assertNull($watch->staleBecause());

        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[{"name":"Blog"}]}');
        self::assertSame("\x03", $watch->pollInput(), 'stale: the key that closes the screen');
        self::assertSame('storage/plugins.json', $watch->staleBecause());
        self::assertSame("\x03", $watch->pollInput(), 'and it stays closed');
        unlink($this->state . '/storage/plugins.json');
    }

    public function testTheScreensTerminalIsTheRealOneForEverythingElse(): void
    {
        $inner = new RecordingTerminal();
        $watch = new StaleWatchTerminal($inner, KernelDefinition::before($this->state));

        $watch->start(static function (): void {
        }, static function (): void {
        });
        $watch->write('frame');
        $watch->moveBy(2);
        $watch->hideCursor();
        $watch->showCursor();
        $watch->clearLine();
        $watch->clearFromCursor();
        $watch->clearScreen();
        $watch->setTitle('coa');
        $watch->stop();

        self::assertSame(['start', 'write frame', 'moveBy 2', 'hideCursor', 'showCursor', 'clearLine', 'clearFromCursor', 'clearScreen', 'setTitle coa', 'stop'], $inner->calls);
        self::assertSame([80, 24, false], [$watch->columns(), $watch->rows(), $watch->atEndOfInput()]);
        self::assertSame('k', $watch->pollInput(), 'asked before its interval: the keys pass without a question');
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
    }

    /**
     * Run the relay here, against the scripted client of `$plan`, and return every message the client received.
     *
     * @param list<array<string, mixed>> $plan
     *
     * @return list<array<string, mixed>>
     */
    private function relay(array $plan): array
    {
        file_put_contents($this->state . '/plan.json', json_encode($plan));
        $client = proc_open(
            [\PHP_BINARY, __DIR__ . '/../Fixtures/supervisor/client.php', $this->state],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->state . '/client-stderr.log', 'a']],
            $pipes,
        );
        self::assertIsResource($client);
        $errors = fopen($this->state . '/stderr.log', 'a');
        self::assertIsResource($errors);

        $state = $this->state;
        $relay = new KernelSupervisor(
            static fn (): array => [\PHP_BINARY, __DIR__ . '/../Fixtures/supervisor/child.php', $state],
            $this->state,
            $pipes[1],
            $pipes[0],
            $errors,
            idle: 0.3,
        );
        self::assertSame(0, $relay->relay());
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($errors);
        proc_close($client);

        $seen = [];
        foreach ($this->lines('transcript.jsonl') as $entry) {
            $line = json_decode($entry, true)['line'] ?? null;
            self::assertIsString($line, "a step waited for a line that never came: {$entry}");
            $message = json_decode($line, true);
            self::assertIsArray($message, "the client's wire carried a line that is no message: {$line}");
            $seen[] = $message;
        }

        return $seen;
    }

    /** @return list<string> the command of a stand-in panel child, opened on `$showing` */
    private function panel(?string $showing): array
    {
        return [\PHP_BINARY, __DIR__ . '/../Fixtures/supervisor/terminal-child.php', $this->state, ...($showing !== null ? [$showing] : [])];
    }

    /** @return array<string, mixed> */
    private static function work(int|string $id, string $tag): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'work', 'params' => ['tag' => $tag]];
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        return is_file($this->state . '/' . $file) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->state . '/' . $file)))) : [];
    }
}

/** A terminal that answers `k` and remembers what it was asked to do. */
final class RecordingTerminal implements TerminalInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function start(callable $onInput, callable $onResize): void
    {
        $this->calls[] = 'start';
    }

    public function stop(): void
    {
        $this->calls[] = 'stop';
    }

    public function write(string $data): void
    {
        $this->calls[] = 'write ' . $data;
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
        $this->calls[] = 'moveBy ' . $lines;
    }

    public function hideCursor(): void
    {
        $this->calls[] = 'hideCursor';
    }

    public function showCursor(): void
    {
        $this->calls[] = 'showCursor';
    }

    public function clearLine(): void
    {
        $this->calls[] = 'clearLine';
    }

    public function clearFromCursor(): void
    {
        $this->calls[] = 'clearFromCursor';
    }

    public function clearScreen(): void
    {
        $this->calls[] = 'clearScreen';
    }

    public function setTitle(string $title): void
    {
        $this->calls[] = 'setTitle ' . $title;
    }
}
