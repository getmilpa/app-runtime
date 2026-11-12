<?php

/**
 * This file is part of milpa/app-runtime.
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
 * THE WORDS A PERSON SAID ON THE CONSOLE TO ACCEPT AN UNCONFINED BOOT, FOR THE ONE CALL THAT CONSOLE IS MAKING.
 *
 * Enabling a capability is an operation, and no operation carries the acceptance of an unconfined boot in its input:
 * an input is what an agent writes (greenhouse decisions/0627). A person still has to be able to give it where the
 * machine cannot confine, so the console takes `--accept-unconfined="<the risk>"` out of the call before it becomes
 * input, holds the words here while that one call runs, and forgets them when it returns
 * ({@see \Milpa\AppRuntime\Console\Application}). Nothing else says anything here: not the MCP server, not the HTTP
 * door, not a session of an agent. Whether the words name a risk is the witness's to judge
 * ({@see HouseBootWitness::acceptance()}), and what it accepts it records (greenhouse decisions/0630).
 */
final class AcceptedOnTheConsole
{
    private static ?string $words = null;

    /**
     * Holds the words while `$call` runs and forgets them when it ends, however it ends.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    public static function during(?string $words, callable $call): mixed
    {
        self::$words = $words;

        try {
            return $call();
        } finally {
            self::$words = null;
        }
    }

    /** The words of the call the console is making now; null when it said none, or when no console is making one. */
    public static function words(): ?string
    {
        return self::$words;
    }
}
