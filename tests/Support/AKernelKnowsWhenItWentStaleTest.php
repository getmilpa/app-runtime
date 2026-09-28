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

use Milpa\AppRuntime\Support\KernelDefinition;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;

/**
 * A process that outlives one request knows when the kernel it booted went stale (greenhouse decisions/0505).
 *
 * Measured in evidence/1035: a FrankenPHP worker kept serving 404 for a plugin installed through it, and
 * ran the next turn without the model written through it. The definition is DERIVED from what the
 * process read — the overlays and the app's included PHP — so no writer has to remember to announce it.
 */
final class AKernelKnowsWhenItWentStaleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kernel-definition-' . bin2hex(random_bytes(4));
        foreach (['storage', '.milpa/sessions', 'vendor/composer', 'vendor/acme/lib', 'config', 'src/Plugins/Blog'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o777, true);
        }
        file_put_contents($this->root . '/storage/plugins.json', '{"Blog":true}');
        file_put_contents($this->root . '/.milpa/agent.json', '{"agent":{"model":"qwen3.8-27b"}}');
        file_put_contents($this->root . '/vendor/composer/installed.php', "<?php return ['versions' => ['milpa/app-runtime' => '0.194.0']];");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** Each overlay the boot reads, rewritten with other content, makes the kernel stale — named by its path. */
    public function testEachOverlayThatChangesMakesItStale(): void
    {
        $cases = [
            'storage/plugins.json' => '{"Blog":false}',
            '.milpa/agent.json' => '{"agent":{"model":"qwen3.8-27c"}}',
            'vendor/composer/installed.php' => "<?php return ['versions' => ['milpa/app-runtime' => '0.195.0']];",
        ];
        foreach ($cases as $path => $content) {
            $definition = KernelDefinition::before($this->root);
            self::assertNull($definition->staleBecause(), 'nothing changed yet');

            $before = (string) file_get_contents($this->root . '/' . $path);
            file_put_contents($this->root . '/' . $path, $content);
            self::assertSame($path, $definition->staleBecause(), "a changed {$path} makes the kernel stale");
            file_put_contents($this->root . '/' . $path, $before);
        }
    }

    /**
     * The SAME size in the SAME second still counts: the fingerprint is the content, not a clock.
     *
     * `qwen3.8-27b` → `qwen3.8-27c` is one byte of difference and no size difference; with `filemtime`
     * (seconds) and size, a write inside the boot's second would read as unchanged.
     */
    public function testASameSizeWriteInTheSameSecondIsStillAChange(): void
    {
        $file = $this->root . '/.milpa/agent.json';
        touch($file, 1_700_000_000);
        $definition = KernelDefinition::before($this->root);

        file_put_contents($file, '{"agent":{"model":"qwen3.8-27c"}}');
        touch($file, 1_700_000_000);
        clearstatcache();

        self::assertSame('.milpa/agent.json', $definition->staleBecause());
    }

    /** An identical rewrite — enabling a plugin that is already on — recycles nothing. */
    public function testAnIdenticalRewriteIsNotAChange(): void
    {
        $definition = KernelDefinition::before($this->root);
        file_put_contents($this->root . '/storage/plugins.json', '{"Blog":true}');
        touch($this->root . '/storage/plugins.json', time() + 5);

        self::assertNull($definition->staleBecause());
    }

    /** A secret file that appears after the boot (the wizard's first write) is a change; so is one that goes away. */
    public function testAnOverlayThatAppearsOrDisappearsIsAChange(): void
    {
        $definition = KernelDefinition::before($this->root);
        file_put_contents($this->root . '/.milpa/secrets.json', '{"agent":{"apiKey":"x"}}');
        self::assertSame('.milpa/secrets.json', $definition->staleBecause());

        $again = KernelDefinition::before($this->root);
        unlink($this->root . '/.milpa/secrets.json');
        self::assertSame('.milpa/secrets.json', $again->staleBecause());
    }

    /**
     * The app's PHP the process INCLUDED is part of the definition — at boot or later — and edited, it is stale.
     *
     * This is the promotion: `sandbox:promote` rewrites a controller the worker already loaded, and PHP
     * never redefines a loaded class.
     */
    public function testAnIncludedAppFileThatChangesMakesItStale(): void
    {
        $definition = KernelDefinition::before($this->root);
        $plugin = $this->root . '/src/Plugins/Blog/BlogProbe' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($plugin, "<?php return 'first';");
        self::assertSame('first', require $plugin);

        self::assertNull($definition->staleBecause(), 'included and unchanged');
        self::assertContains(substr((string) realpath($plugin), \strlen((string) realpath($this->root)) + 1), $definition->inputs());

        file_put_contents($plugin, "<?php return 'other';");
        self::assertStringStartsWith('src/Plugins/Blog/BlogProbe', (string) $definition->staleBecause());
    }

    /** What the process never included and is not an overlay does not define it — a session written each turn above all. */
    public function testWhatTheKernelDidNotReadIsNotTracked(): void
    {
        $definition = KernelDefinition::before($this->root);
        $vendorFile = $this->root . '/vendor/acme/lib/Thing' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($vendorFile, "<?php return 1;");
        require $vendorFile;

        file_put_contents($this->root . '/.milpa/sessions/s1.jsonl', "{\"seq\":1}\n");
        file_put_contents($this->root . '/src/Plugins/Blog/NeverLoaded.php', '<?php // not included');
        file_put_contents($vendorFile, "<?php return 2;");

        self::assertNull($definition->staleBecause(), 'a session, an unloaded file and a vendor file do not define the kernel');
        self::assertNotContains('.milpa/sessions/s1.jsonl', $definition->inputs());
        foreach ($definition->inputs() as $input) {
            self::assertFalse(str_starts_with($input, 'vendor/acme/'), 'vendor files are covered by installed.php, not one by one');
        }
    }

    /** Once stale, it stays stale — even if the file is put back — because the process already holds what it read. */
    public function testOnceStaleItStaysStale(): void
    {
        $definition = KernelDefinition::before($this->root);
        file_put_contents($this->root . '/storage/plugins.json', '{"Blog":false}');
        self::assertSame('storage/plugins.json', $definition->staleBecause());

        file_put_contents($this->root . '/storage/plugins.json', '{"Blog":true}');
        self::assertSame('storage/plugins.json', $definition->staleBecause());
    }

    /** The answer a stale process gives: 307 to the same path and query, never cached, never off this host. */
    public function testTheRetryGoesBackToTheSamePlace(): void
    {
        $factory = new Psr17Factory();

        $retry = KernelDefinition::retryHere(new ServerRequest('POST', 'http://localhost:18754/workspace/turn?session=s1'), $factory);
        self::assertSame(307, $retry->getStatusCode());
        self::assertSame('/workspace/turn?session=s1', $retry->getHeaderLine('Location'));
        self::assertSame('no-store', $retry->getHeaderLine('Cache-Control'));

        // Nyholm already collapses `//host` in a path; another PSR-7 implementation need not, so the path is stubbed.
        $uri = $this->createConfiguredStub(UriInterface::class, ['getPath' => '//evil.example/x', 'getQuery' => '']);
        $hostile = KernelDefinition::retryHere((new ServerRequest('GET', '/'))->withUri($uri, true), $factory);
        self::assertSame('/evil.example/x', $hostile->getHeaderLine('Location'), 'a path of //host is never a protocol-relative redirect');
    }
}
