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

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Config\SecretFiles;
use Milpa\AppRuntime\Operations\TrialOperations;
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
 *         stale; a promotion never carries a book into the house
 *
 * @subject-in milpa/app-runtime
 */
final class ACopyOfTheHouseCarriesNoBookOfWhoMayDoWhatTest extends TestCase
{
    /** The books a house keeps under its tree; the session log lives in `var/`, which no copy carries anyway. */
    private const BOOKS = [
        'storage/identity/enrollments.json' => '{"seats":{"FINGERPRINT":{"label":"resident"}}}',
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
}
