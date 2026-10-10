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
use PHPUnit\Framework\TestCase;

/**
 * A name that is no command is answered with the ones near it (greenhouse evidence/1187).
 *
 * In a newly created app `coa plugins` and `coa routes` — the group, without its verb: what a person types to see
 * what the group has — were answered like a made-up word: «no such command», and the whole help under it. So was a
 * slip of a finger. The lines that mattered had to be found among all of them.
 *
 * Everything here runs through the shipped terminal, over a capability in a house's own tree.
 *
 * @guards a group without its verb, the beginning of a name and a name a slip or two away being answered with the
 *         commands they may have meant, and with a failing status
 *
 * @refuses the whole help under a name that resembles a command; a suggestion for a name that resembles none; any
 *          change to what a program that asked with `--json` is answered, or to the help itself
 *
 * @subject-in milpa/app-runtime
 */
final class ANameThatIsNoCommandIsAnsweredWithTheOnesNearItTest extends TestCase
{
    private const HELP = 'coa — the runtime of this app';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-terminal-near-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        $this->build(['herramientas:dar_baja', 'herramientas.dar_alta', 'herramientas.listar', 'taller', 'taller_inventario', 'almacen']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAGroupWithoutItsVerbIsAnsweredWithWhatTheGroupHas(): void
    {
        [$exit, $output] = $this->run_(['herramientas']);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('✗ no such command «herramientas»', $output);
        self::assertStringContainsString('«herramientas» is a group of commands', $output);
        self::assertSame(['herramientas:dar_alta', 'herramientas:dar_baja', 'herramientas:listar'], self::named($output));
        self::assertStringNotContainsString(self::HELP, $output, 'not the whole help');
        self::assertStringContainsString('`coa` alone lists every command', $output, 'and where the whole of it is');
    }

    /** `coa herramientas:` — the colon typed, the verb not yet. */
    public function testAGroupWithItsColonAndNoVerbIsThatGroup(): void
    {
        [$exit, $output] = $this->run_(['herramientas:']);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('«herramientas» is a group of commands', $output);
        self::assertSame(['herramientas:dar_alta', 'herramientas:dar_baja', 'herramientas:listar'], self::named($output));
    }

    public function testTheBeginningOfANameIsAnsweredWithTheNamesThatBeginSo(): void
    {
        [$exit, $output] = $this->run_(['herr']);
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('these begin with «herr»', $output);
        self::assertSame(['herramientas:dar_alta', 'herramientas:dar_baja', 'herramientas:listar'], self::named($output));

        self::assertSame(['taller', 'taller:inventario'], self::named($this->run_(['tall'])[1]));
        self::assertSame(['herramientas:dar_alta', 'herramientas:dar_baja'], self::named($this->run_(['herramientas:dar'])[1]));
    }

    public function testAMistypedNameIsAnsweredWithTheOneWithinReach(): void
    {
        [$exit, $output] = $this->run_(['herramientas:lisstar']);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('did you mean', $output);
        self::assertSame(['herramientas:listar'], self::named($output), 'the one near it, not its group');
        self::assertStringNotContainsString(self::HELP, $output);
        self::assertSame(['herramientas:listar'], self::named($this->run_(['HERRAMIENTAS:LISTAR'])[1]), 'capitals are no slip');
    }

    public function testAMistypedGroupIsAnsweredWithWhatTheGroupHas(): void
    {
        [$exit, $output] = $this->run_(['heramientas']);

        self::assertSame(1, $exit, $output);
        self::assertSame(['herramientas:dar_alta', 'herramientas:dar_baja', 'herramientas:listar'], self::named($output));
        self::assertStringNotContainsString(self::HELP, $output);
    }

    /** A word may be a command and a group at once: mistyped, it may have meant either. */
    public function testAMistypedWordThatIsACommandAndAGroupIsAnsweredWithBoth(): void
    {
        self::assertSame(['taller', 'taller:inventario'], self::named($this->run_(['taler'])[1]));
    }

    /** `shell` is no operation, and the help announces it: a name near it is answered with it too. */
    public function testWhatTheHelpAnnouncesThatIsNoOperationIsNearToo(): void
    {
        [$exit, $output] = $this->run_(['shll']);

        self::assertSame(1, $exit, $output);
        self::assertSame(['shell'], self::named($output));
    }

    /** Showing what DOES exist is the answer to a name that resembles nothing — as it was. */
    public function testANameThatResemblesNothingIsAnsweredWithTheWholeHelp(): void
    {
        [, $help] = $this->run_([]);

        foreach (['frobnicate', '--frobnicate', ':', ''] as $name) {
            [$exit, $output] = $this->run_([$name]);

            self::assertSame(1, $exit, $output);
            self::assertSame("✗ no such command «{$name}»\n\n" . $help, $output);
        }
    }

    /** «Near» stops: two slips in a short name, three in a long one, are another word. */
    public function testANameTooFarFromEveryCommandIsNotGuessedAt(): void
    {
        [, $help] = $this->run_([]);

        foreach (['almcn', 'herramientas:lxyzar'] as $name) {
            [$exit, $output] = $this->run_([$name]);

            self::assertSame(1, $exit, $output);
            self::assertSame("✗ no such command «{$name}»\n\n" . $help, $output, $name);
        }
        self::assertSame(['almacen'], self::named($this->run_(['almcen'])[1]), 'one slip in a short name is near');
        self::assertSame(['herramientas:listar'], self::named($this->run_(['herramientas:lxytar'])[1]), 'two in a long one are');
    }

    /** A program asked: its answer is the one it was, whatever the name resembles. */
    public function testAProgramThatAskedWithJsonIsAnsweredAsItWas(): void
    {
        [, $help] = $this->run_([]);

        foreach (['herramientas', 'heramientas', 'frobnicate'] as $name) {
            [$exit, $output] = $this->run_([$name, '--json']);

            self::assertSame(1, $exit, $output);
            self::assertSame("✗ no such command «{$name}»\n\n" . $help, $output, $name);
        }
    }

    /**
     * The commands an answer names, in the order it names them: the rows under its sentence.
     *
     * @return list<string>
     */
    private static function named(string $output): array
    {
        preg_match_all('/^  (\S+) {2,}\S/m', $output, $rows);

        return $rows[1];
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
            namespace MilpaTest\\Near;

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
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => \\' . ASeatSignsForABuiltVerbOnTheTerminalTest::class . '::container(' . var_export($this->root, true) . '), "plugins" => [\\MilpaTest\\Near\\' . $class . '::class]];');
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
