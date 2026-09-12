<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** A session's consent and its executor's scopes are independent checks (greenhouse 0314). */
final class ScopeAndConsentTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, ?string}> */
    public static function cases(): iterable
    {
        yield 'scope and exact consent execute' => [true, true, null];
        yield 'scope alone cannot execute' => [true, false, 'needs explicit consent'];
        yield 'consent cannot buy scope' => [false, true, 'Missing required scope'];
        yield 'neither reports scope first' => [false, false, 'Missing required scope'];
    }

    #[DataProvider('cases')]
    public function testTheSameMutationNeedsScopeAndConsent(bool $hasScope, bool $hasConsent, ?string $refusal): void
    {
        $executed = 0;
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('settings_write', 'Write settings', ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]], function (array $arguments) use (&$executed): array {
            ++$executed;

            return ['written' => $arguments['value']];
        }, new ToolOptions(scopes: ['settings:write'], mutating: true, requiresConfirmation: true));
        $grant = new ConsentGrant(new OperationId('settings_write'), 'human:approver', 'scope-parity', new \DateTimeImmutable(), 'session.question_answered', arguments: ['value' => 'approved']);
        $door = new ConsentBridge(
            $registry,
            grants: $hasConsent ? [$grant] : [],
            identity: AuthContext::authenticated(new Actor('developer', ActorType::Agent, $hasScope ? ['settings:write'] : ['settings:read'])),
        );

        try {
            $result = $door->callTool('settings_write', ['value' => 'approved']);
            self::assertNull($refusal, 'a refused case must not return data');
            self::assertSame(['written' => 'approved'], $result);
            self::assertSame(1, $executed);
            self::assertSame('human:approver', $door->consentChain()[0]['principal']);
        } catch (\Exception $error) {
            self::assertNotNull($refusal, $error->getMessage());
            self::assertStringContainsString($refusal, $error->getMessage());
            self::assertSame(0, $executed);
            self::assertSame([], $door->consentChain());
        }
    }
}
