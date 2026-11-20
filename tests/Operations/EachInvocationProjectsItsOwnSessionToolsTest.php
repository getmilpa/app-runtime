<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\{AdmissionAwareRegistry, PluginAuthoringPolicy, SessionBookkeeping};
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\EventStore\{EventStoreInterface, InMemoryEventStore};
use Milpa\Runtime\{Config, Kernel};
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Events\ToolRuntimeEvents;
use Milpa\ToolRuntime\RateLimiting\{RateLimitResult, RateLimiterInterface};
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EachInvocationProjectsItsOwnSessionToolsTest extends TestCase
{
    private function operations(ToolRegistry $shared, InMemoryEventStore $events, ?string $root = null): AgentOperations
    {
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(Config::class, new Config([]));
        $kernel = Kernel::boot(['root' => $root ?? dirname(__DIR__, 2), 'container' => $container, 'toolRegistry' => $shared, 'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);
        return new AgentOperations($container);
    }

    private function project(AgentOperations $ops, array $extra = [], bool $private = false): AdmissionAwareRegistry
    {
        return (new \ReflectionMethod(AgentOperations::class, 'toolsOfThisApp'))->invoke($ops, $extra, false, $private);
    }

    public function testOneInstanceRebindsNotebookAndDebtAcrossAThenBThenA(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('A', 'First');
        $store->start('B', 'Second');
        $shared = new ToolRegistry(new NullLogger());
        $ops = $this->operations($shared, $events);
        $context = new ToolContext(principal: 'fixture', channel: 'mcp', scopes: ['agent:run']);
        foreach (['A', 'B', 'A'] as $index => $id) {
            $attempt = $store->recordToolCall($id, 'probe', ['index' => $index], 'controlled failure', false);
            $registry = $this->project($ops, (new SessionBookkeeping($store, $id, $events))->operations());
            $plan = $registry->call('plan', ['plan' => 'plan-' . $index], $context);
            self::assertSame($id, $plan->data['session']);
            self::assertSame('plan-' . $index, $store->load($id)->plan);
            $calls = $registry->call('HouseDebtRead', ['mode' => 'calls'], $context)->data['items'];
            usort($calls, static fn (array $a, array $b): int => $b['seq'] <=> $a['seq']);
            self::assertSame($attempt, $calls[0]['seq']);
            $report = $registry->call('HouseDebt', ['owner' => 'environment', 'kind' => 'tool_error', 'statement' => 'Attempt ' . $index . ' failed.', 'about' => [$calls[0]['id']]], $context)->data;
            self::assertTrue($report['recorded']);
            $finding = $registry->call('HouseDebtRead', ['mode' => 'findings', 'id' => $report['id']], $context)->data['items'][0];
            self::assertSame(SessionStore::PREFIX . $id, $finding['references'][0]['stream']);
        }
        self::assertSame([], $shared->getToolDefinitions());
    }

    public function testHostDefinitionsKeepPrecedenceAndLateRegistrationsRemainVisible(): void
    {
        $events = new InMemoryEventStore();
        $shared = new ToolRegistry(new NullLogger());
        $callback = static fn () => 'host';
        $shared->register('probe', 'Host override', ['type' => 'object'], $callback, new ToolOptions(scopes: ['probe:read'], timeout: 17, clamps: ['n' => [1, 2]], version: '1.2.3', outputSchema: ['type' => 'string']));
        $definition = $shared->getDefinition('probe');
        $ops = $this->operations($shared, $events);
        $projected = $this->project($ops, [new Operation('probe', 'Declared fallback', static fn () => 'fallback')]);
        self::assertSame($definition, $projected->getDefinition('probe'));
        self::assertSame('host', $projected->call('probe', [], new ToolContext(principal: 'fixture', channel: 'mcp', scopes: ['probe:read']))->data);
        self::assertFalse($projected->call('probe', [], new ToolContext(principal: 'fixture', channel: 'mcp', scopes: []))->success);
        $shared->register('late', 'Late host tool', [], static fn () => 'late');
        self::assertFalse($projected->has('late'));
        $next = $this->project($ops);
        self::assertSame($shared->getDefinition('late'), $next->getDefinition('late'));
        $next->register('local', 'Invocation growth', [], static fn () => true);
        self::assertFalse($shared->has('local'));
        self::assertFalse($this->project($ops)->has('local'));
    }

    public function testNativeServicesKeepTheirIdentityAndActuallyEnforceAndObserveCalls(): void
    {
        $events = new InMemoryEventStore();
        $dispatcher = new EventDispatcher(new NullLogger());
        $observed = [];
        $dispatcher->subscribe(ToolRuntimeEvents::TOOL_EXECUTED, static function ($name, $payload) use (&$observed): void {
            $observed[] = $payload['event'];
        });
        $shared = new ToolRegistry(new NullLogger(), $dispatcher);
        $calls = 0;
        $shared->register('probe', 'Probe', [], static function () use (&$calls): string {
            ++$calls;
            return 'ok';
        }, new ToolOptions(scopes: ['probe:read']));
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::exactly(2))->method('consume')->willReturnOnConsecutiveCalls(RateLimitResult::allowed(), RateLimitResult::denied('fixture rate ceiling'));
        $shared->setRateLimiter($limiter);
        $registry = $this->project($this->operations($shared, $events));
        self::assertSame($shared->getPolicyGate(), $registry->inner()->getPolicyGate());
        self::assertSame($shared->getConfirmationStore(), $registry->getConfirmationStore());
        self::assertSame($limiter, $registry->getRateLimiter());
        self::assertTrue($registry->hasDispatcher());
        $context = new ToolContext(principal: 'fixture', channel: 'mcp', scopes: ['probe:read']);
        self::assertTrue($registry->call('probe', [], $context)->success);
        self::assertFalse($registry->call('probe', [], $context)->success);
        self::assertSame(1, $calls);
        self::assertCount(1, $observed);
    }

    public function testNestedPolicyScopesRestoreTheOuterGateAfterAnException(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('A', 'Outer');
        $store->start('B', 'Inner');
        $shared = new ToolRegistry(new NullLogger());
        $original = new PluginAuthoringPolicy(dirname(__DIR__, 2));
        $shared->getPolicyGate()->setCallPolicy($original);
        $shared->register('denied', 'Denied scope', [], static fn () => 'must not run', new ToolOptions(scopes: ['missing:scope']));
        $ops = $this->operations($shared, $events);
        $outer = $this->project($ops);
        $seat = new \ReflectionMethod(AgentOperations::class, 'seatThePolicy');
        $restoreOuter = $seat->invoke($ops, $outer, $store, 'A');
        $outerPolicy = $shared->getPolicyGate()->getCallPolicy();
        self::assertNotSame($original, $outerPolicy);
        try {
            $inner = $this->project($ops);
            $restoreInner = $seat->invoke($ops, $inner, $store, 'B');
            try {
                self::assertNotSame($outerPolicy, $shared->getPolicyGate()->getCallPolicy());
                throw new \RuntimeException('nested fixture failure');
            } finally {
                $restoreInner();
            }
        } catch (\RuntimeException $error) {
            self::assertSame('nested fixture failure', $error->getMessage());
            self::assertSame($outerPolicy, $shared->getPolicyGate()->getCallPolicy());
            self::assertFalse($outer->call('denied', [], new ToolContext(principal: 'fixture', channel: 'mcp', scopes: []))->success);
        } finally {
            $restoreOuter();
        }
        self::assertSame($original, $shared->getPolicyGate()->getCallPolicy());
    }

    public function testDeclaredGrowthStaysLocalAndRefoldsOnTheNextInvocation(): void
    {
        $root = sys_get_temp_dir() . '/milpa-registry-growth-' . bin2hex(random_bytes(6));
        mkdir($root . '/config', 0777, true);
        $file = $root . '/config/operations.php';
        file_put_contents($file, '<?php return [];');
        try {
            $events = new InMemoryEventStore();
            $shared = new ToolRegistry(new NullLogger());
            $ops = $this->operations($shared, $events, $root);
            $first = $this->project($ops);
            $grown = (new \ReflectionMethod(AgentOperations::class, 'grownDoor'))->invoke($ops, $first, null);
            self::assertFalse($first->has('fixture_grown'));
            file_put_contents($file, '<?php return [' . var_export(InvocationGrowthFixture::class, true) . '];');
            $grown('fixture_grown');
            self::assertSame('grown', $first->call('fixture_grown', [], new ToolContext(principal: 'fixture', channel: 'mcp'))->data);
            self::assertFalse($shared->has('fixture_grown'));
            self::assertTrue($this->project($ops)->has('fixture_grown'));
        } finally {
            unlink($file);
            rmdir($root . '/config');
            rmdir($root);
        }
    }

    public function testExplicitPrivateChildRegistryKeepsItsExistingSeedBoundary(): void
    {
        $events = new InMemoryEventStore();
        $shared = new ToolRegistry(new NullLogger());
        $shared->register('host_only', 'Host only', [], static fn () => 'host');
        $ops = $this->operations($shared, $events);
        $parent = $this->project($ops, [new Operation('parent_only', 'Parent', static fn () => 'parent')]);
        $child = $this->project($ops, [new Operation('child_only', 'Child', static fn () => 'child')], true);
        self::assertTrue($parent->has('host_only'));
        self::assertFalse($child->has('host_only'));
        self::assertFalse($child->has('parent_only'));
        self::assertTrue($child->has('child_only'));
        self::assertFalse($parent->has('child_only'));
    }
}

/** Declared capability fixture for the native lazy refold seam. */
final class InvocationGrowthFixture implements \Milpa\Command\CommandProvider
{
    public function operations(): array
    {
        return [new Operation('fixture:grown', 'A newly declared fixture', static fn () => 'grown', effects: \Milpa\Command\Effect\EffectProfile::readOnly())];
    }
}
