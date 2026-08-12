<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Config\MachineOverlay;
use Milpa\AppRuntime\Operations\ConfigOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * Configuration as a governed act — the last step of the pin's rung.
 *
 * The pair of ceilings is the point, and the second test is the control: reading has to stay a read.
 * If both operations borrowed the heavy ceiling, this would not be two surfaces, it would be one
 * expensive one wearing two names — and everyone would learn to route around it.
 */
final class ConfigOperationsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cfgops-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        file_put_contents($this->root . '/config/app.php', "<?php return ['agent' => ['instructions' => 'from the human']];\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->root . MachineOverlay::RUTA);
        @unlink($this->root . '/config/app.php');
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root . '/config');
        @rmdir($this->root);
    }

    /** Writing borrows the heaviest ceiling of what a judge criterion can permit. */
    public function testWritingBorrowsTheHeaviestCeilingItCouldPermit(): void
    {
        $escribe = $this->operacion('config:set');

        self::assertSame(Subject::Executable, $escribe->effectCeiling()->subject);
        self::assertSame(Authority::Privileged, $escribe->effectCeiling()->authority);
    }

    /** THE CONTROL: reading stays a read, or this is one expensive surface wearing two names. */
    public function testReadingStaysARead(): void
    {
        $lee = $this->operacion('config');

        self::assertSame(Mutation::None, $lee->effectCeiling()->mutation);
        self::assertSame(Authority::Read, $lee->effectCeiling()->authority);
    }

    /** The key is written where the machine owns, nested from its dotted path. */
    public function testItWritesTheKeyWhereTheMachineOwns(): void
    {
        ($this->operacion('config:set')->handler)(['key' => 'agent.permissionWindow', 'value' => 'PT1H']);

        $escrito = json_decode((string) file_get_contents($this->root . MachineOverlay::RUTA), true);

        self::assertSame('PT1H', $escrito['agent']['permissionWindow']);
    }

    /** And it says when what was written governs the judge, because the caller cannot tell by looking. */
    public function testItSaysWhenTheKeyGovernsTheJudge(): void
    {
        $handler = $this->operacion('config:set')->handler;

        self::assertTrue(($handler)(['key' => 'agent.transitions.frontier', 'value' => []])['governs_the_judge']);
        self::assertFalse(($handler)(['key' => 'agent.instructions', 'value' => 'hola'])['governs_the_judge']);
    }

    /** Writing one key does not evaporate the sibling nobody mentioned. */
    public function testWritingOneKeyLeavesTheOthersAlone(): void
    {
        $handler = $this->operacion('config:set')->handler;
        ($handler)(['key' => 'agent.instructions', 'value' => 'from the machine']);
        ($handler)(['key' => 'agent.permissionWindow', 'value' => 'PT8H']);

        $escrito = json_decode((string) file_get_contents($this->root . MachineOverlay::RUTA), true);

        self::assertSame('from the machine', $escrito['agent']['instructions']);
        self::assertSame('PT8H', $escrito['agent']['permissionWindow']);
    }

    private function operacion(string $nombre): Operation
    {
        $catalogo = [new Operation(
            name: 'installs',
            description: 'the heaviest thing the criterion could let through',
            handler: static fn (): array => [],
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: \Milpa\Command\Effect\Externality::ThirdParty,
                reversibility: \Milpa\Command\Effect\Reversibility::Compensatable,
                authority: Authority::Privileged,
                subject: Subject::Executable,
            ),
        )];

        foreach ((new ConfigOperations($this->root, $catalogo))->operations() as $op) {
            if ($op->name === $nombre) {
                return $op;
            }
        }

        self::fail("no existe la operación «{$nombre}»");
    }
}
