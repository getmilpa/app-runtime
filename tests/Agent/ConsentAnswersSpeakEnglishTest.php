<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\AffirmativeAnswer;
use Milpa\AppRuntime\Agent\SessionGrants;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Web\BoardPage;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The consent wire speaks the question's language (greenhouse decisions/0518).
 *
 * The questions went English in agent 0.51.1; their answers stayed «sí»/«no». What the house PRODUCES
 * is now `yes`/`no` — the options a question offers, the answer a grant records, the button a board
 * posts, the wording an MCP client reads. What the house READS keeps every older spelling: a recorded
 * «sí» is history and must replay to the verdict it reached, and an older client that still posts «sí»
 * keeps working through the 0.x line.
 *
 * @internal
 */
final class ConsentAnswersSpeakEnglishTest extends TestCase
{
    private InMemoryEventStore $events;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
    }

    public function testTheProducedVocabularyIsYesAndNo(): void
    {
        self::assertSame('yes', AffirmativeAnswer::YES);
        self::assertSame('no', AffirmativeAnswer::NO);
        self::assertSame(['yes', 'no'], AffirmativeAnswer::OPTIONS, 'allow first: surfaces show the options in this order');
    }

    public function testTheReaderAcceptsTheProducedYesAndEveryOlderSpelling(): void
    {
        foreach (['yes', 'Yes', ' YES ', 'y'] as $yes) {
            self::assertTrue(AffirmativeAnswer::is($yes), "«{$yes}» is the produced yes");
        }
        foreach (['sí', 'Sí', 'si', 's'] as $legacy) {
            self::assertTrue(AffirmativeAnswer::is($legacy), "«{$legacy}» is what an older client or recorded session wrote");
        }
        foreach (['no', 'No', '', 'nope', 'sip', 'yes please', 'no, sí'] as $not) {
            self::assertFalse(AffirmativeAnswer::is($not), "«{$not}» is not consent");
        }
    }

    public function testAYesAnswerGrantsTheOperation(): void
    {
        $this->parked(['yes', 'no']);

        $r = $this->answer(['session' => 's1', 'answer' => 'yes']);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('make', $r['granted']);
        self::assertTrue($this->store()->load('s1')?->allows('make'));
    }

    public function testANoAnswerGrantsNothing(): void
    {
        $this->parked(['yes', 'no']);

        $r = $this->answer(['session' => 's1', 'answer' => 'no']);

        self::assertTrue($r['ok']);
        self::assertNull($r['granted']);
        self::assertFalse($this->store()->load('s1')?->allows('make'));
    }

    /** COMPATIBILITY: an older client posting «sí» to a question parked before 0518 still grants. */
    public function testAnOlderClientsSíOnAnOlderQuestionStillGrants(): void
    {
        $this->parked(['sí', 'no']);

        $r = $this->answer(['session' => 's1', 'answer' => 'sí']);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('make', $r['granted']);
        self::assertSame('sí', $this->store()->load('s1')?->decisions[0]['answer'] ?? null, 'the human\'s words are recorded as said, never rewritten');
    }

    /** COMPATIBILITY, the other way round: a new panel's `yes` answers a question an older house parked. */
    public function testANewClientsYesAnswersAnOlderQuestion(): void
    {
        $this->parked(['sí', 'no']);

        self::assertSame('make', $this->answer(['session' => 's1', 'answer' => 'yes'])['granted']);
    }

    /** REPLAY: a recorded «sí» derives the very grant a recorded `yes` does — history keeps its verdict. */
    public function testARecordedSíDerivesTheSameGrantAsARecordedYes(): void
    {
        $decision = static fn (string $answer): array => [
            'question' => 'The agent wants to run «make». Do you allow it in this session?',
            'answer' => $answer,
            'reason' => 'permission',
            'why' => '{"operation":"make","arguments":{"plugin":"Blog"}}',
        ];
        $now = new \DateTimeImmutable('2026-09-29T12:00:00Z');

        $yes = SessionGrants::of([$decision('yes')], 's1', $now, []);
        $sí = SessionGrants::of([$decision('sí')], 's1', $now, []);

        self::assertCount(1, $yes);
        self::assertEquals($yes, $sí, 'the spelling decides nothing: the same fact, the same grant');
        self::assertSame([], SessionGrants::of([$decision('no')], 's1', $now, []), 'and «no» derives none');
    }

    public function testTheAnswerParameterTellsAnMcpClientToSayYes(): void
    {
        $description = '';
        foreach ((new SessionOperations($this->container()))->operations() as $operation) {
            if ($operation->name === 'agent:answer') {
                $description = (string) ($operation->inputSchema['properties']['answer']['description'] ?? '');
            }
        }

        self::assertStringContainsString('`yes`', $description);
        self::assertStringNotContainsString('sí', $description, 'the wording a model reads is the wording it must post');
    }

    public function testTheBoardsYesButtonPostsYes(): void
    {
        $html = (new BoardPage())->render('s');

        self::assertStringContainsString("answer('yes')", $html);
        self::assertStringNotContainsString("answer('sí')", $html);
    }

    /** @param list<string> $options */
    private function parked(array $options): void
    {
        $store = $this->store();
        $store->start('s1', 'build the blog');
        $store->ask('s1', new PendingQuestion('perm:make', 'The agent wants to run «make». Do you allow it in this session?', $options, reason: 'permission'));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function answer(array $input): array
    {
        foreach ((new SessionOperations($this->container()))->operations() as $operation) {
            if ($operation->name === 'agent:answer') {
                $handler = $operation->handler;
                self::assertIsCallable($handler);

                /** @var array<string, mixed> $r */
                $r = $handler($input);

                return $r;
            }
        }

        self::fail('agent:answer is not declared');
    }

    private function store(): SessionStore
    {
        return new SessionStore($this->events);
    }

    private function container(): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $this->store());

        return $container;
    }
}
