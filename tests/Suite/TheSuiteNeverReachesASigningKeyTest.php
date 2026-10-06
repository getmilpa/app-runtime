<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Suite;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE SUITE NEVER REACHES A SIGNING KEY.
 *
 * Measured (greenhouse evidence/1110): two tests build a repository with `git commit`, and git read the machine's own
 * configuration to do it. On a machine that signs its commits — `commit.gpgsign`, a key on a hardware token — running
 * the suite signed a throwaway commit with the developer's key, and failed when the token was locked. One of the two
 * hid git's stderr and its exit code, so it kept passing with a commit that never happened.
 *
 * The machine here is a HOSTILE one, built in a temporary directory: a `~/.gitconfig` that signs everything with a
 * program that leaves a mark and fails, and a `PATH` whose `gpg`, `gpgsm` and `ssh-keygen` do the same. No real key,
 * keyring, agent or device is ever asked — not when this passes, and not when the guard is broken on purpose: every
 * name git could run to sign resolves to a program of this test.
 */
final class TheSuiteNeverReachesASigningKeyTest extends TestCase
{
    private string $dir = '';

    private string $repo = '';

    private string $mark = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-suite-git-' . bin2hex(random_bytes(6));
        $this->repo = $this->dir . '/repo';
        $this->mark = $this->dir . '/a-signing-program-was-run';
        foreach (['/repo', '/home/.config/git', '/bin', '/no-keyring', '/decoy'] as $made) {
            mkdir($this->dir . $made, 0o700, true);
        }
        // What stands for a key: it says it was asked, and refuses.
        foreach (['/a-real-key', '/bin/gpg', '/bin/gpg2', '/bin/gpgsm', '/bin/ssh-keygen'] as $program) {
            file_put_contents($this->dir . $program, "#!/bin/sh\nprintf '%s %s\\n' \"\$0\" \"\$*\" >> " . escapeshellarg($this->mark) . "\nexit 1\n");
            chmod($this->dir . $program, 0o755);
        }
        file_put_contents($this->dir . '/no-keyring/gpg-agent.conf', "disable-scdaemon\n");
        // A machine that signs everything it commits, tags and pushes.
        $signs = "[user]\n\tname = A Developer\n\temail = developer@example.test\n\tsigningkey = 0xDEADBEEFDEADBEEF\n"
            . "[commit]\n\tgpgsign = true\n[tag]\n\tgpgsign = true\n\tforceSignAnnotated = true\n"
            . "[gpg]\n\tprogram = {$this->dir}/a-real-key\n[gpg \"ssh\"]\n\tprogram = {$this->dir}/a-real-key\n[gpg \"x509\"]\n\tprogram = {$this->dir}/a-real-key\n";
        file_put_contents($this->dir . '/home/.gitconfig', $signs);
        file_put_contents($this->dir . '/home/.config/git/config', $signs);
        file_put_contents($this->dir . '/system-gitconfig', $signs);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testACommitOfTheSuiteDoesNotReadTheMachinesGitConfiguration(): void
    {
        $this->aRepositoryWithOneFileStaged();

        [$code, $said] = $this->git('-c user.email=t@t -c user.name=t commit -q -m house');

        self::assertSame(0, $code, $said);
        $this->assertNoSigningProgramWasRun();
        self::assertStringNotContainsString('gpgsig', $this->git('cat-file commit HEAD')[1], 'and the commit carries no signature');
        self::assertSame('', $this->git('config --get user.signingkey')[1], 'git was not told whose key to use');
        self::assertDoesNotMatchRegularExpression('~^(global|system)\s~m', $this->git('config --list --show-scope')[1], 'nothing of the machine reaches git');
    }

    public function testNeitherDoesATagNorAMerge(): void
    {
        $this->aRepositoryWithOneCommit();

        foreach (['tag v1', '-c user.email=t@t -c user.name=t tag -a v2 -m v2', '-c user.email=t@t -c user.name=t tag -m v3 v3', 'checkout -q -b side', '-c user.email=t@t -c user.name=t commit -q --allow-empty -m side', 'checkout -q -',
            '-c user.email=t@t -c user.name=t merge -q --no-ff -m merged side'] as $step) {
            [$code, $said] = $this->git($step);
            self::assertSame(0, $code, "git {$step}: {$said}");
        }

        $this->assertNoSigningProgramWasRun();
    }

    /** A fixture that copied a repository which asks to sign is not obeyed either. */
    public function testARepositoryThatAsksToSignIsNotObeyed(): void
    {
        $this->aRepositoryWithOneFileStaged();
        foreach (['commit.gpgsign true', 'tag.gpgsign true', 'tag.forceSignAnnotated true', "gpg.program {$this->dir}/a-real-key"] as $asked) {
            self::assertSame(0, $this->git('config ' . $asked)[0]);
        }

        foreach (['-c user.email=t@t -c user.name=t commit -q -m house', '-c user.email=t@t -c user.name=t tag v1', '-c user.email=t@t -c user.name=t tag -a v2 -m v2',
            // The tag `tag.forceSignAnnotated` speaks of: annotated by its message alone (`-a` takes precedence over it).
            '-c user.email=t@t -c user.name=t tag -m v3 v3'] as $step) {
            [$code, $said] = $this->git($step);
            self::assertSame(0, $code, "git {$step}: {$said}");
            $this->assertNoSigningProgramWasRun("git {$step}");
        }
    }

    /**
     * Whoever asks for a signature outright gets a refusal — from a program that only refuses, never from a key.
     */
    #[DataProvider('signatureFormats')]
    public function testAnExplicitSignatureIsRefusedAndNeverReachesAKey(string $format, string $key): void
    {
        $this->aRepositoryWithOneFileStaged();

        [$code, $said] = $this->git("-c user.email=t@t -c user.name=t -c gpg.format={$format} -c user.signingkey=" . escapeshellarg($key) . ' commit -q -S -m signed');

        self::assertNotSame(0, $code, 'a signature nobody can give is not a commit');
        $this->assertNoSigningProgramWasRun($said);
        self::assertNotSame(0, $this->git('rev-parse -q --verify HEAD')[0], 'and nothing was committed unsigned in its place');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function signatureFormats(): iterable
    {
        yield 'openpgp' => ['openpgp', '0xDEADBEEFDEADBEEF'];
        yield 'ssh' => ['ssh', 'key::ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeFakeFakeFakeFakeFakeFakeFakeFakeFakeFake a@b'];
        yield 'x509' => ['x509', 'developer@example.test'];
    }

    /**
     * The guard is the suite's bootstrap, and it holds whatever the suite was started from: a shell that injects
     * signing through git's environment, a hook that points git at the repository being committed.
     */
    public function testTheBootstrapHoldsUnderAnEnvironmentThatSignsAndPointsElsewhere(): void
    {
        self::assertSame(0, $this->git('init -q', $this->dir . '/decoy')[0]);
        file_put_contents($this->repo . '/file', "x\n");
        $script = $this->dir . '/a-test.php';
        file_put_contents($script, '<?php require ' . var_export(\dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . '$repo = ' . var_export($this->repo, true) . ';'
            . 'foreach (["init -q", "add -A", "-c user.email=t@t -c user.name=t commit -q -m house", "-c user.email=t@t -c user.name=t tag -a v1 -m v1"] as $git) {'
            . ' exec("git -C " . escapeshellarg($repo) . " " . $git . " 2>&1", $said, $code); if ($code !== 0) { echo "git {$git}: ", implode("\n", $said), "\n"; exit(1); } }'
            . 'exec("git -C " . escapeshellarg($repo) . " config --list --show-scope 2>&1", $scopes); echo implode("\n", $scopes), "\n";');
        $hostile = [
            'HOME' => $this->dir . '/home', 'XDG_CONFIG_HOME' => $this->dir . '/home/.config', 'GNUPGHOME' => $this->dir . '/no-keyring',
            'PATH' => $this->dir . '/bin:' . getenv('PATH'),
            'GIT_CONFIG_GLOBAL' => $this->dir . '/home/.gitconfig', 'GIT_CONFIG_SYSTEM' => $this->dir . '/system-gitconfig',
            'GIT_CONFIG_COUNT' => '2', 'GIT_CONFIG_KEY_0' => 'commit.gpgsign', 'GIT_CONFIG_VALUE_0' => 'true',
            'GIT_CONFIG_KEY_1' => 'gpg.program', 'GIT_CONFIG_VALUE_1' => $this->dir . '/a-real-key',
            'GIT_CONFIG_PARAMETERS' => "'commit.gpgsign=true' 'tag.gpgsign=true'",
            'GIT_DIR' => $this->dir . '/decoy/.git', 'GIT_WORK_TREE' => $this->dir . '/decoy', 'GIT_INDEX_FILE' => $this->dir . '/decoy/.git/index',
        ];
        $run = proc_open([\PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $hostile);
        self::assertIsResource($run);
        $said = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($run);

        self::assertSame(0, $code, $said);
        $this->assertNoSigningProgramWasRun($said);
        self::assertDoesNotMatchRegularExpression('~^(global|system)\s~m', $said, 'neither file of the machine was read');
        self::assertDoesNotMatchRegularExpression('~gpgsign=true~', $said, 'and what the environment injected was not obeyed');
        self::assertStringNotContainsString('gpgsig', $this->git('cat-file commit HEAD')[1]);
        self::assertNotSame(0, $this->git('rev-parse -q --verify HEAD', $this->dir . '/decoy')[0], 'the repository the environment pointed at was not committed to');
        self::assertFileDoesNotExist($this->dir . '/decoy/.git/index', 'nor was its index written');
    }

    private function aRepositoryWithOneFileStaged(): void
    {
        file_put_contents($this->repo . '/file', "x\n");
        foreach (['init -q', 'add -A'] as $step) {
            [$code, $said] = $this->git($step);
            self::assertSame(0, $code, "git {$step}: {$said}");
        }
    }

    private function aRepositoryWithOneCommit(): void
    {
        $this->aRepositoryWithOneFileStaged();
        [$code, $said] = $this->git('-c user.email=t@t -c user.name=t commit -q -m house');
        self::assertSame(0, $code, $said);
    }

    /**
     * Git as a test of this suite runs it — `exec`, the environment it inherited — on the hostile machine.
     *
     * @return array{int, string}
     */
    private function git(string $arguments, ?string $in = null): array
    {
        $machine = 'HOME=' . escapeshellarg($this->dir . '/home') . ' XDG_CONFIG_HOME=' . escapeshellarg($this->dir . '/home/.config')
            . ' GNUPGHOME=' . escapeshellarg($this->dir . '/no-keyring') . ' PATH=' . escapeshellarg($this->dir . '/bin:' . getenv('PATH'));
        exec($machine . ' git -C ' . escapeshellarg($in ?? $this->repo) . ' ' . $arguments . ' 2>&1', $said, $code);

        return [$code, implode("\n", $said)];
    }

    private function assertNoSigningProgramWasRun(string $after = ''): void
    {
        self::assertFileDoesNotExist($this->mark, 'a program that signs was run: ' . (is_file($this->mark) ? trim((string) file_get_contents($this->mark)) : '') . "\n" . $after);
    }
}
