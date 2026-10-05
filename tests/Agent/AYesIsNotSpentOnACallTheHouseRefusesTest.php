<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
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
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A person's yes is not spent on a call the house already refuses (greenhouse decisions/0569).
 *
 * Rod's second live run (evidence/1099, seq 199–213): the resident called `edit PostController` with a
 * recorded source, the intent contract asked Rod «Confirm edit on «PostController»?», Rod said yes, and the
 * approved call died on «Recorded producer has no attributable rejection diagnostic» — a refusal the house
 * could read from its own record before asking. The gate now reads it first.
 */
final class AYesIsNotSpentOnACallTheHouseRefusesTest extends TestCase
{
    private string $root;
    private SessionStore $sessions;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-yes-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src/Plugins/Blog/Controllers', 0o700, true);
        mkdir($this->root . '/tests/Plugins/Blog', 0o700, true);
        file_put_contents($this->root . '/src/Plugins/Blog/Controllers/PostController.php', "<?php\n");
        file_put_contents($this->root . '/tests/Plugins/Blog/PostControllerTest.php', "<?php\n");
        $this->sessions = new SessionStore(new InMemoryEventStore());
        // The goal names the blog, never the class: every `edit` below meets the intent contract.
        $this->sessions->start('camino', 'Build a tiny blog that serves /blog.', AutonomyMode::Auto);
        $this->sessions->start('other', 'Another seat.', AutonomyMode::Auto);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function gate(bool $house = true): SessionToolGate
    {
        return new SessionToolGate(
            $this->sessions,
            $this->sessions->load('camino'),
            [RecordedEdit::operation($this->lander('edit', EditHandler::class))],
            petition: 'continue',
            trialRouter: $house ? new TrialRouter($this->root, new TrialRunner(bwrap: '/nonexistent/bwrap'), __FILE__) : null,
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

    public function testAnEditOnAClassTheGoalDoesNotNameStillAsks(): void
    {
        self::assertNotNull($this->gate()->refuse('edit', ['plugin' => 'Blog', 'class' => 'PostController', 'edits' => []]));
        self::assertSame('target_not_named', $this->asked(), 'the control: this is the question Rod answered');
    }

    public function testARecordedSourceTheHouseRefusesIsRefusedBeforeAnyoneIsAsked(): void
    {
        $seq = $this->sessions->recordToolCall('camino', 'implement', ['plugin' => 'Blog', 'class' => 'PostController'], 'ok');

        $refusal = $this->gate()->refuse('edit', ['plugin' => 'Blog', 'class' => 'PostController',
            'source' => ['session' => 'camino', 'seq' => $seq, 'sha256' => str_repeat('a', 64)], 'edits' => [['find' => 'a', 'replace' => 'b']]]);

        self::assertSame('Recorded source is not a complete rejected producer for this plugin and class.', $refusal);
        self::assertNull($this->asked(), 'nobody was asked to confirm a call the house refuses');
    }

    public function testAnotherSessionsRecordIsNotReadToDecideWhetherToAsk(): void
    {
        $seq = $this->sessions->recordToolCall('other', 'implement', ['plugin' => 'Blog', 'class' => 'PostController'], 'ok');

        $this->gate()->refuse('edit', ['plugin' => 'Blog', 'class' => 'PostController',
            'source' => ['session' => 'other', 'seq' => $seq, 'sha256' => str_repeat('a', 64)], 'edits' => [['find' => 'a', 'replace' => 'b']]]);

        self::assertSame('target_not_named', $this->asked(), 'whether this seat may read that record is a permission, judged at its own door');
    }

    public function testWithoutAHouseToReadTheGateAsksAsBefore(): void
    {
        $this->gate(house: false)->refuse('edit', ['plugin' => 'Blog', 'class' => 'PostController',
            'source' => ['session' => 'camino', 'seq' => 1, 'sha256' => str_repeat('a', 64)], 'edits' => [['find' => 'a', 'replace' => 'b']]]);

        self::assertSame('target_not_named', $this->asked());
    }

    public function testAClassNoScaffoldDeclaresIsRefusedBeforeAnyoneIsAsked(): void
    {
        if (!method_exists(ImplementHandler::class, 'scaffold')) {
            self::markTestSkipped('The installed DevTools keeps its scaffold lookup private; the gate foreknows nothing about it.');
        }

        $refusal = $this->gate()->refuse('edit', ['plugin' => 'Blog', 'class' => 'CommentController', 'edits' => []]);

        self::assertSame('no scaffold declares class «CommentController» in plugin «Blog» — editing is not creating; '
            . 'scaffold it first with `make`. Nothing ran and nobody was asked.', $refusal);
        self::assertNull($this->asked());
    }

    public function testAPluginsTestClassExistsSoTheQuestionAboutItIsARealOne(): void
    {
        if (!method_exists(ImplementHandler::class, 'scaffold')) {
            self::markTestSkipped('The installed DevTools keeps its scaffold lookup private; the gate foreknows nothing about it.');
        }

        self::assertNotNull($this->gate()->refuse('edit', ['plugin' => 'Blog', 'class' => 'PostControllerTest', 'edits' => []]));
        self::assertSame('target_not_named', $this->asked(), 'D2 of evidence/1081: the edit this yes approves now lands (devtools)');
    }

    public function testAPluginThatDoesNotExistAndAPathShapedNameAreLeftToTheirOwnDoors(): void
    {
        $this->gate()->refuse('edit', ['plugin' => 'Shop', 'class' => 'CartController', 'edits' => []]);
        self::assertSame('target_not_named', $this->asked(), 'the frontier speaks about a plugin nobody made (decisions/0496)');
    }
}
