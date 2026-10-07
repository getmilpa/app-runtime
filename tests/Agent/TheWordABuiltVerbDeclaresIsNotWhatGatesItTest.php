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

use Milpa\AppRuntime\Agent\AdmissionAwareRegistry;
use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolDefinition;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Through the doors a seat really calls by, the word a built verb declares is not what gates it (greenhouse
 * decisions/0590, rules 1 and 2).
 *
 * The tool runtime asks for the declared word BEFORE it asks the house's policy, and it knows nothing of
 * admissions. So where a seat calls a built verb, the house hands that one call the verb's own words and lets its
 * policy be the judge — and hands them to nothing else: an admission never becomes a word the seat holds.
 */
final class TheWordABuiltVerbDeclaresIsNotWhatGatesItTest extends TestCase
{
    use BuiltHouse;

    public function testAnAdmittedSeatRunsTheVerbWithoutHoldingItsWord(): void
    {
        [$registry, $root, $kernel] = $this->leg();
        $this->admit($root, $kernel, 'herramientas:write');
        $seat = $this->seat();

        $gate = $registry->getPolicyGate()->authorizeCall($seat, $this->definition($registry, 'herramientas_prestar'), []);
        self::assertTrue($gate->allowed, (string) $gate->reason);

        $result = $registry->call('herramientas_prestar', [], $seat);
        self::assertTrue($result->success, (string) $result->error);
        self::assertSame(['ok' => true, 'ran' => 'herramientas.prestar'], $result->data);
    }

    public function testASeatNobodyAdmittedReadsTheHousesSentenceNotTheRuntimes(): void
    {
        [$registry] = $this->leg();
        $seat = $this->seat();

        $gate = $registry->getPolicyGate()->authorizeCall($seat, $this->definition($registry, 'herramientas_prestar'), []);
        self::assertFalse($gate->allowed);
        self::assertStringContainsString('no person has admitted it for this seat', (string) $gate->reason);
        self::assertStringNotContainsString('Missing required scope', (string) $gate->reason);

        $result = $registry->call('herramientas_prestar', [], $seat);
        self::assertFalse($result->success);
        self::assertStringContainsString('no person has admitted it for this seat', (string) $result->error);
    }

    /**
     * The escalation by name: a built verb declares a word another operation of the house asks for. Admitting the
     * verb must not hand the seat that word anywhere else.
     */
    public function testWhatWasAdmittedOpensNothingOutsideItsCapability(): void
    {
        [$registry, $root, $kernel] = $this->leg();
        $this->admit($root, $kernel, 'herramientas:write');
        $seat = $this->seat();

        $gate = $registry->getPolicyGate()->authorizeCall($seat, $this->definition($registry, 'almacen_vaciar'), []);
        self::assertFalse($gate->allowed);
        self::assertStringContainsString('Missing required scope', (string) $gate->reason);
        self::assertFalse($registry->call('almacen_vaciar', [], $seat)->success);
        self::assertNotContains('herramientas:write', $this->ledger($root)->scopesFor(self::SEAT) ?? []);
    }

    public function testWhoIsNotASeatIsStillJudgedByTheWord(): void
    {
        [$registry] = $this->leg();
        $without = new ToolContext('passkey:QM1LEWEfsoWiMm', 'cli', ['agent:read']);
        $with = new ToolContext('passkey:QM1LEWEfsoWiMm', 'cli', ['herramientas:write']);

        $refused = $registry->getPolicyGate()->authorizeCall($without, $this->definition($registry, 'herramientas_prestar'), []);
        self::assertFalse($refused->allowed);
        self::assertStringContainsString('Missing required scope', (string) $refused->reason);
        self::assertFalse($registry->call('herramientas_prestar', [], $without)->success);
        self::assertTrue($registry->call('herramientas_prestar', [], $with)->success);
    }

    /** The words are handed over only when the judge that will ask for the admission is the gate's own policy. */
    public function testWithAnotherPolicyOnTheGateNothingIsHandedOver(): void
    {
        [$registry] = $this->leg();
        $registry->getPolicyGate()->setCallPolicy(new class () implements CallPolicy {
            public function authorize(ToolContext $context, ToolDefinition $tool, array $arguments): AuthorizationResult
            {
                return AuthorizationResult::allowed();
            }
        });

        $gate = $registry->getPolicyGate()->authorizeCall($this->seat(), $this->definition($registry, 'herramientas_prestar'), []);

        self::assertFalse($gate->allowed);
        self::assertStringContainsString('Missing required scope', (string) $gate->reason);
        self::assertFalse($registry->call('herramientas_prestar', [], $this->seat())->success);
    }

    /** The leg seats its policy on the gate it is handed: that must reach the gate the registry really asks. */
    public function testAPolicySetOnTheGateReachesTheRegistryItWraps(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $inner = $this->registry($root, $kernel);
        $wrapped = new AdmissionAwareRegistry($inner);
        $policy = new PluginAuthoringPolicy($root);

        $wrapped->getPolicyGate()->setCallPolicy($policy);

        self::assertSame($policy, $inner->getPolicyGate()->getCallPolicy());
        self::assertSame($policy, $wrapped->getPolicyGate()->getCallPolicy());
    }

    /**
     * In everything else the wrapper IS the registry it wraps, and its gate the gate it wraps: what a leg reads or
     * sets through one is read or set on the other. A wrapper that answered for itself would show a leg an empty
     * catalogue, or keep a policy the registry never asks.
     */
    public function testEverythingElseIsTheWrappedRegistryAndItsGate(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $inner = $this->registry($root, $kernel);
        $wrapped = new AdmissionAwareRegistry($inner);

        self::assertSame($inner, $wrapped->inner());
        self::assertSame($inner->getToolSummaries(), $wrapped->getToolSummaries());
        self::assertSame($inner->getToolDefinitions(), $wrapped->getToolDefinitions());
        self::assertSame($inner->getToolsByScopes(['herramientas:write']), $wrapped->getToolsByScopes(['herramientas:write']));
        self::assertSame($inner->getToolsByPrefix('herramientas_'), $wrapped->getToolsByPrefix('herramientas_'));
        self::assertSame($inner->getToolsWithinBudget('gpt-4'), $wrapped->getToolsWithinBudget('gpt-4'));
        self::assertSame($inner->getTokenUsageReport(), $wrapped->getTokenUsageReport());
        self::assertSame($inner->estimateTokens(), $wrapped->estimateTokens());
        self::assertSame($inner->checkTokenBudget('gpt-4'), $wrapped->checkTokenBudget('gpt-4'));
        self::assertSame($inner->getTokenEstimator(), $wrapped->getTokenEstimator());
        self::assertSame($inner->getConfirmationStore(), $wrapped->getConfirmationStore());
        self::assertTrue($wrapped->has('herramientas_listar'));
        self::assertSame($inner->getDefinition('herramientas_listar'), $wrapped->getDefinition('herramientas_listar'));
        self::assertSame($inner->hasDispatcher(), $wrapped->hasDispatcher());
        self::assertFalse($wrapped->hasRateLimiter());
        self::assertNull($wrapped->getRateLimiter());

        $wrapped->register('lab_ping', 'a tool registered through the wrapper', ['type' => 'object'], static fn (array $args): array => ['pong' => true]);
        self::assertTrue($inner->has('lab_ping'), 'registered on the registry the leg really calls');
        $limiter = new \Milpa\ToolRuntime\RateLimiting\InMemoryRateLimiter();
        $wrapped->setRateLimiter($limiter);
        self::assertSame($limiter, $inner->getRateLimiter());
        self::assertTrue($wrapped->hasRateLimiter());

        $gate = $wrapped->getPolicyGate();
        $innerGate = $inner->getPolicyGate();
        self::assertSame($innerGate->channelPolicy('cli'), $gate->channelPolicy('cli'));
        $gate->setChannelPolicy('lab', ['allow_all' => true]);
        self::assertSame(['allow_all' => true], $innerGate->channelPolicy('lab'));
        self::assertSame($innerGate->hasRuleProvider(), $gate->hasRuleProvider());
        self::assertSame($innerGate->getRuleProvider(), $gate->getRuleProvider());
        $definition = $this->definition($inner, 'herramientas_prestar');
        self::assertSame($innerGate->requiresConfirmation($this->seat(), $definition), $gate->requiresConfirmation($this->seat(), $definition));
        // The gate's two other questions are asked the house's way too.
        self::assertTrue($gate->authorizeScopes($this->seat(), 'herramientas_prestar', $definition->scopes)->allowed, 'the word is not the question for a seat');
        self::assertFalse($innerGate->authorizeScopes($this->seat(), 'herramientas_prestar', $definition->scopes)->allowed);
        self::assertFalse($gate->authorize($this->seat(), $definition, [])->allowed, 'and the policy still answers: nobody admitted it');
    }

    /** The terminal's door: one process runs one operation, and its signer is handed that operation's words. */
    public function testTheContextOfASeatsCallCarriesTheVerbsWordsForThatCallOnly(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $policy = new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel));
        $seat = $this->seat();

        $shaped = $policy->contextFor($seat, 'herramientas_prestar');
        self::assertSame([...self::SEAT_SCOPES, 'herramientas:write'], $shaped->scopes);
        self::assertSame($seat->principal, $shaped->principal);
        self::assertSame($seat->channel, $shaped->channel);
        self::assertSame($seat->request_id, $shaped->request_id);

        self::assertSame($seat, $policy->contextFor($seat, 'graph_start'), 'not a built verb');
        $passkey = new ToolContext('passkey:QM1LEWEfsoWiMm', 'web', ['agent:read']);
        self::assertSame($passkey, $policy->contextFor($passkey, 'herramientas_prestar'), 'not a seat');
        self::assertSame($seat, (new PluginAuthoringPolicy($root))->contextFor($seat, 'herramientas_prestar'), 'a policy that judges no admission hands nothing');
    }

    /**
     * A leg's registry as the house builds it: the catalogue projected into a registry whose gate holds the house's
     * policy, then wrapped.
     *
     * @return array{0: AdmissionAwareRegistry, 1: string, 2: Kernel}
     */
    private function leg(): array
    {
        $root = $this->root();
        // An operation of the house that is nobody's built verb, asking for the very word the capability declares.
        $package = $this->verb('almacen.vaciar', ['herramientas:write']);
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())], [$package]);

        return [new AdmissionAwareRegistry($this->registry($root, $kernel)), $root, $kernel];
    }

    private function registry(string $root, Kernel $kernel): ToolRegistry
    {
        $container = new DIContainer();
        $container->registerService(Kernel::class, $kernel);
        PluginAuthoringPolicy::install($container, $root);
        $registry = new ToolRegistry(new NullLogger());
        /** @var list<Operation> $operations */
        $operations = $kernel->commands();
        (new McpProjector())->projectAll($operations, $registry, $container);

        return $registry;
    }

    private function definition(ToolRegistry $registry, string $tool): ToolDefinition
    {
        $definition = $registry->getDefinition($tool);
        self::assertNotNull($definition, "{$tool} is not a tool of this leg");

        return $definition;
    }

    private function seat(): ToolContext
    {
        return new ToolContext('key:' . self::SEAT, 'cli', self::SEAT_SCOPES);
    }

    private function admit(string $root, Kernel $kernel, string $scope): void
    {
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', $scope);
        self::assertNotNull($group);
        self::assertTrue($this->ledger($root)->admit(self::SEAT, 'Prestamos', $scope, $group['verbs'], 'key:' . self::HUMAN));
    }
}
