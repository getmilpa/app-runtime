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

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Agent\HouseWork;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * What the admission card says of HOW a call would run is what THIS house does (greenhouse decisions/0588, 0590).
 *
 * Measured in a lab house: the card said «in the house, confined to that state — what was there is kept» of a verb
 * whose call then ran with no confinement at all, in a house that had switched confinement off; and of one whose
 * call ran in a disposable trial and left nothing, in a house whose agent runtime could not record where work ran.
 * The leg decides both before it plans a call ({@see \Milpa\AppRuntime\Operations\AgentOperations}); the card drew
 * the plan without asking either. A card a person approves from does not say more than the house does.
 */
final class TheCardSaysHowThisHouseRunsACallTest extends TestCase
{
    use BuiltHouse;

    public function testAHouseThatRunsWorkInItselfSaysSo(): void
    {
        [$built] = $this->built();

        $work = $built->runs($built->verb('herramientas_prestar'));

        // Confined to its state where this machine can confine a process, asked of a person where it cannot.
        self::assertContains($work['how'], ['house', 'asks']);
        self::assertTrue(HouseWork::canBeRecordedBy((new \ReflectionClass(SessionStore::class))->newInstanceWithoutConstructor()), 'the agent runtime these tests run with records where work ran');
    }

    public function testAHouseThatSwitchedConfinementOffIsNotSaidToConfine(): void
    {
        [$built] = $this->built(new Config(['agent' => ['trialWorkspace' => false]]));

        $work = $built->runs($built->verb('herramientas_prestar'));

        self::assertSame('open', $work['how']);
        self::assertStringContainsString('confinement is switched off in this house', $work['why'] ?? '');
        self::assertArrayNotHasKey('pre_image', $work, 'nothing is kept to return to, and nothing says it is');
        // The same switch turns trials off: what would have run in one runs as it did before them.
        self::assertSame('open', $built->runs($built->verb('herramientas_regenerar'))['how']);
        self::assertSame(['how' => 'reads'], $built->runs($built->verb('herramientas_listar')), 'a read changes nothing either way');
    }

    /** Any other answer of the house's configuration leaves confinement on: only an explicit `false` is the switch. */
    public function testOnlyAnExplicitFalseIsTheSwitch(): void
    {
        foreach ([new Config([]), new Config(['agent' => []]), new Config(['agent' => ['trialWorkspace' => true]]), new Config(['agent' => ['trialWorkspace' => null]])] as $config) {
            [$built] = $this->built($config);
            self::assertContains($built->runs($built->verb('herramientas_prestar'))['how'], ['house', 'asks']);
        }
    }

    public function testAHouseWhoseAgentRuntimeCannotRecordWhereWorkRanIsNotSaidToConfine(): void
    {
        $old = new class () {
            /** The receipt an agent runtime before 0.53 kept: six facts, and not where it ran. */
            public function recordExecution(string $id, string $operation, mixed $executedBy, string $executorSource, ?array $authorizedBy, string $argumentsDigest): void
            {
            }
        };
        [$built] = $this->built(null, $old::class);

        $work = $built->runs($built->verb('herramientas_prestar'));

        self::assertSame('open', $work['how']);
        self::assertStringContainsString('cannot record where work ran', $work['why'] ?? '');
        self::assertStringContainsString('milpa/agent 0.53', $work['why'] ?? '', 'and says what would');
        // What is not work in the domain is planned as it always was: its trial does not wait on that receipt.
        self::assertSame(['how' => 'trial'], $built->runs($built->verb('herramientas_regenerar')));
        // A house with no agent runtime at all runs no leg, and no work in one.
        [$none] = $this->built(null, 'Milpa\\Agent\\NoSuchSessionStore');
        self::assertSame('open', $none->runs($none->verb('herramientas_prestar'))['how']);
    }

    /** A state that is no place for state is refused whatever the house confines: that is said first. */
    public function testAStateTheHouseRefusesIsStillSaidRefused(): void
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $this->keepsStateIn($plugin, ['herramientas.prestar' => ['config/app.php']]);
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['agent' => ['trialWorkspace' => false]]));
        $built = BuiltCapabilities::of($this->kernel($root, [$plugin], [], $c));

        self::assertSame('refused', $built->runs($built->verb('herramientas_prestar'))['how']);
    }

    /** It reaches the card a person reads, and it is no part of what they approve: the digest does not move. */
    public function testTheCardCarriesItAndTheContractDoesNotMove(): void
    {
        [$on, $root, $plugin] = $this->built();
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['agent' => ['trialWorkspace' => false]]));
        $off = BuiltCapabilities::of($this->kernel($root, [$plugin], [], $c));

        $card = CapabilityAdmissions::forRoot($root, $off)->card(self::SEAT, 'Prestamos', 'herramientas:write');

        self::assertNotNull($card);
        foreach ($card['opens'] as $verb) {
            self::assertSame('open', $verb['runs']['how'], $verb['verb']);
        }
        self::assertSame(
            CapabilityAdmissions::forRoot($root, $on)->card(self::SEAT, 'Prestamos', 'herramientas:write')['contract'] ?? null,
            $card['contract'],
            'how a call runs here is said beside the contract, not inside it',
        );
    }

    /**
     * The course's capability, with one verb that is not work in the domain, in a house configured as given.
     *
     * @param class-string|string|null $sessions the session store this house records with, when not the installed one
     *
     * @return array{0: BuiltCapabilities, 1: string, 2: object}
     */
    private function built(?Config $config = null, ?string $sessions = null): array
    {
        $root = $this->root();
        $executable = new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Executable);
        $plugin = $this->capability($root, 'Prestamos', [
            ...$this->prestamos(),
            $this->verb('herramientas.regenerar', ['herramientas:write'], mutating: true, effects: $executable),
        ]);
        $container = null;
        if ($config !== null) {
            $container = new DIContainer();
            $container->registerService(Config::class, $config);
        }
        $kernel = $this->kernel($root, [$plugin], [], $container);
        \assert($kernel instanceof Kernel);

        return [$sessions === null ? BuiltCapabilities::of($kernel) : BuiltCapabilities::of($kernel, $sessions), $root, $plugin];
    }
}
