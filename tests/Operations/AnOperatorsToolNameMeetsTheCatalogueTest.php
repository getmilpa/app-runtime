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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PausedSequence;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Operations\RecipeOperations;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The resident with recipes, at the operation's door (greenhouse decisions/0550): the flags an operator types are
 * judged against the catalogue before anything starts, a grant that cannot be granted says what to do instead, and a
 * session a recipe paused in says what holds it.
 */
final class AnOperatorsToolNameMeetsTheCatalogueTest extends TestCase
{
    private string $root = '';

    private InMemoryEventStore $events;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-0550-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/recipes', 0o777, true);
        file_put_contents($this->root . '/config/operations.php', '<?php return [\\' . RecipeOperations::class . '::class];');
        file_put_contents($this->root . '/recipes/notes.json', '{"work":[]}');
        $this->events = new InMemoryEventStore();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAnObligationNamingNoOfferedToolIsRefusedBeforeTheSessionIsTouched(): void
    {
        $result = $this->agent(['prompt' => 'build it', 'session' => 's', 'first' => 'recipe.plann']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('--first names a tool this session is not offered: «recipe.plann»', (string) $result['error']);
        self::assertStringContainsString('«recipe_plan»', (string) $result['hint']);
        self::assertSame([], (new SessionStore($this->events))->stream('s'), 'nothing was recorded');
    }

    public function testAWithdrawalOfNothingIsRefusedToo(): void
    {
        $result = $this->agent(['prompt' => 'build it', 'session' => 's', 'deny' => 'recipe.aply']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('--deny names a tool', (string) $result['error']);
        self::assertStringContainsString('«recipe_apply»', (string) $result['hint']);
    }

    public function testTheOperatorsSpellingIsRecordedAsTheCatalogueSpellsIt(): void
    {
        // The provider is a closed loopback port: the leg fails after the session facts land, never before.
        $this->agent(['prompt' => 'build it', 'session' => 's', 'first' => 'recipe.plan', 'deny' => 'recipe:apply']);

        $session = (new SessionStore($this->events))->load('s');
        self::assertNotNull($session);
        self::assertSame(['recipe_plan'], $session->runFirst, 'the obligation the session fold discounts when recipe_plan lands');
        self::assertContains('recipe_apply', $session->removedOptions, 'the withdrawal names the tool the model is offered');
    }

    public function testAGrantNoOperatorCanGiveSaysWhatToDoInstead(): void
    {
        $result = $this->agent(['prompt' => 'apply the notes recipe', 'session' => 's', 'grant' => 'recipe:apply']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('a grant cannot replace it', (string) $result['error']);
        self::assertStringContainsString('when the agent reaches «recipe_apply» this session asks once', (string) $result['hint']);
        self::assertStringContainsString('agent:answer --session=s --answer=yes --sign', (string) $result['hint']);
    }

    public function testASessionARecipePausedInSaysWhatHoldsItAndHowItResumes(): void
    {
        $store = new SessionStore($this->events);
        $store->start('resident', 'apply the notes recipe', AutonomyMode::Auto);
        $store->recordSequencePaused('resident', new PausedSequence('notes', 'sha256:x', [
            ['operation' => 'foundation:found', 'arguments' => []],
            ['operation' => 'make', 'arguments' => []],
        ], 1));

        $result = $this->agent(['prompt' => 'continue', 'session' => 'resident']);

        self::assertFalse($result['ok']);
        self::assertSame('session «resident» is held by the sequence «notes», paused at step 2 of 2: it resumes before the agent continues', $result['error']);
        self::assertStringEndsWith('recipe:apply --recipe=notes --session=resident --sign', (string) $result['hint']);
    }

    public function testTheResumeLineReadsThePausedSequenceNotTheSessionName(): void
    {
        self::assertStringEndsWith('recipe:apply --recipe=notes --sign', RecipeOperations::resumeLine($this->root, 'recipe:notes', 'notes'));
        self::assertStringEndsWith('recipe:apply --recipe=notes --session=resident --sign', RecipeOperations::resumeLine($this->root, 'resident', 'notes'));
        self::assertStringEndsWith('sequence:run --sequence=deploy --session=resident --sign', RecipeOperations::resumeLine($this->root, 'resident', 'deploy'));
    }

    public function testTheAnswerPointsAtTheSequenceThatHoldsTheSession(): void
    {
        $store = new SessionStore($this->events);
        $store->start('resident', 'apply the notes recipe', AutonomyMode::Auto);
        $store->recordSequencePaused('resident', new PausedSequence('notes', 'sha256:x', [
            ['operation' => 'make', 'arguments' => []],
        ], 0));
        $store->ask('resident', new \Milpa\Agent\PendingQuestion('perm:make', 'The agent wants to run «make». Do you allow it in this session?', ['yes', 'no'], '{"operation":"make","arguments":{}}', null, 'permission'));

        $answer = null;
        foreach ((new SessionOperations($this->container()))->operations() as $operation) {
            if ($operation->name === 'agent:answer') {
                $answer = ($operation->handler)(['session' => 'resident', 'answer' => 'yes']);
            }
        }

        self::assertIsArray($answer);
        self::assertStringContainsString('the sequence resumes where it paused', (string) ($answer['hint'] ?? ''), json_encode($answer, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('recipe:apply --recipe=notes --session=resident --sign', (string) $answer['hint']);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function agent(array $input): array
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=test-key');
        try {
            foreach ((new AgentOperations($this->container()))->operations() as $operation) {
                if ($operation->name === 'agent') {
                    $result = ($operation->handler)($input);
                    self::assertIsArray($result);

                    return $result;
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }

        self::fail('the agent operation is missing');
    }

    private function container(): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config([
            'agent' => ['baseUrl' => 'http://127.0.0.1:1', 'model' => 'fixture'],
        ]));
        $container->registerService(EventStoreInterface::class, $this->events);
        $container->registerService(SessionStore::class, new SessionStore($this->events));
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);

        return $container;
    }
}
