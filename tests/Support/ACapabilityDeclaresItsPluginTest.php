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

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use PHPUnit\Framework\TestCase;

/**
 * A capability declares its plugin in its manifest, and the house wires it (greenhouse decisions/0241).
 *
 * `capabilities:enable milpa/admin --sign` used to install the package, report `ok`, and leave
 * `plugins_declared` empty — the panel never came up. The cause was a hand-written `match` with ONE
 * arm, in the very class whose docblock explains why a hand-written list was the defect ADR-0041
 * names. The manifest key is the sibling of `operations`, which this class already read generically.
 */
final class ACapabilityDeclaresItsPluginTest extends TestCase
{
    /** A declared plugin that exists and IS a plugin is accepted. */
    public function testAPluginTheManifestDeclaresIsRead(): void
    {
        $read = Capabilities::pluginsDeclaredBy(['id' => 'probe', 'plugins' => [ProbePlugin::class]]);

        self::assertSame([ProbePlugin::class], $read['plugins']);
        self::assertSame([], $read['refused']);
    }

    /** A leading backslash is the same class — a manifest is written by hand. */
    public function testTheDeclarationIsReadWithOrWithoutItsLeadingSlash(): void
    {
        $read = Capabilities::pluginsDeclaredBy(['plugins' => ['\\' . ProbePlugin::class]]);

        self::assertSame([ProbePlugin::class], $read['plugins']);
    }

    /**
     * A name that does not resolve is REFUSED AND NAMED — never silently dropped.
     *
     * A package that declares a plugin it did not ship is a package whose promise did not arrive, and
     * the house saying nothing would read as «this capability brings no door».
     */
    public function testAClassThatDoesNotExistIsRefusedByName(): void
    {
        $read = Capabilities::pluginsDeclaredBy(['plugins' => ['Acme\\Nope\\NotThere']]);

        self::assertSame([], $read['plugins']);
        self::assertArrayHasKey('Acme\\Nope\\NotThere', $read['refused']);
        self::assertStringContainsString('does not exist', $read['refused']['Acme\\Nope\\NotThere']);
    }

    /**
     * THE ONE THAT CAN SAY NO: a class that exists but is NOT a plugin is refused.
     *
     * Installing a capability is not authorising whatever it happens to ship (decisions/0240,
     * invariant 1: building grants no authority). If this passed, a manifest could name any class in
     * the tree and the house would write it into `config/plugins.php` for the app to boot.
     */
    public function testAClassThatIsNotAPluginIsRefused(): void
    {
        $read = Capabilities::pluginsDeclaredBy(['plugins' => [NotAPlugin::class]]);

        self::assertSame([], $read['plugins']);
        self::assertStringContainsString('is not a plugin', $read['refused'][NotAPlugin::class] ?? '');
    }

    /** A capability that declares nothing wires nothing, and that is not an error. */
    public function testACapabilityWithNoPluginsDeclaresNothing(): void
    {
        $read = Capabilities::pluginsDeclaredBy(['id' => 'plain']);

        self::assertSame([], $read['plugins']);
        self::assertSame([], $read['refused']);
    }

    /**
     * The host's own arm survives, and it is the whole list on purpose.
     *
     * `identity` is delivered by `milpa/auth` while the plugin that mounts its ceremony lives in
     * app-runtime, so no manifest of the delivering package could announce it.
     */
    public function testTheHostStillDeclaresTheOnePluginNoManifestCouldAnnounce(): void
    {
        self::assertNotSame([], Capabilities::pluginsUnlockedBy('identity'));
        self::assertSame([], Capabilities::pluginsUnlockedBy('admin'), 'everything else comes from the manifest');
    }

    /**
     * BOTH branches of the catalogue offer the governed door — the floor AND the derived index.
     *
     * The floor branch had a falsifier; the index branch did not, and a mutation that reverted it to
     * `composer require` stayed green. Found while proving the OTHER mutation, which is the whole
     * reason mutations are run.
     */
    public function testTheIndexBranchOffersTheGovernedDoorToo(): void
    {
        $vendor = sys_get_temp_dir() . '/milpa-vendor-' . bin2hex(random_bytes(4));
        mkdir($vendor . '/composer', 0o755, true);
        file_put_contents($vendor . '/composer/installed.json', json_encode(['packages' => []]));

        $state = Capabilities::state($vendor, [
            'derived_at' => '2026-09-09T00:00:00+00:00',
            'capabilities' => ['acme/thing' => ['id' => 'thing', 'title' => 'A third party', 'version' => 'v1.0.0']],
        ]);

        $row = null;
        foreach ($state['available'] as $candidate) {
            if (($candidate['package'] ?? null) === 'acme/thing') {
                $row = $candidate;
            }
        }

        self::assertNotNull($row, 'a package the index names is offered');
        self::assertSame('php bin/coa capabilities:enable acme/thing --sign', $row['command']);
        self::assertTrue($state['complete'], 'a derived index is not a floor');

        exec('rm -rf ' . escapeshellarg($vendor));
    }

    /** A house with no derived index SAYS it is showing a floor, and how to grow. */
    public function testAFloorSaysItIsAFloorAndHowToGrow(): void
    {
        $vendor = sys_get_temp_dir() . '/milpa-vendor-' . bin2hex(random_bytes(4));
        mkdir($vendor . '/composer', 0o755, true);
        file_put_contents($vendor . '/composer/installed.json', json_encode(['packages' => []]));

        $state = Capabilities::state($vendor, null);

        self::assertFalse($state['complete'], 'nothing was derived, so this is not the world');
        self::assertSame('php bin/coa capabilities:refresh', $state['grow'] ?? null);

        exec('rm -rf ' . escapeshellarg($vendor));
    }

    /** The class is written into config/plugins.php, once, and an already-named class is left alone. */
    public function testTheDeclaredPluginIsWrittenOnce(): void
    {
        $root = sys_get_temp_dir() . '/milpa-plugins-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o755, true);
        file_put_contents($root . '/config/plugins.php', "<?php\n\nreturn [\n];\n");

        $first = Capabilities::registerPlugins($root, [ProbePlugin::class]);
        $again = Capabilities::registerPlugins($root, [ProbePlugin::class]);

        self::assertSame([ProbePlugin::class], $first);
        self::assertSame([], $again, 'a class already named there is left alone');
        self::assertSame(1, substr_count((string) file_get_contents($root . '/config/plugins.php'), ProbePlugin::class));

        exec('rm -rf ' . escapeshellarg($root));
    }
}

/** A real plugin, for the arm that accepts. */
final class ProbePlugin implements PluginInterface
{
    public function __construct(DIContainerInterface $container)
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
}

/** Not a plugin, for the arm that refuses. */
final class NotAPlugin
{
}
