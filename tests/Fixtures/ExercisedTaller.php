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

use Milpa\Agent\SessionStore;
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

    /**
     * The promotion that declared the capability, as the house records it in a session's stream: what landed, and what
     * the capability built there declares — whole. Its seq. A session whose goal names «Taller» is about to close on it.
     *
     * @param list<string> $operations the operations the promotion says it declares, by name
     * @param bool         $verified   whether the writer says its own verification of the class it wrote, green — so
     *                                 the session's record holds a current verification for it
     */
    public static function promoted(SessionStore $sessions, string $session, array $operations, bool $verified = false): int
    {
        $sessions->recordToolCall($session, 'implement', ['plugin' => 'Taller', 'class' => 'Taller'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'changed' => ['src/Plugins/Taller/Taller.php' => 'modified'], 'output' => ['ok' => true],
        ] + ($verified ? ['ok' => true, 'verified' => 'its own test, green'] : [])), mutating: true);

        return $sessions->recordToolCall($session, 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode([
            'ok' => true,
            'promoted' => ['src/Plugins/Taller/Taller.php'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w1', 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => 'w1'], 'paths' => ['src/Plugins/Taller/Taller.php']],
            'capabilities' => [['predicate' => 'declared', 'subject' => 'Taller', 'environment' => ['kind' => 'house'], 'operations' => array_map(
                static fn (string $name): array => ['name' => $name, 'file' => 'src/Plugins/Taller/Taller.php', 'mutating' => false, 'effects' => true, 'scoped' => true],
                $operations,
            )]],
        ]), mutating: true);
    }

    /**
     * A change that lands OUTSIDE the capability and declares nothing again: a class of the house's own, written in a
     * trial with its own verification and promoted. It repairs nothing of the capability. Its seq.
     */
    public static function landedElsewhere(SessionStore $sessions, string $session): int
    {
        $changed = ['src/Support/Clock.php' => 'modified'];
        $sessions->recordToolCall($session, 'edit', ['class' => 'Clock'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w2', 'changed' => $changed, 'output' => ['ok' => true], 'ok' => true, 'verified' => 'its own test, green',
        ]), mutating: true);

        return $sessions->recordToolCall($session, 'sandbox_promote', ['workspace' => 'w2'], (string) json_encode([
            'ok' => true,
            'promoted' => array_keys($changed),
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w2', 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => 'w2'], 'paths' => array_keys($changed)],
        ]), mutating: true);
    }

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
