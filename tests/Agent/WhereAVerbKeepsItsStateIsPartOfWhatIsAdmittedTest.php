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
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Console\McpProjector;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Where a verb's work keeps its state is part of the contract a person admits (greenhouse decisions/0590, rule 4;
 * decisions/0588, rule 3).
 *
 * The house confines a call of work to the state its verb declares. So a person who admits a verb admits it writing
 * THERE — and a verb that later keeps its state somewhere else is not the verb that was admitted. The judge of that
 * state is the one of work in the domain; this reads its answer and pins it.
 */
final class WhereAVerbKeepsItsStateIsPartOfWhatIsAdmittedTest extends TestCase
{
    use BuiltHouse;

    public function testAWriteOfTheDomainSaysWhereItsStateLivesAndAReadKeepsNone(): void
    {
        $root = $this->root();
        $built = BuiltCapabilities::of($this->kernel($root, [$this->capability($root, 'Prestamos', $this->prestamos())]));

        self::assertSame(['paths' => ['var/herramientas.json'], 'source' => 'entities'], $built->verb('herramientas_prestar')?->state());
        self::assertSame(['paths' => ['var/herramientas.json'], 'source' => 'entities'], $built->verb('herramientas_prestar')?->contract()['state']);
        self::assertNull($built->verb('herramientas_listar')?->state(), 'a read keeps no state');
    }

    public function testAStateThePluginDeclaresIsSaidAsDeclared(): void
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $this->keepsStateIn($plugin, ['herramientas.prestar' => ['var/taller/prestamos.json']]);
        $built = BuiltCapabilities::of($this->kernel($root, [$plugin]));

        self::assertSame(['paths' => ['var/taller/prestamos.json'], 'source' => 'declared'], $built->verb('herramientas_prestar')?->state());
        self::assertSame(['paths' => ['var/herramientas.json'], 'source' => 'entities'], $built->verb('herramientas_agregar')?->state());
    }

    /** «Otro estado» is another contract: the admission of that verb lapses, and its siblings keep running. */
    public function testAVerbThatMovesItsStateIsNoLongerTheVerbThatWasAdmitted(): void
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $kernel = $this->kernel($root, [$plugin]);
        $group = CapabilityAdmissions::forRoot($root, BuiltCapabilities::of($kernel))->group('Prestamos', 'herramientas:write');
        self::assertNotNull($group);
        self::assertTrue($this->ledger($root)->admit(self::SEAT, 'Prestamos', 'herramientas:write', $group['verbs'], 'key:' . self::HUMAN));
        self::assertTrue($this->asks($root, $kernel, 'herramientas.prestar'));

        $this->keepsStateIn($plugin, ['herramientas.prestar' => ['var/otra-parte.json']]);
        $moved = $this->kernel($root, [$plugin]);

        self::assertFalse($this->asks($root, $moved, 'herramientas.prestar'), 'it writes somewhere nobody admitted');
        self::assertTrue($this->asks($root, $moved, 'herramientas.agregar'));
    }

    /** A state the house would refuse to mount — code, configuration, its own records — is not something to admit. */
    public function testAVerbThatKeepsItsStateWhereTheHouseKeepsNoneIsNotAdmissible(): void
    {
        $root = $this->root();
        $plugin = $this->capability($root, 'Prestamos', $this->prestamos());
        $this->keepsStateIn($plugin, ['herramientas.prestar' => ['config/app.php']]);
        $built = BuiltCapabilities::of($this->kernel($root, [$plugin]));
        $verb = $built->verb('herramientas_prestar');
        self::assertNotNull($verb);

        self::assertSame('declared', $verb->state()['source'] ?? null);
        self::assertStringContainsString('not a place work may keep state', (string) ($verb->state()['refused'] ?? ''));
        self::assertSame('«herramientas.prestar» keeps its state where the house keeps none', $verb->notAdmissible());
        self::assertSame('refused', $built->runs($verb)['how']);
        self::assertNull($built->verb('herramientas_agregar')?->notAdmissible());
    }

    /** What a card says of how each verb would run, asked of the judge of work when the card is drawn. */
    public function testACardSaysHowEachVerbWouldRunInThisHouse(): void
    {
        $root = $this->root();
        $executable = new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Executable);
        $built = BuiltCapabilities::of($this->kernel($root, [$this->capability($root, 'Prestamos', [
            ...$this->prestamos(),
            $this->verb('herramientas.regenerar', ['herramientas:write'], mutating: true, effects: $executable),
        ])]));

        self::assertSame(['how' => 'reads'], $built->runs($built->verb('herramientas_listar')));
        self::assertSame(['how' => 'trial'], $built->runs($built->verb('herramientas_regenerar')), 'not work in the domain: it lands as authoring does');
        // Work in the domain: confined to its state where this machine can confine a process, asked of a person where it cannot.
        $work = $built->runs($built->verb('herramientas_prestar'));
        self::assertContains($work['how'], ['house', 'asks']);
        self::assertSame($work['how'] === 'house', isset($work['pre_image']));
        self::assertSame(['how' => 'trial'], BuiltCapabilities::none()->runs($built->verb('herramientas_prestar')), 'nothing to ask: no house behind it');
    }

    private function asks(string $root, Kernel $kernel, string $verb): bool
    {
        $policy = new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel));

        return $policy->authorize(
            new ToolContext('key:' . self::SEAT, 'cli', self::SEAT_SCOPES),
            new ToolDefinition(McpProjector::toolName($verb), $verb, [], static fn (): array => [], scopes: ['herramientas:write'], mutating: true),
            [],
        )->allowed;
    }
}
