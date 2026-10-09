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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TrialRunner;
use PHPUnit\Framework\TestCase;

/**
 * THE KEYRING THE HOUSE SIGNS WITH IS NOT REACHABLE FROM A CONFINED PROCESS (greenhouse evidence/1178, decided by
 * Rod 2026-10-09).
 *
 * A confined boot or trial runs under `--ro-bind / /` — the whole machine read-only — so the keyring gpg reads and
 * the agent's sockets are visible to it too. Measured in 1178: a plugin's `boot()`, or an operation's handler, could
 * read the private key the Desktop keeps mounted and SIGN a governed act as the person who holds it. The mask — the
 * one place a confinement hides a secret — must hide the keyring and its agent as well, so a confined process finds
 * no key and no agent to speak to.
 */
final class AConfinedProcessCannotReachTheKeyringTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testTheMaskHidesTheKeyringAndItsAgentDirectories(): void
    {
        $home = $this->dir();
        mkdir($home . '/.gnupg', 0o700, true);
        $gnupg = $this->dir();
        mkdir($gnupg . '/keyring', 0o700, true);
        $runtime = $this->dir();
        mkdir($runtime . '/gnupg', 0o700, true);
        $this->setEnv('HOME', $home);
        $this->setEnv('GNUPGHOME', $gnupg);
        $this->setEnv('XDG_RUNTIME_DIR', $runtime);

        $args = (new TrialRunner())->maskArgs($this->dir());

        self::assertMasksDirectory($args, $home . '/.gnupg', 'the default keyring ($HOME/.gnupg) is masked');
        self::assertMasksDirectory($args, $gnupg, 'the keyring GNUPGHOME names is masked');
        self::assertMasksDirectory($args, $runtime . '/gnupg', 'the agent socket directory under XDG_RUNTIME_DIR is masked');
    }

    public function testADirectoryIsMaskedWithATmpfsNotABindOfDevNull(): void
    {
        // `--ro-bind /dev/null <dir>` cannot mount a file over a directory: a keyring is a directory, and it is
        // overlaid with an empty tmpfs. A file secret stays a `--ro-bind /dev/null` (tested elsewhere).
        $home = $this->dir();
        mkdir($home . '/.gnupg', 0o700, true);
        $this->setEnv('HOME', $home);
        $this->setEnv('GNUPGHOME', false);

        $args = (new TrialRunner())->maskArgs($this->dir());

        $i = array_search($home . '/.gnupg', $args, true);
        self::assertNotFalse($i, 'the keyring is in the mask');
        self::assertSame('--tmpfs', $args[$i - 1] ?? null, 'a keyring is masked by an empty tmpfs, not --ro-bind /dev/null');
    }

    public function testNoKeyringParentDirectoryIsCreatedForAMountThatWouldFail(): void
    {
        // A tmpfs target whose parent does not exist makes bwrap fail — and a failing maskArgs would break EVERY
        // trial. GNUPGHOME pointing at a path with no parent is left out of the mask rather than risking that.
        $this->setEnv('HOME', false);
        $this->setEnv('GNUPGHOME', '/this/parent/does/not/exist/keyring');

        $args = (new TrialRunner())->maskArgs($this->dir());

        self::assertNotContains('/this/parent/does/not/exist/keyring', $args, 'a mask target whose parent is absent is left out');
    }

    /** @param list<string> $args */
    private static function assertMasksDirectory(array $args, string $dir, string $message): void
    {
        for ($i = 0; $i < \count($args) - 1; $i++) {
            if ($args[$i] === '--tmpfs' && $args[$i + 1] === $dir) {
                self::assertTrue(true, $message);

                return;
            }
        }
        self::fail($message . ' (no «--tmpfs ' . $dir . '» in the mask: ' . implode(' ', $args) . ')');
    }

    private function setEnv(string $name, string|false $value): void
    {
        if (!\array_key_exists($name, $this->env)) {
            $this->env[$name] = getenv($name);
        }
        if ($value === false) {
            putenv($name);
        } else {
            putenv("{$name}={$value}");
        }
    }

    private function dir(): string
    {
        $dir = sys_get_temp_dir() . '/milpa-keyring-mask-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700, true);
        $this->roots[] = $dir;

        return $dir;
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
