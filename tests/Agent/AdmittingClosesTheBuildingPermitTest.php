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

use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\MissingPermission;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\Console\McpProjector;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Admitting closes the building permit (greenhouse decisions/0590, rule 10).
 *
 * A built capability is IN WORKS — some seat holds `plugins.<X>:write`, and no seat uses its verbs — or ADMITTED —
 * no seat can write it. Never both. Measured before this (greenhouse evidence/1145): the seat that built a
 * capability and had its verbs admitted extended it with no new act of authority, so what a person had admitted by
 * its contract could be rewritten under that same contract and nobody saw.
 *
 * An admission takes that permit from every seat, and the ledger keeps who closed it. Changing the capability
 * afterwards takes the grant a person already gives knowingly over an existing plugin; while that permit stands
 * what was admitted is suspended — kept, and not counted — and the next admission closes the works again.
 */
final class AdmittingClosesTheBuildingPermitTest extends TestCase
{
    use BuiltHouse;

    private const PERMIT = 'plugins.Prestamos:write';
    private const PASSKEY = 'QM1LEWEfsoWiMm';

    public function testAnAdmissionTakesTheBuildingPermitFromEverySeatAndOnlyThat(): void
    {
        [$root, $kernel] = $this->house();
        $this->grant($root, self::SEAT, [self::PERMIT, 'plugins.Blog:write']);
        $this->grant($root, self::OTHER_SEAT, [self::PERMIT]);
        $ledger = $this->ledger($root);
        $ledger->record(new IdentityEnrolled(self::PASSKEY, ['milpa.admin', self::PERMIT], 'key:' . self::HUMAN));
        self::assertSame([self::SEAT, self::OTHER_SEAT], $ledger->permitHolders('Prestamos'), 'a passkey is a person, not a seat');

        $this->admit($root, $kernel, self::SEAT, 'herramientas:read');

        self::assertSame([...self::SEAT_SCOPES, 'plugins.Blog:write'], $ledger->scopesFor(self::SEAT), 'the permit of another plugin, and every other word, stay');
        self::assertSame(self::SEAT_SCOPES, $ledger->scopesFor(self::OTHER_SEAT), 'a seat that was admitted nothing loses it too');
        self::assertSame(['milpa.admin', self::PERMIT], $ledger->scopesFor(self::PASSKEY));
        self::assertSame([], $ledger->permitHolders('Prestamos'));
        self::assertArrayHasKey('herramientas:read', $ledger->admissionsFor(self::SEAT)['Prestamos']);
    }

    /** A revoked seat holds nothing: it is not counted as holding the permit, and its entry is not rewritten. */
    public function testARevokedSeatIsNeitherCountedNorTouched(): void
    {
        [$root, $kernel] = $this->house();
        $this->grant($root, self::OTHER_SEAT, [self::PERMIT]);
        $ledger = $this->ledger($root);
        $ledger->revoke(self::OTHER_SEAT, 'key:' . self::HUMAN);
        $file = $root . '/storage/identity/enrollments.json';
        $before = json_decode((string) file_get_contents($file), true)[self::OTHER_SEAT];
        self::assertSame([], $ledger->permitHolders('Prestamos'));

        $this->admit($root, $kernel, self::SEAT, 'herramientas:read');

        self::assertSame($before, json_decode((string) file_get_contents($file), true)[self::OTHER_SEAT]);
    }

    public function testTheLedgerKeepsWhoClosedEachPermit(): void
    {
        [$root, $kernel] = $this->house();
        $this->grant($root, self::OTHER_SEAT, [self::PERMIT]);

        $this->admit($root, $kernel, self::SEAT, 'herramientas:read');

        $ledger = $this->ledger($root);
        $trail = $ledger->closuresFor(self::OTHER_SEAT);
        self::assertCount(1, $trail);
        self::assertSame('Prestamos', $trail[0]['capability']);
        self::assertSame('key:' . self::HUMAN, $trail[0]['closed_by']);
        self::assertSame(['seat' => self::SEAT, 'scope' => 'herramientas:read'], $trail[0]['admitted']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $trail[0]['at']);
        self::assertSame([], $ledger->closuresFor(self::SEAT), 'a seat that held no permit has nothing closed');
        // The state it replaced — the seat with its permit — is in the history, as after every write.
        $raw = json_decode((string) file_get_contents($root . '/storage/identity/enrollments.json'), true);
        self::assertContains(self::PERMIT, end($raw[self::OTHER_SEAT]['history'])['scopes']);
        // One more scope for the seat keeps the trail; a typed list starts it over.
        $ledger->recordAndReport(new IdentityEnrolled(self::OTHER_SEAT, [...self::SEAT_SCOPES, 'plugins.Blog:write'], 'key:' . self::HUMAN), keepAdmissions: true);
        self::assertCount(1, $ledger->closuresFor(self::OTHER_SEAT));
        $ledger->recordAndReport(new IdentityEnrolled(self::OTHER_SEAT, self::SEAT_SCOPES, 'key:' . self::HUMAN));
        self::assertSame([], $ledger->closuresFor(self::OTHER_SEAT));
    }

    public function testWhileACapabilityIsInWorksNoSeatUsesItsVerbs(): void
    {
        [$root, $kernel] = $this->house();
        $this->admit($root, $kernel, self::SEAT, 'herramientas:read');
        $this->admit($root, $kernel, self::SEAT, 'herramientas:write');
        $this->admit($root, $kernel, self::OTHER_SEAT, 'herramientas:read');
        self::assertTrue($this->asks($root, $kernel, self::SEAT, 'herramientas.listar')->allowed);

        // A person reopens the works: the grant over the existing plugin, to one seat.
        $this->grant($root, self::OTHER_SEAT, [self::PERMIT]);

        $admissions = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel));
        self::assertSame([self::OTHER_SEAT], $admissions->inWorks('Prestamos'));
        foreach ([self::SEAT, self::OTHER_SEAT] as $seat) {
            $refused = $this->asks($root, $kernel, $seat, 'herramientas.listar');
            self::assertFalse($refused->allowed, 'the holder of the permit does not use its verbs either');
            self::assertStringContainsString('«Prestamos» is in works', (string) $refused->reason);
            self::assertStringContainsString("'herramientas:read' of «Prestamos»", (string) $refused->reason, 'spelled the way the leg\'s door looks for');
        }
        self::assertFalse($this->asks($root, $kernel, self::SEAT, 'herramientas.prestar')->allowed);
        // Suspended is not dropped: what was admitted is still written.
        self::assertCount(2, $this->ledger($root)->admissionsFor(self::SEAT)['Prestamos']);
        self::assertSame([], $admissions->inWorks('Otra'));
    }

    /** A seat that never was admitted reads its own reason, and that the capability is in works. */
    public function testASeatWithNoAdmissionIsToldBoth(): void
    {
        [$root, $kernel] = $this->house();
        $this->grant($root, self::SEAT, [self::PERMIT]);

        $refused = $this->asks($root, $kernel, self::SEAT, 'herramientas.listar');

        self::assertFalse($refused->allowed);
        self::assertStringContainsString('no person has admitted it for this seat', (string) $refused->reason);
        self::assertStringContainsString('«Prestamos» is in works', (string) $refused->reason);
        self::assertStringContainsString('admitting closes that permit', (string) $refused->reason);
    }

    public function testTheNextAdmissionClosesTheWorksAndWhatDidNotChangeStandsAgain(): void
    {
        [$root, $kernel, $plugin] = $this->house();
        foreach ([self::SEAT, self::OTHER_SEAT] as $seat) {
            $this->admit($root, $kernel, $seat, 'herramientas:read');
            $this->admit($root, $kernel, $seat, 'herramientas:write');
        }
        $this->grant($root, self::OTHER_SEAT, [self::PERMIT]);
        // In the works, a verb under `herramientas:write` changes what it declares.
        $this->declare($plugin, [
            $this->verb('herramientas.listar', ['herramientas:read']),
            $this->verb('herramientas.agregar', ['herramientas:write'], mutating: true),
            $this->verb('herramientas.prestar', ['herramientas:write', 'prestamos:lend'], mutating: true),
            $this->verb('herramientas.devolver', ['herramientas:write'], mutating: true),
        ]);
        $after = $this->kernel($root, [$plugin]);

        // The second act: a person admits what came out, to one seat.
        $this->admit($root, $after, self::SEAT, 'herramientas:write');

        self::assertSame([], $this->ledger($root)->permitHolders('Prestamos'), 'the works are closed for every seat');
        self::assertTrue($this->asks($root, $after, self::SEAT, 'herramientas.prestar')->allowed, 'admitted with the contract it has now');
        self::assertTrue($this->asks($root, $after, self::SEAT, 'herramientas.listar')->allowed, 'what did not change stands again, with no act');
        self::assertTrue($this->asks($root, $after, self::OTHER_SEAT, 'herramientas.listar')->allowed);
        self::assertTrue($this->asks($root, $after, self::OTHER_SEAT, 'herramientas.agregar')->allowed);
        $moved = $this->asks($root, $after, self::OTHER_SEAT, 'herramientas.prestar');
        self::assertFalse($moved->allowed, 'what changed is admitted seat by seat, as before');
        self::assertStringContainsString('its contract changed', (string) $moved->reason);
        self::assertStringNotContainsString('in works', (string) $moved->reason);
    }

    public function testTheCardSaysWhoHoldsThePermitAndKeepsWhatChangedInView(): void
    {
        [$root, $kernel, $plugin] = $this->house();
        $this->admit($root, $kernel, self::SEAT, 'herramientas:write');
        $closed = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->card(self::SEAT, 'Prestamos', 'herramientas:write');
        self::assertNull($closed['works'] ?? null);
        self::assertFalse($closed['suspended'] ?? null, 'admitted, and nobody holds the permit: nothing is suspended');
        $this->grant($root, self::OTHER_SEAT, [self::PERMIT]);
        $this->declare($plugin, [
            $this->verb('herramientas.listar', ['herramientas:read']),
            $this->verb('herramientas.agregar', ['herramientas:write'], mutating: true),
            $this->verb('herramientas.prestar', ['herramientas:write', 'prestamos:lend'], mutating: true),
            $this->verb('herramientas.devolver', ['herramientas:write'], mutating: true),
            $this->verb('herramientas.baja', ['herramientas:write'], mutating: true),
        ]);
        $admissions = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($this->kernel($root, [$plugin])));

        $card = $admissions->card(self::SEAT, 'Prestamos', 'herramientas:write');

        self::assertNotNull($card);
        self::assertSame(['holders' => [self::OTHER_SEAT]], $card['works']);
        // What changed stays in view: a verb the seat has as admitted says so, and is not painted «in works».
        self::assertSame(
            ['herramientas.agregar' => 'admitted', 'herramientas.baja' => 'added', 'herramientas.devolver' => 'admitted', 'herramientas.prestar' => 'changed'],
            array_column($card['opens'], 'standing', 'verb'),
        );
        // And whether the admission of that scope is whole and only suspended, or lacks something of its own:
        // measured in the lab house, a card for a suspended admission was headed «No admission covers…».
        self::assertFalse($card['suspended'], 'a verb changed and another was added: this one lacks something of its own');
        self::assertTrue($admissions->card(self::SEAT, 'Prestamos', 'herramientas:write')['works'] !== null);
        $this->admit($root, $this->kernel($root, [$plugin]), self::SEAT, 'herramientas:write');
        $this->grant($root, self::OTHER_SEAT, [self::PERMIT]);
        $whole = $admissions->card(self::SEAT, 'Prestamos', 'herramientas:write');
        self::assertTrue($whole['suspended'] ?? null, 'admitted as it stands, and in works: suspended');
        self::assertFalse($admissions->card(self::OTHER_SEAT, 'Prestamos', 'herramientas:write')['suspended'] ?? null, 'never admitted to this seat: nothing of its is suspended');
        self::assertTrue(array_column($admissions->holdingsOf(self::SEAT, self::SEAT_SCOPES)['unadmitted'], 'suspended', 'scope')['herramientas:write']);
        // The list a person reads says it too: what is admitted is suspended, and who holds the permit.
        $held = $admissions->holdingsOf(self::SEAT, self::SEAT_SCOPES);
        self::assertSame([self::OTHER_SEAT], $held['admitted'][0]['suspended']);
        $holder = $admissions->holdingsOf(self::OTHER_SEAT, [...self::SEAT_SCOPES, self::PERMIT]);
        self::assertSame(['Prestamos'], $holder['permits']);
    }

    public function testASeatWhosePermitWasClosedDoesNotWriteThePluginWithTheWordItsLegStillCarries(): void
    {
        [$root, $kernel] = $this->house();
        $this->grant($root, self::SEAT, [self::PERMIT]);
        $policy = $this->policy($root, $kernel);
        // The leg started while the seat held the permit: its context carries the word until the leg ends.
        $leg = new ToolContext('key:' . self::SEAT, 'mcp', [...self::SEAT_SCOPES, self::PERMIT]);
        self::assertSame(['src/Plugins/Prestamos', 'tests/Plugins/Prestamos'], $policy->writePaths($leg, 'make', ['plugin' => 'Prestamos']));

        $this->admit($root, $kernel, self::OTHER_SEAT, 'herramientas:read');

        try {
            $policy->writePaths($leg, 'make', ['plugin' => 'Prestamos']);
            self::fail('a closed permit wrote the plugin');
        } catch (MissingPermission $refused) {
            self::assertSame(self::PERMIT, $refused->permission);
            self::assertSame('Prestamos', $refused->plugin);
            self::assertStringContainsString('was closed when «Prestamos» was admitted', $refused->getMessage());
            self::assertStringContainsString('key:' . self::HUMAN, $refused->getMessage(), 'and it says who');
        }
        // Only that permit: a word of another plugin its leg carries is judged as it was.
        $other = new ToolContext('key:' . self::SEAT, 'mcp', [...self::SEAT_SCOPES, 'plugins.Blog:write']);
        self::assertSame(['src/Plugins/Blog', 'tests/Plugins/Blog'], $policy->writePaths($other, 'make', ['plugin' => 'Blog']));
        // The frontier judges it the same way, so the grant that reopens the works is what a person is offered.
        self::assertSame(self::PERMIT, $policy->missingPermission($leg, 'edit', ['plugin' => 'Prestamos']));
        // What a trial holds crosses under the same judge: a promotion after the admission is refused.
        try {
            $policy->authorizePaths($leg, ['src/Plugins/Prestamos/Prestamos.php'], $root);
            self::fail('a closed permit promoted over the plugin');
        } catch (MissingPermission $refused) {
            self::assertSame(self::PERMIT, $refused->permission);
        }
    }

    public function testAPermitAPersonGrantsAgainIsAPermitAndPutsTheCapabilityInWorks(): void
    {
        [$root, $kernel] = $this->house();
        $this->grant($root, self::SEAT, [self::PERMIT]);
        $this->admit($root, $kernel, self::SEAT, 'herramientas:read');
        $policy = $this->policy($root, $kernel);
        $context = new ToolContext('key:' . self::SEAT, 'mcp', [...self::SEAT_SCOPES, self::PERMIT]);

        $this->grant($root, self::SEAT, [self::PERMIT]);

        self::assertSame(['src/Plugins/Prestamos', 'tests/Plugins/Prestamos'], $policy->writePaths($context, 'make', ['plugin' => 'Prestamos']));
        self::assertFalse($this->asks($root, $kernel, self::SEAT, 'herramientas.listar')->allowed, 'in works: what was admitted is suspended');
        self::assertCount(1, $this->ledger($root)->closuresFor(self::SEAT), 'what happened is still said');
    }

    /** The rule is about seats: who is not a key this ledger enrolled is judged as before. */
    public function testOnlyASeatsPermitIsReadFromTheLedger(): void
    {
        [$root, $kernel] = $this->house();
        $this->admit($root, $kernel, self::SEAT, 'herramientas:read');
        $policy = $this->policy($root, $kernel);
        $paths = ['src/Plugins/Prestamos', 'tests/Plugins/Prestamos'];

        // A key the ledger never enrolled, carrying the word from elsewhere; a passkey; and the terminal's operator.
        self::assertSame($paths, $policy->writePaths(new ToolContext('key:ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234', 'cli', [self::PERMIT]), 'make', ['plugin' => 'Prestamos']));
        self::assertSame($paths, $policy->writePaths(new ToolContext('passkey:' . self::PASSKEY, 'web', [self::PERMIT]), 'make', ['plugin' => 'Prestamos']));
        self::assertNull($policy->writePaths(ToolContext::cli(), 'make', ['plugin' => 'Prestamos']));
        // And a seat whose permit nobody closed keeps the word its context carries, as before: another plugin's.
        self::assertSame(['src/Plugins/Blog', 'tests/Plugins/Blog'], $policy->writePaths(new ToolContext('key:' . self::SEAT, 'mcp', ['plugins.Blog:write']), 'make', ['plugin' => 'Blog']));
    }

    /**
     * A house with the course's capability built in it and two seats as seated.
     *
     * @return array{0: string, 1: Kernel, 2: object}
     */
    private function house(): array
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());

        return [$root, $this->kernel($root, [$plugin]), $plugin];
    }

    /**
     * A person gives the seat more words, keeping what was admitted — the grant of a scope of authoring.
     *
     * @param list<string> $more
     */
    private function grant(string $root, string $seat, array $more): void
    {
        $ledger = $this->ledger($root);
        $ledger->recordAndReport(new IdentityEnrolled($seat, array_values(array_unique([...($ledger->scopesFor($seat) ?? []), ...$more])), 'key:' . self::HUMAN), keepAdmissions: true);
    }

    private function admit(string $root, Kernel $kernel, string $seat, string $scope): void
    {
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', $scope);
        self::assertNotNull($group, "Prestamos declares nothing under {$scope}");
        self::assertTrue($this->ledger($root)->admit($seat, 'Prestamos', $scope, $group['verbs'], 'key:' . self::HUMAN));
    }

    private function policy(string $root, Kernel $kernel): PluginAuthoringPolicy
    {
        return new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel));
    }

    private function asks(string $root, Kernel $kernel, string $seat, string $verb): AuthorizationResult
    {
        return $this->policy($root, $kernel)->authorize(
            new ToolContext('key:' . $seat, 'cli', $this->ledger($root)->scopesFor($seat) ?? []),
            new ToolDefinition(McpProjector::toolName($verb), $verb, [], static fn (): array => []),
            [],
        );
    }
}
