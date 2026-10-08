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

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\AppliedTrials;
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
 * The house applies the verified trial of an operation a person admitted (greenhouse decisions/0586).
 *
 * A producer runs in a trial, and applying what it left is a second call the model buys with an inference to copy an
 * identifier it was just handed. Asking the resident to say it wants it applied did not work: it said so in 2 of 27
 * trials (decisions/0578, evidence/1121). So it is written down once, per operation: of four operations the
 * verified trial IS the work — the resident applied 84 of 84 without ever looking at the copy — and a house applies
 * it on its own for the ones a person of that house admitted.
 *
 * @guards the verified trial of an admitted operation says the house applies it, and the house continues with the
 *         promotion of that very trial and nothing else; an operation nobody admitted, or that cannot be admitted,
 *         is left exactly as it was; so is a trial that failed, does not boot, did not verify or changed nothing;
 *         a withdrawal holds from the next call and an admission from the next leg; the catalogue says it only of
 *         what the house will honour; a trial already promoted answers so, without an error and without writing
 *         again; a promotion never carries the house's list
 *
 * @refuses applying the trial of an operation nobody admitted; applying on the strength of a result alone;
 *          promoting another workspace; a second promotion that lands twice; a seat admitting itself through a trial
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseAppliesTheVerifiedTrialOfAnAdmittedOperationTest extends TestCase
{
    private const PERSON = 'key:1111111111111111111111111111111111111111';

    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';

    private string $root;
    private string $bwrap;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-applied-trials-' . bin2hex(random_bytes(6));
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

    public function testTheVerifiedTrialOfAnAdmittedOperationSaysTheHouseAppliesIt(): void
    {
        $result = $this->registry(['plugins.register'])->call('plugins_register', ['name' => 'Blog']);

        self::assertTrue($result->success, (string) $result->error);
        $workspace = $result->data['workspace'];
        self::assertSame(['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $workspace]], $result->data['applies']);
        self::assertFalse($result->data['applied'], 'nothing has landed yet: the house applies it next, through the door');
        self::assertArrayNotHasKey('to_apply', $result->data, 'the call is no longer the model\'s to make');
        self::assertArrayNotHasKey('to_discard', $result->data);
        self::assertStringContainsString('The house applies the verified trial of plugins.register', $result->data['note']);
        self::assertStringContainsString('it calls sandbox:promote for it next', $result->data['note']);
        self::assertStringContainsString('Do not call it yourself', $result->data['note']);
        self::assertStringNotContainsString('NOT applied to the app yet', $result->data['note']);
        self::assertSame('ran in a trial and verified — the house applies it next', $result->message, 'the one line a reader sees first does not send the model to promote it');
        self::assertSame(['name' => 'Blog'], $result->data['output']['received'], 'the producer received what the model sent, and nothing else');
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('admissible')]
    public function testEachOfTheFourIsAppliedWhenAdmitted(string $admitted, string $tool, array $arguments): void
    {
        $registry = $this->registry([$admitted]);

        $result = $registry->call($tool, $arguments);

        self::assertArrayHasKey('applies', $result->data, "{$admitted} is admitted and its trial verified");
        self::assertSame($admitted, $registry->saidItApplies($result->data['workspace']));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function admissible(): iterable
    {
        yield 'plugins.register' => ['plugins.register', 'plugins_register', ['name' => 'Blog']];
        yield 'entity:seed' => ['entity:seed', 'entity_seed', ['entity' => 'Post']];
        yield 'make what=page' => ['make what=page', 'make', ['what' => 'page', 'plugin' => 'Blog', 'name' => 'Home']];
        yield 'make what=plugin' => ['make what=plugin', 'make', ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog']];
        yield 'make what=operation' => ['make what=operation', 'make', ['what' => 'operation', 'plugin' => 'Blog', 'name' => 'PublishPost', 'entity' => 'Post']];
        yield 'make what=entity' => ['make what=entity', 'make', ['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Post']];
    }

    public function testTheSixAndNoOther(): void
    {
        self::assertSame(['plugins.register', 'entity:seed', 'make what=page', 'make what=plugin', 'make what=operation', 'make what=entity'], AppliedTrials::admissible());
        self::assertSame('make what=operation', AppliedTrials::key('make', ['what' => 'operation', 'plugin' => 'Blog', 'name' => 'PublishPost']));
        self::assertSame('make what=entity', AppliedTrials::key('make', ['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Post']));
        self::assertSame('make what=page', AppliedTrials::key('make', ['what' => 'page', 'plugin' => 'Blog']));
        self::assertSame('plugins.register', AppliedTrials::key('plugins.register', []));
        foreach ([['make', ['what' => 'test']], ['make', ['what' => 'controller']], ['make', ['what' => 'crud']], ['make', ['what' => 'service']], ['make', []], ['make', ['what' => ['page']]], ['screen:declare', []], ['implement', []], ['edit', []], ['component:define', []], ['plugins.enable', []]] as [$operation, $arguments]) {
            self::assertNull(AppliedTrials::key($operation, $arguments), $operation . ' ' . json_encode($arguments));
        }
        foreach (['screen:declare', 'make', 'make what=test', 'make what=controller', 'implement'] as $never) {
            self::assertFalse(AppliedTrials::forRoot($this->root)->admit($never, self::PERSON, '2026-10-07T00:00:00+00:00'), $never);
        }
        self::assertFileDoesNotExist($this->root . '/' . AppliedTrials::PATH, 'what cannot be admitted is never written');
    }

    /**
     * @param list<string>         $admitted
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('leftAsItWas')]
    public function testEverythingElseIsLeftExactlyAsItWas(array $admitted, string $tool, array $arguments): void
    {
        $registry = $this->registry($admitted);
        $asBefore = $this->registry(null)->call($tool, $arguments);

        $result = $registry->call($tool, $arguments);

        self::assertArrayNotHasKey('applies', $result->data ?? []);
        self::assertSame($asBefore->success, $result->success);
        self::assertSame(self::withoutWorkspace($asBefore->data), self::withoutWorkspace($result->data), 'not a word of the result changed');
        self::assertNull(AppliedTrials::follows($tool, $result->success ? $result->data : (string) $result->error));
        self::assertNull($registry->saidItApplies((string) ($result->data['workspace'] ?? 'w0')));
    }

    /** @return iterable<string, array{0: list<string>, 1: string, 2: array<string, mixed>}> */
    public static function leftAsItWas(): iterable
    {
        $all = ['plugins.register', 'entity:seed', 'make what=page', 'make what=plugin', 'make what=operation', 'make what=entity'];
        yield 'an operation nobody admitted' => [['entity:seed'], 'plugins_register', ['name' => 'Blog']];
        yield 'a house where nobody admitted anything' => [[], 'plugins_register', ['name' => 'Blog']];
        yield 'make of something that cannot be admitted' => [$all, 'make', ['what' => 'test', 'plugin' => 'Blog', 'name' => 'PostTest']];
        yield 'make of an operation when only the entity is admitted' => [['make what=entity'], 'make', ['what' => 'operation', 'plugin' => 'Blog', 'name' => 'PublishPost', 'entity' => 'Post']];
        yield 'make of an entity when only the operation is admitted' => [['make what=operation'], 'make', ['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Post']];
        yield 'make of a page when only the plugin is admitted' => [['make what=plugin'], 'make', ['what' => 'page', 'plugin' => 'Blog', 'name' => 'Home']];
        yield 'an operation that cannot be admitted' => [$all, 'screen_declare', ['name' => 'blog']];
        yield 'a trial that failed' => [$all, 'plugins_register', ['name' => 'Blog', 'fixture' => 'failed']];
        yield 'a trial whose producer says it is not ok' => [$all, 'plugins_register', ['name' => 'Blog', 'fixture' => 'says-not-ok']];
        yield 'a trial the house does not boot with' => [$all, 'plugins_register', ['name' => 'Blog', 'fixture' => 'unbootable']];
        yield 'a trial whose verification failed' => [$all, 'make', ['what' => 'page', 'plugin' => 'Blog', 'fixture' => 'unverified']];
        yield 'a trial with a required postcondition missing' => [$all, 'make', ['what' => 'page', 'plugin' => 'Blog', 'fixture' => 'missing-postconditions']];
        yield 'a trial that changed nothing' => [$all, 'entity_seed', ['entity' => 'Post', 'fixture' => 'no-change']];
    }

    public function testTheTrialLayerRemembersWhichTrialItSaidItAppliesAndForgetsOnceAsked(): void
    {
        $registry = $this->registry(['plugins.register']);

        $applied = $registry->call('plugins_register', ['name' => 'Blog'])->data['workspace'];
        $notAdmitted = $registry->call('entity_seed', ['entity' => 'Post'])->data['workspace'];

        self::assertNull($registry->saidItApplies($notAdmitted), 'a trial of an operation nobody admitted');
        self::assertNull($registry->saidItApplies('w0000000000000000'), 'a trial it never ran');
        self::assertSame('plugins.register', $registry->saidItApplies($applied));
        self::assertNull($registry->saidItApplies($applied), 'asked once: the answer is spent');
    }

    public function testAWithdrawalHoldsFromTheNextCall(): void
    {
        $registry = $this->registry(['plugins.register']);
        self::assertArrayHasKey('applies', $registry->call('plugins_register', ['name' => 'Blog'])->data);

        AppliedTrials::forRoot($this->root)->withdraw('plugins.register', self::PERSON, '2026-10-07T00:00:01+00:00');

        $after = $registry->call('plugins_register', ['name' => 'Shop']);
        self::assertArrayNotHasKey('applies', $after->data, 'the same leg, the very next call');
        self::assertStringContainsString('To apply the change, call sandbox:promote', $after->data['note']);
    }

    public function testAnAdmissionHoldsFromTheNextLeg(): void
    {
        $registry = $this->registry([]);

        AppliedTrials::forRoot($this->root)->admit('plugins.register', self::PERSON, '2026-10-07T00:00:01+00:00');

        self::assertArrayNotHasKey('applies', $registry->call('plugins_register', ['name' => 'Blog'])->data, 'this leg\'s catalogue never said so');
        self::assertArrayHasKey('applies', $this->registry()->call('plugins_register', ['name' => 'Shop'])->data, 'the next leg reads the list as it stands');
    }

    public function testAnUnfinishedMultipartPartIsNeverApplied(): void
    {
        mkdir($this->root . '/src/Plugins/Owned/Services', 0o755, true);
        file_put_contents($this->root . '/src/Plugins/Owned/Services/Renderer.php', '<?php // Live source');
        $inner = new ToolRegistry(new NullLogger());
        $inner->register('implement', 'Author', ['type' => 'object'], static fn (): array => ['ok' => true]);
        $registry = new TrialAwareRegistry($inner, new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/trial-partial-runner.php'), [self::producer('implement')]);
        $this->admit(AppliedTrials::admissible());
        $registry->houseApplies(AppliedTrials::forRoot($this->root));

        $result = $registry->call('implement', ['fixture' => 'start', 'mode' => 'start']);

        self::assertTrue($result->success, (string) $result->error);
        self::assertStringContainsString('The accepted part exists only in this trial', $result->data['note'], 'a part is not the work: the model promotes it, as before');
        self::assertArrayNotHasKey('applies', $result->data, 'authoring cannot be admitted — whoever admits it one day meets this test first');
    }

    public function testTheCatalogueSaysNothingOfAnOperationNoTrialConfines(): void
    {
        $inner = new ToolRegistry(new NullLogger());
        $inner->register('entity_seed', 'Seed', ['type' => 'object'], static fn (): array => ['ok' => true]);
        $asksFirst = new Operation(name: 'entity:seed', description: 'Seed', handler: static fn (): array => ['ok' => true], mutating: true, requiresConfirmation: true);
        $registry = new TrialAwareRegistry($inner, new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/trial-apply-runner.php'), [$asksFirst]);
        $this->admit(['entity:seed']);
        $registry->houseApplies(AppliedTrials::forRoot($this->root));

        self::assertSame('Seed', $registry->getToolSummaries()[0]['description'], 'it does not run in a trial here, so there is no trial for the house to apply');
    }

    public function testALoopThatCannotContinueAppliesNothingAndPromisesNothing(): void
    {
        $this->admit(AppliedTrials::admissible());
        $registry = $this->registry(null);

        $result = $registry->call('plugins_register', ['name' => 'Blog']);

        self::assertArrayNotHasKey('applies', $result->data);
        self::assertStringContainsString('To apply the change, call sandbox:promote', $result->data['note']);
        self::assertSame('Register', array_column($registry->getToolSummaries(), 'description', 'name')['plugins_register']);
    }

    public function testTheCatalogueSaysItOnlyOfWhatTheHouseWillHonour(): void
    {
        $described = static fn (TrialAwareRegistry $registry): array => array_column($registry->getToolSummaries(), 'description', 'name');

        $none = $described($this->registry([]));
        self::assertSame(['Scaffold', 'Register', 'Seed', 'Declare', 'Read', 'Promote'], array_values($none), 'a house where nobody admitted anything says nothing');

        $some = $described($this->registry(['plugins.register', 'make what=page']));
        self::assertSame('Register The house applies its verified trial.', $some['plugins_register']);
        self::assertSame('Scaffold The house applies its verified trial when what is page.', $some['make']);
        self::assertSame('Seed', $some['entity_seed'], 'nobody admitted it');
        self::assertSame('Declare', $some['screen_declare'], 'it cannot be admitted');
        self::assertSame('Promote', $some['sandbox_promote']);

        $all = $described($this->registry(AppliedTrials::admissible()));
        self::assertSame('Scaffold The house applies its verified trial when what is page, plugin, operation or entity.', $all['make']);
        self::assertSame('The house applies its verified trial when what is page or entity.', AppliedTrials::says('make', ['make what=page', 'make what=entity']), 'in the list\'s order, whatever order they were admitted in');
        self::assertSame('The house applies its verified trial when what is operation.', AppliedTrials::says('make', ['make what=operation']));
        self::assertSame('Seed The house applies its verified trial.', $all['entity_seed']);
    }

    public function testTheCatalogueOfALegDoesNotChangeUnderTheModel(): void
    {
        $registry = $this->registry(['plugins.register']);
        $before = $registry->getToolSummaries();

        AppliedTrials::forRoot($this->root)->withdraw('plugins.register', self::PERSON, '2026-10-07T00:00:01+00:00');
        AppliedTrials::forRoot($this->root)->admit('entity:seed', self::PERSON, '2026-10-07T00:00:02+00:00');

        self::assertSame($before, $registry->getToolSummaries(), 'what a leg sends is what it sent first: the house answers a changed list in the result of the call');
    }

    /**
     * A trial the house said it applies is still a rehearsal until its promotion lands (greenhouse decisions/0494,
     * app-runtime#730): if the door refuses that promotion, a claim that leans on the trial is told which call
     * applies it — not that it «changed nothing the house keeps».
     */
    public function testATrialTheHouseSaidItAppliesIsStillARehearsalThatCanBePromoted(): void
    {
        $result = $this->registry(['plugins.register'])->call('plugins_register', ['name' => 'Blog'])->data;
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Build the blog');
        $seq = $sessions->recordToolCall('bv', 'plugins_register', ['name' => 'Blog'], (string) json_encode($result), true, true);

        $rehearsal = \Milpa\AppRuntime\Agent\LandedCalls::of($sessions->stream('bv'))->rehearsalOf('plugins_register');

        self::assertSame(['seq' => $seq, 'workspace' => $result['workspace'], 'promotable' => true], $rehearsal);
        self::assertStringContainsString('Apply it with sandbox:promote {"workspace":"' . $result['workspace'] . '"}', \Milpa\AppRuntime\Agent\LandedCalls::refusal('plugins_register', $rehearsal));
    }

    public function testTheHouseContinuesWithThePromotionOfThatWorkspaceAndNothingElse(): void
    {
        $result = $this->registry(['plugins.register'])->call('plugins_register', ['name' => 'Blog'])->data;

        self::assertSame([['name' => 'sandbox_promote', 'arguments' => ['workspace' => $result['workspace']]]], AppliedTrials::follows('plugins_register', $result));

        self::assertNull(AppliedTrials::follows('plugins_register', array_diff_key($result, ['applies' => 1])), 'the house did not say it applies');
        self::assertNull(AppliedTrials::follows('plugins_register', ['applies' => ['operation' => 'sandbox:undo', 'arguments' => ['workspace' => $result['workspace']]]] + $result), 'only a promotion follows');
        self::assertNull(AppliedTrials::follows('plugins_register', ['applies' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => 'another']]] + $result), 'only the workspace this result is about');
        self::assertNull(AppliedTrials::follows('plugins_register', ['applied' => true] + $result), 'what already landed is not applied again');
        self::assertNull(AppliedTrials::follows('plugins_register', ['ran_in_trial' => false] + $result));
        self::assertNull(AppliedTrials::follows('plugins_register', ['workspace' => '', 'applies' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => '']]] + $result), 'a trial without a name is no trial');
        self::assertNull(AppliedTrials::follows('plugins_register', json_encode($result)), 'a result that is text is not read for calls');
        self::assertNull(AppliedTrials::follows('sandbox_promote', $result), 'a promotion is not continued');
    }

    public function testTheHouseRecordsThatItContinuedAfterWhichCallAndByWhichContract(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Build the blog');
        $sessions->recordToolCall('bv', 'source_read', ['path' => 'a'], '{}');
        $made = $sessions->recordToolCall('bv', 'plugins_register', ['name' => 'Blog'], '{"ran_in_trial":true}', true, true);
        $sessions->setTodo('bv', new \Milpa\Agent\Todo('t1', 'Register the plugin', \Milpa\Agent\TodoStatus::Pending));

        AppliedTrials::continued($events, $sessions->stream('bv'), 'bv', [['name' => 'sandbox_promote', 'arguments' => ['workspace' => 'w1']]], 'key:SEAT', 'plugins.register');

        $facts = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === AppliedTrials::CONTINUED));
        self::assertCount(1, $facts);
        self::assertSame('session.call_continued', AppliedTrials::CONTINUED);
        self::assertSame(['tool' => 'sandbox_promote', 'arguments' => ['workspace' => 'w1'], 'after' => $made, 'because' => 'contract: plugins.register', 'as' => 'key:SEAT'], $facts[0]->payload);
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

    /**
     * Through the door, not only the handler: the house's own gate inspects a trial before its promotion runs, and a
     * trial the house applied has collapsed. Measured with the scripted stand-in (greenhouse evidence/1128): asking
     * again was answered «No trial … to inspect», an error, where the handler would have said «already promoted».
     */
    public function testTheDoorLetsWhoeverCouldUndoItAskAgainForAPromotionAlreadyMade(): void
    {
        $trial = TrialWorkspace::materialize($this->root, 'wapplied', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($trial->copy . '/src/Plugins/Blog/Blog.php', "<?php // the plugin\n");
        self::assertTrue($this->promote('wapplied')['ok']);
        $policy = new \Milpa\AppRuntime\Agent\PluginAuthoringPolicy($this->root);
        $promote = new \Milpa\ToolRuntime\ToolDefinition('sandbox_promote', 'Promote', ['type' => 'object'], static fn (): array => ['ok' => true]);
        $seat = new ToolContext(principal: 'seat', channel: 'cli', scopes: ['plugins.Blog:write']);

        $verdict = $policy->authorize($seat, $promote, ['workspace' => 'wapplied']);
        self::assertTrue($verdict->allowed, (string) $verdict->reason);

        $another = new ToolContext(principal: 'other', channel: 'cli', scopes: ['plugins.Shop:write']);
        $refused = $policy->authorize($another, $promote, ['workspace' => 'wapplied']);
        self::assertFalse($refused->allowed, 'a seat that could not have promoted those paths is not told about them');
        self::assertStringContainsString('plugins.Blog:write', (string) $refused->reason);

        self::assertFalse($policy->authorize($seat, $promote, ['workspace' => 'wnever'])->allowed, 'a trial that never existed is still nothing to inspect');
        self::assertStringContainsString("No trial 'wnever' to inspect", (string) $policy->authorize($seat, $promote, ['workspace' => 'wnever'])->reason);

        // An open trial is judged by what it would write, whatever a record beside it says.
        mkdir($this->root . '/src/Plugins/Shop', 0o755, true);
        $open = TrialWorkspace::materialize($this->root, 'wopen', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($open->copy . '/src/Plugins/Shop/Shop.php', "<?php // another plugin\n");
        file_put_contents($this->root . '/var/trials/wopen/promoted.json', '{"src/Plugins/Blog/Blog.php":"x"}');
        $stillRefused = $policy->authorize($seat, $promote, ['workspace' => 'wopen']);
        self::assertFalse($stillRefused->allowed);
        self::assertStringContainsString('plugins.Shop:write', (string) $stillRefused->reason);
    }

    public function testATrialThatNeverExistedIsStillAnError(): void
    {
        $never = $this->promote('wnever');

        self::assertFalse($never['ok']);
        self::assertSame('no trial «wnever» to promote', $never['error']);
    }

    public function testARecordThatNamesNothingPromotedIsNotAPromotion(): void
    {
        mkdir($this->root . '/var/trials/wempty', 0o755, true);
        file_put_contents($this->root . '/var/trials/wempty/promoted.json', '{}');

        self::assertNull(TrialWorkspace::promotedPaths($this->root, 'wempty'));
        self::assertSame('no trial «wempty» to promote', $this->promote('wempty')['error']);
    }

    /** The seat does not decide what the house applies on its own: not even a principal that may write anything. */
    public function testAPromotionNeverCarriesTheHousesList(): void
    {
        $trial = TrialWorkspace::materialize($this->root, 'wlist', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        mkdir($trial->copy . '/storage/identity', 0o755, true);
        file_put_contents($trial->copy . '/' . AppliedTrials::PATH, '{"plugins.register":{"admitted_by":"key:SEAT","admitted_at":"2026-10-07T00:00:00+00:00"}}');
        file_put_contents($trial->copy . '/src/Plugins/Blog/Blog.php', "<?php // the plugin\n");

        $promoted = $this->promote('wlist');

        self::assertFalse($promoted['ok']);
        self::assertStringContainsString('sandbox:admit', $promoted['error']);
        self::assertFileDoesNotExist($this->root . '/' . AppliedTrials::PATH);
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Blog/Blog.php', 'nothing of that trial crossed');
        self::assertSame([], AppliedTrials::forRoot($this->root)->admitted());
    }

    /**
     * Through the real door of a leg: in a house where a person admitted an operation, the leg hands its loop a
     * continuation, and what the house continues with is recorded as the seat's, after the producer's call.
     */
    public function testALegOfAHouseThatAdmittedSomethingContinuesAndRecordsIt(): void
    {
        $this->admit(['plugins.register']);
        [$operations, $sessions, $loop] = $this->house(new ContinuationRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class)));

        self::leg($operations);

        self::assertNotNull($loop->continuation, 'the leg handed its loop a continuation');
        self::assertTrue(self::honours($loop), 'and told its trial layer what the house applies');

        $made = $sessions->recordToolCall('bv', 'plugins_register', ['name' => 'Blog'], '{}', true, true);
        $result = ['ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'applies' => ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => 'w1']]];

        // A RESULT THAT SAYS SO ON ITS OWN IS NOT ENOUGH: what any tool answers is data, and a result shaped like a
        // verified trial — from a tool the house does not confine, naming a trial of somebody else — applies nothing.
        self::assertNull(($loop->continuation)('plugins_register', ['name' => 'Blog'], $result), 'the leg\'s own trial layer never said it applies «w1»');
        self::assertSame([], array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === AppliedTrials::CONTINUED));

        // Only the trial this leg's trial layer just ran, verified and said it applies.
        (new \ReflectionProperty(TrialAwareRegistry::class, 'applies'))->setValue(self::registryOf($loop), ['w1' => 'plugins.register']);
        self::assertSame([['name' => 'sandbox_promote', 'arguments' => ['workspace' => 'w1']]], ($loop->continuation)('plugins_register', ['name' => 'Blog'], $result));
        self::assertNull(($loop->continuation)('plugins_register', ['name' => 'Blog'], $result), 'and once: what it said is spent');
        $facts = array_values(array_filter($sessions->stream('bv'), static fn (Event $e): bool => $e->type === AppliedTrials::CONTINUED));
        self::assertCount(1, $facts);
        self::assertSame($made, $facts[0]->payload['after']);
        self::assertSame('key:' . self::SEAT, $facts[0]->payload['as']);
        self::assertSame('contract: plugins.register', $facts[0]->payload['because']);
    }

    public function testTheCatalogueNeverSaysItOfAnOperationThatCannotBeAdmittedWhateverListItIsHanded(): void
    {
        self::assertNull(AppliedTrials::says('screen:declare', ['screen:declare']));
        self::assertNull(AppliedTrials::says('implement', ['implement', 'plugins.register']));
        self::assertNull(AppliedTrials::says('make', ['make', 'make what=test']), 'make is admitted for one thing it makes, never whole');
    }

    public function testALegOfAHouseWhereNobodyAdmittedAnythingIsWhatItWas(): void
    {
        [$operations, , $loop] = $this->house(new ContinuationRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class)));

        self::leg($operations);

        self::assertNull($loop->continuation, 'no continuation is handed to the loop');
        self::assertInstanceOf(TrialAwareRegistry::class, self::registryOf($loop));
        self::assertFalse(self::honours($loop));
    }

    /**
     * BY CONTRACT ONLY WHERE THE MODE DOES NOT ASK (greenhouse evidence/1128). Measured in a house in `ask`: the
     * promotion the house played did ask a person first — and after the yes nobody played it again, while the
     * question had said «the agent wants to run sandbox:promote» of a call no agent made. In `ask` the model asks
     * for the promotion and the person is asked about it, exactly as before.
     */
    public function testInAskTheLegIsWhatItWas(): void
    {
        $this->admit(AppliedTrials::admissible());
        [$operations, , $loop] = $this->house(new ContinuationRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class)), mode: \Milpa\Agent\AutonomyMode::Ask);

        self::leg($operations);

        self::assertNull($loop->continuation, 'no continuation is handed to the loop');
        self::assertFalse(self::honours($loop), 'and the trial layer says and applies nothing');
    }

    public function testOutsideAutoEveryModeIsLeftAsItWas(): void
    {
        $this->admit(AppliedTrials::admissible());
        [$operations, , $loop] = $this->house(new ContinuationRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class)), mode: \Milpa\Agent\AutonomyMode::Acknowledge);

        self::leg($operations);

        self::assertNull($loop->continuation);
        self::assertFalse(self::honours($loop));
    }

    public function testAMoveTheHouseCannotRecordIsAMoveItDoesNotMake(): void
    {
        $this->admit(AppliedTrials::admissible());
        [$operations, , $loop] = $this->house(new ContinuationRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class)), log: false);

        self::leg($operations);

        self::assertNull($loop->continuation, 'no log to say «the house continued»: no continuation');
        self::assertFalse(self::honours($loop));
    }

    public function testAHouseWithoutTrialsPromisesNothing(): void
    {
        $this->admit(AppliedTrials::admissible());
        [$operations, , $loop] = $this->house(new ContinuationRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class)), trials: false);

        self::leg($operations);

        self::assertNull($loop->continuation);
        self::assertNotInstanceOf(TrialAwareRegistry::class, self::registryOf($loop));
    }

    /**
     * A result without the one thing that differs between two runs of the same call.
     *
     * @param array<string, mixed>|null $data
     */
    private static function withoutWorkspace(?array $data): string
    {
        return $data === null ? '' : (string) preg_replace('/w[0-9a-f]{16}/', 'W', (string) json_encode($data));
    }

    /** @param list<string> $keys */
    private function admit(array $keys): void
    {
        foreach ($keys as $n => $key) {
            AppliedTrials::forRoot($this->root)->admit($key, self::PERSON, \sprintf('2026-10-07T00:00:%02d+00:00', $n));
        }
    }

    /** The registry the leg's governed door calls through. */
    private static function registryOf(LoopThatOnlyAnswers $loop): ?ToolRegistry
    {
        return $loop->door === null ? null : (new \ReflectionProperty(GatedToolCalls::class, 'registry'))->getValue($loop->door);
    }

    /** Whether that registry was told what the house applies. */
    private static function honours(LoopThatOnlyAnswers $loop): bool
    {
        $registry = self::registryOf($loop);

        return $registry instanceof TrialAwareRegistry && (new \ReflectionProperty(TrialAwareRegistry::class, 'applied'))->getValue($registry) !== null;
    }

    /**
     * A fixture house and a leg of it whose loop is the test's.
     *
     * @return array{0: AppliedFixtureOperations, 1: SessionStore, 2: LoopThatOnlyAnswers}
     */
    private function house(LoopThatOnlyAnswers $loop, bool $trials = true, \Milpa\Agent\AutonomyMode $mode = \Milpa\Agent\AutonomyMode::Auto, bool $log = true): array
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Build the blog', $mode, by: new \Milpa\Agent\Principal('key:' . self::SEAT, true));
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $sessions);
        if ($log) {
            $container->registerService(\Milpa\EventStore\EventStoreInterface::class, $events);
        }
        $container->registerService(\Milpa\Runtime\Kernel::class, \Milpa\Runtime\Kernel::boot(['root' => $this->root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]));
        $operations = new AppliedFixtureOperations($container);
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

    private static function producer(string $name): Operation
    {
        return new Operation(name: $name, description: $name, handler: static fn (): array => ['ok' => true], mutating: true, effects: new EffectProfile(
            mutation: Mutation::Persistent,
            externality: Externality::None,
            reversibility: Reversibility::Compensatable,
            authority: Authority::WriteAsUser,
            subject: Subject::Executable,
        ));
    }

    /**
     * The trial layer of a leg in a house that admitted these operations.
     *
     * @param list<string>|null $admitted what a person admitted before the leg began — an empty list for nobody;
     *                                    null for a leg whose loop cannot continue, whatever the house admitted
     */
    private function registry(?array $admitted = []): TrialAwareRegistry
    {
        $inner = new ToolRegistry(new NullLogger());
        $never = static function (): never {
            throw new \RuntimeException('The host handler must not run');
        };
        $inner->register('make', 'Scaffold', ['type' => 'object', 'properties' => ['what' => ['type' => 'string'], 'plugin' => ['type' => 'string']]], $never);
        $inner->register('plugins_register', 'Register', ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]], $never);
        $inner->register('entity_seed', 'Seed', ['type' => 'object', 'properties' => ['entity' => ['type' => 'string']]], $never);
        $inner->register('screen_declare', 'Declare', ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]], $never);
        $inner->register('source_read', 'Read', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], static fn (): array => ['ok' => true]);
        $inner->register('sandbox_promote', 'Promote', ['type' => 'object', 'properties' => ['workspace' => ['type' => 'string']]], static fn (): array => ['ok' => true]);
        $operations = [
            self::producer('make'),
            self::producer('plugins.register'),
            self::producer('entity:seed'),
            self::producer('screen:declare'),
            new Operation(name: 'source:read', description: 'Read', handler: static fn (): array => ['ok' => true], effects: EffectProfile::readOnly()),
            self::producer('sandbox:promote'),
        ];
        $router = new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/trial-apply-runner.php');
        $registry = new TrialAwareRegistry($inner, $router, $operations);
        if ($admitted !== null) {
            $this->admit($admitted);
            $registry->houseApplies(AppliedTrials::forRoot($this->root));
        }

        return $registry;
    }
}

/** A leg whose orchestrator is the test's. */
final class AppliedFixtureOperations extends AgentOperations
{
    public ?LoopThatOnlyAnswers $loop = null;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->loop !== null);
        $this->loop->door = $cliente;

        return $this->loop;
    }
}

/** A loop that only answers, and remembers the door it was given. */
class LoopThatOnlyAnswers extends AgentOrchestrator
{
    public ?GatedToolCalls $door = null;

    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        return 'Continued.';
    }
}

/** A loop that also remembers the continuation the leg handed it. */
final class ContinuationRecorder extends LoopThatOnlyAnswers
{
    public ?\Closure $continuation = null;

    public function setContinuation(?callable $continuation): self
    {
        $this->continuation = $continuation === null ? null : $continuation(...);

        return $this;
    }
}
