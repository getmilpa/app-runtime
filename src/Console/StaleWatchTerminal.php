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

use Milpa\AppRuntime\Support\KernelDefinition;
use Milpa\Live\Contracts\Tui\TerminalInterface;

/**
 * A terminal that closes the screen on it when the kernel behind the screen went stale (greenhouse decisions/0507).
 *
 * The loop of a screen asks its terminal for input on every tick; that is the one place a screen that knows nothing
 * of kernels passes by ten times a second. Every {@see self::EVERY} seconds this asks {@see KernelDefinition}
 * whether what the kernel read is still the same, and when it is not it hands the loop the key that closes it. The
 * host reads {@see staleBecause()} after the loop and leaves for a clean process.
 */
final class StaleWatchTerminal implements TerminalInterface
{
    /** Seconds between two questions — the check costs ~0.1 ms (greenhouse evidence/1038, c1). */
    public const EVERY = 0.5;

    private float $asked;

    private ?string $stale = null;

    /** Wraps the real terminal and the definition of the kernel the screen was built from; asks every `$every` seconds. */
    public function __construct(
        private readonly TerminalInterface $terminal,
        private readonly KernelDefinition $definition,
        private readonly float $every = self::EVERY,
    ) {
        $this->asked = microtime(true);
    }

    /** The input that made the kernel stale, relative to the app root — or null while it is current. */
    public function staleBecause(): ?string
    {
        return $this->stale;
    }

    /** Starts the real terminal. */
    public function start(callable $onInput, callable $onResize): void
    {
        $this->terminal->start($onInput, $onResize);
    }

    /** Restores the real terminal. */
    public function stop(): void
    {
        $this->terminal->stop();
    }

    /** Writes to the real terminal. */
    public function write(string $data): void
    {
        $this->terminal->write($data);
    }

    /** What the person typed — or, once the kernel went stale, the key that closes the screen. */
    public function pollInput(): string
    {
        if ($this->stale !== null) {
            return "\x03";
        }
        $now = microtime(true);
        if ($now - $this->asked >= $this->every) {
            $this->asked = $now;
            $this->stale = $this->definition->staleBecause();
            if ($this->stale !== null) {
                return "\x03";
            }
        }

        return $this->terminal->pollInput();
    }

    /** Whether the real terminal's input ended. */
    public function atEndOfInput(): bool
    {
        return $this->terminal->atEndOfInput();
    }

    /** The real terminal's width. */
    public function columns(): int
    {
        return $this->terminal->columns();
    }

    /** The real terminal's height. */
    public function rows(): int
    {
        return $this->terminal->rows();
    }

    /** Moves the cursor on the real terminal. */
    public function moveBy(int $lines): void
    {
        $this->terminal->moveBy($lines);
    }

    /** Hides the cursor on the real terminal. */
    public function hideCursor(): void
    {
        $this->terminal->hideCursor();
    }

    /** Shows the cursor on the real terminal. */
    public function showCursor(): void
    {
        $this->terminal->showCursor();
    }

    /** Clears the line on the real terminal. */
    public function clearLine(): void
    {
        $this->terminal->clearLine();
    }

    /** Clears from the cursor on the real terminal. */
    public function clearFromCursor(): void
    {
        $this->terminal->clearFromCursor();
    }

    /** Clears the real terminal. */
    public function clearScreen(): void
    {
        $this->terminal->clearScreen();
    }

    /** Sets the real terminal's title. */
    public function setTitle(string $title): void
    {
        $this->terminal->setTitle($title);
    }
}
