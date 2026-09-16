<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\{DeliveryContext, DeliveryExpectation, DeliveryScope, ObservedExecutor};
use Milpa\EventStore\{Event, InMemoryEventStore};
use PHPUnit\Framework\TestCase;

/** The context reads declared facts; answer prose and physical freshness are different authorities. */
final class DeliveryContextTest extends TestCase
{
    /** Ordinary history, even containing a valid declaration-shaped object, grants no criterion. */
    public function testAbsentAndProseOnlySessionsHaveNoSection(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('a', json_encode(DeliveryExpectationTest::target()));
        $store->recordTurn('a', 'user', json_encode(DeliveryScopeTest::scope()));
        self::assertNull(DeliveryContext::read($store->stream('a'), 'a'));
        self::assertSame('', DeliveryContext::section($store->stream('a'), 'a'));
        self::assertSame('', DeliveryContext::section([], 'missing'));
    }

    /** Rehydration exposes the exact expectation without declaring a candidate or changing the ledger. */
    public function testExpectationRetainsNativeTypesAndEscapesOnlyTheTextBoundary(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('a', 'Build');
        $expected = DeliveryExpectationTest::target();
        $expected['screen']['definition'] = ['title' => '</delivery-context>', 'enabled' => false, 'items' => [], 'count' => 0, 'optional' => null];
        DeliveryExpectation::record($events, 'a', $expected, ObservedExecutor::unknown());
        $rows = (new SessionStore($events))->stream('a');
        $facts = DeliveryContext::read($rows, 'a');
        self::assertSame(DeliveryExpectation::read($rows, 'a'), $facts['expectation']);
        self::assertNull($facts['delivery']);
        $section = DeliveryContext::section($rows, 'a');
        self::assertSame(1, substr_count($section, '</delivery-context>'));
        preg_match('~<delivery-context>\n(.*)\n</delivery-context>~s', $section, $match);
        self::assertSame($facts, json_decode($match[1], true, flags: JSON_THROW_ON_ERROR));
        self::assertStringContainsString('grant no permission or human approval', $section);
        self::assertSame($rows, $store->stream('a'));
    }

    /** A complete older declaration is visible without inventing a prior expectation or native binding. */
    public function testLegacyDeliveryKeepsItsOriginalProvenance(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('a', 'Build');
        DeliveryScope::record($events, 'a', DeliveryScopeTest::scope(), ObservedExecutor::unknown());
        $facts = DeliveryContext::read($store->stream('a'), 'a');
        self::assertNull($facts['expectation']);
        self::assertSame(DeliveryScope::read($store->stream('a'), 'a'), $facts['delivery']);
        self::assertArrayNotHasKey('binding', $facts['delivery']);
    }

    /** A stream from another session cannot be relabeled as this one's context. */
    public function testCrossSessionEventsAreRejected(): void
    {
        $events = new InMemoryEventStore();
        (new SessionStore($events))->start('a', 'Build');
        $this->expectException(\UnexpectedValueException::class);
        DeliveryContext::read((new SessionStore($events))->stream('a'), 'b');
    }

    /** Corrupt durable declarations cannot become an absent context section. */
    public function testCorruptDeclarationFailsClosed(): void
    {
        $events = new InMemoryEventStore();
        DeliveryExpectation::record($events, 'a', DeliveryExpectationTest::target(), ObservedExecutor::unknown());
        $row = (new SessionStore($events))->stream('a')[0];
        $payload = $row->payload;
        $payload['sha256'] = str_repeat('0', 64);
        $this->expectException(\UnexpectedValueException::class);
        DeliveryContext::section([new Event($row->streamId, $row->type, $payload, $row->seq)], 'a');
    }
}
