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
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The refusal teaches the reference (greenhouse decisions/0482).
 *
 * Measured: 63% of work:claim-verified refused — references written as prose («screen:observe blog → status
 * 200…») instead of the subject, invented todo ids, and a promoted config/screens.json the judge did not
 * recognise as materialised. The judge stays exact; a refusal now names what it would accept.
 *
 * @guards each refusal naming the exact candidates of its kind, and the named one then accepted; a promotion
 *         covering the paths it carried
 *
 * @refuses a path the promotion did not carry, and candidates of another kind
 *
 * @subject-in milpa/app-runtime
 */
final class TheRefusalTeachesTheReferenceTest extends TestCase
{
    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s1', 'build the blog page');
        foreach (['t1' => 'Declare the page', 't2' => 'Observe it', 't3' => 'Promote it'] as $id => $text) {
            $this->store->setTodo('s1', new Todo($id, $text, TodoStatus::Pending));
        }
        $this->store->recordToolCall(
            's1',
            'screen_observe',
            ['name' => 'blog'],
            '{"ok":true,"screen":"blog","status":200,"evidence":{"predicate":"served","subject":"blog","environment":{"kind":"house"}}}',
            true
        );
        $this->store->recordToolCall(
            's1',
            'sandbox_promote',
            ['workspace' => 'w1'],
            '{"ok":true,"promoted":["config/screens.json"],"evidence":{"predicate":"promoted","subject":"w1"}}',
            true,
            true
        );
    }

    public function testAProseReferenceIsRefusedNamingTheSubjectThatWouldCoverIt(): void
    {
        $refused = $this->claim('t2', 'screen-served', 'screen:observe blog → status 200 at /live/page?component=blog');
        self::assertFalse($refused['ok']);
        self::assertStringContainsString('«blog»', (string) $refused['error']);
        self::assertTrue($this->claim('t2', 'screen-served', 'blog')['ok'], 'the named reference is accepted');
    }

    public function testAStaleSubjectIsNotOffered(): void
    {
        // Served, then forgotten: the judge would refuse it as stale, so the refusal must not offer it.
        $this->store->recordToolCall(
            's1',
            'screen_declare',
            ['name' => 'old-page'],
            '{"ok":true,"screen":"old-page","evidence":{"predicate":"served","subject":"old-page","servedAt":"/live/page?component=old-page"}}',
            true,
            true
        );
        $this->store->recordToolCall(
            's1',
            'screen_forget',
            ['name' => 'old-page'],
            '{"ok":true,"forgotten":"old-page","evidence":{"predicate":"served","subject":"old-page","invalidates":true}}',
            true,
            true
        );

        $refused = $this->claim('t2', 'screen-served', 'the page');
        self::assertStringContainsString('«blog»', (string) $refused['error']);
        self::assertStringNotContainsString('«old-page»', (string) $refused['error'], 'only what the judge would accept today');
    }

    public function testAnOperationClaimNamesTheOperationsThatSucceeded(): void
    {
        $refused = $this->claim('t3', 'operation-ok', 'sandbox:promote ran ok for w1');
        self::assertStringContainsString('«sandbox_promote»', (string) $refused['error']);
        self::assertStringNotContainsString('«blog»', (string) $refused['error'], 'candidates of another kind are not offered');
        self::assertTrue($this->claim('t3', 'operation-ok', 'sandbox_promote')['ok']);
    }

    public function testAnInventedTodoIsRefusedNamingTheOpenOnes(): void
    {
        $refused = $this->claim('turn:61', 'screen-served', 'blog');
        self::assertStringContainsString('t1 «Declare the page»', (string) $refused['error']);
    }

    /**
     * Measured on fresh cattle (evidence/1017): 7 claims came from sessions that never opened a todo, each refused
     * without saying what to do. With none open, the refusal names the fix.
     */
    public function testWithNoOpenTodoTheRefusalSaysToOpenOneFirst(): void
    {
        $this->store->start('s2', 'build the blog page');
        foreach ((new SessionBookkeeping($this->store, 's2', $this->events))->operations() as $operation) {
            if ($operation->name === 'work:claim-verified') {
                $refused = ($operation->handler)(['todo' => 'screen', 'kind' => 'screen-served', 'reference' => 'blog']);
                self::assertFalse($refused['ok']);
                self::assertStringContainsString('add it first with the todo tool', (string) $refused['error']);

                return;
            }
        }
        self::fail('work:claim-verified is not offered');
    }

    /**
     * Once the HOUSE derived the closure of a session with no todos (greenhouse decisions/0487), the refusal does
     * not send it to open a todo only to close it: measured in evidence/1022, that ritual spent the epilogue.
     */
    public function testWithTheClosureTheHouseDerivedTheRefusalAsksForTheFinalAnswer(): void
    {
        $this->store->start('s3', 'build the blog page');
        $this->store->recordToolCall('s3', 'sandbox_promote', ['workspace' => 'wabc'], (string) json_encode(['ok' => true,
            'evidence' => ['predicate' => 'promoted', 'subject' => 'wabc', 'environment' => ['kind' => 'house']]]), mutating: true);
        $this->store->recordToolCall('s3', 'screen_observe', ['name' => 'blog'], (string) json_encode(['ok' => true,
            'evidence' => ['predicate' => 'served', 'subject' => 'blog', 'environment' => ['kind' => 'house']]]));
        foreach ((new SessionBookkeeping($this->store, 's3', $this->events))->operations() as $operation) {
            if ($operation->name === 'work:claim-verified') {
                $refused = ($operation->handler)(['todo' => 'screen', 'kind' => 'screen-served', 'reference' => 'blog']);
                self::assertFalse($refused['ok']);
                self::assertStringContainsString('the house already closed it on its own observation of «blog»', (string) $refused['error']);
                self::assertStringNotContainsString('add it first with the todo tool', (string) $refused['error']);

                return;
            }
        }
        self::fail('work:claim-verified is not offered');
    }

    public function testAPromotionMaterialisesThePathsItCarriedAndOnlyThose(): void
    {
        self::assertTrue($this->claim('t1', 'artifact-created', 'config/screens.json')['ok'], 'the promotion put it in the house');
        $other = $this->claim('t2', 'artifact-created', 'config/app.php');
        self::assertFalse($other['ok'], 'a path the promotion did not carry is not covered');
    }

    /** @return array<string, mixed> */
    private function claim(string $todo, string $kind, string $reference): array
    {
        foreach ((new SessionBookkeeping($this->store, 's1', $this->events))->operations() as $operation) {
            if ($operation->name === 'work:claim-verified') {
                /** @var array<string, mixed> */
                return ($operation->handler)(['todo' => $todo, 'kind' => $kind, 'reference' => $reference]);
            }
        }
        self::fail('work:claim-verified is not offered');
    }
}
