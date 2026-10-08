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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\GrantedCall;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\SeatFrontier;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Tests\Agent\BuiltHouse;
use Milpa\Console\McpProjector;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Admitting a capability IS granting one of its scopes to a seat, with its contract in view (greenhouse
 * decisions/0590, rules 5 to 8 and 13 to 15).
 *
 * The seat's frontier learns a built verb's refusal from the same judge the gate asks; the card it offers carries
 * the verbs that scope opens and the digest of what it shows; and `identity:grant` admits only when the proof
 * covers THAT digest — recomputed when it is asked, so a contract that moved between seeing and signing admits
 * nothing.
 */
final class AdmittingAScopeOfABuiltCapabilityTest extends TestCase
{
    use BuiltHouse;

    private const STRANGER = 'D00D0000111122223333444455556666777788889';
    private const SESSION = 'taller';

    public function testTheFrontierOffersARefusedBuiltVerbWithTheContractItOpens(): void
    {
        [$c, $root, , $write] = $this->house();

        $rows = $this->frontier($c)->openRefusals(self::SESSION);
        $row = array_column($rows, null, 'seq')[$write];

        self::assertSame('capability', $row['kind']);
        self::assertSame('Prestamos', $row['capability']);
        self::assertSame('Prestamos', $row['plugin']);
        self::assertSame('herramientas:write', $row['permission']);
        self::assertSame('herramientas_agregar', $row['tool']);
        self::assertSame(['nombre' => 'Taladro'], $row['call'], 'the call is shown as recorded');
        self::assertSame('informed', $row['consent'], 'a built capability is work that exists: never one touch');
        self::assertSame('never', $row['why']);
        self::assertSame(['herramientas.agregar', 'herramientas.devolver', 'herramientas.prestar'], array_column($row['opens'], 'verb'));
        self::assertStringStartsWith('sha256:', $row['contract']);

        $prestar = array_column($row['opens'], null, 'verb')['herramientas.prestar'];
        self::assertTrue($prestar['mutating']);
        self::assertSame('persistent', $prestar['effects']['mutation']);
        self::assertSame('manual_recovery', $prestar['effects']['reversibility']);
        self::assertSame(['herramientas:write'], $prestar['scopes']);
        // Where its work keeps its state, and how a call of it would run in this house.
        self::assertSame(['paths' => ['var/herramientas.json'], 'source' => 'entities'], $prestar['state']);
        self::assertContains($prestar['runs']['how'], ['house', 'asks']);
        self::assertStringStartsWith('sha256:', $prestar['digest']);
        self::assertSame('never', $prestar['standing']);

        self::assertCount(2, $rows, 'reading and writing are two acts');
        self::assertNotSame($row['contract'], array_column($rows, null, 'permission')['herramientas:read']['contract']);
        self::assertSame($root, $c->get(Kernel::class)->root());
    }

    /** The published panel asks the frontier without saying what the house built, and reads `refusals` as authoring. */
    public function testWhoDoesNotKnowWhatWasBuiltIsHandedNoCardItWouldMisread(): void
    {
        [$c, $root] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);

        self::assertSame([], SeatFrontier::forRoot($root, $sessions)->openRefusals(self::SESSION));

        $mine = $this->frontier($c)->sessionsFor('key:' . self::HUMAN);
        self::assertCount(1, $mine);
        self::assertSame([], $mine[0]['refusals'], 'a card for write over a plugin is not what this is');
        self::assertSame(['herramientas:read', 'herramientas:write'], array_column($mine[0]['admissions'], 'permission'));
    }

    public function testAnOperationNoBuiltCapabilityDeclaresIsNotOffered(): void
    {
        [$c] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $seq = $sessions->recordToolCall(self::SESSION, 'graph_start', ['graph' => 'x'], "Missing required scope for tool 'graph_start'. Need one of: graph:run", false, true);

        self::assertNotContains($seq, array_column($this->frontier($c)->openRefusals(self::SESSION), 'seq'));
        self::assertNull($this->frontier($c)->wouldOffer(self::SESSION, 'graph_start', ['graph' => 'x']));
        self::assertNull($this->frontier($c)->wouldOffer(self::SESSION, 'herramientas_inventada', []));
    }

    /** What ends the leg and makes the refusal say who decides (decisions/0543, evidence/1113) is this answer. */
    public function testTheRefusalSaysWhoAdmitsItAndTheFrontierWouldOfferIt(): void
    {
        [$c, $root] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);

        $offered = $this->frontier($c)->wouldOffer(self::SESSION, 'herramientas_prestar', ['id' => 1]);
        self::assertSame('herramientas:write', $offered['permission'] ?? null);

        $policy = (new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel)))->withSeatSession($sessions, self::SESSION);
        $verdict = $policy->authorize(
            new ToolContext('key:' . self::SEAT, 'cli', self::SEAT_SCOPES),
            new ToolDefinition('herramientas_prestar', 'x', [], static fn (): array => [], scopes: ['herramientas:write'], mutating: true),
            ['id' => 1],
        );

        self::assertFalse($verdict->allowed);
        self::assertStringContainsString('no person has admitted it for this seat', (string) $verdict->reason);
        self::assertStringContainsString('Whoever enrolled this seat admits it', (string) $verdict->reason);
        self::assertStringContainsString('do not declare HOUSE_DEBT', (string) $verdict->reason);
        self::assertStringContainsString('The leg ends here and waits', (string) $verdict->reason);
    }

    public function testThePlainGrantAdmitsNothingAndSaysWhatItWouldOpen(): void
    {
        [$c, $root, , $write] = $this->house();
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $write]);

        $plain = $this->call($c, ['session' => self::SESSION, 'seq' => $write]);

        self::assertFalse($plain['ok']);
        self::assertStringContainsString('admits «herramientas:write» of the capability «Prestamos»', (string) $plain['error']);
        self::assertStringContainsString('herramientas.agregar, herramientas.devolver, herramientas.prestar', (string) $plain['error']);
        self::assertStringContainsString('admits=' . $this->card($c, $write)['contract'], (string) $plain['error']);
        self::assertStringContainsString('nothing was admitted', (string) $plain['error']);
        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'));
    }

    public function testTheProofHasToCoverTheDigestOfWhatTheCardShowed(): void
    {
        [$c, $root, , $write] = $this->house();
        $before = (string) file_get_contents($root . '/storage/identity/enrollments.json');
        $admits = $this->card($c, $write)['contract'];

        // Another digest, signed: it is not this contract.
        $wrong = ['session' => self::SESSION, 'seq' => $write, 'admits' => 'sha256:' . str_repeat('0', 64)];
        $this->signed($c, self::HUMAN, $wrong);
        $r = $this->call($c, $wrong);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('is not the contract', (string) $r['error']);

        // The right digest, with a signature over the plain call: the person did not approve it.
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $write]);
        $unsigned = $this->call($c, ['session' => self::SESSION, 'seq' => $write, 'admits' => $admits]);
        self::assertFalse($unsigned['ok']);
        self::assertStringContainsString('does not cover', (string) $unsigned['error']);

        self::assertSame($before, (string) file_get_contents($root . '/storage/identity/enrollments.json'));
    }

    public function testAdmittedKnowinglyTheSeatRunsThoseVerbsAndHoldsNoNewWord(): void
    {
        [$c, $root, $read, $write] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $card = $this->card($c, $write);
        $call = ['session' => self::SESSION, 'seq' => $write, 'admits' => $card['contract']];
        $this->signed($c, self::HUMAN, $call);

        $r = $this->call($c, $call);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame('herramientas:write', $r['granted']);
        self::assertSame('Prestamos', $r['capability']);
        self::assertSame(['herramientas.agregar', 'herramientas.devolver', 'herramientas.prestar'], $r['admitted']);
        self::assertSame($card['contract'], $r['contract']);
        self::assertSame('key:' . self::HUMAN, $r['authorized_by']);
        self::assertTrue($r['session_told']);

        // The seat's scopes did not move: an admission never writes a word.
        $ledger = $this->ledger($root);
        self::assertSame(self::SEAT_SCOPES, $ledger->scopesFor(self::SEAT));
        self::assertSame(self::SEAT_SCOPES, $r['scopes']);
        $admitted = $ledger->admissionsFor(self::SEAT)['Prestamos']['herramientas:write'];
        self::assertSame('key:' . self::HUMAN, $admitted['admitted_by']);
        self::assertSame(array_column($card['opens'], 'digest', 'verb'), $admitted['verbs']);
        $raw = json_decode((string) file_get_contents($root . '/storage/identity/enrollments.json'), true);
        self::assertArrayNotHasKey('admissions', $raw[self::SEAT]['history'][0], 'the state it replaced is kept');

        // The gate lets those verbs through, and only those.
        $policy = new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel));
        $seat = new ToolContext('key:' . self::SEAT, 'cli', self::SEAT_SCOPES);
        self::assertTrue($policy->authorize($seat, $this->tool('herramientas.prestar', ['herramientas:write'], true), [])->allowed);
        self::assertFalse($policy->authorize($seat, $this->tool('herramientas.listar', ['herramientas:read'], false), [])->allowed);

        // Judged again, not remembered: the write card is gone, the read card stands, a second grant has nothing to add.
        self::assertSame([$read], array_column($this->frontier($c)->openRefusals(self::SESSION), 'seq'));
        $this->signed($c, self::HUMAN, $call);
        self::assertStringContainsString('not an open refusal', (string) $this->call($c, $call)['error']);

        // The session reads the fact, and the house keeps which call it was given for — with what was admitted.
        $turns = array_values(array_filter($sessions->stream(self::SESSION), static fn ($e): bool => $e->type === 'session.turn' && ($e->payload['role'] ?? null) === 'user'));
        self::assertCount(1, $turns);
        $told = (string) $turns[0]->payload['content'];
        self::assertStringStartsWith(SeatFrontier::NOTICE_PREFIX, $told);
        self::assertStringContainsString('key:' . self::HUMAN . ' admitted «herramientas:write» of the capability «Prestamos» for this seat', $told);
        self::assertStringContainsString('herramientas.agregar, herramientas.devolver, herramientas.prestar', $told);
        self::assertStringContainsString('#' . $write . ' (herramientas_agregar)', $told);
        $facts = array_values(array_filter($sessions->stream(self::SESSION), static fn ($e): bool => $e->type === GrantedCall::GRANTED));
        self::assertCount(1, $facts);
        self::assertSame($write, $facts[0]->payload['seq']);
        self::assertSame('Prestamos', $facts[0]->payload['capability']);
        self::assertSame($card['contract'], $facts[0]->payload['contract']);

        // And that fact is what the seat's next leg opens with (greenhouse decisions/0600): the session is told the
        // call can run, not to make it again — the house holds it, argument for argument.
        self::assertStringContainsString('that same call can run now', $told);
        self::assertStringNotContainsString('make that same call again', $told);
        $resumes = GrantedCall::toResume($sessions->stream(self::SESSION), new \DateTimeImmutable());
        self::assertSame($write, $resumes['seq'] ?? null);
        self::assertSame('herramientas_agregar', $resumes['tool']);
        self::assertSame(['nombre' => 'Taladro'], $resumes['arguments']);
    }

    /** The control decisions/0590 asks by name: the contract moves between seeing the card and signing. */
    public function testAContractThatMovedBetweenSeeingAndSigningAdmitsNothing(): void
    {
        [$c, $root, , $write, $plugin] = $this->house();
        $seen = $this->card($c, $write)['contract'];
        $call = ['session' => self::SESSION, 'seq' => $write, 'admits' => $seen];
        $this->signed($c, self::HUMAN, $call);

        // A promotion lands in between: one more verb under the same word.
        $this->declare($plugin, [...$this->prestamos(), $this->verb('herramientas.baja', ['herramientas:write'], mutating: true)]);
        $c->replaceService(Kernel::class, $this->kernel($root, [$plugin]));

        $r = $this->call($c, $call);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('is not the contract', (string) $r['error']);
        self::assertStringContainsString('herramientas.baja', (string) $r['error']);
        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
    }

    public function testOnlyTheLineThatEnrolledTheSeatAdmits(): void
    {
        [$c, $root, , $write] = $this->house();
        $call = ['session' => self::SESSION, 'seq' => $write, 'admits' => $this->card($c, $write)['contract']];
        $this->signed($c, self::STRANGER, $call);

        $r = $this->call($c, $call);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('you do not answer for this session', (string) $r['error']);
        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
    }

    public function testAScopeWithAVerbThatDoesNotSayItsEffectsIsNotAdmissible(): void
    {
        [$c, $root, , , $plugin] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $this->declare($plugin, [...$this->prestamos(), $this->verb('herramientas.purgar', ['herramientas:write'], mutating: true, classified: false)]);
        $c->replaceService(Kernel::class, $this->kernel($root, [$plugin]));
        $seq = $sessions->recordToolCall(self::SESSION, 'herramientas_purgar', [], 'refused', false, true);
        $card = $this->card($c, $seq);
        self::assertSame('«herramientas.purgar» does not declare its effects', $card['not_admissible']);
        $call = ['session' => self::SESSION, 'seq' => $seq, 'admits' => $card['contract']];
        $this->signed($c, self::HUMAN, $call);

        $r = $this->call($c, $call);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('«herramientas.purgar» does not declare its effects', (string) $r['error']);
        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
    }

    /** What a declaration does not say never lowers a control: a verb that changes state under no scope at all. */
    public function testAMutationThatDeclaresNoScopeIsNotAdmissible(): void
    {
        [$c, $root, , , $plugin] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $this->declare($plugin, [...$this->prestamos(), $this->verb('herramientas.vaciar', [], mutating: true)]);
        $c->replaceService(Kernel::class, $this->kernel($root, [$plugin]));
        $seq = $sessions->recordToolCall(self::SESSION, 'herramientas_vaciar', [], 'refused', false, true);
        $card = $this->card($c, $seq);
        self::assertSame('(no scope) herramientas.vaciar', $card['permission']);
        self::assertSame(['herramientas.vaciar'], array_column($card['opens'], 'verb'), 'a verb with no scope is admitted by itself');
        self::assertSame('«herramientas.vaciar» changes state and declares no scope', $card['not_admissible']);
        $call = ['session' => self::SESSION, 'seq' => $seq, 'admits' => $card['contract']];
        $this->signed($c, self::HUMAN, $call);

        $r = $this->call($c, $call);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('changes state and declares no scope', (string) $r['error']);
        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
    }

    public function testTheTwoWaysOfKnowingAreNotMixed(): void
    {
        [$c, , , $write] = $this->house();
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $admits = $this->card($c, $write)['contract'];

        // `existing` is how write over a plugin is approved; a capability's scope is not that.
        $asWrite = ['session' => self::SESSION, 'seq' => $write, 'existing' => 'Prestamos'];
        $this->signed($c, self::HUMAN, $asWrite);
        self::assertFalse($this->call($c, $asWrite)['ok']);

        // And `admits` approves nothing about an authoring refusal.
        $make = $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'Taller', 'name' => 'Taller'], "Missing required permission 'plugins.Taller:write' for plugin 'Taller'.", false, true);
        $asAdmission = ['session' => self::SESSION, 'seq' => $make, 'admits' => $admits];
        $this->signed($c, self::HUMAN, $asAdmission);
        $r = $this->call($c, $asAdmission);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('admits nothing', (string) $r['error']);
    }

    /** One more scope for a standing seat is not a list somebody typed: what was admitted to it stays. */
    public function testAGrantOfAnAuthoringScopeKeepsWhatWasAdmitted(): void
    {
        [$c, $root, , $write] = $this->house('Build Taller and lend a drill');
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $call = ['session' => self::SESSION, 'seq' => $write, 'admits' => $this->card($c, $write)['contract']];
        $this->signed($c, self::HUMAN, $call);
        self::assertTrue($this->call($c, $call)['ok']);

        $make = $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'Taller', 'name' => 'Taller'], "Missing required permission 'plugins.Taller:write' for plugin 'Taller'.", false, true);
        $this->signed($c, self::HUMAN, ['session' => self::SESSION, 'seq' => $make]);
        $grant = $this->call($c, ['session' => self::SESSION, 'seq' => $make]);

        self::assertTrue($grant['ok'], (string) ($grant['error'] ?? ''));
        self::assertContains('plugins.Taller:write', $this->ledger($root)->scopesFor(self::SEAT) ?? []);
        self::assertArrayHasKey('herramientas:write', $this->ledger($root)->admissionsFor(self::SEAT)['Prestamos'] ?? []);
    }

    /** Whoever recognizes the seat again with a typed list saw no contract: what was admitted is dropped, and said. */
    public function testASeatRecognizedAgainByATypedListLosesWhatWasAdmitted(): void
    {
        [$c, $root, , $write] = $this->house();
        $call = ['session' => self::SESSION, 'seq' => $write, 'admits' => $this->card($c, $write)['contract']];
        $this->signed($c, self::HUMAN, $call);
        self::assertTrue($this->call($c, $call)['ok']);

        $report = $this->ledger($root)->recordAndReport(new IdentityEnrolled(self::SEAT, [...self::SEAT_SCOPES, 'herramientas:write'], 'key:' . self::HUMAN));

        self::assertSame(1, $report['admissions_dropped'] ?? null);
        self::assertSame([], $this->ledger($root)->admissionsFor(self::SEAT));
        $raw = json_decode((string) file_get_contents($root . '/storage/identity/enrollments.json'), true);
        self::assertArrayHasKey('admissions', end($raw[self::SEAT]['history']), 'what it had is in the history');
        // And the typed word opens nothing: the verb is a built one.
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $policy = new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel));
        $typed = new ToolContext('key:' . self::SEAT, 'cli', [...self::SEAT_SCOPES, 'herramientas:write']);
        self::assertFalse($policy->authorize($typed, $this->tool('herramientas.prestar', ['herramientas:write'], true), [])->allowed);
    }

    /** What a person reads before admitting: the verb's contract says the scope it asks for and who declares it. */
    public function testTheContractOfABuiltVerbSaysWhatItAsksAndWhoDeclaresIt(): void
    {
        $root = $this->root();
        $c = new DIContainer();
        $package = $this->verb('almacen.vaciar', ['almacen:write'], mutating: true);
        $c->registerService(Kernel::class, $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())], [$package], $c));
        $agent = new \Milpa\AppRuntime\Operations\AgentOperations($c);

        $built = $agent->contractFor(['name' => 'herramientas_prestar']);
        self::assertTrue($built['ok'], (string) ($built['error'] ?? ''));
        self::assertSame(['herramientas:write'], $built['scopes']);
        self::assertNull($built['permission']);
        self::assertSame('Prestamos', $built['declared_by']);
        self::assertSame(['paths' => ['var/herramientas.json'], 'source' => 'entities'], $built['state']);

        $other = $agent->contractFor(['name' => 'almacen.vaciar']);
        self::assertSame(['almacen:write'], $other['scopes']);
        self::assertNull($other['declared_by'], 'no capability of this house declares it');
        self::assertNull($other['state']);
    }

    /**
     * A house with the course's capability, where the seat's session recorded a refused read and a refused write.
     *
     * @return array{0: DIContainer, 1: string, 2: int, 3: int, 4: object}
     */
    private function house(string $goal = 'Registra un taladro en el taller y préstalo.'): array
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $c = new DIContainer();
        $c->registerService(Kernel::class, $this->kernel($root, [$plugin]));
        $events = new InMemoryEventStore();
        $c->registerService(EventStoreInterface::class, $events);
        $sessions = new SessionStore($events);
        $c->registerService(SessionStore::class, $sessions);

        $sessions->start(self::SESSION, $goal, by: new Principal('key:' . self::SEAT, true));
        $read = $sessions->recordToolCall(self::SESSION, 'herramientas_listar', [], "Missing required scope for tool 'herramientas_listar'. Need one of: herramientas:read", false, false);
        $write = $sessions->recordToolCall(self::SESSION, 'herramientas_agregar', ['nombre' => 'Taladro'], "Missing required scope for tool 'herramientas_agregar'. Need one of: herramientas:write", false, true);

        return [$c, $root, $read, $write, $plugin];
    }

    private function frontier(DIContainer $c): SeatFrontier
    {
        $sessions = $c->get(SessionStore::class);
        \assert($sessions instanceof SessionStore);
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);

        return SeatFrontier::forRoot($kernel->root(), $sessions, BuiltCapabilities::of($kernel));
    }

    /** @return array<string, mixed> the open refusal at that position, as a person is shown it */
    private function card(DIContainer $c, int $seq): array
    {
        $row = $this->frontier($c)->refusal(self::SESSION, $seq);
        self::assertNotNull($row, "#{$seq} is not an open refusal");

        return $row;
    }

    /** @param list<string> $scopes */
    private function tool(string $verb, array $scopes, bool $mutating): ToolDefinition
    {
        return new ToolDefinition(McpProjector::toolName($verb), $verb, [], static fn (): array => [], scopes: $scopes, mutating: $mutating);
    }

    /** @param array<string, mixed> $arguments */
    private function signed(DIContainer $c, string $fingerprint, array $arguments): void
    {
        $authorization = new OperationAuthorization(
            operation: 'identity:grant',
            arguments: $arguments,
            host: 'lab-host',
            issuedAt: '2026-10-07T00:00:00+00:00',
            nonce: 'n-1',
        );
        $c->{$c->has(GrantedAuthorization::class) ? 'replaceService' : 'registerService'}(GrantedAuthorization::class, new GrantedAuthorization(
            authorization: $authorization,
            signer: new VerifiedSigner($fingerprint, 'Lab <lab@example.invalid>'),
            payload: $authorization->canonical(),
            signature: 'exact-signature-bytes',
        ));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $c, array $input): array
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === 'identity:grant') {
                $handler = $op->handler;
                self::assertIsCallable($handler);

                /** @var array<string, mixed> */
                return $handler($input, null, null);
            }
        }
        self::fail('identity:grant is not offered');
    }
}
