<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\Data\InMemoryRepository;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** A verified request's tool authority must not fall back to the server's environment. */
final class RequestAuthorityTest extends TestCase
{
    public function testTheRealAgentDoorUsesThisTurnsAuthorityBeforeAnAmbientWildcard(): void
    {
        $previous = getenv('MILPA_TOKEN');
        $token = 'request-authority-control';
        putenv('MILPA_TOKEN=' . $token);
        try {
            $container = new DIContainer();
            $repository = new InMemoryRepository(ApiToken::class);
            $repository->save(new ApiToken(hash: TokenVerifier::hash($token), actor: 'server', scopes: ['*'], createdAt: '2026-09-11T00:00:00+00:00'));
            $container->registerService(TokenVerifier::class . '.repository', $repository);
            $agent = new AgentOperations($container);
            $executed = 0;
            $registry = new ToolRegistry(new NullLogger());
            $registry->register('probe_read', 'Read the probe', ['type' => 'object', 'properties' => []], static function () use (&$executed): string {
                ++$executed;
                return 'read';
            }, new ToolOptions(scopes: ['probe:read'], mutating: false));
            $context = InvocationContext::web('actor:passkey:caller', 'agent');
            foreach ([[['probe:read'], true], [['other:read'], false], [[], false]] as [$scopes, $allowed]) {
                // Empty input stops before contacting a model, after the shipped turn captures its contexts.
                $result = (new \ReflectionMethod($agent, 'run'))->invoke($agent, [], $context, new ToolContext(principal: 'passkey:caller', channel: 'web', scopes: $scopes));
                self::assertFalse($result['ok']);
                $door = (new \ReflectionMethod($agent, 'governedExecutor'))->invoke($agent, $registry, null, null, null);
                try {
                    self::assertSame('read', $door->callTool('probe_read', []));
                    self::assertTrue($allowed);
                } catch (\Exception $error) {
                    self::assertFalse($allowed);
                    self::assertStringContainsString('Missing required scope', $error->getMessage());
                }
            }
            self::assertSame(1, $executed);

            // An ordinary terminal still reads its presented token after the web turn is over.
            (new \ReflectionMethod($agent, 'run'))->invoke($agent, [], InvocationContext::cli());
            $door = (new \ReflectionMethod($agent, 'governedExecutor'))->invoke($agent, $registry, null, null, null);
            self::assertSame('read', $door->callTool('probe_read', []));
            self::assertSame(2, $executed);
        } finally {
            $previous === false ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $previous);
        }
    }

    public function testAWebTurnWithoutItsAuthorityRefusesBeforeTheModel(): void
    {
        $agent = new AgentOperations(new DIContainer());
        $result = (new \ReflectionMethod($agent, 'run'))->invoke($agent, ['prompt' => 'Read the probe'], InvocationContext::web('actor:caller', 'agent'));
        self::assertFalse($result['ok']);
        self::assertStringContainsString('did not carry tool authority', $result['error']);
    }
}
