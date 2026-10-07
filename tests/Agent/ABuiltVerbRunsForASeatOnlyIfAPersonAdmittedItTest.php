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
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * For a seat, a verb of a capability built in the house runs only if a person admitted that verb with the contract
 * it has today (greenhouse decisions/0590, rules 1 to 4, 8 and 9).
 *
 * The word a built verb declares as its scope is written by whoever writes the capability — and that may be the
 * seat. Measured on the published train: a capability declaring a scope the seat already held was read AND written
 * by it with no human act; one declaring none was read; a verb added under a scope already given ran unseen. So the
 * word alone opens nothing here.
 */
final class ABuiltVerbRunsForASeatOnlyIfAPersonAdmittedItTest extends TestCase
{
    use BuiltHouse;

    public function testASeatIsRefusedAVerbNobodyAdmittedAndTheSentenceSaysWhatItIs(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);

        $verdict = $this->ask($root, $kernel, self::SEAT, 'herramientas.listar');

        self::assertFalse($verdict->allowed);
        self::assertStringContainsString('«herramientas.listar» is a verb of the capability «Prestamos»', (string) $verdict->reason);
        self::assertStringContainsString('no person has admitted it for this seat', (string) $verdict->reason);
    }

    /** The baseline's second row: the capability names its scope after one the seat was seated with. */
    public function testAScopeTheSeatAlreadyHoldsDoesNotOpenABuiltVerb(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos(read: 'agent:read', write: 'agent:run'))]);

        self::assertFalse($this->ask($root, $kernel, self::SEAT, 'herramientas.listar')->allowed, 'reading');
        self::assertFalse($this->ask($root, $kernel, self::SEAT, 'herramientas.agregar')->allowed, 'writing');
    }

    /** The baseline's third row: a read that declares no scope ran for any seat. */
    public function testAVerbThatDeclaresNoScopeIsNotOpenEither(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', [$this->verb('herramientas.listar', [])])]);

        $verdict = $this->ask($root, $kernel, self::SEAT, 'herramientas.listar');

        self::assertFalse($verdict->allowed);
        self::assertStringContainsString('no person has admitted it', (string) $verdict->reason);
    }

    public function testAfterAPersonAdmitsAScopeItsVerbsRunAndTheOtherScopeStaysShut(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);

        $this->admit($root, $kernel, self::SEAT, 'Prestamos', 'herramientas:write');

        foreach (['herramientas.agregar', 'herramientas.prestar', 'herramientas.devolver'] as $verb) {
            self::assertTrue($this->ask($root, $kernel, self::SEAT, $verb)->allowed, $verb);
        }
        self::assertFalse($this->ask($root, $kernel, self::SEAT, 'herramientas.listar')->allowed, 'reading is another act');
    }

    /** The baseline's fifth row: «dar de baja», hung from a scope already given, ran with nobody having seen it. */
    public function testAVerbAddedUnderAnAdmittedScopeIsBornRefused(): void
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $kernel = $this->kernel($root, [$plugin]);
        $this->admit($root, $kernel, self::SEAT, 'Prestamos', 'herramientas:write');

        $this->declare($plugin, [...$this->prestamos(), $this->verb('herramientas.baja', ['herramientas:write'], mutating: true)]);
        $grown = $this->kernel($root, [$plugin]);

        $verdict = $this->ask($root, $grown, self::SEAT, 'herramientas.baja');
        self::assertFalse($verdict->allowed);
        self::assertStringContainsString('added after', (string) $verdict->reason);
        self::assertTrue($this->ask($root, $grown, self::SEAT, 'herramientas.prestar')->allowed, 'what was admitted still runs');
    }

    public function testAVerbWhoseContractChangedIsRefusedAndTheOthersRun(): void
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $kernel = $this->kernel($root, [$plugin]);
        $this->admit($root, $kernel, self::SEAT, 'Prestamos', 'herramientas:write');

        // The same verb, under the same word — and now it cannot be undone and reaches a third party.
        $harsher = new EffectProfile(Mutation::Persistent, Externality::ThirdParty, Reversibility::Irreversible, Authority::WriteAsUser, subject: Subject::Data);
        $this->declare($plugin, [
            $this->verb('herramientas.listar', ['herramientas:read']),
            $this->verb('herramientas.agregar', ['herramientas:write'], mutating: true),
            $this->verb('herramientas.prestar', ['herramientas:write'], mutating: true, effects: $harsher),
            $this->verb('herramientas.devolver', ['herramientas:write'], mutating: true),
        ]);
        $changed = $this->kernel($root, [$plugin]);

        $verdict = $this->ask($root, $changed, self::SEAT, 'herramientas.prestar');
        self::assertFalse($verdict->allowed);
        self::assertStringContainsString('contract changed', (string) $verdict->reason);
        self::assertTrue($this->ask($root, $changed, self::SEAT, 'herramientas.agregar')->allowed);
    }

    public function testAnotherSeatInheritsNothing(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $this->admit($root, $kernel, self::SEAT, 'Prestamos', 'herramientas:write');

        self::assertFalse($this->ask($root, $kernel, self::OTHER_SEAT, 'herramientas.prestar')->allowed);
    }

    public function testARevokedSeatRunsNoBuiltVerb(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', [$this->verb('herramientas.listar', [])])]);
        $this->admit($root, $kernel, self::SEAT, 'Prestamos', '=herramientas.listar');
        self::assertTrue($this->ask($root, $kernel, self::SEAT, 'herramientas.listar')->allowed);

        $this->ledger($root)->revoke(self::SEAT, 'key:' . self::HUMAN);

        self::assertFalse($this->ask($root, $kernel, self::SEAT, 'herramientas.listar', scopes: [])->allowed);
    }

    /** Whoever is not an enrolled key is judged by the word, as before: this rule is about seats. */
    public function testTheTerminalAPasskeyAndAKeyTheHouseNeverEnrolledAreJudgedAsBefore(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $policy = $this->policy($root, $kernel);
        $tool = $this->tool($kernel, 'herramientas.prestar');

        // A passkey lives in the same ledger as a seat, and it is a person: this rule is not about it.
        $this->ledger($root)->record(new IdentityEnrolled('QM1LEWEfsoWiMm', ['milpa.admin', 'herramientas:write'], 'key:' . self::HUMAN));

        self::assertTrue($policy->authorize(ToolContext::cli(), $tool, [])->allowed, 'the terminal');
        self::assertTrue($policy->authorize(new ToolContext('passkey:QM1LEWEfsoWiMm', 'web', ['milpa.admin', 'herramientas:write']), $tool, [])->allowed, 'a passkey');
        self::assertTrue($policy->authorize(new ToolContext('key:D00D0000111122223333444455556666777788889', 'cli', ['herramientas:write']), $tool, [])->allowed, 'a key by static policy');
    }

    public function testAnOperationThatNoBuiltCapabilityDeclaresIsJudgedAsBefore(): void
    {
        $root = $this->root();
        $package = $this->verb('graph.start', ['graph:run'], mutating: true);
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())], [$package]);

        self::assertNull(BuiltCapabilities::of($kernel)->verb('graph_start'));
        self::assertTrue($this->policy($root, $kernel)->authorize($this->seat(self::SEAT), $this->toolOf($package), [])->allowed);
    }

    /**
     * BUILT is where the class lives. A plugin the house runs from anywhere else — a package it installed — declares
     * verbs that are not this house's to admit: its scopes are judged by the word, as before.
     */
    public function testAPluginThatDoesNotLiveInTheHousesOwnTreeIsNotBuiltHere(): void
    {
        $root = $this->root();
        $package = new PackagedWorkshop([$this->verb('almacen.contar', ['almacen:read'])]);
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos()), $package]);
        $built = BuiltCapabilities::of($kernel);

        self::assertSame('Prestamos', $built->verb('herramientas_listar')?->capability);
        self::assertNull($built->verb('almacen_contar'));
        self::assertTrue($this->policy($root, $kernel)->authorize($this->seat(self::SEAT), $this->tool($kernel, 'almacen.contar'), [])->allowed);
    }

    public function testAHouseThatBuiltNothingHasNothingToAdmit(): void
    {
        $root = $this->root();

        // No tree of its own at all.
        $bare = BuiltCapabilities::of($this->kernel($root, [new PackagedWorkshop([$this->verb('almacen.contar', ['almacen:read'])])]));
        self::assertTrue($bare->isEmpty());
        self::assertSame([], $bare->capabilities());
        self::assertTrue(BuiltCapabilities::none()->isEmpty());

        // A file straight under src/Plugins is nobody's tree; a plugin that declares no operation builds no verb.
        mkdir($root . '/src/Plugins', 0o777, true);
        $class = 'Loose' . bin2hex(random_bytes(4));
        file_put_contents($root . '/src/Plugins/' . $class . '.php', "<?php\nnamespace MilpaTest\\Built;\nfinal class {$class} implements \\Milpa\\Command\\CommandProvider { public function operations(): array { return [new \\Milpa\\Command\\Operation(name: 'suelto.leer', description: 'x', handler: static fn (): array => [])]; } }\n");
        require_once $root . '/src/Plugins/' . $class . '.php';
        $loose = 'MilpaTest\\Built\\' . $class;
        $built = BuiltCapabilities::of($this->kernel($root, [new $loose(), new \stdClass(), $this->capability($root, 'Prestamos', $this->prestamos())]));
        self::assertNull($built->verb('suelto_leer'));
        self::assertSame(['Prestamos'], $built->capabilities());
        self::assertCount(4, $built->verbsOf('Prestamos'));
        self::assertSame([], $built->verbsOf('Otra'));

        // A kernel that cannot say what it booted — a test double, a half-built one — built nothing it can be asked about.
        $blank = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Kernel::class, 'root'))->setValue($blank, $root);
        self::assertTrue(BuiltCapabilities::of($blank)->isEmpty());
        self::assertNull(BuiltCapabilities::ofContainer(new \Milpa\Container\DIContainer()));
    }

    /** The same judge stands at the operation boundary, where the terminal and every other surface execute. */
    public function testTheBoundaryRefusesAndAdmitsTheSameCall(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $policy = $this->policy($root, $kernel);
        $operation = $this->operationOf($kernel, 'herramientas.listar');

        try {
            $policy->execute($operation, [], $this->seat(self::SEAT), static fn (): string => 'ran');
            self::fail('the verb ran for a seat nobody admitted it for');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('no person has admitted it for this seat', $refused->getMessage());
        }

        $this->admit($root, $kernel, self::SEAT, 'Prestamos', 'herramientas:read');

        self::assertSame('ran', $policy->execute($operation, [], $this->seat(self::SEAT), static fn (): string => 'ran'));
    }

    /** A house with no built capability, or a policy nobody told about them, changes nothing. */
    public function testWithoutBuiltCapabilitiesThePolicyJudgesAsBefore(): void
    {
        $root = $this->root();
        $kernel = $this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]);
        $tool = $this->tool($kernel, 'herramientas.listar');

        self::assertTrue((new PluginAuthoringPolicy($root))->authorize($this->seat(self::SEAT), $tool, [])->allowed);
    }

    /** @param list<string>|null $scopes */
    private function ask(string $root, Kernel $kernel, string $seat, string $verb, ?array $scopes = null): AuthorizationResult
    {
        return $this->policy($root, $kernel)->authorize($this->seat($seat, $scopes), $this->tool($kernel, $verb), []);
    }

    private function policy(string $root, Kernel $kernel): PluginAuthoringPolicy
    {
        return new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel));
    }

    /** @param list<string>|null $scopes */
    private function seat(string $fingerprint, ?array $scopes = null): ToolContext
    {
        return new ToolContext('key:' . $fingerprint, 'cli', $scopes ?? self::SEAT_SCOPES);
    }

    private function admit(string $root, Kernel $kernel, string $seat, string $capability, string $scope): void
    {
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group($capability, $scope);
        self::assertNotNull($group, "{$capability} declares nothing under {$scope}");
        self::assertTrue($this->ledger($root)->admit($seat, $capability, $scope, $group['verbs'], 'key:' . self::HUMAN));
    }

    private function tool(Kernel $kernel, string $verb): ToolDefinition
    {
        return $this->toolOf($this->operationOf($kernel, $verb));
    }

    private function toolOf(Operation $operation): ToolDefinition
    {
        return new ToolDefinition(McpProjector::toolName($operation->name), $operation->description, $operation->inputSchema ?? [], $operation->handler, scopes: $operation->scopes, mutating: $operation->mutating);
    }

    private function operationOf(Kernel $kernel, string $verb): Operation
    {
        foreach ($kernel->commands() as $operation) {
            if ($operation->name === $verb) {
                return $operation;
            }
        }
        self::fail("the house does not declare {$verb}");
    }
}

/** A plugin as a package ships it: its class lives in the package, not in the house's own tree. */
final class PackagedWorkshop implements \Milpa\Command\CommandProvider
{
    /** @param list<Operation> $declared */
    public function __construct(private readonly array $declared)
    {
    }

    public function operations(): array
    {
        return $this->declared;
    }
}
