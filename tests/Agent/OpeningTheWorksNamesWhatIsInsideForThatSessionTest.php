<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\GrantedCall;
use Milpa\AppRuntime\Agent\OpenedWorks;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\DevTools\Operations\EditHandler;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Decided by Rod, 2026-10-08 (greenhouse decisions/0602).
 *
 * Opening the works of a capability names, for that session, what is inside.
 *
 * Extending a capability that exists is bracketed by two acts of a person: the grant that reopens its works —an
 * informed act, the plugin's name repeated (decisions/0510)— and the admission that closes them (decisions/0590,
 * rule 10). Between the two the intent contract asked a person again, piece by piece: measured (evidence/1158), the
 * house asked whether to edit the class of the very call a person had just granted, and whether to `make` on the
 * plugin whose name that person had just typed. Finishing one extension took a grant, four answers and an admission.
 *
 * So for an authoring operation, the target is NAMED when this session's own record says a person granted it the
 * building permit of that plugin over existing work, the target is that plugin or a file of it, and the works are
 * still open. Read from facts the house wrote, with no model in the circuit; whatever those facts do not say is
 * asked, as before.
 *
 * @guards an `edit` on a class of the opened plugin, in its sources and in its tests, and a `make` on it, being
 *         named by the grant; a second grant to the same session after a closure naming again
 *
 * @refuses a session with no grant, whoever holds the permit; another session's grant; another plugin's target, and a
 *          class that is not of the opened plugin; works an admission closed; a permit reopened for another session;
 *          a one-touch grant; a grant for another permit; a fact that does not say which seat or how many closures;
 *          a seat that was revoked; an operation that is not authoring; a grave operation; a call that names no
 *          plugin; a gate with no house to read
 *
 * @subject-in milpa/app-runtime
 */
final class OpeningTheWorksNamesWhatIsInsideForThatSessionTest extends TestCase
{
    private const SEAT = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';
    private const HUMAN = '1111AAAA2222BBBB3333CCCC4444DDDD5555EEEE';
    private const SCOPES = ['agent:run', 'plugins:read'];

    private string $root;

    private InMemoryEventStore $events;

    private SessionStore $sessions;

    /** What the gate answered to the last call sent to it: null when it let the call through. */
    private ?string $refused = null;

    private int $n = 0;

    protected function setUp(): void
    {
        if (!method_exists(ImplementHandler::class, 'scaffold')) {
            self::markTestSkipped('The installed DevTools keeps its scaffold lookup private; the gate cannot tell a file of the plugin from another.');
        }
        $this->root = sys_get_temp_dir() . '/milpa-opened-' . bin2hex(random_bytes(5));
        foreach (['src/Plugins/Bodega/Operations', 'tests/Plugins/Bodega', 'src/Plugins/Otro/Operations', 'storage/identity'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o700, true);
        }
        // Everything here was in the house before any session below: nothing is «born here» (decisions/0596).
        foreach (['src/Plugins/Bodega/Bodega.php', 'src/Plugins/Bodega/Operations/GuardarCaja.php', 'tests/Plugins/Bodega/GuardarCajaTest.php',
            'src/Plugins/Otro/Otro.php', 'src/Plugins/Otro/Operations/Cosa.php'] as $file) {
            file_put_contents($this->root . '/' . $file, "<?php // it was here\n");
        }
        $this->ledger()->record(new IdentityEnrolled(self::SEAT, self::SCOPES, 'key:' . self::HUMAN));
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        // The goal names neither the plugin nor a class of it, as the station that extends does on purpose.
        $this->sessions->start('extend', 'Boxes can be written off.', AutonomyMode::Auto);
        $this->sessions->start('other', 'Another errand of the same seat.', AutonomyMode::Auto);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testAnEditInsideThePluginAPersonOpenedForThisSessionIsNotAsked(): void
    {
        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja'], session: $this->another()), 'the control: with no grant it asks');

        $this->opens('extend');

        self::assertNull($this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
        self::assertNull($this->refused, 'and the call goes on to the doors that follow');
    }

    public function testAMakeOnThatPluginIsNotAsked(): void
    {
        $this->opens('extend');

        self::assertNull($this->asks('make', ['what' => 'operation', 'plugin' => 'Bodega', 'name' => 'BorrarCaja']));
    }

    public function testATestOfThatPluginIsInsideIt(): void
    {
        $this->opens('extend');

        self::assertNull($this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCajaTest']));
    }

    /** The house as it is today where no rule closed a permit: a seat that simply holds it. Nobody opened anything here. */
    public function testASeatThatHoldsThePermitWithNoGrantInThisSessionIsAskedAsToday(): void
    {
        $this->holds(self::SEAT, 'plugins.Bodega:write');

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
        self::assertSame('target_not_named', $this->asks('make', ['what' => 'operation', 'plugin' => 'Bodega', 'name' => 'BorrarCaja'], session: $this->another()));
    }

    public function testTheGrantOfAnotherSessionNamesNothingHere(): void
    {
        $this->opens('other');

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']), 'nobody decided about this session');
        self::assertNull($this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja'], session: 'other'), 'and the session it was given to is not asked');
    }

    public function testATargetOfAnotherPluginIsAsked(): void
    {
        $this->opens('extend');
        $this->holds(self::SEAT, 'plugins.Otro:write');

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Otro', 'class' => 'Cosa']));
        $second = $this->another();
        $this->opens($second);
        self::assertSame('target_not_named', $this->asks('make', ['what' => 'operation', 'plugin' => 'Otro', 'name' => 'OtraCosa'], session: $second));
        self::assertNull($this->asks('make', ['what' => 'operation', 'plugin' => 'Bodega', 'name' => 'BorrarCaja'], session: $this->opened()), 'the control: the plugin that was opened is named');
    }

    /**
     * Naming the opened plugin beside a class that is not its own opens nothing: the class is looked up in that
     * plugin's own trees, and a call with nothing to land on is refused before anyone is asked (decisions/0591).
     */
    public function testAClassThatIsNotOfTheOpenedPluginHasNothingToLandOn(): void
    {
        $this->opens('extend');

        self::assertNull($this->asks('edit', ['plugin' => 'Bodega', 'class' => 'Cosa']));
        self::assertNotNull($this->refused, 'the call did not run');
        self::assertStringContainsString('Cosa', (string) $this->refused);
    }

    public function testOnceAnAdmissionClosedTheWorksTheGrantNamesNothing(): void
    {
        $this->opens('extend');
        $this->closes();

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
    }

    /** The admission of ANOTHER capability closes that one's permit, and nothing of these works. */
    public function testAnAdmissionOfAnotherCapabilityClosesNothingHere(): void
    {
        $this->holds(self::SEAT, 'plugins.Otro:write');
        $this->opens('extend');
        $this->closes('Otro');

        self::assertNull($this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
    }

    /** The seat holds the permit again — for another session. The count of closures on this session's grant is stale. */
    public function testAPermitReopenedForAnotherSessionDoesNotReviveThisSessionsGrant(): void
    {
        $this->opens('extend');
        $this->closes();
        $this->opens('other');

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
        self::assertNull($this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja'], session: 'other'));
    }

    public function testASecondGrantToThisSessionAfterAClosureNamesAgain(): void
    {
        $this->opens('extend');
        $this->closes();
        $this->opens('extend');

        self::assertNull($this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
    }

    /** One touch over a plugin that did not exist is another act (decisions/0510): this rule does not count it. */
    public function testAOneTouchGrantNamesNothing(): void
    {
        $this->opens('extend', fact: []);

        self::assertSame('target_not_named', $this->asks('make', ['what' => 'operation', 'plugin' => 'Bodega', 'name' => 'BorrarCaja']));
    }

    public function testAGrantForAnotherPermitNamesNothing(): void
    {
        // The seat holds both permits, and the fact says «Bodega» — but the grant it rides on was of another permit.
        $this->holds(self::SEAT, 'plugins.Bodega:write');
        $this->opens('extend', permission: 'plugins.Otro:write', fact: OpenedWorks::fact($this->ledger(), self::SEAT, 'Bodega'));

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
    }

    public function testAFactThatDoesNotSayWhichSeatOrHowManyClosuresNamesNothing(): void
    {
        foreach ([['existing' => 'Bodega'], ['existing' => 'Bodega', 'seat' => self::SEAT], ['existing' => 'Bodega', 'closures' => 0],
            ['existing' => 'Bodega', 'seat' => self::SEAT, 'closures' => '0'], ['existing' => 'Bodega', 'seat' => 'not-a-key', 'closures' => 0],
            ['existing' => 'Bodega', 'seat' => 7, 'closures' => 0], ['seat' => self::SEAT, 'closures' => 0], ['existing' => 'Otro', 'seat' => self::SEAT, 'closures' => 0]] as $fact) {
            $session = $this->another();
            $this->opens($session, fact: $fact);

            self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja'], session: $session), (string) json_encode($fact));
        }
        self::assertNull($this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja'], session: $this->opened()), 'the control: the whole fact names');
    }

    /** Whatever else in the session carries those words is not the house's fact of a grant. */
    public function testOnlyTheHousesOwnFactOfAGrantIsRead(): void
    {
        $this->holds(self::SEAT, 'plugins.Bodega:write');
        $this->events->append(new Event(
            streamId: SessionStore::PREFIX . 'extend',
            type: 'session.effect_observed',
            payload: ['permission' => 'plugins.Bodega:write'] + OpenedWorks::fact($this->ledger(), self::SEAT, 'Bodega'),
            seq: $this->events->nextSeq(),
        ));

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
    }

    public function testARevokedSeatsGrantNamesNothing(): void
    {
        $this->opens('extend');
        $this->ledger()->revoke(self::SEAT, 'key:' . self::HUMAN);

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
    }

    /** Turning the opened plugin off is selecting among what exists, not writing inside it. */
    public function testAnOperationThatIsNotAuthoringIsAskedOnThatSamePlugin(): void
    {
        $this->opens('extend');

        self::assertSame('target_not_named', $this->asks('plugins_disable', ['plugin' => 'Bodega']));
    }

    public function testAGraveOperationIsAskedWhateverWasOpened(): void
    {
        $this->opens('extend');

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja'], authority: Authority::Privileged));
    }

    public function testACallThatNamesNoPluginIsAsked(): void
    {
        $this->opens('extend');

        self::assertSame('target_not_named', $this->asks('edit', ['class' => 'GuardarCaja']));
    }

    public function testWithoutAHouseToReadTheGateAsksAsBefore(): void
    {
        $this->opens('extend');

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja'], house: false));
    }

    public function testALedgerThatCannotBeReadNamesNothing(): void
    {
        $this->opens('extend');
        file_put_contents($this->root . '/storage/identity/enrollments.json', '{ not json');

        self::assertSame('target_not_named', $this->asks('edit', ['plugin' => 'Bodega', 'class' => 'GuardarCaja']));
    }

    /** A session nobody has asked anything yet: a question left pending would answer for the next call. */
    private function another(): string
    {
        $id = 'session-' . (++$this->n);
        $this->sessions->start($id, 'Boxes can be written off.', AutonomyMode::Auto);

        return $id;
    }

    /** A new session a person opened the works of the plugin for. */
    private function opened(): string
    {
        $id = $this->another();
        $this->opens($id);

        return $id;
    }

    private function ledger(): FileEnrollmentStore
    {
        return new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
    }

    /** @param string ...$more scopes the seat is given besides what it holds */
    private function holds(string $seat, string ...$more): void
    {
        $ledger = $this->ledger();
        $ledger->recordAndReport(new IdentityEnrolled($seat, array_values(array_unique([...($ledger->scopesFor($seat) ?? self::SCOPES), ...$more])), 'key:' . self::HUMAN), keepAdmissions: true);
    }

    /**
     * A person grants a refused call of that session the building permit of the plugin, as `identity:grant` leaves
     * it: the seat holds the permit, and the session's record carries the fact.
     *
     * @param array<string, mixed>|null $fact what the grant says of the works it opened; null is what the house writes
     */
    private function opens(string $session, string $plugin = 'Bodega', ?string $permission = null, ?array $fact = null): void
    {
        $permission ??= 'plugins.' . $plugin . ':write';
        $this->sessions->recordToolCall($session, 'edit', ['plugin' => $plugin, 'class' => 'GuardarCaja'], "Missing required permission '{$permission}'", false, true);
        $refused = null;
        foreach ($this->sessions->stream($session) as $event) {
            $refused = $event->type === 'session.tool_called' ? $event : $refused;
        }
        self::assertNotNull($refused);
        $fact ??= OpenedWorks::fact($this->ledger(), self::SEAT, $plugin);
        $this->holds(self::SEAT, $permission);
        GrantedCall::granted($this->events, $session, $refused, $permission, 'key:' . self::HUMAN, $fact);
    }

    /** A person admits a scope of the capability: the admission closes its building permit (decisions/0590, rule 10). */
    private function closes(string $plugin = 'Bodega'): void
    {
        self::assertTrue($this->ledger()->admit(self::SEAT, $plugin, 'bodega:write', ['bodega:guardar' => 'sha256:' . str_repeat('a', 64)], 'key:' . self::HUMAN));
        self::assertNotContains('plugins.' . $plugin . ':write', $this->ledger()->scopesFor(self::SEAT) ?? []);
    }

    /**
     * Send the call to the gate of that session; the reason of the question it left, or null when it asked nothing.
     *
     * @param array<string, mixed> $arguments
     */
    private function asks(string $tool, array $arguments, string $session = 'extend', Authority $authority = Authority::WriteAsUser, bool $house = true): ?string
    {
        $loaded = $this->sessions->load($session);
        self::assertNotNull($loaded);
        $gate = new SessionToolGate(
            $this->sessions,
            $loaded,
            [$this->operation('make', 'plugin', $authority), $this->operation('edit', 'class', $authority), $this->operation('plugins_disable', 'plugin', $authority)],
            petition: 'continue',
            trialRouter: $house ? new TrialRouter($this->root, new TrialRunner(bwrap: '/nonexistent/bwrap'), __FILE__) : null,
        );
        $this->refused = $gate->refuse($tool, $arguments);

        return $this->sessions->load($session)?->question?->reason;
    }

    /** An operation as the house has them: it declares which argument names its target, and nothing that relaxes it. */
    private function operation(string $name, string $named, Authority $authority): Operation
    {
        return new Operation(
            $name,
            'Does its thing.',
            [EditHandler::class, 'handle'],
            inputSchema: ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string'], 'class' => ['type' => 'string']]],
            mutating: true,
            namedTarget: $named,
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::ManualRecovery,
                $authority,
                escalatesOn: [$named],
                subject: Subject::Executable,
            ),
        );
    }
}
