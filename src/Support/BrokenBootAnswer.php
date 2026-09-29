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

namespace Milpa\AppRuntime\Support;

/**
 * A front controller whose boot fails answers `503` with the reason — never `200` with the fatal in the body.
 *
 * ── THE DEFECT, MEASURED (greenhouse evidence/1035 F1, evidence/1039; decisions/0512) ───────────
 *
 * `php -S` and FrankenPHP classic run `public/index.php` once per request. When the house did not boot —
 * a plugin's `boot()` that threw, a class missing an interface method — they answered **HTTP 200** with
 * `Fatal error: … in /home/…/src/Plugins/…` in the body: a broken house that says it is fine, with its
 * paths in the page. The worker already answered `503` and the reason (decisions/0506); the classic door
 * did not, because its boot runs before `ExceptionMiddleware` exists and a compile fatal is caught by
 * nobody.
 *
 * ── HOW ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * {@see watch()} is called right after the autoloader and {@see booted()} right after the kernel is in
 * its container. Between the two, errors are not DISPLAYED (so none reaches the client and none sends the
 * headers) and output is held. If the process ends between the two with a fatal, a shutdown function
 * drops what was held and answers what the worker answers: `503`, `Retry-After`, `no-store`,
 * `Milpa-House-Does-Not-Boot: <why>` and {@see KernelDefinition::doesNotBootText()}. The reason is the
 * one {@see BootProbe} gives — the house's root stripped, any other absolute path cut to its file name.
 * PHP's own log still gets the whole fatal, as before. After {@see booted()} nothing here acts: a fatal
 * while SERVING is the 500 of `ExceptionMiddleware`, not a house that does not boot.
 */
final class BrokenBootAnswer
{
    private const FATAL = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR | \E_RECOVERABLE_ERROR;

    private bool $booted = false;

    private function __construct(
        private readonly string $root,
        private readonly string|false $display,
        private readonly int $level,
    ) {
    }

    /** Start watching a boot: errors stop being displayed and output is held until {@see booted()}. */
    public static function watch(string $root): self
    {
        $display = ini_set('display_errors', '0');
        ob_start();
        $watch = new self($root, $display, ob_get_level());
        register_shutdown_function($watch->shutdown(...));

        return $watch;
    }

    /** The boot finished: errors display as the deployment configured, and what was held goes out. */
    public function booted(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        if ($this->display !== false) {
            ini_set('display_errors', $this->display);
        }
        while (ob_get_level() >= $this->level && ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    /**
     * What a boot that ended with this error answers — null when there is nothing to answer (it booted, or no fatal).
     *
     * @param array{type: int, message: string, file: string, line: int}|null $error what `error_get_last()` returned
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public function answerFor(?array $error): ?array
    {
        if ($this->booted || $error === null || ($error['type'] & self::FATAL) === 0) {
            return null;
        }
        $why = self::reason($error, $this->root);

        return [
            'status' => 503,
            'headers' => [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'Retry-After' => (string) KernelDefinition::RECHECK_SECONDS,
                'Milpa-House-Does-Not-Boot' => (string) preg_replace('/[^\x20-\x7E]/', '?', $why),
            ],
            'body' => KernelDefinition::doesNotBootText($why),
        ];
    }

    /**
     * One line from a fatal, said as {@see BootProbe} says it: `Class: message` for a throwable nobody caught,
     * `Fatal error: message` for the rest — without the stack trace, the root, or any other absolute path.
     *
     * @param array{type: int, message: string, file: string, line: int} $error
     */
    public static function reason(array $error, string $root): string
    {
        $message = $error['message'];
        if (str_starts_with($message, 'Uncaught ')) {
            // «Uncaught X: message in /file:line\nStack trace:…» — the first part is the throwable, as BootProbe reads it.
            $message = substr((string) strtok($message, "\n"), \strlen('Uncaught '));
            $message = (string) preg_replace('/ in \S+:\d+$/', '', $message);
        } else {
            $message = ($error['type'] === \E_PARSE ? 'Parse error: ' : 'Fatal error: ') . $message
                . ' in ' . $error['file'] . ' on line ' . $error['line'];
        }

        return BootProbe::oneLine($message, $root);
    }

    /**
     * Answer a boot that ended with `$error` — what the shutdown function does with `error_get_last()`.
     *
     * True when the `503` was written; false when there was nothing to answer or the headers had already gone.
     *
     * @param array{type: int, message: string, file: string, line: int}|null $error
     */
    public function answer(?array $error): bool
    {
        $answer = $this->answerFor($error);
        if ($answer === null) {
            return false;
        }
        while (ob_get_level() >= $this->level && ob_get_level() > 0) {
            ob_end_clean();
        }
        if (headers_sent()) {
            return false; // something went out before the watch began; nothing honest can be added now
        }
        // A STATUS LINE, not `http_response_code()`: on a fatal with errors hidden, PHP has already set its own
        // «500 Internal Server Error» line, and that line wins over a bare code (measured on `php -S`).
        $protocol = \is_string($_SERVER['SERVER_PROTOCOL'] ?? null) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
        header($protocol . ' 503 Service Unavailable', true, $answer['status']);
        foreach ($answer['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $answer['body'];

        return true;
    }

    private function shutdown(): void
    {
        $this->answer(error_get_last());
    }
}
