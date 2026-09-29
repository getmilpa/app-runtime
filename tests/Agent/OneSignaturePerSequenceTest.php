<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionSequenceReceipts;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Operations\RecipeOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\OperationSigner;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\NonceLedger;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorizer;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * One signature per sequence, kept in the session the sequence runs in (greenhouse decisions/0458,
 * 0500): the first signed leg leaves its receipt, the legs after it cite it and run as the seat,
 * and the receipt dies with the sequence.
 */
final class OneSignaturePerSequenceTest extends TestCase
{
    private const SEAT = 'AAAA1111AAAA1111AAAA1111AAAA1111AAAA1111';

    /** @var list<array{?InvocationContext, ?ToolContext}> */
    private array $legs = [];

    private function verifier(): SignatureVerifier
    {
        return new class () implements SignatureVerifier {
            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return $signature === 'sig:' . hash('sha256', $payload) ? new VerifiedSigner('AAAA1111AAAA1111AAAA1111AAAA1111AAAA1111', 'Resident <r@lab>') : null;
            }
        };
    }

    /** @param list<string> $scopes */
    private function runner(SessionStore $sessions, array $scopes = ['agent:run']): CliRunner
    {
        return new CliRunner(
            signer: new class () implements OperationSigner {
                public function sign(string $operation, array $arguments, string $host, int $now): ?array
                {
                    $payload = (new OperationAuthorization($operation, $arguments, $host, gmdate('c', $now), bin2hex(random_bytes(8))))->canonical();

                    return [$payload, 'sig:' . hash('sha256', $payload)];
                }
            },
            authorizer: new OperationAuthorizer($this->verifier(), new class () implements NonceLedger {
                public function spend(string $nonce, int $ttlSeconds, int $now): bool
                {
                    return true;
                }
            }),
            signerAuthority: static fn (VerifiedSigner $s): ToolContext => new ToolContext(principal: 'key:' . $s->fingerprint, channel: 'cli', scopes: $scopes),
            callerAuthority: ToolContext::cli(),
            verifier: $this->verifier(),
            receipts: new SessionSequenceReceipts(static fn (): SessionStore => $sessions),
        );
    }

    /** A leg of `agent`: opens the session on first use, and says `closure.verified` when told to finish. */
    private function leg(SessionStore $sessions): Operation
    {
        $agent = $this->declared((new AgentOperations(new DIContainer()))->operations(), 'agent');

        return new Operation(
            name: 'agent',
            description: 'a leg',
            handler: function (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null) use ($sessions): array {
                $this->legs[] = [$context, $authority];
                $id = (string) $input['session'];
                if ($sessions->load($id) === null) {
                    $sessions->start($id, (string) $input['prompt']);
                }

                return ['ok' => true, 'closure' => ['verified' => $input['prompt'] === 'finish', 'reasons' => []]];
            },
            inputSchema: ['type' => 'object', 'properties' => ['session' => ['type' => 'string'], 'prompt' => ['type' => 'string']]],
            mutating: true,
            scopes: ['agent:run'],
            effects: new EffectProfile(Mutation::Persistent, Externality::ThirdParty, Reversibility::Irreversible, Authority::WriteAsUser, subject: Subject::Data),
            // The REAL declaration of `agent`, so this measures what ships.
            continues: $agent->continues,
        );
    }

    /**
     * @param list<Operation> $operations
     */
    private function declared(array $operations, string $name): Operation
    {
        foreach ($operations as $op) {
            if ($op->name === $name) {
                return $op;
            }
        }
        self::fail("«{$name}» is not offered here");
    }

    /**
     * @param list<string> $argv
     */
    private function call(CliRunner $runner, Operation $op, array $argv): int
    {
        return $runner->run($op, $argv, new DIContainer(), static fn (string $line): null => null);
    }

    public function testTheSequenceIsTheSessionForBothDeclarations(): void
    {
        $agent = $this->declared((new AgentOperations(new DIContainer()))->operations(), 'agent');
        $recipe = $this->declared((new RecipeOperations(new DIContainer()))->operations(), 'recipe:apply');

        self::assertSame('s1', $agent->sequenceFor(['session' => 's1', 'prompt' => 'x']));
        self::assertNull($agent->sequenceFor(['prompt' => 'a one-off question continues nothing']));
        self::assertSame('recipe:blog', $recipe->sequenceFor(['recipe' => 'blog']));
        self::assertSame('mine', $recipe->sequenceFor(['recipe' => 'blog', 'session' => 'mine']));
        self::assertSame(RecipeOperations::sessionIdFor('blog'), $recipe->sequenceFor(['recipe' => 'blog', 'session' => ' ']));
    }

    public function testElevenLegsOneSignatureAndEveryLegRunsAsTheSeat(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $op = $this->leg($sessions);

        self::assertSame(0, $this->call($this->runner($sessions), $op, ['--session=s1', '--prompt=build the blog', '--sign']));
        for ($i = 2; $i <= 11; ++$i) {
            self::assertSame(0, $this->call($this->runner($sessions), $op, ['--session=s1', '--prompt=continue']), "leg {$i}");
        }

        self::assertCount(11, $this->legs);
        foreach ($this->legs as [$context, $authority]) {
            self::assertSame('key:' . self::SEAT, $context?->actor);
            self::assertSame(['agent:run'], $authority?->scopes);
        }
        $types = array_map(static fn ($e): string => $e->type, $sessions->stream('s1'));
        self::assertSame(1, \count(array_keys($types, 'session.sequence_authorized', true)));
        self::assertSame(10, \count(array_keys($types, 'session.authorization_cited', true)));
    }

    /**
     * A verified closure is the house's judgment of the work, not the end of whose work it is (greenhouse
     * decisions/0522). Measured (evidence/1050): a false closure at leg 2 released the seat's receipt, and legs 3–13
     * ran as the terminal. Now the leg after a verified closure still runs as the seat.
     */
    public function testAVerifiedClosureKeepsTheSeatsReceiptAndTheNextLegRunsAsTheSeat(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $op = $this->leg($sessions);
        $this->call($this->runner($sessions), $op, ['--session=s1', '--prompt=build', '--sign']);

        self::assertSame(0, $this->call($this->runner($sessions), $op, ['--session=s1', '--prompt=finish']));
        self::assertNotNull($sessions->load('s1')?->sequenceAuthorization());

        self::assertSame(0, $this->call($this->runner($sessions), $op, ['--session=s1', '--prompt=next task']));
        self::assertSame('key:' . self::SEAT, $this->legs[2][0]?->actor);
        self::assertSame(['agent:run'], $this->legs[2][1]?->scopes);
        $types = array_map(static fn ($e): string => $e->type, $sessions->stream('s1'));
        self::assertNotContains('session.authorization_released', $types);
    }

    /** What does end it: the session ending (`agent:discard`, a closed answer window), or a new signed leg. */
    public function testOnlyTheSessionEndingOrANewSignatureEndsTheReceipt(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $op = $this->leg($sessions);
        $this->call($this->runner($sessions), $op, ['--session=s1', '--prompt=build', '--sign']);
        $first = $sessions->load('s1')?->sequenceAuthorization();

        $this->call($this->runner($sessions), $op, ['--session=s1', '--prompt=again', '--sign']);
        $second = $sessions->load('s1')?->sequenceAuthorization();
        self::assertNotNull($second);
        self::assertNotSame($first['seq'] ?? null, $second['seq']);

        $sessions->end('s1', 'discarded by the human');
        self::assertNull($sessions->load('s1')?->sequenceAuthorization());
    }

    public function testNoVerdictOfAnAgentLegEndsItsSequence(): void
    {
        self::assertNull(SessionSequenceReceipts::ended('agent', ['ok' => true, 'closure' => ['verified' => true, 'reasons' => [], 'scope' => 'house_observation']]));
        self::assertNull(SessionSequenceReceipts::ended('agent', ['ok' => true, 'closure' => ['verified' => true, 'reasons' => [], 'scope' => 'recorded_work']]));
        self::assertNull(SessionSequenceReceipts::ended('agent', ['closure' => ['verified' => false, 'reasons' => ['pending']]]));
        self::assertNull(SessionSequenceReceipts::ended('agent', ['ok' => true, 'contextExhausted' => true]));
        self::assertNull(SessionSequenceReceipts::ended('recipe:apply', ['ok' => true, 'applied' => false, 'paused' => true]));
        self::assertNotNull(SessionSequenceReceipts::ended('recipe:apply', ['ok' => true, 'applied' => true]));
        self::assertNull(SessionSequenceReceipts::ended('agent', null));
    }

    public function testASignedCallToASessionThatDoesNotExistKeepsNothing(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $book = new SessionSequenceReceipts(static fn (): SessionStore => $sessions);
        $granted = new GrantedAuthorization(
            new OperationAuthorization('agent', ['session' => 'ghost'], 'h', gmdate('c'), 'n'),
            new VerifiedSigner(self::SEAT, null),
            'payload',
            'sig',
        );

        $book->record('ghost', 'agent', $granted, ['ok' => false]);

        self::assertNull($sessions->load('ghost'));
        self::assertSame([], $sessions->stream('ghost'));
    }

    public function testARevokedSeatIsRefusedInsteadOfRunningAsTheTerminal(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $op = $this->leg($sessions);
        $this->call($this->runner($sessions), $op, ['--session=s1', '--prompt=build', '--sign']);

        self::assertSame(1, $this->call($this->runner($sessions, scopes: []), $op, ['--session=s1', '--prompt=continue']));
        self::assertCount(1, $this->legs);
    }

    public function testAReceiptTransplantedFromAnotherSessionIsRefused(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $op = $this->leg($sessions);
        $this->call($this->runner($sessions), $op, ['--session=other', '--prompt=build', '--sign']);
        $sessions->start('s1', 'victim');
        $lifted = $sessions->load('other')?->sequenceAuthorization();
        self::assertNotNull($lifted);
        $sessions->authorizeSequence('s1', 'agent', $lifted['receipt']);

        self::assertSame(1, $this->call($this->runner($sessions), $op, ['--session=s1', '--prompt=continue']));
        self::assertCount(1, $this->legs, 'only the opening leg of «other» ran');
    }

    public function testTheBookResolvesTheStoreOnlyWhenAsked(): void
    {
        $asked = 0;
        new SessionSequenceReceipts(static function () use (&$asked): ?SessionStore {
            ++$asked;

            return null;
        });

        self::assertSame(0, $asked);
    }
}
