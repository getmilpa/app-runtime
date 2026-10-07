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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TrialRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Work runs in the house and can write nothing but its state — under the real sandbox (greenhouse decisions/0588,
 * rule 2).
 *
 * The declaration of an operation is not believed: it is enforced. A work call runs in a child whose root is
 * read-only, with no network and its own pids, and of the house only the paths of its state are writable. So a
 * handler declared `data` that tries to write code, configuration or the session's ledger gets the system's error
 * — not a refusal of policy that a cleverer handler could talk its way around.
 *
 * These tests run the real `bwrap`; where this machine cannot confine a process they are skipped, and the house
 * there asks a person instead ({@see WorkInTheDomainSaysWhereItsStateLivesTest}).
 *
 * @guards the state is written in place, in the house; nothing else of the house can be written; nothing leaves by
 *         the network; a directory of its own confines a SQLite database; the paths are judged again on the real
 *         path when they are mounted
 *
 * @refuses a handler declared data that writes code, configuration, the ledger or a neighbour's store; a link that
 *          appeared between the declaration and the mount
 *
 * @subject-in milpa/app-runtime
 */
final class WorkRunsConfinedToItsStateTest extends TestCase
{
    private string $root;
    private string $scratch;
    private TrialRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new TrialRunner();
        if (! $this->runner->available()) {
            self::markTestSkipped('this machine cannot confine a process: bubblewrap has no namespace to use here');
        }
        $this->root = (string) realpath(sys_get_temp_dir()) . '/milpa-work-confined-' . bin2hex(random_bytes(6));
        foreach (['src/Plugins/Prestamos', 'config', 'var/data', 'var/otra', 'storage/identity'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o755, true);
        }
        file_put_contents($this->root . '/var/herramientas.json', '[]');
        file_put_contents($this->root . '/var/agent-sessions.jsonl', "{}\n");
        file_put_contents($this->root . '/var/socios.json', '[]');
        file_put_contents($this->root . '/config/plugins.php', '<?php return [];');
        $this->scratch = $this->root . '/var/work-scratch';
        mkdir($this->scratch, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testTheStateIsWrittenInPlaceInTheHouse(): void
    {
        $run = $this->work(['nombre' => 'Sierra'], ['var/herramientas.json']);

        self::assertSame(0, $run->exit, $run->stderr . $run->stdout);
        self::assertSame(['ok' => true, 'id' => 1], $run->output);
        self::assertSame([['id' => 1, 'nombre' => 'Sierra', 'prestada' => false]], json_decode((string) file_get_contents($this->root . '/var/herramientas.json'), true));
        self::assertSame(['fs' => 'ro-root+rw-declared-state+rw-scratch', 'net' => 'unshared', 'pid' => 'unshared'], $run->bounds);
    }

    #[DataProvider('notItsState')]
    public function testAHandlerDeclaredDataCanWriteNothingElse(string $path): void
    {
        $before = @file_get_contents($this->root . '/' . $path);

        $run = $this->work(['fixture' => 'write', 'path' => $path], ['var/herramientas.json']);

        self::assertSame(1, $run->exit);
        self::assertFalse($run->output['wrote'] ?? null, $path);
        self::assertMatchesRegularExpression('/Read-only file system|Permission denied/', (string) ($run->output['error'] ?? ''), 'the system\'s own error, not a policy\'s');
        self::assertSame($before, @file_get_contents($this->root . '/' . $path), 'and the house is as it was');
    }

    /** @return iterable<string, array{0: string}> */
    public static function notItsState(): iterable
    {
        yield 'code' => ['src/Plugins/Prestamos/Hook.php'];
        yield 'configuration' => ['config/plugins.php'];
        yield 'the session\'s ledger' => ['var/agent-sessions.jsonl'];
        yield 'a neighbour\'s store' => ['var/socios.json'];
        yield 'a new file beside its store' => ['var/nuevo.json'];
        yield 'the identity' => ['storage/identity/enrollments.json'];
    }

    public function testNothingLeavesByTheNetwork(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        self::assertNotFalse(@stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2.0), 'the control: from here the port answers');

        $run = $this->work(['fixture' => 'connect', 'port' => $port], ['var/herramientas.json']);

        self::assertSame(['ok' => true, 'connected' => false], $run->output);
        fclose($server);
    }

    public function testADirectoryOfItsOwnConfinesADatabaseThatWritesBesideItself(): void
    {
        if (! \extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded here');
        }
        $inItsOwn = $this->work(['fixture' => 'sqlite', 'path' => 'var/data/app.db'], ['var/data']);
        self::assertSame(['ok' => true, 'rows' => 1], $inItsOwn->output, $inItsOwn->stderr);

        $beside = $this->work(['fixture' => 'sqlite', 'path' => 'var/otra/app.db'], ['var/herramientas.json']);
        self::assertFalse($beside->output['ok'] ?? null, 'a database outside its declared state cannot be written');
    }

    public function testTheCallHasAScratchDirectoryAndNothingOfTheHostsTmp(): void
    {
        $run = $this->work(['fixture' => 'tmp'], ['var/herramientas.json']);

        self::assertSame(['ok' => true, 'tmp' => $this->scratch], $run->output);
        self::assertFileExists($this->scratch . '/scratch.txt');
    }

    public function testALinkThatAppearedSinceTheDeclarationIsRefusedAtTheMount(): void
    {
        unlink($this->root . '/var/herramientas.json');
        symlink($this->root . '/config/plugins.php', $this->root . '/var/herramientas.json');

        try {
            $this->work(['fixture' => 'write', 'path' => 'var/herramientas.json'], ['var/herramientas.json']);
            self::fail('the mount was not refused');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('link', $refused->getMessage());
        }
        self::assertSame('<?php return [];', file_get_contents($this->root . '/config/plugins.php'));
    }

    #[DataProvider('notMountable')]
    public function testOnlyAPlaceForStateIsEverMounted(string $path, string $why): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($why);

        $this->work(['fixture' => 'write', 'path' => $path], [$path]);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function notMountable(): iterable
    {
        yield 'configuration' => ['config/plugins.php', 'config/'];
        yield 'code' => ['src/Plugins/Prestamos', 'src/'];
        yield 'the shared directory' => ['var', 'var/'];
        yield 'the ledger' => ['var/agent-sessions.jsonl', 'own files'];
        yield 'a state that does not exist' => ['var/nunca.json', 'does not exist'];
        yield 'out of the house' => ['../afuera.json', 'relative'];
    }

    public function testAScratchThatIsALinkIsNotTaken(): void
    {
        symlink($this->root . '/config', $this->root . '/var/scratch-link');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scratch');

        $this->runner->work($this->root, \dirname(__DIR__) . '/Fixtures/work-runner.php', 'herramientas.agregar', [], ['var/herramientas.json'], $this->root . '/var/scratch-link');
    }

    public function testNoStateNoRun(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->work([], []);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $state
     */
    private function work(array $input, array $state): \Milpa\AppRuntime\Agent\TrialRun
    {
        return $this->runner->work($this->root, \dirname(__DIR__) . '/Fixtures/work-runner.php', 'herramientas.agregar', $input, $state, $this->scratch);
    }
}
