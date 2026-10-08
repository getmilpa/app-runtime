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
use Milpa\DevTools\Operations\EditHandler;
use Milpa\DevTools\Operations\EditPairs;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The repair of a proposal of this session is not asked about again (greenhouse decisions/0596, second clause).
 *
 * When a judge rejects an `implement`, the house's own hint names the repair: `edit` with the recorded proposal as
 * its `source` (decisions/0571). That `implement` had run without a question — it creates its target (decisions/0187)
 * — and the `edit` the house had just suggested then asked a person to confirm the class, and ended the leg
 * (evidence/1137, the third run without skills). decisions/0571 left it written as Rod's to decide.
 *
 * Decided: the repair inherits the intent of its proposal. A source the recorded door binds is a producer of this
 * same session that ran in a trial — it had passed the intent contract when it ran — on the same plugin and class,
 * over a destination that still matches the baseline it was judged against. Nothing the request did not already
 * admit is being selected.
 *
 * The recorded rejection here is a real one, kept as a fixture; the class it lands on was in the house before the
 * session, so nothing but this clause names it.
 *
 * @guards a repair of a bound proposal of this session going on to its trial unasked
 *
 * @refuses a repair of another session's proposal; an operation that does not declare it amends; a grave operation;
 *          the same call behind another handler;
 *          a source the door does not bind being asked about instead of refused; a gate with no house to read
 *
 * @subject-in milpa/app-runtime
 */
final class ARepairOfTheSessionsOwnProposalIsNotAskedTest extends TestCase
{
    private const SESSION = 'desk-00000000000818ac';

    private const SUBJECT = 'src/Plugins/Owned/Services/TodoItemRenderer.php';

    private string $root;

    /** @var array<string, mixed> */
    private array $fixture;

    private SessionStore $sessions;

    protected function setUp(): void
    {
        if (!method_exists(ImplementHandler::class, 'scaffold') || !class_exists(EditPairs::class)) {
            self::markTestSkipped('The installed DevTools has no native editor to repair with, or keeps its scaffold lookup private.');
        }
        $this->root = sys_get_temp_dir() . '/milpa-repair-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src/Plugins/Owned/Services', 0o700, true);
        $this->fixture = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/recorded-edit-origin.json'), true, flags: \JSON_THROW_ON_ERROR);
        file_put_contents($this->root . '/' . self::SUBJECT, $this->fixture['host']);
        $store = new InMemoryEventStore();
        foreach ($this->fixture['events'] as $event) {
            $store->append(Event::fromArray($event));
        }
        $this->sessions = new SessionStore($store);
        // The goals name the work, never the class.
        $this->sessions->start(self::SESSION, 'Make the to-do list render in the visitor\'s language.', AutonomyMode::Auto);
        $this->sessions->start('camino', 'Another seat, on the same list.', AutonomyMode::Auto);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testTheClassWasInTheHouseBeforeTheSessionSoAPlainEditIsAsked(): void
    {
        self::assertNotNull($this->edit(self::SESSION, ['plugin' => 'Owned', 'class' => 'TodoItemRenderer', 'edits' => [['find' => 'a', 'replace' => 'b']]]));
        self::assertSame('target_not_named', $this->asked(self::SESSION), 'the control: nothing in this record says the session brought the class');
    }

    public function testARepairOfAProposalOfThisSessionIsNotAsked(): void
    {
        self::assertNull($this->edit(self::SESSION, $this->repair()), 'the repair goes on to its trial');
        self::assertNull($this->asked(self::SESSION), 'and nobody is asked to confirm the class the house\'s own hint named');
    }

    public function testTheSameRepairIsAskedWhenTheOperationDoesNotDeclareItAmends(): void
    {
        self::assertNotNull($this->edit(self::SESSION, $this->repair(), amends: false));
        self::assertSame('target_not_named', $this->asked(self::SESSION), 'the control: the published `edit`');
    }

    public function testARepairOfAnotherSessionsProposalIsAsked(): void
    {
        self::assertNotNull($this->edit('camino', $this->repair()));
        self::assertSame('target_not_named', $this->asked('camino'), 'that proposal is not this session\'s: whether this seat may read it is a permission');
    }

    public function testOnlyTheNativeEditorRepairs(): void
    {
        // The same name, the same declaration, the same arguments — and another handler behind it.
        self::assertNotNull($this->edit(self::SESSION, $this->repair(), handler: ImplementHandler::class));
        self::assertSame('target_not_named', $this->asked(self::SESSION), 'a `source` means a recorded repair only to the editor that makes one');
    }

    public function testAGraveRepairIsAskedWhateverItDeclares(): void
    {
        self::assertNotNull($this->edit(self::SESSION, $this->repair(), authority: Authority::Privileged));
        self::assertSame('target_not_named', $this->asked(self::SESSION));
    }

    public function testASourceTheDoorDoesNotBindIsRefusedAndNobodyIsAsked(): void
    {
        $repair = $this->repair();
        $repair['source']['sha256'] = str_repeat('a', 64);

        $refusal = $this->edit(self::SESSION, $repair);

        self::assertIsString($refusal);
        self::assertStringNotContainsString('does not name', $refusal, 'it is the door\'s own refusal, not a question');
        self::assertNull($this->asked(self::SESSION), 'the control: a yes is not spent on a call the house refuses (decisions/0571)');
    }

    public function testARepairOverAHouseThatChangedIsRefusedAndNobodyIsAsked(): void
    {
        file_put_contents($this->root . '/' . self::SUBJECT, $this->fixture['host'] . "\n// someone else was here\n");

        $refusal = $this->edit(self::SESSION, $this->repair());

        self::assertIsString($refusal);
        self::assertNull($this->asked(self::SESSION), 'the destination no longer matches the baseline the rejection was judged against');
    }

    public function testWithoutAHouseToReadTheRepairIsAskedAsBefore(): void
    {
        self::assertNotNull($this->edit(self::SESSION, $this->repair(), house: false));
        self::assertSame('target_not_named', $this->asked(self::SESSION));
    }

    /** @return array<string, mixed> the repair the fixture's own rejection calls for */
    private function repair(): array
    {
        $body = $this->fixture['events'][2]['payload']['arguments']['content'];

        return ['plugin' => 'Owned', 'class' => 'TodoItemRenderer',
            'source' => ['session' => self::SESSION, 'seq' => 90, 'sha256' => hash('sha256', $body)],
            'edits' => [...$this->fixture['repairs']['locale'], ...$this->fixture['repairs']['form']]];
    }

    /** @param array<string, mixed> $arguments */
    private function edit(string $session, array $arguments, bool $amends = true, Authority $authority = Authority::WriteAsUser, bool $house = true, string $handler = EditHandler::class): ?string
    {
        $loaded = $this->sessions->load($session);
        self::assertNotNull($loaded);
        $gate = new SessionToolGate(
            $this->sessions,
            $loaded,
            [RecordedEdit::operation($this->editor($amends, $authority, $handler))],
            petition: 'continue',
            trialRouter: $house ? new TrialRouter($this->root, new TrialRunner(bwrap: '/nonexistent/bwrap'), __FILE__) : null,
        );

        return $gate->refuse('edit', $arguments);
    }

    /** @param class-string $handler */
    private function editor(bool $amends, Authority $authority, string $handler): Operation
    {
        return new Operation(
            'edit',
            'Lands a class.',
            [$handler, 'handle'],
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

    private function asked(string $session): ?string
    {
        return $this->sessions->load($session)?->question?->reason;
    }
}
