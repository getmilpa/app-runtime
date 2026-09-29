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
 * The runner milpa/devtools' `repair` and `update` are handed: every `composer …` it is asked for goes through
 * {@see HouseBootWitness::composeIfItBoots()}; anything else runs in the house as before.
 *
 * `Doctor\Repair` and `Doctor\Update` live in milpa/devtools, which cannot know the witness (it lives here), and
 * both already take a runner — the seam their tests use. So the rule reaches them through that seam
 * (greenhouse decisions/0527): a refusal comes back as a failed command whose output is the witness's
 * sentence, and what the witness said (`unwritten`, `house_boots`, `rolled_back`…) is read from {@see said()}
 * and joined to their answer.
 */
final class StagedComposerRunner
{
    /** @var array<string, mixed> */
    private array $said = [];

    /** @var null|callable(string, string): array{0: int, 1: list<string>} */
    private $run;

    /**
     * @param bool                                                          $recovery whether this writer is a way back (0506): `repair` installs what a broken house misses
     * @param null|callable(string, string): array{0: int, 1: list<string>} $run      how Composer runs in a directory — a test's stand-in; null is the real one
     */
    public function __construct(
        private readonly string $root,
        private readonly HouseBootWitness $witness,
        private readonly bool $recovery = false,
        ?callable $run = null,
    ) {
        $this->run = $run;
    }

    /**
     * Run `$command`: a Composer command that writes through the witness, any other (a dry run) in the house's root.
     *
     * @return array{0: int, 1: list<string>}
     */
    public function __invoke(string $command): array
    {
        // A dry run writes nothing, and a command that is not Composer's is not this door's.
        if (!str_starts_with($command, 'composer ') || preg_match('/\s--dry-run\b/', $command) === 1) {
            $out = [];
            $code = 1;
            exec('cd ' . escapeshellarg($this->root) . ' && ' . $command . ' 2>&1', $out, $code);

            return [$code, $out];
        }
        $composed = $this->witness->composeIfItBoots(
            (string) preg_replace('/\s+--no-interaction\b/', '', $command),
            $this->run,
            $this->recovery,
        );
        $this->said = $composed['said'];
        if ($composed['refused'] !== null) {
            return [1, [$composed['refused']]];
        }

        return [$composed['code'], $composed['output']];
    }

    /**
     * What the witness said about the last Composer command — empty before one ran.
     *
     * @return array<string, mixed>
     */
    public function said(): array
    {
        return $this->said;
    }
}
