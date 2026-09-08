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

namespace Milpa\AppRuntime\Tests\Sequence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The governed door opens without the model gateway (greenhouse decisions/0225, F1 in-repo).
 *
 * A child process refuses to autoload any `Milpa\AiGateway\*` class and records every request for one;
 * then it opens the real door — kernel, session store, `GovernedDoor::open()`, `ConsentBridge`,
 * `SessionToolGate` — and calls a read-only operation through it. The control is the same child with the
 * refusal off; the instrument's own control is that the refusal really refuses.
 */
final class TheDoorOpensWithoutTheModelGatewayTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-door-no-gw-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents($this->root . '/config/operations.php', "<?php\n\nreturn [\\DoorProbe\\ReadOnlyProvider::class];\n");
        file_put_contents($this->root . '/door.php', self::childScript($this->root));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_door_opens_and_runs_a_read_with_the_model_gateway_refused(): void
    {
        $said = $this->child(block: true);

        self::assertStringContainsString('GATEWAY PRESENT: no', $said, 'the instrument: the gateway is really refused');
        self::assertStringContainsString('RAN: {"read":"ok"}', $said, 'the door executed the read through gate, bridge and registry');
        self::assertStringContainsString('AIGATEWAY REQUESTED: none', $said, 'no class of the model gateway was ever asked for on the human path');
    }

    #[Test]
    public function the_control_runs_the_same_door_with_the_gateway_available(): void
    {
        $said = $this->child(block: false);

        self::assertStringContainsString('GATEWAY PRESENT: yes', $said);
        self::assertStringContainsString('RAN: {"read":"ok"}', $said);
    }

    private function child(bool $block): string
    {
        $output = [];
        $exit = 0;
        exec(($block ? 'DOOR_BLOCK_GATEWAY=1 ' : '') . escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($this->root . '/door.php') . ' 2>&1', $output, $exit);
        $said = implode("\n", $output);
        self::assertSame(0, $exit, $said);

        return $said;
    }

    /** The child: refuse the gateway (when told to), open the real door, call a read, report what was requested. */
    private static function childScript(string $root): string
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        return <<<PHP
<?php
declare(strict_types=1);
namespace DoorProbe;

require '{$autoload}';

\$requested = [];
if (getenv('DOOR_BLOCK_GATEWAY') === '1') {
    foreach (spl_autoload_functions() as \$loader) {
        spl_autoload_unregister(\$loader);
        spl_autoload_register(static function (string \$class) use (\$loader, &\$requested): void {
            if (str_starts_with(\$class, 'Milpa\\\\AiGateway\\\\')) {
                \$requested[] = \$class;
                return;
            }
            \$loader(\$class);
        });
    }
}
echo 'GATEWAY PRESENT: ' . (class_exists(\\Milpa\\AiGateway\\McpClientService::class) ? 'yes' : 'no') . "\\n";
// The presence check above asked for a gateway class itself: from here on, only the door's own requests count.
\$requested = [];

final class ReadOnlyProvider implements \\Milpa\\Command\\CommandProvider
{
    public function __construct(\\Milpa\\Interfaces\\Di\\DIContainerInterface \$container) {}
    public function operations(): array
    {
        return [new \\Milpa\\Command\\Operation(
            name: 'probe:read',
            description: 'A read the door lets through',
            handler: static fn (array \$input): array => ['read' => 'ok'],
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
            effects: \\Milpa\\Command\\Effect\\EffectProfile::readOnly(),
        )];
    }
}

\$container = new \\Milpa\\Container\\DIContainer();
\$kernel = \\Milpa\\Runtime\\Kernel::boot(['root' => '{$root}', 'container' => \$container, 'toolRegistry' => new \\Milpa\\ToolRuntime\\ToolRegistry(new \\Psr\\Log\\NullLogger()), 'plugins' => [], 'config' => []]);
\$container->registerService(\\Milpa\\Runtime\\Kernel::class, \$kernel);
\$store = new \\Milpa\\Agent\\SessionStore(new \\Milpa\\EventStore\\FileEventStore('{$root}/ledger.jsonl'));
\$store->start('recipe:probe', 'read through the door', \\Milpa\\Agent\\AutonomyMode::Ask);
\$session = \$store->load('recipe:probe');
\$door = \\Milpa\\AppRuntime\\Sequence\\GovernedDoor::open(\$kernel, '{$root}', \$store, \$session, 'read through the door');
\$result = \$door->callTool(\\Milpa\\Console\\McpProjector::toolName('probe:read'), []);
echo 'RAN: ' . json_encode(\$result) . "\\n";
echo 'AIGATEWAY REQUESTED: ' . (\$requested === [] ? 'none' : implode(', ', array_unique(\$requested))) . "\\n";
PHP;
    }
}
