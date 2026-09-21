<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\ProgressReceipt;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\PrerequisiteGate;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Operations\SessionArgumentOperation;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Previously delivered bytes remain accessible without reopening exploration or granting progress. */
final class SessionArgumentRecoveryTest extends TestCase
{
    private InMemoryEventStore $events;
    private SessionStore $sessions;
    private int $callSeq;
    private int $stallSeq;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->sessions->start('own', 'Build the application', AutonomyMode::Auto);
        $this->callSeq = $this->sessions->recordToolCall('own', 'implement', ['content' => 'retained proposal'], '{"ok":false,"error":"rejected"}', false, true);
        $this->stallSeq = $this->events->nextSeq();
        $this->events->append(new Event(SessionStore::PREFIX . 'own', SessionToolGate::PROGRESS_STALLED, [], $this->stallSeq));
    }

    public function testTheNativeProducerDeclaresTheRecordedArgumentContract(): void
    {
        $operations = (new SessionOperations(new DIContainer()))->operations();
        $reader = current(array_filter($operations, static fn ($operation) => $operation->name === 'agent:argument'));
        self::assertInstanceOf(SessionArgumentOperation::class, $reader);
        self::assertFalse($reader->mutating);
        self::assertSame(['agent:read', 'agent:answer'], $reader->scopes);
    }

    public function testRecoveryOffersAndAdmitsAnOwnRecordedArgumentByContract(): void
    {
        $gate = $this->gate();
        self::assertSame(['source_read'], $gate->recoveryHiddenTools(['history_read', 'source_read']));
        self::assertNull($gate->refuse('history_read', ['session' => 'own', 'seq' => $this->callSeq, 'argument' => 'content']));
        self::assertNotNull($gate->refuse('source_read', ['path' => 'src/Domain.php']));
    }

    public function testANameAloneCannotDeclareARecordedArgumentReader(): void
    {
        $plain = new Operation(name: 'agent:argument', description: 'A generic read with the same name', handler: static fn () => [], effects: EffectProfile::readOnly());
        $gate = $this->gate([$plain]);
        self::assertSame(['agent_argument'], $gate->recoveryHiddenTools(['agent_argument']));
        self::assertNotNull($gate->refuse('agent_argument', ['session' => 'own', 'seq' => $this->callSeq, 'argument' => 'content']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unrelatedTargets(): iterable
    {
        yield 'different session' => [['session' => 'other', 'seq' => 2]];
        yield 'no session' => [['seq' => 2]];
        yield 'no sequence' => [['session' => 'own']];
        yield 'unknown sequence' => [['session' => 'own', 'seq' => 999]];
        yield 'non-call event' => [['session' => 'own', 'seq' => 1]];
        yield 'non-integer sequence' => [['session' => 'own', 'seq' => '2']];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('unrelatedTargets')]
    public function testRecoveryDoesNotAdmitAnUnrelatedOrInvalidTarget(array $arguments): void
    {
        self::assertNotNull($this->gate()->refuse('history_read', $arguments));
    }

    public function testReadingTheArgumentDoesNotClearRecovery(): void
    {
        $gate = $this->gate();
        self::assertNull($gate->refuse('history_read', ['session' => 'own', 'seq' => $this->callSeq, 'argument' => 'content']));
        $this->sessions->recordToolCall('own', 'history_read', ['session' => 'own', 'seq' => $this->callSeq, 'argument' => 'content'], '{"ok":true,"content":"complete stored bytes"}', true, false);
        self::assertNotNull($gate->refuse('source_read', ['path' => 'src/Another.php']));
        $stream = $this->sessions->stream('own');
        self::assertNotSame(ProgressReceipt::ADVANCING, ProgressReceipt::of($stream, $this->stallSeq, end($stream)->seq)->progress);
        self::assertSame(['source_read'], $gate->recoveryHiddenTools(['history_read', 'source_read']));
    }

    public function testRecordedArgumentAccessDoesNotBypassThePrerequisiteGate(): void
    {
        $gate = $this->gate(prerequisite: new PrerequisiteGate(['required_first']));
        $refusal = $gate->refuse('history_read', ['session' => 'own', 'seq' => $this->callSeq, 'argument' => 'content']);
        self::assertNotNull($refusal);
        self::assertStringContainsString('required_first', $refusal);
    }

    /** @param list<Operation>|null $operations */
    private function gate(?array $operations = null, ?PrerequisiteGate $prerequisite = null): SessionToolGate
    {
        $reader = new SessionArgumentOperation(name: 'history:read', description: 'An aliased declared argument reader', handler: static fn () => [], effects: EffectProfile::readOnly());
        $source = new Operation(name: 'source:read', description: 'Inspect current source', handler: static fn () => [], effects: EffectProfile::readOnly());

        return new SessionToolGate($this->sessions, $this->sessions->load('own'), $operations ?? [$reader, $source], compuertaPrevia: $prerequisite);
    }
}
