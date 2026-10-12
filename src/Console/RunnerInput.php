<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Console;

use Milpa\Command\Operation;
use Milpa\Console\CliRunner;

/**
 * The input the terminal's runner hands an operation for the tokens it was typed with — derived HERE the way the
 * runner derives it, for whoever has to judge the call before the runner makes it.
 *
 * A standing grant lends to the call it names, so the house is told which call a signed process is about to make
 * before its runner asks the gate (greenhouse decisions/0615). For an operation that declares a schema the runner
 * says how it derives — {@see CliRunner::deriveInput()}, where `--sign` controls the surface and is not input. For
 * one that declares NONE it hands the handler every `--flag` it was typed with, `--sign` among them, and the house
 * used to tell its judge that such a call carried nothing: the two did not mean the same call («Lo que la tercera
 * revisión encontró», M2; decided by Rod 2026-10-12).
 *
 * This does not change what the runner does — that is milpa/console's. It makes the house's reading of the call the
 * runner's own, and a test holds the two together.
 */
final class RunnerInput
{
    /**
     * The input a run of this operation is handed for these tokens.
     *
     * @param list<string> $tokens the tokens after the command name, as they were typed
     *
     * @return array<string, mixed>
     *
     * @throws \Milpa\Console\SchemaCoercionException when the tokens do not fit the schema the operation declares
     */
    public static function of(Operation $operation, array $tokens): array
    {
        if ($operation->inputSchema !== null) {
            return (new CliRunner())->deriveInput($operation, $tokens);
        }
        // No schema: every `--key=value` is an argument, a bare `--key` is `"1"`, the last one said wins, and what
        // does not start with `--` is not input.
        $bag = [];
        foreach ($tokens as $token) {
            if (!str_starts_with($token, '--')) {
                continue;
            }
            $body = substr($token, 2);
            [$key, $value] = str_contains($body, '=') ? explode('=', $body, 2) : [$body, '1'];
            $bag[$key] = $value;
        }

        return $bag;
    }
}
