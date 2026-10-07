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

use Milpa\Agent\EvidenceKind;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The contract of work:claim-verified says which reference the session holds (greenhouse evidence/1116).
 *
 * The judge compares a claim's reference letter by letter against identifiers of the session's own record, and
 * until now only its REFUSAL said which those are: the contract asked for «the artifact, operation or test a
 * reader can re-check». Measured on three runs of the real resident (evidence/1109 §6.4, evidence/1112): the first
 * round of claims is written as prose every time — «plugins.register name=Blog → config/plugins.php promoted into
 * house» — and refused, 15 of 15, before the same model copies what the refusal dictates. That round is a whole
 * model call (31,615 tokens and 73 s on BV-4).
 *
 * The judge does not move. Its contract now says, kind by kind, which identifier it compares against, with one
 * example of each that the judge itself accepts.
 *
 * @guards the contract naming, for every kind the judge knows, the identifier the session holds, with an example
 *         the judge accepts as written; the contract staying the same whatever the session holds, so the leg's
 *         catalogue does not change under the model
 *
 * @refuses an example the judge would not accept; a kind the judge knows and the contract does not explain; the
 *          same identifier wrapped in a sentence
 *
 * @subject-in milpa/app-runtime
 */
final class TheClaimContractSaysWhichReferenceTheSessionHoldsTest extends TestCase
{
    /** The names this session used, for each placeholder the contract's examples show. */
    private const NAMES = ['<Name>' => 'Blog', '<Plugin>' => 'Blog', '<path>' => 'blog'];

    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s1', 'build the blog page');
        foreach (['t1', 't2', 't3', 't4'] as $id) {
            $this->store->setTodo('s1', new Todo($id, 'Work ' . $id, TodoStatus::Pending));
        }
    }

    public function testTheContractExplainsEveryKindTheJudgeKnows(): void
    {
        $contract = $this->contract('s1');
        $kinds = $contract->inputSchema['properties']['kind']['enum'];

        $known = array_map(static fn (EvidenceKind $kind): string => str_replace('_', '-', $kind->value), EvidenceKind::cases());
        self::assertEqualsCanonicalizing($known, $kinds, 'every kind the judge knows is offered, and no other');
        self::assertSame($kinds, array_keys(self::examples($contract)), 'and the contract says, for each in turn, which reference the session holds');
    }

    /**
     * An example shows the FORM of a reference, never a name of its own (greenhouse decisions/0594 §5): a
     * contract is read by every session, whatever it is building. So the judge is given the example with this
     * session's own names where its placeholders are — and that is a reference it accepts.
     */
    public function testTheExampleOfEachKindIsAReferenceTheJudgeAccepts(): void
    {
        $examples = self::examples($this->contract('s1'));
        $this->theSessionDidTheWorkOfTheExamples();

        foreach (array_values($examples) as $n => $example) {
            $kind = array_keys($examples)[$n];
            $held = strtr($example, self::NAMES);
            self::assertStringNotContainsString('<', $held, "«{$example}» has a placeholder this session has no name for");
            $claimed = $this->claim('t' . ($n + 1), $kind, $held);
            self::assertTrue($claimed['ok'], "the contract's own example for {$kind}, «{$example}», written with this session's names («{$held}»), is refused: " . ($claimed['error'] ?? ''));
        }
    }

    /** A form sent back as the contract shows it is no reference of this session, and the judge says so. */
    public function testAnExampleCopiedWithItsPlaceholderIsRefused(): void
    {
        $examples = array_filter(self::examples($this->contract('s1')), static fn (string $example): bool => str_contains($example, '<'));
        $this->theSessionDidTheWorkOfTheExamples();

        self::assertCount(3, $examples, 'three kinds are shown by a form; the fourth by a tool of the house');
        foreach ($examples as $kind => $example) {
            $claimed = $this->claim('t1', $kind, $example);
            self::assertFalse($claimed['ok'], "«{$example}» was accepted as written");
            self::assertStringContainsString($example, (string) ($claimed['error'] ?? ''), 'the refusal names what it looked for');
        }
    }

    public function testTheSameIdentifierInsideASentenceIsRefusedAsTheContractSays(): void
    {
        $contract = $this->contract('s1');
        $this->theSessionDidTheWorkOfTheExamples();

        self::assertStringContainsString('not a sentence', $contract->inputSchema['properties']['reference']['description']);
        foreach (array_values(self::examples($contract)) as $n => $example) {
            $kind = array_keys(self::examples($contract))[$n];
            self::assertFalse($this->claim('t' . ($n + 1), $kind, strtr($example, self::NAMES) . ' (done, verified in the house)')['ok'], "a sentence around «{$example}» was accepted");
        }
    }

    public function testTheContractSaysTheReferenceIsCopiedFromTheRecordNotWritten(): void
    {
        $contract = $this->contract('s1');

        self::assertStringContainsString('already holds', $contract->description);
        self::assertStringContainsString('refused', $contract->description);
        self::assertStringNotContainsString('a reader can re-check', $contract->inputSchema['properties']['reference']['description'], 'the sentence that invited prose is gone');
    }

    public function testTheContractIsTheSameWhateverTheSessionHolds(): void
    {
        $empty = $this->contract('s1');
        $this->theSessionDidTheWorkOfTheExamples();
        $working = $this->contract('s1');

        self::assertSame($empty->description, $working->description);
        self::assertSame($empty->inputSchema, $working->inputSchema, 'the catalogue a leg sends does not change as the session works');
    }

    /** The work whose record holds one reference of each kind: the ones the contract gives as examples. */
    private function theSessionDidTheWorkOfTheExamples(): void
    {
        $this->store->recordToolCall('s1', 'test', ['filter' => 'BlogTest'], '{"ok":true,"tests":3,"failures":0}', true);
        $this->store->recordToolCall('s1', 'plugins_register', ['name' => 'Blog'], '{"ok":true}', true, true);
        $this->store->recordToolCall(
            's1',
            'sandbox_promote',
            ['workspace' => 'w1'],
            '{"ok":true,"promoted":["src/Plugins/Blog/Blog.php"],"evidence":{"predicate":"promoted","subject":"w1"}}',
            true,
            true,
        );
        $this->store->recordToolCall(
            's1',
            'route_observe',
            ['path' => '/blog'],
            '{"ok":true,"observed":[{"predicate":"served","route":"GET /blog","subject":"/blog","status":200,"environment":{"kind":"house"},"servedAt":"/blog"}]}',
            true,
        );
    }

    /**
     * The example the contract gives for each kind, in the order it gives them.
     *
     * @return array<string, string>
     */
    private static function examples(Operation $contract): array
    {
        $described = $contract->inputSchema['properties']['reference']['description'];
        preg_match_all('/(?<kind>[a-z]+-[a-z]+): [^;]*?`(?<example>[^`]+)`(?=;|\.)/', $described, $found, \PREG_SET_ORDER);

        return array_column($found, 'example', 'kind');
    }

    private function contract(string $session): Operation
    {
        foreach ((new SessionBookkeeping($this->store, $session, $this->events))->operations() as $operation) {
            if ($operation->name === 'work:claim-verified') {
                return $operation;
            }
        }
        self::fail('work:claim-verified is not offered');
    }

    /** @return array<string, mixed> */
    private function claim(string $todo, string $kind, string $reference): array
    {
        /** @var array<string, mixed> */
        return ($this->contract('s1')->handler)(['todo' => $todo, 'kind' => $kind, 'reference' => $reference]);
    }
}
