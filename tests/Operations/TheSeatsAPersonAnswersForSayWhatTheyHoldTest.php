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

use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Tests\Agent\BuiltHouse;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * A person can read what each seat they answer for holds — and what it no longer runs (greenhouse decisions/0590).
 *
 * The rule changes houses that already exist: a seat somebody gave a word of the domain to by hand ran those verbs,
 * and now it does not until a person admits them. Nothing is migrated — a typed word carried no contract anybody
 * saw. So the house says it, in one read: for each seat, what persons admitted to it and whether that still stands,
 * and the verbs of built capabilities no admission covers, marked when the seat's own words used to open them.
 */
final class TheSeatsAPersonAnswersForSayWhatTheyHoldTest extends TestCase
{
    use BuiltHouse;

    private const STRANGER = 'D00D0000111122223333444455556666777788889';

    public function testItIsAReadForWhoeverDecidesAFrontierAndNeverASeatsOwn(): void
    {
        $op = $this->operation(new DIContainer());

        self::assertFalse($op->mutating);
        self::assertFalse($op->requiresConfirmation);
        self::assertSame(['identity:enroll'], $op->scopes);
        self::assertSame(['cli', 'http'], $op->surfaces);
        self::assertTrue($op->effectCeiling()->isFullyClassified());
    }

    /** The house that existed before the rule: the seat was handed the words of the domain by hand. */
    public function testASeatThatHeldTheWordsByHandIsToldWhatItNoLongerRuns(): void
    {
        [$c, $root] = $this->house();
        $this->ledger($root)->record(new IdentityEnrolled(self::SEAT, [...self::SEAT_SCOPES, 'herramientas:read', 'herramientas:write'], 'key:' . self::HUMAN));

        $seats = array_column($this->seats($c)['seats'], null, 'fingerprint');

        self::assertSame([], $seats[self::SEAT]['admitted']);
        self::assertSame([
            ['capability' => 'Prestamos', 'scope' => 'herramientas:read', 'verbs' => ['herramientas.listar'], 'ran_before' => true],
            ['capability' => 'Prestamos', 'scope' => 'herramientas:write', 'verbs' => ['herramientas.agregar', 'herramientas.devolver', 'herramientas.prestar'], 'ran_before' => true],
        ], $seats[self::SEAT]['unadmitted']);
        // The other seat never held them: the same verbs wait for a person, and nothing was taken from it.
        self::assertSame([false, false], array_column($seats[self::OTHER_SEAT]['unadmitted'], 'ran_before'));
    }

    public function testAVerbThatAskedForNothingOrForAWordEverySeatHasIsMarkedToo(): void
    {
        $root = $this->root();
        $c = new DIContainer();
        $c->registerService(Kernel::class, $this->kernel($root, [$this->capability($root, 'Prestamos', [
            $this->verb('herramientas.listar', []),
            $this->verb('herramientas.agregar', ['agent:run'], mutating: true),
            $this->verb('herramientas.vaciar', [], mutating: true),
            $this->verb('herramientas.prestar', ['herramientas:write'], mutating: true),
        ])]));

        $waiting = array_column(array_column($this->seats($c)['seats'], null, 'fingerprint')[self::SEAT]['unadmitted'], 'ran_before', 'scope');

        self::assertSame([
            '(no scope) herramientas.listar' => true,
            '(no scope) herramientas.vaciar' => false,
            'agent:run' => true,
            'herramientas:write' => false,
        ], $waiting, 'a read that asked for nothing ran; a mutation that asked for nothing never did');
    }

    public function testWhatWasAdmittedIsListedWithWhetherItStillStands(): void
    {
        [$c, $root, $plugin] = $this->house();
        $kernel = $c->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', 'herramientas:write');
        self::assertNotNull($group);
        $this->ledger($root)->admit(self::SEAT, 'Prestamos', 'herramientas:write', $group['verbs'], 'key:' . self::HUMAN, '2026-10-07T00:00:00Z');

        $seat = array_column($this->seats($c)['seats'], null, 'fingerprint')[self::SEAT];
        self::assertSame([[
            'capability' => 'Prestamos',
            'scope' => 'herramientas:write',
            'admitted_by' => 'key:' . self::HUMAN,
            'at' => '2026-10-07T00:00:00Z',
            'verbs' => ['herramientas.agregar' => 'admitted', 'herramientas.devolver' => 'admitted', 'herramientas.prestar' => 'admitted'],
        ]], $seat['admitted']);
        self::assertSame(['herramientas:read'], array_column($seat['unadmitted'], 'scope'));

        // The capability moves: one verb leaves, one is added, one changes what it asks.
        $this->declare($plugin, [
            $this->verb('herramientas.listar', ['herramientas:read']),
            $this->verb('herramientas.agregar', ['herramientas:write'], mutating: true),
            new Operation(name: 'herramientas.prestar', description: 'Otra cosa', handler: static fn (): array => [], mutating: true, scopes: ['herramientas:write'], effects: $this->verb('x', ['y'], mutating: true)->effects),
            $this->verb('herramientas.baja', ['herramientas:write'], mutating: true),
        ]);
        $c->replaceService(Kernel::class, $this->kernel($root, [$plugin]));

        $seat = array_column($this->seats($c)['seats'], null, 'fingerprint')[self::SEAT];
        self::assertSame(['herramientas.agregar' => 'admitted', 'herramientas.devolver' => 'gone', 'herramientas.prestar' => 'changed'], $seat['admitted'][0]['verbs']);
        $write = array_column($seat['unadmitted'], null, 'scope')['herramientas:write'];
        self::assertSame(['herramientas.baja', 'herramientas.prestar'], $write['verbs'], 'what the standing admission does not cover');
    }

    public function testAPersonReadsOnlyTheSeatsTheyAnswerForAndTheTerminalReadsThemAll(): void
    {
        [$c, $root] = $this->house();
        $this->ledger($root)->record(new IdentityEnrolled('QM1LEWEfsoWiMm', ['milpa.admin', 'identity:enroll'], 'key:' . self::HUMAN));
        $this->ledger($root)->record(new IdentityEnrolled('ZZ9otherCredential', ['milpa.admin', 'identity:enroll'], 'key:' . self::STRANGER));

        $mine = $this->seats($c, new ToolContext('passkey:QM1LEWEfsoWiMm', 'web', ['identity:enroll']));
        $theirs = $this->seats($c, new ToolContext('passkey:ZZ9otherCredential', 'web', ['identity:enroll']));
        $terminal = $this->seats($c, ToolContext::cli());

        self::assertEqualsCanonicalizing([self::SEAT, self::OTHER_SEAT], array_column($mine['seats'], 'fingerprint'));
        self::assertSame([], $theirs['seats'], 'another line reads nothing of these seats');
        self::assertEqualsCanonicalizing([self::SEAT, self::OTHER_SEAT], array_column($terminal['seats'], 'fingerprint'), 'the operator of the terminal reads every seat, and no passkey');
        self::assertSame(self::SEAT_SCOPES, array_column($mine['seats'], 'scopes', 'fingerprint')[self::SEAT]);
    }

    /**
     * A house with the course's capability and two seats as seated.
     *
     * @return array{0: DIContainer, 1: string, 2: object}
     */
    private function house(): array
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $c = new DIContainer();
        $c->registerService(Kernel::class, $this->kernel($root, [$plugin]));

        return [$c, $root, $plugin];
    }

    private function operation(DIContainer $c): Operation
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === 'identity:seats') {
                return $op;
            }
        }
        self::fail('identity:seats is not offered');
    }

    /** @return array{ok: bool, seats: list<array<string, mixed>>} */
    private function seats(DIContainer $c, ?ToolContext $authority = null): array
    {
        $handler = $this->operation($c)->handler;
        self::assertIsCallable($handler);
        $answer = $handler([], null, $authority ?? ToolContext::cli());
        self::assertTrue($answer['ok'], (string) ($answer['error'] ?? ''));

        return $answer;
    }
}
