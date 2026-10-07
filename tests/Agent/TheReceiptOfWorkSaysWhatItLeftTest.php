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

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\ExecutionRecorder;
use Milpa\AppRuntime\Agent\LandedCalls;
use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The receipt of work is the house's, and it says what the work left (greenhouse decisions/0588, rule 5).
 *
 * `session.operation_executed` said «it executed» of a rehearsal and of an act in the house alike. For a work call
 * the house ran in itself, the fact now says `environment: house` and carries the state it touched: each path and
 * its digest before and after. Equal digests are «it did not change» — and what reads whether a call reached the
 * house reads that: an `ok: true` that wrote nothing did not land.
 *
 * @guards the receipt carries what the trial layer saw of the call it ran, asked of that layer and never read out of
 *         a result; a call the house saw nothing of leaves the receipt as it was; the fact in the ledger says where
 *         it ran, the paths and their digests; a work call whose state did not change has not reached the house, and
 *         a claim that leans on it is told so
 *
 * @refuses a receipt written from what a tool answered; calling landed a work call that left the house as it was
 *
 * @subject-in milpa/app-runtime
 */
final class TheReceiptOfWorkSaysWhatItLeftTest extends TestCase
{
    private const LANDED = [
        'environment' => 'house',
        'confined' => true,
        'state' => [['path' => 'var/herramientas.json', 'before' => 'sha256:aaa', 'after' => 'sha256:bbb']],
        'changed' => true,
        'pre_image' => 'k0123456789abcdef',
    ];

    public function testTheReceiptCarriesWhatTheTrialLayerSawOfTheCall(): void
    {
        $asked = [];
        [$bridge, $witness] = $this->bridge(static function (string $tool, array $args) use (&$asked): ?array {
            $asked[] = [$tool, $args];

            return $tool === 'herramientas_agregar' ? self::LANDED : null;
        });

        $bridge->callTool('herramientas_agregar', ['nombre' => 'Sierra']);

        self::assertSame([['herramientas_agregar', ['nombre' => 'Sierra']]], $asked, 'asked of the layer that ran it, with the call');
        self::assertSame(self::LANDED, $witness->facts[0]['landed']);
        self::assertSame('herramientas.agregar', $witness->facts[0]['operation']);
    }

    public function testAResultThatLooksLikeWorkWritesNothingIntoTheReceipt(): void
    {
        [$bridge, $witness] = $this->bridge(static fn (): ?array => null);

        $result = $bridge->callTool('forjador', []);

        self::assertTrue($result['ran_in_house'], 'the tool answered in the shape of a work call');
        self::assertNull($witness->facts[0]['landed'], 'and what a tool answers is data: the receipt says nothing of a state nobody digested');
    }

    /**
     * AN ATTEMPT IS NOT A FACT — AND A CALL THAT FAILED AFTER WRITING IS NOT ONLY AN ATTEMPT. The house saw its state
     * change, so the receipt says so; a failure that left the house as it was leaves nothing behind, as before.
     */
    public function testACallThatFailedAfterChangingTheHouseStillLeavesItsReceipt(): void
    {
        [$bridge, $witness] = $this->bridge(static fn (string $tool, array $args): ?array => ['changed' => ($args['wrote'] ?? false) === true] + self::LANDED);

        try {
            $bridge->callTool('falla', ['wrote' => true]);
            self::fail('the call did not fail');
        } catch (\Throwable) {
        }
        self::assertCount(1, $witness->facts, 'the house changed: that is a fact');
        self::assertSame('falla', $witness->facts[0]['operation']);
        self::assertTrue($witness->facts[0]['landed']['changed']);

        try {
            $bridge->callTool('falla', ['wrote' => false]);
        } catch (\Throwable) {
        }
        self::assertCount(1, $witness->facts, 'a failure that left the house as it was is an attempt, and leaves nothing');
    }

    public function testABridgeNobodyToldAboutWorkLeavesTheReceiptAsItWas(): void
    {
        [$bridge, $witness] = $this->bridge(null);

        $bridge->callTool('herramientas_agregar', ['nombre' => 'Sierra']);

        self::assertNull($witness->facts[0]['landed']);
    }

    public function testTheFactInTheLedgerSaysWhereItRanAndWhatItLeft(): void
    {
        self::needsAReceiptThatSaysWhereItRan();
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'lend the drill');
        $session = $sessions->load('s');
        self::assertNotNull($session);
        $gate = new SessionToolGate($sessions, $session, []);

        $gate->executed('herramientas.agregar', new Principal('key:SEAT', true), 'cli', null, 'sha256:args', self::LANDED);
        $gate->executed('config.set', new Principal('key:SEAT', true), 'cli', null, 'sha256:other');

        $facts = array_values(array_filter($sessions->stream('s'), static fn ($e): bool => $e->type === 'session.operation_executed'));
        self::assertCount(2, $facts);
        self::assertSame('house', $facts[0]->payload['environment']);
        self::assertSame(self::LANDED['state'], $facts[0]->payload['state']);
        self::assertTrue($facts[0]->payload['changed']);
        self::assertTrue($facts[0]->payload['confined']);
        self::assertSame(self::LANDED['pre_image'], $facts[0]->payload['pre_image']);
        self::assertArrayNotHasKey('environment', $facts[1]->payload, 'an execution the house saw nothing of is the fact it was');
    }

    public function testAWorkCallWhoseStateChangedReachedTheHouse(): void
    {
        [$sessions, $seq] = $this->aWorkCall(changed: true);

        $calls = LandedCalls::of($sessions->stream('s'));

        self::assertTrue($calls->reached($seq));
        self::assertNull($calls->rehearsalOf('herramientas_agregar'), 'nothing of it stayed outside the house');
        self::assertSame(['seq' => $seq], $calls->answeredOk('herramientas_agregar'));
    }

    public function testAnOkThatLeftTheHouseAsItWasDidNotReachIt(): void
    {
        [$sessions, $seq] = $this->aWorkCall(changed: false);

        $calls = LandedCalls::of($sessions->stream('s'));

        self::assertFalse($calls->reached($seq), 'it answered ok and the house\'s state is what it was');
        self::assertNull($calls->answeredOk('herramientas_agregar'), 'so no call answers for the tool in the house');
        self::assertNull($calls->executed('herramientas.agregar'), 'and its receipt is not of something that landed');
        $rests = $calls->rehearsalOf('herramientas.agregar');
        self::assertSame(['seq' => $seq, 'workspace' => null, 'promotable' => false, 'left_as_it_was' => true], $rests, 'in either spelling');
        $told = LandedCalls::refusal('herramientas_agregar', $rests);
        self::assertStringContainsString('left the house as it was', $told);
        self::assertStringContainsString("seq {$seq}", $told);
        self::assertStringNotContainsString('trial', $told, 'it never ran in a trial, and is not told to promote anything');
        self::assertStringNotContainsString('sandbox:promote', $told);
    }

    public function testACallTheHouseSawNothingOfIsReadAsBefore(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('s', 'lend the drill');
        $seq = $sessions->recordToolCall('s', 'herramientas_agregar', ['nombre' => 'Sierra'], '{"ok":true,"id":1}', true, true);
        $sessions->recordExecution('s', 'herramientas.agregar', null, 'agent', null, 'sha256:a');

        $calls = LandedCalls::of($sessions->stream('s'));

        self::assertTrue($calls->reached($seq));
        self::assertNull($calls->rehearsalOf('herramientas_agregar'));
    }

    /** The receipt's facts ride what milpa/agent adds to the execution fact: over an older one there is nothing to read. */
    private static function needsAReceiptThatSaysWhereItRan(): void
    {
        if (! \Milpa\AppRuntime\Agent\HouseWork::canBeRecordedBy(new SessionStore(new InMemoryEventStore()))) {
            self::markTestSkipped('the milpa/agent installed here keeps only «it executed»: where an execution ran rides the receipt its next release adds');
        }
    }

    /** @return array{0: SessionStore, 1: int} the store and the seq of the work call */
    private function aWorkCall(bool $changed): array
    {
        self::needsAReceiptThatSaysWhereItRan();
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('s', 'lend the drill');
        $digest = static fn (string $bytes): string => 'sha256:' . hash('sha256', $bytes);
        $state = [['path' => 'var/herramientas.json', 'before' => $digest('[]'), 'after' => $changed ? $digest('[1]') : $digest('[]')]];
        $result = ['ran_in_house' => true, 'changed' => $changed, 'state' => $state, 'pre_image' => null, 'output' => ['ok' => true, 'id' => 1]];
        $seq = $sessions->recordToolCall('s', 'herramientas_agregar', ['nombre' => 'Sierra'], (string) json_encode($result), true, true);
        $sessions->recordExecution('s', 'herramientas.agregar', null, 'agent', null, 'sha256:a', [
            'environment' => 'house', 'confined' => true, 'state' => $state, 'changed' => $changed, 'pre_image' => null,
        ]);

        return [$sessions, $seq];
    }

    /**
     * @param (\Closure(string, array<string, mixed>): ?array<string, mixed>)|null $landed
     *
     * @return array{0: ConsentBridge, 1: object{facts: list<array<string, mixed>>}}
     */
    private function bridge(?\Closure $landed): array
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('herramientas_agregar', 'adds a tool', ['type' => 'object'], static fn (): array => ['ok' => true, 'id' => 1], new ToolOptions(mutating: true));
        $registry->register('forjador', 'answers like work', ['type' => 'object'], static fn (): array => [
            'ran_in_house' => true, 'changed' => true, 'state' => [['path' => 'config/app.php', 'before' => 'sha256:a', 'after' => 'sha256:b']],
        ], new ToolOptions(mutating: true));
        $registry->register('falla', 'fails', ['type' => 'object'], static function (): never {
            throw new \RuntimeException('the rule refused');
        }, new ToolOptions(mutating: true));
        $witness = new class () implements ExecutionRecorder {
            /** @var list<array<string, mixed>> */
            public array $facts = [];

            public function executed(string $operation, ?Principal $executedBy, string $executorSource, ?array $authorizedBy, string $argumentsDigest, ?array $landed = null): void
            {
                $this->facts[] = ['operation' => $operation, 'landed' => $landed];
            }
        };

        return [new ConsentBridge($registry, executions: $witness, executor: new ObservedExecutor(new Principal('key:SEAT', true), 'cli'), landed: $landed), $witness];
    }
}
