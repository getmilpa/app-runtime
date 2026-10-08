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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Console\CommandName;
use Milpa\AppRuntime\Console\UnsignedTerminal;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A verb is called on the terminal by the name it declares (greenhouse evidence/1159).
 *
 * A resident extended a capability with a verb it named `herramienta:dar_baja`. The agent's catalogue offered it;
 * the seat's card and the admission called it by that name. The terminal listed it as `herramienta:dar:baja`, and to
 * the name it declares answered «no such command»: the terminal wrote every underscore and every dot of a name as a
 * colon. That was written for the names that have nothing else to separate their two parts —`token.list`— and it
 * also rewrote the inside of a verb.
 *
 * So an underscore is a separator only where nothing else is one. Everything here runs through the shipped
 * terminal, over a capability in a house's own tree.
 *
 * @guards a verb with an underscore, named with a colon or with a dot, being found by the name it declares; the
 *         list and the line that runs a call signed saying that same name
 *
 * @refuses the name the terminal used to invent for it; any change to a name that has no underscore in its verb, or
 *          that is separated by nothing but underscores
 *
 * @subject-in milpa/app-runtime
 */
final class AVerbIsCalledOnTheTerminalByTheNameItDeclaresTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-terminal-name-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        $this->build(['herramientas:dar_baja', 'herramientas.dar_alta', 'herramientas.listar', 'taller_inventario']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAVerbWithAnUnderscoreIsCalledByTheNameItDeclares(): void
    {
        [$exit, $output] = $this->run_(['herramientas:dar_baja', '--json']);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('"ran":"herramientas:dar_baja"', $output);
    }

    public function testTheNameTheTerminalUsedToInventForItIsNoCommand(): void
    {
        [$exit, $output] = $this->run_(['herramientas:dar:baja', '--json']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('no such command «herramientas:dar:baja»', $output);
    }

    /** A plugin may write its names with a dot: the dot becomes the colon, and the verb stays as it was written. */
    public function testAVerbNamedWithADotKeepsItsUnderscoreToo(): void
    {
        [$exit, $output] = $this->run_(['herramientas:dar_alta', '--json']);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('"ran":"herramientas.dar_alta"', $output);
        self::assertNotSame(0, $this->run_(['herramientas:dar:alta', '--json'])[0]);
    }

    public function testTheListSaysTheNameThatWorks(): void
    {
        [, $output] = $this->run_([]);

        self::assertMatchesRegularExpression('/^\s+herramientas:dar_baja\s/m', $output);
        self::assertMatchesRegularExpression('/^\s+herramientas:dar_alta\s/m', $output);
        self::assertStringNotContainsString('herramientas:dar:', $output);
    }

    /** What had no underscore inside its verb, or nothing but underscores to separate it, is called as before. */
    public function testEveryOtherNameIsCalledAsBefore(): void
    {
        [$dotted, $said] = $this->run_(['herramientas:listar', '--json']);
        self::assertSame(0, $dotted, $said);
        self::assertStringContainsString('"ran":"herramientas.listar"', $said);

        [$underscored, $said] = $this->run_(['taller:inventario', '--json']);
        self::assertSame(0, $underscored, $said);
        self::assertStringContainsString('"ran":"taller_inventario"', $said);
        self::assertNotSame(0, $this->run_(['taller_inventario', '--json'])[0], 'the name with its underscore was never the command');
    }

    /** The line the house writes for a person to run a call signed names the command that exists. */
    public function testTheLineThatRunsACallSignedSaysThatName(): void
    {
        $line = UnsignedTerminal::signedLine(new Operation('herramientas:dar_baja', 'Dar de baja.', static fn (): array => [], effects: EffectProfile::readOnly()), ['herramienta_id' => 3]);

        self::assertStringContainsString(' herramientas:dar_baja --herramienta-id=', $line);
        self::assertStringNotContainsString('dar:baja', $line);
    }

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'a colon, and an underscore inside the verb' => ['herramienta:dar_baja', 'herramienta:dar_baja'];
        yield 'a dot, and an underscore inside the verb' => ['herramienta.dar_baja', 'herramienta:dar_baja'];
        yield 'a colon, and an underscore inside the domain' => ['caja_chica:abrir', 'caja_chica:abrir'];
        yield 'a dot alone' => ['token.list', 'token:list'];
        yield 'dots alone' => ['plugins.config.set', 'plugins:config:set'];
        yield 'underscores alone' => ['sandbox_promote', 'sandbox:promote'];
        yield 'several underscores alone' => ['agent_role_list', 'agent:role:list'];
        yield 'colons alone' => ['agent:role:list', 'agent:role:list'];
        yield 'a colon and a dot' => ['agent:role.list', 'agent:role:list'];
        yield 'one word' => ['doctor', 'doctor'];
    }

    #[DataProvider('names')]
    public function testTheCommandOfAName(string $declared, string $command): void
    {
        self::assertSame($command, CommandName::of($declared));
    }

    /**
     * Put a capability with read-only verbs of those names in the house's own tree, as a promotion would.
     *
     * @param list<string> $names
     */
    private function build(array $names): void
    {
        $class = 'N' . bin2hex(random_bytes(6));
        $dir = $this->root . '/src/Plugins/Taller';
        mkdir($dir, 0o777, true);
        $operations = '';
        foreach ($names as $name) {
            $operations .= "                        new Operation(name: '{$name}', description: 'Lo que hace {$name}.', handler: static fn (array \$input): array => ['ok' => true, 'ran' => '{$name}'], surfaces: ['cli', 'tui', 'mcp'], effects: EffectProfile::readOnly()),\n";
        }
        file_put_contents($dir . '/Taller.php', <<<PHP
            <?php
            namespace MilpaTest\\Named;

            use Milpa\\Command\\Effect\\EffectProfile;
            use Milpa\\Command\\Operation;

            #[\\Milpa\\Attributes\\PluginMetadata(version: '0.1.0', author: 'lab', site: 'https://example.test', name: 'Taller', type: 'Service')]
            final class {$class} implements \\Milpa\\Interfaces\\Plugin\\PluginInterface, \\Milpa\\Command\\CommandProvider
            {
                public function __construct(private readonly \\Milpa\\Interfaces\\Di\\DIContainerInterface \$container)
                {
                }
                public function boot(): void
                {
                }
                public function install(): void
                {
                }
                public function uninstall(): void
                {
                }
                public function enable(): void
                {
                }
                public function disable(): void
                {
                }
                public function operations(): array
                {
                    return [
            {$operations}
                    ];
                }
            }
            PHP);
        require_once $dir . '/Taller.php';
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => \\' . ASeatSignsForABuiltVerbOnTheTerminalTest::class . '::container(' . var_export($this->root, true) . '), "plugins" => [\\MilpaTest\\Named\\' . $class . '::class]];');
    }

    /**
     * Run the shipped terminal as its operator.
     *
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function run_(array $arguments): array
    {
        $token = getenv('MILPA_TOKEN');
        putenv('MILPA_TOKEN');
        ob_start();
        try {
            $exit = (new Application($this->root))->run(['coa', ...$arguments]);

            return [$exit, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
            $token === false ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $token);
        }
    }
}
