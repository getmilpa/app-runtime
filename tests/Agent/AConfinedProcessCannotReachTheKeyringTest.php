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

        self::assertMasksDirectory($args, (string) realpath($home . '/.gnupg'), 'the default keyring ($HOME/.gnupg) is masked');
        self::assertMasksDirectory($args, (string) realpath($gnupg), 'the keyring GNUPGHOME names is masked');
        self::assertMasksDirectory($args, (string) realpath($runtime . '/gnupg'), 'the agent socket directory under XDG_RUNTIME_DIR is masked');
    }

    public function testASymlinkedKeyringIsMaskedByItsRealPathSoBwrapNeitherFailsNorLeavesItReadable(): void
    {
        // `$HOME/.gnupg` is often a symlink elsewhere. bwrap cannot mount a tmpfs onto a symlink, and masking the
        // symlink path would leave the real directory readable by its own path. The mask names the REAL directory.
        $real = $this->dir();
        file_put_contents($real . '/a-key', 'x');
        $home = $this->dir();
        symlink($real, $home . '/.gnupg');
        $this->setEnv('HOME', $home);
        $this->setEnv('GNUPGHOME', false);

        $args = (new TrialRunner())->maskArgs($this->dir());

        self::assertMasksDirectory($args, $real, 'the real keyring the symlink points at is masked');
        self::assertNotContains($home . '/.gnupg', $args, 'the symlink path is not passed to --tmpfs (bwrap would fail)');
    }

    public function testTheSmartcardSocketDirectoryIsMaskedWhereItExists(): void
    {
        self::assertSame('/run/pcscd', TrialRunner::PCSCD_SOCKET_DIR, 'the smartcard socket dir the Desktop mounts');
        if (!is_dir(TrialRunner::PCSCD_SOCKET_DIR)) {
            self::markTestSkipped('no /run/pcscd on this host to mask');
        }
        $this->setEnv('HOME', false);
        $this->setEnv('GNUPGHOME', false);

        $args = (new TrialRunner())->maskArgs($this->dir());

        self::assertMasksDirectory($args, (string) realpath(TrialRunner::PCSCD_SOCKET_DIR), 'the smartcard socket directory is masked (evidence/1178)');
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

        $i = array_search((string) realpath($home . '/.gnupg'), $args, true);
        self::assertNotFalse($i, 'the keyring is in the mask');
        self::assertSame('--tmpfs', $args[$i - 1] ?? null, 'a keyring is masked by an empty tmpfs, not --ro-bind /dev/null');
    }

    public function testAKeyringThatDoesNotExistIsLeftOutSoBwrapNeverFailsToCreateIt(): void
    {
        // `--tmpfs` must CREATE its mountpoint, which cannot be done inside `--ro-bind / /` (the tree is read-only),
        // so masking a keyring that does not exist makes bwrap fail and breaks EVERY confined run — the regression a
        // host without a keyring hits. A keyring that is not there is left out; it is no risk either.
        $home = $this->dir();     // a HOME with no .gnupg
        $this->setEnv('HOME', $home);
        $this->setEnv('GNUPGHOME', '/this/keyring/does/not/exist');

        $args = (new TrialRunner())->maskArgs($this->dir());

        self::assertNotContains($home . '/.gnupg', $args, 'a $HOME with no .gnupg adds no tmpfs mask (bwrap could not create it under ro-bind)');
        self::assertNotContains('/this/keyring/does/not/exist', $args, 'a GNUPGHOME that does not exist is left out');
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
