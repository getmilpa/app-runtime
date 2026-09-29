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

namespace Milpa\AppRuntime\Tests\Fixtures;

/**
 * A real house on disk that a fresh process can boot: `config/boot.php`, `config/plugins.php`, one plugin
 * under `src/Plugins/`, and a `vendor/autoload.php` that loads this package's own dependencies plus `App\`.
 *
 * It exists for the tests of greenhouse decisions/0506, which are about what happens when a house STOPS
 * booting: the only honest witness of «does not boot» is a process that tried.
 */
final class TinyHouse
{
    /** Make a house that boots, with the plugins named (each one a well-formed plugin). */
    public static function create(string ...$plugins): string
    {
        $root = sys_get_temp_dir() . '/milpa-tiny-house-' . bin2hex(random_bytes(4));
        foreach (['vendor', 'config', 'public', 'var', 'storage'] as $dir) {
            mkdir($root . '/' . $dir, 0o777, true);
        }
        file_put_contents($root . '/vendor/autoload.php', '<?php
$loader = require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . "/../src/" . str_replace("\\\\", "/", substr($class, 4)) . ".php";
    if (str_starts_with($class, "App\\\\") && is_file($file)) {
        require $file;
    }
});
return $loader;
');
        // Where the house lives, and which plugins are switched on — the two lines the skeleton's `config/boot.php` carries.
        file_put_contents($root . '/config/boot.php', '<?php $c = new \Milpa\Container\DIContainer(); $c->registerService(\Milpa\Plugin\Contracts\AppRoot::class, new \Milpa\Plugin\Contracts\AppRoot(dirname(__DIR__))); return ["container" => $c, "plugins" => \Milpa\Plugin\Activation\ActivePlugins::wire($c, require __DIR__ . "/plugins.php", dirname(__DIR__) . "/storage/plugins.json")];');
        file_put_contents($root . '/config/app.php', '<?php return [];');
        foreach ($plugins as $name) {
            self::plugin($root, $name);
        }
        self::register($root, ...$plugins);

        return $root;
    }

    /** The plugin list the boot reads, rewritten. */
    public static function register(string $root, string ...$plugins): void
    {
        file_put_contents($root . '/config/plugins.php', self::pluginsFile(...$plugins));
    }

    /** What `config/plugins.php` holds for these plugins. */
    public static function pluginsFile(string ...$plugins): string
    {
        return '<?php return [' . implode(', ', array_map(static fn (string $p): string => "App\\Plugins\\{$p}\\{$p}::class", $plugins)) . "];\n";
    }

    /** Write a plugin that boots. */
    public static function plugin(string $root, string $name): void
    {
        @mkdir($root . "/src/Plugins/{$name}", 0o777, true);
        file_put_contents($root . "/src/Plugins/{$name}/{$name}.php", self::pluginSource($name));
    }

    /** A well-formed plugin — or, with `$broken`, one whose class misses `install()`: a compile fatal nobody can catch (evidence/1038, n5). */
    public static function pluginSource(string $name, bool $broken = false): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\\' . $name . ';
#[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "' . $name . '", type: "Service")]
final class ' . $name . ' implements \Milpa\Interfaces\Plugin\PluginInterface
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
    public function boot(): void {}
' . ($broken ? '' : "    public function install(): void {}\n") . '    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}
}
';
    }

    /** Remove a house made here. */
    public static function remove(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($root);
    }
}
