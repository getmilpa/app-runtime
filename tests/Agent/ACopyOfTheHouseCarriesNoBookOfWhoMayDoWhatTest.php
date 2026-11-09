<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\AppliedTrials;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Config\SecretFiles;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\AppRuntime\Support\BootCandidate;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE TRIAL CANNOT READ, NO COPY CARRIES (greenhouse decisions/0625; evidence/1205 and 1209).
 *
 * The trial masks the house's NON-BEARER books — the enrollment ledger, the token store, the declaration ledger and
 * the session log ({@see SecretFiles::NON_BEARER_STATE}, decisions/0619 B2). Every copy still carried them, so a
 * writer inside the trial built a boot candidate that tried to `copy()` a masked file, failed, and refused the
 * whole write: in an equipped house the agent's seat could write nothing. The books now leave every copy of the
 * house, as the book of standing grants already does (decisions/0615); the reuse comparison leaves out what no copy
 * carries; and what no copy carries, no promotion carries back.
 *
 * @guards in a leg's trial a witnessed write lands in a house that holds every book; no trial copy and no boot
 *         candidate carries a book, as a file or as a link; a write of the house to a book does not make a copy
 *         stale; a promotion never carries a book into the house, under any spelling a case-folding filesystem
 *         resolves to it; the rsync way sweeps what its excludes missed; no boot candidate carries the book of
 *         standing grants
 *
 * @subject-in milpa/app-runtime
 */
final class ACopyOfTheHouseCarriesNoBookOfWhoMayDoWhatTest extends TestCase
{
    /** The books a house keeps under its tree; the session log lives in `var/`, which no copy carries anyway. */
    private const BOOKS = [
        'storage/identity/enrollments.json' => '{"seats":{"FINGERPRINT":{"label":"resident"}}}',
        'storage/identity/invitations.json' => '{"inv1":{"hash":"sha256:abc","redeemed_by":"key:FINGERPRINT"}}',
        'storage/tokens.json' => '{"tokens":{"sha256:abc":{"scopes":["agent:run"]}}}',
        'storage/capabilities/declarations.json' => '{"files":{"config/plugins.php":{"writes":1}}}',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
        foreach (self::BOOKS as $relative => $bytes) {
            @mkdir(\dirname($this->root . '/' . $relative), 0o777, true);
            file_put_contents($this->root . '/' . $relative, $bytes . "\n");
        }
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    public function testEveryBookOfThisTestIsOneTheHouseNamesAsNonBearerState(): void
    {
        // The control of the fixture: the books written above are exactly the ones the house names (outside `var/`).
        $named = array_values(array_filter(SecretFiles::NON_BEARER_STATE, static fn (string $p): bool => !str_starts_with($p, 'var/')));
        self::assertEqualsCanonicalizing($named, array_keys(self::BOOKS));
    }

    public function testInALegsTrialAWitnessedWriteLandsInAHouseThatHoldsEveryBook(): void
    {
        // THE DEFECT OF evidence/1205 AND 1209: «could not copy enrollments.json into the candidate», and nothing written.
        $runner = new TrialRunner();
        if (!$runner->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $workspace = TrialWorkspace::materialize($this->root, 'books-disable', \dirname(__DIR__) . '/Fixtures/trial-boot-witness-runner.php');

        $run = $runner->run($workspace, 'disable', []);

        self::assertSame(0, $run->exit, $run->stdout . $run->stderr);
        self::assertIsArray($run->output, $run->stdout . $run->stderr);
        self::assertNull($run->output['boot']['refused'], (string) json_encode($run->output['boot']));
        self::assertTrue($run->output['boot']['said']['house_boots'], (string) json_encode($run->output['boot']['said']));
        self::assertSame("<?php return [];\n", $run->output['plugins'], 'the write landed in the copy');
    }

    public function testATrialCopyCarriesNoBook(): void
    {
        $workspace = TrialWorkspace::materialize($this->root, 'books-copy', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');

        foreach (array_keys(self::BOOKS) as $relative) {
            self::assertFalse(file_exists($workspace->copy . '/' . $relative) || is_link($workspace->copy . '/' . $relative), "the copy carries {$relative}");
        }
        self::assertFileExists($workspace->copy . '/config/plugins.php', 'the control: the copy carries the rest of the house');
    }

    public function testABootCandidateCarriesNoBookAsAFileOrAsALink(): void
    {
        $candidate = BootCandidate::of($this->root, ['config/app.php' => "<?php return ['touched' => true];\n"]);
        try {
            foreach (array_keys(self::BOOKS) as $relative) {
                self::assertFalse(file_exists($candidate->path . '/' . $relative) || is_link($candidate->path . '/' . $relative), "the candidate carries {$relative}");
            }
            self::assertFileExists($candidate->path . '/config/plugins.php', 'the control: the candidate carries the rest of the house');
        } finally {
            $candidate->remove();
        }
    }

    public function testAWriteOfTheHouseToABookDoesNotMakeACopyStale(): void
    {
        $workspace = TrialWorkspace::materialize($this->root, 'books-reuse', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        self::assertTrue($workspace->hasCurrentInputs(), 'the control: a fresh copy has the inputs of the house');

        file_put_contents($this->root . '/storage/tokens.json', '{"tokens":{"sha256:def":{"scopes":["agent:run"]}}}' . "\n");
        self::assertTrue($workspace->hasCurrentInputs(), 'the house issued a token: no copy carries the token store, so the copy is not stale');

        file_put_contents($this->root . '/config/app.php', "<?php return ['moved' => true];\n");
        self::assertFalse($workspace->hasCurrentInputs(), 'the control: a file the copy carries moved, so the copy is stale');
    }

    public function testAPromotionNeverCarriesABookUnderAnotherSpelling(): void
    {
        // THE REVIEWS OF 0625, M1: a case-insensitive filesystem (macOS, the Desktop's) folds Unicode, so
        // `storage/Tokens.json`, `storage/to\u{212A}ens.json` (KELVIN SIGN) and `storage/token\u{017F}.json` (LONG S)
        // all ARE the token store there. Every refusal by name — the books, the bearer store, the secret files, the
        // book of standing grants, the list the house applies on its own — is asked under each spelling. What is
        // measured is the refusal and the house left as it was: on a case-sensitive filesystem a spelling is another
        // file, on a case-folding one it is the house's own book, and the snapshot holds on both.
        $named = [...array_keys(self::BOOKS), 'storage/confirm-tokens.json', '.milpa/secrets.json', 'auth.json', StandingLedger::PATH, StandingLedger::PATH . StandingLedger::HEAD, AppliedTrials::PATH];
        $before = self::snapshot($this->root);
        $i = 0;
        foreach ($named as $relative) {
            foreach (self::spellings($relative) as $spelling) {
                self::assertNotSame($relative, $spelling, 'control: the spelling differs');
                $id = 'books-spelling-' . $i++;
                $trial = TrialWorkspace::materialize($this->root, $id, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
                @mkdir(\dirname($trial->copy . '/' . $spelling), 0o777, true);
                file_put_contents($trial->copy . '/' . $spelling, '{"written":"in a rehearsal"}' . "\n");
                $promote = (new TrialOperations(new DIContainer(), root: $this->root))->operations()[0];

                $promoted = ($promote->handler)(['workspace' => $id], null, new ToolContext(principal: 'worker', channel: 'cli', scopes: ['*']));

                self::assertFalse($promoted['ok'], "a promotion carried {$spelling}: " . json_encode($promoted));
                self::assertStringStartsWith('a trial never carries', (string) ($promoted['error'] ?? ''), "{$spelling} was refused by its name, not by another check");
                self::assertSame($before, self::snapshot($this->root), "{$spelling}: the house is as it was");
            }
        }
    }

    public function testThePromotionOfAnythingElseStillLands(): void
    {
        // The control of the refusals by name: the same promotion, with a file no rule names, writes it.
        $trial = TrialWorkspace::materialize($this->root, 'books-control', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($trial->copy . '/config/app.php', "<?php return ['promoted' => true];\n");
        $promote = (new TrialOperations(new DIContainer(), root: $this->root))->operations()[0];

        $promoted = ($promote->handler)(['workspace' => 'books-control'], null, new ToolContext(principal: 'worker', channel: 'cli', scopes: ['*']));

        self::assertTrue($promoted['ok'], (string) json_encode($promoted));
        self::assertSame("<?php return ['promoted' => true];\n", file_get_contents($this->root . '/config/app.php'));
    }

    public function testTheRuleComparesNamesAsACaseFoldingFilesystemDoes(): void
    {
        foreach (['STORAGE/TOKENS.JSON', 'storage\\identity\\Enrollments.json', "storage/to\u{212A}ens.json", "storage/identity/invitation\u{017F}.json",
            ".milpa/\u{017F}ecrets.json", "auth.j\u{017F}on", "var/pa\u{00DF}key/sessions.json", "storage/identity/\u{FB06}anding.jsonl", ".ENV.LOCAL"] as $spelling) {
            self::assertTrue(SecretFiles::withheldFromCopy($spelling), "the rule lets {$spelling} into a copy");
        }
        // The controls: a name that folds to no named file is not withheld, accented or not.
        foreach (['config/plugins.php', "storage/tok\u{00E9}ns.json", "storage/to\u{043A}ens.json", '.env.example'] as $other) {
            self::assertFalse(SecretFiles::withheldFromCopy($other), "the rule withholds {$other}, which names no file it keeps");
        }
    }

    public function testTheRsyncWayLeavesOutWhatTheRuleNamesUnderAnySpelling(): void
    {
        // THE SECOND REVIEW OF 0625, L-A and L1: rsync's excludes compare names exactly. A house on a case-folding
        // filesystem may hold its token store as `storage/Tokens.json`, and a secret may be kept as a link; the copy
        // is swept with the rule after rsync. On this filesystem `storage/Tokens.json` is another file the rule
        // still names, which is what makes the sweep measurable here.
        exec('command -v rsync', $_, $code);
        if ($code !== 0) {
            self::markTestSkipped('this host has no rsync; the walk asks the rule path by path');
        }
        file_put_contents($this->root . '/storage/Tokens.json', '{"tokens":{}}' . "\n");
        file_put_contents($this->root . '/.env', "A=1\n");
        symlink($this->root . '/.env', $this->root . '/.env.prod');
        symlink('plugins.php', $this->root . '/config/linked.php');

        $workspace = TrialWorkspace::materialize($this->root, 'books-rsync', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');

        self::assertTrue(is_link($workspace->copy . '/config/linked.php'), 'the control: rsync made this copy (the walk copies a link as a file)');
        self::assertSame([], array_values(array_filter(scandir($workspace->copy . '/storage') ?: [], static fn (string $n): bool => SecretFiles::withheldFromCopy('storage/' . $n))), 'the copy carries a book under another spelling');
        self::assertFalse(is_link($workspace->copy . '/.env.prod') || file_exists($workspace->copy . '/.env.prod'), 'the copy carries a secret kept as a link');
        self::assertFileExists($workspace->copy . '/config/plugins.php', 'the control: the copy carries the rest of the house');
    }

    public function testABootCandidateCarriesNoBookOfStandingGrants(): void
    {
        // THE SECOND REVIEW OF 0625, L-B: the book of standing grants is in the one rule, so a boot candidate leaves
        // it out as a trial copy does.
        foreach ([StandingLedger::PATH, StandingLedger::PATH . StandingLedger::HEAD] as $relative) {
            file_put_contents($this->root . '/' . $relative, '{"grant":"given"}' . "\n");
            chmod($this->root . '/' . $relative, 0o600);
        }
        foreach (['of' => static fn (string $root): BootCandidate => BootCandidate::of($root, ['config/app.php' => "<?php return [];\n"]), 'staged' => BootCandidate::staged(...)] as $way => $make) {
            $candidate = $make($this->root);
            try {
                foreach ([StandingLedger::PATH, StandingLedger::PATH . StandingLedger::HEAD] as $relative) {
                    self::assertFalse(file_exists($candidate->path . '/' . $relative) || is_link($candidate->path . '/' . $relative), "{$way}: the candidate carries {$relative}");
                }
                self::assertFileExists($candidate->path . '/config/plugins.php', "{$way}: the control: the candidate carries the rest of the house");
            } finally {
                $candidate->remove();
            }
        }
    }

    public function testACopierThatLetAWithheldFileInDoesNotMakeAFreshCopyStale(): void
    {
        // THE REVIEW OF 0625, L2: rsync can carry a secret kept as a link. The reuse comparison drops what no copy
        // carries from BOTH sides, so a fresh copy that holds one is not reported stale.
        file_put_contents($this->root . '/.env.local', "A=1\n");
        $workspace = TrialWorkspace::materialize($this->root, 'books-letin', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        $manifest = \dirname($workspace->copy) . '/manifest.json';
        $baseline = json_decode((string) file_get_contents($manifest), true);
        self::assertIsArray($baseline);
        $baseline['.env.local'] = (string) hash_file('sha256', $this->root . '/.env.local');
        $baseline['storage/tokens.json'] = (string) hash_file('sha256', $this->root . '/storage/tokens.json');
        file_put_contents($manifest, (string) json_encode($baseline, \JSON_UNESCAPED_SLASHES));

        self::assertTrue($workspace->hasCurrentInputs(), 'a withheld path in the baseline is dropped as on the house side');
    }

    public function testAPromotionNeverCarriesABookIntoTheHouse(): void
    {
        foreach (array_keys(self::BOOKS) as $i => $relative) {
            $id = 'books-promote-' . $i;
            $trial = TrialWorkspace::materialize($this->root, $id, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
            @mkdir(\dirname($trial->copy . '/' . $relative), 0o777, true);
            file_put_contents($trial->copy . '/' . $relative, '{"written":"in a rehearsal"}' . "\n");
            mkdir($trial->copy . '/src/Plugins/Shop', 0o777, true);
            file_put_contents($trial->copy . '/src/Plugins/Shop/Shop.php', "<?php // the plugin\n");
            $promote = (new TrialOperations(new DIContainer(), root: $this->root))->operations()[0];
            self::assertSame('sandbox:promote', $promote->name);

            $promoted = ($promote->handler)(['workspace' => $id], null, new ToolContext(principal: 'worker', channel: 'cli', scopes: ['*']));

            self::assertFalse($promoted['ok'], "a promotion carried {$relative}: " . json_encode($promoted));
            self::assertStringContainsString($relative, (string) ($promoted['error'] ?? ''), 'the refusal names the book');
            self::assertSame(self::BOOKS[$relative] . "\n", file_get_contents($this->root . '/' . $relative), "the house's {$relative} is untouched");
            self::assertFileDoesNotExist($this->root . '/src/Plugins/Shop/Shop.php', 'nothing of that trial crossed');
        }
    }

    /**
     * Spellings of a named path a case-folding filesystem resolves to it: each segment capitalised, the last `s`
     * as LONG S (every named path ends in `json`/`jsonl`/`head`… and holds an `s`), and a `k` as KELVIN SIGN.
     *
     * @return list<string>
     */
    private static function spellings(string $relative): array
    {
        $spellings = [implode('/', array_map('ucfirst', explode('/', $relative)))];
        $s = strrpos($relative, 's');
        self::assertNotFalse($s, "control: {$relative} holds an s");
        $spellings[] = substr_replace($relative, "\u{017F}", $s, 1);
        if (($k = strpos($relative, 'k')) !== false) {
            $spellings[] = substr_replace($relative, "\u{212A}", $k, 1);
        }

        return $spellings;
    }

    /**
     * Every file of a house outside `var/`, by the name it is stored under, with its digest.
     *
     * @return array<string, string>
     */
    private static function snapshot(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (!str_starts_with($relative, 'var/') && $file->isFile()) {
                $files[$relative] = (string) hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
