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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\RecordedEdit;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\DevTools\Operations\EditHandler;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A REFUSAL THE HOUSE KNOWS BY READING SAYS THE STEP THAT IS MISSING, IS IN THE LEDGER, AND GOES BACK TO THE MODEL
 * (greenhouse decisions/0591).
 *
 * Measured with the real resident (greenhouse evidence/1127): `make` scaffolded a class in a trial, the resident
 * filled it before promoting that trial, and the house answered «no scaffold declares class «X» … scaffold it first
 * with `make`» — the step it had just taken. The refusal ended the leg, and neither the call nor the sentence was
 * recorded: only what `coa agent` printed had them. Two of three runs stopped there.
 */
final class AForeknownRefusalSaysTheStepThatIsMissingTest extends TestCase
{
    private string $root;
    private SessionStore $sessions;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        if (!method_exists(ImplementHandler::class, 'scaffold')) {
            self::markTestSkipped('The installed DevTools keeps its scaffold lookup private; the gate foreknows nothing about it.');
        }
        $this->root = sys_get_temp_dir() . '/milpa-missing-step-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src/Plugins/Blog', 0o700, true);
        mkdir($this->root . '/tests/Plugins/Blog', 0o700, true);
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', "<?php\n");
        $this->sessions = new SessionStore(new InMemoryEventStore());
        $this->sessions->start('camino', 'Build a plugin called Blog with a CommentController.', AutonomyMode::Auto);
        $this->sessions->start('other', 'Another seat, building CommentController in Blog.', AutonomyMode::Auto);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testAClassScaffoldedInATrialOfThisSessionIsToldToPromoteThatTrial(): void
    {
        $this->trial('camino', 'w1', ['src/Plugins/Blog/Controllers/CommentController.php']);

        $refusal = $this->gate()->refuse('implement', ['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php']);

        self::assertSame(
            'class «CommentController» in plugin «Blog» is scaffolded in trial «w1», and that trial is not in the house '
            . 'yet — promote it first: sandbox:promote {"workspace":"w1"}; then send this call again. '
            . 'Nothing ran and nobody was asked.',
            $refusal,
        );
        self::assertNull($this->asked());
    }

    public function testATestClassScaffoldedInATrialIsToldTheSame(): void
    {
        $this->trial('camino', 'w1', ['tests/Plugins/Blog/CommentControllerTest.php']);

        $refusal = (string) $this->gate()->refuse('edit', ['plugin' => 'Blog', 'class' => 'CommentControllerTest', 'edits' => []]);

        self::assertStringContainsString('is scaffolded in trial «w1»', $refusal);
        self::assertStringContainsString('sandbox:promote {"workspace":"w1"}', $refusal);
    }

    public function testAClassNothingDeclaresAnywhereIsStillSentToMake(): void
    {
        $this->trial('camino', 'w1', ['src/Plugins/Blog/Controllers/PostController.php']);

        $refusal = $this->gate()->refuse('implement', ['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php']);

        self::assertSame('no scaffold declares class «CommentController» in plugin «Blog» — filling is not creating; '
            . 'scaffold it first with `make`. Nothing ran and nobody was asked.', $refusal);
    }

    public function testATrialOfAnotherSessionIsNotNamed(): void
    {
        $this->trial('other', 'w9', ['src/Plugins/Blog/Controllers/CommentController.php']);

        $refusal = (string) $this->gate()->refuse('implement', ['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php']);

        self::assertStringNotContainsString('w9', $refusal, 'a promotion this session cannot be told to make');
        self::assertStringContainsString('scaffold it first with `make`', $refusal);
    }

    public function testOnlyATrialThisSessionRanIsNamedWhateverElseItsLedgerSaysOfAnother(): void
    {
        $this->trial('other', 'w9', ['src/Plugins/Blog/Controllers/CommentController.php']);
        // This session's ledger names that workspace too — it tried to throw it away — but never ran it.
        $this->sessions->recordTrialDiscard('camino', ['workspace' => 'w9']);

        $refusal = (string) $this->gate()->refuse('implement', ['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php']);

        self::assertStringNotContainsString('w9', $refusal);
        self::assertStringContainsString('scaffold it first with `make`', $refusal);
    }

    public function testATrialThatIsGoneIsNotNamed(): void
    {
        $this->trial('camino', 'w1', ['src/Plugins/Blog/Controllers/CommentController.php'])->discard();

        $refusal = (string) $this->gate()->refuse('implement', ['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php']);

        self::assertStringNotContainsString('w1', $refusal);
        self::assertStringContainsString('scaffold it first with `make`', $refusal);
    }

    public function testTheNewestTrialThatHoldsTheClassIsTheOneNamed(): void
    {
        $this->trial('camino', 'w1', ['src/Plugins/Blog/Controllers/CommentController.php']);
        $this->trial('camino', 'w2', ['src/Plugins/Blog/Controllers/PostController.php']);
        $this->trial('camino', 'w3', ['src/Plugins/Blog/Controllers/CommentController.php']);
        $this->trial('camino', 'w4', ['src/Plugins/Blog/Entities/Comment.php']);

        $refusal = (string) $this->gate()->refuse('implement', ['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php']);

        self::assertStringContainsString('sandbox:promote {"workspace":"w3"}', $refusal);
        self::assertStringNotContainsString('w1', $refusal);
    }

    public function testOnceTheTrialIsInTheHouseNothingIsForeknown(): void
    {
        mkdir($this->root . '/src/Plugins/Blog/Controllers', 0o700, true);
        file_put_contents($this->root . '/src/Plugins/Blog/Controllers/CommentController.php', "<?php\n");
        $this->trial('camino', 'w1', ['src/Plugins/Blog/Controllers/CommentController.php']);

        $refusal = $this->gate()->refuse('implement', ['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php']);

        self::assertStringNotContainsString('scaffold', (string) $refusal, 'the control: the class is in the house, so the call meets its own doors');
    }

    public function testTheRefusalIsInTheLedgerAsTheFailedResultOfTheCall(): void
    {
        $this->trial('camino', 'w1', ['src/Plugins/Blog/Controllers/CommentController.php']);
        $gate = $this->gate();

        $refusal = $gate->refuse('implement', ['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php // body']);

        $calls = $this->recordedCalls();
        self::assertCount(1, $calls, 'the call the house refused is a fact of the session');
        self::assertSame('implement', $calls[0]['tool']);
        self::assertFalse($calls[0]['ok']);
        self::assertSame($refusal, $calls[0]['result']);
        self::assertSame(['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php // body'], $calls[0]['arguments']);
    }

    public function testARefusalOfTheRecordedDoorIsInTheLedgerToo(): void
    {
        $seq = $this->sessions->recordToolCall('camino', 'implement', ['plugin' => 'Blog', 'class' => 'Blog'], 'ok');
        $arguments = ['plugin' => 'Blog', 'class' => 'Blog',
            'source' => ['session' => 'camino', 'seq' => $seq, 'sha256' => str_repeat('a', 64)], 'edits' => [['find' => 'a', 'replace' => 'b']]];

        $refusal = $this->gate()->refuse('edit', $arguments);

        $calls = array_values(array_filter($this->recordedCalls(), static fn (array $call): bool => $call['tool'] === 'edit'));
        self::assertSame('Recorded source is not a complete rejected producer for this plugin and class.', $refusal);
        self::assertCount(1, $calls);
        self::assertSame($refusal, $calls[0]['result']);
        self::assertFalse($calls[0]['ok']);
    }

    public function testAQuestionForAPersonIsNotRecordedAsAFailedCall(): void
    {
        mkdir($this->root . '/src/Plugins/Blog/Controllers', 0o700, true);
        file_put_contents($this->root . '/src/Plugins/Blog/Controllers/PostController.php', "<?php\n");

        self::assertNotNull($this->gate()->refuse('edit', ['plugin' => 'Blog', 'class' => 'PostController', 'edits' => []]));

        self::assertSame('target_not_named', $this->asked(), 'the control: this call waits for a person');
        self::assertSame([], $this->recordedCalls(), 'and a call that waits is not a call that failed');
    }

    public function testTheRefusalGoesBackToTheModelAndTheLegGoesOn(): void
    {
        $this->trial('camino', 'w1', ['src/Plugins/Blog/Controllers/CommentController.php']);

        try {
            $this->bridge()->callTool('implement', ['plugin' => 'Blog', 'class' => 'CommentController', 'content' => '<?php']);
            self::fail('a class the house does not have was filled');
        } catch (ToolCallRefused $refused) {
            self::assertStringContainsString('sandbox:promote {"workspace":"w1"}', $refused->getMessage());
            self::assertTrue($refused->optionRemoved, 'the loop returns this reason to the model: there is no gate to walk around');
        }
        self::assertSame([], $this->ran, 'nothing ran');
        self::assertCount(1, $this->recordedCalls(), 'and it is recorded once, by the gate — not again by the bridge');
    }

    public function testACallThatWaitsForAPersonStillEndsTheLeg(): void
    {
        mkdir($this->root . '/src/Plugins/Blog/Controllers', 0o700, true);
        file_put_contents($this->root . '/src/Plugins/Blog/Controllers/PostController.php', "<?php\n");

        try {
            $this->bridge()->callTool('edit', ['plugin' => 'Blog', 'class' => 'PostController', 'edits' => []]);
            self::fail('a call that waits for a person ran');
        } catch (ToolCallRefused $refused) {
            self::assertFalse($refused->optionRemoved, 'the control: a person decides this one, so the leg stops in front of it');
        }
    }

    public function testTheMarkDoesNotOutliveTheCallItWasFor(): void
    {
        $this->trial('camino', 'w1', ['src/Plugins/Blog/Controllers/CommentController.php']);
        mkdir($this->root . '/src/Plugins/Blog/Controllers', 0o700, true);
        file_put_contents($this->root . '/src/Plugins/Blog/Controllers/PostController.php', "<?php\n");
        $gate = $this->gate();

        $gate->refuse('edit', ['plugin' => 'Blog', 'class' => 'CommentController', 'edits' => []]);
        self::assertTrue($gate->refusalWasForeknown('edit'));
        self::assertFalse($gate->refusalWasForeknown('implement'), 'it is the mark of one tool');

        $gate->refuse('edit', ['plugin' => 'Blog', 'class' => 'PostController', 'edits' => []]);
        self::assertFalse($gate->refusalWasForeknown('edit'), 'the next call of the same tool waits for a person, and says so');
    }

    /** @param list<string> $files paths the trial adds, relative to the app root */
    private function trial(string $session, string $id, array $files): TrialWorkspace
    {
        $workspace = TrialWorkspace::materialize($this->root, $id, __FILE__);
        $report = [];
        foreach ($files as $file) {
            @mkdir(\dirname($workspace->copy . '/' . $file), 0o700, true);
            file_put_contents($workspace->copy . '/' . $file, "<?php\n");
            $report[$file] = 'added';
        }
        $this->sessions->recordTrialRun($session, ['workspace' => $id, 'operation' => 'make', 'report' => $report]);

        return $workspace;
    }

    private function gate(): SessionToolGate
    {
        $session = $this->sessions->load('camino');
        self::assertNotNull($session);

        return new SessionToolGate(
            $this->sessions,
            $session,
            [$this->lander('implement', ImplementHandler::class), RecordedEdit::operation($this->lander('edit', EditHandler::class))],
            petition: 'continue',
            trialRouter: new TrialRouter($this->root, new TrialRunner(bwrap: '/nonexistent/bwrap'), __FILE__),
        );
    }

    private function bridge(): ConsentBridge
    {
        $gate = $this->gate();
        $registry = new ToolRegistry(new NullLogger());
        foreach (['implement', 'edit'] as $name) {
            $registry->register(
                $name,
                'Lands a class.',
                ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string'], 'class' => ['type' => 'string']]],
                function () use ($name): array {
                    $this->ran[] = $name;

                    return ['ok' => true];
                },
                new ToolOptions(mutating: true, scopes: ['work:write']),
            );
        }

        return new ConsentBridge(
            $registry,
            gate: $gate,
            recorder: $gate,
            authority: new ToolContext(principal: 'fixture', scopes: ['work:write']),
        );
    }

    /** @param class-string $handler */
    private function lander(string $name, string $handler): Operation
    {
        return new Operation(
            $name,
            'Lands a class.',
            [$handler, 'handle'],
            inputSchema: ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string'], 'class' => ['type' => 'string']]],
            mutating: true,
            namedTarget: 'class',
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::ManualRecovery,
                Authority::WriteAsUser,
                escalatesOn: ['class'],
                subject: Subject::Executable,
            ),
        );
    }

    private function asked(): ?string
    {
        return $this->sessions->load('camino')?->question?->reason;
    }

    /** @return list<array{tool: string, ok: bool, result: string, arguments: array<string, mixed>}> */
    private function recordedCalls(): array
    {
        $calls = [];
        foreach ($this->sessions->stream('camino') as $event) {
            \assert($event instanceof Event);
            if ($event->type === 'session.tool_called') {
                $calls[] = [
                    'tool' => (string) $event->payload['tool'],
                    'ok' => (bool) $event->payload['ok'],
                    'result' => (string) $event->payload['result'],
                    'arguments' => (array) $event->payload['arguments'],
                ];
            }
        }

        return $calls;
    }
}
