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
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * A value written through config:set must conform to the type AgentKeys declares for that key.
 *
 * The signed CLI path necessarily carries values as text. The write boundary is therefore where the
 * key's declaration must turn that transport spelling into the declared value, or reject it without
 * touching the file. This is the regression measured in greenhouse evidence/0235.
 */
final class ConfigSetDeclaredTypeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/config-types-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.milpa', 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . MachineOverlay::RUTA);
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    public function testItWritesAnIntegerAsTheDeclaredInteger(): void
    {
        $result = $this->write('agent.treeBudget', '250');

        self::assertTrue($result['ok']);
        self::assertSame(250, $this->writtenAgent()['treeBudget']);
    }

    public function testItWritesFalseAsTheDeclaredBooleanFalse(): void
    {
        $result = $this->write('agent.reprojectPlan', 'false');

        self::assertTrue($result['ok']);
        self::assertFalse($this->writtenAgent()['reprojectPlan']);
    }

    public function testItWritesAnObjectAsTheDeclaredArrayShape(): void
    {
        $result = $this->write('agent.compaction', '{"maxTurns":10,"keepLast":4}');

        self::assertTrue($result['ok']);
        self::assertSame(['maxTurns' => 10, 'keepLast' => 4], $this->writtenAgent()['compaction']);
    }

    public function testItWritesAListAsTheDeclaredStringList(): void
    {
        $result = $this->write('agent.transitions.foundation', '["foundation.exists","manifest.valid"]');

        self::assertTrue($result['ok']);
        self::assertSame(['foundation.exists', 'manifest.valid'], $this->writtenAgent()['transitions']['foundation']);
    }

    public function testItRejectsAnObjectWhenTheDeclarationRequiresABoolean(): void
    {
        $result = $this->write('agent.reprojectPlan', '{"maxTurns":10}');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('agent.reprojectPlan', $result['error']);
        self::assertStringContainsString('bool', $result['error']);
        self::assertFileDoesNotExist($this->root . MachineOverlay::RUTA);
    }

    public function testItPreservesAnEmptyDeclaredObjectAsAJsonObject(): void
    {
        $result = $this->write('agent.compaction', '{}');

        self::assertTrue($result['ok']);
        self::assertTrue($this->write('agent.instructions', 'leave the empty object alone')['ok']);
        $written = json_decode(
            (string) file_get_contents($this->root . MachineOverlay::RUTA),
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(\stdClass::class, $written->agent->compaction);
    }

    public function testItRejectsAJsonListWhenTheDeclarationRequiresAnObjectShape(): void
    {
        $result = $this->write('agent.compaction', '[]');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('array{maxTurns?: int, keepLast?: int}', $result['error']);
        self::assertFileDoesNotExist($this->root . MachineOverlay::RUTA);
    }

    public function testItRejectsANativeEmptyListWhenTheDeclarationRequiresAnObjectShape(): void
    {
        $result = $this->write('agent.compaction', []);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('array{maxTurns?: int, keepLast?: int}', $result['error']);
        self::assertFileDoesNotExist($this->root . MachineOverlay::RUTA);
    }

    public function testItRejectsAValueThatCannotBePersistedAsJson(): void
    {
        $before = "{\n    \"agent\": {\n        \"instructions\": \"before\"\n    }\n}\n";
        file_put_contents($this->root . MachineOverlay::RUTA, $before);

        $result = $this->write('agent.instructions', "invalid UTF-8: \xB1\x31");

        self::assertFalse($result['ok']);
        self::assertStringContainsString('JSON', $result['error']);
        self::assertSame($before, file_get_contents($this->root . MachineOverlay::RUTA));
    }

    public function testItDoesNotSilentlyMigrateAPreviouslyWrittenString(): void
    {
        file_put_contents(
            $this->root . MachineOverlay::RUTA,
            "{\n    \"agent\": {\n        \"reprojectPlan\": \"false\"\n    }\n}\n",
        );

        $result = $this->write('agent.instructions', 'keep existing values untouched');

        self::assertTrue($result['ok']);
        self::assertSame('false', $this->writtenAgent()['reprojectPlan']);
    }

    /** @return array<string, mixed> */
    private function write(string $key, mixed $value): array
    {
        return ($this->operation()->handler)(['key' => $key, 'value' => $value]);
    }

    /** @return array<string, mixed> */
    private function writtenAgent(): array
    {
        /** @var array{agent?: array<string, mixed>} $written */
        $written = json_decode(
            (string) file_get_contents($this->root . MachineOverlay::RUTA),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $written['agent'] ?? [];
    }

    private function operation(): Operation
    {
        foreach (ConfigOperations::para($this->root)->operations() as $operation) {
            if ($operation->name === 'config:set') {
                return $operation;
            }
        }

        self::fail('config:set is not registered');
    }
}
