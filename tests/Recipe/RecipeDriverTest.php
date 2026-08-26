<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Recipe;

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\ToolCallRefusedException;
use Milpa\AppRuntime\Agent\GovernedExecutor;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Recipe\Recipe;
use Milpa\AppRuntime\Recipe\RecipeDriver;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Drives the load-bearing core of `recipe:apply`: expand → run → pause-persist → resume, with a
 * FAKE {@see GovernedExecutor} standing in for the governed door so the loop's governance decisions
 * are tested without a booted app. The two decisions/0076 contracts — resume authenticates with the
 * persisted digest, and a pause whose append fails NEVER invents an in-memory cursor — are each
 * exercised against the real {@see SessionStore} over an in-memory event store, the fixture the rest
 * of this package's agent tests already use.
 *
 * @internal
 */
final class RecipeDriverTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(ToolCallRefusedException::class)) {
            self::markTestSkipped('sin milpa/ai-gateway no hay frontera de consentimiento que fingir');
        }
    }

    /** A recipe whose work is three passthrough operations: a read, a mutation, a read. */
    private function recipe(): Recipe
    {
        return Recipe::fromArray('demo', [
            'work' => [
                ['op' => 'demo:read'],
                ['op' => 'demo:mutate'],
                ['op' => 'demo:read'],
            ],
        ]);
    }

    /**
     * A fake door that grants the reads and PAUSES the mutation — by throwing the refusal the
     * session gate throws (`AutonomyMode::Ask`, no grant), the frontier the runner records as Paused.
     *
     * @return GovernedExecutor&object{calls: list<string>}
     */
    private function pausingExecutor(): GovernedExecutor
    {
        return new class () implements GovernedExecutor {
            /** @var list<string> */
            public array $calls = [];

            public function callTool(string $operation, array $arguments): mixed
            {
                $this->calls[] = $operation;
                if ($operation === 'demo:mutate') {
                    throw new ToolCallRefusedException("consent needed: {$operation}");
                }

                return ['ok' => true, 'operation' => $operation];
            }
        };
    }

    /**
     * A fake door that refuses the mutation as UNJUDGEABLE — the fail-closed hard-deny a call the
     * gate cannot characterise gets (greenhouse decisions/0078), never a real consent pause.
     */
    private function unjudgeableExecutor(): GovernedExecutor
    {
        return new class () implements GovernedExecutor {
            public function callTool(string $operation, array $arguments): mixed
            {
                if ($operation === 'demo:mutate') {
                    throw new ToolCallRefusedException(
                        SessionToolGate::UNJUDGEABLE . ": «{$operation}» resolves to no Operation of this app"
                        . ' and no producer states its effect.',
                    );
                }

                return ['ok' => true, 'operation' => $operation];
            }
        };
    }

    /** A fake door that grants everything — the consent the pause was waiting for has arrived. */
    private function grantingExecutor(): GovernedExecutor
    {
        return new class () implements GovernedExecutor {
            public function callTool(string $operation, array $arguments): mixed
            {
                return ['ok' => true, 'operation' => $operation];
            }
        };
    }

    private function verdict(): callable
    {
        return static fn (): array => ['verdict' => 'unfounded', 'domain' => null];
    }

    private function installed(): callable
    {
        return static fn (): array => [];
    }

    public function testApplyRunsUntilTheConsentFrontierThenPersistsThePause(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $sid = 'recipe:demo';

        $result = (new RecipeDriver())->apply(
            $this->recipe(),
            $this->pausingExecutor(),
            $store,
            $sid,
            $this->verdict(),
            $this->installed(),
        );

        self::assertTrue($result['ok']);
        self::assertFalse($result['applied']);
        self::assertTrue($result['paused']);
        self::assertTrue($result['resumable']);
        self::assertSame('demo:mutate', $result['pending_operation']);
        self::assertSame(1, $result['executed_count'], 'only the first read ran before the frontier');
        self::assertSame(3, $result['steps_total']);

        $paused = $store->load($sid)?->pausedSequence;
        self::assertNotNull($paused, 'the pause was persisted as a first-class session fact');
        self::assertSame(1, $paused->nextIndex, 'the mutation is the step to try next');
        self::assertSame('demo', $paused->sequenceId);
        self::assertCount(3, $paused->steps);
        self::assertFalse($store->load($sid)?->isRunnable(), 'the session is left waiting on the pause');
    }

    /**
     * An UNJUDGEABLE hard-deny can never become judgeable, so a caller must be able to tell it
     * apart from a real consent pause — `pending_reason` is the seam that carries the distinction
     * (`SessionToolGate::UNJUDGEABLE` marks it, greenhouse decisions/0078).
     */
    /**
     * H-DENY-1 (greenhouse decisions/0079): an UNJUDGEABLE refusal is a hard deny no human can answer,
     * so the driver reports it as DENIED — not paused, not resumable — and persists NO pause. The
     * old behaviour (paused:true, resumable:true, a SequencePaused fact written) recorded a session
     * as waiting on a human who cannot exist; a later resume ran nothing and paused again forever.
     */
    public function testAnUnjudgeableRefusalDeniesTheRecipeAndPersistsNoPause(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $sid = 'recipe:demo';

        $result = (new RecipeDriver())->apply(
            $this->recipe(),
            $this->unjudgeableExecutor(),
            $store,
            $sid,
            $this->verdict(),
            $this->installed(),
        );

        self::assertFalse($result['ok']);
        self::assertFalse($result['applied']);
        self::assertFalse($result['paused']);
        self::assertTrue($result['denied']);
        self::assertFalse($result['resumable']);
        self::assertSame('demo:mutate', $result['denied_operation']);
        self::assertStringContainsString(SessionToolGate::UNJUDGEABLE, $result['reason']);
        self::assertSame(1, $result['executed_count'], 'the read before the denied step ran; nothing after it');

        self::assertNull($store->load($sid)?->pausedSequence, 'a deny is not a pause: no SequencePaused fact');

        $resumed = (new RecipeDriver())->resume($store, $sid, $this->grantingExecutor());
        self::assertFalse($resumed['ok']);
        self::assertFalse($resumed['resumable'], 'nothing was paused, so nothing resumes');
        self::assertSame(0, $resumed['executed_count'] ?? 0, 'a resume after a deny runs nothing');
    }

    /** The OTHER frontier — an ordinary consent pause — carries its own reason, and NOT the marker. */
    public function testAPausedResultCarriesAnOrdinaryConsentReasonWithoutTheUnjudgeableMarker(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $sid = 'recipe:demo';

        $result = (new RecipeDriver())->apply(
            $this->recipe(),
            $this->pausingExecutor(),
            $store,
            $sid,
            $this->verdict(),
            $this->installed(),
        );

        self::assertTrue($result['paused']);
        self::assertSame('consent needed: demo:mutate', $result['pending_reason']);
        self::assertStringNotContainsString(SessionToolGate::UNJUDGEABLE, $result['pending_reason']);
    }

    public function testResumeWithGrantedConsentCompletesAndRecordsResumed(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $sid = 'recipe:demo';

        (new RecipeDriver())->apply(
            $this->recipe(),
            $this->pausingExecutor(),
            $store,
            $sid,
            $this->verdict(),
            $this->installed(),
        );

        $resumed = (new RecipeDriver())->resume($store, $sid, $this->grantingExecutor());

        self::assertTrue($resumed['ok']);
        self::assertTrue($resumed['applied']);
        self::assertFalse($resumed['paused']);
        self::assertSame(3, $resumed['executed_count'], 'the carried prefix plus the two resumed steps');
        self::assertSame(3, $resumed['steps_total']);

        self::assertNull($store->load($sid)?->pausedSequence, 'SequenceResumed cleared the pause');
        self::assertTrue($store->load($sid)?->isRunnable());
    }

    public function testResumeWithNoPausedSequenceIsNotResumable(): void
    {
        $store = new SessionStore(new InMemoryEventStore());

        $result = (new RecipeDriver())->resume($store, 'recipe:absent', $this->grantingExecutor());

        self::assertFalse($result['ok']);
        self::assertFalse($result['resumable']);
        self::assertFalse($result['applied']);
    }

    public function testAPauseWhoseAppendThrowsIsNotResumableAndInventsNoCursor(): void
    {
        // 0076 CONTRACT (b): the append is part of the fail-closed frontier. If it throws, the
        // driver reports the pause could not be persisted and NEVER hands back an in-memory cursor
        // a later process could resume against a fact nobody wrote down.
        $store = new SessionStore($this->throwingOnAppend());
        $sid = 'recipe:demo';

        $result = (new RecipeDriver())->apply(
            $this->recipe(),
            $this->pausingExecutor(),
            $store,
            $sid,
            $this->verdict(),
            $this->installed(),
        );

        self::assertFalse($result['ok']);
        self::assertTrue($result['paused']);
        self::assertFalse($result['resumable']);
        self::assertSame('pause could not be persisted', $result['reason']);
        self::assertArrayNotHasKey('pending_operation', $result, 'a pause that did not persist offers nothing to resume');
        self::assertSame(1, $result['executed_count']);
        self::assertSame(3, $result['steps_total']);
    }

    public function testTheDriverNamesNoDomainLiteral(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Recipe/RecipeDriver.php');

        foreach (['blog', 'Post', 'article', 'comment', 'Entity'] as $domainWord) {
            self::assertStringNotContainsString($domainWord, $source, "the driver must stay domain-blind — found '{$domainWord}'");
        }
    }

    /** An event store whose append always throws, to drive the fail-closed persistence contract. */
    private function throwingOnAppend(): EventStoreInterface
    {
        return new class () implements EventStoreInterface {
            public function append(Event $event): void
            {
                throw new \RuntimeException('the log refused the append');
            }

            public function replay(string $streamId): array
            {
                return [];
            }

            public function nextSeq(): int
            {
                return 1;
            }

            public function streams(): array
            {
                return [];
            }

            public function replayAll(): array
            {
                return [];
            }
        };
    }
}
