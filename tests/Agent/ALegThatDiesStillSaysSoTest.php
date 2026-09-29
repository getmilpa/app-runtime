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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\FatalTermination;
use Milpa\EventStore\FileEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A leg that dies still says so (greenhouse decisions/0509 §6).
 *
 * Measured (evidence/1036): four legs ran out of 128 MB and exited 255 with no `session.run_terminated` — the
 * run writes it in a `finally`, and a fatal error never reaches one.
 *
 * @guards an armed run that dies of a fatal error records `failed` with the fatal named, in a real process that
 *         exhausts its memory
 *
 * @refuses recording for a warning, for a disarmed run, or twice
 *
 * @subject-in milpa/app-runtime
 */
final class ALegThatDiesStillSaysSoTest extends TestCase
{
    protected function tearDown(): void
    {
        FatalTermination::disarm();
    }

    public function testAnArmedRunThatDiesOfAFatalRecordsItsTermination(): void
    {
        $recorded = [];
        FatalTermination::arm(static function (array $t) use (&$recorded): void {
            $recorded[] = $t;
        });

        self::assertTrue(FatalTermination::recordIfFatal(['type' => \E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted', 'file' => 'FileEventStore.php', 'line' => 165]));

        self::assertSame([[
            'reason' => 'failed',
            'receipt' => null,
            'fatal' => ['message' => 'Allowed memory size of 134217728 bytes exhausted', 'file' => 'FileEventStore.php', 'line' => 165],
        ]], $recorded);
        self::assertFalse(FatalTermination::recordIfFatal(['type' => \E_ERROR, 'message' => 'again', 'file' => 'x', 'line' => 1]), 'once');
    }

    public function testAWarningIsNotADeath(): void
    {
        $recorded = [];
        FatalTermination::arm(static function (array $t) use (&$recorded): void {
            $recorded[] = $t;
        });

        self::assertFalse(FatalTermination::recordIfFatal(['type' => \E_WARNING, 'message' => 'w', 'file' => 'x', 'line' => 1]));
        self::assertFalse(FatalTermination::recordIfFatal(null));
        self::assertSame([], $recorded);
    }

    public function testADisarmedRunRecordsNothing(): void
    {
        $recorded = [];
        FatalTermination::arm(static function (array $t) use (&$recorded): void {
            $recorded[] = $t;
        });
        FatalTermination::disarm();

        self::assertFalse(FatalTermination::recordIfFatal(['type' => \E_ERROR, 'message' => 'm', 'file' => 'x', 'line' => 1]));
        self::assertSame([], $recorded);
    }

    public function testAProcessThatRunsOutOfMemoryLeavesItsTerminationInTheLedger(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-fatal-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $ledger = $dir . '/agent-sessions.jsonl';
        $store = new SessionStore(new FileEventStore($ledger));
        $store->start('s', 'a leg that dies');
        // A ledger big enough that numbering one more event needs more room than a process dead at its limit
        // has left (1036: ~26 MB to append to a 21 MB ledger) — the headroom the handler raises is what speaks.
        $rows = '';
        for ($i = 2; $i < 4500; ++$i) {
            $rows .= json_encode(['stream_id' => 'agent-session:other', 'type' => 'session.tool_called',
                'payload' => ['tool' => 'source_read', 'result' => str_repeat('r', 4000)], 'seq' => $i]) . "\n";
        }
        file_put_contents($ledger, $rows, \FILE_APPEND);
        $script = $dir . '/leg.php';
        file_put_contents($script, '<?php require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$store = new Milpa\Agent\SessionStore(new Milpa\EventStore\FileEventStore(' . var_export($ledger, true) . '));'
            . 'Milpa\AppRuntime\Agent\FatalTermination::arm(static function (array $t) use ($store): void { $store->recordRunTermination("s", $t); });'
            // 1036 died INSIDE a read of the ledger, under its shared lock: the bailout never unlocks it.
            . '$h = fopen(' . var_export($ledger, true) . ', "r"); flock($h, LOCK_SH);'
            . '$hog = []; while (true) { $hog[] = str_repeat("x", 1 << 20); }');

        // `timeout`: a shutdown that waits on its own lock never ends — the defect is a hang, not a failure.
        exec('timeout 30 ' . escapeshellarg(\PHP_BINARY) . ' -d memory_limit=32M ' . escapeshellarg($script) . ' 2>&1', $output, $exit);

        $terminations = array_values(array_filter(
            (new FileEventStore($ledger))->replay(SessionStore::PREFIX . 's'),
            static fn ($e): bool => $e->type === 'session.run_terminated',
        ));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);

        self::assertSame(255, $exit, implode("\n", $output));
        self::assertCount(1, $terminations, 'the dying leg left exactly one termination');
        self::assertSame('failed', $terminations[0]->payload['reason']);
        self::assertStringContainsString('Allowed memory size', $terminations[0]->payload['fatal']['message']);
    }
}
