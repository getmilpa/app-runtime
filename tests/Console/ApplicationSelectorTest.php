<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Console\Application;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\FileEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The `/sessions` SELECTOR reads the log once, not once per session.
 *
 * This is the path the CLI-level fix missed. `agent:sessions` (the operation) and the TUI's
 * `/sessions` selector are TWO code paths: the operation goes through
 * {@see \Milpa\AppRuntime\Operations\SessionOperations}, but the selector is populated by
 * {@see Application}'s own `sesionesParaElegir()`. The quadratic replay was fixed in the first and
 * left in the second — a fresh app's TUI still froze on a many-session store while the CLI was
 * instant, and only a run through the actual selector (or the pin's TUI method) exposes it. This
 * test drives the selector's data source directly so the gap cannot reopen unseen.
 */
final class ApplicationSelectorTest extends TestCase
{
    /** The `/sessions` selector's data source reads the log once, not once per session. */
    public function testTheSessionSelectorReadsTheLogOnceNotOncePerSession(): void
    {
        [$app, $tally, $root] = $this->appOverACountingStore();

        (new \ReflectionMethod($app, 'sesionesParaElegir'))->invoke($app);

        $this->assertReadOnce($tally, 'the selector');
        $this->cleanup($root);
    }

    /** `coa chat --continue` (which calls `ultimaSesion()`) reads the log once, not once per session. */
    public function testResumingTheLastSessionReadsTheLogOnceNotOncePerSession(): void
    {
        [$app, $tally, $root] = $this->appOverACountingStore();

        (new \ReflectionMethod($app, 'ultimaSesion'))->invoke($app);

        $this->assertReadOnce($tally, 'resuming the last session');
        $this->cleanup($root);
    }

    /**
     * An Application booted against a fixture app whose SessionStore counts each read to a file.
     *
     * @return array{0: Application, 1: string, 2: string} the app, the tally path, the temp root
     */
    private function appOverACountingStore(): array
    {
        $root = sys_get_temp_dir() . '/milpa-selector-' . bin2hex(random_bytes(5));
        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/var', 0o777, true);
        $log = $root . '/var/agent-sessions.jsonl';
        $tally = $root . '/var/tally.txt';

        $seed = new SessionStore(new FileEventStore($log));
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $seed->start($id, 'goal ' . $id);
        }

        file_put_contents($root . '/config/app.php', "<?php return [];\n");
        // boot.php builds a container whose SessionStore counts each read to a file — the count has to
        // survive the freshly-required container, so it lands on disk, not in an object the test holds.
        file_put_contents($root . '/config/boot.php', sprintf(
            "<?php\n\$c = new \\%s();\n\$c->registerService(\\%s::class, new \\%s(new \\%s(new \\%s(%s), %s)));\nreturn ['container' => \$c, 'plugins' => []];\n",
            DIContainer::class,
            SessionStore::class,
            SessionStore::class,
            CountingEventStore::class,
            FileEventStore::class,
            var_export($log, true),
            var_export($tally, true),
        ));

        return [new Application($root), $tally, $root];
    }

    private function assertReadOnce(string $tally, string $what): void
    {
        $marks = is_file($tally) ? (string) file_get_contents($tally) : '';

        self::assertSame(1, substr_count($marks, "A\n"), "{$what} reads the whole log exactly once (replayAll)");
        self::assertSame(0, substr_count($marks, "R\n"), "{$what} never falls back to one replay() per session");
    }

    private function cleanup(string $root): void
    {
        foreach (glob($root . '/{config,var}/*', \GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($root . '/config');
        @rmdir($root . '/var');
        @rmdir($root);
    }
}

/**
 * @internal An {@see EventStoreInterface} that records each read to a file so a boot.php-built
 * container's calls can be counted from the outside. `A` = a whole-log read (replayAll), `R` = a
 * single-stream read (replay).
 */
final class CountingEventStore implements EventStoreInterface
{
    public function __construct(private EventStoreInterface $inner, private string $tally)
    {
    }

    public function append(Event $event): void
    {
        $this->inner->append($event);
    }

    public function replay(string $streamId): array
    {
        file_put_contents($this->tally, "R\n", \FILE_APPEND);

        return $this->inner->replay($streamId);
    }

    public function nextSeq(): int
    {
        return $this->inner->nextSeq();
    }

    public function streams(): array
    {
        return $this->inner->streams();
    }

    public function replayAll(): array
    {
        file_put_contents($this->tally, "A\n", \FILE_APPEND);

        return $this->inner->replayAll();
    }
}
