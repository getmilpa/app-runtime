<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\{DeliveryExpectation, DeliveryScope, ObservedExecutor};
use Milpa\EventStore\{Event, InMemoryEventStore};
use PHPUnit\Framework\TestCase;

/** A criterion precedes execution and cannot be silently replaced on resume. */
final class DeliveryExpectationTest extends TestCase
{
    /** The target has no candidate location or observed test result. */
    public static function target(): array
    {
        return ['test' => ['path' => 'tests/Owned', 'filter' => ''],
            'screen' => ['name' => 'focus', 'type' => 'counter', 'definition' => ['type' => 'counter', 'props' => ['steps' => [2, 1]]]]];
    }

    /** Omission and repetition preserve the original native declaration. */
    public function testDeclarationSurvivesRehydrationAndRepetition(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'Build');
        self::assertNull(DeliveryExpectation::read($sessions->stream('s'), 's'));
        DeliveryExpectation::record($events, 's', self::target(), ObservedExecutor::unknown());
        $before = $sessions->stream('s');
        DeliveryExpectation::record($events, 's', self::target(), ObservedExecutor::unknown());
        self::assertSame($before, $sessions->stream('s'));
        $r = DeliveryExpectation::read((new SessionStore($events))->stream('s'), 's');
        self::assertSame(DeliveryExpectation::parse(json_encode(self::target())), $r['expected']);
        self::assertSame(['source' => 'agent_invocation', 'channel' => 'unknown', 'principal' => null], $r['provenance']);
        self::assertSame([2, 1], $r['expected']['screen']['definition']['props']['steps']);
        self::assertNull(DeliveryScope::read($sessions->stream('s'), 's'));
        $changed = self::target();
        $changed['test']['filter'] = 'OnlyOne';
        try {
            DeliveryExpectation::record($events, 's', $changed, ObservedExecutor::unknown());
            self::fail('Changed expectation accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $sessions->stream('s'));
        }
    }

    /** A fresh expectation cannot be backdated after model, tool, trial or delivery activity. */
    public function testLateDeclarationsRefuseWithoutAppending(): void
    {
        foreach (['session.turn', 'session.model_called', 'session.tool_called', 'session.trial_run_recorded', DeliveryScope::EVENT] as $type) {
            $events = new InMemoryEventStore();
            $events->append(new Event(SessionStore::PREFIX . 's', $type, [], 1));
            $before = (new SessionStore($events))->stream('s');
            try {
                DeliveryExpectation::record($events, 's', self::target(), ObservedExecutor::unknown());
                self::fail($type);
            } catch (\InvalidArgumentException) {
                self::assertSame($before, (new SessionStore($events))->stream('s'));
            }
        }
    }

    /** Malformed explicit values cannot disappear into an omitted input. */
    public function testMalformedTargetsRefuse(): void
    {
        $cases = [null, false, [], '{}', '{', self::target() + ['workspace' => 'w123456789abc']];
        foreach (['../outside', '/tmp/tests', 'tests//Owned', ' tests '] as $path) {
            $v = self::target();
            $v['test']['path'] = $path;
            $cases[] = $v;
        }
        foreach ($cases as $case) {
            try {
                DeliveryExpectation::parse($case);
                self::fail('Malformed target accepted');
            } catch (\InvalidArgumentException|\JsonException) {
                self::assertTrue(true);
            }
        }
    }

    /** Corruption is a failed read, never the absence of an obligation. */
    public function testCorruptOrLateLedgerDeclarationsFailClosed(): void
    {
        $events = new InMemoryEventStore();
        DeliveryExpectation::record($events, 's', self::target(), ObservedExecutor::unknown());
        $original = (new SessionStore($events))->stream('s')[0];
        foreach (['hash', 'schema', 'session', 'stream', 'source', 'channel', 'duplicate', 'late'] as $case) {
            $p = $original->payload;
            $stream = $original->streamId;
            if ($case === 'hash') {
                $p['sha256'] = 'wrong';
            }
            if ($case === 'schema') {
                $p['schema'] = 'wrong';
            }
            if ($case === 'session') {
                $p['session'] = 'other';
            }
            if ($case === 'stream') {
                $stream = 'other';
            }
            if ($case === 'source') {
                $p['provenance']['source'] = 'model';
            }
            if ($case === 'channel') {
                unset($p['provenance']['channel']);
            }
            $rows = [new Event($stream, DeliveryExpectation::EVENT, $p, 2)];
            if ($case === 'duplicate') {
                $rows[] = new Event($stream, DeliveryExpectation::EVENT, $p, 3);
            }
            if ($case === 'late') {
                array_unshift($rows, new Event($stream, 'session.model_called', [], 1));
            }
            try {
                DeliveryExpectation::read($rows, 's');
                self::fail($case);
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }
}
