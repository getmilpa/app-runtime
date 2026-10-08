<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Fixtures;

use Milpa\AppRuntime\Tests\Console\ASeatSignsForABuiltVerbOnTheTerminalTest;

/**
 * A house with ONE capability built in its own tree, as a promotion would leave it — «Taller» — whose operations
 * answer each of the ways an operation can: for the exercise the house makes before it closes (greenhouse
 * decisions/0605). A child process of that house boots it: the package's autoloader, its `config/`, the plugin.
 */
final class ExercisedTaller
{
    /** The operations that answer or refuse: a capability made of these ran. */
    public const RUNS = ['taller:lista', 'taller:alta', 'taller:niega', 'taller:dar_de_baja', 'taller:cuenta'];

    /** Build it under a root that does not exist yet. */
    public static function in(string $root): void
    {
        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/var', 0o777, true);
        $class = 'E' . bin2hex(random_bytes(6));
        $dir = $root . '/src/Plugins/Taller';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/Taller.php', <<<PHP
            <?php
            namespace MilpaTest\\Exercised;

            use Milpa\\Command\\Effect\\EffectProfile;
            use Milpa\\Command\\Operation;

            if (!class_exists(Almacen::class, false)) {
                final class Almacen
                {
                }
            }

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
                    \$reads = EffectProfile::readOnly();
                    \$op = static fn (string \$name, \\Closure \$handler, ?array \$schema = null): Operation => new Operation(name: \$name, description: "Lo que hace {\$name}.", handler: \$handler, inputSchema: \$schema, surfaces: ['cli', 'tui', 'mcp'], effects: \$reads);

                    return [
                        \$op('taller:lista', static fn (array \$input): array => ['herramientas' => []]),
                        \$op('taller:alta', static function (array \$input): array {
                            return ['ok' => true, 'given' => \$input];
                        }, ['type' => 'object', 'properties' => ['nombre' => ['type' => 'string'], 'cantidad' => ['type' => 'integer'], 'tipo' => ['type' => 'string', 'enum' => ['manual', 'electrica']], 'activa' => ['type' => 'boolean'], 'nota' => ['type' => 'string']], 'required' => ['nombre', 'cantidad', 'tipo', 'activa']]),
                        \$op('taller:niega', static fn (array \$input): array => ['ok' => false, 'error' => 'Validation: falta el nombre']),
                        \$op('taller:rota', static fn (array \$input): array => ['ok' => (new Almacen())->guardar(\$input)]),
                        \$op('taller:lanza', static function (array \$input): array {
                            throw new \\DomainException('no hay herramienta «' . \$input['id'] . "»\\nen el almacén");
                        }, ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]),
                        \$op('taller:dar_de_baja', static fn (array \$input): array => ['ok' => true, 'ran' => 'taller:dar_de_baja']),
                        \$op('taller:cuenta', static fn (array \$input): int => 3),
                        \$op('taller:finge', static fn (array \$input): array => ['ok' => true, 'ran' => 'taller:finge', 'missing' => true, 'thrown' => ['class' => 'Error', 'engine' => true]]),
                        \$op('taller:segunda', static function (array \$input): array {
                            // What it keeps, it keeps where the process stands: in the copy, when it runs in one.
                            \$veces = is_file('var/segunda') ? (int) file_get_contents('var/segunda') : 0;
                            file_put_contents('var/segunda', (string) (\$veces + 1));

                            return ['ok' => true, 'veces' => \\count(array_merge([1], \$veces === 0 ? [] : \$veces))];
                        }),
                    ];
                }
            }
            PHP);
        // What a child process of the house loads: the package's own autoloader, and the capability.
        mkdir($root . '/vendor', 0o777, true);
        file_put_contents($root . '/vendor/autoload.php', '<?php require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; require_once ' . var_export(\dirname(__DIR__) . '/Console/ASeatSignsForABuiltVerbOnTheTerminalTest.php', true)
            . '; require_once ' . var_export($dir . '/Taller.php', true) . ';');
        file_put_contents($root . '/config/app.php', '<?php return [];');
        file_put_contents($root . '/config/boot.php', '<?php return ["container" => \\' . ASeatSignsForABuiltVerbOnTheTerminalTest::class . '::container(' . var_export($root, true) . '), "plugins" => [\\MilpaTest\\Exercised\\' . $class . '::class]];');
    }
}
