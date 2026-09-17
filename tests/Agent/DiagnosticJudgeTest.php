<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\{ProgressReceipt, SessionStore};
use Milpa\AppRuntime\Agent\{DiagnosticContract, DiagnosticJudge, ObservedExecutor, SessionDiagnosticJudge};
use Milpa\EventStore\{Event, InMemoryEventStore};
use PHPUnit\Framework\TestCase;

final class DiagnosticJudgeTest extends TestCase
{
    private const DOCUMENT = '{"left":"cedar","right":"maple","failure":"mismatch"}';
    private const ANSWER = '{"required":"cedar","configured":"maple","matches":false}';

    /** @return array<string,mixed> */
    public static function criterion(): array
    {
        return ['path' => 'tests/fixture.json', 'sha256' => hash('sha256', self::DOCUMENT),
            'fields' => ['required' => 'left', 'configured' => 'right'], 'equals' => ['matches' => ['left', 'right']]];
    }

    /** @return array{InMemoryEventStore,SessionStore} */
    private function ledger(bool $complete = true, bool $delivered = true, bool $structured = false): array
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'Diagnose');
        $criterion = self::criterion() + ($structured ? ['output' => 'json_schema'] : []);
        DiagnosticContract::record($events, 's', $criterion, ObservedExecutor::unknown());
        $offset = 0;
        $parts = [substr(self::DOCUMENT, 0, 20), substr(self::DOCUMENT, 20)];
        foreach ($complete ? $parts : [$parts[0]] as $i => $part) {
            $page = ['ok' => true, 'path' => 'tests/fixture.json', 'sha256' => hash('sha256', self::DOCUMENT),
                'content' => $part, 'offset' => $offset, 'next_offset' => $offset + strlen($part),
                'total_bytes' => strlen(self::DOCUMENT), 'next_cursor' => $i === 0 ? 'cursor-20' : null];
            $args = ['path' => $page['path']] + ($i ? ['cursor' => 'cursor-20'] : []);
            $events->append(new Event(
                SessionStore::PREFIX . 's',
                'session.tool_called',
                ['tool' => 'source_page', 'arguments' => $args, 'result' => json_encode($page), 'ok' => true, 'mutating' => false],
                $events->nextSeq()
            ));
            if ($delivered) {
                $events->append(new Event(
                    SessionStore::PREFIX . 's',
                    'session.model_called',
                    ['messages' => [['role' => 'tool', 'content' => json_encode($page)]]] + ($structured ? ['response_format' => DiagnosticContract::outputFormat($criterion)->toArray()] : []),
                    $events->nextSeq()
                ));
            }
            $offset += strlen($part);
        }
        return [$events, $sessions];
    }

    public function testAcceptedVerdictHasDurableEvidenceButCreatesNoProgress(): void
    {
        [$events, $sessions] = $this->ledger();
        $before = $sessions->stream('s');
        $result = (new SessionDiagnosticJudge($events, 's'))->judge(self::ANSWER);
        self::assertSame('accepted', $result->status);
        self::assertCount(2, $result->evidence['pages']);
        self::assertSame($result->toArray(), DiagnosticJudge::derive('s', $before, self::ANSWER)->toArray());
        $rows = (new SessionStore($events))->stream('s');
        self::assertSame(['candidate' => self::ANSWER, 'verdict' => $result->toArray()], end($rows)->payload);
        self::assertSame('stalled', ProgressReceipt::of($rows, 0, end($rows)->seq)->progress);
        self::assertSame('accepted', DiagnosticJudge::derive('s', $before, "```json\n" . self::ANSWER . "\n```")->status);
    }

    public function testFalseUnsupportedMalformedAndDuplicateCandidatesDoNotClose(): void
    {
        foreach ([[true, true, str_replace('false', 'true', self::ANSWER)], [true, true, str_replace('false', '0', self::ANSWER)],
            [true, true, self::ANSWER . ' extra'], [true, true, '{"required":"cedar","required":"cedar","configured":"maple","matches":false}'],
            [false, true, self::ANSWER], [true, false, self::ANSWER], [true, true, '{}'], [true, true, '[]'],
            [true, true, '{"required":{},"configured":"maple","matches":false}']] as [$complete, $delivered, $candidate]) {
            [, $sessions] = $this->ledger($complete, $delivered);
            self::assertSame('rejected', DiagnosticJudge::derive('s', $sessions->stream('s'), $candidate)->status);
        }
    }

    public function testStructuredDeliveryRequiresItsObservedFormatAndStillRejectsFalseValues(): void
    {
        [, $sessions] = $this->ledger(structured:true);
        $rows = $sessions->stream('s');
        self::assertSame('accepted', DiagnosticJudge::derive('s', $rows, self::ANSWER)->status);
        self::assertSame('rejected', DiagnosticJudge::derive('s', $rows, str_replace('false', 'true', self::ANSWER))->status);
        self::assertSame('rejected', DiagnosticJudge::derive('s', $rows, "```json\n" . self::ANSWER . "\n```")->status);
        foreach ([null, ['type' => 'json_object']] as $format) {
            $bad = $rows;
            foreach ($bad as $i => $event) {
                if ($event->type !== 'session.model_called') {
                    continue;
                }
                $payload = $event->payload;
                if ($format === null) {
                    unset($payload['response_format']);
                } else {
                    $payload['response_format'] = $format;
                }
                $bad[$i] = new Event($event->streamId, $event->type, $payload, $event->seq);
                break;
            }
            $verdict = DiagnosticJudge::derive('s', $bad, self::ANSWER);
            self::assertSame('indeterminate', $verdict->status);
            self::assertSame('output_format_not_observed', $verdict->reason);
        }
    }

    public function testOutputIsOptionalFiniteAndCannotBeAddedToAnExistingDeclaration(): void
    {
        self::assertNull(DiagnosticContract::outputFormat(self::criterion()));
        $criterion = self::criterion() + ['output' => 'json_schema'];
        $format = DiagnosticContract::outputFormat($criterion)->toArray();
        self::assertSame('boolean', $format['json_schema']['schema']['properties']['matches']['type']);
        self::assertSame(['boolean','null','number','string'], $format['json_schema']['schema']['properties']['required']['type']);
        foreach ([null,false,[], 'json_object'] as $invalid) {
            try {
                DiagnosticContract::parse(self::criterion() + ['output' => $invalid]);
                self::fail('Invalid format accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        [$events] = $this->ledger();
        $this->expectException(\InvalidArgumentException::class);
        DiagnosticContract::record($events, 's', $criterion, ObservedExecutor::unknown());
    }

    public function testCriterionIsImmutableAndMustPrecedeExecution(): void
    {
        [$events, $sessions] = $this->ledger();
        $before = $sessions->stream('s');
        DiagnosticContract::record($events, 's', self::criterion(), ObservedExecutor::unknown());
        self::assertSame($before, $sessions->stream('s'));
        $changed = self::criterion();
        $changed['path'] = 'tests/another.json';
        try {
            DiagnosticContract::record($events, 's', $changed, ObservedExecutor::unknown());
            self::fail('Changed criterion accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $sessions->stream('s'));
        }
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('late', 'Diagnose');
        $sessions->recordTurn('late', 'user', 'Already started');
        $this->expectException(\InvalidArgumentException::class);
        DiagnosticContract::record($events, 'late', self::criterion(), ObservedExecutor::unknown());
    }

    public function testInvalidDeclarationsAndProvenanceAreNotSilentlyAbsent(): void
    {
        $bad = [null, '{}', self::criterion() + ['answer' => true]];
        foreach (['path' => '../outside', 'sha256' => 'bad', 'fields' => ['1bad' => 'left'], 'equals' => ['required' => ['left', 'right']]] as $key => $value) {
            $item = self::criterion();
            $item[$key] = $value;
            $bad[] = $item;
        }
        foreach ($bad as $criterion) {
            try {
                DiagnosticContract::parse($criterion);
                self::fail('Bad criterion accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(DiagnosticContract::parse(self::criterion()), DiagnosticContract::parse(json_encode(self::criterion())));
        [, $sessions] = $this->ledger();
        $rows = $sessions->stream('s');
        $p = $rows[1]->payload;
        $p['sha256'] = str_repeat('0', 64);
        $rows[1] = new Event($rows[1]->streamId, $rows[1]->type, $p, $rows[1]->seq);
        $this->expectException(\UnexpectedValueException::class);
        DiagnosticContract::read($rows, 's');
    }
}
