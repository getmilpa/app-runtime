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

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Auth\HostPermissionPolicy;
use Milpa\Auth\ArrayPermissionCatalog;
use Milpa\Auth\CatalogPermissionResolver;
use Milpa\Auth\Contracts\PermissionResolver;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationBoundary;
use Milpa\Console\OperationPermissionPolicy;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\McpServer\JsonRpcService;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * MCP and a finite CLI caller judge an operation's `permission` with the host's resolver (GHSA-xj7j-99jx-52hh).
 *
 * milpa/console 0.22.2 withholds a permission-typed operation from MCP and refuses it to a finite CLI caller until
 * the host registers an `OperationPermissionPolicy`. The house registers {@see HostPermissionPolicy} with its
 * boundary, and the boundary asks that same judge for a permission-typed mutation instead of refusing it as one
 * that declares no authority. The local terminal, which holds `*`, is not asked — as before.
 */
final class PermissionOnMcpAndCliTest extends TestCase
{
    private DIContainer $container;

    /** @var list<string> the operations whose handler actually ran */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->container = new DIContainer();
        $this->container->registerService(MilpaEventDispatcherInterface::class, new EventDispatcher(new NullLogger()));
        PluginAuthoringPolicy::install($this->container, sys_get_temp_dir());
        // The resolver an HTTP policy asks: a caller's scope that names a permission grants it (milpa/auth).
        $this->container->registerService(PermissionResolver::class, new CatalogPermissionResolver(ArrayPermissionCatalog::fromArray([])));
    }

    /** @return array{0: Operation, 1: Operation} the read and the mutation of one app, both typed by permission */
    private function grades(): array
    {
        $catalogue = PluginAuthoringPolicy::catalogue($this->container, [
            new Operation('grades:read', 'Read the grades.', function (): array {
                $this->ran[] = 'grades:read';

                return ['ok' => true, 'grades' => [10, 9]];
            }, permission: 'school.grades:read', effects: EffectProfile::readOnly()),
            new Operation('grades:write', 'Record a grade.', function (): array {
                $this->ran[] = 'grades:write';

                return ['ok' => true];
            }, mutating: true, permission: 'school.grades:write', effects: new EffectProfile(Mutation::Persistent, Externality::SamePrincipal, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data)),
        ]);
        self::assertCount(2, $catalogue);

        return [$catalogue[0], $catalogue[1]];
    }

    /**
     * One `tools/call` over MCP, as `coa mcp` answers it: the registry the projector filled, one caller per request.
     *
     * @param list<string> $scopes
     *
     * @return array<string, mixed>
     */
    private function mcp(string $tool, array $scopes): array
    {
        $registry = new ToolRegistry(new NullLogger());
        $projector = new McpProjector();
        $projector->projectAll($this->grades(), $registry, $this->container);
        self::assertSame([], $projector->withheld(), 'a house with a judge withholds nothing');
        $answer = (new JsonRpcService($registry))->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]],
            ToolContext::stdio('1', 'token:caller', $scopes),
        );
        self::assertIsArray($answer);
        $text = $answer['result']['content'][0]['text'] ?? null;
        self::assertIsString($text, json_encode($answer) ?: '');
        $result = json_decode($text, true);
        self::assertIsArray($result);

        return $result;
    }

    /**
     * One call from the terminal, by a caller whose authority is this context (null: the local shell).
     *
     * @return array{0: int, 1: string}
     */
    private function cli(Operation $operation, ?ToolContext $caller): array
    {
        $lines = [];
        $code = (new CliRunner(callerAuthority: $caller))->run($operation, [], $this->container, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return [$code, implode("\n", $lines)];
    }

    public function testTheHouseRegistersItsJudgeWithItsBoundary(): void
    {
        self::assertInstanceOf(HostPermissionPolicy::class, $this->container->get(OperationPermissionPolicy::class));
    }

    public function testAHostsOwnJudgeIsKept(): void
    {
        $container = new DIContainer();
        $own = new class () implements OperationPermissionPolicy {
            public function enforce(Operation $op, ToolContext $caller, array $arguments): AuthorizationResult
            {
                return AuthorizationResult::denied('the host judges');
            }
        };
        $container->registerService(OperationPermissionPolicy::class, $own);

        PluginAuthoringPolicy::install($container, sys_get_temp_dir());

        self::assertSame($own, $container->get(OperationPermissionPolicy::class));
    }

    public function testMcpServesAPermissionedReadToACallerThatHoldsThePermission(): void
    {
        $result = $this->mcp('grades_read', ['school.grades:read']);

        self::assertTrue($result['success'] ?? false, json_encode($result) ?: '');
        self::assertSame(['grades:read'], $this->ran);
    }

    public function testMcpRefusesAPermissionedReadToACallerWithoutThePermission(): void
    {
        $result = $this->mcp('grades_read', ['agent:run']);

        self::assertFalse($result['success'] ?? true);
        self::assertStringContainsString("'token:caller' does not hold it", json_encode($result) ?: '');
        self::assertSame([], $this->ran);
    }

    public function testMcpRunsAPermissionedMutationThePermissionAdmitsPastTheBoundary(): void
    {
        $result = $this->mcp('grades_write', ['school.grades:write']);

        self::assertTrue($result['success'] ?? false, json_encode($result) ?: '');
        self::assertSame(['grades:write'], $this->ran);
    }

    public function testMcpRefusesAPermissionedMutationToACallerWithoutThePermission(): void
    {
        $result = $this->mcp('grades_write', ['school.grades:read']);

        self::assertFalse($result['success'] ?? true);
        self::assertStringContainsString("requires the permission 'school.grades:write', and 'token:caller' does not hold it", json_encode($result) ?: '');
        self::assertSame([], $this->ran);
    }

    public function testAFiniteCliCallerWithThePermissionRunsTheReadAndTheMutation(): void
    {
        [$read, $write] = $this->grades();
        $seat = new ToolContext('key:SEAT', 'cli', ['agent:run', 'school.grades:read', 'school.grades:write']);

        self::assertSame(0, $this->cli($read, $seat)[0]);
        [$code, $said] = $this->cli($write, $seat);

        self::assertSame(0, $code, $said);
        self::assertSame(['grades:read', 'grades:write'], $this->ran);
    }

    public function testAFiniteCliCallerWithoutThePermissionIsRefusedAndNothingRuns(): void
    {
        [$read, $write] = $this->grades();
        $seat = new ToolContext('key:SEAT', 'cli', ['agent:run']);

        [$code, $said] = $this->cli($read, $seat);
        self::assertSame(1, $code);
        self::assertStringContainsString("Operation 'grades:read' requires the permission 'school.grades:read', and 'key:SEAT' does not hold it.", $said);

        [$code, $said] = $this->cli($write, $seat);
        self::assertSame(1, $code);
        self::assertStringContainsString("Operation 'grades:write' requires the permission 'school.grades:write', and 'key:SEAT' does not hold it.", $said);
        self::assertSame([], $this->ran);
    }

    public function testTheLocalTerminalIsNotAskedEvenWithoutAResolver(): void
    {
        $this->container = new DIContainer();
        PluginAuthoringPolicy::install($this->container, sys_get_temp_dir());
        [$read, $write] = $this->grades();

        self::assertSame(0, $this->cli($read, null)[0]);
        self::assertSame(0, $this->cli($write, null)[0]);
        self::assertSame(['grades:read', 'grades:write'], $this->ran);
    }

    public function testAMutationThatDeclaresNoAuthorityIsStillRefusedToAFiniteCaller(): void
    {
        $bare = PluginAuthoringPolicy::catalogue($this->container, [new Operation('sync:push', '', function (): array {
            $this->ran[] = 'sync:push';

            return ['ok' => true];
        }, mutating: true)])[0];

        [$code, $said] = $this->cli($bare, new ToolContext('key:SEAT', 'cli', ['*:write']));

        self::assertSame(1, $code);
        self::assertStringContainsString("Mutation 'sync_push' declares no authority for a finite principal.", $said);
        self::assertSame([], $this->ran);
    }

    public function testTheBoundaryAsksTheJudgeForTheOperationThatRuns(): void
    {
        [, $write] = $this->grades();
        $boundary = $this->container->get(OperationBoundary::class);
        self::assertInstanceOf(PluginAuthoringPolicy::class, $boundary);

        self::assertSame('ran', $boundary->execute($write, [], new ToolContext('key:SEAT', 'cli', ['school.grades:write']), static fn (): string => 'ran'));

        $this->expectExceptionMessage("Operation 'grades:write' requires the permission 'school.grades:write', and 'key:SEAT' does not hold it.");
        $boundary->execute($write, [], new ToolContext('key:SEAT', 'cli', ['school.grades:read']), static fn (): string => 'ran');
    }

    public function testTheBoundaryJudgesAnOperationTheCatalogueNeverNamed(): void
    {
        $boundary = $this->container->get(OperationBoundary::class);
        self::assertInstanceOf(PluginAuthoringPolicy::class, $boundary);
        $stray = new Operation('grades:erase', '', static fn (): null => null, mutating: true, permission: 'school.grades:erase');

        $this->expectExceptionMessage("Operation 'grades:erase' requires the permission 'school.grades:erase', and 'key:SEAT' does not hold it.");
        $boundary->execute($stray, [], new ToolContext('key:SEAT', 'cli', ['agent:run']), static fn (): string => 'ran');
    }

    public function testWithoutAJudgeThePermissionedMutationIsRefusedAndTheRefusalSaysWhy(): void
    {
        $policy = new PluginAuthoringPolicy(sys_get_temp_dir());
        $write = new Operation('grades:write', '', static fn (): null => null, mutating: true, permission: 'school.grades:write');

        $this->expectExceptionMessage("Operation 'grades:write' requires the permission 'school.grades:write' and this host wired no " . OperationPermissionPolicy::class . ' to judge it. Nothing ran.');
        $policy->execute($write, [], new ToolContext('key:SEAT', 'cli', ['school.grades:write']), static fn (): string => 'ran');
    }

    public function testAnAuthoringOperationKeepsItsOwnChecksWhateverItsPermission(): void
    {
        $boundary = $this->container->get(OperationBoundary::class);
        self::assertInstanceOf(PluginAuthoringPolicy::class, $boundary);
        $make = new Operation('make', '', static fn (): null => null, mutating: true, permission: 'plugins:write');

        $this->expectExceptionMessage('Scoped authoring requires one canonical plugin name.');
        $boundary->execute($make, [], new ToolContext('key:SEAT', 'cli', ['plugins:write']), static fn (): string => 'ran');
    }
}
