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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\RecordedEdit;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\DevTools\Operations\DevToolsOperations;
use Milpa\DevTools\Operations\EditHandler;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * An `edit` on what the session itself brought into the house does not select a target (greenhouse decisions/0596).
 *
 * The intent contract asks a person before an operation touches a target the request does not name, and no mode
 * exempts it. For `edit` the line was drawn by the verb: `implement` creates its target and is not asked, `edit`
 * selects one that exists and is. The record of the lab's houses drew it elsewhere — of 34 times a house asked a
 * person to confirm an `edit`, 28 were about a class that same session had brought into the house a moment before,
 * and not one about a class the record shows in the house from before the session. Each of those questions ended a
 * leg: it stopped two runs of three in the thesis run (evidence/1137) and it is what both long runs of evidence/1128
 * hit.
 *
 * So for an operation that declares it amends the target it names, the target is NAMED when this session's own
 * record says where it came from: a trial of this session added the file, that trial reached the house, and the file
 * is still, byte for byte, what this session left. It is read from facts the house wrote — never from what a model
 * says — and it fails closed: whatever the record does not say is asked, as before.
 *
 * @guards a class this session brought being named by its record, in the plugin's sources and in its tests, by the
 *         fixture's `edit` and by the one milpa/devtools installs; the
 *         digest the file must still have being the one of the last trial of this session that LANDED, in the order
 *         the trials landed; a later trial that never landed changing nothing
 *
 * @refuses a class that was in the house before the session; a file that changed since the session left it; a class
 *          another session brought; a class the session overwrote instead of adding; a trial that never reached the
 *          house, even over identical bytes — nor one whose promotion failed or carried nothing; a record that keeps
 *          no digest; a fact of the session that is not a trial run; a link; a call that names no plugin; an
 *          operation that does not declare it amends; a grave operation whatever it declares; a gate with no house
 *
 * @subject-in milpa/app-runtime
 */
final class AnEditOnWhatTheSessionBroughtIsNotAskedTest extends TestCase
{
    private const CONTROLLER = 'src/Plugins/Blog/Controllers/PostController.php';

    private const JUDGE = 'tests/Plugins/Blog/BlogTest.php';

    private string $root;

    private SessionStore $sessions;

    protected function setUp(): void
    {
        if (!method_exists(ImplementHandler::class, 'scaffold')) {
            self::markTestSkipped('The installed DevTools keeps its scaffold lookup private; the gate cannot find the class to read its record.');
        }
        $this->root = sys_get_temp_dir() . '/milpa-brought-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src/Plugins/Blog/Controllers', 0o700, true);
        mkdir($this->root . '/tests/Plugins/Blog', 0o700, true);
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', "<?php\n");
        $this->sessions = new SessionStore(new InMemoryEventStore());
        // The goal names the blog, never the classes: every `edit` below meets the intent contract.
        $this->sessions->start('camino', 'Build a tiny blog that serves /blog.', AutonomyMode::Auto);
        $this->sessions->start('other', 'Another seat, building the same blog.', AutonomyMode::Auto);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testAClassThisSessionBroughtIsNamedByItsRecord(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);

        self::assertNull($this->edit('PostController'), 'the edit goes on to its trial');
        self::assertNull($this->asked(), 'and nobody is asked to confirm a class this session made');
    }

    public function testATestClassThisSessionBroughtIsNamedTheSame(): void
    {
        $this->lands('camino', 'w1', [self::JUDGE => ['added', "<?php // judge\n"]]);

        self::assertNull($this->edit('BlogTest'));
        self::assertNull($this->asked(), 'the judge of a plugin is a class of it too (evidence/1128: both long runs)');
    }

    public function testTheEditThisHouseInstallsIsOneThatDeclaresIt(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        $installed = array_column((new DevToolsOperations())->operations(), null, 'name')['edit'];
        $session = $this->sessions->load('camino');
        self::assertNotNull($session);
        $gate = new SessionToolGate(
            $this->sessions,
            $session,
            [RecordedEdit::operation($installed)],
            petition: 'continue',
            trialRouter: new TrialRouter($this->root, new TrialRunner(bwrap: '/nonexistent/bwrap'), __FILE__),
        );

        $gate->refuse('edit', ['plugin' => 'Blog', 'class' => 'PostController', 'edits' => [['find' => '// scaffold', 'replace' => '// filled']]]);

        self::assertNull($this->asked(), 'the rule is one an operation declares, and the `edit` this package requires declares it');
    }

    public function testAClassThatWasInTheHouseBeforeTheSessionIsStillAsked(): void
    {
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // it was here\n");

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'the control: selecting what was already there stays the person\'s');
    }

    public function testAFileThatChangedSinceTheSessionLeftItIsAsked(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // someone else was here\n");

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'it is no longer what this session left');
    }

    public function testAClassAnotherSessionBroughtIsAsked(): void
    {
        $this->lands('other', 'w9', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'another session\'s record is not read to decide whether to ask (decisions/0571)');
    }

    public function testAClassTheSessionOverwroteInsteadOfAddingIsAsked(): void
    {
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // it was here\n");
        // `make` with force over a class that exists: the trial reports it modified, and it lands.
        $this->lands('camino', 'w1', [self::CONTROLLER => ['modified', "<?php // overwritten\n"]]);

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'the first thing this record says of the file is not that it added it');
    }

    public function testATrialThatNeverReachedTheHouseBringsNothingEvenOverTheSameBytes(): void
    {
        $this->runs('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        // Someone else put the very same scaffold in the house.
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // scaffold\n");

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'a rehearsal is not a fact about the house (decisions/0494)');
    }

    public function testTheFileMustStillBeWhatTheLastLandedTrialOfTheSessionLeft(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        $this->lands('camino', 'w2', [self::CONTROLLER => ['modified', "<?php // filled\n"]]);

        self::assertNull($this->edit('PostController'));
        self::assertNull($this->asked(), 'born here, and amended here since: still what this session left');
    }

    public function testTheBytesTheSessionAddedAreNotEnoughOnceItLandedOthers(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        $this->lands('camino', 'w2', [self::CONTROLLER => ['modified', "<?php // filled\n"]]);
        // Something put the first version back.
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // scaffold\n");

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'the file is compared with the LAST thing this session landed, not with anything it ever wrote');
    }

    public function testALaterTrialOfTheSessionThatDidNotLandChangesNothing(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        $this->runs('camino', 'w2', [self::CONTROLLER => ['modified', "<?php // a rehearsal\n"]]);

        self::assertNull($this->edit('PostController'));
        self::assertNull($this->asked(), 'the house still holds what the session landed');
    }

    public function testWhatLandedLastIsReadInTheOrderTheTrialsLanded(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        // Two trials ran one after the other and were promoted the other way round: the house holds the first one's bytes.
        $this->runs('camino', 'w2', [self::CONTROLLER => ['modified', "<?php // ran first, landed last\n"]]);
        $this->runs('camino', 'w3', [self::CONTROLLER => ['modified', "<?php // ran last, landed first\n"]]);
        $this->promotes('camino', 'w3', [self::CONTROLLER]);
        $this->promotes('camino', 'w2', [self::CONTROLLER]);
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // ran first, landed last\n");

        self::assertNull($this->edit('PostController'));
        self::assertNull($this->asked());
    }

    public function testAnAddThatLandedAfterAnOverwriteIsNotABirth(): void
    {
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // it was here\n");
        // The trial that says «added» ran while the file was absent somewhere else in time; what LANDED first was a change.
        $this->runs('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        $this->lands('camino', 'w2', [self::CONTROLLER => ['modified', "<?php // overwritten\n"]]);
        $this->promotes('camino', 'w1', [self::CONTROLLER]);
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // scaffold\n");

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'the first thing that landed from this session on that file was not an addition');
    }

    public function testAnOperationThatDoesNotDeclareItAmendsIsAskedAsBefore(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);

        self::assertNotNull($this->edit('PostController', amends: false));
        self::assertSame('target_not_named', $this->asked(), 'the control: this is the published `edit`, and the rule is one an operation declares');
    }

    public function testAGraveOperationIsAskedWhateverItDeclares(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);

        self::assertNotNull($this->edit('PostController', authority: Authority::Privileged));
        self::assertSame('target_not_named', $this->asked(), 'the admissibility table marks privileged authority NEVER (decisions/0184)');
    }

    public function testARecordThatKeepsNoDigestNamesNothing(): void
    {
        $this->sessions->recordTrialRun('camino', ['workspace' => 'w1', 'operation' => 'make', 'report' => [self::CONTROLLER => 'added']]);
        $this->promotes('camino', 'w1', [self::CONTROLLER]);
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // scaffold\n");

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'without a digest nobody can say the file is still what the session left');
    }

    public function testALinkIsNotAFileTheSessionLeft(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        rename($this->root . '/' . self::CONTROLLER, $this->root . '/elsewhere.php');
        symlink($this->root . '/elsewhere.php', $this->root . '/' . self::CONTROLLER);

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'the same bytes reached through a link are another resource');
    }

    public function testWithoutAHouseToReadTheGateAsksAsBefore(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);

        self::assertNotNull($this->edit('PostController', house: false));
        self::assertSame('target_not_named', $this->asked());
    }

    public function testAnotherClassOfTheSamePluginIsJudgedOnItsOwnRecord(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        file_put_contents($this->root . '/src/Plugins/Blog/Controllers/CommentController.php', "<?php // it was here\n");

        self::assertNotNull($this->edit('CommentController'));
        self::assertSame('target_not_named', $this->asked(), 'what the session brought names that file, not its plugin');
    }

    public function testACallThatNamesNoPluginIsAskedAsBefore(): void
    {
        $this->lands('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);

        self::assertNotNull($this->edit('PostController', plugin: null));
        self::assertSame('target_not_named', $this->asked(), 'there is no plugin to look the class up in');
    }

    public function testAPluginThatDoesNotExistIsLeftToItsOwnDoor(): void
    {
        self::assertNotNull($this->edit('CartController', plugin: 'Shop'));
        self::assertSame('target_not_named', $this->asked(), 'the frontier speaks about a plugin nobody made (decisions/0496)');
    }

    public function testAReportWhoseDigestIsMissingNamesNothing(): void
    {
        $this->sessions->recordTrialRun('camino', ['workspace' => 'w1', 'operation' => 'make', 'report' => [self::CONTROLLER => ['status' => 'added']]]);
        $this->promotes('camino', 'w1', [self::CONTROLLER]);
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // scaffold\n");

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked());
    }

    public function testOnlyTheHousesOwnFactOfATrialRunIsRead(): void
    {
        $content = "<?php // scaffold\n";
        // Another fact of the session carries the same words — a workspace that was promoted, and a report — and is not a trial run.
        $this->sessions->recordTrialPromotion('camino', ['workspace' => 'w1', 'paths' => [self::CONTROLLER],
            'report' => [self::CONTROLLER => ['status' => 'added', 'sha256' => hash('sha256', $content)]]]);
        $this->promotes('camino', 'w1', [self::CONTROLLER]);
        file_put_contents($this->root . '/' . self::CONTROLLER, $content);

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked(), 'what a trial changed is said by the fact the house writes when the trial ends, and by no other');
    }

    public function testAPromotionThatCarriedNothingBringsNothing(): void
    {
        $this->runs('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        $this->promotes('camino', 'w1', []);
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // scaffold\n");

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked());
    }

    public function testAPromotionThatFailedBringsNothing(): void
    {
        $this->runs('camino', 'w1', [self::CONTROLLER => ['added', "<?php // scaffold\n"]]);
        $this->sessions->recordToolCall('camino', 'sandbox_promote', ['workspace' => 'w1'], '{"ok":false,"error":"the house would not boot"}', false, true);
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // scaffold\n");

        self::assertNotNull($this->edit('PostController'));
        self::assertSame('target_not_named', $this->asked());
    }

    public function testTheQuestionThatIsStillAskedSaysWhatItSaid(): void
    {
        file_put_contents($this->root . '/' . self::CONTROLLER, "<?php // it was here\n");

        $this->edit('PostController');

        self::assertSame(
            'The request does not name «PostController». Confirm edit on «PostController»?',
            $this->sessions->load('camino')?->question?->question,
        );
    }

    /**
     * Send the `edit` to the gate of the session «camino».
     */
    private function edit(string $class, bool $amends = true, Authority $authority = Authority::WriteAsUser, bool $house = true, ?string $plugin = 'Blog'): ?string
    {
        $session = $this->sessions->load('camino');
        self::assertNotNull($session);
        $gate = new SessionToolGate(
            $this->sessions,
            $session,
            [RecordedEdit::operation($this->editor($amends, $authority))],
            petition: 'continue',
            trialRouter: $house ? new TrialRouter($this->root, new TrialRunner(bwrap: '/nonexistent/bwrap'), __FILE__) : null,
        );

        return $gate->refuse('edit', ($plugin === null ? [] : ['plugin' => $plugin]) + ['class' => $class, 'edits' => [['find' => 'a', 'replace' => 'b']]]);
    }

    private function editor(bool $amends, Authority $authority): Operation
    {
        return new Operation(
            'edit',
            'Lands a class.',
            [EditHandler::class, 'handle'],
            inputSchema: ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string'], 'class' => ['type' => 'string']]],
            mutating: true,
            namedTarget: 'class',
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::ManualRecovery,
                $authority,
                escalatesOn: ['class'],
                subject: Subject::Executable,
            ),
            amendsNamedTarget: $amends,
        );
    }

    /**
     * A trial of the session ran and reported those files, as the house records it: the trial's own fact, then the call.
     *
     * @param array<string, array{string, string}> $files path => [status, content the trial left]
     */
    private function runs(string $session, string $workspace, array $files): void
    {
        $report = $changed = [];
        foreach ($files as $path => [$status, $content]) {
            $report[$path] = ['status' => $status, 'sha256' => hash('sha256', $content)];
            $changed[$path] = $status;
        }
        $this->sessions->recordTrialRun($session, ['workspace' => $workspace, 'operation' => 'make', 'report' => $report]);
        $this->sessions->recordToolCall(
            $session,
            'make',
            ['what' => 'crud', 'plugin' => 'Blog'],
            (string) json_encode(['ran_in_trial' => true, 'applied' => false, 'workspace' => $workspace, 'changed' => $changed,
                'output' => ['ok' => true], 'to_apply' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $workspace]]]),
            true,
            true,
        );
    }

    /**
     * The session promoted that trial, as the house records a promotion that carried something.
     *
     * @param list<string> $paths
     */
    private function promotes(string $session, string $workspace, array $paths): void
    {
        $this->sessions->recordToolCall(
            $session,
            'sandbox_promote',
            ['workspace' => $workspace],
            (string) json_encode(['ok' => true, 'promoted' => $paths, 'evidence' => ['predicate' => 'promoted', 'subject' => $workspace,
                'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => $workspace], 'paths' => $paths]]),
            true,
            true,
        );
    }

    /**
     * A trial ran, was promoted, and the house holds what it left.
     *
     * @param array<string, array{string, string}> $files path => [status, content the trial left]
     */
    private function lands(string $session, string $workspace, array $files): void
    {
        $this->runs($session, $workspace, $files);
        $this->promotes($session, $workspace, array_keys($files));
        foreach ($files as $path => [, $content]) {
            file_put_contents($this->root . '/' . $path, $content);
        }
    }

    private function asked(): ?string
    {
        return $this->sessions->load('camino')?->question?->reason;
    }
}
