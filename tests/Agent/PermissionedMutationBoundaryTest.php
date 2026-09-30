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

use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\AppRuntime\Auth\CallerActors;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\ArrayPermissionCatalog;
use Milpa\Auth\CatalogPermissionResolver;
use Milpa\Auth\Contracts\PermissionResolver;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Console\Events\ConsoleEvents;
use Milpa\Console\Http\HttpProjector;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationRunner;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * The boundary judges the permission of the operation that runs, never the channel its authority came from.
 *
 * `Operation` holds `scopes` XOR `permission`. The boundary refused every mutation with empty scopes for a finite
 * principal, so a `permission` mutation served over HTTP — admitted by the host's `OperationHttpPolicy` before it
 * ran — answered 500 on every call (app-docentes S-0036). The HTTP run of that one operation now carries the
 * policy's verdict to the boundary; the authority it hands its handler carries none. An agent turn started over
 * HTTP holds a web authority, and a permissioned mutation its model calls is judged as its own call.
 */
final class PermissionedMutationBoundaryTest extends TestCase
{
    private DIContainer $container;

    private EventDispatcher $events;

    /** @var list<string> the operations whose handler actually ran */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->container = new DIContainer();
        $this->events = new EventDispatcher(new NullLogger());
        $this->container->registerService(MilpaEventDispatcherInterface::class, $this->events);
        PluginAuthoringPolicy::install($this->container, sys_get_temp_dir());
        // The house's resolver grants `attendance:write` to the teacher ROLE. The teacher's web authority carries
        // `agent:run` and no role, so it holds no permission of its own until the host names its actor.
        $this->container->registerService(PermissionResolver::class, new CatalogPermissionResolver(ArrayPermissionCatalog::fromArray([
            'roles' => ['teacher' => ['label' => 'Teacher', 'permissions' => ['attendance:write']]],
        ])));
    }

    private function mutation(string $name, ?string $permission): Operation
    {
        return new Operation($name, '', function () use ($name): array {
            $this->ran[] = $name;

            return ['ok' => true];
        }, mutating: true, permission: $permission, effects: self::rows());
    }

    /** A row the caller writes as themself: what the HTTP surface serves without a consent token. */
    private static function rows(): EffectProfile
    {
        return new EffectProfile(Mutation::Persistent, Externality::SamePrincipal, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data);
    }

    /** The host's HTTP policy: admits the request whose actor holds the operation's permission. */
    private static function policy(): OperationHttpPolicy
    {
        return new class () implements OperationHttpPolicy {
            public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
            {
                $held = $request->getAttribute('test.permissions', []);
                if ($op->permission === null || \in_array($op->permission, $held, true)) {
                    return null;
                }

                return (new Psr17Factory())->createResponse(403);
            }
        };
    }

    /**
     * Serve one operation over HTTP to an authenticated actor holding these permissions.
     *
     * @param list<string> $permissions
     */
    private function serve(Operation $operation, array $permissions): ResponseInterface
    {
        $projector = new HttpProjector([$operation], $this->container, new Psr17Factory(), new Psr17Factory(), policy: self::policy(), dispatcher: $this->events);
        $actor = new class () {
            public string $id = 'teacher-1';

            /** @var list<string> */
            public array $scopes = ['agent:run'];
        };
        $auth = new class ($actor) {
            public function __construct(public object $actor)
            {
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        $request = (new Psr17Factory())->createServerRequest('POST', '/' . $operation->name)
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($projector->routes()[0]))
            ->withAttribute('milpa.auth', $auth)
            ->withAttribute('test.permissions', $permissions);

        return $projector->handle($request);
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return \is_array($body) ? $body : [];
    }

    public function testAPermissionedMutationTheHttpPolicyAdmittedRuns(): void
    {
        $response = $this->serve($this->mutation('sync_push', 'attendance:write'), ['attendance:write']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['sync_push'], $this->ran);
    }

    public function testAPermissionedMutationTheHttpPolicyRefusedIsA403AndNeverRuns(): void
    {
        $response = $this->serve($this->mutation('sync_push', 'attendance:write'), []);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->ran);
    }

    public function testAMutationThatDeclaresNoAuthorityIsStillRefusedOverHttp(): void
    {
        $response = $this->serve($this->mutation('sync_push', null), []);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([], $this->ran);
    }

    /**
     * An agent turn over HTTP: its web authority reaches the model's tools through the governed door.
     *
     * The door is built as the agent's leg builds it — the turn's authority, the session's consent for the
     * call — and, a second time, stamped with the web channel, so no verdict can come from the channel.
     */
    #[DataProvider('doorChannels')]
    public function testAnAgentTurnOverHttpDoesNotRunAPermissionedMutationThePrincipalLacks(string $channel): void
    {
        $push = $this->mutation('sync_push', 'attendance:write');
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll([$push], $registry, $this->container);
        $grant = new ConsentGrant(
            operation: new OperationId('sync_push'),
            principal: 'teacher-1',
            session: 's1',
            grantedAt: new \DateTimeImmutable(),
            provenance: 'session.question_answered',
            arguments: [],
        );
        $turn = new Operation('agent_turn', '', static function (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null) use ($registry, $grant, $channel): array {
            $door = new ConsentBridge($registry, [$grant], channel: $channel, authority: $authority);
            try {
                $door->callTool('sync_push', []);
            } catch (\Throwable $refused) {
                return ['ok' => true, 'refused' => $refused->getMessage()];
            }

            return ['ok' => true, 'refused' => null];
        }, mutating: true, scopes: ['agent:run'], effects: self::rows());

        $response = $this->serve($turn, ['attendance:write']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->ran, 'the model\'s call to a permissioned mutation must not run on the turn\'s admission');
        self::assertSame("Operation 'sync_push' requires the permission 'attendance:write', and 'teacher-1' does not hold it.", self::body($response)['refused'] ?? null);
    }

    /**
     * The same call, by a teacher the house's resolver DOES grant the permission, through the agent's door: refused.
     *
     * MCP and the terminal now admit what the resolver grants (decisions/0545), and the agent's tools are projected
     * the same way. The model's call stays closed at the agent's door until its own slice (decisions/0544 §2).
     */
    public function testTheAgentsDoorKeepsAPermissionedMutationClosedEvenWhenTheResolverGrantsIt(): void
    {
        $this->container->registerService(CallerActors::class, new class () implements CallerActors {
            public function actorOf(ToolContext $caller): ?Actor
            {
                return $caller->principal === 'teacher-1' ? new Actor('teacher-1', ActorType::User, $caller->scopes, roles: ['teacher']) : null;
            }
        });
        $push = $this->mutation('sync_push', 'attendance:write');
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll(PluginAuthoringPolicy::catalogue($this->container, [$push]), $registry, $this->container);
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Auto);
        $store->grant('s1', 'sync_push');
        $session = $store->load('s1');
        self::assertNotNull($session);
        $door = new SessionToolGate($store, $session, [$push]);
        $turn = new Operation('agent_turn', '', static function (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null) use ($registry, $door): array {
            $grant = new ConsentGrant(new OperationId('sync_push'), 'teacher-1', 's1', new \DateTimeImmutable(), 'session.question_answered', []);
            try {
                (new ConsentBridge($registry, [$grant], $door, channel: 'web', authority: $authority))->callTool('sync_push', []);
            } catch (\Throwable $refused) {
                return ['ok' => true, 'refused' => $refused->getMessage()];
            }

            return ['ok' => true, 'refused' => null];
        }, mutating: true, scopes: ['agent:run'], effects: self::rows());

        $response = $this->serve($turn, []);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->ran);
        self::assertStringContainsString("A model's call to it stays refused at the agent's door", (string) (self::body($response)['refused'] ?? ''));
    }

    /** @return iterable<string, array{string}> */
    public static function doorChannels(): iterable
    {
        yield 'as the agent leg builds it' => ['cli'];
        yield 'stamped with the web channel' => ['web'];
    }

    public function testAWebAuthorityOutsideTheHttpRunOfThatOperationIsNoVerdict(): void
    {
        $runner = new OperationRunner($this->container, $this->events);

        try {
            $runner->run($this->mutation('sync_push', 'attendance:write'), [], 'cli', null, ToolContext::web('teacher-1', []));
            self::fail('a web authority is not a judged permission');
        } catch (\RuntimeException $refused) {
            self::assertSame("Operation 'sync_push' requires the permission 'attendance:write', and 'teacher-1' does not hold it.", $refused->getMessage());
        }
        self::assertSame([], $this->ran);
    }

    public function testAnHttpRunThatNeverReachedTheBoundaryLeavesNoVerdictBehind(): void
    {
        $push = $this->mutation('sync_push', 'attendance:write');
        $stop = static function (string $event, array $payload): void {
            if ($payload['event']->surface === 'http') {
                $payload['slot']->stop();
            }
        };
        $this->events->subscribe(ConsoleEvents::EXECUTING, $stop, 100);

        self::assertSame(409, $this->serve($push, ['attendance:write'])->getStatusCode());

        $boundary = $this->container->get(\Milpa\Console\OperationBoundary::class);
        self::assertInstanceOf(PluginAuthoringPolicy::class, $boundary);
        $this->expectExceptionMessage("Operation 'sync_push' requires the permission 'attendance:write', and 'teacher-1' does not hold it.");
        try {
            $boundary->execute($push, [], ToolContext::web('teacher-1', []), static fn (): string => 'ran');
        } finally {
            self::assertSame([], $this->ran);
        }
    }

    public function testAnAuthoringOperationKeepsItsOwnChecksOverHttp(): void
    {
        $response = $this->serve($this->mutation('make', 'plugins:write'), ['plugins:write']);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([], $this->ran);
    }
}
