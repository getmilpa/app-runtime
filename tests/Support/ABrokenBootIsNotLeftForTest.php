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

use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Support\KernelDefinition;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use PHPUnit\Framework\TestCase;

/**
 * A stale process asks whether the NEW definition boots before it leaves (greenhouse decisions/0506).
 *
 * Measured in evidence/1038 (n5): a promotion that broke the boot sent every FrankenPHP worker out of its
 * loop and into a crash loop — requests waited with no answer. The answer can only come from a process
 * that tried to boot, so these tests boot real houses on disk in real child processes.
 *
 * @guards a house that boots reads as booting; a compile fatal, a throwing boot and a boot that hangs
 *         each read as not booting, with the reason in one line and without the root in it
 *
 * @refuses probing the same content twice — a held worker checks every request, and must not boot the
 *          house on every request
 *
 * @subject-in milpa/app-runtime
 */
final class ABrokenBootIsNotLeftForTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    public function testAHouseThatBootsReadsAsBooting(): void
    {
        self::assertNull((new BootProbe())->whyNot($this->root));
    }

    /** The shape of evidence/1038 n5: a registered plugin whose class misses an interface method. */
    public function testARegisteredPluginMissingAnInterfaceMethodDoesNotBoot(): void
    {
        TinyHouse::plugin($this->root, 'Roto');
        file_put_contents($this->root . '/src/Plugins/Roto/Roto.php', TinyHouse::pluginSource('Roto', broken: true));
        TinyHouse::register($this->root, 'Blog', 'Roto');

        $why = (new BootProbe())->whyNot($this->root);

        self::assertNotNull($why);
        self::assertStringStartsWith('Fatal error: Class App\Plugins\Roto\Roto contains 1 abstract method', $why);
        self::assertStringNotContainsString($this->root, $why, 'the reason travels in a header and a log: the root stays home');
        self::assertStringNotContainsString("\n", $why);
    }

    public function testABootThatThrowsIsNamedByWhatItThrew(): void
    {
        file_put_contents($this->root . '/config/app.php', "<?php throw new \\RuntimeException('config is broken');\n");

        self::assertSame('RuntimeException: config is broken', (new BootProbe())->whyNot($this->root));
    }

    public function testAParseErrorDoesNotBoot(): void
    {
        file_put_contents($this->root . '/config/plugins.php', "<?php return [ App\\Plugins\\Blog\\Blog::class \n");

        self::assertStringStartsWith('ParseError: ', (string) (new BootProbe())->whyNot($this->root));
    }

    public function testABootThatHangsDoesNotBoot(): void
    {
        file_put_contents($this->root . '/config/app.php', "<?php sleep(30); return [];\n");

        self::assertSame('the house did not finish booting within 1s', (new BootProbe(timeoutSeconds: 1))->whyNot($this->root));
    }

    public function testCouldNotAskIsNeverReadAsBoots(): void
    {
        self::assertNotNull((new BootProbe(php: '/nonexistent/php'))->whyNot($this->root));
        self::assertNotNull((new BootProbe(php: ''))->whyNot($this->root));
        unlink($this->root . '/vendor/autoload.php');
        self::assertNotNull((new BootProbe())->whyNot($this->root));
    }

    /** A held process asks at every request; the probe runs once per distinct content, and again after a new write. */
    public function testTheSameContentIsProbedOnce(): void
    {
        $count = $this->root . '/var/probes';
        $fake = $this->root . '/var/fake-probe.php';
        file_put_contents($fake, '<?php file_put_contents(' . var_export($count, true) . ', "x", FILE_APPEND); exit(255);');
        $probe = new BootProbe(script: $fake);

        $definition = KernelDefinition::before($this->root);
        file_put_contents($this->root . '/storage/plugins.json', '{"Blog":false}');
        self::assertSame('storage/plugins.json', $definition->staleBecause());
        self::assertSame('the house did not boot (exit 255)', $definition->nextBootFails($probe));
        foreach (range(1, 5) as $_) {
            $definition->staleBecause();
            $definition->nextBootFails($probe);
        }
        self::assertSame(1, \strlen((string) file_get_contents($count)), 'five more requests on the same content probed nothing');

        file_put_contents($this->root . '/storage/plugins.json', '{"Blog":true}');
        self::assertNotNull($definition->staleBecause(), 'a change is sticky: the kernel read the other content');
        $definition->nextBootFails($probe);
        self::assertSame(2, \strlen((string) file_get_contents($count)), 'a new write asks again');
    }

    /**
     * The fix usually lands in a file the held kernel never read — the broken plugin's own class — so the
     * digest does not move; a «does not boot» is asked again after the recheck interval (0 here).
     */
    public function testAFixToAFileTheKernelNeverReadIsSeenAfterTheRecheck(): void
    {
        $definition = KernelDefinition::before($this->root);
        TinyHouse::plugin($this->root, 'Roto');
        file_put_contents($this->root . '/src/Plugins/Roto/Roto.php', TinyHouse::pluginSource('Roto', broken: true));
        TinyHouse::register($this->root, 'Blog', 'Roto');
        $definition->staleBecause();
        self::assertNotNull($definition->nextBootFails(), 'broken: the process must not leave');

        file_put_contents($this->root . '/src/Plugins/Roto/Roto.php', TinyHouse::pluginSource('Roto'));
        $definition->staleBecause();
        self::assertNotNull($definition->nextBootFails(), 'within the interval the verdict is kept');
        self::assertNull($definition->nextBootFails(recheckSeconds: 0), 'after it, the fix is seen: the process may leave');
    }

    /**
     * An undo rewrites what the held kernel READ (`config/plugins.php`), so the digest moves and the probe is
     * asked again at once — no interval to wait (recheck set far away to prove it).
     */
    public function testAnUndoOfWhatTheKernelReadIsSeenAtOnce(): void
    {
        $definition = KernelDefinition::before($this->root);
        require $this->root . '/config/plugins.php'; // what a worker's boot includes
        $definition->takeInIncluded();
        TinyHouse::plugin($this->root, 'Roto');
        file_put_contents($this->root . '/src/Plugins/Roto/Roto.php', TinyHouse::pluginSource('Roto', broken: true));
        TinyHouse::register($this->root, 'Blog', 'Roto');
        $definition->staleBecause();
        self::assertNotNull($definition->nextBootFails(), 'broken: the process must not leave');

        TinyHouse::register($this->root, 'Blog');
        $definition->staleBecause();
        self::assertNull($definition->nextBootFails(recheckSeconds: 3600), 'restored: the process may leave, and a clean one boots');
    }

    /** While the house does not boot the answer is a refusal that says why — 503, never the old kernel's answer (Rod, 2026-09-28). */
    public function testTheRefusalSaysWhyAndIsNotCached(): void
    {
        $response = KernelDefinition::houseDoesNotBoot('ArgumentCountError: Too few arguments — «Blog»', new \Nyholm\Psr7\Factory\Psr17Factory());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame((string) KernelDefinition::RECHECK_SECONDS, $response->getHeaderLine('Retry-After'));
        self::assertSame('ArgumentCountError: Too few arguments ??? ??Blog??', $response->getHeaderLine('Milpa-House-Does-Not-Boot'), 'a header carries printable ASCII only');
        self::assertStringStartsWith('This house does not boot: ArgumentCountError: Too few arguments — «Blog»', (string) $response->getBody());
        self::assertStringContainsString('coa sandbox:undo', (string) $response->getBody());
    }
}
