<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\CandidateState;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The native read shares the SDK and reaches it only through the declared scope. */
final class CandidateStateOperationTest extends TestCase
{
    private DIContainer $container;
    private SessionStore $sessions;
    private Operation $operation;

    protected function setUp(): void
    {
        $this->container = new DIContainer();
        $this->sessions = new SessionStore(new InMemoryEventStore());
        $this->container->registerService(SessionStore::class, $this->sessions);
        $found = array_values(array_filter((new AgentOperations($this->container))->operations(), static fn ($op) => $op->name === 'candidate:state'));
        self::assertCount(1, $found);
        $this->operation = $found[0];
    }

    public function testTheCatalogueDescribesAReadWithExplicitAuthority(): void
    {
        self::assertSame(['agent:read'], $this->operation->scopes);
        self::assertFalse($this->operation->mutating);
        self::assertFalse($this->operation->requiresConfirmation);
        self::assertSame(Mutation::None, $this->operation->effects->mutation);
        self::assertSame(Authority::Read, $this->operation->effects->authority);
    }

    public function testMissingOrMalformedInputsDoNotReadASession(): void
    {
        foreach ([[], ['session' => []], ['session' => 'missing', 'workspace' => []]] as $input) {
            $r = ($this->operation->handler)($input);
            self::assertFalse($r['ok']);
            self::assertStringContainsString('required', $r['error']);
        }
        $r = ($this->operation->handler)(['session' => 'missing', 'workspace' => 'w1234567890abcdef']);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('does not exist', $r['error']);
    }

    public function testTheOperationUsesTheSdkWithoutAppendingAnEvent(): void
    {
        $this->sessions->start('s1', 'read the candidate');
        $before = $this->sessions->stream('s1');
        $expected = ['ok' => true, 'session' => 's1'] + CandidateState::read(dirname(__DIR__, 2), $before, 'w1234567890abcdef');
        $actual = ($this->operation->handler)(['session' => 's1', 'workspace' => 'w1234567890abcdef']);
        self::assertSame($expected, $actual);
        self::assertSame('trial_receipt_missing', $actual['reason']);
        self::assertSame($before, $this->sessions->stream('s1'));
    }

    public function testTheNativeToolGateRefusesAnUnscopedRead(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll([$this->operation], $registry, $this->container);
        $args = ['session' => 'missing', 'workspace' => 'w1234567890abcdef'];
        $denied = $registry->call('candidate_state', $args, new ToolContext(principal: 'reader', scopes: ['agent:run']));
        self::assertFalse($denied->success);
        self::assertStringContainsString('agent:read', $denied->error ?? '');
        $allowed = $registry->call('candidate_state', $args, new ToolContext(principal: 'reader', scopes: ['agent:read']));
        self::assertTrue($allowed->success, $allowed->error ?? '');
        self::assertFalse($allowed->data['ok']);
        self::assertStringContainsString('does not exist', $allowed->data['error']);
    }
}
