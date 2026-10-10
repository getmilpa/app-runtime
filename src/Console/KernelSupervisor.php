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

namespace Milpa\AppRuntime\Console;

/**
 * Holds what a long-lived client sees — an MCP pipe, a terminal — while a CHILD holds the kernel, and starts a clean
 * child whenever the one serving went stale.
 *
 * ── THE DEFECT, MEASURED (greenhouse evidence/1040, p0) ─────────────────────────────────────────
 *
 * `bin/mcp-server.php` and `coa panel` boot the kernel once and serve with it for as long as the agent or the person
 * stays. On fresh cattle, a config written, a trial promoted — even by the server's OWN tool call —, a plugin
 * disabled and a package installed after the start were all invisible until someone restarted the process; the
 * disabled plugin kept answering its tools.
 *
 * ── WHY A SUPERVISOR, AND WHY IT HAS NO KERNEL (greenhouse decisions/0507) ──────────────────────
 *
 * decisions/0505 answered this for a FrankenPHP worker: the stale process does not serve, it leaves, and the server
 * starts a clean one. Here nobody would: an MCP client over stdio does not relaunch a server that died — the agent
 * loses every tool of the house mid-session —, and `pcntl_exec` is in none of the house's images. So the process
 * the client talks to is split in two. This class holds the pipe or the terminal and never boots a kernel, so it can
 * never go stale; the child boots one, and exits with {@see STALE} when {@see \Milpa\AppRuntime\Support\KernelDefinition}
 * says what it read has changed. Re-booting in the child's own process is not an option for the reasons 0505 gave:
 * PHP never redefines a loaded class, and the autoloader in memory holds the maps from before the install.
 *
 * ── WHAT THE MCP CLIENT SEES ────────────────────────────────────────────────────────────────────
 *
 * A child that finds itself stale when a request ARRIVES does not run it; this relay hands the same line to the
 * next child, so the request runs exactly once and the answer only takes one boot longer. A child that made itself
 * stale answers first and then leaves. When the new child lists different tools than the client last saw, the relay
 * says so once with `notifications/tools/list_changed` (and declares `tools.listChanged` in `initialize`). A child
 * that cannot boot — a promotion that broke the house — answers every pending request with an error that says so,
 * and the pipe stays open: the next request tries again, never a loop.
 */
final class KernelSupervisor
{
    /** The exit code of a child that left because its kernel went stale — `EX_TEMPFAIL`: try again. */
    public const STALE = 75;

    /** The id prefix of the requests this relay sends on its own; their answers never reach the client. */
    public const OWN = 'milpa-supervisor:';

    /**
     * Stale exits in a row, with nothing answered in between, before the relay stops starting children.
     *
     * A kernel whose boot rewrote one of its own inputs would go stale on every boot; without a ceiling that is a
     * loop that looks like a server.
     */
    public const STALE_IN_A_ROW = 5;

    /**
     * What a terminal child writes on the second line of its handoff when its kernel had been found current at least once.
     *
     * Only children that never saw a settled house count toward {@see STALE_IN_A_ROW}: five changes a person makes in
     * ten seconds are five healthy children, not a loop (greenhouse evidence/1053 — the time window of 0507 closed the
     * chat, the shell and the panel on them).
     */
    public const SETTLED = 'settled';

    /** Where a terminal child finds what the stale one before it handed over (greenhouse decisions/0519). */
    public const HANDOFF = 'MILPA_TERMINAL_HANDOFF';

    /** @var \Closure(): list<string> */
    private readonly \Closure $child;

    /** @var null|resource */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $fromClient = '';

    private string $fromChild = '';

    private string $childErrors = '';

    /** @var array<string, array{line: string, method: string}> id (JSON-encoded) → the request, in arrival order */
    private array $pending = [];

    /** A hash of the last tool list the client received — null until it asked for one. */
    private ?string $clientList = null;

    private int $sequence = 0;

    private int $starts = 0;

    private int $staleInARow = 0;

    private bool $answeredSinceStart = false;

    /**
     * @param callable(): list<string> $child  the command that starts a child (`php bin/coa mcp --child`)
     * @param resource                 $in     what the client writes
     * @param resource                 $out    what the client reads
     * @param resource                 $errors where the children's STDERR goes on
     * @param float                    $idle   seconds of silence before the relay asks the child whether it is current
     */
    public function __construct(
        callable $child,
        private readonly string $cwd,
        private $in,
        private $out,
        private $errors,
        private readonly float $idle = 2.0,
    ) {
        $this->child = \Closure::fromCallable($child);
    }

    /** How many children this relay started — the first one included. */
    public function starts(): int
    {
        return $this->starts;
    }

    /**
     * Relay JSON-RPC lines between the client and a child until the client closes its end — the exit code of the relay.
     */
    public function relay(): int
    {
        $this->start();
        $clientOpen = true;

        // Until the client closes its end: it has finished ASKING. {@see stop()} lets the child finish what it was
        // asked and leave, and hands the client what the child answered meanwhile — a script or a CI that pipes its
        // messages in (`printf … | coa mcp`) closes its end at once and is still reading. The relay waits for
        // nothing more than it always did: the child's leaving.
        while ($clientOpen) {
            $read = [$this->in];
            if ($this->process !== null) {
                $read[] = $this->pipes[1];
                $read[] = $this->pipes[2];
            }
            $write = $except = null;
            $quiet = $this->pending === [] && $this->process !== null;
            $ready = @stream_select($read, $write, $except, $quiet ? (int) $this->idle : 1, $quiet ? (int) (($this->idle - (int) $this->idle) * 1_000_000) : 0);
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                if ($quiet) {
                    // Nobody asked for anything: ask the child whether it is still current, so a change made by
                    // another process reaches the client without waiting for its next call. MCP defines `ping`.
                    $this->toChild(json_encode(['jsonrpc' => '2.0', 'id' => self::OWN . 'ping:' . ++$this->sequence, 'method' => 'ping'], \JSON_UNESCAPED_SLASHES));
                }
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $this->in) {
                    $chunk = (string) fread($this->in, 65536);
                    if ($chunk === '' && feof($this->in)) {
                        // A last line that ends without a newline was asked all the same.
                        if (trim($this->fromClient) !== '') {
                            $this->fromClient(rtrim($this->fromClient, "\r"));
                        }
                        $this->fromClient = '';
                        $clientOpen = false;
                        continue;
                    }
                    $this->fromClient .= $chunk;
                    while (($nl = strpos($this->fromClient, "\n")) !== false) {
                        $line = rtrim(substr($this->fromClient, 0, $nl), "\r");
                        $this->fromClient = substr($this->fromClient, $nl + 1);
                        if (trim($line) !== '') {
                            $this->fromClient($line);
                        }
                    }
                } elseif ($this->process !== null && $stream === $this->pipes[2]) {
                    $this->passErrors((string) fread($stream, 65536));
                } elseif ($this->process !== null && $stream === $this->pipes[1]) {
                    $chunk = (string) fread($stream, 65536);
                    if ($chunk === '' && feof($stream)) {
                        $this->childLeft();
                        continue;
                    }
                    $this->fromChild .= $chunk;
                    $this->childLines();
                }
            }
        }

        $this->stop();

        return 0;
    }

    /**
     * Run a terminal child until it leaves for a reason other than a stale kernel — its exit code.
     *
     * The child inherits the terminal itself (no relay: a screen is keystrokes and paint, not messages) plus a pipe
     * on descriptor 3 where a stale child writes what it was showing, so the next one opens there. What it wrote
     * also reaches the next child in its environment ({@see HANDOFF}) and not only in the argv `$child` builds: a
     * chat hands over the text the person was typing, and an argv is readable by every user of the machine
     * (greenhouse decisions/0519).
     *
     * @param callable(?string): list<string> $child   the command that starts a child, given what the last one showed
     * @param string                          $surface what the person calls this screen — `panel`, `chat`, `shell` — in what it is told
     * @param null|callable(string): ?string  $held    what the person would lose if the next child never boots, read from the
     *                                                 last handoff — said once, with the reason, instead of vanishing
     * @param resource|null                   $errors  where the supervisor's own lines go — the terminal's STDERR unless a test listens
     */
    public static function terminal(callable $child, string $cwd, ?string $showing = null, string $surface = 'panel', ?callable $held = null, $errors = null): int
    {
        $errors ??= \STDERR;
        // The terminal as the person handed it over, before any child made it raw. A child restores it on its way
        // out — a key, a signal it can catch, a fatal error —; a child killed outright (SIGKILL, or a signal where
        // there is no pcntl, as in the house's images) cannot, and would leave the person without echo. The
        // supervisor outlives every child, so it puts the terminal back after each one (greenhouse decisions/0524).
        $asHanded = self::terminalSettings();
        try {
            $inARow = 0;
            $handoff = null;
            while (true) {
                // In THIS process's environment, which the child inherits whole. Handing proc_open an array instead
                // rebuilds it, and PHP drops every variable whose value is empty — measured: the child lost one the
                // person's shell had set (evidence/1053). The child must see the environment the person started with.
                putenv($handoff !== null ? self::HANDOFF . '=' . $handoff : self::HANDOFF);
                $process = proc_open($child($showing), [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR, 3 => ['pipe', 'w']], $pipes, $cwd);
                if (!\is_resource($process)) {
                    fwrite($errors, "✗ the {$surface} could not start a process of the house\n");

                    return 1;
                }
                [$written, $settled] = explode("\n", (string) stream_get_contents($pipes[3]), 2) + [1 => ''];
                $written = trim($written);
                fclose($pipes[3]);
                [$code, $signaled] = self::exitOf($process);
                self::restoreTerminal($asHanded);
                if ($code !== self::STALE) {
                    // A child ended by a signal was stopped by someone; the house did not fail to start.
                    if ($code !== 0 && $code !== 130 && !$signaled) {
                        fwrite($errors, "✗ the house did not start (exit {$code}); the {$surface} closed. What it said is above; undo what changed and open it again.\n");
                        $lost = $handoff !== null && $held !== null ? $held($handoff) : null;
                        if ($lost !== null && $lost !== '') {
                            fwrite($errors, $lost . "\n");
                        }
                    }

                    return $code;
                }
                if ($written !== '') {
                    $handoff = $written;
                    $showing = $written;
                }
                // The same ceiling as the relay, with the same measure: stale exits of children that never served. A child
                // whose kernel was current at least once served the person — however soon the next change came.
                $inARow = trim($settled) === self::SETTLED ? 0 : $inARow + 1;
                if ($inARow >= self::STALE_IN_A_ROW) {
                    fwrite($errors, '✗ the house changed ' . self::STALE_IN_A_ROW . " times in a row while the {$surface} was starting; it stopped. Open it again when it settles.\n");

                    return 1;
                }
            }
        } finally {
            // The supervisor's own environment carries nothing past the children it handed it to.
            putenv(self::HANDOFF);
        }
    }

    /**
     * How a terminal child ended: its exit code, or 128 + the signal that ended it — the shell's convention, so a
     * child stopped by SIGTERM does not read as one that exited 15.
     *
     * @param resource $process
     *
     * @return array{0: int, 1: bool} the code, and whether a signal ended it
     */
    private static function exitOf($process): array
    {
        do {
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(2000);
            }
        } while ($status['running']);
        proc_close($process);

        return $status['signaled'] ? [128 + $status['termsig'], true] : [$status['exitcode'], false];
    }

    /** The `stty -g` of this process's terminal — null when its input is no terminal, so there is nothing to restore. */
    private static function terminalSettings(): ?string
    {
        if (!\function_exists('stream_isatty') || !@stream_isatty(\STDIN) || !\function_exists('shell_exec')) {
            return null;
        }
        $settings = trim((string) shell_exec('stty -g 2>/dev/null'));

        return $settings !== '' ? $settings : null;
    }

    /** Puts the terminal back as it was handed over; a child that already restored it changes nothing. */
    private static function restoreTerminal(?string $settings): void
    {
        if ($settings !== null) {
            shell_exec('stty ' . escapeshellarg($settings) . ' 2>/dev/null');
        }
    }

    /**
     * What the last child of {@see terminal()} handed to this one — null when this is the first, or not a terminal child.
     *
     * Read once: the variable is removed from this process, so a tool the child runs does not inherit it.
     */
    public static function handedOver(): ?string
    {
        $handoff = getenv(self::HANDOFF);
        putenv(self::HANDOFF);

        return \is_string($handoff) && $handoff !== '' ? $handoff : null;
    }

    /** A line from the client: remember every request until its answer comes back, then hand it to the child. */
    private function fromClient(string $line): void
    {
        $message = json_decode($line, true);
        if (\is_array($message) && \array_key_exists('id', $message) && \is_string($message['method'] ?? null)) {
            $this->pending[(string) json_encode($message['id'])] = ['line' => $line, 'method' => $message['method']];
        }
        if ($this->process === null) {
            // The last child could not boot. Try again now — once per request the client sends, never on a timer.
            $this->start();
            $this->resend();

            return;
        }
        $this->toChild($line);
    }

    /** Every complete line the child wrote: the client's answers go on, the relay's own are read here. */
    private function childLines(): void
    {
        while (($nl = strpos($this->fromChild, "\n")) !== false) {
            $line = substr($this->fromChild, 0, $nl);
            $this->fromChild = substr($this->fromChild, $nl + 1);
            $message = json_decode($line, true);
            if (!\is_array($message)) {
                // Not a message: an `echo` somewhere in the house. It would corrupt the client's wire; it is a
                // line for a person, so it goes where the person reads.
                $this->passErrors($line . "\n");
                continue;
            }
            $id = \array_key_exists('id', $message) ? $message['id'] : null;
            if ($id !== null) {
                // Any answer — the client's or the relay's own — proves this child booted and served.
                $this->answeredSinceStart = true;
            }

            if (\is_string($id) && str_starts_with($id, self::OWN)) {
                if (str_starts_with($id, self::OWN . 'list:') && \is_array($message['result']['tools'] ?? null)) {
                    $now = self::listHash($message['result']['tools']);
                    if ($this->clientList !== null && $now !== $this->clientList) {
                        $this->clientList = $now;
                        $this->toClient((string) json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']));
                    }
                }
                continue;
            }

            $key = $id !== null ? (string) json_encode($id) : null;
            $request = $key !== null ? ($this->pending[$key] ?? null) : null;
            if ($request !== null) {
                unset($this->pending[$key]);
                if ($request['method'] === 'initialize' && \is_array($message['result'] ?? null)) {
                    // This relay is what can tell the client its tools moved, so this relay declares it.
                    $tools = $message['result']['capabilities']['tools'] ?? [];
                    $message['result']['capabilities']['tools'] = ['listChanged' => true] + (\is_array($tools) ? $tools : []);
                    $line = (string) json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                }
                if ($request['method'] === 'tools/list' && \is_array($message['result']['tools'] ?? null)) {
                    $this->clientList = self::listHash($message['result']['tools']);
                }
            }
            $this->toClient($line);
        }
    }

    /** The child closed its output: read why, and start the next one or tell the client. */
    private function childLeft(): void
    {
        $this->passErrors((string) stream_get_contents($this->pipes[2]));
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $code = $this->process !== null ? proc_close($this->process) : -1;
        $this->process = null;
        $this->pipes = [];
        $this->fromChild = '';
        $booted = $this->answeredSinceStart;

        if ($code === self::STALE) {
            $this->staleInARow = $booted ? 1 : $this->staleInARow + 1;
            if ($this->staleInARow < self::STALE_IN_A_ROW) {
                $this->start();
                $this->resend();
                if ($this->clientList !== null) {
                    $this->toChild((string) json_encode(['jsonrpc' => '2.0', 'id' => self::OWN . 'list:' . ++$this->sequence, 'method' => 'tools/list']));
                }

                return;
            }
            $this->refuse('the house changed ' . self::STALE_IN_A_ROW . ' times in a row while the server was starting, so it stopped starting it. Call again when the house settles.');
            $this->staleInARow = 0;

            return;
        }

        $last = rtrim(self::lastLine($this->childErrors), '.');
        $this->refuse($booted
            ? "the server process of the house ended while serving (exit {$code})" . ($last !== '' ? ": {$last}" : '') . '. A call that was running may or may not have taken effect; the next call starts a new process.'
            : "the house did not start (exit {$code})" . ($last !== '' ? ": {$last}" : '') . '. Nothing ran. Undo what changed — a promotion keeps its pre-image — and call again; the next call starts a new process.');
    }

    /** Answer every pending request with an error that says what happened — the pipe stays open. */
    private function refuse(string $why): void
    {
        foreach ($this->pending as $key => $request) {
            $this->toClient((string) json_encode([
                'jsonrpc' => '2.0',
                // (string): a numeric id's key became an int in this array — PHP casts numeric string keys.
                'id' => json_decode((string) $key, true),
                'error' => ['code' => -32603, 'message' => $why],
            ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }
        $this->pending = [];
    }

    /** Hand every request still unanswered to the child, in the order the client sent them. */
    private function resend(): void
    {
        foreach ($this->pending as $request) {
            $this->toChild($request['line']);
        }
    }

    private function start(): void
    {
        $process = proc_open(($this->child)(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->cwd);
        ++$this->starts;
        $this->answeredSinceStart = false;
        $this->childErrors = '';
        if (!\is_resource($process)) {
            $this->process = null;
            $this->refuse('the relay could not start a process of the house.');

            return;
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
    }

    private function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        fclose($this->pipes[0]);
        stream_set_blocking($this->pipes[1], true);
        // What the child answers while it finishes belongs to whoever asked: it goes to the client through the same
        // door as every other line (the relay's own answers stay here). It used to be read and thrown away, so a
        // client that closed its end right after asking got nothing, and exit 0.
        $this->fromChild .= (string) stream_get_contents($this->pipes[1]);
        $this->childLines();
        stream_set_blocking($this->pipes[2], true);
        $this->passErrors((string) stream_get_contents($this->pipes[2]));
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->process);
        $this->process = null;
    }

    private function toChild(string $line): void
    {
        if ($this->process !== null) {
            // A child that already left makes this write fail; its exit is read next, and the request is resent.
            @fwrite($this->pipes[0], $line . "\n");
        }
    }

    private function toClient(string $line): void
    {
        // A client that really left cannot be written to; that is not an error of this relay.
        if (@fwrite($this->out, $line . "\n") !== false) {
            @fflush($this->out);
        }
    }

    private function passErrors(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }
        fwrite($this->errors, $chunk);
        $this->childErrors = substr($this->childErrors . $chunk, -4096);
    }

    private static function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => $l !== ''));

        return $lines === [] ? '' : $lines[\count($lines) - 1];
    }

    /** @param array<mixed> $tools */
    private static function listHash(array $tools): string
    {
        return hash('xxh128', (string) json_encode($tools));
    }
}
