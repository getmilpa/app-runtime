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

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\AppliedWhenVerified;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Command\InvocationContext;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A producer call says whether the house applies its trial once it verifies: `apply: "when_verified"`
 * (greenhouse decisions/0578, evidence/1115).
 *
 * Measured on the BV-4 run with the real resident (evidence/1109 §3): four model calls did nothing but copy
 * `to_apply: {operation: sandbox:promote, arguments: {workspace: …}}` out of the result they had just been handed —
 * 103,863 tokens and 144 s. But «every verified trial is promoted» is not what residents do: in 13 recorded runs
 * they did not apply 7 of 93 trials that had not failed. So the intention to apply travels in the call itself, and
 * without it nothing changes.
 *
 * @guards with the parameter and a trial that verified, the result says the house applies it and what it calls;
 *         without the parameter, or with a trial that failed, does not boot, did not verify or changed nothing, the
 *         result is what it was; the producer never receives the parameter; the house continues only with the
 *         promotion of the workspace that same result names; a trial already promoted answers so, without an error
 *         and without writing again
 *
 * @refuses applying what nobody asked to apply; promoting another workspace; a second promotion that lands twice
 *
 * @subject-in milpa/app-runtime
 */
final class TheCallSaysWhetherTheHouseAppliesItTest extends TestCase
{
    private string $root;
    private string $bwrap;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-apply-when-verified-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Plugins/Blog', 0o755, true);
        $this->bwrap = $this->root . '-bwrap';
        file_put_contents($this->bwrap, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($this->bwrap, 0o755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->bwrap);
    }

    public function testAVerifiedTrialTheCallAskedToApplySaysTheHouseAppliesIt(): void
    {
        $result = $this->registry()->call('make', ['plugin' => 'Blog', 'apply' => 'when_verified']);

        self::assertTrue($result->success, (string) $result->error);
        $workspace = $result->data['workspace'];
        self::assertSame(['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $workspace]], $result->data['applies']);
        self::assertFalse($result->data['applied'], 'nothing has landed yet: the house applies it next, through the door');
        self::assertSame($result->data['applies'], $result->data['to_apply'], 'the call that applies it is still said: the model can make it if the house does not get to');
        self::assertStringContainsString('the house calls sandbox:promote for it next', $result->data['note']);
        self::assertStringContainsString('Do not call it yourself', $result->data['note']);
    }

    public function testTheTrialLayerRemembersWhichTrialItSaidItAppliesAndForgetsOnceAsked(): void
    {
        $registry = $this->registry();
        $registry->houseAppliesWhenAsked(true);

        $asked = $registry->call('make', ['plugin' => 'Blog', 'apply' => 'when_verified'])->data['workspace'];
        $notAsked = $registry->call('make', ['plugin' => 'Shop'])->data['workspace'];

        self::assertFalse($registry->saidItApplies($notAsked), 'a trial whose call did not ask');
        self::assertFalse($registry->saidItApplies('w0000000000000000'), 'a trial it never ran');
        self::assertTrue($registry->saidItApplies($asked));
        self::assertFalse($registry->saidItApplies($asked), 'asked once: the answer is spent');
    }

    public function testTheProducerNeverReceivesTheParameter(): void
    {
        $result = $this->registry()->call('make', ['plugin' => 'Blog', 'apply' => 'when_verified']);

        self::assertSame(['plugin' => 'Blog'], $result->data['output']['received']);
    }

    public function testWithoutTheParameterNothingIsAppliedAndTheCallThatAppliesIsStillSaid(): void
    {
        $result = $this->registry()->call('make', ['plugin' => 'Blog']);

        self::assertTrue($result->success);
        self::assertArrayNotHasKey('applies', $result->data);
        self::assertSame(['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $result->data['workspace']]], $result->data['to_apply']);
        self::assertStringContainsString('To apply the change, call sandbox:promote', $result->data['note']);
        self::assertStringNotContainsString('the house calls', $result->data['note']);
    }

    /**
     * THE NOTE SAYS THE RULE (greenhouse decisions/0578, «option 1», evidence/1121). The resident does what the note
     * of a trial says: it said «call sandbox:promote», and of 15 trials that changed something the resident asked
     * the house to apply 2. The note now also states what the house does when the producer call asks — as a rule of
     * the house, not an instruction, and with the call that applies still there.
     */
    public function testTheNoteOfATrialNobodyAskedToApplyStatesTheRule(): void
    {
        $result = $this->registry()->call('make', ['plugin' => 'Blog']);

        self::assertSame('A producer called with apply: "when_verified" is applied by the house once its trial verifies.', AppliedWhenVerified::RULE);
        self::assertStringEndsWith(' ' . AppliedWhenVerified::RULE, $result->data['note']);
        self::assertStringContainsString('To apply the change, call sandbox:promote', $result->data['note'], 'the call that applies it is still said, first');
        self::assertArrayNotHasKey('applies', $result->data, 'stating the rule applies nothing');
        self::assertFalse($this->registry()->saidItApplies($result->data['workspace']));
    }

    public function testATrialThatWasAskedAndDidNotVerifyStillSaysItIsNotAppliedAndTheRule(): void
    {
        $result = $this->registry()->call('make', ['plugin' => 'Blog', 'apply' => 'when_verified', 'fixture' => 'unverified']);

        self::assertStringContainsString('is NOT applied to the app yet', $result->data['note']);
        self::assertStringEndsWith(' ' . AppliedWhenVerified::RULE, $result->data['note'], 'the rule says why: it applies a trial that verifies');
    }

    public function testATrialTheHouseAppliesDoesNotRepeatTheRule(): void
    {
        $result = $this->registry()->call('make', ['plugin' => 'Blog', 'apply' => 'when_verified']);

        self::assertStringContainsString('the house calls sandbox:promote for it next', $result->data['note']);
        self::assertStringNotContainsString(AppliedWhenVerified::RULE, $result->data['note']);
    }

    public function testATrialThatChangedNothingSaysNoRule(): void
    {
        $result = $this->registry()->call('make', ['plugin' => 'Blog', 'fixture' => 'no-change']);

        self::assertStringContainsString('there is nothing to apply', $result->data['note']);
        self::assertStringNotContainsString(AppliedWhenVerified::RULE, $result->data['note']);
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('notApplied')]
    public function testTheHouseAppliesNothingElse(array $arguments, bool $houseApplies = true): void
    {
        $result = $this->registry($houseApplies)->call('make', $arguments);

        self::assertArrayNotHasKey('applies', $result->data ?? []);
        self::assertNull(AppliedWhenVerified::follows('make', $arguments, $result->success ? $result->data : (string) $result->error));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1?: bool}> */
    public static function notApplied(): iterable
    {
        yield 'another word for apply' => [['plugin' => 'Blog', 'apply' => 'always']];
        yield 'apply: true' => [['plugin' => 'Blog', 'apply' => true]];
        yield 'a trial that failed' => [['plugin' => 'Blog', 'apply' => 'when_verified', 'fixture' => 'failed']];
        yield 'a trial whose producer says it is not ok' => [['plugin' => 'Blog', 'apply' => 'when_verified', 'fixture' => 'says-not-ok']];
        yield 'a trial the house does not boot with' => [['plugin' => 'Blog', 'apply' => 'when_verified', 'fixture' => 'unbootable']];
        yield 'a trial whose verification failed' => [['plugin' => 'Blog', 'apply' => 'when_verified', 'fixture' => 'unverified']];
        yield 'a trial with a required postcondition missing' => [['plugin' => 'Blog', 'apply' => 'when_verified', 'fixture' => 'missing-postconditions']];
        yield 'a trial that changed nothing' => [['plugin' => 'Blog', 'apply' => 'when_verified', 'fixture' => 'no-change']];
        yield 'a leg whose loop cannot continue' => [['plugin' => 'Blog', 'apply' => 'when_verified'], false];
    }

    public function testAnUnfinishedMultipartPartIsNeverApplied(): void
    {
        mkdir($this->root . '/src/Plugins/Owned/Services', 0o755, true);
        file_put_contents($this->root . '/src/Plugins/Owned/Services/Renderer.php', '<?php // Live source');
        $inner = new ToolRegistry(new NullLogger());
        $inner->register('implement', 'Author', ['type' => 'object'], static fn (): array => ['ok' => true]);
        $operation = new Operation(name: 'implement', description: 'Author', handler: static fn (): array => ['ok' => true], mutating: true, effects: new EffectProfile(
            mutation: Mutation::Persistent,
            externality: Externality::None,
            reversibility: Reversibility::Compensatable,
            authority: Authority::WriteAsUser,
            subject: Subject::Executable,
        ));
        $registry = new TrialAwareRegistry($inner, new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/trial-partial-runner.php'), [$operation]);
        $registry->houseAppliesWhenAsked(true);

        $result = $registry->call('implement', ['fixture' => 'start', 'mode' => 'start', 'apply' => 'when_verified']);

        self::assertTrue($result->success, (string) $result->error);
        self::assertStringContainsString('The accepted part exists only in this trial', $result->data['note'], 'a part is not the work: the model promotes it, as before');
        self::assertArrayNotHasKey('applies', $result->data);
        self::assertStringNotContainsString(AppliedWhenVerified::RULE, $result->data['note'], 'the rule is not said of a part: the house would not apply it');
        self::assertStringNotContainsString(AppliedWhenVerified::RULE, $registry->call('implement', ['fixture' => 'start', 'mode' => 'start'])->data['note']);
    }

    public function testAProducerWithAnApplyOfItsOwnKeepsIt(): void
    {
        $inner = new ToolRegistry(new NullLogger());
        $inner->register('make', 'Scaffold', ['type' => 'object', 'properties' => ['apply' => ['type' => 'boolean', 'description' => 'the producer\'s own']]], static fn (): array => ['ok' => true]);
        $operation = new Operation(name: 'make', description: 'Scaffold', handler: static fn (): array => ['ok' => true], mutating: true, effects: new EffectProfile(
            mutation: Mutation::Persistent,
            externality: Externality::None,
            reversibility: Reversibility::Compensatable,
            authority: Authority::WriteAsUser,
            subject: Subject::Executable,
        ));
        $registry = new TrialAwareRegistry($inner, new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/trial-apply-runner.php'), [$operation]);
        $registry->houseAppliesWhenAsked(true);

        self::assertSame(['type' => 'boolean', 'description' => 'the producer\'s own'], $registry->getToolSummaries()[0]['inputSchema']['properties']['apply']);
        $result = $registry->call('make', ['plugin' => 'Blog', 'apply' => 'when_verified']);
        self::assertSame(['plugin' => 'Blog', 'apply' => 'when_verified'], $result->data['output']['received'], 'the parameter is the producer\'s: it reaches it');
        self::assertArrayNotHasKey('applies', $result->data);
        self::assertStringNotContainsString(AppliedWhenVerified::RULE, $result->data['note'], 'the rule is not said where `apply` means something else');
    }

    public function testALoopThatCannotContinueStillStripsTheParameterAndSaysTheModelApplies(): void
    {
        $result = $this->registry(houseApplies: false)->call('make', ['plugin' => 'Blog', 'apply' => 'when_verified']);

        self::assertTrue($result->success);
        self::assertSame(['plugin' => 'Blog'], $result->data['output']['received']);
        self::assertStringContainsString('To apply the change, call sandbox:promote', $result->data['note']);
        self::assertStringNotContainsString(AppliedWhenVerified::RULE, $result->data['note'], 'a house that cannot do it does not state the rule');
        self::assertStringNotContainsString(AppliedWhenVerified::RULE, $this->registry(houseApplies: false)->call('make', ['plugin' => 'Shop'])->data['note']);
    }

    public function testTheCatalogueOffersTheParameterOnlyWhereTheHouseWillHonourIt(): void
    {
        $offered = static fn (TrialAwareRegistry $registry): array => array_column($registry->getToolSummaries(), 'inputSchema', 'name');

        $with = $offered($this->registry());
        self::assertSame(['type' => 'string', 'enum' => ['when_verified']], array_diff_key($with['make']['properties']['apply'], ['description' => 1]));
        self::assertStringContainsString('sandbox:promote', $with['make']['properties']['apply']['description']);
        self::assertArrayNotHasKey('apply', (array) ($with['source_read']['properties'] ?? []), 'a read is not applied');
        self::assertArrayNotHasKey('apply', (array) ($with['sandbox_promote']['properties'] ?? []), 'the promotion is the applying');
        self::assertArrayNotHasKey('required', $with['make'], 'it is never required');

        $without = $offered($this->registry(houseApplies: false));
        self::assertArrayNotHasKey('apply', (array) ($without['make']['properties'] ?? []), 'a loop that cannot continue does not promise to');
    }

    public function testTheHouseContinuesWithThePromotionOfThatWorkspaceAndNothingElse(): void
    {
        $arguments = ['plugin' => 'Blog', 'apply' => 'when_verified'];
        $result = $this->registry()->call('make', $arguments)->data;

        self::assertSame([['name' => 'sandbox_promote', 'arguments' => ['workspace' => $result['workspace']]]], AppliedWhenVerified::follows('make', $arguments, $result));

        self::assertNull(AppliedWhenVerified::follows('make', ['plugin' => 'Blog'], $result), 'the call did not ask');
        self::assertNull(AppliedWhenVerified::follows('make', $arguments, array_diff_key($result, ['applies' => 1])), 'the house did not say it applies');
        self::assertNull(AppliedWhenVerified::follows('make', $arguments, ['applies' => ['operation' => 'sandbox:undo', 'arguments' => ['workspace' => $result['workspace']]]] + $result), 'only a promotion follows');
        self::assertNull(AppliedWhenVerified::follows('make', $arguments, ['applies' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => 'another']]] + $result), 'only the workspace this result is about');
        self::assertNull(AppliedWhenVerified::follows('make', $arguments, ['applied' => true] + $result), 'what already landed is not applied again');
        self::assertNull(AppliedWhenVerified::follows('make', $arguments, ['ran_in_trial' => false] + $result));
        self::assertNull(AppliedWhenVerified::follows('make', $arguments, json_encode($result)), 'a result that is text is not read for calls');
        self::assertNull(AppliedWhenVerified::follows('sandbox_promote', ['workspace' => $result['workspace'], 'apply' => 'when_verified'], $result), 'a promotion is not continued');
    }

    public function testTheHouseRecordsThatItContinuedAndAfterWhichCall(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Build the blog');
        $sessions->recordToolCall('bv', 'source_read', ['path' => 'a'], '{}');
        $made = $sessions->recordToolCall('bv', 'make', ['plugin' => 'Blog', 'apply' => 'when_verified'], '{"ran_in_trial":true}', true, true);

        AppliedWhenVerified::continued($events, $sessions->stream('bv'), 'bv', [['name' => 'sandbox_promote', 'arguments' => ['workspace' => 'w1']]], 'key:SEAT');

        $facts = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === AppliedWhenVerified::CONTINUED));
        self::assertCount(1, $facts);
        self::assertSame(['tool' => 'sandbox_promote', 'arguments' => ['workspace' => 'w1'], 'after' => $made, 'because' => 'apply: when_verified', 'as' => 'key:SEAT'], $facts[0]->payload);
    }

    public function testATrialAlreadyPromotedAnswersSoWithoutAnErrorAndWithoutWritingAgain(): void
    {
        $trial = TrialWorkspace::materialize($this->root, 'wapplied', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($trial->copy . '/src/Plugins/Blog/Blog.php', "<?php // the plugin\n");
        $first = $this->promote('wapplied');
        self::assertTrue($first['ok'], json_encode($first) ?: '');
        self::assertSame(['src/Plugins/Blog/Blog.php'], $first['promoted']);
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', "<?php // edited after the promotion\n");

        $again = $this->promote('wapplied');

        self::assertTrue($again['ok'], json_encode($again) ?: '');
        self::assertTrue($again['already_promoted']);
        self::assertSame(['src/Plugins/Blog/Blog.php'], $again['paths']);
        self::assertArrayNotHasKey('promoted', $again, 'nothing landed now: it is not a change of the house');
        self::assertArrayNotHasKey('evidence', $again, 'and it earns no second receipt');
        self::assertStringContainsString('already promoted', $again['note']);
        self::assertSame("<?php // edited after the promotion\n", file_get_contents($this->root . '/src/Plugins/Blog/Blog.php'), 'nothing was written again');
    }

    public function testATrialThatNeverExistedIsStillAnError(): void
    {
        $never = $this->promote('wnever');

        self::assertFalse($never['ok']);
        self::assertSame('no trial «wnever» to promote', $never['error']);
    }

    /**
     * Through the real door of a leg: where the loop can continue, the leg's catalogue offers the parameter on its
     * producers, and what the house continues with is recorded as the seat's, after the producer's call.
     */
    public function testALegOffersTheParameterAndRecordsWhatItContinuesWith(): void
    {
        [$operations, $sessions, $loop] = $this->house(new ContinuationRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class)));

        self::leg($operations);

        self::assertNotNull($loop->continuation, 'the leg handed its loop a continuation');
        self::assertTrue(self::honours($loop), 'and told its registry to offer and honour the parameter');

        $made = $sessions->recordToolCall('bv', 'make', ['plugin' => 'Blog', 'apply' => 'when_verified'], '{}', true, true);
        $result = ['ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'applies' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => 'w1']]];

        // A RESULT THAT SAYS SO ON ITS OWN IS NOT ENOUGH: what any tool answers is data, and a result shaped like a
        // verified trial — from a tool the house does not confine, naming a trial of somebody else — applies nothing.
        self::assertNull(($loop->continuation)('make', ['plugin' => 'Blog', 'apply' => 'when_verified'], $result), 'the leg\'s own trial layer never said it applies «w1»');
        self::assertSame([], array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === AppliedWhenVerified::CONTINUED));

        // Only the trial this leg's trial layer just ran, verified and said it applies.
        (new \ReflectionProperty(TrialAwareRegistry::class, 'applies'))->setValue(self::registryOf($loop), ['w1' => true]);
        self::assertSame([['name' => 'sandbox_promote', 'arguments' => ['workspace' => 'w1']]], ($loop->continuation)('make', ['plugin' => 'Blog', 'apply' => 'when_verified'], $result));
        self::assertNull(($loop->continuation)('make', ['plugin' => 'Blog', 'apply' => 'when_verified'], $result), 'and once: what it said is spent');
        $facts = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === AppliedWhenVerified::CONTINUED));
        self::assertCount(1, $facts);
        self::assertSame($made, $facts[0]->payload['after']);
        self::assertSame('key:' . self::SEAT, $facts[0]->payload['as']);

        self::assertNull(($loop->continuation)('make', ['plugin' => 'Blog'], $result), 'a call that did not ask is not continued');
        self::assertCount(1, array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === AppliedWhenVerified::CONTINUED), 'and nothing is recorded for it');
    }

    public function testALegWhoseLoopCannotContinuePromisesNothing(): void
    {
        [$operations, , $loop] = $this->house(new LoopThatOnlyAnswers($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class)));

        self::leg($operations);

        self::assertInstanceOf(TrialAwareRegistry::class, self::registryOf($loop));
        self::assertFalse(self::honours($loop), 'the registry offers and honours nothing its loop cannot play');
    }

    public function testAHouseWithoutTrialsPromisesNothing(): void
    {
        [$operations, , $loop] = $this->house(new ContinuationRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class)), trials: false);

        self::leg($operations);

        self::assertNull($loop->continuation);
        self::assertNotInstanceOf(TrialAwareRegistry::class, self::registryOf($loop));
    }

    /** The registry the leg's governed door calls through. */
    private static function registryOf(LoopThatOnlyAnswers $loop): ?ToolRegistry
    {
        return $loop->door === null ? null : (new \ReflectionProperty(GatedToolCalls::class, 'registry'))->getValue($loop->door);
    }

    /** Whether that registry was told its leg applies what a call asks to. */
    private static function honours(LoopThatOnlyAnswers $loop): bool
    {
        $registry = self::registryOf($loop);

        return $registry instanceof TrialAwareRegistry && (new \ReflectionProperty(TrialAwareRegistry::class, 'houseApplies'))->getValue($registry) === true;
    }

    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';

    /**
     * A fixture house and a leg of it whose loop is the test's.
     *
     * @return array{0: ApplyFixtureOperations, 1: SessionStore, 2: LoopThatOnlyAnswers}
     */
    private function house(LoopThatOnlyAnswers $loop, bool $trials = true): array
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Build the blog', \Milpa\Agent\AutonomyMode::Auto, by: new \Milpa\Agent\Principal('key:' . self::SEAT, true));
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(\Milpa\EventStore\EventStoreInterface::class, $events);
        $container->registerService(\Milpa\Runtime\Kernel::class, \Milpa\Runtime\Kernel::boot(['root' => $this->root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]));
        $operations = new ApplyFixtureOperations($container);
        (new \ReflectionProperty(AgentOperations::class, 'trialRouterMemo'))->setValue(
            $operations,
            $trials ? new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/trial-apply-runner.php') : null,
        );
        $operations->loop = $loop;

        return [$operations, $sessions, $loop];
    }

    private static function leg(AgentOperations $operations): void
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    $result = ($operation->handler)(['prompt' => 'continue', 'session' => 'bv'], new InvocationContext('key:' . self::SEAT, true));
                    self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'the leg failed'));
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
    }

    /** @return array<string, mixed> */
    private function promote(string $workspace): array
    {
        $promote = (new TrialOperations(new DIContainer(), root: $this->root))->operations()[0];
        self::assertSame('sandbox:promote', $promote->name);

        return ($promote->handler)(['workspace' => $workspace], null, new ToolContext(principal: 'worker', channel: 'cli', scopes: ['*']));
    }

    private function registry(bool $houseApplies = true): TrialAwareRegistry
    {
        $inner = new ToolRegistry(new NullLogger());
        $never = static function (): never {
            throw new \RuntimeException('The host handler must not run');
        };
        $inner->register('make', 'Scaffold', ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']]], $never);
        $inner->register('source_read', 'Read', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], static fn (): array => ['ok' => true]);
        $inner->register('sandbox_promote', 'Promote', ['type' => 'object', 'properties' => ['workspace' => ['type' => 'string']]], static fn (): array => ['ok' => true]);
        $writes = new EffectProfile(mutation: Mutation::Persistent, externality: Externality::None, reversibility: Reversibility::Compensatable, authority: Authority::WriteAsUser, subject: Subject::Executable);
        $operations = [
            new Operation(name: 'make', description: 'Scaffold', handler: static fn (): array => ['ok' => true], mutating: true, effects: $writes),
            new Operation(name: 'source:read', description: 'Read', handler: static fn (): array => ['ok' => true], effects: EffectProfile::readOnly()),
            new Operation(name: 'sandbox:promote', description: 'Promote', handler: static fn (): array => ['ok' => true], mutating: true, effects: $writes),
        ];
        $router = new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/trial-apply-runner.php');
        $registry = new TrialAwareRegistry($inner, $router, $operations);
        $registry->houseAppliesWhenAsked($houseApplies);

        return $registry;
    }
}

/** A leg whose orchestrator is the test's. */
final class ApplyFixtureOperations extends AgentOperations
{
    public ?LoopThatOnlyAnswers $loop = null;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->loop !== null);
        $this->loop->door = $cliente;

        return $this->loop;
    }
}

/** The loop of a gateway that cannot continue a call: it answers, and remembers the catalogue it was given at run time. */
class LoopThatOnlyAnswers extends AgentOrchestrator
{
    public ?GatedToolCalls $door = null;
    /** @var list<array<string, mixed>> */
    public array $offered = [];

    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        $this->offered = $this->door?->getToolSummaries() ?? [];

        return 'Continued.';
    }
}

/** The loop of a gateway that can: it also remembers the continuation it was handed. */
final class ContinuationRecorder extends LoopThatOnlyAnswers
{
    public ?\Closure $continuation = null;

    public function setContinuation(?callable $continuation): self
    {
        $this->continuation = $continuation === null ? null : $continuation(...);

        return $this;
    }
}
