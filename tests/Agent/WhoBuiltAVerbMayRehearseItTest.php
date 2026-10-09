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
use Milpa\Agent\EffectObservation;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\OwnVerbRehearsal;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * WHO BUILT A VERB MAY REHEARSE IT — who, and only who (greenhouse decisions/0605, R2).
 *
 * Measured on twenty build runs (greenhouse evidence/1166): four sessions called a verb they had just built, and the
 * house stopped all four — «…built in this house, and no person has admitted it for this seat» (decisions/0590). The
 * leg ended there. So the house answers that call in a rehearsal — but ONLY for the session that wrote the verb, and
 * only while the capability is in works.
 *
 * These are the falsifiers, written before the code:
 *
 *  - X5 — it answers who did not build: another session of the SAME seat, another seat, or the same session after
 *    someone else landed that file.
 *  - X7 — a rehearsal answers from the house's own state: a capability that keeps state where the copy would carry
 *    it is not rehearsed at all.
 *
 * «This session wrote it» is read from its own receipts, by FILE: the last thing that landed on the file declaring
 * the verb is a promotion of this session, and the file is still what that promotion left. One file that declares
 * several verbs makes them all this session's.
 */
final class WhoBuiltAVerbMayRehearseItTest extends TestCase
{
    use BuiltHouse;

    private const PERMIT = 'plugins.Prestamos:write';
    private const FILE = 'src/Plugins/Prestamos/Prestamos.php';

    private InMemoryEventStore $events;

    private SessionStore $sessions;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
    }

    public function testTheSessionThatLandedAVerbMayRehearseItWhileItIsInWorks(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('builder', $root);

        $verb = $this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar');

        self::assertNotNull($verb, 'it built the verb, the capability is in works, and nobody landed that file since');
        self::assertSame('herramientas.prestar', $verb->operation->name);
        self::assertNotNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_listar'), 'by file: every verb that file declares');
    }

    /** X5, first: the seat's building permit is not the session's. A second session of the same seat wrote nothing. */
    public function testAnotherSessionOfTheSameSeatMayNot(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('builder', $root);
        $this->sessions->start('another', 'Lend the drill.', AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, true));

        self::assertNull($this->mayRehearse($root, $kernel, 'another', self::SEAT, 'herramientas_prestar'));
    }

    /** X5: another seat, in a session of its own, with the very same permit. */
    public function testAnotherSeatMayNot(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('builder', $root);
        $this->grant($root, self::OTHER_SEAT, [self::PERMIT]);
        $this->sessions->start('theirs', 'Lend the drill.', AutonomyMode::Auto, by: new Principal('key:' . self::OTHER_SEAT, true));

        self::assertNull($this->mayRehearse($root, $kernel, 'theirs', self::OTHER_SEAT, 'herramientas_prestar'));
    }

    /**
     * X5, the fourth: the builder's OWN session, continued by another seat that holds the very same permit. A
     * different seat inherits nothing and building does not give the use (greenhouse decisions/0590, rules 8 and 9):
     * the one exception is that the caller built it, and this caller did not. Who may speak in a session is another
     * rule's (decisions/0517); this one asks who built.
     */
    public function testAnotherSeatThatContinuesTheBuildersSessionMayNot(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('builder', $root);
        $this->grant($root, self::OTHER_SEAT, [self::PERMIT]);
        self::assertNotNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'), 'the control: the seat that opened it');

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::OTHER_SEAT, 'herramientas_prestar'));
    }

    /** A session no verified seat opened — a terminal's, or one whose opener nobody verified — has no seat that built in it. */
    public function testASessionNoVerifiedSeatOpenedIsRehearsedByNobody(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('terminal', $root, openedBy: null);
        $this->landedBy('unverified', $root, openedBy: new Principal('key:' . self::SEAT, false));

        self::assertNull($this->mayRehearse($root, $kernel, 'terminal', self::SEAT, 'herramientas_prestar'));
        self::assertNull($this->mayRehearse($root, $kernel, 'unverified', self::SEAT, 'herramientas_prestar'));
    }

    /** X5: the same session, after something else landed on that file. It is no longer what this session left. */
    public function testAfterAnotherLandingOnThatFileTheSessionMayNot(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('builder', $root);
        self::assertNotNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'), 'the control');

        file_put_contents($root . '/' . self::FILE, "\n// landed by another session\n", \FILE_APPEND);

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'));
    }

    /** A rehearsal the session only made in a trial — never promoted — landed nothing. */
    public function testATrialThatNeverLandedIsNotALanding(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->sessions->start('builder', 'Build Prestamos.', AutonomyMode::Auto);
        $this->sessions->recordToolCall('builder', 'implement', ['plugin' => 'Prestamos', 'class' => 'Prestamos'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'changed' => [self::FILE => 'modified'], 'output' => ['ok' => true],
        ]), mutating: true);

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'));
    }

    /**
     * What a tool answers is data. A result shaped like a promotion — from a tool that is not the house's promotion,
     * the session's own verb for one — says nothing landed, whatever it says of itself.
     */
    public function testAResultShapedLikeAPromotionFromAnotherToolIsNotALanding(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('forger', $root, 'herramientas_listar');

        self::assertNull($this->mayRehearse($root, $kernel, 'forger', self::SEAT, 'herramientas_prestar'));
    }

    /** What counts is the LAST landing of that file by this session: what it left before is not what stands. */
    public function testTheSessionThatLandedItTwiceRehearsesWhatItLeftLast(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $first = (string) file_get_contents($root . '/' . self::FILE);
        $this->landedBy('builder', $root);
        file_put_contents($root . '/' . self::FILE, "\n// its repair\n", \FILE_APPEND);
        $this->promotes('builder', $root);

        self::assertNotNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'), 'its second landing stands');

        file_put_contents($root . '/' . self::FILE, $first);

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'), 'put back as it first left it, by someone else: not its last landing');
    }

    /** A promotion that points at no observed effect says nothing of the file — whatever an earlier one observed. */
    public function testALaterPromotionTheHouseObservedNothingOfIsNotReadAsTheOneBefore(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('builder', $root);
        $this->promotes('builder', $root, observedAtAll: false);

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'));
    }

    /** An effect the house could not observe is not a file it knows it left: closed, not open. */
    public function testAPromotionWhoseEffectTheHouseCouldNotObserveIsNotOne(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('builder', $root, 'sandbox_promote', known: false);

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'));
    }

    /** After a person admits it, the works are closed: the call is work, in the house, and there is nothing to rehearse. */
    public function testOnceAPersonAdmittedItThereIsNothingToRehearse(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('builder', $root);
        foreach (['herramientas:read', 'herramientas:write'] as $scope) {
            $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', $scope);
            self::assertNotNull($group);
            self::assertTrue($this->ledger($root)->admit(self::SEAT, 'Prestamos', $scope, $group['verbs'], 'key:' . self::HUMAN));
        }
        self::assertSame([], $this->ledger($root)->permitHolders('Prestamos'), 'the control: admitting closed the permit');

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'));
    }

    /** Refused, but not in works — nobody holds the permit: that is 0590 and nothing else. */
    public function testAVerbRefusedWhileNobodyHoldsThePermitIsNotRehearsed(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $this->landedBy('builder', $root);
        self::assertNotNull(CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->missing('key:' . self::SEAT, 'herramientas_prestar'), 'the control: the seat is refused');

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'));
    }

    /**
     * X7. The copy a rehearsal runs in starts with an empty `var/`: a store kept there is left behind. A capability
     * that keeps state anywhere else would be answered with rows of real work, so it is not rehearsed at all.
     */
    public function testACapabilityThatKeepsStateWhereTheCopyCarriesItIsNotRehearsed(): void
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $this->keepsStateIn($plugin, ['herramientas.agregar' => ['storage/herramientas.json']]);
        $kernel = $this->kernel($root, [$plugin]);
        $this->grant($root, self::SEAT, [self::PERMIT]);
        $this->landedBy('builder', $root);

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_prestar'), 'not that verb');
        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'herramientas_listar'), 'and not one that only reads: it would read that store');
    }

    public function testWhatIsNoVerbOfABuiltCapabilityAndWhoIsNoSeatAreNotRehearsed(): void
    {
        [$root, $kernel] = $this->houseInWorks();
        $this->landedBy('builder', $root);

        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'implement'));
        self::assertNull($this->mayRehearse($root, $kernel, 'builder', self::SEAT, 'no_such_tool'));
        self::assertNull(OwnVerbRehearsal::mayRehearse($root, $this->sessions->stream('builder'), CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel)), BuiltCapabilities::of($kernel), 'passkey:QM1LEWEfsoWiMm', 'herramientas_prestar'));
        self::assertNull(OwnVerbRehearsal::mayRehearse($root, $this->sessions->stream('builder'), CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel)), BuiltCapabilities::of($kernel), null, 'herramientas_prestar'));
    }

    /** @return array{0: string, 1: Kernel} a house whose capability Prestamos is in works: the seat holds its building permit */
    private function houseInWorks(): array
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $this->grant($root, self::SEAT, [self::PERMIT]);

        return [$root, $kernel];
    }

    private function grant(string $root, string $seat, array $more): void
    {
        $ledger = $this->ledger($root);
        $ledger->recordAndReport(new IdentityEnrolled($seat, array_values(array_unique([...($ledger->scopesFor($seat) ?? []), ...$more])), 'key:' . self::HUMAN), keepAdmissions: true);
    }

    /**
     * A session that built the capability: its trial was promoted, the promotion's receipt says what the capability
     * declares and in which file, and the house observed what that promotion left of the file — as the house records it.
     */
    private function landedBy(string $session, string $root, string $promotedBy = 'sandbox_promote', bool $known = true, Principal|false|null $openedBy = false): void
    {
        // Opened by the seat, verified, as a resident's session is — unless a case says who else, or nobody.
        $this->sessions->start($session, 'Build a plugin named Prestamos to lend the tools of a workshop.', AutonomyMode::Auto, by: $openedBy === false ? new Principal('key:' . self::SEAT, true) : $openedBy);
        $this->sessions->recordToolCall($session, 'implement', ['plugin' => 'Prestamos', 'class' => 'Prestamos'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'changed' => [self::FILE => 'modified'], 'output' => ['ok' => true],
        ]), mutating: true);
        $this->promotes($session, $root, $promotedBy, $known);
    }

    /** The promotion of the file as it is in the house now, with what the house observed it leave — or could not observe. */
    private function promotes(string $session, string $root, string $promotedBy = 'sandbox_promote', bool $known = true, bool $observedAtAll = true): void
    {
        $left = hash('sha256', (string) json_encode(['applied', self::FILE, hash_file('sha256', $root . '/' . self::FILE)], \JSON_THROW_ON_ERROR));
        $observed = $this->sessions->recordEffectObservation($session, $promotedBy, ['workspace' => 'w1'], new EffectObservation('app-runtime/file-effects/v1', $known, $known ? [$left] : []));
        $this->sessions->recordToolCall($session, $promotedBy, ['workspace' => 'w1'], (string) json_encode([
            'ok' => true,
            'promoted' => [self::FILE],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w1', 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => 'w1'], 'paths' => [self::FILE]],
            'capabilities' => [['predicate' => 'declared', 'subject' => 'Prestamos', 'environment' => ['kind' => 'house'], 'operations' => array_map(
                static fn (string $name): array => ['name' => $name, 'file' => self::FILE, 'mutating' => $name !== 'herramientas.listar', 'effects' => true, 'scoped' => true],
                ['herramientas.listar', 'herramientas.agregar', 'herramientas.prestar', 'herramientas.devolver'],
            )]],
        ]), mutating: true, effectObservationSeq: $observedAtAll ? $observed : null);
    }

    private function mayRehearse(string $root, Kernel $kernel, string $session, string $seat, string $tool): ?\Milpa\AppRuntime\Agent\BuiltVerb
    {
        $built = BuiltCapabilities::of($kernel);

        return OwnVerbRehearsal::mayRehearse($root, $this->stream($session), CapabilityAdmissions::forRoot($root, $built), $built, 'key:' . $seat, $tool);
    }

    /** @return list<Event> */
    private function stream(string $session): array
    {
        return $this->sessions->stream($session);
    }
}
